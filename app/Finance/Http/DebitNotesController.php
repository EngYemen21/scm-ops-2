<?php

namespace App\Finance\Http;

use App\Finance\Services\DebitNoteService;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Supplier debit notes: issued by the returns flow when goods go back to a supplier; finance reads and cancels them here. */
class DebitNotesController extends Controller
{
    public function __construct(private readonly DebitNoteService $notes) {}

    public function index(Request $request): array
    {
        return $this->notes->list(Paging::from($request), $request->only(['status', 'supplier', 'return', 'from', 'to']));
    }

    public function show(string $id): array
    {
        return $this->notes->get($id);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($this->notes->cancel(AuthUser::current(), $id, $data['reason']), 201);
    }
}
