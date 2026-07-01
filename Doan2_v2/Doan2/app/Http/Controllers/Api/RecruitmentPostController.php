<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RecruitmentPostService;
use App\Support\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Controller: CRUD bài đăng tuyển dụng (Recruitment Posts).
 *
 * HR Admin tạo/sửa/xóa bài đăng → dữ liệu ghi vào PostgreSQL
 * và đồng bộ tự động lên Redis cache cho landing page công khai.
 *
 * Public candidate đọc landing page qua Redis (publicShow / publicListing).
 */
class RecruitmentPostController extends Controller
{
    private RecruitmentPostService $postService;

    public function __construct()
    {
        $this->postService = new RecruitmentPostService();
    }

    // ══════════════════════════════════════════════════════════════
    //  PUBLIC ENDPOINTS (không cần auth — dành cho ứng viên)
    // ══════════════════════════════════════════════════════════════

    /**
     * GET /public/recruitment-posts
     * Danh sách bài đăng đã publish (landing page listing).
     */
    public function publicListing(): JsonResponse
    {
        $posts = $this->postService->getPublicListing();

        return $this->ok($posts, 'Danh sách tin tuyển dụng');
    }

    /**
     * GET /public/recruitment-posts/{slug}
     * Chi tiết một bài đăng theo slug (landing page chi tiết).
     */
    public function publicShow(string $slug): JsonResponse
    {
        $post = $this->postService->getPublicPost($slug);

        if (!$post) {
            return $this->notFound('Bài đăng tuyển dụng không tồn tại hoặc chưa được công bố');
        }

        return $this->ok($post, 'Chi tiết tin tuyển dụng');
    }

    // ══════════════════════════════════════════════════════════════
    //  ADMIN CRUD ENDPOINTS (cần auth — dành cho HR)
    // ══════════════════════════════════════════════════════════════

    /**
     * GET /recruitment-posts
     * Danh sách bài đăng (phân trang, lọc theo status/position).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = DB::table('recruitment_posts')
            ->leftJoin('recruitment_positions', 'recruitment_posts.recruitment_position_id', '=', 'recruitment_positions.id')
            ->select([
                'recruitment_posts.*',
                'recruitment_positions.position_name',
            ])
            ->orderByDesc('recruitment_posts.id');

        // Tenant scoping
        if (TenantContext::hasTenant() && Schema::hasColumn('recruitment_posts', 'tenant_id')) {
            $query->where('recruitment_posts.tenant_id', TenantContext::id());
        }

        // Filters
        foreach (['status', 'employment_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where("recruitment_posts.{$filter}", $request->query($filter));
            }
        }

        if ($request->filled('recruitment_position_id')) {
            $query->where('recruitment_posts.recruitment_position_id', $request->query('recruitment_position_id'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('recruitment_posts.title', 'ilike', "%{$search}%")
                    ->orWhere('recruitment_posts.summary', 'ilike', "%{$search}%");
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
        ], 'Danh sách bài đăng tuyển dụng');
    }

    /**
     * POST /recruitment-posts
     * Tạo mới bài đăng tuyển dụng.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:500',
            'slug' => 'nullable|string|max:255|unique:recruitment_posts,slug',
            'recruitment_position_id' => 'nullable|exists:recruitment_positions,id',
            'summary' => 'nullable|string|max:2000',
            'content' => 'nullable|string',
            'benefits' => 'nullable|array',
            'requirements' => 'nullable|array',
            'location' => 'nullable|string|max:255',
            'salary_range' => 'nullable|string|max:255',
            'employment_type' => 'nullable|string|max:100',
            'deadline' => 'nullable|date',
            'status' => 'nullable|string|in:DRAFT,PUBLISHED,CLOSED,ARCHIVED',
        ], [
            'title.required' => 'Tiêu đề bài đăng là bắt buộc',
            'slug.unique' => 'Slug đã tồn tại, vui lòng chọn slug khác',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $request->only([
            'title', 'slug', 'recruitment_position_id', 'summary', 'content',
            'benefits', 'requirements', 'location', 'salary_range',
            'employment_type', 'deadline', 'status', 'meta',
        ]);

        // Auto-generate slug nếu không cung cấp
        $data['slug'] = $data['slug'] ?? Str::slug($data['title']) . '-' . Str::random(6);
        $data['status'] = $data['status'] ?? 'DRAFT';
        $data['created_by'] = $request->attributes->get('auth_employee_id');
        $data['created_at'] = now();
        $data['updated_at'] = now();

        // Encode JSON fields
        if (isset($data['benefits']) && is_array($data['benefits'])) {
            $data['benefits'] = json_encode($data['benefits'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($data['requirements']) && is_array($data['requirements'])) {
            $data['requirements'] = json_encode($data['requirements'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($data['meta']) && is_array($data['meta'])) {
            $data['meta'] = json_encode($data['meta'], JSON_UNESCAPED_UNICODE);
        }

        // Nếu status = PUBLISHED → set published_at
        if ($data['status'] === 'PUBLISHED' && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        // Tenant stamping
        $data = TenantContext::stamp($data);

        $newId = DB::table('recruitment_posts')->insertGetId($data);
        $post = DB::table('recruitment_posts')->where('id', $newId)->first();

        // Sync to Redis
        $this->postService->syncToRedis($post);

        AuditLogger::log('create', 'recruitment_posts', $newId, null, (array) $post);

        return response()->json([
            'status' => 201,
            'message' => 'Bài đăng tuyển dụng đã được tạo',
            'data' => $post,
        ], 201);
    }

    /**
     * GET /recruitment-posts/{id}
     * Xem chi tiết bài đăng (admin view — bao gồm cả DRAFT).
     */
    public function show(int $id): JsonResponse
    {
        $query = DB::table('recruitment_posts')
            ->leftJoin('recruitment_positions', 'recruitment_posts.recruitment_position_id', '=', 'recruitment_positions.id')
            ->select([
                'recruitment_posts.*',
                'recruitment_positions.position_name',
                'recruitment_positions.required_skills_json',
            ])
            ->where('recruitment_posts.id', $id);

        if (TenantContext::hasTenant()) {
            $query->where('recruitment_posts.tenant_id', TenantContext::id());
        }

        $post = $query->first();

        if (!$post) {
            return $this->notFound('Bài đăng tuyển dụng không tồn tại');
        }

        return $this->ok($post, 'Chi tiết bài đăng tuyển dụng');
    }

