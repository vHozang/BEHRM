# MULTITENANT_MIGRATION_PLAN.md — Single-Org → Multi-Tenant SaaS

Derived from `D:\HRM-System-2\MT_schema_inventory.md` (schema introspection) and `D:\HRM-System-2\MT_tenant_classification.md` (per-table classification). READ-ONLY analysis only — no DDL/DML has been executed against the live `hrm` database. This document is the implementation blueprint; nothing here runs automatically.

App reference files inspected (live backend `Doan2_laravel_docker_20260525_002205`):
- `app/Http/Middleware/HrmAuth.php` — token→employee auth, no tenant resolution today.
- `app/Http/Controllers/Api/GenericResourceController.php` — generic CRUD over raw `DB::table()`, **no tenant filtering anywhere**.
- `app/Support/HrmTables.php` — resource→table whitelist (66 resources) that drives the generic controller.

---

## 1. OVERVIEW

### Chosen isolation model
**Shared database + shared schema + row-level `tenant_id`** for v1. Every business/transactional/config table gets a `tenant_id bigint NOT NULL` column, indexed, FK → `tenants(id)`. Isolation is enforced in the application layer by:
- a **tenant-resolution middleware** that establishes the active tenant per request, and
- a **global query scope / query macro** that auto-injects `WHERE tenant_id = :current_tenant` on every read and auto-stamps `tenant_id` on every write.

This is deliberately abstracted behind a `TenantContext` service and a `BelongsToTenant` concern so a large/regulated tenant can later be **promoted to schema-per-tenant or database-per-tenant** without rewriting business logic (only the connection/scope resolution layer changes).

### Data hierarchy
```
tenant (account / SaaS customer)
   └── legal_entity (pháp nhân / chi nhánh — employer of record)
          └── department
                 └── employee
```
- **tenant_id** is carried by *every* business table (defense-in-depth, even on junction/child tables).
- **legal_entity_id** is added only to the 30 tables that are tied to a specific legal entity for **payroll, tax/insurance registration, labor reporting, or physical org structure** (employees, contracts, departments, all salary_*, attendances, attendance_logs + 13 partitions, shift_*, assets, asset_locations, insurance_claims, social_insurance_info, payroll_adjustments).

### High-level approach
Expand → migrate → contract:
1. Create `tenants` + `legal_entities`.
2. Add `tenant_id` / `legal_entity_id` as **NULLABLE** columns everywhere (cheap, non-blocking).
3. Backfill all existing rows to default tenant `id=1` and default legal_entity `id=1` (existing demo data is conceptually one org).
4. Tighten to `NOT NULL`, add indexes, add FKs, convert risky global uniques to composite `(tenant_id, …)`.
5. Ship the app-layer changes (middleware + global scope + auto-stamping + tenant-aware login) behind the same release.

### Counts (from stages 1–2)
- Total base tables in DB `hrm`: **112** (98 logical + 1 partitioned parent + 13 partition children).
- Logical business tables receiving a `tenant_id` ALTER: **88** (these resolve to **101 classification rows** once the 13 inheriting `attendance_logs` partition children are counted — the children get their columns by cascade from the parent ALTER, not by an individual ALTER).
- Of those, rows also receiving `legal_entity_id` (entity_scoped_count): **30** (17 logical TENANT+ENTITY tables + attendance_logs parent + 13 partition children).
- TENANT (tenant_id only): **71** logical tables.
- GLOBAL untouched: **11** (`banks`, `nationalities` GLOBAL reference, + 9 PLATFORM-GLOBAL infra/auth: `users`, `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`, `migrations`, `password_reset_tokens`, `sessions`).
- Composite-unique changes flagged: **38** risky/natural-key uniques (5 existing global uniques + 33 app-enforced codes + the 1:1 candidate_cvs constraint = 39 statements across §4.1–§4.3; plus ~85 optional `legacy_id` composites, non-blocking).

---

## 2. NEW TABLES

### 2.1 `tenants` (the SaaS account / root of the hierarchy)

**Laravel migration**
```php
Schema::create('tenants', function (Blueprint $t) {
    $t->id();
    $t->string('code')->unique();              // stable slug used for subdomain / header routing
    $t->string('name');
    $t->string('status')->default('active');   // active | suspended | trial | closed
    $t->string('subdomain')->nullable()->unique();
    $t->string('locale', 10)->default('vi');
    $t->string('currency', 3)->default('VND');
    $t->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
    $t->string('plan')->nullable();            // billing plan tier
    $t->timestamp('trial_ends_at')->nullable();
    $t->jsonb('meta')->nullable();
    $t->timestampsTz();
});
```

