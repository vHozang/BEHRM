<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service: Quản lý đồng bộ bài đăng tuyển dụng giữa PostgreSQL ↔ Redis.
 *
 * Kiến trúc:
 * ┌──────────────┐     ┌───────────┐     ┌──────────────┐
 * │  HR Admin    │────▶│  Laravel  │────▶│  PostgreSQL  │
 * │  (CRUD)      │     │           │────▶│  Redis Cache │
 * └──────────────┘     └───────────┘     └──────────────┘
 *                            │
 *                     ┌──────▼──────┐
 *                     │ Public API  │◀── Candidate (Landing Page)
 *                     │ (Redis GET) │
 *                     └─────────────┘
 *
 * Write Path: Create/Update/Delete → PostgreSQL + sync Redis
 * Read Path:  Redis GET → fast JSON (no DB hit) → fallback to DB on miss
 */
class RecruitmentPostService
{
    /** Cache key prefix cho từng bài đăng (theo slug). */
    private const POST_CACHE_PREFIX = 'recruitment_post:';

    /** Cache key cho danh sách tất cả bài đăng đã publish. */
    private const LISTING_CACHE_KEY = 'recruitment_posts:listing';

    /** TTL cho cache bài đăng riêng lẻ (24 giờ). */
    private const POST_TTL_SECONDS = 86400;

    /** TTL cho cache danh sách listing (1 giờ). */
    private const LISTING_TTL_SECONDS = 3600;

    // ──────────────────────────────────────────────────────────────
    //  WRITE PATH: Đồng bộ Redis sau mỗi thao tác ghi PostgreSQL
    // ──────────────────────────────────────────────────────────────

    /**
     * Đồng bộ một bài đăng vào Redis sau khi tạo/cập nhật.
     *
     * Chỉ cache các bài có status = PUBLISHED. Các trạng thái khác
     * (DRAFT, CLOSED, ARCHIVED) sẽ bị xóa khỏi Redis.
     */
    public function syncToRedis(object $post): void
    {
        try {
            if ($post->status === 'PUBLISHED') {
                $payload = $this->buildPublicPayload($post);
                Cache::store('redis')->put(
                    self::POST_CACHE_PREFIX . $post->slug,
                    json_encode($payload, JSON_UNESCAPED_UNICODE),
                    self::POST_TTL_SECONDS
                );
            } else {
                $this->removeFromRedis($post->slug);
            }

            // Luôn invalidate listing cache khi có bất kỳ thay đổi nào
            $this->invalidateListingCache();
        } catch (\Throwable $e) {
            Log::error("RecruitmentPostService::syncToRedis failed: " . $e->getMessage());
        }
    }

    /**
     * Xóa một bài đăng khỏi Redis (khi delete hoặc chuyển trạng thái).
     */
    public function removeFromRedis(string $slug): void
    {
        try {
            Cache::store('redis')->forget(self::POST_CACHE_PREFIX . $slug);
            $this->invalidateListingCache();
        } catch (\Throwable $e) {
            Log::error("RecruitmentPostService::removeFromRedis failed: " . $e->getMessage());
        }
    }

    /**
     * Invalidate listing cache → lần đọc tiếp theo sẽ rebuild từ DB.
     */
    private function invalidateListingCache(): void
    {
        Cache::store('redis')->forget(self::LISTING_CACHE_KEY);
    }

    // ──────────────────────────────────────────────────────────────
    //  READ PATH: Đọc từ Redis trước, fallback sang PostgreSQL
    // ──────────────────────────────────────────────────────────────

