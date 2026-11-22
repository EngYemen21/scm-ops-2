<?php

// Odoo → OPS live mirror (docs/odoo/ARCHITECTURE.ar.md). OPS only ever READS from Odoo: the connector refuses every
// method that is not a read before anything is sent, so nothing in Odoo can be created, changed or deleted from here,
// whatever the Odoo user is allowed to do. Secrets come only from the environment; code reads config('odoo…').

return [
    'enabled' => (bool) env('ODOO_ENABLED', false),

    // https://erp.example.com (no trailing slash). Plain http is accepted for localhost only.
    'url' => rtrim((string) env('ODOO_URL', ''), '/'),
    'db' => env('ODOO_DB'),
    'login' => env('ODOO_LOGIN'),
    // API key of the Odoo user (Odoo 14+). A dedicated user with read-only access rights is the right owner of it.
    'key' => env('ODOO_API_KEY'),

    // auto = /json/2 on Odoo 19+, /jsonrpc before it
    'api' => env('ODOO_API', 'auto'),
    // language of translated values (null = the Odoo user's own language)
    'lang' => env('ODOO_LANG'),
    'timeout_seconds' => (int) env('ODOO_TIMEOUT', 25),

    // ── what is mirrored ─────────────────────────────────────────────────────────────────────────────────────────
    // Every model the Odoo user can read is mirrored, except technical ones. A pattern ends with "." (prefix), starts
    // with "." (suffix) or is an exact model name. `include` wins over `exclude`. ODOO_EXCLUDE adds business models a
    // deployment leaves out (for example `account.move.line` where storage is short) — it decides what a model is when
    // it is first registered; a model already registered is paused or resumed from the screen.
    'exclude' => array_merge([
        'ir.', 'bus.', 'base.', 'base_import.', 'web.', 'web_editor.', 'web_tour.', 'report.', 'iap.', 'auth.', 'auth_totp.',
        'digest.', 'fetchmail.', 'mail.', 'discuss.', 'im_livechat.', 'sms.', 'snailmail.', 'phone.', 'spreadsheet.', 'studio.',
        'website.', 'social.', 'payment.', 'res.config', 'res.users.log', 'res.users.apikeys', 'res.users.identitycheck',
        'res.users.deletion', 'change.password.', 'portal.', '.wizard', 'data_recycle.', 'data_merge.', 'onboarding.',
        'rating.', 'utm.', 'link.tracker', 'privacy.', 'google.', 'microsoft.', 'voip.', 'whatsapp.', 'appointment.',
        'knowledge.', 'sign.log', 'barcodes.', 'html.', 'format.', 'image.', 'avatar.', 'decimal.precision', '_unknown',
    ], array_values(array_filter(array_map('trim', explode(',', (string) env('ODOO_EXCLUDE', '')))))),
    'include' => array_values(array_filter(array_map('trim', explode(',', (string) env('ODOO_INCLUDE', ''))))),

    // PEOPLE'S FILES ARE NOT OPERATIONS DATA. Pay, contracts, leave, attendance, recruitment and bank accounts are
    // registered but NOT copied, even when the Odoo user can read them — until a person with integration.manage takes a
    // model in from the screen, or it is listed under ODOO_INCLUDE. Of HR only the directory is copied (who works
    // here, in which job and department), and without the personal part of an employee's file (`personal_fields`).
    'private' => ['hr.', 'hr_', 'res.partner.bank', 'resource.calendar.leaves'],
    'private_allow' => ['hr.employee', 'hr.employee.category', 'hr.department', 'hr.job', 'hr.work.location'],
    // an employee's private file: identity papers, family, home address and phone, bank account, pay
    'personal_fields' => '/^(private_|ssnid$|sinid$|identification_id$|passport_id$|visa_|permit_no$|work_permit|has_work_permit|birthday$|place_of_birth$|country_of_birth$|country_id$|gender$|marital$|spouse_|children$|emergency_|bank_account_id$|salary_|wage|study_|km_home_work|distance_home_work|is_non_resident$|additional_note$|notes$)/i',

    // Field values that are never copied, whatever the model: binary content, and anything that works as a credential
    // (portal access tokens on orders and invoices, signup tokens on partners, passwords, keys, an attendance PIN).
    'never_fields' => '/pass(word|wd)?|secret|token|api_?key|totp|signup_|oauth|private_key|certificate|^pin$/i',
    'skip_types' => ['binary'],
    // One2many id lists are left out: the child model carries the link (many2one), so nothing is lost.
    'skip_one2many' => true,

    // Models synchronised on every run, in this order; everything else takes turns with the time that is left.
    'priority' => [
        // master data first: small, and everything else points at it
        'res.company', 'res.currency', 'uom.uom', 'product.category', 'product.template', 'product.product', 'product.packaging',
        'res.partner', 'stock.warehouse', 'stock.location', 'stock.picking.type', 'stock.lot', 'stock.quant',
        'product.pricelist', 'product.pricelist.item', 'product.supplierinfo', 'account.tax', 'account.account', 'account.journal',
        'fleet.vehicle', 'hr.employee', 'res.users',
        // then the documents people work in all day
        'sale.order', 'sale.order.line', 'purchase.order', 'purchase.order.line', 'stock.picking', 'stock.move', 'stock.move.line',
        'pos.order', 'pos.order.line', 'account.payment', 'account.move', 'account.move.line',
    ],

    // ── from the mirror to OPS documents ─────────────────────────────────────────────────────────────────────────
    'project' => [
        // products, customers and suppliers of Odoo become / refresh OPS master data after every run (OdooMasterData).
        // Off: the mirror is kept, OPS master data is only touched when a person applies the plan.
        'master' => (bool) env('ODOO_PROJECT_MASTER', false),
    ],

    // ── how ──────────────────────────────────────────────────────────────────────────────────────────────────────
    'page_size' => (int) env('ODOO_PAGE_SIZE', 500),
    'max_page_size' => 2000,
    // every run re-reads this much before its cursor (records are stamped when written, committed a little later)
    'overlap_seconds' => (int) env('ODOO_OVERLAP_SECONDS', 180),
    // … and once in a while a much longer stretch, for transactions that stayed open for a long time
    'deep_overlap_seconds' => 3600,
    'deep_every_minutes' => 15,
    // deletions leave no trace in Odoo: they are found by comparing ids (priority models / the others)
    'deletion_check_minutes' => (int) env('ODOO_DELETION_MINUTES', 30),
    'deletion_check_minutes_other' => 360,
    'deletion_page' => 5000,
    // how long one run may work when it rides on the heartbeat (the serverless request limit is 60 s)
    'run_seconds' => (int) env('ODOO_RUN_SECONDS', 20),
    'rediscover_hours' => 24,
    // the change feed is a working log, not an archive
    'keep_changes_days' => (int) env('ODOO_KEEP_CHANGES_DAYS', 30),
    'backoff_minutes' => [1, 2, 5, 15, 60],
];