**Raw SQL**
```sql
CREATE TABLE tenants (
    id           bigserial PRIMARY KEY,
    code         varchar(255) NOT NULL,
    name         varchar(255) NOT NULL,
    status       varchar(255) NOT NULL DEFAULT 'active',
    subdomain    varchar(255),
    locale       varchar(10)  NOT NULL DEFAULT 'vi',
    currency     varchar(3)   NOT NULL DEFAULT 'VND',
    timezone     varchar(64)  NOT NULL DEFAULT 'Asia/Ho_Chi_Minh',
    plan         varchar(255),
    trial_ends_at timestamptz,
    meta         jsonb,
    created_at   timestamptz,
    updated_at   timestamptz
);
CREATE UNIQUE INDEX tenants_code_unique      ON tenants (code);
CREATE UNIQUE INDEX tenants_subdomain_unique ON tenants (subdomain) WHERE subdomain IS NOT NULL;
```

### 2.2 `legal_entities` (pháp nhân / chi nhánh — payroll/tax/labor unit)

**Laravel migration**
```php
Schema::create('legal_entities', function (Blueprint $t) {
    $t->id();
    $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
    $t->string('code');                         // unique WITHIN a tenant
    $t->string('name');
    $t->string('tax_code')->nullable();         // mã số thuế
    $t->string('social_insurance_code')->nullable();
    $t->string('registration_number')->nullable();
    $t->string('address')->nullable();
    $t->string('legal_representative')->nullable();
    $t->string('status')->default('active');
    $t->jsonb('meta')->nullable();
    $t->timestampsTz();
    $t->unique(['tenant_id', 'code']);
});
```

**Raw SQL**
```sql
CREATE TABLE legal_entities (
    id                    bigserial PRIMARY KEY,
    tenant_id             bigint NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    code                  varchar(255) NOT NULL,
    name                  varchar(255) NOT NULL,
    tax_code              varchar(255),
    social_insurance_code varchar(255),
    registration_number   varchar(255),
    address               varchar(255),
    legal_representative   varchar(255),
    status                varchar(255) NOT NULL DEFAULT 'active',
    meta                  jsonb,
    created_at            timestamptz,
    updated_at            timestamptz
);
CREATE UNIQUE INDEX legal_entities_tenant_code_unique ON legal_entities (tenant_id, code);
CREATE INDEX legal_entities_tenant_id_index ON legal_entities (tenant_id);
```

---

## 3. SCHEMA CHANGES (grouped by classification)

> Pattern applied to **all 88 logical business tables** (101 classification rows including the 13 inheriting `attendance_logs` partition children): add `tenant_id bigint` (nullable first), backfill, then `SET NOT NULL` + index + FK. The 30 TENANT+ENTITY rows (17 logical tables + attendance_logs parent + 13 partition children) additionally get `legal_entity_id bigint` (kept NULLABLE — see Risks §9). Adding a nullable column in PostgreSQL with no default is metadata-only (no table rewrite, no long lock), which is why columns are added nullable in the expand phase.

### 3.1 TENANT-only tables (71 logical tables — `tenant_id` only)
Full list: `allowances, api_tokens, approval_flows, approval_histories, approval_roles, approval_steps, asset_assignments, asset_categories, asset_incidents, asset_maintenance, certificate_types, certificates, contract_change_logs, contract_histories, contract_templates, contract_types, dashboard_views, deductions, dependents, document_types, employee_allowances, employee_deductions, employee_roles, employment_histories, holidays, identity_documents, insurance_types, interview_schedules, job_families, leave_advancement_config, leave_advancement_requests, leave_balances, leave_carryover_tracking, leave_requests, leave_transactions, leave_types, news, news_categories, news_reads, notification_configs, notifications, overtime_requests, password_reset_requests, permissions, policies, policy_acknowledgments, positions, qualification_types, qualifications, recruitment_ai_scoring_jobs, recruitment_candidate_cvs, recruitment_candidate_manager_reviews, recruitment_candidates, recruitment_positions, recruitment_rejected_archive, report_histories, report_templates, request_attachments, request_types, requests, role_permissions, roles, salary_components, seniority_leave_history, service_categories, service_ticket_updates, service_tickets, shift_swaps, shift_types, suppliers, system_configs`.

