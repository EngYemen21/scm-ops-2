<?php

namespace App\Integration\Odoo\Http;

use App\Integration\Odoo\OdooMasterData;
use App\Integration\Odoo\OdooMirror;
use App\Integration\Odoo\OdooStockView;
use App\Integration\Odoo\OdooSync;
use App\Support\AppError;
use App\Support\Paging;
use Illuminate\Http\Request;

/**
 * The Odoo mirror for people (integration tower → "Odoo"). Reading needs integration.view; starting a run or changing
 * what is mirrored needs integration.manage. Nothing here can change Odoo: the connector only reads.
 */
class OdooController
{
    public function status(Request $request, OdooMirror $mirror): array
    {
        return $mirror->status($request->boolean('fresh'));
    }

    public function models(Request $request, OdooMirror $mirror): array
    {
        return $mirror->models(Paging::from($request, 100), $request->only(['status', 'state', 'hasRecords']));
    }

    public function model(string $model, OdooMirror $mirror): array
    {
        return $mirror->model($model);
    }

    public function setModel(Request $request, string $model, OdooMirror $mirror): array
    {
        return $mirror->setModel($model, $request->validate(['status' => 'sometimes|in:active,paused', 'core' => 'sometimes|boolean']));
    }

    public function records(Request $request, OdooMirror $mirror): array
    {
        return $mirror->records(Paging::from($request), $request->only(['model', 'deleted']));
    }

    public function record(string $model, int $odooId, OdooMirror $mirror): array
    {
        return $mirror->record($model, $odooId);
    }

    public function changes(Request $request, OdooMirror $mirror): array
    {
        return $mirror->changes(Paging::from($request), $request->only(['model', 'kind']));
    }

    public function runs(Request $request, OdooMirror $mirror): array
    {
        return $mirror->runs(Paging::from($request, 30));
    }

    /** Odoo's stock as Odoo holds it (a view of the mirror, not the OPS ledger): per warehouse and product, lots, expiry. */
    public function stock(Request $request, OdooStockView $stock): array
    {
        return $stock->list(Paging::from($request), $request->only(['warehouse', 'expiry']));
    }

    /**
     * Odoo's products, customers and suppliers in the OPS master data: what is linked so far and how many changes of
     * Odoo wait — and with `?plan=1` what an apply would do (nothing is changed either way).
     */
    public function masterPlan(Request $request, OdooMasterData $master): array
    {
        return $master->overview($request->boolean('plan'));
    }

    /**
     * Does it: creates what OPS lacks, links what it has, refreshes what Odoo changed. Idempotent. A first load is
     * thousands of records — more than a web request should carry — so above the limit the answer is "run the command".
     */
    public function masterApply(OdooMasterData $master): array
    {
        $work = OdooMasterData::work($master->plan()['counts']);
        if ($work > OdooMasterData::WEB_APPLY_LIMIT) {
            throw AppError::rule('ODOO_APPLY_TOO_LARGE',
                "الدفعة كبيرة على الشاشة: {$work} سجلًا. نفّذها من الخادم بالأمر php artisan scm:odoo-master --apply",
                "Too much for the screen: {$work} records. Run it on the server: php artisan scm:odoo-master --apply");
        }

        return $master->apply() + ['work' => $work];
    }

    /** A short run now (the periodic runs do the real work); `model` restricts it to one model. */
    public function sync(Request $request, OdooSync $sync, OdooMirror $mirror): array
    {
        $data = $request->validate(['model' => 'nullable|string|max:120', 'seconds' => 'sometimes|integer|min:3|max:25']);
        $out = $sync->run((int) ($data['seconds'] ?? 15), 'person', empty($data['model']) ? null : [$data['model']]);
        if (! $out['ran']) {
            throw $out['reason'] === 'disabled'
                ? AppError::rule('ODOO_DISABLED', 'ربط أودو غير مفعّل على هذا الخادم (ODOO_ENABLED وبيانات الاتصال)', 'The Odoo connection is not enabled on this server')
                : AppError::conflict('ODOO_BUSY', 'مزامنة أخرى تعمل الآن — انتظر لحظات', 'Another synchronisation is running — try again in a moment');
        }

        return $out + ['status' => $mirror->status()];
    }
}
