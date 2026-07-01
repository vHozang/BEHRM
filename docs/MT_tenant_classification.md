# MT_tenant_classification.md — Per-Table Tenant Classification

Source: `D:\HRM-System-2\MT_schema_inventory.md` (read-only introspection of DB `hrm`).
Target architecture: shared DB + row-level `tenant_id` (global query scope + tenant-resolution middleware), with hierarchy `tenant -> legal_entity -> department -> employee`. Two NEW tables introduced: `tenants`, `legal_entities`.

Classification legend:
- **TENANT** — add `tenant_id` (NOT NULL, indexed, FK -> tenants).
- **TENANT+ENTITY** — add `tenant_id` AND `legal_entity_id` (FK -> legal_entities); these are tied to a specific legal entity for payroll/tax/labor or org structure.
- **GLOBAL** — truly universal platform reference; left untouched.
- **PLATFORM-GLOBAL** — infra/auth platform tables; left untouched.

> The 13 `attendance_logs` partition children inherit the parent's columns; classification rows are collapsed under the parent `attendance_logs` with a note. Each is listed explicitly at the end of the table so every base table appears exactly once.

| table | classification | needs tenant_id | needs legal_entity_id | composite-unique changes needed | rationale |
|---|---|---|---|---|---|
| allowances | TENANT | yes | no | (tenant_id, allowance_code); (tenant_id, legacy_id) | Pay component catalog a company customizes. |
| api_tokens | TENANT | yes | no | keep token_hash GLOBAL unique (lookup key); no composite | Auth token for an employee who belongs to a tenant; isolate rows but token must be globally unique. |
| approval_flows | TENANT | yes | no | (tenant_id, legacy_id) | Workflow config customized per company. |
| approval_histories | TENANT | yes | no | (tenant_id, legacy_id) | Transactional child of requests; defense-in-depth isolation. |
| approval_roles | TENANT | yes | no | (tenant_id, role_code); (tenant_id, legacy_id) | Approval-routing roles customized per company. |
| approval_steps | TENANT | yes | no | (tenant_id, legacy_id) | Steps of a tenant's approval flow. |
| asset_assignments | TENANT | yes | no | (tenant_id, legacy_id) | Asset-to-employee transactional record. |
| asset_categories | TENANT | yes | no | (tenant_id, category_code); (tenant_id, legacy_id) | Company-customizable asset taxonomy. |
| asset_incidents | TENANT | yes | no | (tenant_id, legacy_id) | Transactional incident log. |
| asset_locations | TENANT+ENTITY | yes | yes | (tenant_id, location_code); (tenant_id, legacy_id) | Physical locations belong to a specific legal entity/branch (ties to department/org structure). |
| asset_maintenance | TENANT | yes | no | (tenant_id, legacy_id) | Transactional maintenance log per asset. |
| assets | TENANT+ENTITY | yes | yes | (tenant_id, asset_code); (tenant_id, legacy_id) | Fixed assets are booked/owned by a legal entity for accounting purposes. |
| attendances | TENANT+ENTITY | yes | yes | (tenant_id, legacy_id) | Attendance feeds payroll/labor reporting which is done at legal-entity level. |
| attendance_logs | TENANT+ENTITY | yes | yes | none (composite PK id,checked_at) | Raw punch log feeding attendance/payroll; entity-scoped for labor reporting. Partition children inherit. |
| banks | GLOBAL | no | no | none | Universal Vietnamese bank registry; identical for everyone. |
| certificate_types | TENANT | yes | no | (tenant_id, certificate_type_code); (tenant_id, legacy_id) | Company-customizable catalog. |
| certificates | TENANT | yes | no | (tenant_id, legacy_id) | Employee-owned record (per-employee, HR data). |
| contract_change_logs | TENANT | yes | no | (tenant_id, legacy_id) | Audit child of contracts. |
| contract_histories | TENANT | yes | no | (tenant_id, legacy_id) | Audit child of contracts. |
| contract_templates | TENANT | yes | no | (tenant_id, template_code); (tenant_id, legacy_id) | Document templates customized per company. |
| contract_types | TENANT | yes | no | (tenant_id, contract_type_code); (tenant_id, legacy_id) | Company-customizable catalog. |
| contracts | TENANT+ENTITY | yes | yes | (tenant_id, contract_number); (tenant_id, legacy_id) | Employment contract is signed with a specific legal entity (employer of record) for labor/tax. |
| dashboard_views | TENANT | yes | no | (tenant_id, legacy_id) | Per-employee UI tracking. |
| deductions | TENANT | yes | no | (tenant_id, deduction_code); (tenant_id, legacy_id) | Pay-component catalog customized per company. |
| departments | TENANT+ENTITY | yes | yes | (tenant_id, department_code); (tenant_id, legacy_id) | Org structure; a department belongs to one legal entity/branch. |
| dependents | TENANT | yes | no | (tenant_id, legacy_id) | Employee personal/tax dependents. |
| document_types | TENANT | yes | no | (tenant_id, document_type_code); (tenant_id, legacy_id) | Company-customizable catalog. |
| employee_allowances | TENANT | yes | no | (tenant_id, legacy_id) | Per-employee pay assignment. |
| employee_deductions | TENANT | yes | no | (tenant_id, legacy_id) | Per-employee pay assignment. |
| employee_roles | TENANT | yes | no | (tenant_id, legacy_id) | RBAC junction; defense-in-depth isolation. |
| employees | TENANT+ENTITY | yes | yes | (tenant_id, employee_code); (tenant_id, company_email); (tenant_id, legacy_id) | Core entity; an employee is employed by a specific legal entity for payroll/tax/SI registration. |
| employment_histories | TENANT | yes | no | (tenant_id, legacy_id) | Per-employee HR history. |
| holidays | TENANT | yes | no | (tenant_id, holiday_code); (tenant_id, legacy_id) | Company holiday calendar is customizable. |
| identity_documents | TENANT | yes | no | (tenant_id, legacy_id) | Employee personal documents. |
| insurance_claims | TENANT+ENTITY | yes | yes | (tenant_id, claim_code); (tenant_id, legacy_id) | Social-insurance claims are filed via the legal entity registered with the SI authority. |
| insurance_types | TENANT | yes | no | (tenant_id, insurance_type_code); (tenant_id, legacy_id) | Company-customizable catalog. |
| interview_schedules | TENANT | yes | no | (tenant_id, legacy_id) | Recruitment transactional record. |
| job_families | TENANT | yes | no | (tenant_id, code) | Job taxonomy customized per company (RISKY global unique on code). |
| leave_advancement_config | TENANT | yes | no | (tenant_id, legacy_id) | Leave policy config customized per company. |
| leave_advancement_requests | TENANT | yes | no | (tenant_id, legacy_id) | Transactional leave request. |
| leave_balances | TENANT | yes | no | (tenant_id, legacy_id) | Per-employee leave accrual. |
| leave_carryover_tracking | TENANT | yes | no | (tenant_id, legacy_id) | Per-employee leave accrual. |
| leave_requests | TENANT | yes | no | (tenant_id, legacy_id) | Transactional leave request. |
| leave_transactions | TENANT | yes | no | (tenant_id, legacy_id) | Per-employee leave ledger. |
| leave_types | TENANT | yes | no | (tenant_id, leave_type_code); (tenant_id, legacy_id) | Leave-type catalog is company-customizable. |
| news | TENANT | yes | no | (tenant_id, legacy_id) | Company internal communications. |
| news_categories | TENANT | yes | no | (tenant_id, category_code); (tenant_id, legacy_id) | Company-customizable taxonomy. |
| news_reads | TENANT | yes | no | (tenant_id, legacy_id) | Read-tracking junction; defense-in-depth. |
| notification_configs | TENANT | yes | no | (tenant_id, legacy_id) | Per-company notification settings. |
| notifications | TENANT | yes | no | (tenant_id, legacy_id) | Per-employee transactional notifications. |
| overtime_requests | TENANT | yes | no | (tenant_id, legacy_id) | Transactional request. |
| password_reset_requests | TENANT | yes | no | keep token GLOBAL unique; no composite | Tied to an employee/tenant; reset token must stay globally unique for lookup. |
| payroll_adjustments | TENANT+ENTITY | yes | yes | (tenant_id, legacy_id) | Payroll correction tied to a legal entity's payroll run. |
| permissions | TENANT | yes | no | (tenant_id, permission_code); (tenant_id, legacy_id) | RBAC vocabulary a company can customize; tenant-scoped (see OPEN QUESTIONS). |
| policies | TENANT | yes | no | (tenant_id, policy_code); (tenant_id, legacy_id) | Company HR policies. |
| policy_acknowledgments | TENANT | yes | no | (tenant_id, legacy_id) | Per-employee acknowledgment record. |
| positions | TENANT | yes | no | (tenant_id, position_code); (tenant_id, legacy_id) | Job positions customized per company (org definitions, not entity-specific). |
| qualification_types | TENANT | yes | no | (tenant_id, qualification_type_code); (tenant_id, legacy_id) | Company-customizable catalog. |
| qualifications | TENANT | yes | no | (tenant_id, legacy_id) | Per-employee HR record. |
| recruitment_ai_scoring_jobs | TENANT | yes | no | (tenant_id, legacy_id) | Recruitment processing record. |
| recruitment_candidate_cvs | TENANT | yes | no | (tenant_id, candidate_id) one CV per candidate; (tenant_id, legacy_id) | Recruitment artifact; isolate per tenant. |
| recruitment_candidate_manager_reviews | TENANT | yes | no | (tenant_id, legacy_id) | Recruitment transactional record. |
| recruitment_candidates | TENANT | yes | no | (tenant_id, legacy_id) | Recruitment data per company. |
| recruitment_positions | TENANT | yes | no | (tenant_id, legacy_id) | Job openings per company (org-level, see OPEN QUESTIONS re entity). |
| recruitment_rejected_archive | TENANT | yes | no | (tenant_id, legacy_id) | Recruitment archive. |
| report_histories | TENANT | yes | no | (tenant_id, legacy_id) | Per-company report executions. |
| report_templates | TENANT | yes | no | (tenant_id, template_code); (tenant_id, legacy_id) | Per-company report definitions. CONTAINS RAW SQL — must enforce tenant scoping at execution time (leak risk). |
| request_attachments | TENANT | yes | no | (tenant_id, legacy_id) | Transactional child of requests. |
| request_types | TENANT | yes | no | (tenant_id, request_type_code); (tenant_id, legacy_id) | Company-customizable request catalog. |
| requests | TENANT | yes | no | (tenant_id, legacy_id) | Transactional request root. |
| role_permissions | TENANT | yes | no | (tenant_id, legacy_id) | RBAC junction; defense-in-depth. |
| roles | TENANT | yes | no | (tenant_id, role_code); (tenant_id, legacy_id) | Roles customized per company (is_system_role baseline seeded per tenant). |
| salary_attendance_summary | TENANT+ENTITY | yes | yes | (tenant_id, legacy_id) | Payroll input summary tied to a legal entity's payroll period. |
| salary_breakdowns | TENANT+ENTITY | yes | yes | (tenant_id, legacy_id) | Line items of a legal-entity payslip. |
| salary_components | TENANT | yes | no | (tenant_id, code) | Pay-component catalog customized per company (RISKY global unique on code). |
| salary_details | TENANT+ENTITY | yes | yes | (tenant_id, legacy_id) | Payslip per employee per period; payroll is run at legal-entity level. |
| salary_periods | TENANT+ENTITY | yes | yes | (tenant_id, period_code); (tenant_id, legacy_id) | Payroll periods are opened/closed per legal entity. |
| seniority_leave_history | TENANT | yes | no | (tenant_id, legacy_id) | Per-employee leave accrual history. |
| service_categories | TENANT | yes | no | (tenant_id, category_code); (tenant_id, legacy_id) | Company-customizable taxonomy. |
| service_ticket_updates | TENANT | yes | no | (tenant_id, legacy_id) | Transactional child of tickets. |
| service_tickets | TENANT | yes | no | (tenant_id, ticket_code); (tenant_id, legacy_id) | Internal support tickets per company. |
| shift_assignments | TENANT+ENTITY | yes | yes | (tenant_id, legacy_id) | Shift/attendance scheduling feeds entity-level labor reporting. |
| shift_schedule_details | TENANT+ENTITY | yes | yes | (tenant_id, legacy_id) | Child of shift_schedules (entity-scoped via department). |
| shift_schedules | TENANT+ENTITY | yes | yes | (tenant_id, schedule_code); (tenant_id, legacy_id) | Department-bound schedules; departments belong to a legal entity. |
| shift_swaps | TENANT | yes | no | (tenant_id, legacy_id) | Transactional swap request. |
| shift_types | TENANT | yes | no | (tenant_id, shift_code); (tenant_id, legacy_id) | Shift definitions customized per company. |
| social_insurance_info | TENANT+ENTITY | yes | yes | (tenant_id, social_insurance_number); (tenant_id, tax_code); (tenant_id, legacy_id) | SI/tax registration is done through the employee's legal entity. |
| suppliers | TENANT | yes | no | (tenant_id, supplier_code); (tenant_id, legacy_id) | Company vendor list. |
| system_configs | TENANT | yes | no | (tenant_id, config_key); (tenant_id, legacy_id) | Per-tenant settings store. |
| users | PLATFORM-GLOBAL | no | no | keep email GLOBAL unique | 0 rows; default Laravel table. App auth runs via employees+api_tokens. Reserve as platform-admin table (see OPEN QUESTIONS). |
| nationalities | GLOBAL | no | no | none | Universal ISO-style nationality registry; identical for everyone. |
| cache | PLATFORM-GLOBAL | no | no | none | Infra cache store. |
| cache_locks | PLATFORM-GLOBAL | no | no | none | Infra lock store. |
| failed_jobs | PLATFORM-GLOBAL | no | no | keep uuid GLOBAL unique | Infra queue failures. |
| job_batches | PLATFORM-GLOBAL | no | no | none | Infra queue batches. |
| jobs | PLATFORM-GLOBAL | no | no | none | Infra queue. |
| migrations | PLATFORM-GLOBAL | no | no | none | Laravel migration ledger. |
| password_reset_tokens | PLATFORM-GLOBAL | no | no | none | Laravel default reset table (unused; app uses password_reset_requests). |
| sessions | PLATFORM-GLOBAL | no | no | none | Infra session store. |
| attendance_logs_2026_01 | TENANT+ENTITY | yes | yes | inherits parent | Partition child of attendance_logs. |
| attendance_logs_2026_02 | TENANT+ENTITY | yes | yes | inherits parent | Partition child of attendance_logs. |
| attendance_logs_2026_03 | TENANT+ENTITY | yes | yes | inherits parent | Partition child of attendance_logs. |
| attendance_logs_2026_04 | TENANT+ENTITY | yes | yes | inherits parent | Partition child of attendance_logs. |
| attendance_logs_2026_05 | TENANT+ENTITY | yes | yes | inherits parent | Partition child of attendance_logs. |
| attendance_logs_2026_06 | TENANT+ENTITY | yes | yes | inherits parent | Partition child of attendance_logs. |
| attendance_logs_2026_07 | TENANT+ENTITY | yes | yes | inherits parent | Partition child of attendance_logs. |
| attendance_logs_2026_08 | TENANT+ENTITY | yes | yes | inherits parent | Partition child of attendance_logs. |
| attendance_logs_2026_09 | TENANT+ENTITY | yes | yes | inherits parent | Partition child of attendance_logs. |
| attendance_logs_2026_10 | TENANT+ENTITY | yes | yes | inherits parent | Partition child of attendance_logs. |
| attendance_logs_2026_11 | TENANT+ENTITY | yes | yes | inherits parent | Partition child of attendance_logs. |
| attendance_logs_2026_12 | TENANT+ENTITY | yes | yes | inherits parent | Partition child of attendance_logs. |
| attendance_logs_default | TENANT+ENTITY | yes | yes | inherits parent | Default partition of attendance_logs. |