**Laravel migration pseudocode (run once per table, generated in a loop):**
```php
$tenantOnly = ['allowances','api_tokens','approval_flows', /* …full list above… */ 'system_configs'];
foreach ($tenantOnly as $table) {
    Schema::table($table, function (Blueprint $t) {
        $t->unsignedBigInteger('tenant_id')->nullable()->after('id'); // nullable in expand phase
    });
}
// … BACKFILL (section 6) …
foreach ($tenantOnly as $table) {
    Schema::table($table, function (Blueprint $t) {
        $t->unsignedBigInteger('tenant_id')->nullable(false)->change();
        $t->index('tenant_id', "{$table}_tenant_id_index");
        $t->foreign('tenant_id', "{$table}_tenant_id_fk")
          ->references('id')->on('tenants')->restrictOnDelete();
    });
}
```

**Raw SQL (per table — example with `leave_types`; repeat for each):**
```sql
-- expand
ALTER TABLE leave_types ADD COLUMN tenant_id bigint;
-- (backfill: section 6)
-- contract
ALTER TABLE leave_types ALTER COLUMN tenant_id SET NOT NULL;
CREATE INDEX leave_types_tenant_id_index ON leave_types (tenant_id);
ALTER TABLE leave_types
    ADD CONSTRAINT leave_types_tenant_id_fk
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT;
```

### 3.2 TENANT+ENTITY tables (17 logical + attendance_logs parent + 13 partition children = 30 — `tenant_id` AND `legal_entity_id`)
List: `employees, departments, contracts, attendances, attendance_logs (+ 13 partition children), salary_periods, salary_details, salary_breakdowns, salary_attendance_summary, payroll_adjustments, insurance_claims, social_insurance_info, shift_schedules, shift_schedule_details, shift_assignments, assets, asset_locations`.

Each gets `tenant_id` (NOT NULL, as §3.1) **plus** `legal_entity_id` (FK → legal_entities, kept NULLABLE per Risks §9 so pooled/central data and assets are not forced into one branch).

**Laravel migration pseudocode:**
```php
$entityScoped = ['employees','departments','contracts','attendances','salary_periods',
    'salary_details','salary_breakdowns','salary_attendance_summary','payroll_adjustments',
    'insurance_claims','social_insurance_info','shift_schedules','shift_schedule_details',
    'shift_assignments','assets','asset_locations'];
foreach ($entityScoped as $table) {
    Schema::table($table, function (Blueprint $t) {
        $t->unsignedBigInteger('tenant_id')->nullable()->after('id');
        $t->unsignedBigInteger('legal_entity_id')->nullable()->after('tenant_id');
    });
}
// … BACKFILL (section 6) …
foreach ($entityScoped as $table) {
    Schema::table($table, function (Blueprint $t) {
        $t->unsignedBigInteger('tenant_id')->nullable(false)->change();
        $t->index('tenant_id', "{$table}_tenant_id_index");
        $t->index(['tenant_id','legal_entity_id'], "{$table}_tenant_entity_index");
        $t->foreign('tenant_id', "{$table}_tenant_id_fk")
          ->references('id')->on('tenants')->restrictOnDelete();
        $t->foreign('legal_entity_id', "{$table}_legal_entity_id_fk")
          ->references('id')->on('legal_entities')->restrictOnDelete();
    });
}
```

**Raw SQL (example with `employees`):**
```sql
-- expand
ALTER TABLE employees ADD COLUMN tenant_id       bigint;
ALTER TABLE employees ADD COLUMN legal_entity_id bigint;
-- (backfill: section 6)
-- contract
ALTER TABLE employees ALTER COLUMN tenant_id SET NOT NULL;
CREATE INDEX employees_tenant_id_index    ON employees (tenant_id);
CREATE INDEX employees_tenant_entity_index ON employees (tenant_id, legal_entity_id);
ALTER TABLE employees ADD CONSTRAINT employees_tenant_id_fk
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT;
ALTER TABLE employees ADD CONSTRAINT employees_legal_entity_id_fk
    FOREIGN KEY (legal_entity_id) REFERENCES legal_entities(id) ON DELETE RESTRICT;
```

**attendance_logs (partitioned parent) — special handling:** Add `tenant_id`/`legal_entity_id` **on the parent only** with `ALTER TABLE attendance_logs ADD COLUMN …`; PostgreSQL automatically propagates the column to all 13 children (`attendance_logs_2026_01 … _12`, `_default`). Do **not** ALTER the children individually. Because the partition key is `(id, checked_at)`, the new columns do not affect the PK. Indexes on a partitioned table should be created on the parent (`CREATE INDEX … ON attendance_logs (tenant_id, checked_at)`) so they cascade to partitions; FKs from a partitioned table to `tenants`/`legal_entities` are supported in PG 11+.

