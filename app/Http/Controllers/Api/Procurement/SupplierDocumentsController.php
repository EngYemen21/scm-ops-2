<?php

namespace App\Http\Controllers\Api\Procurement;

use App\Models\SupplierDocument;
use App\Services\Procurement\SupplierDocumentsService;
use App\Support\AuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Supplier qualification documents with expiry (CR, VAT, licences…). */
class SupplierDocumentsController extends ProcurementBaseController
{
    public function __construct(private readonly SupplierDocumentsService $docs) {}

    public function index(string $code): array
    {
        return $this->docs->list($code);
    }

    public function store(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:'.implode(',', SupplierDocument::TYPES), 'number' => 'nullable|string|max:80', 'issuedAt' => Rules::date(), 'expiresAt' => Rules::date(),
            'mandatory' => 'nullable|boolean', 'notes' => 'nullable|string|max:500', 'fileName' => 'nullable|string|max:200',
        ]);

        return $this->created($this->docs->add(AuthUser::current(), $code, $data));
    }

    public function destroy(string $code, string $id): array
    {
        return $this->docs->remove(AuthUser::current(), $code, $id);
    }
}
