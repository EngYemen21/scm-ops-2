<script setup>
// «البيع» — the counter. Without an open session of the user: the open-session form. With one: the scan field and the
// catalogue on one side, the cart on the other (stacked on a phone, with a sticky total / pay bar), the payment panel,
// held sales, cash in / out and the close of the session.
//   scan    GET /pos/catalog/scan?register&code&customer → one product (+1 when it is already in the cart)
//   search  GET /pos/catalog?register&q&customer&page     → paged products with price and available stock
//   price   POST /pos/quote (debounced, ./pos.js useQuote) — the server is the only source of totals
//   pay     POST /pos/orders { session, clientKey, customer?, lines, payments, expectedTotal, heldId? } → 201 (200 replayed)
// The cart, the customer and the sale's clientKey live in localStorage per session: a reload keeps the sale, and the
// same key sent again (lost answer, double tap) gives back the same sale, never a second one.
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, EmptyState, ErrorBanner, Modal, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { fmtDate, fmtMoney, num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm, toast } from '@/stores/ui';
import CartPanel from './CartPanel.vue';
import CashMoveForm from './CashMoveForm.vue';
import CloseSessionForm from './CloseSessionForm.vue';
import HeldList from './HeldList.vue';
import OpenSessionForm from './OpenSessionForm.vue';
import PaymentSheet from './PaymentSheet.vue';
import { cents, codeName, errCode, fmtCents, forgetCart, money, nameOf, useCart, usePosLookups, usePosNav, useQuote } from './pos';
import { usePrint } from './receipt';

const auth = useAuth();
const nav = usePosNav();
const lk = usePosLookups();
const canSell = computed(() => auth.can('pos.sell'));
const session = lk.session;
const sessionId = computed(() => session.value?.id || null);
const register = computed(() => (session.value ? lk.registerById(session.value.registerId) : null));
const methods = computed(() => lk.methodsOf(register.value));

const cart = useCart(sessionId);
const qt = useQuote(sessionId, cart);
const quote = qt.quote;

// ---------------------------------------------------------------- scan (a USB scanner types the code + Enter)
const scanWrap = ref(null);
const code = ref('');
const scanAct = useAction({ success: false, invalidate: false });
function focusScan() {
  if (isMobile.value) return; // a phone would pop its keyboard after every tap
  nextTick(() => { const el = scanWrap.value?.querySelector('input'); if (el) el.focus(); });
}
/** Marks the last refusal left on lines; any change of the cart clears them. */
const badProducts = ref([]);
const badLine = ref(-1);
watch(() => JSON.stringify(cart.body()), () => { badProducts.value = []; badLine.value = -1; });

function addProduct(p) {
  if (p.price == null) {
    toast.say({ ar: `لا سعر للصنف ${p.sku} لهذا العميل — أضفه في قائمة الأسعار`, en: `No price for ${p.sku} for this customer — add it to the price list` }, 3600);
    return;
  }
  cart.add(p);
  focusScan();
}
async function onScan() {
  const c = String(code.value || '').trim();
  code.value = '';
  if (!c || !register.value) { focusScan(); return; }
  const p = await scanAct.run(() => api.get('/pos/catalog/scan', { register: register.value.id, code: c, customer: cart.state.customer?.code || undefined }));
  if (p) addProduct(p);
  focusScan();
}
onMounted(focusScan);
watch(sessionId, (id) => { if (id) focusScan(); });

// ---------------------------------------------------------------- catalogue search
const search = ref('');
const term = ref('');
const cpage = ref(1);
let searchTimer = null;
function typeSearch(v) { search.value = v; clearTimeout(searchTimer); searchTimer = setTimeout(() => { term.value = String(v || '').trim(); cpage.value = 1; }, 300); }
function applySearch() { clearTimeout(searchTimer); term.value = search.value.trim(); cpage.value = 1; }
const catalog = useList('/pos/catalog', () => ({ register: register.value?.id, q: term.value || undefined, customer: cart.state.customer?.code || undefined, page: cpage.value, pageSize: 24 }), { enabled: () => !!register.value });
const products = computed(() => catalog.data.value?.items || []);
const cPages = computed(() => catalog.data.value?.pages || 1);
const inCart = (id) => cart.state.lines.find((l) => l.productId === id)?.qty || 0;