```sql
ALTER TABLE attendance_logs ADD COLUMN tenant_id       bigint;
ALTER TABLE attendance_logs ADD COLUMN legal_entity_id bigint;
-- backfill all partitions in one UPDATE on the parent (section 6)
ALTER TABLE attendance_logs ALTER COLUMN tenant_id SET NOT NULL;
CREATE INDEX attendance_logs_tenant_id_index ON attendance_logs (tenant_id);
ALTER TABLE attendance_logs ADD CONSTRAINT attendance_logs_tenant_id_fk
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT;
```

### 3.3 GLOBAL / PLATFORM-GLOBAL (11 tables — NO CHANGE)
`banks`, `nationalities` (universal reference) and `users`, `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`, `migrations`, `password_reset_tokens`, `sessions` (infra/auth) are left untouched. If `users` is later repurposed for tenant end-user login, reclassify to TENANT with composite `(tenant_id, email)` — see Risks §9.

---

## 4. UNIQUE CONSTRAINT CHANGES

Every UNIQUE on a business/natural key must become **composite `(tenant_id, <col>)`**, otherwise the first tenant to use a code blocks all others. Drop-then-recreate, executed in the **contract phase** (after `tenant_id` is populated + NOT NULL).

### 4.1 Existing declared global uniques that MUST become composite (5 blocking)
```sql
-- employees
ALTER TABLE employees DROP CONSTRAINT employees_employee_code_unique;
ALTER TABLE employees ADD  CONSTRAINT employees_tenant_employee_code_unique UNIQUE (tenant_id, employee_code);
ALTER TABLE employees DROP CONSTRAINT employees_company_email_unique;
ALTER TABLE employees ADD  CONSTRAINT employees_tenant_company_email_unique UNIQUE (tenant_id, company_email);
-- job_families
ALTER TABLE job_families DROP CONSTRAINT job_families_code_unique;
ALTER TABLE job_families ADD  CONSTRAINT job_families_tenant_code_unique UNIQUE (tenant_id, code);
-- salary_components
ALTER TABLE salary_components DROP CONSTRAINT salary_components_code_unique;
ALTER TABLE salary_components ADD  CONSTRAINT salary_components_tenant_code_unique UNIQUE (tenant_id, code);
```
`positions.job_family_id → job_families.id` is a declared FK and must keep pointing at the PK `id` (unchanged); only the natural-key uniqueness on `code` changes. No FK rewrite needed.

