// End-to-end across BOTH systems (docs/integration/ARCHITECTURE.md "Definition of done"): a real B2B Sales instance and
// a real OPS instance, connected only by the signed API + events — no shared database, no manual re-entry.
//
//   Customer → Sales order → availability → reservation → OPS fulfilment → picking → packing → dispatch → driver →
//   delivery + POD → Sales status updated → customer receipt → compared with POD; plus a shortage that completes
//   when stock arrives, and Sales delivering an order while OPS is down (outbox retry).
//
// Never point this at production. Local servers (see docs/integration/RUNBOOK.md §E2E):
//   SALES=http://127.0.0.1:3100 OPS=http://127.0.0.1:8100 OPS_PASSWORD=… SALES_ADMIN_KEY=… SALES_SECRET=… SCHED_SECRET=… \
//   node tools/e2e-sales-ops.mjs
import { createHash, createHmac } from 'node:crypto';

const SALES = process.env.SALES || 'http://127.0.0.1:3100';
const OPS = process.env.OPS || 'http://127.0.0.1:8100';
const { OPS_PASSWORD, SALES_ADMIN_KEY, SALES_SECRET, SCHED_SECRET } = process.env;
if (!OPS_PASSWORD || !SALES_ADMIN_KEY || !SALES_SECRET || !SCHED_SECRET) throw new Error('OPS_PASSWORD, SALES_ADMIN_KEY, SALES_SECRET and SCHED_SECRET are required');
if (!/127\.0\.0\.1|localhost/.test(SALES + OPS)) throw new Error('local instances only');

