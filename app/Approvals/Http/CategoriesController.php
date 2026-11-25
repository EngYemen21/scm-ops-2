<?php

namespace App\Approvals\Http;

use App\Approvals\Services\ApprovalFlow;
use App\Approvals\Services\CategoriesService;
use App\Approvals\Services\People;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Categories of approval requests, and what the forms of the section pick from (people, partners, products). */
class CategoriesController extends Controller
{
    public function __construct(private readonly CategoriesService $categories, private readonly People $people) {}

    public function index(Request $request): array
    {
        return $this->categories->list(Paging::from($request, 50), $request->validate(['active' => 'nullable|in:0,1,true,false,all', 'source' => 'nullable|in:odoo,ops']));
    }

    public function show(string $id): array
    {
        return $this->categories->get($id);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->categories->create(AuthUser::current(), $request->validate(['nameAr' => 'required|string|max:250'] + self::rules())), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->categories->update(AuthUser::current(), $id, $request->validate(['nameAr' => 'sometimes|required|string|max:250'] + self::rules()));
    }

    /** Everything the "new request" and "category" forms choose from, in one call. */
    public function lookups(): array
    {
        return [
            'categories' => $this->categories->active(),
            'users' => $this->people->approvers(),
            'fields' => ApprovalFlow::FIELDS, 'levels' => ApprovalFlow::LEVELS, 'modes' => ApprovalFlow::MODES,
            'paymentMethods' => ApprovalFlow::PAYMENT_METHODS, 'partnerTypes' => ApprovalFlow::PARTNER_TYPES,
            'odooOwns' => CategoriesService::odooOwns(),
        ];
    }

    /** Customers or suppliers by name or code, for the partner of a request (the twenty best matches). */
    public function partners(Request $request): array
    {
        $data = $request->validate(['type' => 'required|in:customer,supplier', 'q' => 'nullable|string|max:120']);
        $query = DB::table($data['type'] === 'customer' ? 'customers' : 'suppliers')->where('active', true);
        if (($q = trim((string) ($data['q'] ?? ''))) !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('name_ar', Sql::like(), $like)->orWhere('name_en', Sql::like(), $like)->orWhere('code', Sql::like(), $like));
        }

        return ['items' => $query->orderBy('name_ar')->orderBy('id')->limit(20)->get(['id', 'code', 'name_ar', 'name_en'])
            ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en])->all()];
    }

    /** Products by name or code, for the lines of a request (the twenty best matches). */
    public function products(Request $request): array
    {
        $data = $request->validate(['q' => 'nullable|string|max:120']);
        $query = DB::table('products')->where('active', true);
        if (($q = trim((string) ($data['q'] ?? ''))) !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('sku', Sql::like(), $like)->orWhere('name_ar', Sql::like(), $like)->orWhere('name_en', Sql::like(), $like));
        }

        return ['items' => $query->orderBy('sku')->limit(20)->get(['id', 'sku', 'name_ar', 'name_en'])
            ->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en])->all()];
    }

    private static function rules(): array
    {
        return [
            'code' => 'nullable|string|max:40', 'nameEn' => 'nullable|string|max:250', 'description' => 'nullable|string|max:500', 'active' => 'sometimes|boolean', 'sort' => 'sometimes|integer|min:0|max:100000',
            'approvalMode' => ['sometimes', Rule::in(ApprovalFlow::MODES)], 'minApprovals' => 'sometimes|integer|min:1|max:20', 'allowSelfApproval' => 'sometimes|boolean',
            'fields' => 'sometimes|array', 'fields.*' => ['required', Rule::in(ApprovalFlow::LEVELS)],
            'approvers' => 'sometimes|array|max:20', 'approvers.*.user' => 'required|string|max:60', 'approvers.*.sequence' => 'nullable|integer|min:0|max:100000',
            'approvers.*.required' => 'nullable|boolean', 'approvers.*.minAmount' => 'nullable|numeric|min:0|max:9999999999',
        ];
    }
}
