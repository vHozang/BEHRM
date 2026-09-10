# MT_schema_inventory.md — Full Public Schema Inventory

READ-ONLY introspection of database `hrm` (user `hrm`) for the single-org -> multi-tenant SaaS conversion.
Generated from `information_schema`, `pg_constraint`, and `pg_stat_user_tables`. No DDL/DML was executed.

## Key facts up front

- **112 base tables** in schema `public`. Of these, **13 are partition children** of `attendance_logs` (`attendance_logs_2026_01` … `_2026_12` + `attendance_logs_default`) and **1 is the partitioned parent** (`attendance_logs`). The remaining **98 are normal logical tables**.
- **Only 2 real database-level FOREIGN KEY constraints exist**: `employees.manager_id -> employees.id` and `positions.job_family_id -> job_families.id`. Every other relationship is enforced only at the Laravel application layer via `*_id` columns. The "FOREIGN KEY GRAPH" section below therefore lists **declared FKs** plus the **inferred logical FK graph** (by `*_id` naming convention) so a topological backfill order can be derived.
- Nearly every business table carries a `legacy_id bigint UNIQUE` column (migration artifact from the legacy system) and a `meta jsonb` column.
- **Almost every business table has a `legacy_id` UNIQUE constraint that is GLOBAL.** These are migration-surrogate keys; whether they must become composite is discussed in the natural-keys section (they are low-risk but listed).
- The genuinely **risky global UNIQUE constraints on natural/business keys** are: `employees.employee_code`, `employees.company_email`, `users.email`, `job_families.code`, `salary_components.code`, plus the auth/token uniques `api_tokens.token_hash`, `password_reset_requests.token`, `failed_jobs.uuid`, and the 1:1 `recruitment_candidate_cvs.candidate_id`. See the dedicated section.

---

## Table of Contents (all base tables)

**Business / config / transactional (tenant-scope candidates):**
allowances, approval_flows, approval_histories, approval_roles, approval_steps, asset_assignments, asset_categories, asset_incidents, asset_locations, asset_maintenance, assets, attendances, certificate_types, certificates, contract_change_logs, contract_histories, contract_templates, contract_types, contracts, dashboard_views, deductions, departments, dependents, document_types, employee_allowances, employee_deductions, employee_roles, employees, employment_histories, holidays, identity_documents, insurance_claims, insurance_types, interview_schedules, job_families, leave_advancement_config, leave_advancement_requests, leave_balances, leave_carryover_tracking, leave_requests, leave_transactions, leave_types, news, news_categories, news_reads, notification_configs, notifications, overtime_requests, payroll_adjustments, permissions, policies, policy_acknowledgments, positions, qualification_types, qualifications, recruitment_ai_scoring_jobs, recruitment_candidate_cvs, recruitment_candidate_manager_reviews, recruitment_candidates, recruitment_positions, recruitment_rejected_archive, report_histories, report_templates, request_attachments, request_types, requests, role_permissions, roles, salary_attendance_summary, salary_breakdowns, salary_components, salary_details, salary_periods, seniority_leave_history, service_categories, service_ticket_updates, service_tickets, shift_assignments, shift_schedule_details, shift_schedules, shift_swaps, shift_types, social_insurance_info, suppliers, system_configs

**Attendance log (partitioned set):**
attendance_logs (parent), attendance_logs_2026_01 … attendance_logs_2026_12, attendance_logs_default

**Auth / platform:**
api_tokens, password_reset_requests, users

**Global reference (may stay global):**
banks, nationalities

**Infra (GLOBAL — leave untouched):**
cache, cache_locks, failed_jobs, job_batches, jobs, migrations, password_reset_tokens, sessions

---

## Per-table detail

> Column legend: `name : type (NULL?)`. NULL? = `null` if nullable, `NOT NULL` otherwise.
> Every PK is `id bigint` unless stated. Most tables also have `legacy_id bigint NULL` with a GLOBAL UNIQUE (`<table>_legacy_id_unique`), `meta jsonb NULL`, `created_at/updated_at timestamptz NULL` — abbreviated as **[std]** below.

### allowances — rows ≈ 5
Cols: id, legacy_id, allowance_code:varchar(null), allowance_name:varchar(null), allowance_type:varchar(null), calculation_method:varchar(null), is_taxable:bool(NOT NULL), is_insurable:bool(NOT NULL), description:text(null), status:varchar(null), [std].
PK id. Unique: legacy_id. FK: none. Logical: none.

### api_tokens — rows ≈ 38
Cols: id, employee_id:bigint(NOT NULL), token_hash:varchar(NOT NULL), expires_at:timestamptz(null), created_at, updated_at. PK id. Unique: **token_hash** (natural — token). Logical FK: employee_id->employees.

### approval_flows — rows ≈ 3
Cols: id, legacy_id, request_type_id:bigint(null), flow_name:varchar(null), description:text, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: request_type_id->request_types.

