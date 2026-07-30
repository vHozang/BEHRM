# HRM System — Kế hoạch hoàn thành 100% (production cho doanh nghiệp VN)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Đưa HRM từ "code cứng cáp, verify bảo mật/nghiệp vụ" lên "sẵn sàng chào & vận hành cho doanh nghiệp VN thật" — lấp 3 khoảng trống thật: dữ liệu demo trống, nghiệp vụ VN còn thiếu (lương tháng 13), và chưa giao được (email/deploy).

**Architecture:** Laravel 11 (Doan2_v2/Doan2) + Vue 3 (client/) + PostgreSQL đa-tenant. KHÔNG thêm dependency mới; tái dùng seeder/service/engine đã có. Business rule đọc qua `App\Support\HrmConfig` (system_configs override config/hrm.php), không hard-code.

**Tech Stack:** PHP 8 / Laravel 11 / PHPUnit Feature tests · Vue 3 + Vite · PostgreSQL 15 · Docker (hrm_laravel_php, hrm_postgres).

## Global Constraints

- Đa-tenant: mọi bảng có `tenant_id` (+`legal_entity_id`). Seeder PHẢI stamp qua `TenantContext::stamp()` hoặc set tường minh `tenant_id=1, legal_entity_id=1`.
- Boolean Postgres: KHÔNG bind int; dùng `DB::raw('true'/'false')` hoặc set literal. `departments.status` là BOOLEAN.
- Sau khi sửa PHP: `docker restart hrm_laravel_php` (opcache validate_timestamps=0). Sửa `config/hrm.php`: `config:clear && config:cache`. Sửa route: `route:cache`.
- Ký tự Việt qua psql: `docker cp` file UTF-8 no-BOM rồi `psql -f`, HOẶC dùng heredoc `docker exec -i`. KHÔNG `psql -c "tiếng việt"` (mangle).
- Seeder idempotent + có thể chạy lại; đăng ký trong `DatabaseSeeder` để bền qua reseed.
- Verify bằng thực tế: browser (Claude Preview cổng 5050) + curl + psql. Không tuyên bố "xong" khi chưa chạy.
- Demo accounts: an.nguyen@company.com/test1234 (Admin), mai.tran/demo1234 (HR), cuong.le/demo1234 (Manager), huong.pham/demo1234 (NV id=4).

---

## Audit nghiệp vụ VN — ma trận hoàn thiện (đã kiểm chứng bằng thực tế)

Chấm theo nhu cầu HR+Payroll của doanh nghiệp VN thật, xác minh bằng API/DB/browser — không đoán.

| Nghiệp vụ VN cốt lõi | Trạng thái | Bằng chứng |
|---|---|---|
| Bảng lương (thác nước gross→BH→giảm trừ→PIT→net) | ✅ ĐỦ | 620 phiếu, verify số học |
| PIT lũy tiến 5 bậc (Luật 109/2025) | ✅ ĐỦ | engine PayrollTaxService |
| BHXH/BHYT/BHTN (trần + SÀN vùng) | ✅ ĐỦ | InsuranceService |
| Giảm trừ gia cảnh + người phụ thuộc | ✅ ĐỦ | 15.5tr + 6.2tr/người |
| **Báo cáo BHXH (kê khai)** | ✅ ĐỦ | `reports/generate` type=bhxh-declaration trả rows+totals |
| **Quyết toán thuế TNCN cuối năm** | ✅ ĐỦ | type=pit-finalization CHẠY (plan cũ ghi thiếu — SAI) |
| Chấm công + đi muộn/về sớm + máy chấm công | ✅ ĐỦ | 14.720 bản ghi + API device-punch |
| OT theo luật (150/200/300% + đêm +30%) | ✅ ĐỦ | TimePolicy, verify |
| Phép năm + thâm niên + nghỉ luật + nghỉ lễ (âm lịch Tết) | ✅ ĐỦ | LeavePolicyService + VietnameseLunarConverter |
| Hợp đồng + mẫu + ký điện tử OTP | ✅ ĐỦ | ContractController + e-sign |
| Tuyển dụng + phỏng vấn + đánh giá ứng viên | ✅ ĐỦ (ít data) | có bảng, cần seed |
| Onboarding / Offboarding | ✅ ĐỦ | OnboardingService |
| Báo cáo nhân sự (headcount/lương/phép/công) | ✅ ĐỦ | 4 report chạy thật |
| RBAC + đa-tenant + bảo mật | ✅ ĐỦ | 3 vòng zero-trust, 13 lỗ đã vá |
| **Lương tháng 13 / thưởng Tết** | ❌ THIẾU | payroll_adjustments có bảng nhưng engine chưa đọc |
| **Tạm ứng lương** | ❌ THIẾU | không có cơ chế (có thể qua payroll_adjustments âm) |
| Đánh giá hiệu suất/KPI nhân viên | ⏸️ KHÔNG CÓ | YAGNI — phase 2 (nhiều SME VN chưa dùng trong phần mềm) |
| Đào tạo (training) | ⏸️ KHÔNG CÓ | YAGNI — phase 2 |
| Gửi email/OTP thật | ❌ THIẾU | tạo record nhưng chưa gửi |

