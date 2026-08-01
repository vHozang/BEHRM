<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\InterviewSchedule;
use App\Models\RecruitmentCandidate;
use App\Services\AiFeedbackService;
use App\Services\AutoRecruitScreeningService;
use App\Services\GoogleMeetService;
use App\Services\RecruitmentMailService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecruitmentController extends Controller
{
    public function publicPositions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_code' => 'required|string|exists:tenants,code',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $tenantId = DB::table('tenants')
            ->where('code', $request->input('tenant_code'))
            ->where('status', 'ACTIVE')
            ->value('id');
        if (! $tenantId) {
            return $this->notFound('Công ty không tồn tại hoặc đã ngừng hoạt động');
        }

        $positions = DB::table('recruitment_positions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'OPEN')
            ->orderBy('position_name')
            ->get(['id', 'position_name', 'employment_type', 'required_skills_json']);

        return $this->ok($positions, 'Open recruitment positions');
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = RecruitmentCandidate::with([
            'position:id,position_name',
            'cv:id,candidate_id,original_filename,storage_path,mime_type,file_size',
        ])
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
        $publicTenantId = null;
        if (! TenantContext::hasTenant()) {
            $publicValidator = Validator::make($request->all(), [
                'tenant_code' => 'required|string|exists:tenants,code',
            ]);
            if ($publicValidator->fails()) {
                return $this->validationError($publicValidator->errors()->toArray());
            }
            $publicTenantId = DB::table('tenants')
                ->where('code', $request->input('tenant_code'))
                ->where('status', 'ACTIVE')
                ->value('id');
            if (! $publicTenantId) {
                return $this->notFound('Công ty không tồn tại hoặc đã ngừng hoạt động');
            }
        }

        $input = $request->all();
        if (array_key_exists('phone', $input) && ! array_key_exists('phone_number', $input)) {
            $input['phone_number'] = $input['phone'];
        }

        $validator = Validator::make($input, [
            'full_name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone_number' => 'nullable|string|max:50',
            'recruitment_position_id' => 'nullable|exists:recruitment_positions,id',
        ], [
            'full_name.required' => 'Họ tên ứng viên là bắt buộc',
            'email.required' => 'Email là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : (int) $publicTenantId;
        if ($request->filled('recruitment_position_id') && ! DB::table('recruitment_positions')
            ->where('id', $request->input('recruitment_position_id'))
            ->where('tenant_id', $tenantId)
            ->exists()) {
            return $this->validationError([
                'recruitment_position_id' => ['Vị trí tuyển dụng không thuộc công ty đã chọn'],
            ]);
        }

        $columns = Schema::getColumnListing('recruitment_candidates');
        $data = collect($input)->only($columns)->toArray();
        $data['application_status'] = $data['application_status'] ?? 'PENDING';
        $data['tenant_id'] = $tenantId;
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $candidate = RecruitmentCandidate::create($data);

        // Enqueue AI scoring job if position has required_skills
        if ($candidate->recruitment_position_id && Schema::hasTable('recruitment_ai_scoring_jobs')) {
            DB::table('recruitment_ai_scoring_jobs')->insert(TenantContext::stamp([
                'candidate_id' => $candidate->id,
                'tenant_id' => $tenantId,
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

    /**
     * Public application endpoint used by the careers landing page.
     * Laravel stores the CV and proxies it to the private AutoRecruit service.
     */
    public function publicApplication(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_code' => ['required', 'string', 'exists:tenants,code'],
            'post_slug' => ['required', 'string'],
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
            'cv' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ], [
            'cv.required' => 'Vui lòng tải lên CV của bạn',
            'cv.mimes' => 'CV chỉ hỗ trợ định dạng PDF, DOC hoặc DOCX',
            'cv.max' => 'CV không được vượt quá 10 MB',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $tenant = DB::table('tenants')
            ->where('code', $request->input('tenant_code'))
            ->where('status', 'ACTIVE')
            ->first(['id', 'code']);

        if (! $tenant) {
            return $this->notFound('Công ty không tồn tại hoặc đã ngừng hoạt động');
        }

        $post = DB::table('recruitment_posts')
            ->where('tenant_id', $tenant->id)
            ->where('slug', $request->input('post_slug'))
            ->where('status', 'PUBLISHED')
            ->first();

        if (! $post) {
            return $this->notFound('Tin tuyển dụng không tồn tại hoặc đã đóng');
        }

        if ($post->deadline && now()->startOfDay()->gt($post->deadline)) {
            return $this->validationError(['post_slug' => ['Tin tuyển dụng đã hết hạn nhận hồ sơ']]);
        }

        $position = $post->recruitment_position_id
            ? DB::table('recruitment_positions')->where('id', $post->recruitment_position_id)->first()
            : null;
        $requirements = json_decode($post->requirements ?? '[]', true) ?: [];
        $requiredSkills = $position
            ? (json_decode($position->required_skills_json ?? '[]', true) ?: [])
            : [];
        $jdText = implode("\n", array_filter([
            $post->title,
            $post->summary,
            $post->content,
            $position?->position_name,
            'Yêu cầu: '.implode(', ', $requiredSkills),
            'Chi tiết: '.implode('; ', $requirements),
        ]));

        $candidate = RecruitmentCandidate::create([
            'tenant_id' => $tenant->id,
            'recruitment_position_id' => $post->recruitment_position_id,
            'full_name' => $request->input('full_name'),
            'email' => $request->input('email'),
            'phone_number' => $request->input('phone'),
            'application_status' => 'PENDING',
            'ai_scoring_status' => 'PENDING',
            'meta' => [
                'cover_letter' => $request->input('cover_letter'),
                'submitted_via' => 'careers_landing',
            ],
        ]);

        $file = $request->file('cv');
        $storagePath = $file->store("candidate-cvs/{$candidate->id}");
        DB::table('recruitment_candidate_cvs')->updateOrInsert(
            ['candidate_id' => $candidate->id],
            [
                'tenant_id' => $tenant->id,
                'original_filename' => $file->getClientOriginalName(),
                'storage_path' => $storagePath,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $screening = null;
        try {
            $screening = app(AutoRecruitScreeningService::class)->screen($file, $jdText);
            $screenedCandidate = $screening['candidate'];
            $scores = $screenedCandidate['scores'] ?? [];
            $finalScore = isset($scores['final_score']) ? (float) $scores['final_score'] : null;
            $meta = $candidate->meta ?? [];
            $meta['skills'] = $screenedCandidate['skills'] ?? [];
            $meta['experience_years'] = $screenedCandidate['years_experience'] ?? null;
            $meta['screening'] = [
                'job_id' => $screening['job_id'],
                'recommendation' => $scores['recommendation'] ?? null,
                'analysis' => $screenedCandidate['analysis'] ?? [],
            ];

            $candidate->update([
                'application_status' => 'SCREENING',
                'ai_score' => $finalScore === null ? null : round($finalScore * 100, 1),
                'ai_scoring_status' => 'DONE',
                'ai_scored_at' => now(),
                'ai_matched_skills_json' => $scores['matched_skills'] ?? [],
                'ai_missing_skills_json' => $scores['missing_skills'] ?? [],
                'meta' => $meta,
            ]);
        } catch (\Throwable $exception) {
            Log::warning("Public CV screening deferred for candidate #{$candidate->id}", [
                'error' => $exception->getMessage(),
            ]);
            $candidate->update([
                'ai_scoring_status' => 'FAILED',
                'ai_scoring_error' => 'CV đã nhận; hệ thống sẽ chấm lại sau',
            ]);
        }

        $candidate->refresh();
        $confirmationEmailSent = app(RecruitmentMailService::class)->sendApplicationReceived($candidate);

        return response()->json([
            'status' => 201,
            'message' => $candidate->ai_scoring_status === 'DONE'
                ? 'Hồ sơ đã được gửi và chấm điểm thành công'
                : 'Hồ sơ đã được gửi; hệ thống sẽ hoàn tất chấm điểm sau',
            'data' => [
                'candidate_id' => $candidate->id,
                'application_status' => $candidate->application_status,
                'ai_scoring_status' => $candidate->ai_scoring_status,
                'ai_score' => $candidate->ai_score,
                'matched_skills' => $candidate->ai_matched_skills_json ?? [],
                'missing_skills' => $candidate->ai_missing_skills_json ?? [],
                'confirmation_email_sent' => $confirmationEmailSent,
            ],
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $candidate = RecruitmentCandidate::with([
            'position:id,position_name',
            'cv:id,candidate_id,original_filename,storage_path,mime_type,file_size',
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

        $input = $request->except(['id', 'created_at', 'updated_at']);
        if (array_key_exists('phone', $input) && ! array_key_exists('phone_number', $input)) {
            $input['phone_number'] = $input['phone'];
        }

        $columns = Schema::getColumnListing('recruitment_candidates');
        $data = collect($input)->only($columns)->toArray();

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

        $allowed = $validTransitions[$candidate->application_status] ?? [];
        $newStatus = $request->input('status', $allowed[0] ?? null);

        if (! is_string($newStatus) || ! in_array($newStatus, $allowed, true)) {
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
     * Gửi feedback về AutoRecruit khi có cả điểm AI và điểm quản lý.
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

        $managerScore = $request->input('manager_score');
        $aiScore = $candidate->ai_score;
        $feedbackAnalysis = null;

        if ($managerScore !== null && $aiScore !== null) {
            try {
                $position = $candidate->recruitment_position_id
                    ? DB::table('recruitment_positions')->where('id', $candidate->recruitment_position_id)->first()
                    : null;
                $requiredSkills = $position
                    ? (json_decode($position->required_skills_json ?? '[]', true) ?: [])
                    : [];
                $candidateMeta = json_decode($candidate->meta ?? '{}', true) ?: [];
                $matchedSkills = json_decode($candidate->ai_matched_skills_json ?? '[]', true) ?: [];
                $missingSkills = json_decode($candidate->ai_missing_skills_json ?? '[]', true) ?: [];

                $feedbackResult = (new AiFeedbackService)->sendFeedback(
                    candidateId: $id,
                    aiScore: (int) $aiScore,
                    humanScore: (int) $managerScore,
                    jdText: $position
                        ? 'Position: '.($position->position_name ?? 'Job Position').'. Requirements: '.implode(', ', $requiredSkills)
                        : '',
                    cvSkills: is_array($candidateMeta['skills'] ?? null) ? $candidateMeta['skills'] : [],
                    matchedSkills: $matchedSkills,
                    missingSkills: $missingSkills
                );

                if ($feedbackResult && isset($feedbackResult['analysis'])) {
                    $feedbackAnalysis = $feedbackResult['analysis'];
                    $reviewMeta = json_decode(
                        DB::table('recruitment_candidate_manager_reviews')->where('candidate_id', $id)->value('meta') ?? '{}',
                        true
                    ) ?: [];
                    $reviewMeta['ai_feedback_analysis'] = $feedbackAnalysis;
                    $reviewMeta['ai_feedback_sent_at'] = now()->toIso8601String();
                    DB::table('recruitment_candidate_manager_reviews')
                        ->where('candidate_id', $id)
                        ->update(['meta' => json_encode($reviewMeta, JSON_UNESCAPED_UNICODE)]);
                }
            } catch (\Throwable $e) {
                Log::error("AI feedback failed for candidate #{$id}: ".$e->getMessage());
            }
        }

        $responseData = (array) DB::table('recruitment_candidate_manager_reviews')
            ->where('candidate_id', $id)
            ->first();
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

        if (in_array($candidate->application_status, ['REJECTED', 'HIRED'], true)) {
            return $this->validationError([
                'status' => ['Không thể từ chối ứng viên đã kết thúc quy trình'],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        DB::transaction(function () use ($candidate, $request) {
            // Archive snapshot
            if (Schema::hasTable('recruitment_rejected_archive')) {
                DB::table('recruitment_rejected_archive')->insert(TenantContext::stamp([
                    'candidate_id' => $candidate->id,
                    'snapshot' => json_encode($candidate->toArray()),
                    'rejected_by' => $request->attributes->get('auth_employee_id'),
                    'meta' => json_encode([
                        'rejection_reason' => $request->input('reason'),
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            $candidate->update(['application_status' => 'REJECTED']);
        });

        $candidate->refresh();
        $mailSent = app(RecruitmentMailService::class)->sendRejected(
            $candidate,
            $request->input('reason'),
            $request->attributes->get('auth_employee_id'),
        );
        $candidate->setAttribute('notification_email_sent', $mailSent);

        return $this->ok($candidate, 'Ứng viên đã bị từ chối');
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

        if (! in_array($candidate->application_status, ['INTERVIEWING', 'OFFERED'], true)) {
            return $this->validationError([
                'status' => ['Chỉ có thể tuyển ứng viên đang phỏng vấn hoặc đã được offer'],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'start_date' => ['nullable', 'date', 'after_or_equal:today'],
            'arrival_time' => ['nullable', 'date_format:H:i'],
            'work_location' => ['nullable', 'string', 'max:500'],
            'offer_note' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $employee = DB::transaction(function () use ($candidate, $request) {
            $candidate->update(['application_status' => 'HIRED']);

            // Create employee from candidate
            $employee = Employee::create([
                'full_name' => $candidate->full_name,
                'company_email' => $candidate->email,
                'phone_number' => $candidate->phone_number,
                'status' => 'ACTIVE',
                'hire_date' => $request->input('start_date', now()->addWeekday()->toDateString()),
                'department_id' => $request->input('department_id'),
                'position_id' => $request->input('position_id'),
                'password_hash' => \Hash::make('password'),
            ]);

            return $employee;
        });

        $candidate->refresh();
        $mailSent = app(RecruitmentMailService::class)->sendHired(
            $candidate,
            $request->only(['start_date', 'arrival_time', 'work_location', 'offer_note']),
            $request->attributes->get('auth_employee_id'),
        );

        return $this->ok([
            'candidate' => $candidate->fresh(),
            'employee' => $employee,
            'notification_email_sent' => $mailSent,
        ], 'Ứng viên đã được tuyển dụng thành công');
    }

    // ═══════════════════════════════════════════════════════
    // INTERVIEWS
    // ═══════════════════════════════════════════════════════

    public function interviewsIndex(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = InterviewSchedule::with(['candidate:id,full_name,email,recruitment_position_id', 'candidate.position:id,position_name'])
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
            'interview_time' => ['nullable', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'interview_mode' => ['nullable', 'in:ONSITE,ONLINE,HYBRID'],
            'interviewer_id' => ['nullable', 'integer', 'exists:employees,id'],
            'interviewer' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:500'],
            'meeting_link' => ['nullable', 'url', 'max:1000'],
            'auto_create_meeting' => ['nullable', 'boolean'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'confirmation_deadline' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $timezone = (string) config('services.google_calendar.timezone', 'Asia/Ho_Chi_Minh');
        $parsedDate = Carbon::parse($request->input('interview_date'), $timezone)->setTimezone($timezone);
        $interviewTime = $request->input('interview_time', $parsedDate->format('H:i'));
        $scheduledAt = Carbon::parse(
            $parsedDate->toDateString().' '.substr((string) $interviewTime, 0, 5),
            $timezone,
        );
        $columns = Schema::getColumnListing('interview_schedules');
        $data = collect($request->all())->only($columns)->toArray();
        $data['interview_date'] = $scheduledAt->toDateString();
        $data['interview_time'] = $scheduledAt->format('H:i');
        $data['interview_mode'] = $request->input(
            'interview_mode',
            $request->filled('meeting_link') || filter_var($request->input('location'), FILTER_VALIDATE_URL)
                ? 'ONLINE'
                : 'ONSITE',
        );
        $data['status'] = match (strtolower((string) ($data['status'] ?? 'scheduled'))) {
            'pending', 'scheduled' => 'SCHEDULED',
            'passed', 'completed' => 'COMPLETED',
            'failed', 'cancelled' => 'CANCELLED',
            default => strtoupper((string) $data['status']),
        };
        $candidate = RecruitmentCandidate::with('position:id,position_name')
            ->findOrFail($request->integer('candidate_id'));
        $durationMinutes = $request->integer('duration_minutes')
            ?: (int) config('recruitment.mail.interview_duration_minutes', 60);

        try {
            $meetingMeta = $this->resolveMeetingMeta(
                $request,
                $candidate,
                $scheduledAt,
                (string) $data['interview_mode'],
                $durationMinutes,
            );
        } catch (\Throwable $exception) {
            Log::warning('Could not prepare interview meeting link', [
                'candidate_id' => $candidate->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->validationError([
                'meeting_link' => [$exception->getMessage()],
            ]);
        }

        $data['meta'] = array_filter([
            'interviewer' => $request->input('interviewer'),
            'location' => $request->input('location'),
            'duration_minutes' => $durationMinutes,
            'confirmation_deadline' => $request->input('confirmation_deadline'),
            ...$meetingMeta,
        ], static fn ($value) => $value !== null && $value !== '');
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $interview = InterviewSchedule::create($data);
        if (in_array($candidate->application_status, ['PENDING', 'SCREENING'], true)) {
            $candidate->update(['application_status' => 'INTERVIEWING']);
        }
        $mailSent = app(RecruitmentMailService::class)->sendInterviewInvitation(
            $candidate,
            $interview,
            $request->attributes->get('auth_employee_id'),
        );
        $interview->setAttribute('invitation_email_sent', $mailSent);

        return response()->json([
            'status' => 201,
            'message' => 'Lịch phỏng vấn đã được tạo',
            'data' => $interview->load('candidate:id,full_name,email,recruitment_position_id'),
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

        $validator = Validator::make($request->all(), [
            'interview_date' => ['sometimes', 'date'],
            'interview_time' => ['nullable', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'interview_mode' => ['nullable', 'in:ONSITE,ONLINE,HYBRID'],
            'interviewer' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:500'],
            'meeting_link' => ['nullable', 'url', 'max:1000'],
            'auto_create_meeting' => ['nullable', 'boolean'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'confirmation_deadline' => ['nullable', 'date'],
            'result_note' => ['nullable', 'string', 'max:5000'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $timezone = (string) config('services.google_calendar.timezone', 'Asia/Ho_Chi_Minh');
        $dateValue = $request->input(
            'interview_date',
            Carbon::parse($interview->getRawOriginal('interview_date'), $timezone)->toDateString(),
        );
        $parsedDate = Carbon::parse($dateValue, $timezone)->setTimezone($timezone);
        $timeValue = $request->input('interview_time', $interview->interview_time ?: '09:00');
        $scheduledAt = Carbon::parse(
            $parsedDate->toDateString().' '.substr((string) $timeValue, 0, 5),
            $timezone,
        );

        $columns = Schema::getColumnListing('interview_schedules');
        $data = collect($request->all())->only($columns)->toArray();
        if ($request->filled('interview_date')) {
            $data['interview_date'] = $scheduledAt->toDateString();
        }
        if ($request->exists('interview_time') || $request->filled('interview_date')) {
            $data['interview_time'] = $scheduledAt->format('H:i');
        }
        if ($request->filled('status')) {
            $data['status'] = match (strtolower((string) $request->input('status'))) {
                'pending', 'scheduled' => 'SCHEDULED',
                'passed', 'completed' => 'COMPLETED',
                'failed', 'cancelled' => 'CANCELLED',
                default => strtoupper((string) $request->input('status')),
            };
        }
        if ($request->filled('result_note')) {
            $data['result'] = $request->input('result_note');
        }
        $meta = is_array($interview->meta) ? $interview->meta : [];
        foreach (['interviewer', 'location', 'duration_minutes', 'confirmation_deadline'] as $field) {
            if ($request->exists($field)) {
                $meta[$field] = $request->input($field);
            }
        }
        $candidate = RecruitmentCandidate::with('position:id,position_name')->findOrFail($interview->candidate_id);
        $mode = (string) ($data['interview_mode'] ?? $interview->interview_mode ?? 'ONSITE');
        $durationMinutes = (int) ($request->input('duration_minutes')
            ?? $meta['duration_minutes']
            ?? config('recruitment.mail.interview_duration_minutes', 60));

        try {
            $meta = array_merge($meta, $this->resolveMeetingMeta(
                $request,
                $candidate,
                $scheduledAt,
                $mode,
                $durationMinutes,
                $meta,
            ));
        } catch (\Throwable $exception) {
            Log::warning('Could not update interview meeting link', [
                'interview_id' => $interview->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->validationError([
                'meeting_link' => [$exception->getMessage()],
            ]);
        }
        $data['meta'] = array_filter($meta, static fn ($value) => $value !== null && $value !== '');

        $interview->update($data);

        return $this->ok($interview->fresh()->load('candidate:id,full_name'), 'Lịch phỏng vấn đã được cập nhật');
    }

    /**
     * @param  array<string, mixed>  $existingMeta
     * @return array<string, mixed>
     */
    private function resolveMeetingMeta(
        Request $request,
        RecruitmentCandidate $candidate,
        Carbon $scheduledAt,
        string $interviewMode,
        int $durationMinutes,
        array $existingMeta = [],
    ): array {
        $meetService = app(GoogleMeetService::class);
        $meetingLink = $request->exists('meeting_link')
            ? trim((string) $request->input('meeting_link'))
            : trim((string) ($existingMeta['meeting_link'] ?? ''));

        if ($meetingLink === '' && filter_var($request->input('location'), FILTER_VALIDATE_URL)) {
            $meetingLink = trim((string) $request->input('location'));
        }

        $mode = strtoupper($interviewMode);
        $needsOnlineRoom = in_array($mode, ['ONLINE', 'HYBRID'], true);
        if ($meetingLink === '' && $needsOnlineRoom && $request->boolean('auto_create_meeting')) {
            $positionName = $candidate->position?->position_name ?: 'Vị trí tuyển dụng';
            $created = $meetService->createMeeting(
                $scheduledAt,
                $durationMinutes,
                "Phỏng vấn {$candidate->full_name} - {$positionName}",
                "Lịch phỏng vấn ứng viên {$candidate->full_name} cho vị trí {$positionName}.",
                $candidate->email,
            );

            return [
                'meeting_link' => $created['meeting_link'],
                'meeting_provider' => 'GOOGLE_MEET',
                'google_calendar_event_id' => $created['event_id'],
                'google_calendar_event_url' => $created['event_url'],
            ];
        }

        if ($meetingLink !== '' && ! $meetService->isUsableMeetingLink($meetingLink)) {
            throw new \RuntimeException(
                'Link Google Meet phải là link phòng cụ thể, ví dụ https://meet.google.com/abc-defg-hij; không dùng trang chủ Google Meet.'
            );
        }

        if ($needsOnlineRoom && $meetingLink === '') {
            throw new \RuntimeException(
                'Phỏng vấn trực tuyến cần link phòng họp. Hãy nhập link hợp lệ hoặc bật tạo Google Meet tự động.'
            );
        }

        return ['meeting_link' => $meetingLink ?: null];
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

    /** GET /recruitment-ai/feedback-stats — Thống kê feedback AI. */
    public function aiFeedbackStats(): JsonResponse
    {
        $service = new AiFeedbackService;
        $stats = $service->getStats();
        $adjustments = $service->getAdjustments();

        if ($stats === null && $adjustments === null) {
            return $this->ok(['message' => 'Dịch vụ AI không khả dụng'], 'Không thể lấy thống kê feedback AI');
        }

        return $this->ok(['stats' => $stats, 'adjustments' => $adjustments], 'Thống kê feedback AI');
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
