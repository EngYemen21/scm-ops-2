<?php

namespace App\Payroll\Http;

use App\Http\Controllers\Controller;
use App\Payroll\Services\ConfigService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What working time is made of: schedules, public holidays, kinds of time off, attendance policies. */
class ConfigController extends Controller
{
    private const SCHEDULE = ['nameEn' => 'sometimes|nullable|string|max:190', 'timezone' => 'sometimes|nullable|string|max:60', 'hoursPerDay' => 'sometimes|numeric|min:0.5|max:24', 'isDefault' => 'sometimes|boolean',
        'active' => 'sometimes|boolean', 'notes' => 'sometimes|nullable|string|max:2000', 'days' => 'sometimes|array|max:60', 'days.*.weekday' => 'required|integer|min:0|max:6', 'days.*.kind' => 'nullable|in:work,break',
        'days.*.from' => 'required|string|max:5', 'days.*.to' => 'required|string|max:5', 'days.*.name' => 'nullable|string|max:120'];

    private const HOLIDAY = ['nameEn' => 'sometimes|nullable|string|max:190', 'dateTo' => 'sometimes|nullable|date', 'scope' => 'sometimes|in:company,department,employee', 'scopeIds' => 'sometimes|nullable|array|max:500',
        'scopeIds.*' => 'string|max:40', 'active' => 'sometimes|boolean', 'notes' => 'sometimes|nullable|string|max:2000'];

    private const LEAVE_TYPE = ['nameEn' => 'sometimes|nullable|string|max:190', 'paid' => 'sometimes|boolean', 'requiresAllocation' => 'sometimes|boolean', 'yearlyDays' => 'sometimes|nullable|numeric|min:0|max:366',
        'approval' => 'sometimes|in:none,manager,hr,both', 'requiresDocument' => 'sometimes|boolean', 'allowHalfDay' => 'sometimes|boolean', 'allowNegative' => 'sometimes|boolean', 'color' => 'sometimes|nullable|integer|min:0|max:11',
        'sequence' => 'sometimes|nullable|integer|min:0|max:100000', 'active' => 'sometimes|boolean', 'notes' => 'sometimes|nullable|string|max:2000'];

    private const POLICY = ['nameEn' => 'sometimes|nullable|string|max:190', 'lateGraceMinutes' => 'sometimes|integer|min:0|max:600', 'earlyGraceMinutes' => 'sometimes|integer|min:0|max:600', 'isDefault' => 'sometimes|boolean',
        'active' => 'sometimes|boolean', 'notes' => 'sometimes|nullable|string|max:2000', 'late' => 'sometimes|nullable|array|max:20', 'early' => 'sometimes|nullable|array|max:20', 'absence' => 'sometimes|nullable|array|max:31',
        'overtime' => 'sometimes|nullable|array|max:3',
        // every key of a rule line is named: what is not named here does not reach the service
        'late.*.type' => 'nullable|in:rate,fix', 'late.*.thresholdMinutes' => 'nullable|integer|min:0|max:1440', 'late.*.rate' => 'nullable|numeric|min:0|max:10', 'late.*.amountMinutes' => 'nullable|integer|min:0|max:1440',
        'late.*.weights' => 'nullable|array|max:5', 'late.*.weights.*' => 'nullable|numeric|min:0|max:10',
        'early.*.type' => 'nullable|in:rate,fix', 'early.*.thresholdMinutes' => 'nullable|integer|min:0|max:1440', 'early.*.rate' => 'nullable|numeric|min:0|max:10', 'early.*.amountMinutes' => 'nullable|integer|min:0|max:1440',
        'early.*.weights' => 'nullable|array|max:5', 'early.*.weights.*' => 'nullable|numeric|min:0|max:10',
        'absence.*.counter' => 'nullable|integer|min:1|max:31', 'absence.*.rate' => 'nullable|numeric|min:0|max:10',
        'overtime.*.dayType' => 'required|in:workday,weekend,holiday', 'overtime.*.activeAfterMinutes' => 'nullable|integer|min:0|max:1440', 'overtime.*.rate' => 'required|numeric|min:0|max:10'];

    public function __construct(private readonly ConfigService $config) {}

    public function schedules(): array
    {
        return ['items' => $this->config->schedules()];
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        return response()->json($this->config->saveSchedule(AuthUser::current(), null, $request->validate(['nameAr' => 'required|string|min:2|max:190'] + self::SCHEDULE)), 201);
    }

    public function updateSchedule(Request $request, string $id): array
    {
        return $this->config->saveSchedule(AuthUser::current(), $id, $request->validate(['nameAr' => 'sometimes|string|min:2|max:190'] + self::SCHEDULE));
    }

    public function holidays(Request $request): array
    {
        return $this->config->holidays(Paging::from($request, 50), $request->only(['year', 'from', 'to', 'active']));
    }

    public function storeHoliday(Request $request): JsonResponse
    {
        return response()->json($this->config->saveHoliday(AuthUser::current(), null, $request->validate(['nameAr' => 'required|string|min:2|max:190', 'dateFrom' => 'required|date'] + self::HOLIDAY)), 201);
    }

    public function updateHoliday(Request $request, string $id): array
    {
        return $this->config->saveHoliday(AuthUser::current(), $id, $request->validate(['nameAr' => 'sometimes|string|min:2|max:190', 'dateFrom' => 'sometimes|date'] + self::HOLIDAY));
    }

    public function leaveTypes(Request $request): array
    {
        return ['items' => $this->config->leaveTypes($request->boolean('all', true))];
    }

    public function storeLeaveType(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'min:2', 'max:30', 'regex:/^[A-Za-z0-9_.\-]+$/'], 'nameAr' => 'required|string|min:2|max:190'] + self::LEAVE_TYPE);

        return response()->json($this->config->saveLeaveType(AuthUser::current(), null, $data), 201);
    }

    public function updateLeaveType(Request $request, string $id): array
    {
        return $this->config->saveLeaveType(AuthUser::current(), $id, $request->validate(['nameAr' => 'sometimes|string|min:2|max:190'] + self::LEAVE_TYPE));
    }

    public function policies(): array
    {
        return ['items' => $this->config->policies()];
    }

    public function storePolicy(Request $request): JsonResponse
    {
        return response()->json($this->config->savePolicy(AuthUser::current(), null, $request->validate(['nameAr' => 'required|string|min:2|max:190'] + self::POLICY)), 201);
    }

    public function updatePolicy(Request $request, string $id): array
    {
        return $this->config->savePolicy(AuthUser::current(), $id, $request->validate(['nameAr' => 'sometimes|string|min:2|max:190'] + self::POLICY));
    }
}