**Kết luận trung thực:** lõi HR+Payroll+Tuân-thủ-pháp-lý VN đã **~85–90% đủ để chào doanh nghiệp thật**. Khoảng trống PHẢI đóng hẹp: dữ liệu demo, org chart, **thưởng/lương tháng 13**, email. KPI/Đào tạo là **phase 2 — cố tình hoãn (ponytail/YAGNI)**, không build đầu cơ vì nhiều DN VN chưa cần trong phần mềm HR ở giai đoạn này; thêm khi có khách yêu cầu thật.

## Bức tranh lớn — thứ tự ưu tiên

| # | Gói việc | Vì sao | Rủi ro | Ưu tiên |
|---|---|---|---|---|
| P1 | Seed dữ liệu demo 5 module rỗng | Demo trống = nhìn như chưa làm xong → chặn promotion | Thấp (chỉ data) | ▶️ CAO |
| P2 | Fix org chart phân nhánh (bug render FE) | Sơ đồ tổ chức là màn "wow" khi chào hàng | Thấp | ▶️ CAO |
| P3 | Lương tháng 13 / thưởng Tết | Nghiệp vụ VN gần như bắt buộc | Trung (đụng payroll) | 🔶 TRUNG |
| P4 | Email/SMS delivery (quên MK, OTP, thông báo) | Chưa gửi được = không dùng thật được | Trung (cần SMTP) | 🔶 TRUNG |
| P5 | Deploy config (CORS, APP_DEBUG, HTTPS, env) | Không có = không lên VPS được | Thấp | 🔶 khi có VPS |
| P6 | Xóa 1.200 dòng dead code | Sạch để bàn giao/bảo trì | Thấp | 🔵 THẤP |

Mỗi gói tự chứa, test độc lập, có thể dừng ở ranh giới bất kỳ. Làm P1→P2 trước (giá trị cao/rủi ro thấp/thấy ngay), rồi P3–P6.

---

## Task 1: Seeder dữ liệu demo cho 5 module rỗng

**Files:**
- Create: `Doan2_v2/Doan2/database/seeders/DemoCoverageSeeder.php`
- Modify: `Doan2_v2/Doan2/database/seeders/DatabaseSeeder.php` (đăng ký gọi seeder)
- Test: `Doan2_v2/Doan2/tests/Feature/DemoCoverageSeederTest.php`

**Interfaces:**
- Consumes: bảng có sẵn `assets, asset_assignments, interview_schedules, recruitment_candidates, policies, service_tickets, service_categories, shift_swaps, shift_assignments, employees`.
- Produces: mỗi bảng rỗng có ≥ 4 hàng thực tế kiểu VN, gắn đúng `tenant_id=1`, FK trỏ tới employee/candidate/shift có thật.

