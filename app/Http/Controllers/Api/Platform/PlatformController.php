<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePermission;
use App\Services\Platform\DashboardService;
use App\Services\Platform\PlatformService;
use App\Services\Platform\ReportsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Dashboard, Control Tower, activity/audit, notifications, reports, settings and global search (all under /api). */
class PlatformController extends Controller
{
    public function __construct(
        private readonly DashboardService $dash,
        private readonly ReportsService $reports,
        private readonly PlatformService $platform,
    ) {}

    public function dashboard(): array
    {
        return $this->dash->dashboard(AuthUser::current());
    }

    public function tower(): array
    {
        return $this->dash->tower(AuthUser::current());
    }

    public function activity(Request $request): array
    {
        $filters = $request->validate(['entity' => 'nullable|string', 'user' => 'nullable|string', 'from' => 'nullable|string', 'to' => 'nullable|string']);

        return $this->platform->activity(Paging::from($request), $filters);
    }

    public function audit(Request $request): array
    {
        $filters = $request->validate(['entity' => 'nullable|string', 'user' => 'nullable|string', 'from' => 'nullable|string', 'to' => 'nullable|string', 'entityType' => 'nullable|string', 'action' => 'nullable|string']);

        return $this->platform->auditTrail(Paging::from($request), $filters);
    }

    public function notifications(Request $request): array
    {
        $data = $request->validate(['unread' => 'nullable|string']);

        return $this->platform->notifications(AuthUser::current(), Paging::from($request), $data['unread'] ?? null);
    }

    public function readAll(): array
    {
        return $this->platform->markAllRead(AuthUser::current());
    }

    public function read(string $id): array
    {
        return $this->platform->markRead(AuthUser::current(), $id);
    }

    public function reports(): array
    {
        return $this->reports->names();
    }

    public function report(Request $request, string $name): array|Response
    {
        $filters = $request->validate(['warehouse' => 'nullable|string', 'from' => 'nullable|string', 'to' => 'nullable|string', 'status' => 'nullable|string', 'format' => 'sometimes|in:json,csv']);
        if (($filters['format'] ?? 'json') !== 'csv') {
            return $this->reports->run(AuthUser::current(), $name, Paging::from($request), $filters);
        }
        // Exporting is a permission of its own (the web only offers the button to roles holding it): same 403 + security audit as `perm:`.
        app(RequirePermission::class)->handle($request, fn () => new Response, 'report.export');
        $csv = $this->reports->csv(AuthUser::current(), $name, Paging::from($request), $filters);

        return response($csv['body'], 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$csv['fileName'].'"']);
    }

    public function settings(): array
    {
        return $this->platform->allSettings();
    }

    public function setSetting(Request $request, string $key): array
    {
        $request->validate(['value' => 'present']);

        return $this->platform->setSetting(AuthUser::current(), $key, $request->input('value'));
    }

    public function search(Request $request): array
    {
        $request->merge(['q' => trim((string) $request->query('q', ''))]);
        $data = $request->validate(['q' => 'required|string|min:1|max:80']);

        return $this->platform->search($data['q']);
    }
}