### 4.2 Natural-key columns NOT YET unique that SHOULD get composite uniques (33)
These were app-enforced only; add composite uniques to prevent intra-tenant collisions. Example shape (repeat per row):
```sql
ALTER TABLE departments    ADD CONSTRAINT departments_tenant_code_unique        UNIQUE (tenant_id, department_code);
ALTER TABLE positions      ADD CONSTRAINT positions_tenant_code_unique          UNIQUE (tenant_id, position_code);
ALTER TABLE contracts      ADD CONSTRAINT contracts_tenant_number_unique        UNIQUE (tenant_id, contract_number);
ALTER TABLE leave_types    ADD CONSTRAINT leave_types_tenant_code_unique        UNIQUE (tenant_id, leave_type_code);
ALTER TABLE roles          ADD CONSTRAINT roles_tenant_code_unique              UNIQUE (tenant_id, role_code);
ALTER TABLE permissions    ADD CONSTRAINT permissions_tenant_code_unique        UNIQUE (tenant_id, permission_code);
ALTER TABLE request_types  ADD CONSTRAINT request_types_tenant_code_unique      UNIQUE (tenant_id, request_type_code);
ALTER TABLE news_categories ADD CONSTRAINT news_categories_tenant_code_unique   UNIQUE (tenant_id, category_code);
ALTER TABLE service_categories ADD CONSTRAINT service_categories_tenant_code_unique UNIQUE (tenant_id, category_code);
ALTER TABLE service_tickets ADD CONSTRAINT service_tickets_tenant_code_unique   UNIQUE (tenant_id, ticket_code);
ALTER TABLE shift_types    ADD CONSTRAINT shift_types_tenant_code_unique        UNIQUE (tenant_id, shift_code);
ALTER TABLE shift_schedules ADD CONSTRAINT shift_schedules_tenant_code_unique   UNIQUE (tenant_id, schedule_code);
ALTER TABLE salary_periods ADD CONSTRAINT salary_periods_tenant_code_unique     UNIQUE (tenant_id, period_code);
ALTER TABLE policies       ADD CONSTRAINT policies_tenant_code_unique           UNIQUE (tenant_id, policy_code);
ALTER TABLE report_templates ADD CONSTRAINT report_templates_tenant_code_unique UNIQUE (tenant_id, template_code);
ALTER TABLE holidays       ADD CONSTRAINT holidays_tenant_code_unique           UNIQUE (tenant_id, holiday_code);
ALTER TABLE allowances     ADD CONSTRAINT allowances_tenant_code_unique         UNIQUE (tenant_id, allowance_code);
ALTER TABLE deductions     ADD CONSTRAINT deductions_tenant_code_unique         UNIQUE (tenant_id, deduction_code);
ALTER TABLE asset_categories ADD CONSTRAINT asset_categories_tenant_code_unique UNIQUE (tenant_id, category_code);
ALTER TABLE assets         ADD CONSTRAINT assets_tenant_code_unique             UNIQUE (tenant_id, asset_code);
ALTER TABLE asset_locations ADD CONSTRAINT asset_locations_tenant_code_unique   UNIQUE (tenant_id, location_code);
ALTER TABLE suppliers      ADD CONSTRAINT suppliers_tenant_code_unique          UNIQUE (tenant_id, supplier_code);
ALTER TABLE insurance_types ADD CONSTRAINT insurance_types_tenant_code_unique   UNIQUE (tenant_id, insurance_type_code);
ALTER TABLE insurance_claims ADD CONSTRAINT insurance_claims_tenant_code_unique UNIQUE (tenant_id, claim_code);
ALTER TABLE contract_types ADD CONSTRAINT contract_types_tenant_code_unique     UNIQUE (tenant_id, contract_type_code);
ALTER TABLE contract_templates ADD CONSTRAINT contract_templates_tenant_code_unique UNIQUE (tenant_id, template_code);
ALTER TABLE certificate_types ADD CONSTRAINT certificate_types_tenant_code_unique UNIQUE (tenant_id, certificate_type_code);
ALTER TABLE document_types ADD CONSTRAINT document_types_tenant_code_unique     UNIQUE (tenant_id, document_type_code);
ALTER TABLE qualification_types ADD CONSTRAINT qualification_types_tenant_code_unique UNIQUE (tenant_id, qualification_type_code);
ALTER TABLE approval_roles ADD CONSTRAINT approval_roles_tenant_code_unique     UNIQUE (tenant_id, role_code);
ALTER TABLE system_configs ADD CONSTRAINT system_configs_tenant_key_unique      UNIQUE (tenant_id, config_key);
ALTER TABLE social_insurance_info ADD CONSTRAINT social_insurance_info_tenant_sin_unique UNIQUE (tenant_id, social_insurance_number);
ALTER TABLE social_insurance_info ADD CONSTRAINT social_insurance_info_tenant_tax_unique UNIQUE (tenant_id, tax_code);
```
> Pre-flight before each `ADD … UNIQUE`: run a `SELECT tenant_id, <col>, count(*) … GROUP BY … HAVING count(*) > 1` to confirm no intra-tenant dupes already exist (the legacy import enforced these in app code only).

### 4.3 `recruitment_candidate_cvs.candidate_id` (1:1)
```sql
ALTER TABLE recruitment_candidate_cvs DROP CONSTRAINT recruitment_candidate_cvs_candidate_id_unique;
ALTER TABLE recruitment_candidate_cvs ADD  CONSTRAINT recruitment_candidate_cvs_tenant_candidate_unique UNIQUE (tenant_id, candidate_id);
```

### 4.4 Uniques that intentionally STAY GLOBAL (do NOT change)
- `api_tokens.token_hash` — lookup is by token alone in `HrmAuth` (no tenant context at auth boundary); add `tenant_id` column for row isolation but keep the hash globally unique.
- `password_reset_requests.token` — random reset token, looked up without tenant context.
- `failed_jobs.uuid`, `users.email` (while `users` stays platform-global) — unchanged.

### 4.5 `legacy_id` uniques (~85, OPTIONAL / non-blocking)
Recommend converting each `<table>_legacy_id_unique` to `(tenant_id, legacy_id)` for cleanliness, but not required: `legacy_id` is NULL for all new rows and PostgreSQL permits multiple NULLs under a UNIQUE. Defer to a follow-up batch.

---

## 5. MIGRATION ORDER (topologically safe)

The schema has only **2 real DB FKs** (`employees.manager_id→employees.id`, `positions.job_family_id→job_families.id`); all other relationships are app-level `*_id` columns. The ordering risk is therefore almost entirely about the **new** `tenant_id`/`legal_entity_id` FKs we are introducing, not the inferred graph. Safe order:

1. **Create parents first:** `tenants`, then `legal_entities` (FK → tenants). Without these, no `tenant_id`/`legal_entity_id` FK can be created.
2. **Seed defaults:** insert `tenants(id=1)` and `legal_entities(id=1, tenant_id=1)` (§6) — these rows must exist before any FK is enabled or any backfill points at them.
3. **Add columns NULLABLE on all 88 logical tables** (parent `attendance_logs` is ALTERed once and cascades the new columns to its 13 children). Nullable add = metadata-only, no rewrite, no FK yet → cannot violate anything.
4. **Backfill** `tenant_id = 1` / `legal_entity_id = 1` on every existing row (§6). Order across business tables is irrelevant here because every column is set to the same constant `1`; the FK targets already exist from step 2.
5. **Tighten:** `SET NOT NULL` on `tenant_id`, create indexes, then `ADD … FOREIGN KEY`. Adding the FK after backfill guarantees every row already satisfies it, so the validating scan passes. `legal_entity_id` FK is added the same way but the column stays nullable.
6. **Convert uniques** to composite (§4) last — after `tenant_id` is non-null and populated, so the new composite index has no NULL `tenant_id` partial-uniqueness surprises.

**Why this avoids FK violations:** Each new FK is only created in step 5, *after* (a) its parent row `id=1` exists (step 2) and (b) every child row's `tenant_id`/`legal_entity_id` has been set to `1` (step 4). PostgreSQL validates the constraint against existing data at `ADD CONSTRAINT` time; since all values are `1` and `tenants(1)`/`legal_entities(1)` exist, validation cannot fail. Adding columns nullable up front (step 3) decouples the cheap DDL from the expensive validation, and keeps the expand phase lock-light.

---

## 6. BACKFILL PLAN

Run inside one transaction per logical group. All existing demo/seeded data is conceptually one org → default tenant `id=1`, default legal_entity `id=1`.

```sql
BEGIN;

-- 1. Default tenant
INSERT INTO tenants (id, code, name, status, locale, currency, timezone, created_at, updated_at)
VALUES (1, 'default', 'Default Organization', 'active', 'vi', 'VND', 'Asia/Ho_Chi_Minh', now(), now());

-- 2. Default legal entity under the default tenant
INSERT INTO legal_entities (id, tenant_id, code, name, status, created_at, updated_at)
VALUES (1, 1, 'HQ', 'Default Legal Entity (HQ)', 'active', now(), now());

-- 3. Keep sequences ahead of the explicit id=1 inserts
SELECT setval('tenants_id_seq',         (SELECT max(id) FROM tenants));
SELECT setval('legal_entities_id_seq',  (SELECT max(id) FROM legal_entities));

COMMIT;
```

**Backfill `tenant_id = 1` on every TENANT + TENANT+ENTITY table.** Generate one `UPDATE` per logical table (88 statements; the single `attendance_logs` UPDATE cascades across all 13 partition children, so no per-child statement is needed) — example shape:
```sql
UPDATE leave_types       SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE salary_components SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE employees         SET tenant_id = 1 WHERE tenant_id IS NULL;
-- … one per logical business table …
UPDATE attendance_logs   SET tenant_id = 1 WHERE tenant_id IS NULL;  -- cascades to all 13 partitions
```

**Backfill `legal_entity_id = 1` on the 30 entity-scoped tables:**
```sql
UPDATE employees         SET legal_entity_id = 1 WHERE legal_entity_id IS NULL;
UPDATE departments       SET legal_entity_id = 1 WHERE legal_entity_id IS NULL;
UPDATE contracts         SET legal_entity_id = 1 WHERE legal_entity_id IS NULL;
UPDATE attendances       SET legal_entity_id = 1 WHERE legal_entity_id IS NULL;
UPDATE attendance_logs   SET legal_entity_id = 1 WHERE legal_entity_id IS NULL;
-- salary_periods, salary_details, salary_breakdowns, salary_attendance_summary,
-- payroll_adjustments, insurance_claims, social_insurance_info,
-- shift_schedules, shift_schedule_details, shift_assignments, assets, asset_locations …
```

**Then tighten** (this is the §5 step 5 transition):
```sql
ALTER TABLE leave_types       ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE salary_components ALTER COLUMN tenant_id SET NOT NULL;
-- … repeat SET NOT NULL for all 88 logical tables (attendance_logs on the parent only; children inherit) …
```
Verification gate before tightening: `SELECT count(*) FROM <table> WHERE tenant_id IS NULL;` must return `0` for every table.

---

## 7. APP-LAYER CHANGES

Today the app has **no tenant awareness at all**: `HrmAuth` resolves a token → employee with no tenant, and `GenericResourceController` runs raw `DB::table($table)` reads/writes/deletes with zero tenant filter. The following concrete changes are required.