**Bối cảnh cần đọc trước:** trạng thái rỗng xác nhận bằng `SELECT count(*)`: assets=0, interview_schedules=0, policies=0, service_tickets=0, shift_swaps=0. Ứng viên đã có 6 (`recruitment_candidates`), vị trí tuyển 3 (`recruitment_positions`) → interviews chỉ cần link.

- [ ] **Step 1: Viết test thất bại** — khẳng định sau seed, 5 bảng đều có hàng.

```php
// tests/Feature/DemoCoverageSeederTest.php
public function test_seeds_all_empty_modules(): void
{
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\DemoCoverageSeeder'])->assertExitCode(0);
    foreach (['assets','interview_schedules','policies','service_tickets','shift_swaps'] as $t) {
        $this->assertGreaterThan(0, DB::table($t)->count(), "$t phải có dữ liệu demo");
    }
    // Idempotent: chạy lần 2 không nhân đôi
    $before = DB::table('assets')->count();
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\DemoCoverageSeeder']);
    $this->assertSame($before, DB::table('assets')->count(), 'seeder phải idempotent');
}
```

- [ ] **Step 2: Chạy test — PHẢI fail** vì seeder chưa tồn tại.

Run: `docker exec hrm_laravel_php php artisan test --filter=DemoCoverageSeederTest`
Expected: FAIL "Class DemoCoverageSeeder not found".

- [ ] **Step 3: Viết seeder** — mỗi khối idempotent (`updateOrInsert` hoặc kiểm tra `count()` trước khi chèn), stamp tenant. Nội dung VN thật:
  - **assets** (4): "Laptop Dell Latitude 5540", "Màn hình LG 24''", "Điện thoại iPhone 13", "Xe máy Honda Wave" — mã tài sản TS-0001.., trạng thái AVAILABLE/ASSIGNED, nguyên giá.
  - **asset_assignments** (2): gán 2 tài sản cho 2 employee có thật (bàn giao ngày, tình trạng "Tốt").
  - **interview_schedules** (4): link 4 `recruitment_candidates` có sẵn + 1 hội đồng (employee), thời gian tương lai, địa điểm/Google Meet, trạng thái SCHEDULED/COMPLETED, điểm đánh giá.
  - **policies** (4): "Nội quy lao động", "Chính sách nghỉ phép", "Quy định chấm công", "Chính sách bảo mật thông tin" — nội dung ngắn thật.
  - **service_categories** (3) + **service_tickets** (5): loại "IT/Nhân sự/Cơ sở vật chất"; ticket "Cấp lại thẻ nhân viên", "Sửa máy tính", "Xin xác nhận lương" — requester = employee thật, trạng thái OPEN/IN_PROGRESS/RESOLVED.
  - **shift_swaps** (3): requester + target là 2 employee có `shift_assignments`, ngày đổi, trạng thái PENDING/APPROVED, `approval_status`.

  Mẫu một khối (lặp cho các bảng):
```php
// ponytail: seeder demo — idempotent theo mã, stamp tenant tường minh.
$assets = [
    ['asset_code'=>'TS-0001','asset_name'=>'Laptop Dell Latitude 5540','status'=>'ASSIGNED','purchase_cost'=>25000000],
    ['asset_code'=>'TS-0002','asset_name'=>'Màn hình LG 24 inch','status'=>'AVAILABLE','purchase_cost'=>3200000],
    // ... 2 nữa
];
foreach ($assets as $a) {
    DB::table('assets')->updateOrInsert(
        ['asset_code'=>$a['asset_code'], 'tenant_id'=>1],
        array_merge($a, ['tenant_id'=>1,'legal_entity_id'=>1,'updated_at'=>now(),'created_at'=>now()])
    );
}
```
  **Trước khi viết:** chạy `docker exec hrm_postgres psql -U hrm -d hrm -c "\d assets"` (và 4 bảng kia) để lấy ĐÚNG tên cột — không đoán schema.

- [ ] **Step 4: Chạy test — PHẢI pass.**