let passed = 0;
const ok = (cond, what) => {
  if (!cond) throw new Error(`✗ ${what}`);
  passed++;
  console.log(`  ✓ ${what}`);
};
const step = (t) => console.log(`\n▶ ${t}`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const uid = Date.now().toString().slice(-6);

// ── OPS as people (JWT) ──
const tokens = {};
async function ops(user, method, path, body) {
  if (!tokens[user]) {
    const r = await fetch(`${OPS}/api/auth/login`, { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ username: user, password: OPS_PASSWORD }) });
    tokens[user] = (await r.json()).accessToken;
    if (!tokens[user]) throw new Error(`OPS login failed for ${user}`);
  }
  const r = await fetch(OPS + path, { method, headers: { authorization: `Bearer ${tokens[user]}`, 'content-type': 'application/json', accept: 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  const j = await r.json().catch(() => null);
  if (r.status >= 300) throw new Error(`OPS ${method} ${path} → ${r.status} ${JSON.stringify(j).slice(0, 300)}`);
  return j;
}

// ── OPS as a system (signed) ──
function signed(system, keyId, secret, method, path, body = '') {
  const ts = String(Math.floor(Date.now() / 1000));
  const sig = createHmac('sha256', secret).update(`${ts}\n${method}\n${path}\n${createHash('sha256').update(body).digest('hex')}`).digest('hex');
  return { 'X-B2B-System': system, 'X-B2B-Key-Id': keyId, 'X-B2B-Timestamp': ts, 'X-B2B-Signature': sig, 'content-type': 'application/json', accept: 'application/json' };
}
async function opsAsSales(path) {
  const r = await fetch(OPS + path, { headers: signed('sales', 'k1', SALES_SECRET, 'GET', path) });
  return { status: r.status, body: await r.json().catch(() => null) };
}
async function heartbeat() {
  const r = await fetch(`${OPS}/api/v1/ops/heartbeat`, { method: 'POST', headers: signed('scheduler', 's1', SCHED_SECRET, 'POST', '/api/v1/ops/heartbeat') });
  return r.json();
}

// ── Sales as people (cookie session + role) ──
async function salesSession(role) {
  let cookie = '';
  const call = async (body) => {
    const r = await fetch(`${SALES}/api/auth`, { method: 'POST', headers: { 'content-type': 'application/json', cookie }, body: JSON.stringify(body) });
    const set = r.headers.get('set-cookie');
    if (set) cookie = set.split(';')[0];
    if (r.status >= 300) throw new Error(`Sales auth ${JSON.stringify(body)} → ${r.status} ${await r.text()}`);
  };
  await call({ action: 'verify', phone: `05${uid}${role.length}00`.slice(0, 10), otp: '1234' });
  await call({ action: 'role', role, adminKey: role === 'b2b' ? SALES_ADMIN_KEY : undefined });
  return {
    async cmd(cmd, payload = {}) {
      const r = await fetch(`${SALES}/api/command`, { method: 'POST', headers: { 'content-type': 'application/json', cookie }, body: JSON.stringify({ cmd, ...payload }) });
      const j = await r.json();
      if (r.status >= 300) throw new Error(`Sales ${cmd} → ${r.status} ${j.error}`);
      return j;
    },
    async state() {
      const r = await fetch(`${SALES}/api/state`, { headers: { cookie } });
      return (await r.json()).snapshot;
    },
  };
}
const salesOrder = async (s, id) => (await s.state()).orders.find((o) => o.id === id);
async function waitFor(fn, what, ms = 15000) {
  const t0 = Date.now();
  for (;;) {
    const v = await fn();
    if (v) return v;
    if (Date.now() - t0 > ms) throw new Error(`timeout waiting for ${what}`);
    await sleep(500);
  }
}

// ═════════════════════════════════════════════════════════════
step('0. Initial sync: Sales master data → OPS (customers, products)');
const b2b = await salesSession('b2b');
const sync = await b2b.cmd('integration.sync');
console.log('   ', sync.msg);
const mirror = await ops('admin', 'GET', '/api/integration/mappings/product?pageSize=200');
ok(mirror.total >= 25, `OPS received the Sales catalogue (${mirror.total} products waiting for mapping)`);
const custMirror = await ops('admin', 'GET', '/api/integration/mappings/customer?pageSize=50');
ok(custMirror.items.some((c) => c.externalId === '1' && c.mapped), 'Sales customer 1 exists in OPS as a customer (Sales is its system of record)');

step('1. OPS data steward: products with stock, mapped to Sales products');
const mk = async (k, qty) => {
  const sku = `E2E-${k}-${uid}`;
  await ops('admin', 'POST', '/api/products', { sku, nameAr: `منتج تكامل ${k}`, nameEn: `E2E ${k}`, weightKg: 2, lengthCm: 30, widthCm: 20, heightCm: 15 });
  if (qty) await ops('admin', 'POST', '/api/inventory/adjust', { sku, warehouseCode: 'RYD', binCode: 'A-01-1-B1', qtyDelta: qty, reason: 'E2E opening stock' });
  return sku;
};
const skuA = await mk('A', 50); const skuB = await mk('B', 50); const skuC = await mk('C', 2);
for (const [pid, sku] of [['P-1042', skuA], ['P-1118', skuB], ['P-1207', skuC]]) {
  const m = await ops('admin', 'POST', '/api/integration/mappings/product', { externalId: pid, internal: sku });
  ok(m.internalCode === sku, `${pid} ↔ ${sku}`);
}
const atp = await opsAsSales('/api/v1/inventory/availability?products=P-1042,P-1207,P-1310');
ok(atp.status === 200 && atp.body.items[0].atp === 50 && atp.body.items[1].atp === 2 && atp.body.items[2].mapped === false, 'Sales reads ATP by its own product ids (50 / 2 / unmapped)');

step('2. Sales owner orders (approved commercially at once) → OPS fulfilment order, stock reserved');
const owner = await salesSession('owner');
const sub = await owner.cmd('orders.submit', { items: [{ pid: 'P-1042', qty: 5 }, { pid: 'P-1118', qty: 3 }] });
const ref = sub.msg.match(/ORD-\d+/)[0];
console.log('   ', sub.msg);
const reserved = await waitFor(async () => { const o = await salesOrder(owner, ref); return o?.ops?.status === 'reserved' ? o : null; }, 'reserved in Sales');
ok(reserved.ops.ref?.startsWith('SO-'), `Sales shows OPS order ${reserved.ops.ref} — status "${reserved.ops.label}"`);
ok(reserved.items.every((i) => typeof i.price === 'number'), 'order lines carry the price at order time (snapshot)');
const view = (await opsAsSales(`/api/v1/orders/${ref}`)).body;
ok(view.opsStatus === 'allocated' && view.lines.every((l) => l.reserved === l.qty), 'OPS reserved every line FEFO');
const so = view.opsOrder;

step('3. OPS executes: release → pick → pack → trip → load → dispatch');
const fo = await ops('wm', 'POST', `/api/sales/orders/${so}/fulfill`);
const full = await ops('worker', 'GET', `/api/fulfillment/orders/${fo.number}`);
for (const t of full.pickLists[0].tasks) await ops('worker', 'POST', `/api/fulfillment/pick-tasks/${t.id}/confirm`, { scannedBin: t.bin.code, scannedProduct: t.product.sku });
await ops('worker', 'POST', `/api/fulfillment/orders/${fo.number}/pack`, { cartons: 2, weightKg: 20 });
ok((await salesOrder(owner, ref)).ops.status === 'packed', 'Sales sees "packed" without logging into OPS');
const veh = `V-E${uid}`; const drv = `DRV-E${uid}`; const drvUser = `drv-e2e-${uid}`;
await ops('disp', 'POST', '/api/transport/vehicles', { code: veh, plateAr: `ت ك ل ${uid.slice(-4)}`, plateEn: `TKL ${uid.slice(-4)}`, vin: `VIN${uid}E2E000001`, brand: 'Isuzu', model: 'NPR', kind: 'dry', ownership: 'owned', maxKg: 3000, maxCbm: 16, pallets: 8, warehouseCode: 'RYD', regExpiry: '2028-01-01', insuranceExpiry: '2028-01-01', inspectionExpiry: '2028-01-01', opCardExpiry: '2028-01-01' });
await ops('disp', 'POST', '/api/transport/drivers', { code: drv, nameAr: `سائق التكامل ${uid}`, nameEn: 'E2E driver', employeeNo: `EMP-E${uid}`, mobile: '0500000000', licenseNo: `L-E${uid}`, licenseType: 'ثقيل', licenseExpiry: '2028-06-01', iqamaExpiry: '2028-06-01', shift: 'am', username: drvUser, password: OPS_PASSWORD });
const trip = await ops('disp', 'POST', '/api/transport/trips', { warehouseCode: 'RYD', date: new Date().toISOString().slice(0, 10), routeAr: 'تكامل', foNumbers: [fo.number], tempNeed: 'dry' });
const tripNo = trip.number ?? trip.trip.number;
await ops('disp', 'POST', `/api/transport/trips/${tripNo}/assign`, { vehicleCode: veh, driverCode: drv });
await ops('worker', 'POST', `/api/fulfillment/trips/${tripNo}/load`, { foNumber: fo.number });
await ops('disp', 'POST', `/api/fulfillment/trips/${tripNo}/dispatch`);
const shipped = await waitFor(async () => { const o = await salesOrder(owner, ref); return o?.st === 'ship' ? o : null; }, 'ship in Sales');
ok(shipped.ops.status === 'out_for_delivery' && shipped.ops.events.some((e) => e.type === 'shipment.dispatched' && e.text.includes(tripNo)), `Sales order moved to "out for delivery" by OPS (trip ${tripNo}, driver shown)`);

step('4. The driver delivers with proof of delivery');
const mine = await ops(drvUser, 'GET', '/api/delivery/my-trips');
const stop = mine.current.stops.find((s) => s.fo?.number === fo.number);
await ops(drvUser, 'POST', `/api/delivery/trips/${tripNo}/start`);
await ops(drvUser, 'POST', `/api/delivery/stops/${stop.id}/arrive`, { gps: { lat: 24.7, lng: 46.7 } });
const pod = await ops(drvUser, 'POST', `/api/delivery/stops/${stop.id}/deliver`, { receiverName: 'م. ناصر القحطاني', gps: { lat: 24.7, lng: 46.7 }, signature: 'sig.png' });
const delivered = await waitFor(async () => { const o = await salesOrder(owner, ref); return o?.ops?.status === 'delivered' ? o : null; }, 'delivered in Sales');
ok(delivered.ops.events.some((e) => e.type === 'delivery.completed' && e.text.includes('ناصر')), `Sales shows the POD (${pod.number ?? pod.pod ?? 'POD'}, receiver)`);

step('5. The restaurant confirms receipt in Sales → OPS compares it with the POD');
const worker = await salesSession('worker');
await worker.cmd('orders.receive', { id: ref, recv: {} });
ok((await salesOrder(worker, ref)).st === 'done', 'Sales order done (customer receipt)');
const after = (await opsAsSales(`/api/v1/orders/${ref}`)).body;
ok(after.received.some((e) => e.type === 'sales_order.received' && e.status === 'processed') && !after.exceptions.some((x) => x.code === 'RECEIPT_MISMATCH'), 'OPS recorded the receipt — matches the POD, no exception');
ok(after.timeline.map((t) => t.sequence).every((s, i) => s === i + 1), `OPS→Sales events gap-free and ordered (${after.timeline.length} events)`);
await ops('disp', 'POST', `/api/transport/trips/${tripNo}/close`);

step('6. Shortage: reserved what exists, rest backordered → completes when stock arrives');
const sub2 = await owner.cmd('orders.submit', { items: [{ pid: 'P-1207', qty: 5 }] });
const ref2 = sub2.msg.match(/ORD-\d+/)[0];
const short = await waitFor(async () => { const o = await salesOrder(owner, ref2); return o?.ops?.status === 'partially_reserved' ? o : null; }, 'partial in Sales');
ok(short.ops.events.some((e) => e.type === 'procurement.required'), 'Sales sees "partially reserved" + a procurement requirement');
await ops('admin', 'POST', '/api/inventory/adjust', { sku: skuC, warehouseCode: 'RYD', binCode: 'A-01-1-B1', qtyDelta: 10, reason: 'E2E receipt' });
const hb = await heartbeat();
ok(hb.backordersCompleted >= 1, `the integration cycle completed ${hb.backordersCompleted} backorder(s)`);
ok(hb.systems?.sales?.status === 200, 'the same cycle triggered the Sales cycle (one scheduler for both)');
await waitFor(async () => (await salesOrder(owner, ref2))?.ops?.status === 'reserved', 'reserved after stock arrival');
ok(true, 'Sales shows the backorder fully reserved');
const st = await owner.state();
ok(st.opsStock?.['P-1042']?.atp === 45, `Sales catalogue shows OPS availability (P-1042 ATP ${st.opsStock?.['P-1042']?.atp})`);

step('7. Cancelling in Sales before picking releases the reservation in OPS');
const sub3 = await owner.cmd('orders.submit', { items: [{ pid: 'P-1042', qty: 4 }] });
const ref3 = sub3.msg.match(/ORD-\d+/)[0];
await waitFor(async () => (await salesOrder(owner, ref3))?.ops?.status === 'reserved', 'reserved');
await owner.cmd('orders.reject', { id: ref3, reason: 'العميل ألغى الطلب' });
await waitFor(async () => (await salesOrder(owner, ref3))?.ops?.status === 'cancelled', 'cancelled in OPS');
ok((await opsAsSales(`/api/v1/orders/${ref3}`)).body.opsStatus === 'cancelled', 'OPS cancelled the order and released its stock');
ok((await opsAsSales('/api/v1/inventory/availability?products=P-1042')).body.items[0].atp === 45, 'availability back to 45');

step('8. Partial delivery → return to the warehouse → every step visible in Sales');
const sub5 = await owner.cmd('orders.submit', { items: [{ pid: 'P-1118', qty: 10 }] });
const ref5 = sub5.msg.match(/ORD-\d+/)[0];
const so5 = (await waitFor(async () => { const o = await salesOrder(owner, ref5); return o?.ops?.status === 'reserved' ? o : null; }, 'reserved')).ops.ref;
const fo5 = await ops('wm', 'POST', `/api/sales/orders/${so5}/fulfill`);
for (const t of (await ops('worker', 'GET', `/api/fulfillment/orders/${fo5.number}`)).pickLists[0].tasks) await ops('worker', 'POST', `/api/fulfillment/pick-tasks/${t.id}/confirm`, { scannedBin: t.bin.code, scannedProduct: t.product.sku });
await ops('worker', 'POST', `/api/fulfillment/orders/${fo5.number}/pack`, { cartons: 3, weightKg: 20 });
const trip5 = await ops('disp', 'POST', '/api/transport/trips', { warehouseCode: 'RYD', date: new Date().toISOString().slice(0, 10), routeAr: 'تكامل — جزئي', foNumbers: [fo5.number], tempNeed: 'dry' });
const trip5No = trip5.number ?? trip5.trip.number;
await ops('disp', 'POST', `/api/transport/trips/${trip5No}/assign`, { vehicleCode: veh, driverCode: drv });
await ops('worker', 'POST', `/api/fulfillment/trips/${trip5No}/load`, { foNumber: fo5.number });
await ops('disp', 'POST', `/api/fulfillment/trips/${trip5No}/dispatch`);
const stop5 = (await ops(drvUser, 'GET', '/api/delivery/my-trips')).current.stops.find((s) => s.fo?.number === fo5.number);
await ops(drvUser, 'POST', `/api/delivery/trips/${trip5No}/start`);
await ops(drvUser, 'POST', `/api/delivery/stops/${stop5.id}/arrive`);
const pod5 = await ops(drvUser, 'POST', `/api/delivery/stops/${stop5.id}/partial`, { receiverName: 'أمين المستودع', deliveredQty: 7 });
const part = await waitFor(async () => { const o = await salesOrder(owner, ref5); return o?.ops?.status === 'delivered_partial' ? o : null; }, 'partial delivery in Sales');
ok(part.ops.events.some((e) => e.type === 'return.created' && e.text.includes(pod5.return)), `Sales shows the partial delivery and the return ${pod5.return}`);
await ops('wm', 'POST', `/api/returns/${pod5.return}/receive`);
await ops('wm', 'POST', `/api/returns/${pod5.return}/inspect`, { findings: 'سليم' });
await ops('wm', 'POST', `/api/returns/${pod5.return}/decide`, { decision: 'restock' });
await waitFor(async () => (await salesOrder(owner, ref5))?.ops?.events.some((e) => e.type === 'return.closed'), 'return closed in Sales');
ok(true, 'return received, inspected and restocked — each step reached Sales');
await worker.cmd('orders.receive', { id: ref5, recv: { 'P-1118': { short: true, recv: 7 } } });
const v5 = (await opsAsSales(`/api/v1/orders/${ref5}`)).body;
ok((await salesOrder(worker, ref5)).st === 'short' && !v5.exceptions.some((x) => x.code === 'RECEIPT_MISMATCH'), 'customer received 7 of 10 in Sales (shortage ticket) — equals the POD, no mismatch');
await ops('disp', 'POST', `/api/transport/trips/${trip5No}/close`);
ok((await ops('admin', 'GET', '/api/inventory/reconciliation')).ok, 'OPS inventory ledger = balances after the whole journey');

step('9. Reconciliation: the two systems agree; a drift is flagged for a person, never auto-fixed');
const rec = await ops('admin', 'POST', '/api/integration/reconcile');
ok(rec.systems.sales.error === null && rec.systems.sales.compared >= 4 && rec.systems.sales.mismatches === 0, `compared ${rec.systems.sales.compared} orders — no disagreement`);
if (process.env.SALES_PG_URL && process.env.SALES_DIR) {
  const { createRequire } = await import('node:module');
  const pg = createRequire(`${process.env.SALES_DIR}/package.json`)('pg');
  const db = new pg.Client({ connectionString: process.env.SALES_PG_URL });
  await db.connect();
  await db.query("UPDATE orders SET st = 'done' WHERE id = $1", [ref2]); // simulate a drift in Sales
  const rec2 = await ops('admin', 'POST', '/api/integration/reconcile');
  const exc = await ops('admin', 'GET', `/api/integration/exceptions?status=open&code=RECON_MISMATCH&q=${ref2}`);
  ok(rec2.systems.sales.mismatches === 1 && exc.total === 1, `drift on ${ref2} raised RECON_MISMATCH: "${exc.items[0].message}"`);
  ok((await opsAsSales(`/api/v1/orders/${ref2}`)).body.opsStatus === 'allocated', 'OPS did not change the order by itself');
  await db.query("UPDATE orders SET st = 'b2b' WHERE id = $1", [ref2]);
  await db.end();
  await ops('admin', 'POST', '/api/integration/reconcile');
  ok((await ops('admin', 'GET', `/api/integration/exceptions?status=open&code=RECON_MISMATCH&q=${ref2}`)).total === 0, 'once both sides agree again the exception closes itself');
}

if (process.env.OPS2_PORT && process.env.OPS2_RESTART) {
  step('10. OPS is down while Sales sends an order → kept in the Sales outbox, delivered once OPS is back, never twice');
  const { execSync, spawn } = await import('node:child_process');
  const pids = execSync(`powershell -NoProfile -Command "(Get-NetTCPConnection -LocalPort ${process.env.OPS2_PORT} -State Listen).OwningProcess | Sort-Object -Unique"`).toString().trim().split(/\s+/).filter(Boolean);
  for (const p of pids) execSync(`powershell -NoProfile -Command "Stop-Process -Id ${p} -Force"`);
  const sub4 = await owner.cmd('orders.submit', { items: [{ pid: 'P-1042', qty: 1 }] });
  const ref4 = sub4.msg.match(/ORD-\d+/)[0];
  const waiting = await salesOrder(owner, ref4);
  ok(waiting.st === 'b2b' && waiting.ops?.status === 'sent', 'the order was accepted in Sales although OPS is unreachable (event kept in the outbox)');
  ok((await opsAsSales(`/api/v1/orders/${ref4}`)).status === 404, 'OPS has not seen it yet');
  spawn('bash', [process.env.OPS2_RESTART], { detached: true, stdio: 'ignore' }).unref();
  await sleep(40000); // past the first back-off step (30 s + jitter)
  await heartbeat(); // the scheduler's cycle triggers the Sales cycle, which retries the outbox
  await waitFor(async () => (await salesOrder(owner, ref4))?.ops?.status === 'reserved', 'reserved after OPS came back', 30000);
  ok(true, 'delivered on retry and reserved in OPS');
  await heartbeat();
  const v4 = (await opsAsSales(`/api/v1/orders/${ref4}`)).body;
  ok(v4.received.filter((e) => e.type === 'sales_order.confirmed').length === 1 && v4.opsOrder, `exactly one OPS order for ${ref4} (${v4.opsOrder})`);
}

console.log(`\n✅ ${passed} checks passed — Sales and OPS ran one order journey with no double entry.`);
