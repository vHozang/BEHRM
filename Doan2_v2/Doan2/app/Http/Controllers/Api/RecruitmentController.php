<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\InterviewSchedule;
use App\Models\RecruitmentCandidate;
use App\Services\AiFeedbackService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecruitmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = RecruitmentCandidate::with(['position:id,position_name'])
            ->orderByDesc('id');

        foreach (['recruitment_position_id', 'application_status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $page = $query->paginate($perPage);

        return $this->ok([
            'items' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Recruitment candidates list');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email' => 'required|email',
            'recruitment_position_id' => 'nullable|exists:recruitment_positions,id',
        ], [
            'full_name.required' => 'Họ tên ứng viên là bắt buộc',
            'email.required' => 'Email là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $columns = Schema::getColumnListing('recruitment_candidates');
        $data = collect($request->all())->only($columns)->toArray();
        $data['application_status'] = $data['application_status'] ?? 'PENDING';
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $candidate = RecruitmentCandidate::create($data);

        // Enqueue AI scoring job if position has required_skills
        if ($candidate->recruitment_position_id && Schema::hasTable('recruitment_ai_scoring_jobs')) {
            DB::table('recruitment_ai_scoring_jobs')->insert(TenantContext::stamp([
                'candidate_id' => $candidate->id,
                'status' => 'PENDING',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        return response()->json([
            'status' => 201,
            'message' => 'Ứng viên đã được tạo',
            'data' => $candidate,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $candidate = RecruitmentCandidate::with([
            'position:id,position_name',
            'interviews',
        ])->find($id);

        if (! $candidate) {
            return $this->notFound();
        }

        // Attach manager review if exists
        $candidate->manager_review = DB::table('recruitment_candidate_manager_reviews')
            ->where('candidate_id', $id)
            ->first();

        return $this->ok($candidate, 'Candidate detail');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $candidate = RecruitmentCandidate::find($id);

        if (! $candidate) {
            return $this->notFound();
        }

        $columns = Schema::getColumnListing('recruitment_candidates');
        $data = collect($request->except(['id', 'created_at', 'updated_at']))->only($columns)->toArray();

        $candidate->update($data);

        return $this->ok($candidate->fresh(), 'Ứng viên đã được cập nhật');
    }

    public function destroy(int $id): JsonResponse
    {
        $candidate = RecruitmentCandidate::find($id);

        if (! $candidate) {
            return $this->notFound();
        }

        $violations = $candidate->deletionViolations();

        if (! empty($violations)) {
            return $this->conflict($violations, 'Ứng viên');
        }

        $candidate->delete();

        return $this->ok(['id' => $id], 'Ứng viên đã được xóa');
    }

    // ═══════════════════════════════════════════════════════
    // PIPELINE ACTIONS
    // ═══════════════════════════════════════════════════════

    /**
     * POST /recruitment-candidates/{id}/advance — Chuyển stage pipeline.
     */
    public function advanceStage(Request $request, int $id): JsonResponse
    {
        $candidate = RecruitmentCandidate::find($id);

        if (! $candidate) {
            return $this->notFound();
        }

        $validTransitions = [
            'PENDING' => ['SCREENING'],
            'SCREENING' => ['INTERVIEWING', 'REJECTED'],
            'INTERVIEWING' => ['OFFERED', 'REJECTED'],
            'OFFERED' => ['HIRED', 'REJECTED'],
        ];

        $newStatus = $request->input('status');
        $allowed = $validTransitions[$candidate->application_status] ?? [];

        if (! in_array($newStatus, $allowed)) {
            return $this->validationError([
                'status' => ["Không thể chuyển từ {$candidate->application_status} sang {$newStatus}"],
            ]);
        }

        $candidate->update(['application_status' => $newStatus]);

        return $this->ok($candidate->fresh(), "Ứng viên đã chuyển sang {$newStatus}");
    }

    /**
     * POST /recruitment-candidates/{id}/cv — Upload CV.
     */
    public function uploadCv(Request $request, int $id): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        if (! RecruitmentCandidate::where('id', $id)->exists()) {
            return $this->notFound();
        }

        $path = $request->file('file')->store("candidate-cvs/{$id}");

        DB::table('recruitment_candidate_cvs')->updateOrInsert(
            ['candidate_id' => $id],
            TenantContext::stamp([
                'original_filename' => $request->file('file')->getClientOriginalName(),
                'storage_path' => $path,
                'mime_type' => $request->file('file')->getMimeType(),
                'file_size' => $request->file('file')->getSize(),
                'updated_at' => now(),
                'created_at' => now(),
            ])
        );

        return $this->ok(
            DB::table('recruitment_candidate_cvs')->where('candidate_id', $id)->first(),
            'CV đã được upload'
        );
    }

    /**
     * GET /recruitment-candidates/{id}/cv — Download CV.
     */
    public function downloadCv(int $id): JsonResponse|StreamedResponse
    {
        // Tenant guard: ensure the candidate belongs to the current tenant (matches sibling CV methods).
        if (! RecruitmentCandidate::where('id', $id)->exists()) {
            return $this->notFound();
        }

        $cv = DB::table('recruitment_candidate_cvs')->where('candidate_id', $id)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('recruitment_candidate_cvs.tenant_id', TenantContext::id()))
            ->first();

        if (! $cv || ! Storage::exists($cv->storage_path)) {
            return $this->notFound('CV không tồn tại');
        }

        return Storage::download($cv->storage_path, $cv->original_filename);
    }

    /**
     * PATCH /recruitment-candidates/{id}/manager-review — Đánh giá ứng viên.
     *
     * Nếu ứng viên đã có ai_score và manager_score được gửi lên,
     * tự động gửi feedback cho dịch vụ AI AutoRecruit để phân tích
     * sự lệch điểm và giúp AI học lại.
     */
    public function managerReview(Request $request, int $id): JsonResponse
    {
        $candidate = RecruitmentCandidate::find($id);

        if (! $candidate) {
            return $this->notFound();
        }

        $data = $request->all();
        $data['candidate_id'] = $id;
        $data['reviewed_by'] = $request->attributes->get('auth_employee_id');
        $data['updated_at'] = now();

        DB::table('recruitment_candidate_manager_reviews')->updateOrInsert(
            ['candidate_id' => $id],
            TenantContext::stamp($data + ['created_at' => now()])
        );

        // ── AI Feedback Loop ─────────────────────────────────
        // Nếu ứng viên đã có ai_score VÀ request gửi lên manager_score,
        // tự động gửi feedback cho AutoRecruit để AI học lại.
        $managerScore = $request->input('manager_score');
        $aiScore = $candidate->ai_score;

        $feedbackAnalysis = null;

        if ($managerScore !== null && $aiScore !== null) {
            try {
                // Thu thập dữ liệu về vị trí tuyển dụng
                $position = $candidate->recruitment_position_id
                    ? DB::table('recruitment_positions')
                        ->where('id', $candidate->recruitment_position_id)
                        ->first()
                    : null;

                $jdText = '';
                $cvSkills = [];

                if ($position) {
                    $positionName = $position->position_name ?? 'Job Position';
                    $requiredSkillsList = json_decode($position->required_skills_json ?? '[]', true) ?: [];
                    $jdText = "Position: {$positionName}. Requirements: " . implode(', ', $requiredSkillsList);
                }

                // Kỹ năng từ meta của ứng viên
                $candidateMeta = json_decode($candidate->meta ?? '{}', true) ?: [];
                $cvSkills = is_array($candidateMeta['skills'] ?? null)
                    ? $candidateMeta['skills']
                    : [];

                // Kỹ năng AI đã match / thiếu
                $matchedSkills = json_decode($candidate->ai_matched_skills_json ?? '[]', true) ?: [];
                $missingSkills = json_decode($candidate->ai_missing_skills_json ?? '[]', true) ?: [];

                // Gửi feedback (fire-and-forget: không block nếu AI service lỗi)
                $feedbackService = new AiFeedbackService();
                $feedbackResult = $feedbackService->sendFeedback(
                    candidateId: $id,
                    aiScore: (int) $aiScore,
                    humanScore: (int) $managerScore,
                    jdText: $jdText,
                    cvSkills: $cvSkills,
                    matchedSkills: $matchedSkills,
                    missingSkills: $missingSkills
                );

                // Lưu phân tích AI vào meta của review
                if ($feedbackResult && isset($feedbackResult['analysis'])) {
                    $feedbackAnalysis = $feedbackResult['analysis'];
                    $reviewMeta = json_decode(
                        DB::table('recruitment_candidate_manager_reviews')
                            ->where('candidate_id', $id)
                            ->value('meta') ?? '{}',
                        true
                    ) ?: [];

                    $reviewMeta['ai_feedback_analysis'] = $feedbackAnalysis;
                    $reviewMeta['ai_feedback_sent_at'] = now()->toIso8601String();

                    DB::table('recruitment_candidate_manager_reviews')
                        ->where('candidate_id', $id)
                        ->update(['meta' => json_encode($reviewMeta, JSON_UNESCAPED_UNICODE)]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error(
                    "AI feedback failed for candidate #{$id}: " . $e->getMessage()
                );
            }
        }

        $review = DB::table('recruitment_candidate_manager_reviews')
            ->where('candidate_id', $id)
            ->first();

        $responseData = (array) $review;
        if ($feedbackAnalysis) {
            $responseData['ai_feedback_analysis'] = $feedbackAnalysis;
        }

        return $this->ok($responseData, 'Đánh giá quản lý đã được lưu');
    }

    /**
     * POST /recruitment-candidates/{id}/ai-score/retry — Retry AI scoring.
     */
    public function retryAiScore(int $id): JsonResponse
    {
        $candidate = RecruitmentCandidate::find($id);

        if (! $candidate) {
            return $this->notFound();
        }

        if (Schema::hasTable('recruitment_ai_scoring_jobs')) {
            DB::table('recruitment_ai_scoring_jobs')->updateOrInsert(
                ['candidate_id' => $id],
                TenantContext::stamp([
                    'status' => 'PENDING',
                    'updated_at' => now(),
                    'created_at' => now(),
                ])
            );
        }

        $candidate->update(['ai_scoring_status' => 'PENDING']);

        return $this->ok($candidate->fresh(), 'AI scoring đã được đặt lại');
    }

    /**
     * POST /recruitment-candidates/{id}/reject — Từ chối + archive.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $candidate = RecruitmentCandidate::find($id);

        if (! $candidate) {
            return $this->notFound();
        }

        DB::transaction(function () use ($candidate, $request) {
            // Archive snapshot
            if (Schema::hasTable('recruitment_rejected_archive')) {
                DB::table('recruitment_rejected_archive')->insert(TenantContext::stamp([
                    'candidate_id' => $candidate->id,
                    'snapshot' => json_encode($candidate->toArray()),
                    'rejection_reason' => $request->input('reason'),
                    'rejected_by' => $request->attributes->get('auth_employee_id'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            $candidate->update(['application_status' => 'REJECTED']);
        });

        return $this->ok($candidate->fresh(), 'Ứng viên đã bị từ chối');
    }

    /**
     * POST /recruitment-candidates/{id}/hire — Tuyển dụng + tạo NV.
     */
    public function hire(Request $request, int $id): JsonResponse
    {
        $candidate = RecruitmentCandidate::find($id);

        if (! $candidate) {
            return $this->notFound();
        }

        if ($candidate->application_status !== 'OFFERED') {
            return $this->validationError([
                'status' => ['Chỉ có thể tuyển ứng viên đã được offer'],
            ]);
        }

        $employee = DB::transaction(function () use ($candidate, $request) {
            $candidate->update(['application_status' => 'HIRED']);

            // Create employee from candidate
            $employee = Employee::create([
                'full_name' => $candidate->full_name,
                'company_email' => $candidate->email,
                'phone' => $candidate->phone,
                'status' => 'ACTIVE',
                'hire_date' => now()->toDateString(),
                'department_id' => $request->input('department_id'),
                'position_id' => $request->input('position_id'),
                'password_hash' => \Hash::make('password'),
            ]);

            return $employee;
        });

        return $this->ok([
            'candidate' => $candidate->fresh(),
            'employee' => $employee,
        ], 'Ứng viên đã được tuyển dụng thành công');
    }

    // ═══════════════════════════════════════════════════════
    // INTERVIEWS
    // ═══════════════════════════════════════════════════════

    public function interviewsIndex(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = InterviewSchedule::with(['candidate:id,full_name'])
            ->orderByDesc('id');

        foreach (['candidate_id', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        $page = $query->paginate($perPage);

        return $this->ok([
            'items' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Interview schedules list');
    }

    public function storeInterview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'candidate_id' => 'required|exists:recruitment_candidates,id',
            'interview_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $columns = Schema::getColumnListing('interview_schedules');
        $data = collect($request->all())->only($columns)->toArray();
        $data['status'] = $data['status'] ?? 'SCHEDULED';
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $interview = InterviewSchedule::create($data);

        return response()->json([
            'status' => 201,
            'message' => 'Lịch phỏng vấn đã được tạo',
            'data' => $interview->fresh()->load('candidate:id,full_name'),
        ], 201);
    }

    public function showInterview(int $id): JsonResponse
    {
        $interview = InterviewSchedule::with(['candidate:id,full_name'])->find($id);

        if (! $interview) {
            return $this->notFound();
        }

        return $this->ok($interview, 'Interview detail');
    }

    /**
     * PATCH /interviews/{id}/manager-review
     */
    public function interviewManagerReview(Request $request, int $id): JsonResponse
    {
        $interview = InterviewSchedule::find($id);

        if (! $interview) {
            return $this->notFound();
        }

        $columns = Schema::getColumnListing('interview_schedules');
        $data = collect($request->all())->only($columns)->toArray();

        $interview->update($data);

        return $this->ok($interview->fresh(), 'Đánh giá phỏng vấn đã được lưu');
    }

    public function updateInterview(Request $request, int $id): JsonResponse
    {
        $interview = InterviewSchedule::find($id);

        if (! $interview) {
            return $this->notFound();
        }

        $columns = Schema::getColumnListing('interview_schedules');
        $data = collect($request->all())->only($columns)->toArray();

        $interview->update($data);

        return $this->ok($interview->fresh()->load('candidate:id,full_name'), 'Lịch phỏng vấn đã được cập nhật');
    }

    public function destroyInterview(int $id): JsonResponse
    {
        $interview = InterviewSchedule::find($id);

        if (! $interview) {
            return $this->notFound();
        }

        $interview->delete();

        return $this->ok(['id' => $id], 'Lịch phỏng vấn đã được xóa');
    }

    // ── Response Helpers ─────────────────────────────────

    /**
     * GET /recruitment-ai/feedback-stats — Thống kê feedback AI.
     */
    public function aiFeedbackStats(): JsonResponse
    {
        $feedbackService = new AiFeedbackService();

        $stats = $feedbackService->getStats();
        $adjustments = $feedbackService->getAdjustments();

        if ($stats === null && $adjustments === null) {
            return $this->ok(
                ['message' => 'Dịch vụ AI không khả dụng'],
                'Không thể lấy thống kê feedback AI'
            );
        }

        return $this->ok([
            'stats' => $stats,
            'adjustments' => $adjustments,
        ], 'Thống kê feedback AI');
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(string $message = 'Record not found'): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => $message, 'data' => null], 404);
    }

    private function conflict(array $violations, string $resourceName): JsonResponse
    {
        return response()->json([
            'status' => 409,
            'message' => "Không thể xóa {$resourceName} do vi phạm ràng buộc nghiệp vụ",
            'data' => ['violations' => $violations],
        ], 409);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => 'Dữ liệu không hợp lệ',
            'data' => ['errors' => $errors],
        ], 422);
    }
}