Run: `docker exec hrm_laravel_php php artisan test --filter=DemoCoverageSeederTest`
Expected: PASS.

- [ ] **Step 5: Đăng ký trong DatabaseSeeder** (bền qua reseed) — thêm `$this->call(DemoCoverageSeeder::class);` sau `LeaveTypeStatutorySeeder`.

- [ ] **Step 6: Chạy seeder trên DB live + verify browser.**

Run: `docker exec hrm_laravel_php php artisan db:seed --class=DemoCoverageSeeder --force`
Rồi mở /assets, /interviews, /policies, /service-tickets, /shift-swaps (đăng nhập Admin) — mỗi trang phải hiện danh sách, 0 lỗi console.

- [ ] **Step 7: Commit.**

```bash
git add Doan2_v2/Doan2/database/seeders/DemoCoverageSeeder.php Doan2_v2/Doan2/database/seeders/DatabaseSeeder.php Doan2_v2/Doan2/tests/Feature/DemoCoverageSeederTest.php
git commit -m "feat(demo): seed 5 module rong (assets/interviews/policies/tickets/swaps) du lieu VN"
```

---

## Task 2: Fix sơ đồ tổ chức không phân nhánh

**Files:**
- Modify: `client/src/views/OrganizationChart.vue` (hoặc `client/src/components/OrgTreeNode.vue`) — chỗ build cây
- Investigate: `Doan2_v2/Doan2/app/Repositories/OrganizationChartRepository.php` (nguồn dữ liệu)

**Interfaces:**
- Consumes: API `/employees/org-chart` (nested tree) hoặc `/organization/chart`; `employees.manager_id` (đã seed 20/21).
- Produces: cây phân cấp đúng — nhân viên lồng dưới quản lý của họ; không còn nhiều node gốc phẳng.

**Bối cảnh (đã kiểm chứng):** `SELECT count(*) FROM employees WHERE manager_id IS NOT NULL` = **20** → dữ liệu reporting-line CÓ. Vậy "không phân nhánh" là **bug FE/mode**, không phải thiếu data. Nghi ngờ: (a) chart đang ở mode "Phòng ban" gom theo `department.meta.parent_id` nhưng render phẳng, hoặc (b) FE build tree so khớp `manager_id` sai kiểu (số vs chuỗi), hoặc (c) parent trỏ tới id không nằm trong tập trả về.

- [ ] **Step 1: Chẩn đoán bằng dữ liệu thật.** Đăng nhập Admin, tại /organization-chart chạy trong console:
```js
const t=localStorage.getItem('auth_token');
const r=await fetch('http://localhost/api/v1/employees/org-chart',{headers:{Authorization:'Bearer '+t}});
const j=await r.json();
console.log(JSON.stringify(j.data).slice(0,800));
```
Xác định: API trả nested (có `children`) hay phẳng? Nếu phẳng → bug ở repository. Nếu nested nhưng FE render phẳng → bug ở component.

- [ ] **Step 2: Viết test khẳng định cây phân nhánh.** Nếu bug ở BE (repository), viết Feature test:
```php
// tests/Feature/OrgChartTest.php
public function test_org_chart_nests_reports_under_manager(): void
{
    $tree = (new \App\Repositories\OrganizationChartRepository)->getNestedTree(null);
    $flat = $tree->count();                 // số node gốc
    $this->assertLessThan(DB::table('employees')->count(), $flat, 'không được phẳng: gốc phải ít hơn tổng NV');
    // ít nhất 1 node gốc có children
    $this->assertTrue($tree->contains(fn($n) => !empty($n['children'] ?? $n->children ?? [])));
}
```
Nếu bug ở FE, bỏ test PHP, verify bằng browser ở Step 4.

- [ ] **Step 3: Sửa root cause.** Tùy chẩn đoán:
  - **Kiểu so khớp:** ép `Number(manager_id) === Number(id)` khi build map (tránh '5' !== 5).
  - **Mode Phòng ban:** map `department.meta.parent_id` (đã normalize ở seeder) đúng khi group.
  - **Repository:** đảm bảo `getNestedTree` lồng theo `manager_id` trong cùng tenant.
  Sửa ĐÚNG chỗ chẩn đoán chỉ ra, không vá đại.

