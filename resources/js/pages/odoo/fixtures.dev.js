// DEV ONLY — canned answers of the archive API. No page imports this file, so it is not part of the production
// bundle; it is read by the render check of the page (a Node script outside the repository) and is the reference
// when the real answers are compared with what the screens expect. Every name, number and amount here is invented.
// Two sets, and the page is checked against both:
//   1. `answer()` — the shapes of docs/odoo/WAVE2.md §3 as written, which the client was built on before the API
//      existed (the first half of this file);
//   2. `server.answer()` — what app/Integration/Odoo/Archive really sends, as read from its source and its tests
//      on 2026-11-24 (the second half): `label` + `labelEn`, the wording of a state on its column, ISO moments,
//      `groups` / `summary` / `stock.warehouses` for a partner and a product, and the rest listed there.
//
// Where §3 leaves a shape open, the first set is the shape the client was first built on (the client reads a few
// more — see ./archive.js):
//   · a `label` is `{ ar, en }`;
//   · `sorts` are column keys (or `{ key, label }` for a sort that is not a column), `defaultSort` is `{ key, order }`;
//   · a filter is sent under its own `key` (state, dim1, dim2, partner, product) — a daterange as `from` / `to`;
//   · a `state` — in a cell, in a related / latest / search item — is the bare value; its label and tone are the
//     option of that kind's `state` choice filter (a state the catalog cannot name is shown as it is);
//   · `partner` / `product` cells are `{ id, name }`; a `user` cell is the name;
//   · money carries no currency except on a list item (`currency`): a summary entry, a line and a related item show
//     the figure alone (the client also reads `currency` on the entry or the document, and `{ amount, currency }`);
//   · GET partners/{id} and GET products/{id}: see `partner` and `product` below.

const L = (ar, en) => ({ ar, en });
const page = (items, { page: p = 1, pageSize = 30, total = items.length, totals } = {}) => ({ items, total, page: p, pageSize, pages: Math.max(1, Math.ceil(total / pageSize)), ...(totals ? { totals } : {}) });

// ---------------------------------------------------------------- GET catalog
const SO_STATES = [
  { value: 'draft', label: L('عرض سعر', 'Quotation'), tone: 'neutral' },
  { value: 'sent', label: L('عرض مُرسل', 'Quotation sent'), tone: 'blue' },
  { value: 'sale', label: L('أمر بيع', 'Sales order'), tone: 'ok' },
  { value: 'cancel', label: L('ملغى', 'Cancelled'), tone: 'bad' },
];
const PICKING_STATES = [
  { value: 'draft', label: L('مسودة', 'Draft'), tone: 'neutral' },
  { value: 'waiting', label: L('بانتظار عملية أخرى', 'Waiting another operation'), tone: 'warn' },
  { value: 'confirmed', label: L('بانتظار التوفر', 'Waiting'), tone: 'warn' },
  { value: 'assigned', label: L('جاهز', 'Ready'), tone: 'blue' },
  { value: 'done', label: L('تم', 'Done'), tone: 'ok' },
  { value: 'cancel', label: L('ملغى', 'Cancelled'), tone: 'bad' },
];
const INVOICE_STATES = [
  { value: 'draft', label: L('مسودة', 'Draft'), tone: 'neutral' },
  { value: 'posted', label: L('مرحّلة', 'Posted'), tone: 'ok' },
  { value: 'cancel', label: L('ملغاة', 'Cancelled'), tone: 'bad' },
];

export const catalog = {
  ready: true,
  indexed: 168378, // every record of the kinds below, once
  sections: [
    {
      key: 'sales', label: L('المبيعات', 'Sales'),
      kinds: [
        {
          key: 'sale_order', label: L('أوامر البيع', 'Sales orders'), model: 'sale.order', count: 12840, private: false,
          columns: [
            { key: 'number', label: L('رقم الأمر', 'Order'), type: 'text' },
            { key: 'partner', label: L('العميل', 'Customer'), type: 'partner' },
            { key: 'date', label: L('تاريخ الطلب', 'Order date'), type: 'datetime' },
            { key: 'user', label: L('مندوب المبيعات', 'Salesperson'), type: 'user' },
            { key: 'amount', label: L('الإجمالي', 'Total'), type: 'money' },
            { key: 'state', label: L('الحالة', 'Status'), type: 'state' },
          ],
          filters: [
            { key: 'state', label: L('الحالة', 'Status'), type: 'choice', options: SO_STATES },
            { key: 'date', label: L('تاريخ الطلب', 'Order date'), type: 'daterange' },
            { key: 'partner', label: L('العميل', 'Customer'), type: 'partner' },
            { key: 'dim1', label: L('فريق المبيعات', 'Sales team'), type: 'text' },
          ],
          sorts: ['date', 'number', 'amount'], defaultSort: { key: 'date', order: 'desc' },
        },
        {
          key: 'sale_order_line', label: L('أسطر أوامر البيع', 'Sales order lines'), model: 'sale.order.line', count: 58210, private: false,
          columns: [
            { key: 'number', label: L('الأمر', 'Order'), type: 'text' },
            { key: 'product', label: L('الصنف', 'Product'), type: 'product' },
            { key: 'qty', label: L('الكمية', 'Quantity'), type: 'qty' },
            { key: 'price', label: L('سعر الوحدة', 'Unit price'), type: 'money' },
            { key: 'amount', label: L('الإجمالي', 'Subtotal'), type: 'money' },
          ],
          filters: [{ key: 'product', label: L('الصنف', 'Product'), type: 'product' }],
          sorts: [], defaultSort: null,
        },
        {
          key: 'customer', label: L('العملاء', 'Customers'), model: 'res.partner', count: 2310, private: false,
          columns: [
            { key: 'title', label: L('الاسم', 'Name'), type: 'text' },
            { key: 'city', label: L('المدينة', 'City'), type: 'text' },
            { key: 'orders', label: L('عدد الأوامر', 'Orders'), type: 'number' },
            { key: 'since', label: L('عميل منذ', 'Customer since'), type: 'date' },
            { key: 'active', label: L('فعّال', 'Active'), type: 'bool' },
          ],
          filters: [{ key: 'dim1', label: L('المدينة', 'City'), type: 'text' }],
          sorts: ['title'], defaultSort: { key: 'title', order: 'asc' },
        },
      ],
    },
    {
      key: 'inventory', label: L('المخزون', 'Inventory'),
      kinds: [
        {
          key: 'stock_picking', label: L('التحويلات المخزنية', 'Stock transfers'), model: 'stock.picking', count: 20415, private: false,
          columns: [
            { key: 'number', label: L('المرجع', 'Reference'), type: 'text' },
            { key: 'partner', label: L('الشريك', 'Partner'), type: 'partner' },
            { key: 'date', label: L('الموعد المجدول', 'Scheduled'), type: 'datetime' },
            { key: 'done', label: L('تاريخ الإنجاز', 'Done on'), type: 'date' },
            { key: 'origin', label: L('المستند المصدر', 'Source document'), type: 'text' },
            { key: 'lines', label: L('الأسطر', 'Lines'), type: 'number' },
            { key: 'backorder', label: L('له متأخرات', 'Has backorder'), type: 'bool' },
            { key: 'state', label: L('الحالة', 'Status'), type: 'state' },
          ],
          filters: [
            { key: 'state', label: L('الحالة', 'Status'), type: 'choice', options: PICKING_STATES },
            { key: 'dim1', label: L('نوع العملية', 'Operation type'), type: 'choice', options: [{ value: 'incoming', label: L('استلام', 'Receipt'), tone: 'blue' }, { value: 'outgoing', label: L('تسليم', 'Delivery'), tone: 'ok' }, { value: 'internal', label: L('داخلي', 'Internal'), tone: 'neutral' }] },
            { key: 'product', label: L('الصنف', 'Product'), type: 'product' },
            { key: 'date', label: L('الموعد المجدول', 'Scheduled'), type: 'daterange' },
            { key: 'dim2', label: L('المستند المصدر', 'Source document'), type: 'text' },
          ],
          sorts: ['date', 'number', { key: 'odooId', label: L('ترتيب الإنشاء في أودو', 'Creation order in Odoo') }], defaultSort: '-date',
        },
        {
          key: 'stock_move', label: L('حركات المخزون', 'Stock moves'), model: 'stock.move', count: 61244, private: false,
          columns: [
            { key: 'number', label: L('التحويل', 'Transfer'), type: 'text' },
            { key: 'product', label: L('الصنف', 'Product'), type: 'product' },
            { key: 'qty', label: L('الكمية', 'Quantity'), type: 'qty' },
            { key: 'date', label: L('التاريخ', 'Date'), type: 'datetime' },
            { key: 'state', label: L('الحالة', 'Status'), type: 'state' },
          ],
          filters: [{ key: 'state', label: L('الحالة', 'Status'), type: 'choice', options: PICKING_STATES }, { key: 'product', label: L('الصنف', 'Product'), type: 'product' }],
          sorts: ['date'], defaultSort: { key: 'date', order: 'desc' },
        },
      ],
    },
    {
      key: 'accounting', label: L('المحاسبة', 'Accounting'),
      kinds: [
        {
          key: 'customer_invoice', label: L('فواتير العملاء', 'Customer invoices'), model: 'account.move', count: 9120, private: false,
          columns: [
            { key: 'number', label: L('رقم الفاتورة', 'Invoice'), type: 'text' },
            { key: 'partner', label: L('العميل', 'Customer'), type: 'partner' },
            { key: 'date', label: L('تاريخ الفاتورة', 'Invoice date'), type: 'date' },
            { key: 'amount', label: L('الإجمالي', 'Total'), type: 'money' },
            { key: 'state', label: L('الحالة', 'Status'), type: 'state' },
          ],
          filters: [{ key: 'state', label: L('الحالة', 'Status'), type: 'choice', options: INVOICE_STATES }, { key: 'partner', label: L('العميل', 'Customer'), type: 'partner' }],
          sorts: ['date', 'amount'], defaultSort: { key: 'date', order: 'desc' },
        },
      ],
    },
    {
      key: 'products', label: L('الأصناف', 'Products'),
      kinds: [
        {
          key: 'product', label: L('الأصناف', 'Products'), model: 'product.product', count: 4180, private: false,
          columns: [
            { key: 'number', label: L('الرمز', 'Code'), type: 'text' },
            { key: 'title', label: L('الاسم', 'Name'), type: 'text' },
            { key: 'amount', label: L('سعر البيع', 'Sales price'), type: 'money' },
          ],
          filters: [], sorts: ['number', 'title'], defaultSort: 'number',
        },
      ],
    },
    {
      key: 'people', label: L('الأشخاص', 'People'),
      kinds: [
        {
          key: 'hr_employee', label: L('الموظفون', 'Employees'), model: 'hr.employee', count: 31, private: true,
          columns: [{ key: 'title', label: L('الاسم', 'Name'), type: 'text' }, { key: 'job', label: L('الوظيفة', 'Job'), type: 'text' }, { key: 'department', label: L('القسم', 'Department'), type: 'text' }],
          filters: [{ key: 'dim1', label: L('القسم', 'Department'), type: 'text' }],
          sorts: ['title'], defaultSort: { key: 'title', order: 'asc' },
        },
        {
          key: 'hr_contract', label: L('العقود', 'Contracts'), model: 'hr.contract', count: 28, private: true,
          columns: [{ key: 'title', label: L('العقد', 'Contract'), type: 'text' }, { key: 'date', label: L('البداية', 'Start'), type: 'date' }, { key: 'state', label: L('الحالة', 'Status'), type: 'state' }],
          filters: [], sorts: [], defaultSort: null,
        },
      ],
    },
    {
      key: 'other', label: L('أخرى', 'Other'),
      kinds: [
        // a model that fits nowhere: a plain kind — a name and a date, nothing declared beyond the columns
        { key: 'x_route_note', label: L('ملاحظات خطوط السير', 'Route notes'), model: 'x_route.note', count: 0, private: false, columns: [{ key: 'title', label: L('الاسم', 'Name'), type: 'text' }, { key: 'date', label: L('التاريخ', 'Date'), type: 'date' }], filters: [], sorts: [], defaultSort: null },
      ],
    },
  ],
};