// ---------------------------------------------------------------- pay
const stockBlocked = computed(() => !quote.value?.allowNegativeStock && (quote.value?.stockShort || []).length > 0);
const canPay = computed(() => cart.state.lines.length > 0 && !!quote.value && !qt.error.value && !qt.stale.value && !qt.loading.value && !stockBlocked.value && cents(quote.value.total) > 0);
const payOpen = ref(false);
const payAct = useAction({ success: false });
const keyRenewed = ref(false);
function openPay() { if (!canPay.value) return; payAct.clearError(); keyRenewed.value = false; payOpen.value = true; }

/** The sale that just went through: the success sheet. */
const done = ref(null);
const pr = usePrint();

async function pay(payments) {
  if (!quote.value || !sessionId.value) return;
  const body = {
    session: sessionId.value, clientKey: cart.state.clientKey, customer: cart.state.customer?.code || undefined, lines: cart.body(), payments,
    expectedTotal: quote.value.total, heldId: cart.state.heldId || undefined,
  };
  const o = await payAct.run(() => api.postIdempotent('/pos/orders', body), { invalidate: ['pos'] });
  if (o) {
    done.value = o;
    payOpen.value = false;
    cart.clear();
    return;
  }
  const e = payAct.error.value;
  switch (errCode(e)) {
    case 'POS_TOTAL_CHANGED': await qt.run(); break; // the sheet stays open on the new total
    case 'POS_STOCK_SHORT': badProducts.value = (e.details?.lines || []).map((l) => l.productId); payOpen.value = false; void qt.run(); break;
    case 'POS_DISCOUNT_LIMIT': case 'POS_NO_PRICE': badLine.value = Number(e.details?.line || 0) - 1; payOpen.value = false; break;
    case 'POS_KEY_REUSED': cart.renewKey(); keyRenewed.value = true; break;
    case 'POS_SESSION_CLOSED': case 'POS_NOT_YOUR_SESSION': case 'POS_SESSION_NOT_FOUND': payOpen.value = false; void lk.query.refetch(); break;
    default: break;
  }
}
function closeDone() { done.value = null; focusScan(); }
const defaultFormat = computed(() => register.value?.receiptFormat || '80mm');

// ---------------------------------------------------------------- hold / held / new sale
const holdAct = useAction();
async function hold() {
  if (!cart.state.lines.length) return;
  const label = await ask({ title: { ar: 'تعليق البيع', en: 'Hold the sale' }, label: { ar: 'اسم يميّزه (اختياري)', en: 'A label (optional)' }, placeholder: { ar: 'مثال: عميل بالسيارة', en: 'e.g. customer in the car' }, okLabel: { ar: 'تعليق', en: 'Hold' } });
  if (label === null) return;
  const r = await holdAct.run(() => api.postIdempotent('/pos/held', {
    session: sessionId.value, label: label || undefined, customer: cart.state.customer?.code || undefined, clientKey: cart.state.clientKey, lines: cart.body(),
    total: quote.value && !qt.stale.value ? quote.value.total : undefined,
  }), { success: t('عُلّق البيع — تجده في «المعلّقة»', 'Sale held — find it under "Held sales"'), invalidate: ['pos'] });
  if (r) { cart.clear(); focusScan(); }
}
const heldOpen = ref(false);
function onRecalled(h) { cart.loadHeld(h.payload || {}, h.id); focusScan(); }
async function newSale() {
  if (cart.state.lines.length && !(await confirm({ title: { ar: 'بدء بيع جديد؟', en: 'Start a new sale?' }, sub: { ar: 'تُفرَّغ السلة الحالية دون حفظ.', en: 'The current cart is emptied without saving.' }, tone: 'danger', okLabel: { ar: 'تفريغ السلة', en: 'Empty the cart' } }))) return;
  cart.clear();
  focusScan();
}

