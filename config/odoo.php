<?php

use App\Approvals\OdooApprovalsProjection;
use App\Crm\OdooCrmProjection;
use App\Hr\OdooHrProjection;

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

    // WHAT IS COPIED OUT OF THE OTHERWISE TECHNICAL PREFIXES (exact model names). The copy is the company's backup of
    // Odoo, so it keeps what gives the records their meaning and history: the conversation and the log of value changes
    // on every document, the list of attachments (their content is archived by `scm:odoo-files`), Odoo's own dictionary
    // of models, fields and choices (the copy can then be read without Odoo), the numbering sequences, and the business
    // tables of the customer portal.
    'content' => [
        'mail.message', 'mail.tracking.value', 'mail.activity', 'mail.activity.type', 'mail.message.subtype',
        'ir.attachment', 'ir.model', 'ir.model.fields', 'ir.model.fields.selection', 'ir.sequence', 'ir.sequence.date_range',
        'portal.customer.branch', 'portal.onboarding.application', 'utm.campaign', 'utm.medium', 'utm.source', 'utm.stage', 'utm.tag',
        'rating.rating', 'payment.transaction', 'payment.method', 'knowledge.article', 'sms.sms', 'discuss.channel',
        // small, and written by people: who sits in a channel, the wording of the company's e-mails and messages, the
        // activity plans, the contacts and the signature of a customer's onboarding application, the rounding of figures
        'discuss.channel.member', 'mail.template', 'sms.template', 'mail.activity.plan', 'mail.activity.plan.template',
        'portal.onboarding.responsible.person', 'portal.onboarding.signit.envelope', 'decimal.precision',
    ],
    // fields left out of one model by name: text Odoo derives from a file's content (large, and rebuilt from the file)
    'skip_fields' => ['ir.attachment' => ['index_content']],

    // PEOPLE'S FILES. Pay, contracts, leave, attendance, recruitment, bank accounts and the personal part of an
    // employee's record (`personal_fields`) are COPIED — the owner asked for a complete backup — and SHOWN only to a
    // user who holds `odoo.private`. A deployment that must not hold them at all sets ODOO_COPY_PRIVATE=false: they
    // are then registered but not copied until a person with integration.manage takes a model in from the screen.
    'copy_private' => (bool) env('ODOO_COPY_PRIVATE', true),
    // models that are not people's files but hold what people wrote to each other: copied, shown like people's files
    'restricted' => ['mail.message'],
    // (`attendance.` and `penalty.` are the attendance-sheet and staff-penalty modules installed on the company's Odoo)
    'private' => ['hr.', 'hr_', 'attendance.', 'penalty.', 'res.partner.bank', 'resource.calendar.leaves'],
    'private_allow' => ['hr.employee', 'hr.employee.category', 'hr.department', 'hr.job', 'hr.work.location'],
    // an employee's private file: identity papers, family, home address and phone, bank account, pay
    'personal_fields' => '/^(private_|ssnid$|sinid$|identification_id$|passport_id$|visa_|permit_no$|work_permit|has_work_permit|birthday$|place_of_birth$|country_of_birth$|country_id$|gender$|marital$|spouse_|children$|emergency_|bank_account_id$|salary_|wage|study_|certificate$|km_home_work|distance_home_work|is_non_resident$|additional_note$|notes$)/i',

    // Field values that are never copied, whatever the model: binary content, and anything that works as a credential —
    // portal access tokens on orders and invoices, signup tokens on partners, passwords, API and kiosk keys, an attendance
    // PIN, and the e-invoicing unit's certificate with its secret (`…_csid_json`, the private key, the certificate request).
    // The rule is by name, so it is written to miss what only LOOKS like one: `passport_id`, `…_checks_passed`, an
    // employee's education `certificate` are data and are copied (the personal ones are shown with `odoo.private` only).
    'never_fields' => '/password|passwd|(^|_)pass($|_)|secret|token|api_?key|(^|_)key$|totp|signup_|oauth|private_key|csid(?!_validity)|(^|_)csr($|_)|certificate_key|client_id$|provider_data$|callback_hash$|^pin$/i',
    // …and by model, where the name alone does not say it: the invitation key of a discussion channel
    'never_model_fields' => [
        'discuss.channel' => ['uuid', 'sfu_channel_uuid', 'sfu_server_url'],
        // what a signature or notification provider sent back: it can carry a signing link, which opens the document
        'portal.onboarding.signit.envelope' => ['last_payload'], 'b2b.credit.signit.envelope' => ['last_payload'],
        'b2b.credit.notification.log' => ['payload'],
    ],
    // Identity numbers and bank account numbers, in whatever model they stand (a customer's onboarding application, a
    // credit request): copied, and shown only to a user who holds `odoo.private`.
    'identity_fields' => '/(^|_)(national_id|identification_id|passport_id|passport_no|ssnid|sinid|iqama|iban|acc_number|account_number|visa_no|permit_no)($|_)/i',
    // A value Odoo works out when it is asked (not stored on the record) is normally left out as "computed". Some are
    // what people rely on and exist nowhere else in the copy: they are copied by name. The customer's price list is
    // one — Odoo 17 keeps it per company and answers the list in force for the partner.
    'computed_allow' => ['res.partner' => ['property_product_pricelist']],
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
        // the OPS sections Odoo had and OPS lacked (docs/odoo/COVERAGE.ar.md). On: the section follows Odoo — what Odoo
        // holds appears in it after every run and those records are Odoo's. Off: the section is handed over to OPS.
        'approvals' => (bool) env('ODOO_PROJECT_APPROVALS', true),
        'crm' => (bool) env('ODOO_PROJECT_CRM', true),
        'hr' => (bool) env('ODOO_PROJECT_HR', true),
        'pricing' => (bool) env('ODOO_PROJECT_PRICING', true),
        'commissions' => (bool) env('ODOO_PROJECT_COMMISSIONS', true),
        'portal' => (bool) env('ODOO_PROJECT_PORTAL', true),
        'preq' => (bool) env('ODOO_PROJECT_PREQ', true),
        'workspace' => (bool) env('ODOO_PROJECT_WORKSPACE', true),
        'payroll' => (bool) env('ODOO_PROJECT_PAYROLL', true),
        'pos' => (bool) env('ODOO_PROJECT_POS', true),
        'planning' => (bool) env('ODOO_PROJECT_PLANNING', true),
        'credit' => (bool) env('ODOO_PROJECT_CREDIT', true),
        'banking' => (bool) env('ODOO_PROJECT_BANKING', true),
        'tax' => (bool) env('ODOO_PROJECT_TAX', true),
        'insights' => (bool) env('ODOO_PROJECT_INSIGHTS', true),
    ],
    // area => the class that turns its mirrored records into OPS records (App\Integration\Odoo\Projection\OdooProjection)
    'projections' => [
        'approvals' => OdooApprovalsProjection::class,
        'crm' => OdooCrmProjection::class,
        'hr' => OdooHrProjection::class,
        // wave 3 (docs/odoo/WAVE3.md) — a class that is not installed yet is simply skipped by the runs
        'pricing' => 'App\\Pricing\\OdooPricingProjection',
        'commissions' => 'App\\Commissions\\OdooCommissionsProjection',
        'portal' => 'App\\Portal\\OdooPortalProjection',
        'preq' => 'App\\ProductRequests\\OdooProductRequestsProjection',
        'workspace' => 'App\\Workspace\\OdooWorkspaceProjection',
        'payroll' => 'App\\Payroll\\OdooPayrollProjection',
        // wave 4 (docs/odoo/WAVE4.md)
        'pos' => 'App\\Pos\\OdooPosProjection',
        'planning' => 'App\\Planning\\OdooPlanningProjection',
        'credit' => 'App\\Credit\\OdooCreditProjection',
        'banking' => 'App\\Banking\\OdooBankingProjection',
        'tax' => 'App\\Tax\\OdooTaxProjection',
        'insights' => 'App\\Insights\\OdooInsightsProjection',
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

    // ── the copy as a backup ─────────────────────────────────────────────────────────────────────────────────────
    // A model that loses this many records at once — and this share of the ones looked at — is put on hold instead of
    // having them marked deleted: a person confirms (see OdooSync::guardHolds).
    'deletion_guard' => ['min' => (int) env('ODOO_GUARD_MIN', 50), 'share' => 0.3],
    // Attachment content (`scm:odoo-files`): the disk of config/filesystems.php it is kept on (a server without a
    // writable disk needs an object-storage disk here), the largest file read in one piece, and the attachments that
    // are Odoo's own machinery rather than the company's documents (web assets, module icons).
    'files' => [
        'disk' => env('ODOO_FILES_DISK', 'local'),
        'folder' => 'odoo-files',
        'max_mb' => (int) env('ODOO_FILES_MAX_MB', 100),
        'skip_models' => ['ir.ui.view', 'ir.module.module', 'ir.ui.menu', 'ir.attachment', 'ir.actions.report'],
    ],
    // `scm:odoo-verify`: how many records of each model are read again from Odoo and compared with the copy
    'verify_sample' => 25,
];