### approval_histories — rows ≈ 13
Cols: id, legacy_id, request_id:bigint(null), step_id:bigint(null), approver_id:bigint(null), action:varchar, comment:text, action_date:date, [std]. PK id. Unique: legacy_id. Logical FK: request_id->requests, step_id->approval_steps, approver_id->employees.

### approval_roles — rows ≈ 5
Cols: id, legacy_id, role_code:varchar, role_name:varchar, description:text, [std]. PK id. Unique: legacy_id.

### approval_steps — rows ≈ 8
Cols: id, legacy_id, approval_flow_id:bigint(null), step_order:varchar, approver_role_id:bigint(null), approver_user_id:bigint(null), status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: approval_flow_id->approval_flows, approver_role_id->approval_roles, approver_user_id->employees.

### asset_assignments — rows ≈ 0
Cols: id, legacy_id, asset_id:bigint(null), employee_id:bigint(null), assigned_by:varchar, assigned_date:date, return_date:date, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: asset_id->assets, employee_id->employees.

### asset_categories — rows ≈ 0
Cols: id, legacy_id, category_code:varchar, category_name:varchar, description:text, status:varchar, [std]. PK id. Unique: legacy_id.

### asset_incidents — rows ≈ 0
Cols: id, legacy_id, asset_id:bigint, assignment_id:bigint, reported_by:bigint, incident_type:varchar, incident_date:date, description:text, damage_level:varchar, status:varchar, resolved_by:bigint, resolved_date:date, [std]. PK id. Unique: legacy_id. Logical FK: asset_id->assets, assignment_id->asset_assignments, reported_by/resolved_by->employees.

### asset_locations — rows ≈ 0
Cols: id, legacy_id, location_code:varchar, location_name:varchar, department_id:bigint, description:text, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: department_id->departments.

### asset_maintenance — rows ≈ 0
Cols: id, legacy_id, asset_id:bigint, maintenance_type:varchar, maintenance_date:date, cost:numeric, vendor:varchar, description:text, next_maintenance_date:date, [std]. PK id. Unique: legacy_id. Logical FK: asset_id->assets.

### assets — rows ≈ 0
Cols: id, legacy_id, asset_code:varchar, asset_name:varchar, category_id:bigint, supplier_id:bigint, location_id:bigint, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: category_id->asset_categories, supplier_id->suppliers, location_id->asset_locations. Natural key candidate: asset_code (no unique declared currently).

### attendances — rows ≈ 496
Cols: id, legacy_id, employee_id:bigint, shift_type_id:bigint, work_date:date, check_in_time:time, check_out_time:time, check_in_time_2:varchar, check_out_time_2:varchar, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, shift_type_id->shift_types.

### attendance_logs (PARTITIONED parent) — rows: see children
Cols: id:bigint(NOT NULL), employee_id:bigint(NOT NULL), employee_code:varchar(NOT NULL), action:varchar(NOT NULL), source:varchar(NOT NULL), device_id:varchar, location_code:varchar, ip_address:inet, metadata:jsonb, status:varchar(NOT NULL), checked_at:timestamptz(NOT NULL), processed_at:timestamptz, error_message:text, created_at:timestamptz(NOT NULL).
**Composite PK (id, checked_at)** (partition key must be in PK). Partitioned by RANGE(checked_at). No legacy_id, no updated_at. Logical FK: employee_id->employees (carries denormalized employee_code).
Children (identical structure, composite PK): attendance_logs_2026_01..12, attendance_logs_default. Row counts: 2026_05 ≈ 294, 2026_06 ≈ 966, all others 0.

### banks — rows ≈ 15  (GLOBAL REFERENCE candidate)
Cols: id, legacy_id, bank_code:varchar, bank_name:varchar, swift_code:varchar, status:bool(NOT NULL), description:text, [std]. PK id. Unique: legacy_id.

### certificate_types — rows ≈ 3
Cols: id, legacy_id, certificate_type_code:varchar, certificate_type_name:varchar, description:text, [std]. PK id. Unique: legacy_id.

### certificates — rows ≈ 8
Cols: id, legacy_id, employee_id:bigint, certificate_type_id:bigint, certificate_name:varchar, issued_by:varchar, issued_date:date, expiry_date:date, certificate_number:varchar, score:numeric, file_url:varchar, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, certificate_type_id->certificate_types.

### contract_change_logs — rows ≈ 0
Cols: id, legacy_id, employee_id:bigint, contract_id:bigint, change_type:varchar, old_value:text, new_value:text, reason:text, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, contract_id->contracts.

### contract_histories — rows ≈ 5
Cols: id, legacy_id, contract_id:bigint, action:varchar, action_by:bigint, previous_value:text, new_value:text, notes:text, [std]. PK id. Unique: legacy_id. Logical FK: contract_id->contracts, action_by->employees.

### contract_templates — rows ≈ 3
Cols: id, legacy_id, template_code:varchar, template_name:varchar, contract_type_id:bigint, content:text, version:varchar, is_active:bool(NOT NULL), file_url:varchar, effective_date:date, [std]. PK id. Unique: legacy_id. Logical FK: contract_type_id->contract_types.