1. **TenantContext service (`app/Support/TenantContext.php` — new).** A request-scoped singleton holding `tenantId` and `legalEntityId`. Single source of truth that both middleware and the query scope read.

2. **Tenant-resolution middleware (`app/Http/Middleware/ResolveTenant.php` — new).** Runs before `HrmAuth`. Resolves the tenant from, in priority order: (a) subdomain (`acme.hrm.app` → `tenants.subdomain`), (b) `X-Tenant` header, (c) a `tenant_id` claim on the bearer token. Loads the `tenants` row, rejects suspended/closed tenants, and sets `TenantContext`. Register in `bootstrap/app.php` middleware group ahead of `hrm.auth`.

3. **Scope `HrmAuth` to the tenant (`app/Http/Middleware/HrmAuth.php`).** Change the `api_tokens` lookup to also constrain `tenant_id` to the resolved tenant, and assert `employees.tenant_id` matches before setting `auth_employee`. Concretely, the existing query at lines 24–29 gains `->where('tenant_id', TenantContext::id())`, and the employee fetch at line 39 gains the same filter. This prevents a token minted for tenant A from authenticating against tenant B.

4. **Global tenant query scope / macro.** Two options behind the same `BelongsToTenant` abstraction:
   - For any Eloquent models, a `TenantScope` global scope that adds `where('tenant_id', TenantContext::id())` on read and an `creating` model event that auto-stamps `tenant_id` (+ `legal_entity_id` where present).
   - Because `GenericResourceController` uses the **query builder, not Eloquent**, add a `DB::table()` wrapper / builder macro `tenantTable($table)` that returns `DB::table($table)->where('tenant_id', TenantContext::id())`. Every `DB::table($table)` call in the controller (index line 41, show line 68, update line 123, destroy lines 136 & 140, updateStatus line 197, and the CV helpers) is replaced with the tenant-scoped wrapper.

5. **Auto-stamp writes (`GenericResourceController::payloadFor`, lines 202–218).** Inject `tenant_id = TenantContext::id()` (and `legal_entity_id` for entity-scoped tables) into the `$payload` in `store()` (line 92) before `insertGetId`. `update()`/`destroy()` must additionally filter by `tenant_id` so a forged `id` from another tenant returns 404, not a cross-tenant mutation. The `recruitment_candidate_cvs` `updateOrInsert` (lines 150–160) must include `tenant_id` in both the match and the values.

6. **Make `HrmTables`/`GenericResourceController` tenant-aware.** `HrmTables` gains a companion map flagging which resources are entity-scoped (need `legal_entity_id`), so the controller knows when to stamp/require it. Optionally `HrmTables::resourceMap()` stays as-is and a new `HrmTables::isEntityScoped($resource)` drives the legal-entity logic.

7. **report_templates execution hardening.** `report_templates.sql_query` stores raw SQL (leak risk noted in inventory). The report runner must wrap/parametrize execution so it cannot read across tenants — e.g. run under a DB role with row-level filters or validate/inject a mandatory `tenant_id = :current` predicate. Do not execute stored SQL verbatim.

8. **Login + token issuance scoped to tenant.** The login endpoint (`/api/v1/auth/login`) must resolve the tenant first, look up the employee by `(tenant_id, company_email)`, and mint an `api_tokens` row carrying `tenant_id`. Add the tenant claim so step 2(c) can resolve it on subsequent requests.

9. **Super-admin vs tenant-admin.** Reserve `users` (currently 0 rows, platform-global) as the **super-admin / platform console** identity, bypassing the tenant scope (can act across tenants for provisioning/support). Tenant-admins remain `employees` with elevated `roles` inside their own `tenant_id`.

10. **Tenant provisioning / onboarding service (`app/Services/TenantProvisioner.php` — new).** On new-tenant signup: create `tenants` row, create at least one `legal_entities` row, then seed per-tenant defaults (baseline `roles` with `is_system_role`, `permissions`, `leave_types`, `request_types`/`approval_flows`, `news_categories`, `system_configs`). These were previously global seeds; they now run per tenant.

11. **Real audit log (`audit_logs` — new tenant-scoped table + middleware/observer).** Record actor employee, tenant_id, table, row id, action, before/after diff. Replaces the current no-op `manager-review`/`profile` stub handlers (`storeRelatedPayload`, lines 176–184) with persisted, tenant-scoped records.

---

## 8. ROLLOUT & ROLLBACK

Expand → migrate → contract, each phase independently shippable and verifiable.

