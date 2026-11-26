<?php

namespace App\Hr\Http;

use App\Hr\Services\AlertsService;
use App\Hr\Services\ContractsService;
use App\Hr\Services\DirectoryService;
use App\Hr\Services\DocumentsService;
use App\Hr\Services\FilesService;
use App\Hr\Services\PrivateFileService;
use App\Hr\Support\Hr;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff directory (work details: `page.hr`; changes: `hr.manage`) and the personal file (`hr.private`).
 * An employee's card is put together here, part by part, so that what each caller receives is decided in one place:
 * the personal file, the contracts and the private papers are KEYS THAT DO NOT EXIST in the answer without `hr.private`.
 */
class DirectoryController extends Controller
{
    private const EMPLOYEE_RULES = [
        'nameAr' => 'string|min:2|max:200', 'nameEn' => 'nullable|string|max:200', 'employeeNo' => 'nullable|string|max:40', 'departmentId' => 'nullable|string|max:40',
        'jobId' => 'nullable|string|max:40', 'jobTitle' => 'nullable|string|max:200', 'managerId' => 'nullable|string|max:40', 'workEmail' => 'nullable|email|max:200',
        'workPhone' => 'nullable|string|max:40', 'workMobile' => 'nullable|string|max:40', 'workLocation' => 'nullable|string|max:200', 'startDate' => 'nullable|date',
        'status' => 'in:active,left,inactive', 'userId' => 'nullable|string|max:120', 'driverId' => 'nullable|string|max:40', 'notes' => 'nullable|string|max:2000',
    ];

    public function __construct(
        private readonly DirectoryService $directory,
        private readonly PrivateFileService $private,
        private readonly ContractsService $contracts,
        private readonly DocumentsService $documents,
        private readonly AlertsService $alerts,
        private readonly FilesService $files,
    ) {}

    // ── employees
    public function employees(Request $request): array
    {
        return $this->directory->employees(Paging::from($request, 25), $request->only(['status', 'department', 'sub', 'job', 'manager', 'source', 'linked', 'order']));
    }

    public function employee(string $id): array
    {
        $user = AuthUser::current();
        $card = $this->directory->employee($id);
        if (! Hr::seesDocuments($user)) {
            unset($card['notes']); // free text written by HR about a person is HR's, not the directory's
        }
        if (Hr::seesPrivate($user)) {
            $employee = $this->directory->find($card['id']);
            $card['private'] = $this->private->get($user, $employee);
            $card['contracts'] = $this->contracts->forEmployee($user, $employee->id);
            $card['files'] = $this->files->of('employee', $employee->id)->map(fn ($f) => FilesService::shape($f))->all();
        }
        if (Hr::seesDocuments($user)) {
            $card['documents'] = $this->documents->forEmployee($user, $card['id']);
            $card['alerts'] = $this->alerts->forEmployee($user, $card['id']);
        }

        return $card;
    }

    public function storeEmployee(Request $request): JsonResponse
    {
        $data = $request->validate(self::rules(self::EMPLOYEE_RULES, ['nameAr']));

        return response()->json($this->directory->createEmployee(AuthUser::current(), $data), 201);
    }

    public function updateEmployee(Request $request, string $id): array
    {
        return $this->directory->updateEmployee(AuthUser::current(), $id, $request->validate(self::rules(self::EMPLOYEE_RULES)));
    }

    // ── the personal file (hr.private)
    public function privateFile(string $id): array
    {
        return $this->private->get(AuthUser::current(), $this->directory->find($id));
    }

    public function updatePrivateFile(Request $request, string $id): array
    {
        $data = $request->validate(self::rules([
            'nationality' => 'nullable|string|max:80', 'identityNo' => 'nullable|string|max:40', 'identityExpiry' => 'nullable|date', 'passportNo' => 'nullable|string|max:40',
            'passportExpiry' => 'nullable|date', 'birthDate' => 'nullable|date|before:today', 'gender' => 'nullable|in:male,female', 'maritalStatus' => 'nullable|in:single,married,divorced,widowed',
            'children' => 'nullable|integer|min:0|max:30', 'homeAddress' => 'nullable|string|max:2000', 'privatePhone' => 'nullable|string|max:40', 'privateEmail' => 'nullable|email|max:200',
            'bankName' => 'nullable|string|max:120', 'bankAccount' => 'nullable|string|max:60', 'emergencyContact' => 'nullable|string|max:200', 'emergencyPhone' => 'nullable|string|max:40',
            'endDate' => 'nullable|date', 'departureReason' => 'nullable|string|max:250', 'notes' => 'nullable|string|max:2000',
        ]));

        return $this->private->update(AuthUser::current(), $this->directory->find($id), $data);
    }

    // ── departments
    public function departments(Request $request): array
    {
        return $this->directory->departments($request->boolean('archived'));
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        $data = $request->validate(['nameAr' => 'required|string|min:2|max:200', 'nameEn' => 'nullable|string|max:200', 'parentId' => 'nullable|string|max:40', 'managerId' => 'nullable|string|max:40', 'notes' => 'nullable|string|max:2000']);

        return response()->json($this->directory->createDepartment(AuthUser::current(), $data), 201);
    }

    public function updateDepartment(Request $request, string $id): array
    {
        $data = $request->validate(['nameAr' => 'sometimes|string|min:2|max:200', 'nameEn' => 'sometimes|nullable|string|max:200', 'parentId' => 'sometimes|nullable|string|max:40',
            'managerId' => 'sometimes|nullable|string|max:40', 'notes' => 'sometimes|nullable|string|max:2000', 'active' => 'sometimes|boolean']);

        return $this->directory->updateDepartment(AuthUser::current(), $id, $data);
    }

    // ── jobs
    public function jobs(Request $request): array
    {
        return $this->directory->jobs(Paging::from($request, 100), $request->only(['department', 'archived']));
    }

    public function storeJob(Request $request): JsonResponse
    {
        $data = $request->validate(['nameAr' => 'required|string|min:2|max:200', 'nameEn' => 'nullable|string|max:200', 'departmentId' => 'nullable|string|max:40', 'description' => 'nullable|string|max:2000']);

        return response()->json($this->directory->createJob(AuthUser::current(), $data), 201);
    }

    public function updateJob(Request $request, string $id): array
    {
        $data = $request->validate(['nameAr' => 'sometimes|string|min:2|max:200', 'nameEn' => 'sometimes|nullable|string|max:200', 'departmentId' => 'sometimes|nullable|string|max:40',
            'description' => 'sometimes|nullable|string|max:2000', 'active' => 'sometimes|boolean']);

        return $this->directory->updateJob(AuthUser::current(), $id, $data);
    }

    public function org(): array
    {
        return $this->directory->org();
    }

    /** `sometimes` on every rule (a PATCH sends only what changes), `required` on the named ones. */
    private static function rules(array $rules, array $required = []): array
    {
        $out = [];
        foreach ($rules as $field => $rule) {
            $out[$field] = (in_array($field, $required, true) ? 'required|' : 'sometimes|').$rule;
        }

        return $out;
    }
}
