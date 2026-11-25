<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use App\Tax\Zatca\Codes;

/**
 * The taxpayer: the one row (`company`) that says who issues the invoices — legal name, VAT number, the other
 * identification, the national address — how often the VAT return is filed, and from which day OPS makes e-invoices.
 */
class TaxProfile extends BaseModel
{
    public const ID = 'company';

    public const FREQUENCIES = ['monthly', 'quarterly'];

    protected $table = 'tax_profile';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['einvoice_auto' => 'boolean', 'einvoice_from' => 'date'];
    }

    /** What an e-invoice cannot be made without: the name and the VAT number its QR code carries. */
    public function canIssue(): bool
    {
        return trim((string) $this->legal_name_ar) !== '' && Codes::vatNumber($this->vat_no);
    }
}
