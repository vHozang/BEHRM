# Production Sync and Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Commit the reorganized source tree (`FE/`, `BE/`, `ai/`, `docs/`) with all upstream production bug fixes preserved, clean legacy Docker containers, rebuild local containers without data loss, and push to branch `test` on GitHub, verifying CI/CD and public health.

**Architecture:** The project is structured into `FE/` (Vue 3/Vite/Tailwind), `BE/` (Laravel 12 API), `ai/` (AutoRecruit FastAPI/Ollama/MinerU), `docs/` (consolidated documentation, test cases, and diagrams), and root configuration (`.github/`, `.vscode/`, `.claude/`, `.gitignore`). Rebase onto `origin/production` preserves the four recent business fixes while mapping them to the new tree structure.

**Tech Stack:** Vue 3, Vite, Tailwind CSS, Laravel 12, PHP 8.2, FastAPI, Python 3.11, Docker Compose, PostgreSQL 15, Redis, Playwright, CodeGraph, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-10-production-sync-delivery-design.md`

## Global Constraints

- Never force-push (`--force` or `+ref`). Use standard push only.
- Never delete or recreate Docker named volumes (`doan2_postgres_data`, `doan2_redis_data`, `doan2_mineru_output`, `autorecruit-main_ollama_data`).
- Never touch unrelated containers like `9router`.
- Remove only the 4 explicitly approved legacy containers: `hrm-nginx`, `hrm-php`, `hrm-postgres`, `hrm-redis` belonging to project `php` (`D:\php`).
- Never commit secrets, `.env`, local `.db` SQLite files, `node_modules`, `vendor`, `dist`, or browser profile directories.
- All commits must include trailer `Co-Authored-By: Claude Code <noreply@anthropic.com>`.

---

### Task 1: Create Backup and Prepare `test` Branch

**Files:**
- Modify: `.gitignore`
- Modify: `docs/test-cases/*.py`, `docs/test-cases/*.ps1`
- Create branch: `test`
- Create backup ref: `backup/pre-test-delivery-20260910`

- [ ] **Step 1: Create a safety backup branch of the current local state**

Run: `git branch backup/pre-test-delivery-20260910`
Verify: `git rev-parse backup/pre-test-delivery-20260910` matches current HEAD.

- [ ] **Step 2: Create and checkout the `test` branch**

Run: `git checkout -b test`
Verify: `git branch --show-current` outputs `test`.

- [ ] **Step 3: Verify `.gitignore` excludes large browser profiles and caches**

Run: `python -c "from pathlib import Path; import subprocess; raw=subprocess.check_output(['git','ls-files','--others','--exclude-standard']); files=raw.decode('utf-8').splitlines(); print(len(files)); assert not any('profile' in f for f in files); assert not any('component_crx_cache' in f for f in files)"`
Expected: 0 profile files detected in untracked list.

- [ ] **Step 4: Verify test script path fixes**

Run: `rg "HRM_TestCases" docs/test-cases/`
Expected: No matches (or only historical log strings).

---

### Task 2: Commit Reorganization Checkpoint and Rebase Upstream Fixes

**Files:**
- Commit staged reorganization tree
- Rebase onto `origin/production`
- Resolve any merge conflicts in `BE/` and `FE/`

- [ ] **Step 1: Stage all reorganized files and deletions**

Run: `git add -A`
Check: `git status --short | grep '^[ MADRC]' | head -20`

- [ ] **Step 2: Ensure no forbidden files are staged**

Run: `git diff --cached --name-only | grep -E '(\.env|\.db|node_modules|vendor|dist|cdp-profile|interactive-profile|\.codegraph/.*\.db)' || true`
Expected: Empty output (no forbidden files staged).

- [ ] **Step 3: Commit the source-tree reorganization**

Run:
```bash
git commit -m "refactor: restructure project into FE, BE, ai, and docs

- Move Vue 3 client to FE/ with root-level Vite and Tailwind configs
- Move Laravel API to BE/ with dedicated docker-compose
- Move AutoRecruit to ai/ with standalone service compose
- Consolidate diagrams, test cases, and reports into docs/
- Exclude ephemeral browser test profiles from version control

Co-Authored-By: Claude Code <noreply@anthropic.com>"
```

- [ ] **Step 4: Fetch latest `origin/production`**

Run: `git fetch origin production`
Verify: `git rev-parse origin/production` is `dfcbc52004c9fa957535f1626362d72b00b20faf`.

- [ ] **Step 5: Rebase `test` branch onto `origin/production`**

Run: `git rebase origin/production`
If conflicts occur:
1. For files modified in `Doan2_v2/Doan2/...`, apply the upstream change to `BE/...`.
2. For files modified in `client/...`, apply the upstream change to `FE/...`.
3. For deleted paths, ensure files live only at new destinations.
4. Run `git add -u` and `git rebase --continue`.

- [ ] **Step 6: Verify commit history contains upstream commits**

Run: `git log -6 --oneline`
Expected: `dfcbc520`, `01ff9f27`, `45af8759`, `dc82f521` are in ancestry.

---

### Task 3: CodeGraph Synchronization and Architecture Verification

**Files:**
- Workspace CodeGraph: `D:\HRM\.codegraph\`

- [ ] **Step 1: Run CodeGraph sync on the updated tree**

Run: `"/c/Users/vHozang/AppData/Local/codegraph/current/node.exe" "/c/Users/vHozang/AppData/Local/codegraph/current/lib/dist/bin/codegraph.js" sync /d/HRM`
Expected: Sync completed with 0 errors.

- [ ] **Step 2: Verify CodeGraph index status**

Run: `"/c/Users/vHozang/AppData/Local/codegraph/current/node.exe" "/c/Users/vHozang/AppData/Local/codegraph/current/lib/dist/bin/codegraph.js" status /d/HRM`
Expected: Output includes "Index is up to date".

---

### Task 4: Complete Local Build and Automated Test Suite

**Files:**
- Test `FE/`
- Test `BE/`
- Test `ai/`
- Test `FE/tools/zk-bridge/`

- [ ] **Step 1: Run Frontend production build**

Run: `npm --prefix FE run build`
Expected: Vite build outputs `dist/index.html` successfully.

- [ ] **Step 2: Run Frontend regression and unit tests**

Run:
```bash
npm --prefix FE run test:csv
npm --prefix FE run test:lookup-cache
npm --prefix FE run test:recruitment
npm --prefix FE run test:employee-self-service
npm --prefix FE run test:management-ui
npm --prefix FE run test:money-input
npm --prefix FE run test:payslips
npm --prefix FE run test:attendance-overtime
npm --prefix FE run test:user-role
npm --prefix FE run test:shift-roster
npm --prefix FE run test:organization-chart
```
Expected: All scripts pass.

- [ ] **Step 3: Run Endpoint contract audit**

Run: `npm --prefix FE run test:endpoints`
Expected: `Endpoint contract audit passed: 315 Laravel routes, 348 frontend calls, 74 generic resources.`

- [ ] **Step 4: Run Playwright auth tests**

Run: `npm --prefix FE run test:auth-browser`
Expected: 4 passed.

- [ ] **Step 5: Run ZK bridge tests**

Run: `npm test --prefix FE/tools/zk-bridge`
Expected: `bridge state and delay tests passed`.

- [ ] **Step 6: Validate Composer and run Laravel tests**

Run:
```bash
docker compose -f BE/docker-compose.yml run --rm --no-deps php composer validate --strict
docker compose -f BE/docker-compose.yml run --rm --no-deps php php artisan test --without-tty
```
Expected: Composer valid, all 227 tests pass.

- [ ] **Step 7: Run AutoRecruit unit tests**

Run:
```bash
MSYS_NO_PATHCONV=1 docker compose -f ai/compose.yaml run --rm --no-deps -v D:/HRM/ai:/workspace -w /workspace backend python -m unittest discover -s tests -p 'test_*.py'
```
Expected: Ran 8 tests, OK.

- [ ] **Step 8: Validate Docker Compose configurations**

Run:
```bash
docker compose -f BE/docker-compose.yml config --quiet
docker compose -f ai/compose.yaml config --quiet
```
Expected: Zero errors.

---

### Task 5: Clean Duplicate Legacy Docker Containers and Recreate Canonical Stack

**Containers:**
- Remove: `hrm-nginx`, `hrm-php`, `hrm-postgres`, `hrm-redis` (from project `php` at `D:\php`)
- Rebuild/start: Canonical project `doan2` (`BE/docker-compose.yml`)

- [ ] **Step 1: Inspect legacy containers to confirm they are safe to remove**

Run: `docker ps -a --filter "name=hrm-" --format "{{.ID}}\t{{.Names}}\t{{.Status}}\t{{.Labels}}"`
Verify: Target 4 containers are exited and belong to project `php`.

- [ ] **Step 2: Remove the 4 duplicate legacy containers**

Run: `docker rm hrm-nginx hrm-php hrm-postgres hrm-redis`
Expected: Successfully removed container IDs.

- [ ] **Step 3: Verify named volumes were NOT removed**

Run: `docker volume ls | grep -E '(doan2_postgres_data|doan2_redis_data|php_pgdata)'`
Expected: All volumes still exist intact.

- [ ] **Step 4: Recreate and start canonical `doan2` stack**

Run: `cd /d/HRM/BE && docker compose up -d --build --remove-orphans`
Expected: `hrm_postgres`, `hrm_redis`, `hrm_laravel_php`, `hrm_laravel_nginx`, `hrm_laravel_reverb` are running.

- [ ] **Step 5: Verify container health and data integrity**

Run:
```bash
docker compose -f /d/HRM/BE/docker-compose.yml ps
docker compose -f /d/HRM/BE/docker-compose.yml exec -T php php artisan --version
docker compose -f /d/HRM/BE/docker-compose.yml exec -T php php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo "Employees count: " . Illuminate\Support\Facades\DB::table("employees")->count() . "\n";'
```
Expected: Employees count > 0 (database data verified intact).

- [ ] **Step 6: Verify local HTTP health**

Run: `curl -fsS http://localhost/api/v1/health`
Expected: `{"status":200,"message":"HRM API is healthy",...}`

---

### Task 6: Push to `test` Branch and Monitor CI/CD

- [ ] **Step 1: Verify git status is clean before push**

Run: `git status`
Expected: Working tree clean (or only untracked local tools).

- [ ] **Step 2: Push `test` branch to origin**

Run: `git push -u origin test`
Expected: Push succeeds and sets upstream to `origin/test`.

- [ ] **Step 3: Inspect GitHub Actions workflow run for branch `test`**

Run:
```bash
curl -fsS "https://api.github.com/repos/vHozang/BEHRM/actions/runs?branch=test&per_page=5" | python -c "import sys,json; d=json.load(sys.stdin); [print(r['id'], r['name'], r['status'], r['conclusion'], r['html_url']) for r in d.get('workflow_runs',[])]"
```
Expected: `CI` workflow triggers for the pushed SHA.

- [ ] **Step 4: Poll CI run until completion**

Wait and poll until status is `completed` with conclusion `success`.
If CI fails, inspect run logs via API, resolve locally, commit, and push to `test` again.

---

### Task 7: Production Health and Verification

- [ ] **Step 1: Check public production health endpoints**

Run:
```bash
curl -fsS https://devtapcode.io.vn/api/v1/health
curl -fsSI https://devtapcode.io.vn/
```
Expected: Both return HTTP 200.

- [ ] **Step 2: Report final summary**

Summarize the commit SHA on `test` branch, test results, Docker container cleanup status, and CI run links.
