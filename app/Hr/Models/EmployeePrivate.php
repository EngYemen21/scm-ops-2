<?php

namespace App\Hr\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * The personal file of an employee: identity papers, family, home address, bank account, how and when the person left.
 * Read and written ONLY by a holder of `hr.private` (App\Hr\Services\PrivateFileService) — and never serialised as a
 * whole: `$hidden` keeps an accidental `toArray()` empty of the values that identify a person.
 */
class EmployeePrivate extends BaseModel
{
    use HasUlids;

    protected $table = 'hr_employee_private';

    /** API field → column: the whole personal file, in the order the screen shows it. */
    public const FIELDS = [
        'nationality' => 'nationality', 'identityNo' => 'identity_no', 'identityExpiry' => 'identity_expiry', 'passportNo' => 'passport_no', 'passportExpiry' => 'passport_expiry',
        'birthDate' => 'birth_date', 'gender' => 'gender', 'maritalStatus' => 'marital_status', 'children' => 'children', 'homeAddress' => 'home_address',
        'privatePhone' => 'private_phone', 'privateEmail' => 'private_email', 'bankName' => 'bank_name', 'bankAccount' => 'bank_account',
        'emergencyContact' => 'emergency_contact', 'emergencyPhone' => 'emergency_phone', 'endDate' => 'end_date', 'departureReason' => 'departure_reason', 'notes' => 'notes',
    ];

    public const DATES = ['identity_expiry', 'passport_expiry', 'birth_date', 'end_date'];

    public const GENDERS = ['male', 'female'];

    public const MARITAL = ['single', 'married', 'divorced', 'widowed'];

    protected $hidden = ['identity_no', 'passport_no', 'birth_date', 'home_address', 'private_phone', 'private_email', 'bank_name', 'bank_account', 'emergency_contact', 'emergency_phone'];

    protected function casts(): array
    {
        return ['identity_expiry' => 'date', 'passport_expiry' => 'date', 'birth_date' => 'date', 'end_date' => 'date', 'children' => 'integer',
            'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