    /**
     * PUT|PATCH /recruitment-posts/{id}
     * Cập nhật bài đăng tuyển dụng.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $existing = DB::table('recruitment_posts')->where('id', $id);

        if (TenantContext::hasTenant()) {
            $existing->where('tenant_id', TenantContext::id());
        }

        $existingPost = $existing->first();

        if (!$existingPost) {
            return $this->notFound('Bài đăng tuyển dụng không tồn tại');
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:500',
            'slug' => "sometimes|string|max:255|unique:recruitment_posts,slug,{$id}",
            'recruitment_position_id' => 'nullable|exists:recruitment_positions,id',
            'summary' => 'nullable|string|max:2000',
            'content' => 'nullable|string',
            'benefits' => 'nullable|array',
            'requirements' => 'nullable|array',
            'location' => 'nullable|string|max:255',
            'salary_range' => 'nullable|string|max:255',
            'employment_type' => 'nullable|string|max:100',
            'deadline' => 'nullable|date',
            'status' => 'nullable|string|in:DRAFT,PUBLISHED,CLOSED,ARCHIVED',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $columns = Schema::getColumnListing('recruitment_posts');
        $data = collect($request->all())->only($columns)->except(['id', 'tenant_id', 'created_at'])->toArray();
        $data['updated_at'] = now();

        // Encode JSON fields
        if (isset($data['benefits']) && is_array($data['benefits'])) {
            $data['benefits'] = json_encode($data['benefits'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($data['requirements']) && is_array($data['requirements'])) {
            $data['requirements'] = json_encode($data['requirements'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($data['meta']) && is_array($data['meta'])) {
            $data['meta'] = json_encode($data['meta'], JSON_UNESCAPED_UNICODE);
        }

        // Set published_at khi chuyển sang PUBLISHED lần đầu
        if (isset($data['status']) && $data['status'] === 'PUBLISHED' && !$existingPost->published_at) {
            $data['published_at'] = now();
        }

        $oldSlug = $existingPost->slug;

        $beforeRow = (array) $existingPost;
        DB::table('recruitment_posts')->where('id', $id)->update($data);
        $updatedPost = DB::table('recruitment_posts')->where('id', $id)->first();

        // Nếu slug thay đổi → xóa cache cũ
        if (isset($data['slug']) && $data['slug'] !== $oldSlug) {
            $this->postService->removeFromRedis($oldSlug);
        }

        // Sync to Redis
        $this->postService->syncToRedis($updatedPost);

        AuditLogger::log('update', 'recruitment_posts', $id, $beforeRow, (array) $updatedPost);

        return $this->ok($updatedPost, 'Bài đăng tuyển dụng đã được cập nhật');
    }

    /**
     * DELETE /recruitment-posts/{id}
     * Xóa bài đăng tuyển dụng.
     */
    public function destroy(int $id): JsonResponse
    {
        $query = DB::table('recruitment_posts')->where('id', $id);

        if (TenantContext::hasTenant()) {
            $query->where('tenant_id', TenantContext::id());
        }

        $post = $query->first();

        if (!$post) {
            return $this->notFound('Bài đăng tuyển dụng không tồn tại');
        }

        DB::table('recruitment_posts')->where('id', $id)->delete();

        // Remove from Redis
        $this->postService->removeFromRedis($post->slug);

        AuditLogger::log('delete', 'recruitment_posts', $id, (array) $post, null);

        return $this->ok(['id' => $id], 'Bài đăng tuyển dụng đã được xóa');
    }

