<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrganizationStructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class OrganizationChartController extends Controller
{
    public function __construct(private readonly OrganizationStructureService $service) {}

    public function structure(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'scope' => ['nullable', Rule::in(['company', 'branch', 'department'])],
            'legal_entity_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Dữ liệu không hợp lệ',
                'data' => ['errors' => $validator->errors()->toArray()],
            ], 422);
        }

        $scope = (string) $request->query('scope', 'company');
        if ($scope === 'branch' && ! $request->filled('legal_entity_id')) {
            return $this->scopeError('legal_entity_id', 'Vui lòng chọn chi nhánh');
        }
        if ($scope === 'department' && ! $request->filled('department_id')) {
            return $this->scopeError('department_id', 'Vui lòng chọn phòng ban');
        }

        try {
            $data = $this->service->structure(
                $scope,
                $request->filled('legal_entity_id') ? (int) $request->query('legal_entity_id') : null,
                $request->filled('department_id') ? (int) $request->query('department_id') : null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->scopeError('scope', $exception->getMessage());
        }

        return response()->json([
            'status' => 200,
            'message' => 'Organization structure',
            'data' => $data,
        ]);
    }

    private function scopeError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => 'Dữ liệu không hợp lệ',
            'data' => ['errors' => [$field => [$message]]],
        ], 422);
    }
}