## Tables left GLOBAL / PLATFORM-GLOBAL and why

**GLOBAL reference (truly universal, identical for everyone — no tenant_id):**
- `banks` — national bank registry; banks are the same for every tenant.
- `nationalities` — ISO-style nationality list; universal.

> Note: anything a company can customize is NOT global. `leave_types`, `holidays`, `roles`, `departments`, `positions`, `approval_flows`, `news_categories`, `request_types`, `system_configs`, etc. are all tenant-scoped per the rules.

**PLATFORM-GLOBAL (infra/auth — left untouched):**
- `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`, `sessions`, `password_reset_tokens` — Laravel infra; no tenant column.
- `users` — Laravel default auth table, currently 0 rows. App authenticates through `employees` + `api_tokens`, so `users` is reserved as a future platform/super-admin table and kept global (email stays globally unique). If it is ever repurposed for tenant end-users, it must be reclassified TENANT with composite (tenant_id, email).

> `api_tokens` and `password_reset_requests` are auth but tied to `employees`, so they are TENANT-scoped (row isolation) while keeping their lookup tokens globally unique.

## OPEN QUESTIONS (defaults chosen; not blocking)

1. **users table** — Defaulted to PLATFORM-GLOBAL (super-admin/console). If the product later uses it for tenant end-user login, reclassify to TENANT with composite (tenant_id, email). Chosen default avoids touching an empty table.
2. **permissions** — Defaulted to TENANT (a company can customize its permission vocabulary). Alternative: treat as PLATFORM-GLOBAL RBAC vocabulary shared by all tenants with only role_permissions tenant-scoped. Chose tenant-scoped for stronger isolation and to allow per-tenant custom permissions.
3. **positions / job_families** — Defaulted to TENANT only (not TENANT+ENTITY). Job catalog is usually shared across a tenant's branches. If a regulated tenant needs per-entity position registries, promote to TENANT+ENTITY. (departments stay TENANT+ENTITY because org units physically belong to a branch.)
4. **recruitment_positions / recruitment_candidates** — Defaulted to TENANT only. Hiring is often centralized at the tenant. If a branch hires under its own legal entity, recruitment_positions could gain legal_entity_id; left tenant-scoped for v1.
5. **assets / asset_locations** — Defaulted to TENANT+ENTITY (assets booked per legal entity, locations physically belong to a branch). If a tenant pools assets centrally, legal_entity_id can be nullable. Chose entity-scoped to match accounting/branch ownership.
6. **api_tokens / password_reset_requests** — tenant_id added for isolation, but the unique token columns stay GLOBAL because lookups are by token alone (no tenant context at the reset/auth boundary).
7. **legacy_id composite uniques** — Recommended to make all ~85 `<table>_legacy_id_unique` composite (tenant_id, legacy_id) for cleanliness; non-blocking since legacy_id is NULL for new rows. Listed per-row above where the table is tenant-scoped.

## Counts

- TENANT (tenant_id only): see structured output.
- TENANT+ENTITY (tenant_id + legal_entity_id): includes employees, departments, contracts, attendances, attendance_logs (+13 children), salary_periods, salary_details, salary_breakdowns, salary_attendance_summary, payroll_adjustments, insurance_claims, social_insurance_info, shift_schedules, shift_schedule_details, shift_assignments, assets, asset_locations.
- GLOBAL: banks, nationalities.
- PLATFORM-GLOBAL: users, cache, cache_locks, failed_jobs, job_batches, jobs, migrations, password_reset_tokens, sessions.
- business_count (tables receiving tenant_id) = TENANT + TENANT+ENTITY.