/** What the server answers to a user without odoo.private: the people's-files kinds are left out, and not counted. */
export const withoutPrivate = (c) => ({
  ...c,
  indexed: c.indexed - c.sections.flatMap((s) => s.kinds).filter((k) => k.private).reduce((n, k) => n + k.count, 0),
  sections: c.sections.map((s) => ({ ...s, kinds: s.kinds.filter((k) => !k.private) })).filter((s) => s.kinds.length),
});
/** The catalog while the index is being built. */
export const catalogBuilding = { ...catalog, ready: false, indexed: 95000 };
/** A server that has indexed nothing yet. */
export const catalogEmpty = { ready: false, indexed: 0, sections: [] };

// ---------------------------------------------------------------- GET docs/{kind}
const PARTNER = { id: 42, name: 'شركة الواحة للتجارة' };
const PARTNER_B = { id: 57, name: 'Blue Palm Trading Co.' };
const PRODUCT = { id: 7, name: 'تمر سكري فاخر 1 كجم' };
const PRODUCT_B = { id: 9, name: 'Olive oil 750 ml' };

export const lists = {
  sale_order: page([
    { odooId: 123, number: 'S00123', title: 'S00123', partner: PARTNER, date: '2026-11-20 08:15:00', state: 'sale', amount: 18450.75, currency: 'SAR', user: 'سالم العتيبي', active: true, cells: { number: 'S00123', partner: PARTNER, date: '2026-11-20 08:15:00', user: 'سالم العتيبي', amount: 18450.75, state: 'sale' } },
    { odooId: 122, number: 'S00122', title: 'S00122', partner: PARTNER_B, date: '2026-11-19 13:40:00', state: 'draft', amount: '2300.00', currency: 'SAR', user: 'Mona Hassan', active: true, cells: { number: 'S00122', partner: PARTNER_B, date: '2026-11-19 13:40:00', user: 'Mona Hassan', amount: '2300.00', state: 'draft' } },
    // a cancelled, archived order with no customer, no salesperson and a state the catalog does not name
    { odooId: 121, number: 'S00121', title: 'S00121', partner: null, date: '2026-11-18 06:05:00', state: 'on_hold', amount: 0, currency: 'SAR', user: false, active: false, cells: { number: 'S00121', partner: false, date: '2026-11-18 06:05:00', user: false, amount: 0, state: 'on_hold' } },
  ], { total: 12840, totals: { amount: 20750.75 } }),
  stock_picking: page([
    { odooId: 501, number: 'WH/OUT/00501', title: 'WH/OUT/00501', partner: PARTNER, date: '2026-11-21 05:00:00', state: 'done', amount: null, currency: null, user: 'أمين المستودع', active: true, cells: { number: 'WH/OUT/00501', partner: PARTNER, date: '2026-11-21 05:00:00', done: '2026-11-21', origin: 'S00123', lines: 12, backorder: false, state: 'done' } },
    { odooId: 502, number: 'WH/IN/00090', title: 'WH/IN/00090', partner: PARTNER_B, date: '2026-11-22 09:30:00', state: 'assigned', amount: null, currency: null, user: false, active: true, cells: { number: 'WH/IN/00090', partner: PARTNER_B, date: '2026-11-22 09:30:00', done: false, origin: 'P00077', lines: 3, backorder: true, state: 'assigned' } },
  ], { total: 20415 }),
  stock_move: page([
    { odooId: 7001, number: 'WH/OUT/00501', title: 'تمر سكري فاخر 1 كجم', partner: PARTNER, date: '2026-11-21 05:00:00', state: 'done', amount: null, currency: null, user: null, active: true, cells: { number: 'WH/OUT/00501', product: PRODUCT, qty: 400, date: '2026-11-21 05:00:00', state: 'done' } },
  ], { total: 61244 }),
  sale_order_line: page([
    { odooId: 9001, number: 'S00123', title: 'تمر سكري فاخر 1 كجم', partner: PARTNER, date: '2026-11-20 08:15:00', state: 'sale', amount: 12000, currency: 'SAR', user: null, active: true, cells: { number: 'S00123', product: PRODUCT, qty: 400, price: 30, amount: 12000 } },
    { odooId: 9002, number: 'S00123', title: 'Olive oil 750 ml', partner: PARTNER, date: '2026-11-20 08:15:00', state: 'sale', amount: 6450.75, currency: 'SAR', user: null, active: true, cells: { number: 'S00123', product: PRODUCT_B, qty: 150.5, price: 42.8621, amount: 6450.75 } },
  ], { total: 450, pageSize: 50, totals: { amount: 18450.75 } }),
  customer: page([
    { odooId: 42, number: 'C0042', title: 'شركة الواحة للتجارة', partner: PARTNER, date: null, state: null, amount: null, currency: null, user: null, active: true, cells: { title: 'شركة الواحة للتجارة', city: 'الرياض', orders: 1250, since: '2021-03-14', active: true } },
    { odooId: 57, number: false, title: 'Blue Palm Trading Co.', partner: PARTNER_B, date: null, state: null, amount: null, currency: null, user: null, active: true, cells: { title: 'Blue Palm Trading Co.', city: false, orders: 0, since: false, active: false } },
  ], { total: 2310 }),
  customer_invoice: page([
    { odooId: 77, number: 'INV/2026/00077', title: 'INV/2026/00077', partner: PARTNER, date: '2026-11-20', state: 'posted', amount: 18450.75, currency: 'SAR', user: null, active: true, cells: { number: 'INV/2026/00077', partner: PARTNER, date: '2026-11-20', amount: 18450.75, state: 'posted' } },
    { odooId: 78, number: 'INV/2026/00078', title: 'INV/2026/00078', partner: PARTNER_B, date: '2026-11-21', state: 'posted', amount: 950, currency: 'USD', user: null, active: true, cells: { number: 'INV/2026/00078', partner: PARTNER_B, date: '2026-11-21', amount: 950, state: 'posted' } },
  ], { total: 9120, totals: { amount: 19400.75 } }),
  product: page([
    { odooId: 7, number: 'DT-001', title: 'تمر سكري فاخر 1 كجم', partner: null, date: null, state: null, amount: 30, currency: 'SAR', user: null, active: true, cells: { number: 'DT-001', title: 'تمر سكري فاخر 1 كجم', amount: 30 } },
    { odooId: 9, number: 'OL-750', title: 'Olive oil 750 ml', partner: null, date: null, state: null, amount: 42.8621, currency: 'SAR', user: null, active: true, cells: { number: 'OL-750', title: 'Olive oil 750 ml', amount: 42.8621 } },
  ], { total: 4180 }),
  hr_employee: page([
    { odooId: 3, number: null, title: 'موظف تجريبي أ', partner: null, date: null, state: null, amount: null, currency: null, user: null, active: true, cells: { title: 'موظف تجريبي أ', job: 'محاسب', department: 'المالية' } },
  ], { total: 31 }),
  hr_contract: page([], { total: 0 }),
  x_route_note: page([], { total: 0 }),
};