### contract_types — rows ≈ 4
Cols: id, legacy_id, contract_type_code:varchar, contract_type_name:varchar, description:text, status:varchar, [std]. PK id. Unique: legacy_id.

### contracts — rows ≈ 15
Cols: id, legacy_id, employee_id:bigint, contract_type_id:bigint, position_id:bigint, department_id:bigint, contract_number:varchar, status:varchar, start_date:date, end_date:date, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, contract_type_id->contract_types, position_id->positions, department_id->departments. **Natural key: contract_number (no unique declared currently — should be composite-unique per tenant/legal_entity).**

### dashboard_views — rows ≈ 0
Cols: id, legacy_id, employee_id:bigint, view_date:date, view_type:varchar, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees.

### deductions — rows ≈ 5
Cols: id, legacy_id, deduction_code:varchar, deduction_name:varchar, deduction_type:varchar, is_mandatory:bool(NOT NULL), description:text, status:varchar, [std]. PK id. Unique: legacy_id.

### departments — rows ≈ 16
Cols: id, legacy_id, department_code:varchar, department_name:varchar, status:bool(NOT NULL), description:text, [std]. PK id. Unique: legacy_id. **Natural key: department_code (no unique declared — should become composite-unique).** No parent_department_id column (flat).

### dependents — rows ≈ 10
Cols: id, legacy_id, employee_id:bigint, full_name:varchar, relationship:varchar, date_of_birth:varchar, id_card_number:varchar, tax_code:varchar, deduction_percent:numeric, start_date:date, end_date:date, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees.

### document_types — rows ≈ 3
Cols: id, legacy_id, document_type_code:varchar, document_type_name:varchar, description:text, [std]. PK id. Unique: legacy_id.

### employee_allowances — rows ≈ 18
Cols: id, legacy_id, employee_id:bigint, allowance_id:bigint, amount:numeric, percentage:numeric, effective_date:date, expiry_date:date, is_active:bool(NOT NULL), notes:text, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, allowance_id->allowances.

### employee_deductions — rows ≈ 21
Cols: id, legacy_id, employee_id:bigint, deduction_id:bigint, amount:numeric, percentage:numeric, effective_date:date, expiry_date:date, is_active:bool(NOT NULL), notes:text, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, deduction_id->deductions.

