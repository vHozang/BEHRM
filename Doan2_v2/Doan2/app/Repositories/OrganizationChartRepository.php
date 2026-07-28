<?php

namespace App\Repositories;

use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Repository: Truy vấn cây sơ đồ tổ chức bằng WITH RECURSIVE (CTE).
 *
 * Vấn đề N+1 khi không dùng CTE:
 *  - Lấy nhân viên → tìm manager → tìm manager của manager → ...
 *  - Với cây sâu 5 cấp và 5000 nhân viên: hàng nghìn query!
 *
 * Giải pháp: WITH RECURSIVE (Common Table Expression đệ quy)
 *  - Chỉ cần 1 query DUY NHẤT để lấy toàn bộ cây bất kể độ sâu.
 *  - PostgreSQL tối ưu hóa nội bộ, sử dụng hash join và work_mem hiệu quả.
 *  - Thời gian thực thi: ~5-20ms cho 5000 nhân viên.
 */
class OrganizationChartRepository
{
    /**
     * Lấy toàn bộ cây sơ đồ tổ chức, bắt đầu từ một nhân viên cụ thể.
     * Trả về tất cả cấp dưới (subordinates) theo dạng phẳng (flat list).
     *
     * Ví dụ kết quả:
     * [
     *   {id: 1, full_name: "CEO", manager_id: null, depth: 0, path: "1"},
     *   {id: 2, full_name: "Head of Dev", manager_id: 1, depth: 1, path: "1.2"},
     *   {id: 5, full_name: "Senior Dev", manager_id: 2, depth: 2, path: "1.2.5"},
     * ]
     *
     * @param  int|null  $rootEmployeeId  null = lấy từ gốc (CEO, không có manager)
     * @param  int  $maxDepth  Giới hạn độ sâu để tránh infinite loop nếu có cycle
     */
    public function getTree(
        ?int $rootEmployeeId = null,
        int $maxDepth = 10
    ): Collection {
        // The production query uses PostgreSQL arrays/operators. Feature tests
        // use SQLite in-memory, so keep the same behavior with a small in-PHP
        // traversal rather than making the endpoint database-specific.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $this->getTreePortable($rootEmployeeId, $maxDepth);
        }

        // ── WITH RECURSIVE: Giải thích cú pháp ─────────────────────────────
        //
        // Cấu trúc CTE đệ quy gồm 2 phần nối bởi UNION ALL:
        //
        // 1. ANCHOR (phần khởi đầu): Lấy node gốc (root node)
        //    - Nếu $rootEmployeeId null → lấy nhân viên không có manager (CEO level)
        //    - Nếu có $rootEmployeeId → lấy đúng nhân viên đó
        //
        // 2. RECURSIVE (phần đệ quy): Join CTE với chính nó
        //    - Mỗi vòng lặp: lấy nhân viên mà manager_id = id của vòng trước
        //    - Dừng khi không còn kết quả (no more children)
        //
        // depth: đếm cấp bậc (0 = CEO, 1 = Director, 2 = Manager, ...)
        // path:  chuỗi ID từ gốc đến nút hiện tại, dùng để sort theo thứ tự cây
        //        Ví dụ: "1.5.12.34" → có thể ORDER BY path để ra đúng thứ tự

        $anchorCondition = $rootEmployeeId
            ? "e.id = {$rootEmployeeId}"
            : 'e.manager_id IS NULL';

        // Tenant isolation: confine the whole recursion to the current tenant.
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;
        $tenantAnchor = $tenantId !== null ? " AND e.tenant_id = {$tenantId}" : '';
        $tenantRecursive = $tenantId !== null ? " AND e.tenant_id = {$tenantId}" : '';