- [ ] **Step 4: Verify browser.** Mở /organization-chart → screenshot phải thấy cây lồng nhau (An → cấp dưới), không phải các thẻ gốc rời rạc. Kiểm 0 lỗi console.

- [ ] **Step 5: Commit.**

```bash
git add client/src/views/OrganizationChart.vue
git commit -m "fix(orgchart): so do to chuc phan nhanh theo manager_id (data da co, loi render)"
```

---

## Task 3: Lương tháng 13 / thưởng (nghiệp vụ VN)

**Files:**
- Modify: `Doan2_v2/Doan2/app/Services/PayrollRunService.php` (cộng thưởng vào gross/taxable)
- Modify: `Doan2_v2/Doan2/config/hrm.php` + `SettingsController::CATALOG` (tham số bật/tắt + hệ số)
- Reuse: bảng `payroll_adjustments` (đã có) cho khoản thưởng ad-hoc theo kỳ
- Test: `Doan2_v2/Doan2/tests/Feature/ThirteenthMonthTest.php`

**Interfaces:**
- Consumes: `payroll_adjustments` (employee_id, period_id, amount, type='BONUS'|'THANG_13', taxable bool), `HrmConfig::get('payroll.*')`.
- Produces: phiếu lương kỳ có dòng thưởng; gross + taxable_income cộng thưởng; PIT tính trên thu nhập gồm thưởng (thưởng chịu thuế TNCN theo luật VN).

**Bối cảnh (đã kiểm chứng):** hiện KHÔNG có cơ chế thưởng lương — chỉ `seniority_bonus` cho ngày phép. VN: lương tháng 13 + thưởng Tết là chuẩn. Thưởng CHỊU thuế TNCN (khác phụ trội OT có phần miễn). Bảng `payroll_adjustments` đã tồn tại nhưng payroll engine chưa đọc để cộng.

- [ ] **Step 1: Xác nhận schema + engine hiện tại.** `\d payroll_adjustments`; đọc `PayrollRunService::run()` chỗ tính gross (đã biết: prorated_base + allowance + OT + piece-rate). Xác định điểm chèn "adjustments".

- [ ] **Step 2: Viết test thất bại** — thưởng 5tr vào kỳ → gross + taxable tăng 5tr, PIT tăng tương ứng.
```php
public function test_bonus_adjustment_increases_gross_and_taxable(): void
{
    // seed 1 employee + contract + period OPEN, chạy payroll baseline
    // thêm payroll_adjustments amount=5_000_000 type=BONUS taxable=true
    // chạy lại payroll → salary_details.meta gross tăng 5tr; taxable_income tăng 5tr
    $this->assertEqualsWithDelta($baseGross + 5_000_000, $newGross, 1);
    $this->assertGreaterThan($basePit, $newPit); // thưởng chịu thuế
}
```

- [ ] **Step 3: Chạy test — PHẢI fail** (engine chưa cộng adjustments).

- [ ] **Step 4: Implement** — trong `PayrollRunService`, sau khi tính OT/piece-rate, đọc `payroll_adjustments` của (employee, period), cộng khoản `type IN ('BONUS','THANG_13')` vào gross; khoản `taxable=true` cộng vào taxable_income (mặc định thưởng chịu thuế). Ghi breakdown dòng `EARNING/BONUS`. Thêm helper `payroll.thirteenth_month_enabled` + `SettingsController::CATALOG` để bật tính năng nhập thưởng ở FE (nếu làm UI).

- [ ] **Step 5: Chạy test — PHẢI pass.**

- [ ] **Step 6: Verify số học trên DB thật** — thêm 1 adjustment cho NV có phiếu lương kỳ OPEN, chạy `payroll/run`, mở phiếu lương (Salaries.vue) thấy dòng "Thưởng" + NET đúng.

