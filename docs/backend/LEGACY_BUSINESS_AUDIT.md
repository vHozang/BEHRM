# Legacy Business Audit

Source backup: `D:\Doan2_legacy_backup_20260524_231501`

## Business Modules Found

- Core HR master data: employees, departments, positions, nationalities, banks.
- Employee profile: qualifications, certificates, identity documents, social insurance, dependents, employment histories.
- Contracts: contract types, templates, contracts, histories, change logs.
- Assets: categories, suppliers, locations, assets, assignments, incidents, maintenance.
- Request workflow: request types, approval flows, approval steps, requests, approval histories, attachments.
- Leave: leave types, holidays, balances, seniority history, advancement config/requests, carryover tracking, transactions.
- Attendance: shift types, schedules, schedule details, assignments, swaps, attendances, overtime requests.
- Payroll and insurance: insurance types/claims, allowances, deductions, employee allowances/deductions, salary periods, attendance summaries, details, breakdowns, adjustments.
- Communication/governance: news categories, news, reads, policies, acknowledgments, notification configs, notifications, dashboard views.
- IAM/reporting/settings: roles, permissions, role permissions, employee roles, report templates/histories, system configs.
- Recruitment/internal service extensions: recruitment positions/candidates/CVs/interviews/manager reviews/AI jobs/rejected archive, service categories/tickets/updates.

## Database Comparison

- Legacy `SQL_hackathon v4.sql` contains 81 business tables.
- Before this audit pass, PostgreSQL migrations covered 56 tables.
- Missing legacy tables were added in `2026_05_24_010000_create_legacy_gap_tables.php`.
- `data.sql` inserts into 50 tables; all table names referenced by `data.sql` now have PostgreSQL destination tables.

## Important Difference

The PostgreSQL schema is not a byte-for-byte MySQL clone. It follows the rewrite decision:

- primary keys are Laravel-style `id`;
- old source IDs are stored as nullable `legacy_id`;
- MySQL `ENUM` values are represented as strings;
- flexible legacy/external fields can use `jsonb meta`;
- legacy import commands copy matching columns and keep the source ID in `legacy_id`.

## Import Order

Run the commands in this order after loading the old SQL files into a temporary MySQL database:

```bash
php artisan legacy:import-master-data
php artisan legacy:import-employees
php artisan legacy:import-contracts
php artisan legacy:import-requests
php artisan legacy:import-attendance-leave
php artisan legacy:import-payroll
php artisan legacy:import-recruitment
php artisan legacy:import-communications
php artisan legacy:verify
```