    // ══════════════════════════════════════════════════════════════
    //  WORKFLOW ACTIONS
    // ══════════════════════════════════════════════════════════════

    /**
     * POST /recruitment-posts/{id}/publish
     * Công bố bài đăng → chuyển status sang PUBLISHED → sync Redis.
     */
    public function publish(int $id): JsonResponse
    {
        $query = DB::table('recruitment_posts')->where('id', $id);

        if (TenantContext::hasTenant()) {
            $query->where('tenant_id', TenantContext::id());
        }

        $post = $query->first();

        if (!$post) {
            return $this->notFound('Bài đăng tuyển dụng không tồn tại');
        }

        if ($post->status === 'PUBLISHED') {
            return $this->ok($post, 'Bài đăng đã ở trạng thái công bố');
        }

        $now = now();
        DB::table('recruitment_posts')->where('id', $id)->update([
            'status' => 'PUBLISHED',
            'published_at' => $post->published_at ?? $now,
            'updated_at' => $now,
        ]);

        $updatedPost = DB::table('recruitment_posts')->where('id', $id)->first();
        $this->postService->syncToRedis($updatedPost);

        AuditLogger::log('update', 'recruitment_posts', $id, (array) $post, (array) $updatedPost);

        return $this->ok($updatedPost, 'Bài đăng tuyển dụng đã được công bố');
    }

    /**
     * POST /recruitment-posts/{id}/close
     * Đóng bài đăng → chuyển status sang CLOSED → xóa khỏi Redis public.
     */
    public function close(int $id): JsonResponse
    {
        $query = DB::table('recruitment_posts')->where('id', $id);

        if (TenantContext::hasTenant()) {
            $query->where('tenant_id', TenantContext::id());
        }

        $post = $query->first();

        if (!$post) {
            return $this->notFound('Bài đăng tuyển dụng không tồn tại');
        }

        if ($post->status === 'CLOSED') {
            return $this->ok($post, 'Bài đăng đã ở trạng thái đóng');
        }

        $now = now();
        DB::table('recruitment_posts')->where('id', $id)->update([
            'status' => 'CLOSED',
            'updated_at' => $now,
        ]);

        $updatedPost = DB::table('recruitment_posts')->where('id', $id)->first();
        $this->postService->syncToRedis($updatedPost); // sẽ xóa khỏi Redis vì status != PUBLISHED

        AuditLogger::log('update', 'recruitment_posts', $id, (array) $post, (array) $updatedPost);

        return $this->ok($updatedPost, 'Bài đăng tuyển dụng đã được đóng');
    }

    // ══════════════════════════════════════════════════════════════
    //  RESPONSE HELPERS
    // ══════════════════════════════════════════════════════════════

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
        return response()->json([
            'status' => 422,
            'message' => 'Dữ liệu không hợp lệ',
            'data' => ['errors' => $errors],
        ], 422);
    }
}
