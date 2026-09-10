<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContractTemplate;
use App\Support\ContractRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ContractTemplateController extends Controller
{
    /**
     * GET /contract-templates — danh sách mẫu. Tự tạo mẫu chuẩn mặc định nếu
     * tenant chưa có mẫu nào (để luôn có ít nhất 1 mẫu để dùng/sửa).
     */
    public function index(): JsonResponse
    {
        // Đảm bảo luôn có một mẫu chuẩn mặc định để dùng/sửa.
        // (PG boolean: ghi/lọc qua DB::raw để tránh lỗi "boolean = integer".)
        if (! ContractTemplate::whereRaw('is_default = true')->exists()) {
            $tpl = ContractTemplate::create([
                'template_name' => 'HĐLĐ chuẩn (mẫu mặc định)',
                'content' => ContractRenderer::defaultTemplate(),
                'source' => 'system',
            ]);
            DB::table('contract_templates')->where('id', $tpl->id)->update([
                'is_default' => DB::raw('true'),
                'is_active' => DB::raw('true'),
            ]);
        }

        $items = ContractTemplate::orderByDesc('is_default')->orderBy('template_name')->get();

        return $this->ok($items, 'Contract templates');
    }

    /** GET /contract-templates/placeholders — danh mục trường trộn. */
    public function placeholders(): JsonResponse
    {
        return $this->ok(ContractRenderer::placeholders(), 'Placeholders');
    }

    public function show(int $id): JsonResponse
    {
        $tpl = ContractTemplate::find($id);

        return $tpl ? $this->ok($tpl, 'Template') : $this->notFound();
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'content' => 'required|string',
        ], [
            'name.required' => 'Tên mẫu là bắt buộc',
            'content.required' => 'Nội dung mẫu là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $tpl = ContractTemplate::create([
            'template_name' => $request->input('name'),
            'content' => $request->input('content'),
            'source' => $request->input('source', 'uploaded'),
            'meta' => is_array($request->input('meta')) ? $request->input('meta') : null,
        ]);

        $isDefault = (bool) $request->input('is_default', false);
        DB::table('contract_templates')->where('id', $tpl->id)->update([
            'is_active' => DB::raw('true'),
            'is_default' => DB::raw($isDefault ? 'true' : 'false'),
        ]);
        if ($isDefault) {
            DB::table('contract_templates')->where('id', '!=', $tpl->id)
                ->where('tenant_id', $tpl->tenant_id)->update(['is_default' => DB::raw('false')]);
        }

        return response()->json(['status' => 201, 'message' => 'Đã tạo mẫu hợp đồng', 'data' => $tpl->fresh()], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tpl = ContractTemplate::find($id);
        if (! $tpl) {
            return $this->notFound();
        }

        $payload = $request->only(['content', 'source']);
        if ($request->filled('name')) {
            $payload['template_name'] = $request->input('name');
        }
        if (is_array($request->input('meta'))) {
            $payload['meta'] = $request->input('meta');
        }
        if (! empty($payload)) {
            $tpl->update($payload);
        }

        if ($request->has('is_default')) {
            $isDefault = $request->boolean('is_default');
            DB::table('contract_templates')->where('id', $tpl->id)->update(['is_default' => DB::raw($isDefault ? 'true' : 'false')]);
            if ($isDefault) {
                DB::table('contract_templates')->where('id', '!=', $tpl->id)
                    ->where('tenant_id', $tpl->tenant_id)->update(['is_default' => DB::raw('false')]);
            }
        }

        return $this->ok($tpl->fresh(), 'Đã cập nhật mẫu hợp đồng');
    }

    public function destroy(int $id): JsonResponse
    {
        $tpl = ContractTemplate::find($id);
        if (! $tpl) {
            return $this->notFound();
        }

        $tpl->delete();

        return $this->ok(['id' => $id], 'Đã xóa mẫu hợp đồng');
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(string $message = 'Record not found'): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => $message, 'data' => null], 404);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json(['status' => 422, 'message' => 'Dữ liệu không hợp lệ', 'data' => ['errors' => $errors]], 422);
    }
}