- [ ] **Step 7: Commit.**

```bash
git commit -am "feat(payroll): thuong/luong thang 13 cong vao gross+taxable (payroll_adjustments), chiu thue TNCN"
```

> ponytail: dùng `payroll_adjustments` sẵn có thay vì bảng mới. UI nhập thưởng là tùy chọn — nếu chưa cần, nhập qua API/seed. **Quyết toán thuế TNCN cuối năm ĐÃ CÓ** (`reports/generate` type=pit-finalization, đã kiểm chạy thật) — không cần làm lại. **Tạm ứng lương**: nếu khách cần, tái dùng `payroll_adjustments` với khoản âm type='ADVANCE' (trừ vào net), cùng cơ chế Task 3 — thêm khi có yêu cầu thật, đừng build đầu cơ.

---

## Task 4: Giao tin thật — Email/OTP/Thông báo

**Files:**
- Modify: `Doan2_v2/Doan2/app/Http/Controllers/Api/AuthController.php` (forgotPassword gửi mail thay vì chỉ tạo token)
- Modify: `Doan2_v2/Doan2/app/Http/Controllers/Api/ContractController.php` (requestOtp gửi OTP qua mail)
- Modify: `Doan2_v2/Doan2/app/Support/Notifier.php` (tùy chọn gửi mail khi notify)
- Config: `Doan2_v2/Doan2/.env` (MAIL_* — dùng log driver khi dev, SMTP khi prod)

**Interfaces:**
- Consumes: Laravel `Mail`/`Notification` (core, không thêm dep); `password_reset_requests`, contract meta `sign_otp`.
- Produces: khi `MAIL_MAILER` cấu hình, các luồng gửi mail thật; khi chưa, ghi log (không vỡ).

- [ ] **Step 1: Cấu hình mail driver dev = log.** Trong `.env`: `MAIL_MAILER=log`. Xác nhận `php artisan tinker --execute="Mail::raw('test', fn(\$m)=>\$m->to('x@y.z')->subject('t'));"` ghi vào `storage/logs`.

- [ ] **Step 2: Viết test** — forgotPassword gửi 1 mail (dùng `Mail::fake()` + `assertSent`).
```php
public function test_forgot_password_sends_reset_email(): void
{
    Mail::fake();
    $this->postJson('/api/v1/auth/forgot-password', ['company_email'=>'huong.pham@company.com'])->assertOk();
    Mail::assertSent(fn($mail) => true); // gửi đúng 1 mail tới địa chỉ đó
}
```

- [ ] **Step 3: Fail** (hiện chỉ tạo token, không gửi).

- [ ] **Step 4: Implement** — sau khi tạo token reset, `Mail::raw("Link đặt lại: .../reset?token=$token", ...)`. Tương tự OTP ký HĐ: gửi mã qua mail thay vì chỉ lưu (đã ẩn khỏi response từ trước). Notifier: nếu `notifications.email_enabled`, gửi mail kèm in-app.

- [ ] **Step 5: Pass.**

- [ ] **Step 6: Verify** — gọi forgot-password, kiểm `storage/logs/laravel.log` có nội dung mail + link token đúng.

- [ ] **Step 7: Commit.**

```bash
git commit -am "feat(mail): gui email reset password + OTP ky HD + thong bao (driver log dev, SMTP prod)"
```

---

## Task 5: Deploy config (làm khi có VPS/domain)

**Files:**
- Modify: `Doan2_v2/Doan2/config/cors.php` (allowed_origins = domain Vercel)
- Modify: `Doan2_v2/Doan2/.env.production` (APP_ENV=production, APP_DEBUG=false, DB/redis, MAIL SMTP, JWT_TTL)
- Doc: `docs/DEPLOY.md` (các bước VPS + Vercel + biến môi trường)

**Interfaces:** Consumes domain/VPS của user (đầu vào bên ngoài). Produces app chạy HTTPS, FE Vercel gọi được API.

