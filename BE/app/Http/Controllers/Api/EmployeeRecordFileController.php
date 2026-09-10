<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AttendanceAccess;
use App\Support\AccessControl;
use App\Support\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeRecordFileController extends Controller
{
    private const TARGETS = [
        'identity-documents' => ['table' => 'identity_documents', 'employee' => 'employee_id', 'fields' => ['front' => 'front_image_url', 'back' => 'back_image_url']],
        'qualifications' => ['table' => 'qualifications', 'employee' => 'employee_id', 'fields' => ['file' => 'file_url']],
    ];

    public function __construct(private readonly AttendanceAccess $access) {}

    public function upload(Request $request, string $resource, int $id, string $slot): JsonResponse
    {
        abort_unless(AccessControl::accessHasCapability((array) $request->attributes->get('access', []), 'employee.records.manage'), 403);
        [$target, $row, $column] = $this->resolve($request, $resource, $id, $slot, true);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ]);
        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs(
            'private/employee-records/'.TenantContext::id().'/'.$row->{$target['employee']}.'/'.$resource,
            $id.'-'.$slot.'-'.now()->format('YmdHis').'.'.$extension,
            'local'
        );
        $oldPath = $row->{$column} ?? null;
        DB::table($target['table'])->where('tenant_id', TenantContext::id())->where('id', $id)
            ->update([$column => $path, 'updated_at' => now()]);
        if (is_string($oldPath) && str_starts_with($oldPath, 'private/employee-records/') && Storage::disk('local')->exists($oldPath)) {
            Storage::disk('local')->delete($oldPath);
        }
        AuditLogger::log('upload_private_file', $target['table'], $id, [$column => $oldPath], [$column => $path]);

        return response()->json(['status' => 200, 'message' => 'Đã lưu tệp hồ sơ riêng tư', 'data' => ['id' => $id, 'slot' => $slot, 'has_file' => true]]);
    }

    public function download(Request $request, string $resource, int $id, string $slot): StreamedResponse|JsonResponse
    {
        [$target, $row, $column] = $this->resolve($request, $resource, $id, $slot, false);
        $path = $row->{$column} ?? null;
        if (! is_string($path) || ! str_starts_with($path, 'private/employee-records/') || ! Storage::disk('local')->exists($path)) {
            return response()->json(['status' => 404, 'message' => 'Không tìm thấy tệp hồ sơ', 'data' => null], 404);
        }

        return Storage::disk('local')->download($path);
    }

    /** @return array{0:array<string,mixed>,1:object,2:string} */
    private function resolve(Request $request, string $resource, int $id, string $slot, bool $write): array
    {
        $target = self::TARGETS[$resource] ?? null;
        abort_unless($target && isset($target['fields'][$slot]), 404);
        $column = $target['fields'][$slot];
        $row = DB::table($target['table'])->where('tenant_id', TenantContext::id())->where('id', $id)->first();
        abort_unless($row, 404);
        $employeeId = (int) $row->{$target['employee']};
        if ($write) {
            abort_unless($this->access->canAccessEmployee($request, $employeeId, false), 403);
        } else {
            $isOwner = $employeeId === $this->access->actorId($request);
            $canManage = AccessControl::accessHasCapability((array) $request->attributes->get('access', []), 'employee.records.manage');
            abort_unless($isOwner || ($canManage && $this->access->canAccessEmployee($request, $employeeId, false)), 403);
        }

        return [$target, $row, $column];
    }
}
