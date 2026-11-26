<?php

namespace App\Payroll\Services;

use App\Hr\Services\Workforce;
use App\Payroll\Models\Payslip;
use App\Payroll\Models\Profile;
use App\Payroll\Models\Run;
use App\Payroll\Support\Money;
use App\Payroll\Support\Owned;
use App\Payroll\Support\Shape;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;

/**
 * The bank transfer listing of an approved run: who is paid how much, into which account. This is the ONE place of the
 * section where bank account numbers leave the server — only for a holder of `payroll.manage`, only for a run that is
 * approved or beyond, and every time it is built the audit trail says so (who, which run, how many lines — never an
 * account number).
 *
 * The file is a plain CSV a person uploads to the bank's portal or keys from. It is NOT the bank's own salary-file
 * format (each bank and the wage-protection system have their own layout): that needs the bank's specification.
 */
class TransferFile
{
    public function __construct(private readonly Workforce $workforce, private readonly AuditService $audit) {}

    /**
     * @return array{run: array<string, mixed>, rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function listing(AuthUser $user, string $idOrNumber): array
    {
        if (! $user->can('payroll.manage')) {
            throw AppError::forbidden('PAYROLL_PRIVATE', 'أرقام الحسابات البنكية تُعرض لمسؤول الرواتب فقط عند بناء ملف التحويل', 'Bank account numbers are shown only to a payroll officer building the transfer file');
        }
        $run = Run::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first() ?? throw AppError::notFound('PAYRUN_NOT_FOUND', 'مسير الرواتب غير موجود', 'Payroll run not found');
        if (! in_array($run->state, Run::FINAL, true) || $run->source_system === Owned::ODOO) {
            throw AppError::rule('PAYRUN_STATE', "ملف التحويل يُبنى لمسير معتمد من مسيرات هذا النظام (حالة {$run->number}: {$run->state})", "The transfer file is built for an approved run of this system ({$run->number} is {$run->state})", ['state' => $run->state]);
        }
        $slips = Payslip::where('run_id', $run->id)->with('employee')->orderBy('number')->get();
        $methods = Profile::whereIn('employee_id', $slips->pluck('employee_id')->all() ?: ['-'])->pluck('payment_method', 'employee_id');
        $rows = [];
        $totals = ['bank' => ['count' => 0, 'amount' => 0], 'cash' => ['count' => 0, 'amount' => 0], 'missingBank' => ['count' => 0, 'amount' => 0], 'nothingToPay' => 0];
        foreach ($slips as $p) {
            $net = Money::h($p->net);
            if ($net <= 0) {
                $totals['nothingToPay']++;

                continue;
            }
            $method = $methods[$p->employee_id] ?? 'bank';
            $bank = $method === 'bank' ? $this->workforce->bankOf($p->employee_id) : ['bankName' => null, 'bankAccount' => null];
            $bucket = $method === 'cash' ? 'cash' : ($bank['bankAccount'] ? 'bank' : 'missingBank');
            $totals[$bucket]['count']++;
            $totals[$bucket]['amount'] += $net;
            $rows[] = ['payslip' => $p->number, 'employee' => Shape::employee($p->employee), 'method' => $method, 'bankName' => $bank['bankName'], 'bankAccount' => $bank['bankAccount'],
                'amount' => Money::s($net), 'currency' => $p->currency, 'ready' => $bucket !== 'missingBank'];
        }
        foreach (['bank', 'cash', 'missingBank'] as $bucket) {
            $totals[$bucket]['amount'] = Money::s($totals[$bucket]['amount']);
        }
        $this->audit->log($user, ['action' => 'PAYRUN.TRANSFER_FILE', 'entityType' => 'PayrollRun', 'entityId' => $run->id, 'entityNumber' => $run->number,
            'newValue' => ['lines' => count($rows), 'bank' => $totals['bank']['count'], 'cash' => $totals['cash']['count'], 'missingBank' => $totals['missingBank']['count']]]);

        return ['run' => ['id' => $run->id, 'number' => $run->number, 'period' => $run->period, 'state' => $run->state, 'net' => $run->net, 'currency' => $run->currency], 'rows' => $rows, 'totals' => $totals];
    }

    /** The bank lines of the listing as CSV (UTF-8 with a byte-order mark, so a spreadsheet opens the Arabic names). */
    public function csv(AuthUser $user, string $idOrNumber): array
    {
        $listing = $this->listing($user, $idOrNumber);
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Employee code', 'Name (AR)', 'Name (EN)', 'Bank', 'IBAN', 'Amount', 'Currency', 'Payslip', 'Reference', 'Period'], ',', '"', '');
        // a cell a spreadsheet would read as a formula is written as text
        $cell = fn (mixed $v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : (string) $v;
        foreach ($listing['rows'] as $r) {
            if ($r['method'] === 'bank' && $r['ready']) {
                fputcsv($out, array_map($cell, [$r['employee']['code'] ?? '', $r['employee']['nameAr'] ?? '', $r['employee']['nameEn'] ?? '', $r['bankName'] ?? '', $r['bankAccount'], $r['amount'], $r['currency'], $r['payslip'],
                    $listing['run']['number'], $listing['run']['period']]), ',', '"', '');
            }
        }
        rewind($out);
        $bytes = (string) stream_get_contents($out);
        fclose($out);

        return ['fileName' => "salaries-{$listing['run']['period']}-{$listing['run']['number']}.csv", 'bytes' => $bytes];
    }
}