- [ ] **Step 1:** Viết `docs/DEPLOY.md`: (a) VPS: docker-compose up, nginx + certbot HTTPS, set `.env` production; (b) Vercel: `VITE_API_BASE_URL=https://api.domain`, build; (c) CORS allow origin Vercel; (d) checklist: APP_DEBUG=false, `config:cache`, `route:cache`, chạy migration + seeder.
- [ ] **Step 2:** Sửa `config/cors.php` đọc `allowed_origins` từ env `CORS_ALLOWED_ORIGINS`.
- [ ] **Step 3:** Verify local: đặt `APP_DEBUG=false`, xác nhận lỗi trả JSON gọn (không stack trace) — nối tiếp global handler đã có.
- [ ] **Step 4:** Commit.

```bash
git commit -am "chore(deploy): CORS theo env + .env.production + docs/DEPLOY.md"
```

> Gói này CHỜ user cung cấp VPS/domain — không tự chạy được đến khi có hạ tầng.

---

## Task 6: Xóa dead code (ponytail-audit)

**Files (xóa):**
- `Doan2_v2/Doan2/app/Http/Controllers/Api/AttendanceAsyncController.php`, `app/Services/AttendanceService.php`, `app/Repositories/AttendanceRepository.php`, `app/Repositories/Contracts/AttendanceRepositoryContract.php`, `app/Jobs/ProcessAttendanceLog.php`, `app/DTOs/CheckInData.php`, `app/Http/Requests/Attendance/CheckInRequest.php`, `app/Providers/RepositoryServiceProvider.php`, `app/Console/Commands/CreateAttendancePartitionCommand.php`, `app/Models/AttendanceLog.php` (~814 dòng)
- `client/src/stores/{employeeStore,departmentStore,attendanceStore,leaveStore}.js` (~384 dòng)

**Interfaces:** không có — toàn code không được route/import nào tham chiếu (đã xác minh: grep 0 caller ngoài chính cụm).

- [ ] **Step 1:** Xác nhận lại 0 tham chiếu: `grep -r "AttendanceAsyncController\|ProcessAttendanceLog\|useEmployeeStore\|useLeaveStore" --include=*.php --include=*.vue --include=*.js` (loại chính các file đó + README + config array). Nếu có caller bất ngờ → dừng, xử lý trước.
- [ ] **Step 2:** Gỡ đăng ký `RepositoryServiceProvider` khỏi `bootstrap/providers.php` (nếu có). Xóa các file.
- [ ] **Step 3:** Verify hệ thống nguyên vẹn: `docker exec hrm_laravel_php php artisan route:list >/dev/null` (không lỗi), check-in vẫn 200 (`AttendanceController::checkIn`), FE build `vite build --config vite.preview.config.mjs` exit 0.
- [ ] **Step 4:** Commit.

```bash
git commit -am "refactor: xoa tang async attendance chet + 4 pinia store khong dung (-1200 dong)"
```

---

## Self-Review — đối chiếu với khoảng trống đã audit

- ✅ Module rỗng (assets/interviews/policies/tickets/swaps) → Task 1
- ✅ Org chart phẳng → Task 2 (đã ghi rõ: data có, lỗi render)
- ✅ Thiếu thưởng/lương tháng 13 VN → Task 3
- ✅ Không gửi được email/OTP → Task 4
- ✅ Deploy (CORS/HTTPS/APP_DEBUG) → Task 5
- ✅ Dead code 1.200 dòng → Task 6
- Đã CÓ sẵn (không cần làm): quyết toán thuế TNCN cuối năm + kê khai BHXH (2 report chạy thật), máy chấm công (API device-punch).
- Hoãn có chủ đích (YAGNI, phase 2): đánh giá KPI/hiệu suất, đào tạo, tạm ứng lương (tái dùng payroll_adjustments khi cần), tích hợp ngân hàng thật.

**Thứ tự đề xuất chạy:** Task 1 → 2 (giá trị cao, rủi ro thấp, thấy ngay khi demo) → 3 → 4 → 6 → 5 (khi có VPS).