        $sql = "
            WITH RECURSIVE org_tree AS (

                -- ── ANCHOR MEMBER: Node gốc ─────────────────────────────
                SELECT
                    e.id,
                    e.employee_code,
                    e.full_name,
                    e.manager_id,
                    e.status,
                    e.department_id,
                    e.position_id,
                    d.department_name,
                    p.position_name,
                    0 AS depth,
                    ARRAY[e.id] AS id_path,          -- Mảng ID từ gốc đến nút hiện tại
                    e.id::TEXT AS path_string          -- Chuỗi để ORDER BY và display
                FROM
                    employees e
                    LEFT JOIN departments d ON d.id = e.department_id
                    LEFT JOIN positions   p ON p.id = e.position_id
                WHERE
                    {$anchorCondition}
                    AND e.status != 'TERMINATED'
                    {$tenantAnchor}

                UNION ALL

                -- ── RECURSIVE MEMBER: Đệ quy xuống các tầng con ─────────
                SELECT
                    e.id,
                    e.employee_code,
                    e.full_name,
                    e.manager_id,
                    e.status,
                    e.department_id,
                    e.position_id,
                    d.department_name,
                    p.position_name,
                    ot.depth + 1,                      -- Tăng cấp độ mỗi vòng
                    ot.id_path || e.id,                -- Thêm id của nhân viên vào path array
                    ot.path_string || '.' || e.id::TEXT
                FROM
                    employees e
                    INNER JOIN org_tree ot ON ot.id = e.manager_id  -- Join: manager = node trên
                    LEFT JOIN departments d ON d.id = e.department_id
                    LEFT JOIN positions   p ON p.id = e.position_id
                WHERE
                    e.status != 'TERMINATED'
                    AND ot.depth < :maxDepth           -- Giới hạn độ sâu, tránh infinite loop
                    AND NOT (e.id = ANY(ot.id_path))  -- Phát hiện cycle: nếu ID đã có trong path → dừng
                    {$tenantRecursive}
            )

            -- ── Final SELECT: Lấy kết quả từ CTE ────────────────────────
            SELECT
                id,
                employee_code,
                full_name,
                manager_id,
                status,
                department_id,
                department_name,
                position_id,
                position_name,
                depth,
                path_string
            FROM
                org_tree
            ORDER BY
                path_string  -- Sort theo path → ra đúng thứ tự cây (parent trước children)
        ";

        $results = DB::select($sql, ['maxDepth' => $maxDepth]);