// ---------------------------------------------------------------- cash in / out, close
const cashOpen = ref(false);
const closeOpen = ref(false);
function onClosed(s) {
  closeOpen.value = false;
  forgetCart(s.id);
  nav.go('sessions', { report: s.id });
}
/** The quote names the customer the server resolved; "named" = not the register's walk-in customer (needed for "on account"). */
const quotedCustomer = computed(() => (quote.value && !qt.stale.value ? quote.value.customer || null : null));
const namedCustomer = computed(() => !!cart.state.customer && !!quotedCustomer.value && quotedCustomer.value.id !== register.value?.defaultCustomerId);
const walkIn = computed(() => register.value?.customer || (!cart.state.customer ? quote.value?.customer || null : null));
const customerLabel = computed(() => (cart.state.customer ? codeName(cart.state.customer) : walkIn.value ? codeName(walkIn.value) : ''));
</script>

<template>
  <div v-if="!lk.loaded.value && !lk.query.error.value" class="skel h-[320px]" />
  <div v-else-if="!canSell" class="hint !mt-0">{{ t('البيع يحتاج صلاحية الكاشير (pos.sell). يمكنك تصفح الجلسات والطلبات من التبويبات الأخرى.', 'Selling needs the cashier permission (pos.sell). You can browse sessions and orders in the other tabs.') }}</div>
  <OpenSessionForm v-else-if="!session" @opened="focusScan" />

  <template v-else>
    <!-- the session bar -->
    <div class="card mb-3 flex flex-wrap items-center gap-x-4 gap-y-2 p-3">
      <div class="min-w-0">
        <div class="flex items-center gap-2">
          <span class="num ltr text-[13px] font-extrabold text-violet">{{ session.number }}</span>
          <Chip small :label="{ ar: 'مفتوحة', en: 'Open' }" fg="#1d7a3e" bg="#e6f9ec" />
        </div>
        <div class="text-[10.5px] text-muted" dir="auto">{{ register ? `${register.code} · ${nameOf(register)}` : codeName(session.register) }} · {{ t('منذ', 'since') }} <span class="num ltr">{{ fmtDate(session.openedAt) }}</span></div>
      </div>
      <div class="text-[10.5px]">
        <div class="text-muted">{{ t('النقد المتوقع', 'Expected cash') }}</div>
        <div class="num ltr font-extrabold text-ink">{{ money(session.expectedCashNow) }}</div>
      </div>
      <div v-if="session.summary" class="text-[10.5px]">
        <div class="text-muted">{{ t('صافي المبيعات', 'Net sales') }} · <span class="num">{{ session.summary.sales ?? 0 }}</span></div>
        <div class="num ltr font-extrabold text-ink">{{ money(session.summary.netTotal) }}</div>
      </div>
      <div class="grow" />
      <div class="flex flex-wrap gap-1.5">
        <Btn tone="soft" size="sm" @click="heldOpen = true">{{ t('المعلّقة', 'Held') }} <span v-if="session.held" class="badge num ms-1">{{ session.held }}</span></Btn>
        <Btn tone="softBlue" size="sm" :label="{ ar: 'إيداع / سحب نقد', en: 'Cash in / out' }" @click="cashOpen = true" />
        <Btn tone="ghost" size="sm" :label="{ ar: 'تفاصيل الجلسة', en: 'Session details' }" @click="nav.openSession(session.id)" />
        <Btn tone="dangerOutline" size="sm" :label="{ ar: 'إغلاق الجلسة', en: 'Close session' }" @click="closeOpen = true" />
      </div>
    </div>
    <div v-if="!register" class="banner amber mb-3">{{ t('صندوق هذه الجلسة غير نشط في الإعداد — طرق الدفع وحد الخصم غير متاحة. أغلق الجلسة أو اطلب من المدير تفعيله.', 'The register of this session is not active in setup — payment methods and the discount limit are unavailable. Close the session or ask a manager to reactivate it.') }}</div>
    <ErrorBanner :error="payOpen ? null : payAct.error.value" @close="payAct.clearError()" />

    <div class="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_360px] xl:grid-cols-[minmax(0,1fr)_420px]">
      <!-- scan + catalogue -->
      <div class="min-w-0">
        <div class="card mb-3 p-3">
          <div ref="scanWrap">
            <TextInput v-model="code" scan dir="ltr" :label="{ ar: 'امسح الباركود أو اكتب رمز الصنف ثم Enter', en: 'Scan a barcode or type a SKU, then Enter' }" :placeholder="{ ar: 'باركود / SKU', en: 'Barcode / SKU' }"
                       :disabled="!register" autocomplete="off" @enter="onScan" />
          </div>
          <ErrorBanner :error="scanAct.error.value" @close="scanAct.clearError()" />
          <TextInput small type="search" class="mt-2" field-class="!mb-0" :model-value="search" :placeholder="{ ar: 'بحث بالاسم أو الرمز أو العلامة…', en: 'Search name, SKU or brand…' }" @update:model-value="typeSearch" @enter="applySearch" />
        </div>

        <ErrorBanner :error="catalog.error.value" :closable="false" />
        <div v-if="catalog.isLoading.value" class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4"><div v-for="i in 8" :key="i" class="skel h-[92px] rounded-xl" /></div>
        <EmptyState v-else-if="!products.length" tone="dashed" :text="term ? { ar: 'لا صنف يطابق البحث.', en: 'No product matches the search.' } : { ar: 'لا أصناف نشطة.', en: 'No active products.' }" />
        <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4" :class="{ 'opacity-60': catalog.isFetching.value }">
          <button v-for="p in products" :key="p.id" type="button" class="relative rounded-xl border bg-white p-2.5 text-start transition-colors"
                  :class="p.price == null ? 'cursor-not-allowed border-line opacity-60' : 'cursor-pointer border-line hover:border-brand'" :title="p.price == null ? t('لا سعر لهذا العميل', 'No price for this customer') : ''" @click="addProduct(p)">
            <span v-if="inCart(p.id)" class="badge num absolute end-1.5 top-1.5">{{ inCart(p.id) }}</span>
            <div class="line-clamp-2 min-h-[30px] pe-5 text-[11.5px] font-extrabold leading-[1.35] text-ink" dir="auto">{{ nameOf(p) }}</div>
            <div class="num ltr mt-0.5 truncate text-start text-[9.5px] text-muted">{{ p.sku }}</div>
            <div class="mt-1.5 flex items-end justify-between gap-1">
              <span class="num ltr text-[13px] font-extrabold" :class="p.price == null ? 'text-bad' : 'text-ink'">{{ p.price == null ? t('بلا سعر', 'No price') : fmtMoney(num(p.price)) }}</span>
              <span class="text-[9.5px] font-bold" :class="num(p.available) > 0 ? 'text-ok' : 'text-bad'">{{ t('متاح', 'Avail.') }} <span class="num">{{ num(p.available) }}</span></span>
            </div>
          </button>
        </div>
        <div v-if="cPages > 1" class="mt-2 flex items-center justify-center gap-2">
          <button type="button" class="pg-btn" :disabled="cpage <= 1" @click="cpage -= 1">‹</button>
          <span class="num text-[11px] text-sec">{{ cpage }} / {{ cPages }}</span>
          <button type="button" class="pg-btn" :disabled="cpage >= cPages" @click="cpage += 1">›</button>
        </div>
      </div>

      <!-- the cart -->
      <div class="min-w-0 md:sticky md:top-3 md:self-start">
        <CartPanel :cart="cart" :quote="quote" :quote-error="qt.error.value" :quoting="qt.loading.value" :stale="qt.stale.value" :register="register" :can-pay="canPay"
                   :bad-products="badProducts" :bad-line="badLine" :busy="payAct.pending.value || holdAct.pending.value" :compact="isMobile"
                   @pay="openPay" @hold="hold" @held="heldOpen = true" @clear="newSale" @requote="qt.run()" />
      </div>
    </div>

    <!-- phone: the total and the pay button stay above the tab bar -->
    <div v-if="isMobile && cart.state.lines.length" class="sticky z-30 mt-3 flex items-center gap-3 rounded-2xl bg-night p-3 text-white shadow-lg" :style="{ bottom: 'calc(72px + env(safe-area-inset-bottom))' }">
      <div class="min-w-0 flex-1">
        <div class="text-[10px] opacity-75">{{ t('الإجمالي', 'Total') }} · <span class="num">{{ cart.count.value }}</span> {{ t('قطعة', 'items') }}</div>
        <div class="num ltr text-[18px] font-extrabold">{{ quote ? fmtCents(cents(quote.total)) : '…' }}</div>
      </div>
      <Btn tone="success" class="!h-12 min-w-[120px] !text-[14px]" :disabled="!canPay" @click="openPay">{{ t('الدفع', 'Pay') }}</Btn>
    </div>

    <PaymentSheet :open="payOpen" :total="quote?.total || 0" :methods="methods" :named-customer="namedCustomer" :customer-label="customerLabel"
                  :note="keyRenewed ? t('أُنشئ مفتاح عملية جديد لهذا البيع — راجع السلة ثم اضغط «تأكيد الدفع» مرة أخرى.', 'A new operation key was made for this sale — review the cart and press Confirm payment again.') : null"
                  :pending="payAct.pending.value" :error="payAct.error.value" @pay="pay" @close="payOpen = false; focusScan()" @clear-error="payAct.clearError()" />

    <Modal :open="!!done" :title="{ ar: 'تم البيع ✓', en: 'Sale completed ✓' }" :sub="done ? done.number : null" :width="440" @close="closeDone">
      <template v-if="done">
        <div v-if="done.replayed" class="hint !mt-0 mb-2">{{ t('هذا البيع سُجّل من قبل بالمفتاح نفسه — هذه نتيجته، ولم يُسجَّل بيع ثانٍ.', 'This sale was already recorded with the same key — this is its result; no second sale was recorded.') }}</div>
        <div class="rounded-xl bg-soft p-4 text-center">
          <div class="text-[11px] text-muted">{{ t('الإجمالي', 'Total') }}</div>
          <div class="num ltr text-[26px] font-extrabold text-ink">{{ money(done.total) }}</div>
          <div class="mt-2 grid grid-cols-2 gap-2 text-[11.5px]">
            <div><div class="text-muted">{{ t('المدفوع', 'Tendered') }}</div><div class="num ltr font-extrabold">{{ money(done.tendered) }}</div></div>
            <div><div class="text-muted">{{ t('الباقي للعميل', 'Change') }}</div><div class="num ltr text-[16px] font-extrabold text-ok">{{ money(done.changeDue) }}</div></div>
          </div>
          <div v-if="num(done.onAccount) > 0" class="mt-2 text-[11px] font-bold text-violet">{{ t('على حساب العميل', 'On the customer account') }}: <span class="num ltr">{{ money(done.onAccount) }}</span></div>
          <div v-if="done.invoiceNumber" class="mt-2 text-[10.5px] text-muted">{{ t('الفاتورة الضريبية', 'Tax invoice') }} <span class="num ltr font-bold">{{ done.invoiceNumber }}</span></div>
          <div v-if="done.pendingQty > 0" class="mt-2 text-[10.5px] font-bold text-warn">{{ t(`بقيت ${done.pendingQty} قطعة دَينًا على المخزون`, `${done.pendingQty} item(s) are owed by stock`) }}</div>
        </div>
      </template>
      <template #footer>
        <div class="flex flex-wrap gap-2">
          <Btn :tone="defaultFormat === '80mm' ? 'dark' : 'soft'" class="flex-1" :loading="pr.printing.value === '80mm'" :label="{ ar: 'طباعة 80مم', en: 'Print 80mm' }" @click="pr.print(done.id, '80mm')" />
          <Btn :tone="defaultFormat === 'a4' ? 'dark' : 'soft'" class="flex-1" :loading="pr.printing.value === 'a4'" :label="{ ar: 'طباعة A4', en: 'Print A4' }" @click="pr.print(done.id, 'a4')" />
        </div>
        <Btn tone="success" block class="mt-2 !h-11" :label="{ ar: 'بيع جديد', en: 'New sale' }" @click="closeDone" />
      </template>
    </Modal>

    <HeldList :open="heldOpen" :session-id="sessionId" :cart-has-lines="cart.state.lines.length > 0" @close="heldOpen = false; focusScan()" @recalled="onRecalled" />
    <CashMoveForm :open="cashOpen" :session="session" @close="cashOpen = false; focusScan()" />
    <CloseSessionForm :open="closeOpen" :session-id="sessionId" @close="closeOpen = false" @done="onClosed" />
  </template>
</template>
