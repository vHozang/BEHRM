<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HrAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AiAssistantController extends Controller
{
    public function __construct(private HrAssistantService $assistant)
    {
    }

    /**
     * POST /ai/ask — ask the HR assistant a question (scoped to the caller).
     */
    public function ask(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:4000',
            'history' => 'sometimes|array',
            'history.*.role' => 'required_with:history|string|in:user,assistant',
            'history.*.content' => 'required_with:history|string',
        ], [
            'message.required' => 'Vui lòng nhập câu hỏi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Dữ liệu không hợp lệ',
                'data' => ['errors' => $validator->errors()->toArray()],
            ], 422);
        }

        $employeeId = (int) $request->attributes->get('auth_employee_id');

        $result = $this->assistant->ask(
            $employeeId,
            (string) $request->input('message'),
            (array) $request->input('history', []),
        );

        // Always 200 — even when not configured — so the FE can render the
        // friendly message (data.configured tells it the feature state).
        return response()->json([
            'status' => 200,
            'message' => $result['configured'] ? 'OK' : 'AI chưa được cấu hình',
            'data' => [
                'answer' => $result['answer'],
                'configured' => $result['configured'],
                'usage' => $result['usage'],
            ],
        ]);
    }
}