### employee_roles — rows ≈ 18
Cols: id, legacy_id, employee_id:bigint, role_id:bigint, department_id:bigint, effective_date:date, expiry_date:date, is_active:bool(NOT NULL), [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, role_id->roles, department_id->departments.

### employees — rows ≈ 21  (CORE)
Cols: id, legacy_id, employee_code:varchar(null), full_name:varchar(NOT NULL), date_of_birth:date, gender:varchar, phone_number:varchar, personal_email:varchar, company_email:varchar(null), password_hash:varchar, status:varchar(NOT NULL), hire_date:date, department_id:bigint, position_id:bigint, profile:jsonb, created_at, updated_at, skills:jsonb(NOT NULL), manager_id:bigint, base_salary:numeric.
PK id. Unique: **employee_code** (`employees_employee_code_unique`), **company_email** (`employees_company_email_unique`), legacy_id. **Declared FK: manager_id -> employees.id.** Logical FK: department_id->departments, position_id->positions.

### employment_histories — rows ≈ 20
Cols: id, legacy_id, employee_id:bigint, department_id:bigint, position_id:bigint, start_date:date, end_date:date, is_current:bool(NOT NULL), decision_number:varchar, decision_date:date, notes:text, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, department_id->departments, position_id->positions.

### holidays — rows ≈ 0
Cols: id, legacy_id, holiday_code:varchar, holiday_name:varchar, holiday_date:date, holiday_type:varchar, is_recurring:bool(NOT NULL), description:text, [std]. PK id. Unique: legacy_id.

### identity_documents — rows ≈ 10
Cols: id, legacy_id, employee_id:bigint, document_type_id:bigint, document_number:varchar, full_name:varchar, date_of_birth:varchar, issue_date:date, issue_place:varchar, expiry_date:date, front_image_url:varchar, back_image_url:varchar, has_chip:bool(NOT NULL), [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, document_type_id->document_types.

### insurance_claims — rows ≈ 4
Cols: id, legacy_id, employee_id:bigint, request_id:bigint, insurance_type_id:bigint, claim_code:varchar, leave_request_id:bigint, start_date:date, end_date:date, total_days:numeric, daily_rate:numeric, total_amount:numeric, payment_source:varchar, certificate_number:varchar, certificate_file_url:varchar, certificate_uploaded_date:date, bank_account:varchar, bank_id:bigint, payment_status:varchar, notes:text, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, request_id->requests, insurance_type_id->insurance_types, leave_request_id->leave_requests, bank_id->banks. Natural key: claim_code.

### insurance_types — rows ≈ 0
Cols: id, legacy_id, insurance_type_code:varchar, insurance_type_name:varchar, description:text, status:varchar, [std]. PK id. Unique: legacy_id.

### interview_schedules — rows ≈ 0
Cols: id, legacy_id, candidate_id:bigint, interviewer_id:bigint, department_manager_id:bigint, interview_date:date, interview_time:time, interview_mode:varchar, status:varchar, result:varchar, manager_decision:varchar, [std]. PK id. Unique: legacy_id. Logical FK: candidate_id->recruitment_candidates, interviewer_id/department_manager_id->employees.

### job_families — rows ≈ 0
Cols: id, code:varchar(NOT NULL), name:varchar(NOT NULL), description:text, is_active:bool(NOT NULL), meta:jsonb, created_at, updated_at. PK id. Unique: **code** (`job_families_code_unique` — natural key). **Referenced by declared FK from positions.job_family_id.** No legacy_id.

### leave_advancement_config — rows ≈ 0
Cols: id, legacy_id, department_id:bigint, position_id:bigint, max_advance_days:numeric, approval_flow_id:bigint, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: department_id->departments, position_id->positions, approval_flow_id->approval_flows.

### leave_advancement_requests — rows ≈ 0
Cols: id, legacy_id, request_id:bigint, employee_id:bigint, advance_days:numeric, reason:text, approved_by_manager:bigint, approved_by_hr:bigint, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: request_id->requests, employee_id->employees, approved_by_*->employees.

### leave_balances — rows ≈ 10
Cols: id, legacy_id, employee_id:bigint, leave_type_id:bigint, year:varchar, total_days:numeric, used_days:numeric, remaining_days:numeric, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, leave_type_id->leave_types.

### leave_carryover_tracking — rows ≈ 0
Cols: id, legacy_id, employee_id:bigint, leave_type_id:bigint, year:int, carried_days:numeric, used_days:numeric, expired_days:numeric, expiry_date:date, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, leave_type_id->leave_types.

### leave_requests — rows ≈ 9
Cols: id, legacy_id, request_id:bigint, employee_id:bigint, leave_type_id:bigint, start_date:date, end_date:date, total_days:numeric, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: request_id->requests, employee_id->employees, leave_type_id->leave_types.

### leave_transactions — rows ≈ 18
Cols: id, legacy_id, employee_id:bigint, leave_type_id:bigint, transaction_date:date, transaction_type:varchar, quantity:numeric, before_balance:numeric, after_balance:numeric, reference_id:bigint, reference_type:varchar, reason:text, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, leave_type_id->leave_types (reference_id/type = polymorphic).

### leave_types — rows ≈ 5
Cols: id, legacy_id, leave_type_code:varchar, leave_type_name:varchar, category:varchar, status:varchar, [std]. PK id. Unique: legacy_id. Natural key: leave_type_code.

### news — rows ≈ 6
Cols: id, legacy_id, category_id:bigint, title:varchar, summary:varchar, content:text, priority:varchar, status:varchar, published_at:timestamptz, [std]. PK id. Unique: legacy_id. Logical FK: category_id->news_categories.

### news_categories — rows ≈ 3
Cols: id, legacy_id, category_code:varchar, category_name:varchar, description:text, status:varchar, [std]. PK id. Unique: legacy_id. Natural key: category_code.

### news_reads — rows ≈ 0
Cols: id, legacy_id, news_id:bigint, employee_id:bigint, read_at:timestamptz, [std]. PK id. Unique: legacy_id. Logical FK: news_id->news, employee_id->employees.

### notification_configs — rows ≈ 0
Cols: id, legacy_id, notification_type:varchar, recipients:varchar, template_subject:varchar, template_body:text, status:varchar, [std]. PK id. Unique: legacy_id.

### notifications — rows ≈ 9
Cols: id, legacy_id, sender_id:bigint, receiver_id:bigint, title:varchar, message:text, priority:varchar, reference_type:varchar, reference_id:bigint, read_at:timestamptz, [std]. PK id. Unique: legacy_id. Logical FK: sender_id/receiver_id->employees (reference = polymorphic).

### overtime_requests — rows ≈ 1
Cols: id, legacy_id, request_id:bigint, employee_id:bigint, work_date:date, start_time:time, end_time:time, total_hours:numeric, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: request_id->requests, employee_id->employees.

### password_reset_requests — rows ≈ 0  (AUTH)
Cols: id, employee_id:bigint(null), company_email:varchar(NOT NULL), token:varchar(NOT NULL), expires_at:timestamptz(NOT NULL), used_at:timestamptz, created_at, updated_at. PK id. Unique: **token** (natural). Logical FK: employee_id->employees.

### payroll_adjustments — rows ≈ 0
Cols: id, legacy_id, employee_id:bigint, paid_salary_detail_id:bigint, paid_period_id:bigint, adjustment_type:varchar, amount:numeric, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, paid_salary_detail_id->salary_details, paid_period_id->salary_periods.

### permissions — rows ≈ 7
Cols: id, legacy_id, permission_code:varchar, permission_name:varchar, module:varchar, description:text, [std]. PK id. Unique: legacy_id. Natural key: permission_code. (Often treated as platform-global RBAC vocabulary — see classification.)

### policies — rows ≈ 1
Cols: id, legacy_id, policy_code:varchar, policy_name:varchar, policy_type:varchar, content:text, status:varchar, effective_date:date, [std]. PK id. Unique: legacy_id. Natural key: policy_code.

### policy_acknowledgments — rows ≈ 1
Cols: id, legacy_id, policy_id:bigint, employee_id:bigint, acknowledged_at:timestamptz, ip_address:varchar, [std]. PK id. Unique: legacy_id. Logical FK: policy_id->policies, employee_id->employees.

### positions — rows ≈ 15
Cols: id, legacy_id, position_code:varchar, position_name:varchar, position_group:varchar, position_level:varchar, status:bool(NOT NULL), description:text, meta, created_at, updated_at, job_family_id:bigint(null). PK id. Unique: legacy_id. **Declared FK: job_family_id -> job_families.id.** Natural key: position_code.

### qualification_types — rows ≈ 3
Cols: id, legacy_id, qualification_type_code:varchar, qualification_type_name:varchar, description:text, [std]. PK id. Unique: legacy_id.

### qualifications — rows ≈ 10
Cols: id, legacy_id, employee_id:bigint, qualification_type_id:bigint, qualification_name:varchar, major:varchar, school_name:varchar, graduation_year:int, graduation_grade:varchar, issued_date:date, issued_by:varchar, qualification_number:varchar, file_url:varchar, is_highest:bool(NOT NULL), [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, qualification_type_id->qualification_types.

### recruitment_ai_scoring_jobs — rows ≈ 0
Cols: id, legacy_id, candidate_id:bigint, status:varchar, attempts:varchar, max_attempts:varchar, available_at:timestamptz, last_error:text, [std]. PK id. Unique: legacy_id. Logical FK: candidate_id->recruitment_candidates.

### recruitment_candidate_cvs — rows ≈ 0
Cols: id, legacy_id, candidate_id:bigint(NOT NULL), original_filename:varchar, storage_path:varchar(NOT NULL), mime_type:varchar, file_size:bigint, uploaded_by:bigint, created_at, updated_at. PK id. Unique: legacy_id, **candidate_id** (`..._candidate_id_unique` — enforces 1 CV per candidate). Logical FK: candidate_id->recruitment_candidates, uploaded_by->employees.

### recruitment_candidate_manager_reviews — rows ≈ 0
Cols: id, legacy_id, candidate_id:bigint, manager_id:bigint, workflow_status:varchar, manager_score:numeric, manager_decision_proposal:varchar, [std]. PK id. Unique: legacy_id. Logical FK: candidate_id->recruitment_candidates, manager_id->employees.

### recruitment_candidates — rows ≈ 6
Cols: id, legacy_id, recruitment_position_id:bigint, full_name:varchar, email:varchar, phone_number:varchar, application_status:varchar, ai_score:numeric, ai_scoring_status:varchar, ai_scoring_error:text, ai_scored_at:timestamptz, ai_matched_skills_json:jsonb, ai_missing_skills_json:jsonb, [std]. PK id. Unique: legacy_id. Logical FK: recruitment_position_id->recruitment_positions. (email NOT unique here.)

### recruitment_positions — rows ≈ 3
Cols: id, legacy_id, position_name:varchar, department_id:bigint, employment_type:varchar, status:varchar, required_skills_json:jsonb, [std]. PK id. Unique: legacy_id. Logical FK: department_id->departments.

### recruitment_rejected_archive — rows ≈ 0
Cols: id, legacy_id, candidate_id:bigint, rejected_by:varchar, snapshot:jsonb, [std]. PK id. Unique: legacy_id. Logical FK: candidate_id->recruitment_candidates.

### report_histories — rows ≈ 0
Cols: id, legacy_id, template_id:bigint, executed_by:bigint, executed_at:timestamptz, parameters:jsonb, file_url:varchar, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: template_id->report_templates, executed_by->employees.

### report_templates — rows ≈ 6
Cols: id, legacy_id, template_code:varchar, template_name:varchar, report_type:varchar, sql_query:text, columns_config:jsonb, filters_config:jsonb, chart_config:jsonb, created_by:bigint, is_public:bool(NOT NULL), status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: created_by->employees. Natural key: template_code. **NOTE: stores raw SQL — cross-tenant data leak risk; must enforce tenant scoping at execution.**

### request_attachments — rows ≈ 0
Cols: id, legacy_id, request_id:bigint, uploaded_by:bigint, file_name:varchar, file_url:varchar, mime_type:varchar, file_size:varchar, [std]. PK id. Unique: legacy_id. Logical FK: request_id->requests, uploaded_by->employees.

### request_types — rows ≈ 5
Cols: id, legacy_id, request_type_code:varchar, request_type_name:varchar, category:varchar, approval_flow_id:bigint, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: approval_flow_id->approval_flows. Natural key: request_type_code.

### requests — rows ≈ 10
Cols: id, legacy_id, request_type_id:bigint, requester_id:bigint, current_step_id:bigint, title:varchar, description:text, status:varchar, priority:varchar, [std]. PK id. Unique: legacy_id. Logical FK: request_type_id->request_types, requester_id->employees, current_step_id->approval_steps.

### role_permissions — rows ≈ 0
Cols: id, legacy_id, role_id:bigint, permission_id:bigint, [std]. PK id. Unique: legacy_id. Logical FK: role_id->roles, permission_id->permissions. (Junction.)

### roles — rows ≈ 5
Cols: id, legacy_id, role_code:varchar, role_name:varchar, description:text, is_system_role:bool(NOT NULL), [std]. PK id. Unique: legacy_id. Natural key: role_code. (is_system_role flags platform-baseline roles.)

### salary_attendance_summary — rows ≈ 5
Cols: id, legacy_id, employee_id:bigint, period_id:bigint, standard_days:numeric, actual_working_days:numeric, paid_leave_days:numeric, unpaid_leave_days:numeric, holiday_days:numeric, overtime_hours:numeric, late_minutes:numeric, early_leave_minutes:numeric, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, period_id->salary_periods.

### salary_breakdowns — rows ≈ 0
Cols: id, legacy_id, salary_detail_id:bigint, item_type:varchar, item_code:varchar, item_name:varchar, amount:numeric, [std]. PK id. Unique: legacy_id. Logical FK: salary_detail_id->salary_details.

### salary_components — rows ≈ 0
Cols: id, code:varchar(NOT NULL), name:varchar(NOT NULL), type:varchar(NOT NULL), category:varchar(NOT NULL), is_taxable:bool(NOT NULL), is_active:bool(NOT NULL), meta, created_at, updated_at. PK id. Unique: **code** (`salary_components_code_unique` — natural key). No legacy_id.

### salary_details — rows ≈ 7
Cols: id, legacy_id, period_id:bigint, employee_id:bigint, contract_id:bigint, gross_salary:numeric, net_salary:numeric, transfer_status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: period_id->salary_periods, employee_id->employees, contract_id->contracts.

### salary_periods — rows ≈ 3
Cols: id, legacy_id, period_code:varchar, period_name:varchar, period_type:varchar, start_date:date, end_date:date, status:varchar, [std]. PK id. Unique: legacy_id. Natural key: period_code.

### seniority_leave_history — rows ≈ 0
Cols: id, legacy_id, employee_id:bigint, calculation_date:date, years_of_service:int, base_leave:numeric, seniority_bonus:numeric, total_leave:numeric, effective_year:int, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees.

### service_categories — rows ≈ 0
Cols: id, legacy_id, category_code:varchar, category_name:varchar, description:text, status:varchar, [std]. PK id. Unique: legacy_id. Natural key: category_code.

### service_ticket_updates — rows ≈ 0
Cols: id, legacy_id, ticket_id:bigint, created_by:bigint, action_type:varchar, old_status:varchar, new_status:varchar, comment:text, [std]. PK id. Unique: legacy_id. Logical FK: ticket_id->service_tickets, created_by->employees.

### service_tickets — rows ≈ 0
Cols: id, legacy_id, ticket_code:varchar, requester_id:bigint, category_id:bigint, assigned_to:varchar, title:varchar, description:text, priority:varchar, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: requester_id->employees, category_id->service_categories. Natural key: ticket_code.

### shift_assignments — rows ≈ 11
Cols: id, legacy_id, employee_id:bigint, shift_type_id:bigint, effective_date:date, expiry_date:date, is_permanent:bool(NOT NULL), assigned_by:bigint, notes:text, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees, shift_type_id->shift_types, assigned_by->employees.

### shift_schedule_details — rows ≈ 7
Cols: id, legacy_id, schedule_id:bigint, day_of_week:varchar, shift_type_id:bigint, is_holiday:bool(NOT NULL), [std]. PK id. Unique: legacy_id. Logical FK: schedule_id->shift_schedules, shift_type_id->shift_types.

### shift_schedules — rows ≈ 5
Cols: id, legacy_id, schedule_code:varchar, schedule_name:varchar, department_id:bigint, effective_from:date, effective_to:date, is_active:bool(NOT NULL), [std]. PK id. Unique: legacy_id. Logical FK: department_id->departments. Natural key: schedule_code.

### shift_swaps — rows ≈ 0
Cols: id, legacy_id, requester_id:bigint, target_employee_id:bigint, original_shift_id:bigint, requested_shift_id:bigint, swap_date:date, reason:text, approval_status:varchar, approver_id:bigint, [std]. PK id. Unique: legacy_id. Logical FK: requester_id/target_employee_id/approver_id->employees, original_shift_id/requested_shift_id->shift_types (or shift_assignments).

### shift_types — rows ≈ 4
Cols: id, legacy_id, shift_code:varchar, shift_name:varchar, start_time:time, end_time:time, status:varchar, [std]. PK id. Unique: legacy_id. Natural key: shift_code.

### social_insurance_info — rows ≈ 10
Cols: id, legacy_id, employee_id:bigint, social_insurance_number:varchar, health_insurance_number:varchar, tax_code:varchar, issue_date:date, issue_place:varchar, status:varchar, [std]. PK id. Unique: legacy_id. Logical FK: employee_id->employees. Natural keys (not declared unique): social_insurance_number, tax_code.

### suppliers — rows ≈ 0
Cols: id, legacy_id, supplier_code:varchar, supplier_name:varchar, contact_person:varchar, phone_number:varchar, email:varchar, address:varchar, status:varchar, [std]. PK id. Unique: legacy_id. Natural key: supplier_code.

### system_configs — rows ≈ 27
Cols: id, legacy_id, config_key:varchar, config_value:varchar, config_type:varchar, description:text, [std]. PK id. Unique: legacy_id. Natural key: config_key. (Per-tenant settings store.)

### users — rows ≈ 0  (AUTH / platform — separate from employees)
Cols: id, name:varchar(NOT NULL), email:varchar(NOT NULL), email_verified_at:timestamp, password:varchar(NOT NULL), remember_token:varchar, created_at, updated_at. PK id. Unique: **email** (`users_email_unique`). No legacy_id/meta. Currently 0 rows — auth appears to run through `employees` + `api_tokens`, with `users` as the default Laravel table.

### Infra tables (GLOBAL — leave untouched)
- **cache** — key:varchar PK, value:text, expiration:int.
- **cache_locks** — key:varchar PK, owner:varchar, expiration:int.
- **failed_jobs** — id PK, uuid:varchar UNIQUE, connection/queue/payload/exception:text, failed_at:timestamp.
- **job_batches** — id:varchar PK, name, total_jobs/pending_jobs/failed_jobs:int, failed_job_ids:text, options:text, cancelled_at/created_at/finished_at:int.
- **jobs** — id PK, queue:varchar, payload:text, attempts:smallint, reserved_at/available_at/created_at:int.
- **migrations** — id:int PK, migration:varchar, batch:int.
- **password_reset_tokens** — email:varchar PK, token:varchar, created_at:timestamp.
- **sessions** — id:varchar PK, user_id:bigint, ip_address, user_agent:text, payload:text, last_activity:int.

---

## UNIQUE CONSTRAINTS ON NATURAL KEYS

These are the constraints that BREAK multi-tenancy if left global. Each must become a COMPOSITE unique that includes the tenant scope column (and, for legal-entity-scoped tables, optionally legal_entity_id). **Bold = high risk (active business natural key with data / actively written).**

| # | Table.Column | Existing constraint | Action required |
|---|---|---|---|
| 1 | **employees.employee_code** | `employees_employee_code_unique` | -> UNIQUE (tenant_id, employee_code) |
| 2 | **employees.company_email** | `employees_company_email_unique` | -> UNIQUE (tenant_id, company_email) |
| 3 | **users.email** | `users_email_unique` | -> UNIQUE (tenant_id, email) (if users are tenant-scoped; or keep global if users become platform-admin only — decide in classification) |
| 4 | **job_families.code** | `job_families_code_unique` | -> UNIQUE (tenant_id, code) |
| 5 | **salary_components.code** | `salary_components_code_unique` | -> UNIQUE (tenant_id, code) |
| 6 | api_tokens.token_hash | `api_tokens_token_hash_unique` | Keep GLOBAL unique (token must be globally unique for lookup); add tenant_id column for isolation but DO NOT scope the uniqueness of the hash. |
| 7 | password_reset_requests.token | `password_reset_requests_token_unique` | Keep GLOBAL unique (random token); tenant_id optional. |
| 8 | recruitment_candidate_cvs.candidate_id | `recruitment_candidate_cvs_candidate_id_unique` | 1:1 with candidate which is already tenant-scoped via parent; keep as-is but add tenant_id. |
| 9 | failed_jobs.uuid | `failed_jobs_uuid_unique` | GLOBAL — leave untouched (infra). |

### Natural-key columns that are NOT YET declared unique but SHOULD become composite-unique per tenant
The legacy import did not create DB uniques for most `*_code` columns (uniqueness was app-enforced). When adding tenant_id these should get composite uniques to prevent intra-tenant collisions: `departments.department_code`, `positions.position_code`, `contracts.contract_number`, `leave_types.leave_type_code`, `roles.role_code`, `permissions.permission_code`, `request_types.request_type_code`, `news_categories.category_code`, `service_categories.category_code`, `service_tickets.ticket_code`, `shift_types.shift_code`, `shift_schedules.schedule_code`, `salary_periods.period_code`, `policies.policy_code`, `report_templates.template_code`, `holidays.holiday_code`, `allowances.allowance_code`, `deductions.deduction_code`, `asset_categories.category_code`, `assets.asset_code`, `suppliers.supplier_code`, `insurance_types.insurance_type_code`, `insurance_claims.claim_code`, `contract_types.contract_type_code`, `contract_templates.template_code`, `certificate_types.certificate_type_code`, `document_types.document_type_code`, `qualification_types.qualification_type_code`, `nationalities.nationality_code`, `banks.bank_code`, `system_configs.config_key`, `social_insurance_info.social_insurance_number`/`tax_code`. (Detailed per-table decision is in MT_tenant_classification.md.)

### legacy_id UNIQUE constraints (LOW RISK)
~85 tables carry `<table>_legacy_id_unique` on the migration-surrogate `legacy_id`. These are GLOBAL uniques on a one-time import id. They are low risk because new tenants will not populate legacy_id (it stays NULL for new rows, and PostgreSQL allows multiple NULLs under a UNIQUE). **Recommendation:** make these composite `(tenant_id, legacy_id)` for cleanliness/defense-in-depth, but they are not blocking. They are NOT counted in the "risky" list returned.

---

## FOREIGN KEY GRAPH

### A. DECLARED database FOREIGN KEYS (only 2 exist)
```
employees.manager_id      -> employees.id      (self-reference; manager hierarchy)
positions.job_family_id   -> job_families.id
```
> The schema relies almost entirely on application-level integrity. The backfill/scope work must therefore be driven by the INFERRED graph below, not by `pg_constraint`.

### B. INFERRED logical FK graph (by `*_id` naming) — for topological backfill order

**Tier 0 (no parent / roots, scope directly to tenant first):**
tenants (NEW), legal_entities (NEW, -> tenants), nationalities, banks, leave_types, contract_types, document_types, certificate_types, qualification_types, insurance_types, asset_categories, service_categories, news_categories, shift_types, salary_periods, salary_components, holidays, allowances, deductions, suppliers, positions(+job_families), roles, permissions, approval_roles, request_types(-> approval_flows), system_configs, policies, job_families, departments.

**Tier 1 (reference one Tier-0 / root):**
employees (-> departments, positions, employees.manager_id), approval_flows (-> request_types), shift_schedules (-> departments), asset_locations (-> departments), recruitment_positions (-> departments), news (-> news_categories), assets (-> asset_categories, suppliers, asset_locations), contract_templates (-> contract_types).

**Tier 2 (reference employees / Tier-1):**
contracts (-> employees, contract_types, positions, departments), employment_histories, employee_roles (-> employees, roles, departments), employee_allowances, employee_deductions, dependents, identity_documents, qualifications, certificates, social_insurance_info, leave_balances, leave_carryover_tracking, leave_transactions, seniority_leave_history, attendances (-> employees, shift_types), attendance_logs (-> employees), shift_assignments, shift_schedule_details (-> shift_schedules, shift_types), recruitment_candidates (-> recruitment_positions), approval_steps (-> approval_flows, approval_roles, employees), api_tokens (-> employees), password_reset_requests (-> employees), dashboard_views, notifications, news_reads, policy_acknowledgments, leave_advancement_config, recruitment_candidate_cvs, recruitment_ai_scoring_jobs, recruitment_candidate_manager_reviews, recruitment_rejected_archive, asset_assignments, asset_maintenance, report_templates, shift_swaps, certificates, salary_attendance_summary (-> employees, salary_periods).

**Tier 3 (reference Tier-2 transaction roots):**
requests (-> request_types, employees, approval_steps), leave_requests (-> requests, employees, leave_types), overtime_requests (-> requests, employees), leave_advancement_requests (-> requests, employees), insurance_claims (-> employees, requests, insurance_types, leave_requests, banks), salary_details (-> salary_periods, employees, contracts), contract_histories (-> contracts, employees), contract_change_logs (-> employees, contracts), interview_schedules (-> recruitment_candidates, employees), asset_incidents (-> assets, asset_assignments, employees), service_tickets (-> employees, service_categories), report_histories (-> report_templates, employees), payroll_adjustments (-> employees, salary_details, salary_periods).

**Tier 4 (reference Tier-3):**
approval_histories (-> requests, approval_steps, employees), request_attachments (-> requests, employees), salary_breakdowns (-> salary_details), service_ticket_updates (-> service_tickets, employees), role_permissions (-> roles, permissions).

> Backfill order = Tier 0 -> 4. Add tenant_id (+ legal_entity_id where applicable) starting at roots, populate every existing row to the default tenant_id=1 / default legal_entity, then add NOT NULL + indexes + composite uniques.

---

## Counts summary
- Base tables total: **112** (98 logical + 1 partitioned parent + 13 partition children).
- Declared FKs: **2**.
- Risky global UNIQUE constraints on natural keys (blocking, must become composite): **5** (employee_code, company_email, users.email, job_families.code, salary_components.code). Plus 3 auth/uuid uniques that intentionally stay global, and ~85 low-risk legacy_id uniques.