- **Phase A — Expand (additive, zero downtime).** Create `tenants`+`legal_entities`; add nullable `tenant_id`/`legal_entity_id` everywhere. App still ignores the columns. *Verify:* every business table has the nullable column (`information_schema.columns`); app behaves exactly as before.
- **Phase B — Backfill.** Seed default tenant/entity (id=1); `UPDATE … SET tenant_id=1`. *Verify:* `SELECT count(*) … WHERE tenant_id IS NULL` = 0 on all 88 logical tables (the 13 attendance_logs children are covered by the parent UPDATE); spot-check `legal_entity_id` on the 30 entity-scoped rows.
- **Phase C — Tighten.** `SET NOT NULL`, indexes, FKs, composite uniques (after the §4.2 dupe pre-flight). *Verify:* constraints present in `pg_constraint`; `EXPLAIN` shows the new indexes used for tenant-filtered queries.
- **Phase D — App cutover.** Deploy `ResolveTenant` + tenant-scoped `HrmAuth` + query-scope/auto-stamp + login changes together. *Verify:* a token for tenant A cannot read/write tenant B (integration test); default tenant users see exactly their pre-migration data.

**Rollback notes:**
- Phases A/B are reversible by dropping the added columns (FKs/uniques not yet created). Keep each phase in its own migration file so `php artisan migrate:rollback` peels back one phase.
- Phase C rollback = drop FKs, drop composite uniques, recreate the original global uniques, drop indexes, then `DROP COLUMN`. Recreating `employees_employee_code_unique` etc. only succeeds if data is still single-tenant — once a second tenant exists, rollback of the unique change is **not** safe (duplicate codes across tenants). Treat the moment a second tenant onboards as the point of no return for §4 changes.
- Phase D rollback is a code revert (no schema change); the NOT NULL columns are harmless to the old code path because `store()` will simply not set them — therefore keep a transitional DB default of `1` on `tenant_id` until Phase D is confirmed stable, then drop the default.

---

## 9. RISKS & EDGE CASES

1. **Composite-unique pitfalls.** PostgreSQL treats NULL as distinct in a unique index, so `(tenant_id, code)` with a NULL `tenant_id` would not enforce uniqueness — hence `tenant_id` must be NOT NULL *before* the composite unique is added (§5 ordering). The §4.2 columns were app-enforced only; run the `GROUP BY … HAVING count(*)>1` dupe check before each `ADD UNIQUE` or the statement will fail on legacy dupes.
2. **Nullable `legal_entity_id`.** Kept nullable on purpose: assets/asset_locations may be pooled centrally and recruitment is centralized (per open questions). A nullable FK means the global scope must treat "entity not set" as "all entities within the tenant" — never as cross-tenant. Do not make it NOT NULL without confirming every entity-scoped row truly belongs to exactly one branch.
3. **Large-table ALTER locking.** `attendances` (~496), `attendance_logs` partitions (~1260 rows across 2026_05/06) are small today, but the pattern must hold at scale: add columns nullable (metadata-only, no rewrite), backfill in batches if a table is large, and `SET NOT NULL` only after backfill. Avoid `ADD COLUMN … DEFAULT` with a volatile default on big tables (forces a rewrite). For `attendance_logs`, ALTER the **parent only**; never lock 13 children individually.
4. **Seeded/demo data.** All existing rows are conceptually tenant 1 / entity 1; the backfill is a blanket `=1`. If any future seeder is rerun it must be made tenant-aware (TenantProvisioner) or it will insert NULL `tenant_id` and break the NOT NULL constraint.
5. **Route-binding / generic-controller quirks.** `GenericResourceController` resolves tables by string via `HrmTables` and runs raw `DB::table()` with **no tenant predicate** — every read/write/delete is currently cross-tenant-blind. Until the query-scope wrapper (§7.4) lands, the schema changes alone provide *no* isolation. Also the `update()`/`destroy()` methods locate rows by `id` only; without the added `tenant_id` filter, a guessed/forged numeric `id` would mutate another tenant's row. This is the single highest-risk gap and must ship in Phase D.
6. **Auth boundary tokens.** `api_tokens.token_hash` and `password_reset_requests.token` stay globally unique by design; the corresponding lookups in `HrmAuth` and password reset have no tenant context, so resolution is by token first, then `tenant_id` is asserted from the loaded row — not used as a lookup filter.
7. **`users` reclassification.** Left platform-global (0 rows). If product later authenticates tenant end-users via `users`, it must become TENANT with composite `(tenant_id, email)` — a follow-up migration, not part of this plan.
8. **report_templates SQL injection of cross-tenant data.** Stored raw SQL can bypass the row scope entirely; treated as a security item (§7.7), not just a schema item.