    /**
     * Lấy một bài đăng theo slug (public landing page).
     *
     * Ưu tiên đọc từ Redis cache. Nếu cache miss thì query PostgreSQL
     * và tự động re-populate vào Redis cho lần đọc tiếp theo.
     *
     * @return array|null  Mảng dữ liệu bài đăng hoặc null nếu không tìm thấy / chưa publish.
     */
    public function getPublicPost(string $slug): ?array
    {
        // Bước 1: Thử đọc từ Redis
        $cached = Cache::store('redis')->get(self::POST_CACHE_PREFIX . $slug);

        if ($cached !== null) {
            return json_decode($cached, true);
        }

        // Bước 2: Cache miss → query PostgreSQL
        $post = DB::table('recruitment_posts')
            ->where('slug', $slug)
            ->where('status', 'PUBLISHED')
            ->first();

        if (!$post) {
            return null;
        }

        // Bước 3: Re-populate Redis
        $payload = $this->buildPublicPayload($post);
        Cache::store('redis')->put(
            self::POST_CACHE_PREFIX . $slug,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            self::POST_TTL_SECONDS
        );

        return $payload;
    }

    /**
     * Lấy danh sách tất cả bài đăng đã publish (public listing).
     *
     * Cached trong Redis với TTL 1 giờ. Khi HR thực hiện CRUD
     * thì listing cache bị invalidate (xem syncToRedis/removeFromRedis).
     *
     * @return array  Mảng các bài đăng (thông tin tóm tắt).
     */
    public function getPublicListing(): array
    {
        $cached = Cache::store('redis')->get(self::LISTING_CACHE_KEY);

        if ($cached !== null) {
            return json_decode($cached, true);
        }

        // Cache miss → query PostgreSQL
        $posts = DB::table('recruitment_posts')
            ->leftJoin('recruitment_positions', 'recruitment_posts.recruitment_position_id', '=', 'recruitment_positions.id')
            ->where('recruitment_posts.status', 'PUBLISHED')
            ->orderByDesc('recruitment_posts.published_at')
            ->select([
                'recruitment_posts.id',
                'recruitment_posts.slug',
                'recruitment_posts.title',
                'recruitment_posts.summary',
                'recruitment_posts.location',
                'recruitment_posts.salary_range',
                'recruitment_posts.employment_type',
                'recruitment_posts.deadline',
                'recruitment_posts.published_at',
                'recruitment_positions.position_name',
            ])
            ->get()
            ->map(function ($post) {
                return [
                    'id' => $post->id,
                    'slug' => $post->slug,
                    'title' => $post->title,
                    'summary' => $post->summary,
                    'location' => $post->location,
                    'salary_range' => $post->salary_range,
                    'employment_type' => $post->employment_type,
                    'deadline' => $post->deadline,
                    'published_at' => $post->published_at,
                    'position_name' => $post->position_name,
                ];
            })
            ->toArray();

        // Populate cache
        Cache::store('redis')->put(
            self::LISTING_CACHE_KEY,
            json_encode($posts, JSON_UNESCAPED_UNICODE),
            self::LISTING_TTL_SECONDS
        );

        return $posts;
    }

    // ──────────────────────────────────────────────────────────────
    //  HELPERS
    // ──────────────────────────────────────────────────────────────

    /**
     * Xây dựng payload public từ một row bài đăng.
     *
     * Join thêm thông tin position nếu có recruitment_position_id.
     */
    private function buildPublicPayload(object $post): array
    {
        $position = null;
        if ($post->recruitment_position_id) {
            $position = DB::table('recruitment_positions')
                ->where('id', $post->recruitment_position_id)
                ->select(['id', 'position_name', 'department_id', 'employment_type', 'required_skills_json'])
                ->first();
        }

        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->title,
            'summary' => $post->summary,
            'content' => $post->content,
            'benefits' => json_decode($post->benefits ?? '[]', true),
            'requirements' => json_decode($post->requirements ?? '[]', true),
            'location' => $post->location,
            'salary_range' => $post->salary_range,
            'employment_type' => $post->employment_type,
            'deadline' => $post->deadline,
            'published_at' => $post->published_at,
            'position' => $position ? [
                'id' => $position->id,
                'name' => $position->position_name,
                'department_id' => $position->department_id,
                'required_skills' => json_decode($position->required_skills_json ?? '[]', true),
            ] : null,
        ];
    }
}
