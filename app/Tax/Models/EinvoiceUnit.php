<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * An e-invoicing unit ("EGS unit"): what makes and stamps the e-invoices, with its own counter and its own chain of
 * hashes. `status` is its onboarding with the tax authority:
 *
 *   not_onboarded → csr_ready → compliance → compliant → active      (and retired, from any of them)
 *
 * A unit that is not `active` makes the XML, the counter, the chain and the basic QR code, and sends nothing.
 *
 * SECRETS. The signing key, the certificate request, both certificates and their secrets are stored encrypted with
 * the application key and are hidden from every array / JSON form of the model. Nothing in the section returns or
 * logs them; the services read them at the moment they sign or call the authority, and nowhere else.
 */
class EinvoiceUnit extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_einvoice_units';

    public const STATES = ['not_onboarded', 'csr_ready', 'compliance', 'compliant', 'active', 'retired'];

    public const LABELS = [
        'not_onboarded' => ['غير مربوطة بالهيئة', 'Not onboarded'], 'csr_ready' => ['طلب الشهادة جاهز', 'Certificate request ready'],
        'compliance' => ['شهادة الامتثال صادرة', 'Compliance certificate issued'], 'compliant' => ['اجتازت فحوص الامتثال', 'Compliance checks passed'],
        'active' => ['مربوطة وتعمل', 'Onboarded'], 'retired' => ['موقوفة', 'Retired'],
    ];

    public const SECRETS = ['private_key', 'csr', 'compliance_token', 'compliance_secret', 'compliance_request_id', 'production_token', 'production_secret', 'production_request_id'];

    protected $hidden = self::SECRETS;

    protected function casts(): array
    {
        return array_fill_keys(self::SECRETS, 'encrypted') + [
            'icv' => 'integer', 'is_default' => 'boolean', 'sending' => 'boolean', 'compliance_checks' => 'array',
            'cert_not_before' => 'datetime', 'cert_not_after' => 'datetime', 'retired_at' => 'datetime',
        ];
    }

    /** The unit holds a production certificate and may stamp and send. */
    public function onboarded(): bool
    {
        return $this->status === 'active' && $this->production_token !== null && $this->production_secret !== null && $this->private_key !== null;
    }
}