// ---------------------------------------------------------------- GET docs/{kind}/{odooId}
const LINE_COLUMNS = [
  { key: 'product', label: L('الصنف', 'Product'), type: 'product' },
  { key: 'name', label: L('الوصف', 'Description'), type: 'text' },
  { key: 'qty', label: L('الكمية', 'Quantity'), type: 'qty' },
  { key: 'price', label: L('سعر الوحدة', 'Unit price'), type: 'money' },
  { key: 'amount', label: L('الإجمالي', 'Subtotal'), type: 'money' },
];

export const docs = {
  'sale_order:123': {
    kind: 'sale_order', model: 'sale.order', odooId: 123, number: 'S00123', title: 'أمر بيع لشركة الواحة',
    state: { value: 'sale', label: L('أمر بيع', 'Sales order'), tone: 'ok' },
    summary: [
      { label: L('العميل', 'Customer'), value: PARTNER, type: 'partner', link: { kind: 'customer', id: 42 } },
      { label: L('تاريخ الطلب', 'Order date'), value: '2026-11-20 08:15:00', type: 'datetime' },
      { label: L('صالح حتى', 'Valid until'), value: '2026-12-20', type: 'date' },
      { label: L('مندوب المبيعات', 'Salesperson'), value: 'سالم العتيبي', type: 'user' },
      { label: L('الحالة', 'Status'), value: 'sale', type: 'state' },
      // §3 gives a summary entry no currency (a list item has one): the screen shows the figure alone
      { label: L('الإجمالي قبل الضريبة', 'Untaxed amount'), value: 16044.13, type: 'money' },
      { label: L('الإجمالي', 'Total'), value: 18450.75, type: 'money' },
      { label: L('عدد الأسطر', 'Lines'), value: 450, type: 'number' },
      { label: L('إجمالي الكمية', 'Total quantity'), value: 9120.5, type: 'qty' },
      { label: L('مفوتر بالكامل', 'Fully invoiced'), value: false, type: 'bool' },
      { label: L('أبرز صنف', 'Main product'), value: PRODUCT, type: 'product' },
      { label: L('الفاتورة', 'Invoice'), value: 'INV/2026/00077', type: 'text', link: { kind: 'customer_invoice', id: 77 } },
      { label: L('مرجع العميل', 'Customer reference'), value: false, type: 'text' },
      { label: L('الشروط', 'Terms'), value: '<p>الدفع خلال 30 يومًا من تاريخ الفاتورة.</p><p>Delivery to the customer&#39;s warehouse in Riyadh.</p>', type: 'text' },
    ],
    sections: [
      {
        key: 'lines', label: L('أسطر الأمر', 'Order lines'), kind: 'sale_order_line', columns: LINE_COLUMNS,
        rows: [
          { odooId: 9001, cells: { product: PRODUCT, name: 'تمر سكري فاخر 1 كجم — كرتون 12', qty: 400, price: 30, amount: 12000 } },
          { odooId: 9002, cells: { product: PRODUCT_B, name: 'Olive oil 750 ml', qty: 150.5, price: 42.8621, amount: 6450.75 } },
        ],
        total: 450, more: true,
      },
      { key: 'options', label: L('أصناف اختيارية', 'Optional products'), kind: 'sale_order_option', columns: LINE_COLUMNS.slice(0, 3), rows: [], total: 0, more: false },
    ],
    related: [
      { label: L('الفواتير', 'Invoices'), kind: 'customer_invoice', items: [{ odooId: 77, number: 'INV/2026/00077', date: '2026-11-20', state: 'posted', amount: 18450.75 }, { odooId: 79, number: 'RINV/2026/00003', date: '2026-11-21', state: 'draft', amount: -450 }] },
      { label: L('التحويلات المخزنية', 'Stock transfers'), kind: 'stock_picking', items: [{ odooId: 501, number: 'WH/OUT/00501', date: '2026-11-21 05:00:00', state: 'done', amount: null }] },
    ],
    history: [
      { at: '2026-11-20 09:02:11', author: 'سالم العتيبي', type: 'note', text: 'العميل طلب التسليم قبل نهاية الشهر.', changes: [] },
      { at: '2026-11-20 08:40:00', author: 'Mona Hassan', type: 'email', text: '<p>Dear customer,</p><p>Your order <b>S00123</b> is confirmed.</p>', changes: [] },
      { at: '2026-11-20 08:16:30', author: 'OdooBot', type: 'system', text: '', changes: [{ field: 'الحالة', old: 'عرض سعر', new: 'أمر بيع' }, { field: 'تاريخ التأكيد', old: null, new: '2026-11-20 08:16:30' }] },
      { at: '2026-11-20 08:15:00', author: false, type: 'system', text: 'أُنشئ أمر البيع', changes: [] },
    ],
    attachments: [
      { id: 301, name: 'طلب شراء العميل.pdf', mimetype: 'application/pdf', size: 245760, archived: true, url: '/api/odoo-backup/files/301' },
      { id: 302, name: 'delivery-photo.jpg', mimetype: 'image/jpeg', size: 1887436, archived: false, url: null },
      { id: 303, name: 'price-list.xlsx', mimetype: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', size: 18250, archived: false, url: null },
    ],
    fields: [
      { name: 'name', label: 'مرجع الأمر', type: 'char', value: 'S00123' },
      { name: 'partner_id', label: 'العميل', type: 'many2one', value: [42, 'شركة الواحة للتجارة'] },
      { name: 'date_order', label: 'تاريخ الطلب', type: 'datetime', value: '2026-11-20 08:15:00' },
      { name: 'validity_date', label: 'انتهاء الصلاحية', type: 'date', value: '2026-12-20' },
      { name: 'amount_total', label: 'الإجمالي', type: 'monetary', value: 18450.75 },
      { name: 'state', label: 'الحالة', type: 'selection', value: 'sale' },
      { name: 'is_expired', label: 'منتهي', type: 'boolean', value: false },
      { name: 'client_order_ref', label: 'مرجع العميل', type: 'char', value: false },
      { name: 'tag_ids', label: 'الوسوم', type: 'many2many', value: [3, 7, 9] },
      { name: 'note', label: 'الشروط والأحكام', type: 'html', value: '<p>الدفع خلال 30 يومًا من تاريخ الفاتورة.</p>' },
      { name: 'x_studio_extra', label: false, type: 'char', value: 'قيمة حقل بلا تسمية' },
    ],
  },
  'stock_picking:501': {
    kind: 'stock_picking', model: 'stock.picking', odooId: 501, number: 'WH/OUT/00501', title: 'WH/OUT/00501',
    state: { value: 'done', label: L('تم', 'Done'), tone: 'ok' },
    summary: [
      { label: L('الشريك', 'Partner'), value: PARTNER, type: 'partner' },
      { label: L('الموعد المجدول', 'Scheduled'), value: '2026-11-21 05:00:00', type: 'datetime' },
      { label: L('تاريخ الإنجاز', 'Done on'), value: '2026-11-21', type: 'date' },
      { label: L('المستند المصدر', 'Source document'), value: 'S00123', type: 'text', link: { kind: 'sale_order', id: 123 } },
    ],
    sections: [
      {
        key: 'moves', label: L('الحركات', 'Moves'), kind: 'stock_move',
        columns: [{ key: 'product', label: L('الصنف', 'Product'), type: 'product' }, { key: 'qty', label: L('الكمية', 'Quantity'), type: 'qty' }, { key: 'done', label: L('المنجز', 'Done'), type: 'qty' }, { key: 'state', label: L('الحالة', 'Status'), type: 'state' }],
        rows: [{ odooId: 7001, cells: { product: PRODUCT, qty: 400, done: 400, state: 'done' } }],
        total: 1, more: false,
      },
    ],
    related: [], history: [], attachments: [], fields: [{ name: 'name', label: 'المرجع', type: 'char', value: 'WH/OUT/00501' }],
  },
  // people's files (needs odoo.private): an invented employee, work details only
  'hr_employee:3': {
    kind: 'hr_employee', model: 'hr.employee', odooId: 3, number: false, title: 'موظف تجريبي أ', state: null,
    summary: [{ label: L('الوظيفة', 'Job'), value: 'محاسب', type: 'text' }, { label: L('القسم', 'Department'), value: 'المالية', type: 'text' }],
    sections: [], related: [], history: [], attachments: [], fields: [{ name: 'name', label: 'اسم الموظف', type: 'char', value: 'موظف تجريبي أ' }],
  },
  // a plain record: the answer carries nothing but its fields (empty lists arrive as `[]`)
  'x_route_note:5': { kind: 'x_route_note', model: 'x_route.note', odooId: 5, number: false, title: 'ملاحظة خط الرياض — الخرج', state: null, summary: [], sections: [], related: [], history: [], attachments: [], fields: [{ name: 'x_name', label: 'الاسم', type: 'char', value: 'ملاحظة خط الرياض — الخرج' }] },
  'customer_invoice:77': {
    kind: 'customer_invoice', model: 'account.move', odooId: 77, number: 'INV/2026/00077', title: 'INV/2026/00077',
    state: { value: 'posted', label: L('مرحّلة', 'Posted'), tone: 'ok' },
    summary: [{ label: L('العميل', 'Customer'), value: PARTNER, type: 'partner' }, { label: L('تاريخ الفاتورة', 'Invoice date'), value: '2026-11-20', type: 'date' }, { label: L('الإجمالي', 'Total'), value: 18450.75, type: 'money' }],
    sections: [], related: [{ label: L('أمر البيع', 'Sales order'), kind: 'sale_order', items: [{ odooId: 123, number: 'S00123', date: '2026-11-20 08:15:00', state: 'sale', amount: 18450.75 }] }],
    history: [], attachments: [], fields: [],
  },
};

// ---------------------------------------------------------------- GET partners/{odooId} · GET products/{odooId}
// §3 describes these two in words. This is the shape the client was built on.
export const partner = {
  odooId: 42, name: 'شركة الواحة للتجارة', kind: 'customer',
  profile: [
    { label: L('الرقم الضريبي', 'VAT number'), value: '310000000000003', type: 'text' },
    { label: L('المدينة', 'City'), value: 'الرياض', type: 'text' },
    { label: L('الهاتف', 'Phone'), value: '+966 11 000 0000', type: 'text' },
    { label: L('مندوب المبيعات', 'Salesperson'), value: 'سالم العتيبي', type: 'user' },
    { label: L('عميل منذ', 'Customer since'), value: '2021-03-14', type: 'date' },
    { label: L('الشركة الأم', 'Parent company'), value: { id: 40, name: 'مجموعة الواحة القابضة' }, type: 'partner' },
  ],
  receivable: { amount: 61200.5, currency: 'SAR', count: 4 },
  payable: { amount: 0, currency: 'SAR', count: 0 },
  documents: [
    { kind: 'sale_order', label: L('أوامر البيع', 'Sales orders'), count: 1250, items: [{ odooId: 123, number: 'S00123', date: '2026-11-20 08:15:00', state: 'sale', amount: 18450.75, currency: 'SAR' }, { odooId: 119, number: 'S00119', date: '2026-11-12 10:00:00', state: 'cancel', amount: 900, currency: 'SAR' }] },
    { kind: 'customer_invoice', label: L('فواتير العملاء', 'Customer invoices'), count: 1190, items: [{ odooId: 77, number: 'INV/2026/00077', date: '2026-11-20', state: 'posted', amount: 18450.75, currency: 'SAR' }] },
    { kind: 'stock_picking', label: L('التحويلات المخزنية', 'Stock transfers'), count: 1304, items: [] },
  ],
};
export const product = {
  odooId: 7, name: 'تمر سكري فاخر 1 كجم', code: 'DT-001', kind: 'product',
  profile: [
    { label: L('الفئة', 'Category'), value: 'تمور', type: 'text' },
    { label: L('وحدة القياس', 'Unit of measure'), value: 'كرتون', type: 'text' },
    { label: L('سعر البيع', 'Sales price'), value: 30, type: 'money', currency: 'SAR' },
    { label: L('الباركود', 'Barcode'), value: '6280000000017', type: 'text' },
    { label: L('يُباع', 'Can be sold'), value: true, type: 'bool' },
  ],
  stock: [
    { warehouseId: 1, warehouse: 'مستودع الرياض الرئيسي', qty: 1250, reserved: 400, available: 850 },
    { warehouseId: 2, warehouse: 'Jeddah DC', qty: 310.5, reserved: 0, available: 310.5 },
  ],
  documents: [
    { kind: 'sale_order_line', label: L('آخر المبيعات', 'Latest sales'), count: 2104, items: [{ odooId: 9001, number: 'S00123', date: '2026-11-20 08:15:00', state: 'sale', amount: 12000, currency: 'SAR', qty: 400, partner: PARTNER }] },
    { kind: 'purchase_order_line', label: L('آخر المشتريات', 'Latest purchases'), count: 96, items: [{ odooId: 4001, number: 'P00077', date: '2026-11-10 07:00:00', state: 'purchase', amount: 21000, currency: 'SAR', qty: 1000 }] },
    { kind: 'stock_move', label: L('آخر حركات المخزون', 'Latest stock moves'), count: 5120, items: [{ odooId: 7001, number: 'WH/OUT/00501', date: '2026-11-21 05:00:00', state: 'done', amount: null, qty: 400 }] },
  ],
};

// ---------------------------------------------------------------- GET search?q=
export const search = {
  groups: [
    { kind: 'sale_order', label: L('أوامر البيع', 'Sales orders'), total: 12, items: lists.sale_order.items.slice(0, 2) },
    { kind: 'customer', label: L('العملاء', 'Customers'), total: 1, items: [lists.customer.items[0]] },
    { kind: 'hr_employee', label: L('الموظفون', 'Employees'), total: 1, items: lists.hr_employee.items },
  ],
};

// ---------------------------------------------------------------- one entry: path + query → answer
const PRIVATE = new Set(['hr_employee', 'hr_contract']);
/** Thrown for what the API refuses; the render check turns it into the ApiError the client would get. */
export class Refusal extends Error {
  constructor(status, code, message, messageEn) { super(messageEn); this.status = status; this.code = code; this.messageAr = message; this.messageEn = messageEn; }
}
/**
 * The answer of GET /odoo-archive/<path> for a user who holds (or not) odoo.private.
 *   answer('/odoo-archive/docs/sale_order', { state: 'sale', page: 1, pageSize: 30 }, { private: false })
 * `q=nothing` matches no document, as a search for words nobody wrote would.
 */
export function answer(path, params = {}, user = { private: true }) {
  const parts = String(path).replace(/^\/odoo-archive\/?/, '').split('/').filter(Boolean).map(decodeURIComponent);
  const guard = (kind) => { if (PRIVATE.has(kind) && !user.private) throw new Refusal(403, 'ODOO_PRIVATE', 'هذا النوع من ملفات الأشخاص ويحتاج صلاحية خاصة', 'This kind holds people’s files and needs its own permission'); };
  const missing = () => new Refusal(404, 'NOT_FOUND', 'السجل غير موجود في الأرشيف', 'The record is not in the archive');
  if (parts[0] === 'catalog') return user.private ? catalog : withoutPrivate(catalog);
  if (parts[0] === 'search') {
    if (params.q === 'nothing') return { groups: [] };
    return user.private ? search : { groups: search.groups.filter((g) => !PRIVATE.has(g.kind)) };
  }
  if (parts[0] === 'partners') { if (parts[1] === '42') return partner; throw missing(); }
  if (parts[0] === 'products') { if (parts[1] === '7') return product; throw missing(); }
  if (parts[0] === 'docs' && parts.length === 2) {
    guard(parts[1]);
    const list = lists[parts[1]];
    if (!list) throw missing();
    if (params.q === 'nothing') return page([], { pageSize: list.pageSize, totals: list.totals ? { amount: 0 } : undefined });
    return { ...list, page: Number(params.page) || 1, pageSize: Number(params.pageSize) || list.pageSize };
  }
  if (parts[0] === 'docs' && parts.length === 3) {
    guard(parts[1]);
    const doc = docs[`${parts[1]}:${parts[2]}`];
    if (!doc) throw missing();
    return doc;
  }
  throw missing();
}

// ================================================================================================================
// 2. What the server really sends (app/Integration/Odoo/Archive — ArchiveReader, ArchiveCells, ArchiveDocument — and
//    tests/Feature/Integration/OdooArchiveTest.php, read on 2026-11-24). The differences from §3 as written:
//      · a label is `label` (Arabic) + `labelEn`, everywhere; a kind also says what ONE document is called (`one`, `oneEn`);
//      · a kind carries `plain`, `line`, `view`, `totals`; `defaultSort` is a key, its direction is `defaultOrder`;
//      · the wording of a state is in the `options` of its COLUMN (with `count`), also in the columns of a section;
//        the state of a document and a state in its summary are `{ value, label, labelEn, tone }`; elsewhere it is bare;
//      · the catalog carries `lookups` (the kinds a partner / product picker searches) and a section `locked`;
//      · a list answers `totals: { amount, currency }` (amount null when the matches are in several currencies) and
//        `kind`; an item adds `kind`, `deleted`, `parent`; a moment is ISO 8601 UTC (`…Z`), a day `Y-m-d`;
//      · a document adds `label`, `partner`, `date`, `amount`, `currency`, `user`, `active`, `deleted`, `private`,
//        `parent`, `historyTotal`; a summary entry has a `key`; a section and a related group carry `query` (what
//        finds them in the list of their kind), a related group `total` / `more`; a change of the history is typed
//        (number, true / false, day, moment) and names its field twice (`field` the label, `name` the technical name);
//        an attachment adds `at` and `link`; a field is the record as it is in Odoo, as §3 writes it — Odoo's type
//        (null where unknown), the value as copied (null or Odoo's `false` when empty) — plus `valueLabel`, the name
//        Odoo gives a choice;
//      · a partner: `summary`, `receivable` / `payable` `{ amount, currency, documents }`, `groups` (`total`, `amount`,
//        `query`), `isCustomer`, `isSupplier`, `contacts`; a product: `code`, `stock: { asOf, qty, reserved, available,
//        warehouses: [...] }`, `groups`;
//      · the search answers `q` and, per group, the section of the kind.
const B = (ar, en) => ({ label: ar, labelEn: en });
const opt = (value, ar, en, tone, count) => ({ value, label: ar, labelEn: en, tone, ...(count == null ? {} : { count }) });
const col = (key, type, ar, en, options) => ({ key, ...B(ar, en), type, ...(options ? { options } : {}) });
const SALE_STATE = [opt('draft', 'عرض سعر', 'Quotation', 'neutral', 1), opt('sent', 'عرض سعر مُرسل', 'Quotation sent', 'blue'), opt('sale', 'أمر بيع', 'Sales order', 'ok', 3), opt('done', 'مقفل', 'Locked', 'ok'), opt('cancel', 'ملغى', 'Cancelled', 'off', 1), opt('pos_order', 'طلب نقطة بيع', 'PoS order', 'violet')];
const INVOICE_STATUS = [opt('no', 'لا شيء للفوترة', 'Nothing to invoice', 'neutral'), opt('to invoice', 'بانتظار الفوترة', 'To invoice', 'warn'), opt('invoiced', 'مفوتر بالكامل', 'Fully invoiced', 'ok'), opt('upselling', 'فرصة بيع إضافي', 'Upselling', 'blue')];
const DELIVERY_STATUS = [opt('pending', 'لم يُسلَّم', 'Not delivered', 'warn'), opt('partial', 'مسلَّم جزئيًا', 'Partially delivered', 'warn'), opt('full', 'مسلَّم بالكامل', 'Fully delivered', 'ok')];
const MOVE_STATE = [opt('draft', 'مسودة', 'Draft', 'neutral', 1), opt('posted', 'مرحّل', 'Posted', 'ok', 2), opt('cancel', 'ملغى', 'Cancelled', 'off')];
const PAYMENT_STATE = [opt('not_paid', 'غير مدفوع', 'Not paid', 'warn'), opt('partial', 'مدفوع جزئيًا', 'Partially paid', 'warn'), opt('paid', 'مدفوع', 'Paid', 'ok')];
const TRANSFER_STATE = [opt('assigned', 'جاهز', 'Ready', 'blue', 1), opt('done', 'تم', 'Done', 'ok', 2), opt('cancel', 'ملغى', 'Cancelled', 'off')];
const REEF = { id: 10, name: 'مطعم الريف' };
const REEF_BRANCH = { id: 14, name: 'فرع الريف - العليا' };
const FLOUR = { id: 200, name: '[P0001] طحين فاخر Flour 10kg' };
const OIL = { id: 201, name: '[P0002] زيت Oil 1L' };
const LINE_COLS = [col('product', 'product', 'المنتج', 'Product'), col('title', 'text', 'الوصف', 'Description'), col('product_uom_qty', 'qty', 'الكمية', 'Quantity'), col('price_unit', 'money', 'سعر الوحدة', 'Unit price'), col('amount', 'money', 'الإجمالي قبل الضريبة', 'Subtotal'), col('state', 'state', 'الحالة', 'Status', SALE_STATE)];
const kindOut = (key, ar, en, oneAr, oneEn, model, count, more) => ({ key, ...B(ar, en), one: oneAr, oneEn, model, count, private: false, plain: false, line: false, view: false, sorts: ['date'], defaultSort: 'date', defaultOrder: 'desc', totals: false, ...more });

const serverCatalog = {
  ready: true,
  indexed: 5 + 5 + 6 + 3 + 3 + 6 + 1 + 2 + 1 + 1 + 2, // every kind once: the customers are a view of the contacts
  sections: [
    { key: 'sales', ...B('المبيعات', 'Sales'), locked: false, kinds: [
      kindOut('sale_order', 'أوامر البيع', 'Sales orders', 'أمر بيع', 'Sales order', 'sale.order', 5, {
        columns: [col('number', 'text', 'الرقم', 'Number'), col('partner', 'partner', 'العميل', 'Customer'), col('date', 'datetime', 'تاريخ الطلب', 'Order date'), col('user', 'user', 'المندوب', 'Salesperson'), col('amount_untaxed', 'money', 'قبل الضريبة', 'Untaxed'), col('amount', 'money', 'الإجمالي', 'Total'),
          col('invoice_status', 'state', 'الفوترة', 'Invoicing', INVOICE_STATUS), col('delivery_status', 'state', 'التسليم', 'Delivery', DELIVERY_STATUS), col('state', 'state', 'الحالة', 'Status', SALE_STATE)],
        filters: [col('state', 'choice', 'الحالة', 'Status', SALE_STATE), col('date', 'daterange', 'تاريخ الطلب', 'Order date'), col('partner', 'partner', 'العميل', 'Customer'), col('dim1', 'choice', 'حالة الفوترة', 'Invoice status', INVOICE_STATUS), col('dim2', 'choice', 'حالة التسليم', 'Delivery status', DELIVERY_STATUS)],
        sorts: ['date', 'number', 'amount'], totals: true,
      }),
      kindOut('sale_order_line', 'بنود أوامر البيع', 'Sales order lines', 'بند أمر بيع', 'Sales order line', 'sale.order.line', 5, {
        line: true, totals: true,
        columns: [col('number', 'text', 'أمر البيع', 'Sales order'), col('date', 'datetime', 'تاريخ الطلب', 'Order date'), col('partner', 'partner', 'العميل', 'Customer'), ...LINE_COLS],
        filters: [col('state', 'choice', 'الحالة', 'Status', SALE_STATE), col('date', 'daterange', 'تاريخ الطلب', 'Order date'), col('partner', 'partner', 'العميل', 'Customer'), col('product', 'product', 'المنتج', 'Product')],
        sorts: ['date', 'number', 'amount'],
      }),
      // a view: another list of the contacts — counted once, in `contact`
      kindOut('customer', 'العملاء', 'Customers', 'عميل', 'Customer', 'res.partner', 4, { view: true, columns: [col('title', 'text', 'الاسم', 'Name'), col('number', 'text', 'المرجع', 'Reference'), col('city', 'text', 'المدينة', 'City'), col('active', 'bool', 'نشط', 'Active')], filters: [col('date', 'daterange', 'تاريخ الإنشاء', 'Created')], sorts: ['date', 'title', 'number'] }),
      kindOut('contact', 'جهات الاتصال', 'Contacts', 'جهة اتصال', 'Contact', 'res.partner', 6, { columns: [col('title', 'text', 'الاسم', 'Name'), col('number', 'text', 'المرجع', 'Reference'), col('city', 'text', 'المدينة', 'City'), col('active', 'bool', 'نشط', 'Active')], filters: [col('date', 'daterange', 'تاريخ الإنشاء', 'Created'), col('partner', 'partner', 'الشركة', 'Company')], sorts: ['date', 'title', 'number'] }),
    ] },
    { key: 'inventory', ...B('المخزون', 'Inventory'), locked: false, kinds: [
      kindOut('transfer', 'أذون المخزون', 'Transfers', 'إذن مخزون', 'Transfer', 'stock.picking', 3, {
        columns: [col('number', 'text', 'الرقم', 'Number'), col('partner', 'partner', 'الشريك', 'Partner'), col('date', 'datetime', 'الموعد', 'Scheduled'), col('origin', 'text', 'المستند المصدر', 'Source document'), col('state', 'state', 'الحالة', 'Status', TRANSFER_STATE)],
        // a dimension that names a record offers the records in use, by their name
        filters: [col('state', 'choice', 'الحالة', 'Status', TRANSFER_STATE), col('date', 'daterange', 'الموعد', 'Scheduled'), col('partner', 'partner', 'الشريك', 'Partner'), col('dim1', 'choice', 'نوع العملية', 'Operation type', [opt('1', 'Receipts', 'Receipts', 'neutral', 1), opt('2', 'Delivery Orders', 'Delivery Orders', 'neutral', 2)]), col('dim2', 'text', 'المستند المصدر', 'Source document')],
        sorts: ['date', 'number'],
      }),
    ] },
    { key: 'accounting', ...B('المحاسبة', 'Accounting'), locked: false, kinds: [
      kindOut('customer_invoice', 'فواتير العملاء', 'Customer invoices', 'فاتورة عميل', 'Customer invoice', 'account.move', 3, {
        totals: true,
        columns: [col('number', 'text', 'الرقم', 'Number'), col('partner', 'partner', 'العميل', 'Customer'), col('date', 'date', 'تاريخ الفاتورة', 'Invoice date'), col('amount', 'money', 'الإجمالي', 'Total'), col('residual', 'money', 'المتبقي', 'Amount due'), col('payment_state', 'state', 'السداد', 'Payment', PAYMENT_STATE), col('state', 'state', 'الحالة', 'Status', MOVE_STATE)],
        filters: [col('state', 'choice', 'الحالة', 'Status', MOVE_STATE), col('date', 'daterange', 'تاريخ الفاتورة', 'Invoice date'), col('partner', 'partner', 'العميل', 'Customer'), col('dim1', 'choice', 'حالة السداد', 'Payment status', PAYMENT_STATE), col('dim2', 'choice', 'أمر البيع', 'Sales order', [opt('S00001', 'S00001', 'S00001', 'neutral', 2), opt('S00002', 'S00002', 'S00002', 'neutral', 1)])],
        sorts: ['date', 'number', 'amount'],
      }),
      kindOut('journal_item', 'بنود القيود', 'Journal items', 'بند قيد', 'Journal item', 'account.move.line', 6, { line: true, columns: [col('number', 'text', 'القيد', 'Entry'), col('title', 'text', 'البيان', 'Label'), col('debit', 'money', 'مدين', 'Debit'), col('credit', 'money', 'دائن', 'Credit')], filters: [col('date', 'daterange', 'التاريخ', 'Date'), col('product', 'product', 'المنتج', 'Product')] }),
      kindOut('payment', 'المدفوعات', 'Payments', 'دفعة', 'Payment', 'account.payment', 1, {
        totals: true,
        columns: [col('number', 'text', 'الرقم', 'Number'), col('partner', 'partner', 'الشريك', 'Partner'), col('date', 'date', 'التاريخ', 'Date'), col('amount', 'money', 'المبلغ', 'Amount'), col('state', 'state', 'الحالة', 'Status', MOVE_STATE)],
        filters: [col('state', 'choice', 'الحالة', 'Status', MOVE_STATE), col('date', 'daterange', 'التاريخ', 'Date'), col('partner', 'partner', 'الشريك', 'Partner')], sorts: ['date', 'number', 'amount'],
      }),
    ] },
    { key: 'products', ...B('المنتجات', 'Products'), locked: false, kinds: [
      kindOut('product', 'المنتجات', 'Products', 'منتج', 'Product', 'product.product', 2, { columns: [col('number', 'text', 'الرمز', 'Code'), col('title', 'text', 'الاسم', 'Name'), col('amount', 'money', 'سعر البيع', 'Sales price')], filters: [col('date', 'daterange', 'تاريخ الإنشاء', 'Created')], sorts: ['date', 'number', 'title', 'amount'] }),
    ] },
    { key: 'people', ...B('شؤون الموظفين', 'People'), locked: false, kinds: [
      // the staff directory is open (without the personal file); contracts are people's files
      kindOut('employee', 'الموظفون', 'Employees', 'موظف', 'Employee', 'hr.employee', 1, { columns: [col('title', 'text', 'الاسم', 'Name'), col('job_title', 'text', 'الوظيفة', 'Job')], filters: [col('date', 'daterange', 'تاريخ الإنشاء', 'Created')] }),
      kindOut('contract', 'العقود', 'Contracts', 'عقد', 'Contract', 'hr.contract', 1, { private: true, columns: [col('number', 'text', 'الرقم', 'Number'), col('date', 'date', 'البداية', 'Start'), col('amount', 'money', 'الأجر', 'Wage')], filters: [col('date', 'daterange', 'البداية', 'Start')], totals: true }),
    ] },
    { key: 'other', ...B('أخرى', 'Other'), locked: false, kinds: [
      kindOut('x_custom_thing', 'Custom Thing', 'Custom Thing', 'Custom Thing', 'Custom Thing', 'x_custom.thing', 2, { plain: true, columns: [col('number', 'text', 'الرقم', 'Number'), col('title', 'text', 'البيان', 'Description'), col('date', 'datetime', 'التاريخ', 'Date')], filters: [col('date', 'daterange', 'التاريخ', 'Date')] }),
    ] },
  ],
  lookups: { partner: 'contact', product: 'product' },
};
/** The same catalog for a user without odoo.private: the contracts are neither listed nor counted, their section says it holds more. */
const serverCatalogOpen = { ...serverCatalog, indexed: serverCatalog.indexed - 1, sections: serverCatalog.sections.map((s) => (s.key === 'people' ? { ...s, locked: true, kinds: s.kinds.filter((k) => !k.private) } : s)) };

const orderItem = (odooId, number, title, partner, date, state, untaxed, amount, invoiced, delivered, user, more = {}) => ({
  odooId, kind: 'sale_order', number, title, partner, date, state, amount, currency: 'SAR', user, active: true, deleted: false, parent: null,
  cells: { number, partner, date, user, amount_untaxed: untaxed, amount, invoice_status: invoiced, delivery_status: delivered, state }, ...more,
});
const ORDERS = [
  orderItem(5, 'S00005', null, REEF_BRANCH, '2026-04-15T12:00:00Z', 'sale', 300, 345, 'invoiced', 'full', 'Sara Sales'),
  orderItem(2, 'S00002', null, REEF, '2026-03-10T09:00:00Z', 'sale', 200, 230, 'to invoice', 'partial', null),
  orderItem(1, 'S00001', 'PO-REEF-77', REEF, '2026-03-05T21:30:00Z', 'sale', 1000, 1150, 'invoiced', 'full', 'Sara Sales'),
];
const lineItem = (odooId, product, title, qty, price, amount) => ({
  odooId, kind: 'sale_order_line', number: 'S00001', title, partner: REEF, date: '2026-03-05T21:30:00Z', state: 'sale', amount, currency: 'SAR', user: 'Sara Sales', active: true, deleted: false,
  parent: { model: 'sale.order', kind: 'sale_order', id: 1 },
  cells: { number: 'S00001', date: '2026-03-05T21:30:00Z', partner: REEF, product, title, product_uom_qty: qty, price_unit: price, amount, state: 'sale' },
});
const LINES = [lineItem(1002, OIL, 'زيت Oil 1L', 20, 10, 200), lineItem(1001, FLOUR, 'طحين فاخر Flour 10kg', 16, 50, 800)];
const contactItem = (odooId, title, number, city, active = true) => ({ odooId, kind: 'contact', number, title, partner: null, date: '2026-01-10T08:00:00Z', state: null, amount: null, currency: null, user: null, active, deleted: false, parent: null, cells: { title, number, city, active } });
const CONTACTS = [contactItem(10, 'مطعم الريف', 'C-10', 'الرياض'), contactItem(14, 'فرع الريف - العليا', null, 'الرياض'), contactItem(15, 'عميل مؤرشف', null, null, false)];
const invoiceItem = (odooId, number, partner, date, amount, residual, paid, state, currency = 'SAR') => ({ odooId, kind: 'customer_invoice', number, title: null, partner, date, state, amount, currency, user: 'Sara Sales', active: true, deleted: false, parent: null, cells: { number, partner, date, amount, residual, payment_state: paid, state } });
const NO_TOTAL = { amount: null, currency: null };

const serverLists = {
  sale_order: { items: ORDERS, total: 5, totals: { amount: 2399.5, currency: 'SAR' } },
  sale_order_line: { items: LINES, total: 450, totals: { amount: 1000, currency: 'SAR' } },
  contact: { items: CONTACTS, total: 6, totals: NO_TOTAL },
  customer: { items: CONTACTS.slice(0, 2), total: 4, totals: NO_TOTAL },
  transfer: { items: [{ odooId: 500, kind: 'transfer', number: 'WH/OUT/00001', title: null, partner: REEF, date: '2026-03-06T05:00:00Z', state: 'done', amount: null, currency: null, user: null, active: true, deleted: false, parent: { model: 'sale.order', kind: 'sale_order', id: 1 }, cells: { number: 'WH/OUT/00001', partner: REEF, date: '2026-03-06T05:00:00Z', origin: 'S00001', state: 'done' } }], total: 3, totals: NO_TOTAL },
  // invoices in two currencies: the API gives no single total
  customer_invoice: { items: [invoiceItem(905, 'INV/2026/00006', REEF_BRANCH, '2026-04-16', 300, 300, 'not_paid', 'draft', 'USD'), invoiceItem(900, 'INV/2026/00001', REEF, '2026-03-06', 1150, 150, 'partial', 'posted')], total: 3, totals: NO_TOTAL },
  product: { items: [{ odooId: 200, kind: 'product', number: 'P0001', title: 'طحين فاخر Flour 10kg', partner: null, date: '2026-01-05T08:00:00Z', state: null, amount: 50, currency: 'SAR', user: null, active: true, deleted: false, parent: null, cells: { number: 'P0001', title: 'طحين فاخر Flour 10kg', amount: 50 } }], total: 2, totals: NO_TOTAL },
  employee: { items: [{ odooId: 1, kind: 'employee', number: null, title: 'موظف تجريبي أ', partner: null, date: '2026-01-02T08:00:00Z', state: null, amount: null, currency: null, user: null, active: true, deleted: false, parent: null, cells: { title: 'موظف تجريبي أ', job_title: 'سائق' } }], total: 1, totals: NO_TOTAL },
  contract: { items: [{ odooId: 1, kind: 'contract', number: 'CONTRACT-001', title: null, partner: null, date: '2026-01-01', state: null, amount: 9000, currency: 'SAR', user: null, active: true, deleted: false, parent: null, cells: { number: 'CONTRACT-001', date: '2026-01-01', amount: 9000 } }], total: 1, totals: { amount: 9000, currency: 'SAR' } },
  x_custom_thing: { items: [], total: 0, totals: NO_TOTAL },
  journal_item: { items: [], total: 0, totals: NO_TOTAL },
  payment: { items: [{ odooId: 50, kind: 'payment', number: 'BNK1/2026/00001', title: null, partner: REEF, date: '2026-03-08', state: 'posted', amount: 1000, currency: 'SAR', user: null, active: true, deleted: false, parent: null, cells: { number: 'BNK1/2026/00001', partner: REEF, date: '2026-03-08', amount: 1000, state: 'posted' } }], total: 1, totals: { amount: 1000, currency: 'SAR' } },
};
/** What Odoo deleted and the archive kept (`deleted=1`). */
const DELETED_ORDER = orderItem(4, 'S00004', null, { id: 11, name: 'شركة النخبة' }, '2026-02-01T10:00:00Z', 'cancel', 600, 674.5, 'no', 'pending', null, { deleted: true });

const head = (kind, model, odooId, oneAr, oneEn, more) => ({ kind, model, odooId, ...B(oneAr, oneEn), number: null, title: null, state: null, partner: null, date: null, amount: null, currency: null, user: null, active: true, deleted: false, private: false, parent: null, ...more });
const sum = (key, type, ar, en, value, link) => ({ key, ...B(ar, en), type, value, ...(link ? { link } : {}) });
const serverDocs = {
  'sale_order:1': {
    ...head('sale_order', 'sale.order', 1, 'أمر بيع', 'Sales order', { number: 'S00001', title: 'PO-REEF-77', state: opt('sale', 'أمر بيع', 'Sales order', 'ok'), partner: REEF, date: '2026-03-05T21:30:00Z', amount: 1150, currency: 'SAR', user: 'Sara Sales' }),
    summary: [
      sum('partner_id', 'partner', 'العميل', 'Customer', REEF, { kind: 'contact', id: 10 }),
      sum('partner_shipping_id', 'text', 'عنوان التوصيل', 'Delivery address', 'فرع الريف - العليا', { kind: 'contact', id: 14 }),
      sum('date_order', 'datetime', 'تاريخ الطلب', 'Order date', '2026-03-05T21:30:00Z'),
      sum('validity_date', 'date', 'صلاحية العرض', 'Expiration', '2026-04-04'),
      sum('user_id', 'user', 'المندوب', 'Salesperson', 'Sara Sales', { kind: 'user', id: 7 }),
      sum('warehouse_id', 'text', 'المستودع', 'Warehouse', 'Riyadh warehouse', { kind: 'warehouse', id: 1 }),
      sum('amount_untaxed', 'money', 'قبل الضريبة', 'Untaxed amount', 1000),
      sum('amount_total', 'money', 'الإجمالي', 'Total', 1150),
      sum('invoice_status', 'state', 'حالة الفوترة', 'Invoice status', opt('invoiced', 'مفوتر بالكامل', 'Fully invoiced', 'ok')),
      sum('note', 'text', 'الشروط والملاحظات', 'Terms and notes', 'التسليم صباحًا'),
    ],
    sections: [{ key: 'lines', ...B('بنود الأمر', 'Order lines'), kind: 'sale_order_line', columns: LINE_COLS, rows: LINES.map((l) => ({ odooId: l.odooId, cells: l.cells })), total: 450, more: true, query: { parent: 'sale.order:1' } }],
    related: [
      { ...B('فواتير العميل', 'Customer invoices'), kind: 'customer_invoice', total: 1, more: false, items: [{ odooId: 900, kind: 'customer_invoice', number: 'INV/2026/00001', title: null, date: '2026-03-06', state: 'posted', amount: 1150, currency: 'SAR', partner: REEF }], query: { dim2: 'S00001' } },
      { ...B('أذون المخزون', 'Transfers'), kind: 'transfer', total: 12, more: true, items: [{ odooId: 500, kind: 'transfer', number: 'WH/OUT/00001', title: null, date: '2026-03-06T05:00:00Z', state: 'done', amount: null, currency: null, partner: REEF }], query: { parent: 'sale.order:1' } },
    ],
    history: [
      { id: 3002, at: '2026-03-06T07:00:00Z', author: 'Sara Sales', type: 'note', subject: null, text: 'Customer asked for morning delivery', changes: [] },
      { id: 3001, at: '2026-03-05T21:31:00Z', author: 'Sara Sales', type: 'system', subject: 'تأكيد الطلب', text: 'تم تأكيد الطلب S00001', changes: [
        { field: 'Status', name: 'state', old: 'Quotation', new: 'Sales Order' }, { field: 'Total', name: 'amount_total', old: 0, new: 1150.5 },
        { field: 'Locked', name: 'locked', old: false, new: true }, { field: 'Confirmed on', name: 'date_order', old: null, new: '2026-03-05T21:30:00Z' }, { field: 'Expiration', name: 'validity_date', old: '2026-03-20', new: '2026-04-04' },
        { field: '—', name: 'x_studio_ref', old: null, new: 'R-7' }, // a field the copy has no label for
      ] },
    ],
    historyTotal: 240,
    attachments: [
      { id: 7001, name: 'S00001.pdf', mimetype: 'application/pdf', size: 20480, archived: true, url: '/api/odoo-backup/files/7001', at: '2026-03-05T21:35:00Z', link: null },
      { id: 7002, name: 'signature.png', mimetype: 'image/png', size: 900, archived: false, url: null, at: '2026-03-06T07:10:00Z', link: null },
      { id: 7003, name: 'Tracking page', mimetype: null, size: 0, archived: false, url: null, at: null, link: 'https://carrier.example.com/track/S00001' },
    ],
    // the record as it is in Odoo: Odoo's own type, the value as it was copied, a choice also by Odoo's name of it
    fields: [
      { name: 'amount_total', label: 'Total', type: 'monetary', value: 1150 },
      { name: 'client_order_ref', label: 'Customer Reference', type: 'char', value: false },
      { name: 'date_order', label: 'date_order', type: 'datetime', value: '2026-03-05 21:30:00' },
      { name: 'locked', label: 'Locked', type: 'boolean', value: false },
      { name: 'note', label: 'Terms and conditions', type: 'html', value: '<p>التسليم <b>صباحًا</b></p>' },
      { name: 'origin', label: 'Source Document', type: 'char', value: null },
      { name: 'partner_id', label: 'Customer', type: 'many2one', value: [10, 'مطعم الريف'] },
      { name: 'state', label: 'Status', type: 'selection', value: 'sale', valueLabel: 'Sales Order' },
      { name: 'tag_ids', label: 'Tags', type: 'many2many', value: [3, 7] },
      { name: 'validity_date', label: 'Expiration', type: 'date', value: '2026-04-04' },
      { name: 'x_undescribed', label: 'x_undescribed', type: null, value: 12 },
    ],
  },
  // a line: it says what it hangs on
  'sale_order_line:1001': {
    ...head('sale_order_line', 'sale.order.line', 1001, 'بند أمر بيع', 'Sales order line', { number: 'S00001', title: 'طحين فاخر Flour 10kg', state: opt('sale', 'أمر بيع', 'Sales order', 'ok'), partner: REEF, date: '2026-03-05T21:30:00Z', amount: 800, currency: 'SAR', parent: { kind: 'sale_order', id: 1, ...B('أمر بيع', 'Sales order'), name: 'S00001' } }),
    summary: [sum('product_id', 'product', 'المنتج', 'Product', FLOUR, { kind: 'product', id: 200 }), sum('product_uom_qty', 'qty', 'الكمية', 'Quantity', 16)], sections: [], related: [], history: [], historyTotal: 0, attachments: [], fields: [],
  },
  // an invoice: the orders it came from (found by their ids — the first of them, and no `query`: nothing lists such a
  // group), and what settled it — a group marked `reconciled`, where the amount beside each document is what was
  // matched with the invoice, not the document's own
  'customer_invoice:900': {
    ...head('customer_invoice', 'account.move', 900, 'فاتورة عميل', 'Customer invoice', { number: 'INV/2026/00001', state: opt('posted', 'مرحّل', 'Posted', 'ok'), partner: REEF, date: '2026-03-06', amount: 1150, currency: 'SAR', user: 'Sara Sales' }),
    summary: [sum('partner_id', 'partner', 'العميل', 'Customer', REEF, { kind: 'contact', id: 10 }), sum('invoice_date', 'date', 'تاريخ الفاتورة', 'Invoice date', '2026-03-06'), sum('amount_total', 'money', 'الإجمالي', 'Total', 1150),
      sum('amount_residual', 'money', 'المتبقي', 'Amount due', 150), sum('payment_state', 'state', 'حالة السداد', 'Payment status', opt('partial', 'مدفوع جزئيًا', 'Partially paid', 'warn'))],
    sections: [],
    related: [
      { ...B('أوامر البيع', 'Sales orders'), kind: 'sale_order', total: 12, more: true, items: [{ odooId: 1, kind: 'sale_order', number: 'S00001', title: 'PO-REEF-77', date: '2026-03-05T21:30:00Z', state: 'sale', amount: 1150, currency: 'SAR', partner: REEF }], query: null },
      { ...B('التسويات والمدفوعات', 'Payments and settlements'), kind: 'payment', total: 1, more: false, items: [{ odooId: 50, kind: 'payment', number: 'BNK1/2026/00001', title: null, date: '2026-03-08', state: 'posted', amount: 1000, currency: 'SAR', partner: REEF }], query: null, reconciled: true },
    ],
    history: [], historyTotal: 0, attachments: [], fields: [],
  },
  // what Odoo deleted: the archive keeps its last values
  'sale_order:4': { ...head('sale_order', 'sale.order', 4, 'أمر بيع', 'Sales order', { number: 'S00004', state: opt('cancel', 'ملغى', 'Cancelled', 'off'), date: '2026-02-01T10:00:00Z', amount: 674.5, currency: 'SAR', deleted: true }), summary: [sum('amount_total', 'money', 'الإجمالي', 'Total', 674.5)], sections: [], related: [], history: [], historyTotal: 0, attachments: [], fields: [] },
  // asked as a customer, answered as what the record is: a contact
  'customer:10': { ...head('contact', 'res.partner', 10, 'جهة اتصال', 'Contact', { number: 'C-10', title: 'مطعم الريف' }), summary: [sum('vat', 'text', 'الرقم الضريبي', 'VAT', '300111222')], sections: [], related: [], history: [], historyTotal: 0, attachments: [], fields: [{ name: 'name', label: 'Name', type: 'text', odooType: 'char', value: 'مطعم الريف' }] },
  'contract:1': { ...head('contract', 'hr.contract', 1, 'عقد', 'Contract', { number: 'CONTRACT-001', date: '2026-01-01', amount: 9000, currency: 'SAR', private: true }), summary: [sum('wage', 'money', 'الأجر', 'Wage', 9000)], sections: [], related: [], history: [], historyTotal: 0, attachments: [], fields: [] },
};
serverDocs['contact:10'] = serverDocs['customer:10'];

const serverPartner = {
  ...head('contact', 'res.partner', 10, 'جهة اتصال', 'Contact', { number: 'C-10', title: 'مطعم الريف' }),
  name: 'مطعم الريف', isCustomer: true, isSupplier: false, contacts: 1,
  summary: [sum('vat', 'text', 'الرقم الضريبي', 'VAT', '300111222'), sum('city', 'text', 'المدينة', 'City', 'الرياض'), sum('user_id', 'user', 'المندوب', 'Salesperson', 'Sara Sales', { kind: 'user', id: 7 }), sum('parent_id', 'text', 'الشركة الأم', 'Parent company', 'مجموعة الريف', { kind: 'contact', id: 9 })],
  receivable: { amount: 265, currency: 'SAR', documents: 3 },
  payable: { amount: 0, currency: null, documents: 0 },
  groups: [
    { kind: 'sale_order', ...B('أوامر البيع', 'Sales orders'), total: 3, amount: 1725, items: ORDERS, query: { partner: 10 } },
    { kind: 'customer_invoice', ...B('فواتير العملاء', 'Customer invoices'), total: 2, amount: 1380, items: [serverLists.customer_invoice.items[1]], query: { partner: 10 } },
    { kind: 'transfer', ...B('أذون المخزون', 'Transfers'), total: 2, amount: null, items: serverLists.transfer.items, query: { partner: 10 } },
    { kind: 'contact', ...B('جهات الاتصال', 'Contacts'), total: 1, amount: null, items: [CONTACTS[1]], query: { partner: 10 } },
  ],
};
const serverProduct = {
  ...head('product', 'product.product', 200, 'منتج', 'Product', { number: 'P0001', title: 'طحين فاخر Flour 10kg', amount: 50, currency: 'SAR' }),
  name: 'طحين فاخر Flour 10kg', code: 'P0001',
  summary: [sum('barcode', 'text', 'الباركود', 'Barcode', '6281000000017'), sum('category', 'text', 'الفئة', 'Category', 'All / Food'), sum('standard_price', 'money', 'التكلفة', 'Cost', 31.5), sum('type', 'state', 'النوع', 'Type', opt('product', 'منتج مخزني', 'Storable product', 'ok')), sum('product_tmpl_id', 'text', 'قالب المنتج', 'Product template', 'طحين فاخر', { kind: 'product_template', id: 100 })],
  stock: { asOf: '2026-11-24T06:00:00.000000Z', qty: 37, reserved: 5, available: 32, warehouses: [
    { qty: 30, reserved: 5, available: 25, warehouseId: 1, warehouse: 'Riyadh warehouse', lots: 1, nearestExpiry: '2027-01-31' },
    { qty: 7, reserved: 0, available: 7, warehouseId: 2, warehouse: 'Jeddah warehouse', lots: 0, nearestExpiry: null },
  ] },
  groups: [
    { kind: 'sale_order_line', ...B('بنود أوامر البيع', 'Sales order lines'), total: 3, amount: null, items: [LINES[1]], query: { product: 200 } },
    { kind: 'journal_item', ...B('بنود القيود', 'Journal items'), total: 1, amount: null, items: [{ odooId: 9001, kind: 'journal_item', number: 'INV/2026/00001', title: 'طحين فاخر Flour 10kg', partner: REEF, date: '2026-03-06', state: 'posted', amount: 800, currency: 'SAR', user: null, active: true, deleted: false, parent: { model: 'account.move', kind: 'customer_invoice', id: 900 }, cells: {} }], query: { product: 200 } },
  ],
};
const serverSearch = { q: 'S00001', groups: [
  { kind: 'sale_order', ...B('أوامر البيع', 'Sales orders'), section: 'sales', sectionLabel: 'المبيعات', sectionLabelEn: 'Sales', total: 1, items: [ORDERS[2]] },
  { kind: 'contact', ...B('جهات الاتصال', 'Contacts'), section: 'sales', sectionLabel: 'المبيعات', sectionLabelEn: 'Sales', total: 8, items: CONTACTS.slice(0, 2) },
  { kind: 'contract', ...B('العقود', 'Contracts'), section: 'people', sectionLabel: 'شؤون الموظفين', sectionLabelEn: 'People', total: 1, items: serverLists.contract.items },
] };

/** The answer of GET /odoo-archive/<path> as the server gives it — the same entry as `answer()` above. */
function answerAsServer(path, params = {}, user = { private: true }) {
  const parts = String(path).replace(/^\/odoo-archive\/?/, '').split('/').filter(Boolean).map(decodeURIComponent);
  const refuse = () => new Refusal(403, 'ODOO_PRIVATE', 'هذه السجلات من ملفات الأشخاص — عرضها يحتاج صلاحية odoo.private', 'These records are people’s files — reading them needs odoo.private');
  const missing = (code = 'ODOO_DOC_NOT_FOUND') => new Refusal(404, code, 'السجل غير موجود في أرشيف أودو', 'The record is not in the Odoo archive');
  if (parts[0] === 'catalog') return user.private ? serverCatalog : serverCatalogOpen;
  if (parts[0] === 'search') return String(params.q || '').trim().length < 2 || params.q === 'nothing' ? { q: String(params.q || ''), groups: [] } : { ...serverSearch, groups: serverSearch.groups.filter((g) => user.private || g.kind !== 'contract') };
  if (parts[0] === 'partners') { if (parts[1] === '10') return serverPartner; throw missing('ODOO_PARTNER_NOT_FOUND'); }
  if (parts[0] === 'products') { if (parts[1] === '200') return serverProduct; throw missing('ODOO_PRODUCT_NOT_FOUND'); }
  if (parts[0] === 'docs' && parts.length === 2) {
    if (parts[1] === 'contract' && !user.private) throw refuse();
    const list = serverLists[parts[1]];
    if (!list) throw missing('ODOO_KIND_NOT_FOUND');
    const pageSize = Number(params.pageSize) || 50;
    const items = String(params.deleted) === '1' ? (parts[1] === 'sale_order' ? [DELETED_ORDER] : []) : params.q === 'nothing' ? [] : list.items;
    const whole = items === list.items;
    const total = whole ? list.total : items.length;
    return { items, total, page: Number(params.page) || 1, pageSize, pages: Math.max(1, Math.ceil(total / pageSize)), totals: whole ? list.totals : { amount: list.totals.amount == null ? null : items.reduce((n, i) => n + (i.amount || 0), 0), currency: list.totals.currency }, kind: parts[1] };
  }
  if (parts[0] === 'docs' && parts.length === 3) {
    if (parts[1] === 'contract' && !user.private) throw refuse();
    const doc = serverDocs[`${parts[1]}:${parts[2]}`];
    if (!doc) throw missing();
    return doc;
  }
  throw missing();
}
export const server = { catalog: serverCatalog, catalogOpen: serverCatalogOpen, lists: serverLists, docs: serverDocs, partner: serverPartner, product: serverProduct, search: serverSearch, answer: answerAsServer };
