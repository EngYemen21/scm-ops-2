<?php

namespace App\Tax\Http;

use App\Http\Controllers\Controller;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Tax\Models\EinvoiceHistory;
use App\Tax\Models\VatReturn;
use App\Tax\OdooTaxProjection;
use App\Tax\Services\EinvoiceHistoryService;
use App\Tax\Services\EinvoiceService;
use App\Tax\Services\TaxCodesService;
use App\Tax\Services\TaxDocumentsService;
use App\Tax\Services\TaxProfileService;
use App\Tax\Services\VatReturnService;
use App\Tax\Support\Owned;
use App\Tax\Support\Shape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The dictionary and the settings of the section: tax codes, groups, fiscal positions, the taxpayer, the tax details
 * of customers and suppliers, the code of one document — and the overview the page opens on.
 */
class TaxController extends Controller
{
    public function __construct(
        private readonly TaxCodesService $codes,
        private readonly TaxProfileService $profiles,
        private readonly TaxDocumentsService $documents,
    ) {}

    public function lookups(): array
    {
        return $this->codes->lookups();
    }

    /** What the page opens on: the e-invoicing mode, the returns that wait, the history that came from Odoo. */
    public function overview(EinvoiceService $einvoices, VatReturnService $returns, EinvoiceHistoryService $history): array
    {
        $open = VatReturn::whereIn('status', ['draft', 'reviewed', 'filed'])->orderBy('period_from')->get();
        $projection = null;
        try {
            $p = app(OdooTaxProjection::class);
            $projection = ['follows' => Owned::following(), 'ready' => $p->ready(), 'waiting' => $p->ready() ? $p->waiting() : 0];
        } catch (Throwable) {
            // the copy of Odoo is not installed on this server: the section works without it
        }

        return [
            'einvoicing' => $einvoices->summary(),
            'returns' => ['open' => $open->map(fn (VatReturn $r) => Shape::vatReturn($r))->all(), 'suggest' => $returns->suggest(),
                'last' => ($last = VatReturn::whereIn('status', ['filed', 'locked'])->orderByDesc('period_to')->first()) ? Shape::vatReturn($last) : null],
            'history' => ['total' => EinvoiceHistory::count(), 'notAccepted' => EinvoiceHistory::whereIn('status', ['not_sent', 'error'])->count()],
            'projection' => $projection,
            'profile' => $this->profiles->show(),
        ];
    }

    // ───────────── tax codes, groups ─────────────

    public function codes(Request $request): array
    {
        return $this->codes->listCodes(Paging::from($request, 100), $request->only(['use', 'kind', 'active', 'origin', 'box', 'group']));
    }

    public function storeCode(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:30', 'nameAr' => 'required|string|min:1|max:190', 'use' => 'required|string|in:sale,purchase', 'kind' => 'required|string|max:20', 'rate' => 'required|numeric|min:0|max:100']
            + self::codeRules());