        return collect($results);
    }

    private function getTreePortable(?int $rootEmployeeId, int $maxDepth): Collection
    {
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;
        $rows = DB::table('employees as e')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->leftJoin('positions as p', 'p.id', '=', 'e.position_id')
            ->where('e.status', '!=', 'TERMINATED')
            ->when($tenantId !== null, fn ($q) => $q->where('e.tenant_id', $tenantId))
            ->get([
                'e.id', 'e.employee_code', 'e.full_name', 'e.manager_id', 'e.status',
                'e.department_id', 'e.position_id', 'd.department_name', 'p.position_name',
            ]);

        $byManager = $rows->groupBy(fn ($row) => $row->manager_id === null ? 'root' : (string) $row->manager_id);
        $roots = $rootEmployeeId !== null
            ? $rows->filter(fn ($row) => (int) $row->id === $rootEmployeeId)
            : ($byManager->get('root') ?? collect());
        $result = collect();

        $visit = function ($row, int $depth, array $path) use (&$visit, &$result, $byManager, $maxDepth): void {
            $id = (int) $row->id;
            if (in_array($id, $path, true) || $depth > $maxDepth) {
                return;
            }

            $nextPath = [...$path, $id];
            $result->push((object) array_merge((array) $row, [
                'depth' => $depth,
                'path_string' => implode('.', $nextPath),
            ]));

            foreach ($byManager->get((string) $id, collect()) as $child) {
                $visit($child, $depth + 1, $nextPath);
            }
        };

        foreach ($roots as $root) {
            $visit($root, 0, []);
        }

        return $result;
    }

    /**
     * Lấy cây tổ chức dạng nested (tree structure) thay vì flat list.
     * Phù hợp để render UI org chart, export JSON.
     *
     * Thuật toán: Build nested tree từ flat list với O(n) time complexity.
     */
    public function getNestedTree(?int $rootEmployeeId = null): array
    {
        $flatList = $this->getTree($rootEmployeeId);

        // Sơ đồ tổ chức = cây QUẢN LÝ + văn phòng. KHÔNG nhồi công nhân sản xuất
        // (số lượng lớn làm rối, mất ý nghĩa quản trị). Loại các chức danh trong
        // org_chart.exclude_positions (mặc định 'CN' = Công nhân) — chỉ ở bản VẼ này;
        // getTree (phạm vi duyệt đơn, kiểm vòng lặp) vẫn giữ đủ mọi người.
        $excludeCodes = (array) \App\Support\HrmConfig::get('org_chart.exclude_positions', ['CN']);
        if (! empty($excludeCodes)) {
            $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;
            $excludedIds = DB::table('positions')
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereIn('position_code', $excludeCodes)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (! empty($excludedIds)) {
                $flatList = $flatList
                    ->reject(fn ($e) => in_array((int) $e->position_id, $excludedIds, true))
                    ->values();
            }
        }

        // Kiêm nhiệm (matrix / dotted-line): với mỗi nhân viên trong cây, lấy các
        // phòng ban PHỤ từ pivot employee_departments. Chỉ 1 query bulk (tránh N+1),
        // group theo employee_id rồi đính vào từng node để FE vẽ đường nét đứt.
        $empIds = $flatList->pluck('id')->all();
        $secondaryByEmp = [];
        if (! empty($empIds) && \Illuminate\Support\Facades\Schema::hasTable('employee_departments')) {
            $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;
            $pivotQuery = DB::table('employee_departments as ed')
                ->join('departments as d', 'd.id', '=', 'ed.department_id')
                ->whereIn('ed.employee_id', $empIds)
                ->select('ed.employee_id', 'ed.department_id', 'ed.role_in_dept', 'd.department_name');
            if ($tenantId !== null) {
                $pivotQuery->where('ed.tenant_id', $tenantId);
            }
            foreach ($pivotQuery->get() as $row) {
                $secondaryByEmp[$row->employee_id][] = [
                    'department_id' => (int) $row->department_id,
                    'department_name' => $row->department_name,
                    'role_in_dept' => $row->role_in_dept,
                ];
            }
        }

        // Index theo ID để lookup O(1)
        $nodes = $flatList->keyBy('id')->map(fn ($item) => [
            ...(array) $item,
            'secondary_departments' => $secondaryByEmp[$item->id] ?? [],
            'children' => [],
        ])->all();

        $roots = [];

        foreach ($nodes as $id => &$node) {
            $managerId = $node['manager_id'];

            if ($managerId && isset($nodes[$managerId])) {
                // Thêm vào danh sách con của manager
                $nodes[$managerId]['children'][] = &$node;
            } else {
                // Không có manager → là root node
                $roots[] = &$node;
            }
        }

        return $roots;
    }

    /**
     * Lấy danh sách TẤT CẢ cấp trên (ancestors) của một nhân viên.
     * Đi ngược từ nhân viên lên CEO: Employee → Manager → Director → CEO
     *
     * Dùng: "Ai có quyền duyệt yêu cầu của nhân viên này?"
     */
    public function getAncestors(int $employeeId): Collection
    {
        // Tenant isolation: keep the manager-chain walk inside the current tenant.
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;
        $tenantAnchor = $tenantId !== null ? " AND e.tenant_id = {$tenantId}" : '';
        // Wrap the existing OR-condition so the tenant filter ANDs against the whole thing.
        $tenantRecursive = $tenantId !== null ? " AND e.tenant_id = {$tenantId}" : '';

        $sql = "
            WITH RECURSIVE ancestors AS (

                -- ANCHOR: Bắt đầu từ nhân viên
                SELECT e.id, e.full_name, e.manager_id, e.employee_code, 0 AS level
                FROM employees e
                WHERE e.id = :employeeId{$tenantAnchor}

                UNION ALL

                -- RECURSIVE: Đi lên từng cấp manager
                SELECT e.id, e.full_name, e.manager_id, e.employee_code, a.level + 1
                FROM employees e
                INNER JOIN ancestors a ON a.manager_id = e.id  -- Join ngược: tìm manager
                WHERE (e.manager_id IS NOT NULL OR e.id != a.id){$tenantRecursive}  -- Dừng khi đến CEO
            )

            SELECT id, full_name, employee_code, manager_id, level
            FROM ancestors
            WHERE id != :employeeId  -- Loại bỏ chính nhân viên
            ORDER BY level ASC       -- Sắp xếp: manager gần nhất trước
        ";

        return collect(DB::select($sql, ['employeeId' => $employeeId]));
    }
}
