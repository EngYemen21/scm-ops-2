<?php

namespace App\Hr\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paper of an employee, usually with an expiry date: residence permit, passport, driving licence, health
 * certificate… A PRIVATE document is part of the personal file (it is listed, read, written and alerted about only
 * for a holder of `hr.private`); identity papers are always private.
 */
class Document extends BaseModel
{
    use HasUlids;

    protected $table = 'hr_documents';

    /** kind → labels and whether it is ALWAYS part of the personal file */
    public const KINDS = [
        'iqama' => ['ar' => 'إقامة', 'en' => 'Residence permit (iqama)', 'private' => true],
        'national_id' => ['ar' => 'هوية وطنية', 'en' => 'National ID', 'private' => true],
        'passport' => ['ar' => 'جواز سفر', 'en' => 'Passport', 'private' => true],
        'visa' => ['ar' => 'تأشيرة / رخصة عمل', 'en' => 'Visa / work permit', 'private' => true],
        'medical_insurance' => ['ar' => 'تأمين طبي', 'en' => 'Medical insurance', 'private' => true],
        'contract_copy' => ['ar' => 'نسخة عقد العمل', 'en' => 'Copy of the contract', 'private' => true],
        'driving_license' => ['ar' => 'رخصة قيادة', 'en' => 'Driving licence', 'private' => false],
        'health_card' => ['ar' => 'شهادة صحية', 'en' => 'Health certificate', 'private' => false],
        'professional_license' => ['ar' => 'رخصة مهنية / اعتماد', 'en' => 'Professional licence', 'private' => false],
        'training' => ['ar' => 'شهادة تدريب', 'en' => 'Training certificate', 'private' => false],
        'other' => ['ar' => 'وثيقة أخرى', 'en' => 'Other document', 'private' => false],
    ];

    public const STATUSES = ['active', 'replaced', 'void'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'expiry_date' => 'date', 'is_private' => 'boolean', 'voided_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    public static function alwaysPrivate(string $kind): bool
    {
        return (bool) (self::KINDS[$kind]['private'] ?? false);
    }
}