        return response()->json($this->codes->createCode(AuthUser::current(), self::typed($data)), 201);
    }

    public function updateCode(Request $request, string $id): array
    {
        $data = $request->validate(['code' => 'sometimes|string|max:30', 'nameAr' => 'sometimes|string|min:1|max:190', 'use' => 'sometimes|string|in:sale,purchase', 'kind' => 'sometimes|string|max:20',
            'rate' => 'sometimes|numeric|min:0|max:100', 'active' => 'sometimes|boolean'] + self::codeRules());

        return $this->codes->updateCode(AuthUser::current(), $id, self::typed($data));
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate(['nameAr' => 'required|string|min:1|max:120', 'nameEn' => 'nullable|string|max:120', 'sequence' => 'nullable|integer|min:0|max:100000']);

        return response()->json($this->codes->createGroup(AuthUser::current(), self::typed($data)), 201);
    }

    public function updateGroup(Request $request, string $id): array
    {
        $data = $request->validate(['nameAr' => 'sometimes|string|min:1|max:120', 'nameEn' => 'sometimes|nullable|string|max:120', 'sequence' => 'sometimes|integer|min:0|max:100000', 'active' => 'sometimes|boolean']);

        return $this->codes->updateGroup(AuthUser::current(), $id, self::typed($data));
    }

    // ───────────── fiscal positions ─────────────

    public function positions(): array
    {
        return ['items' => $this->codes->positions(), 'follows' => Owned::following()];
    }

    public function storePosition(Request $request): JsonResponse
    {
        $data = $request->validate(['nameAr' => 'required|string|min:1|max:120'] + self::positionRules());

        return response()->json($this->codes->createPosition(AuthUser::current(), self::typed($data)), 201);
    }

    public function updatePosition(Request $request, string $id): array
    {
        $data = $request->validate(['nameAr' => 'sometimes|string|min:1|max:120', 'active' => 'sometimes|boolean'] + self::positionRules());

        return $this->codes->updatePosition(AuthUser::current(), $id, self::typed($data));
    }

    // ───────────── the taxpayer, customers and suppliers ─────────────

    public function profile(): array
    {
        return $this->profiles->show();
    }

    public function updateProfile(Request $request): array
    {
        $user = self::settingsUser();
        $data = $request->validate([
            'legalNameAr' => 'sometimes|string|min:1|max:190', 'legalNameEn' => 'sometimes|nullable|string|max:190', 'vatNo' => 'sometimes|nullable|string|max:15', 'idScheme' => 'sometimes|nullable|string|max:3',
            'idValue' => 'sometimes|nullable|string|max:40', 'street' => 'sometimes|nullable|string|max:190', 'buildingNo' => 'sometimes|nullable|string|max:10', 'additionalNo' => 'sometimes|nullable|string|max:10',
            'district' => 'sometimes|nullable|string|max:120', 'city' => 'sometimes|nullable|string|max:120', 'postalCode' => 'sometimes|nullable|string|max:10', 'province' => 'sometimes|nullable|string|max:120',
            'countryCode' => 'sometimes|nullable|string|size:2', 'filingFrequency' => 'sometimes|string|in:monthly,quarterly', 'einvoiceAuto' => 'sometimes|boolean', 'einvoiceFrom' => 'sometimes|nullable|date_format:Y-m-d',
        ]);

        return $this->profiles->update($user, self::typed($data));
    }

    public function parties(Request $request): array
    {
        return $this->profiles->parties(Paging::from($request), $request->only(['type', 'state', 'position']));
    }

    public function party(string $type, string $id): array
    {
        return $this->profiles->party($type, $id);
    }

    public function saveParty(Request $request, string $type, string $id): array
    {
        $user = self::settingsUser();
        $data = $request->validate([
            'fiscalPositionId' => 'sometimes|nullable|string|max:60', 'defaultCodeId' => 'sometimes|nullable|string|max:60', 'invoiceKind' => 'sometimes|nullable|string|in:auto,standard,simplified',
            'street' => 'sometimes|nullable|string|max:190', 'buildingNo' => 'sometimes|nullable|string|max:10', 'additionalNo' => 'sometimes|nullable|string|max:10', 'district' => 'sometimes|nullable|string|max:120',
            'city' => 'sometimes|nullable|string|max:120', 'postalCode' => 'sometimes|nullable|string|max:10', 'countryCode' => 'sometimes|nullable|string|size:2', 'idScheme' => 'sometimes|nullable|string|max:3',
            'idValue' => 'sometimes|nullable|string|max:40',
        ]);

        return $this->profiles->saveParty($user, $type, $id, $data);
    }

    // ───────────── the code of one document ─────────────

    public function document(string $type, string $id): array
    {
        return $this->documents->show($type, $id);
    }

    public function assignDocument(Request $request, string $type, string $id): array
    {
        $data = $request->validate(['codeId' => 'nullable|string|max:60', 'reason' => 'nullable|string|max:300']);

        return $this->documents->assign(AuthUser::current(), $type, $id, $data['codeId'] ?? null, $data['reason'] ?? null);
    }

    // ───────────── helpers ─────────────

    /** The taxpayer and the partners' tax details serve both halves of the section: either manager may keep them. */
    private static function settingsUser(): AuthUser
    {
        $user = AuthUser::current();
        if (! $user->can('vatreturn.manage') && ! $user->can('einvoice.manage')) {
            throw AppError::forbidden('FORBIDDEN', 'لا تملك صلاحية تعديل البيانات الضريبية', 'Insufficient permission to change tax data');
        }

        return $user;
    }

    private static function codeRules(): array
    {
        return [
            'nameEn' => 'sometimes|nullable|string|max:190', 'label' => 'sometimes|nullable|string|max:190', 'description' => 'sometimes|nullable|string|max:2000', 'returnBox' => 'sometimes|nullable|integer|min:1|max:11',
            'exemptionReasonCode' => 'sometimes|nullable|string|max:30', 'exemptionReasonAr' => 'sometimes|nullable|string|max:300', 'exemptionReasonEn' => 'sometimes|nullable|string|max:300',
            'scope' => 'sometimes|nullable|string|in:goods,service', 'priceInclude' => 'sometimes|boolean', 'sequence' => 'sometimes|nullable|integer|min:0|max:100000', 'groupId' => 'sometimes|nullable|string|max:60',
            'isDefault' => 'sometimes|boolean',
        ];
    }

    private static function positionRules(): array
    {
        return [
            'nameEn' => 'sometimes|nullable|string|max:120', 'sequence' => 'sometimes|nullable|integer|min:0|max:100000', 'autoApply' => 'sometimes|boolean', 'vatRequired' => 'sometimes|boolean',
            'countryCode' => 'sometimes|nullable|string|size:2', 'countryGroup' => 'sometimes|nullable|string|max:60', 'zipFrom' => 'sometimes|nullable|string|max:20', 'zipTo' => 'sometimes|nullable|string|max:20',
            'foreignVat' => 'sometimes|nullable|string|max:40', 'note' => 'sometimes|nullable|string|max:4000',
            'lines' => 'sometimes|array|max:100', 'lines.*.fromCodeId' => 'required_with:lines|string|max:60', 'lines.*.toCodeId' => 'required_with:lines|string|max:60',
        ];
    }

    /** Validated input may arrive as numeric strings and 0 / 1 flags: give the services real numbers and booleans. */
    private static function typed(array $data): array
    {
        foreach (['active', 'priceInclude', 'isDefault', 'autoApply', 'vatRequired', 'einvoiceAuto'] as $flag) {
            if (array_key_exists($flag, $data) && $data[$flag] !== null) {
                $data[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (['sequence', 'returnBox'] as $int) {
            if (isset($data[$int])) {
                $data[$int] = (int) $data[$int];
            }
        }
        if (isset($data['rate'])) {
            $data['rate'] = (string) $data['rate'];
        }

        return $data;
    }
}
