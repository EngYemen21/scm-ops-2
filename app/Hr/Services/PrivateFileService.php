<?php

namespace App\Hr\Services;

use App\Hr\Models\Employee;
use App\Hr\Models\EmployeePrivate;
use App\Hr\Support\Hr;
use App\Hr\Support\Source;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The personal file of an employee: identity papers and their expiry, passport, birth date, family, home address,
 * private phone, bank account, emergency contact, how and when the person left. Read and written ONLY with
 * `hr.private` — the routes require it and this service checks it again, because it is the last place before the data.
 *
 * The audit trail of a change names the fields that changed and never their values: the trail is read by people who
 * may not read the file.
 */
class PrivateFileService
{
    /**
     * What Odoo holds of the personal file (App\Hr\OdooHrProjection writes exactly these). The rest is OPS's own on
     * every employee, also one that came from Odoo: the expiry of the identity, the passport and its expiry, notes.
     */
    public const SOURCE_OWNED = ['nationality', 'identity_no', 'birth_date', 'gender', 'marital_status', 'children', 'home_address', 'private_phone', 'private_email',
        'bank_name', 'bank_account', 'emergency_contact', 'emergency_phone', 'end_date', 'departure_reason'];

    public function __construct(private readonly AuditService $audit, private readonly AlertsService $alerts) {}

    public function get(AuthUser $user, Employee $e): array
    {
        Hr::needPrivate($user);

        return $this->shape($e, EmployeePrivate::where('employee_id', $e->id)->first());
    }

    public function update(AuthUser $user, Employee $employee, array $dto): array
    {
        Hr::needPrivate($user);

        return DB::transaction(function () use ($user, $employee, $dto) {
            $e = Employee::whereKey($employee->id)->lockForUpdate()->first();
            $p = EmployeePrivate::where('employee_id', $e->id)->first() ?? new EmployeePrivate(['employee_id' => $e->id]);
            $new = [];
            foreach (EmployeePrivate::FIELDS as $field => $column) {
                if (! array_key_exists($field, $dto)) {
                    continue;
                }
                $new[$column] = match (true) {
                    in_array($column, EmployeePrivate::DATES, true) => Hr::date($dto[$field]),
                    $column === 'children' => $dto[$field] === null || $dto[$field] === '' ? null : max(0, (int) $dto[$field]),
                    $column === 'bank_account' => ($x = Hr::text($dto[$field], 60)) === null ? null : mb_strtoupper(preg_replace('/\s+/', '', $x)),
                    $column === 'private_email' => ($x = Hr::text($dto[$field])) === null ? null : mb_strtolower($x),
                    in_array($column, ['home_address', 'notes'], true) => Hr::text($dto[$field], 2000),
                    default => Hr::text($dto[$field]),
                };
            }
            $changes = Hr::diff($p, $new);
            // an employee that came from Odoo: the values Odoo holds are changed there
            if (Source::locked($e->source_system) && ($hit = array_values(array_intersect(array_keys($changes), self::SOURCE_OWNED)))) {
                throw Source::refusal('employee', "الملف الشخصي للموظف {$e->code}", "The personal file of employee {$e->code}", array_map(fn (string $c) => Str::camel($c), $hit));
            }
            if (isset($changes['end_date']) && $e->start_date && $changes['end_date'] < Hr::day($e->start_date)) {
                throw AppError::validation('EMPLOYEE_END_BEFORE_START', 'تاريخ ترك العمل قبل تاريخ المباشرة', 'The leaving date is before the start date');
            }
            if ($changes) {
                $p->fill($changes + ['updated_by' => $user->username])->save();
                $this->audit->log($user, ['action' => 'HR.PRIVATE.UPDATE', 'entityType' => 'Employee', 'entityId' => $e->id, 'entityNumber' => $e->code,
                    'newValue' => ['fields' => array_map(fn (string $c) => Str::camel($c), array_keys($changes))]]);
                $this->alerts->refreshEmployee($e->id); // an identity or a passport that was renewed stops alerting at once
            }

            return $this->shape($e, $p->exists ? $p->refresh() : null);
        });
    }

    private function shape(Employee $e, ?EmployeePrivate $p): array
    {
        $out = [];
        foreach (EmployeePrivate::FIELDS as $field => $column) {
            $value = $p?->getAttribute($column);
            $out[$field] = in_array($column, EmployeePrivate::DATES, true) ? Hr::day($value) : $value;
        }

        return $out + [
            'identityDays' => Hr::daysTo($p?->identity_expiry), 'passportDays' => Hr::daysTo($p?->passport_expiry),
            'updatedBy' => $p?->updated_by, 'updatedAt' => Hr::at($p?->updated_at),
            'ownedFields' => Source::locked($e->source_system) ? array_map(fn (string $c) => Str::camel($c), self::SOURCE_OWNED) : [],
        ];
    }
}
