# Production Sync and Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the reorganized HRM source tree to `production`, rebuild the canonical local backend and AI Docker stacks without losing persistent data, and verify the exact production commit through GitHub CI/CD and the VPS public runtime.

**Architecture:** Keep commit `70c5b253` and subsequent delivery fixes on the existing `test` integration branch until all local gates pass, then fetch/rebase it onto the latest `origin/production`, fast-forward local `production`, and normal-push without force. Preserve PostgreSQL, Redis, Ollama, AI SQLite, environment, and certificate state while Docker Compose recreates containers from `BE/` and `ai/`; the GitHub `Deploy production` workflow is the only authorized VPS mutation path.

**Tech Stack:** Vue 3, Vite, Node.js 22, Playwright, Laravel 12, PHP 8.2, PostgreSQL 15, Redis, FastAPI, Python 3.11, Ollama, Docker Compose, GitHub Actions, CodeGraph.

**Spec:** `docs/superpowers/specs/2026-09-10-production-sync-delivery-design.md`

## Current Execution Checkpoint

- Current workspace: `/Users/vhozang/Downloads/HRM-System-main` on macOS.
- Current integration branch: `test` at `70c5b2537a8c0a19e43f128a47e56325f92224de`, tracking `origin/test`.
- `70c5b253` is a direct child of current `origin/production` at `dfcbc52004c9fa957535f1626362d72b00b20faf`; the reorganization checkpoint and all four named upstream fixes are already integrated.
- The former plan text that ended at branch `test` is obsolete. The authoritative goal and approved spec require final delivery to `production`.
- Existing Docker services use the canonical Compose project names but old source mounts under `Doan2_v2/Doan2` and `AutoRecruit-main`; they must be recreated from `BE/` and `ai/` while retaining data.
- The four specifically approved legacy containers `hrm-nginx`, `hrm-php`, `hrm-postgres`, and `hrm-redis` are currently absent on this host. Do not substitute other containers as cleanup targets.

## Global Constraints

- Never force-push (`--force`, `--force-with-lease`, or a `+` refspec).
- Never run `docker compose down --volumes`, `docker volume rm`, `docker system prune --volumes`, `migrate:fresh`, database reset, or destructive seed/import commands.
- Preserve every Docker named volume, especially `doan2_postgres_data`, `doan2_redis_data`, and `autorecruit-main_ollama_data`, plus the AI SQLite bind-mounted data.
- Never touch unrelated containers or projects, including `9router`.
- Remove only exact legacy containers `hrm-nginx`, `hrm-php`, `hrm-postgres`, and `hrm-redis` if they exist, are stopped, and have Compose project/working-directory labels matching the approved legacy stack. Otherwise skip cleanup.
- Do not print or commit `.env` contents, credentials, private keys, database files, Redis state, model data, dependencies, build output, CodeGraph databases, or browser profiles.
- Preserve VPS `/opt/hrm/BE/.env`, Docker volumes, database data, Redis data, and secrets. VPS changes occur only through `.github/workflows/deploy-production.yml`.
- Final commits must end with `Co-Authored-By: Claude Code <noreply@anthropic.com>`.
- A normal push to `production` and its resulting GitHub deployment are already authorized by the active goal; no other outward-facing deployment is authorized.

---

### Task 1: Reconcile the Integration Branch and Repository Artifacts

**Files:**
- Modify: `docs/superpowers/plans/2026-09-10-production-sync-delivery.md`
- Inspect: `.gitignore`
- Inspect: `.github/workflows/ci.yml`
- Inspect: `.github/workflows/deploy-production.yml`
- Inspect: `docs/operations/deploy/deploy-production.sh`

**Interfaces:**
- Consumes: approved spec and `origin/production` Git ref.
- Produces: recoverable integration ref whose ancestry and tracked contents are safe for production delivery.

- [ ] **Step 1: Refresh remote refs and record branch topology**

Run:
```bash
git fetch origin --prune
git branch -vv
git log --graph --decorate --oneline --all -20
```
Expected: `origin/production` is visible and no history rewrite is required when it is already an ancestor of `test`.

- [ ] **Step 2: Create a recoverable backup ref without changing the working tree**

Run:
```bash
git branch -f backup/pre-production-delivery-20260910 test
test "$(git rev-parse backup/pre-production-delivery-20260910)" = "$(git rev-parse test)"
```
Expected: comparison exits 0.

- [ ] **Step 3: Verify integration ancestry and upstream business commits**

Run:
```bash
git merge-base --is-ancestor origin/production test
for sha in dfcbc520 01ff9f27 45af8759 dc82f521; do
  git merge-base --is-ancestor "$sha" test
  printf '%s retained\n' "$sha"
done
```
Expected: every command exits 0.

- [ ] **Step 4: Audit untracked and tracked delivery files**

Run a metadata-only inventory with `git ls-files --others --exclude-standard`, reject any path collision with `origin/production`, and scan `origin/production...test` for `.env`, private keys, runtime databases, dependencies, build output, browser profiles, CodeGraph databases, or objects at/above 100 MiB. Do not read secret file contents.

Expected: no prohibited file is included in the delivery commit and untracked local artifacts remain untouched.

- [ ] **Step 5: Validate operational path references**

Run:
```bash
rg -n 'Doan2_v2|AutoRecruit-main|HRM_TestCases|D:\\HRM|/d/HRM' \
  .github BE ai FE docs/operations docs/test-cases \
  --glob '!**/node_modules/**' --glob '!**/vendor/**'
bash -n docs/operations/deploy/deploy-production.sh
bash -n docs/operations/deploy/provision-vps.sh
bash -n docs/resume/mineru-resume-local/scripts/*.sh
```
Expected: no executable configuration points at the old source tree; any historical prose is identified as non-executable; all scripts parse.

---

### Task 2: Synchronize CodeGraph and Run the Complete Local Gate Matrix

**Files:**
- Verify: `.codegraph/`
- Verify: `FE/`
- Verify: `BE/`
- Verify: `ai/`
- Verify: `FE/tools/zk-bridge/`

**Interfaces:**
- Consumes: reconciled `test` integration tree.
- Produces: executable evidence that frontend, backend, AI, and FE↔BE contracts are production-ready.

- [ ] **Step 1: Synchronize and query the workspace-local CodeGraph**

Run:
```bash
codegraph sync /Users/vhozang/Downloads/HRM-System-main
codegraph status /Users/vhozang/Downloads/HRM-System-main
```
Then query the deployment/Compose paths and the upstream-changed recruitment, attendance, approvals, organization, resource-guard, and shift flows.

Expected: index is current and no moved-path drift is found.

- [ ] **Step 2: Install frontend dependencies exactly as CI does**

Run:
```bash
npm --prefix FE ci --no-audit --no-fund
npm --prefix FE ci --prefix tools/zk-bridge --no-audit --no-fund
```
If npm rejects the nested prefix form, run `npm ci --prefix FE/tools/zk-bridge --no-audit --no-fund`.

Expected: both lockfile installs exit 0.

- [ ] **Step 3: Run all frontend regressions and the production build**

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
npm --prefix FE run test:endpoints
npm --prefix FE run test:auth-browser
VITE_API_BASE_URL=/api/v1 npm --prefix FE run build
npm test --prefix FE/tools/zk-bridge
```
Expected: every command exits 0, Playwright reports all auth tests passed, endpoint audit reports no missing contract, and `FE/dist/index.html` exists.

- [ ] **Step 4: Validate and test Laravel with the supported runtime**

Run:
```bash
docker compose -f BE/docker-compose.yml run --rm --no-deps php composer validate --strict
docker compose -f BE/docker-compose.yml run --rm --no-deps \
  -e APP_ENV=testing \
  -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=:memory: \
  php php artisan test --without-tty
docker compose -f BE/docker-compose.yml run --rm --no-deps php php artisan route:list --json
```
Expected: Composer is strictly valid, the complete Laravel suite has zero failures, and the route JSON command exits 0.

- [ ] **Step 5: Test AutoRecruit and validate Compose**

Run:
```bash
docker compose -f ai/compose.yaml run --rm --no-deps \
  -e MINERU_URL= backend python -m unittest discover -s tests -p 'test_*.py'
docker compose -f BE/docker-compose.yml config --quiet
docker compose -f ai/compose.yaml config --quiet
```
Expected: all AI tests pass and both Compose configurations validate.

- [ ] **Step 6: Debug any failure systematically**

For each failure, capture the exact failing command/output, invoke `superpowers:systematic-debugging`, make the smallest repository-scoped correction, rerun the failing test, then rerun its complete subsystem gate. Do not weaken a test or CI/deployment control.

---

### Task 3: Preserve Data and Rebuild the Canonical Docker Stacks

**Files:**
- Use: `BE/docker-compose.yml`
- Use: `ai/compose.yaml`
- Preserve locally: ignored `BE/.env`, certificate/runtime state, `ai/data/`, and named volumes.

**Interfaces:**
- Consumes: passing source tree and currently running old-path containers.
- Produces: canonical `doan2` and `autorecruit-main` containers sourced from `BE/` and `ai/` with before/after continuity evidence.

- [ ] **Step 1: Capture non-secret pre-rebuild evidence**

Record container IDs/status/Compose labels/working directories, named volume names and mountpoints, PostgreSQL database identity plus safe core-table counts, Redis `DBSIZE`, AI SQLite path/hash/table counts, and Ollama volume identity/model names. Record only metadata and counts, never row values or secrets.

Expected: evidence identifies the exact persistent resources that must survive.

- [ ] **Step 2: Preserve ignored local runtime files at the new paths**

Check only file presence/permissions for `BE/.env`, certificate directories, and `ai/data`. If an old-path runtime file is required and the equivalent new-path file is absent, copy it locally with permissions preserved; never print it, stage it, or copy it to the VPS. For AI data, copy missing files from `AutoRecruit-main/data/` into `ai/data/` without deleting either source or destination, then compare hashes/counts.

Expected: new source paths can start against the same local runtime state.

- [ ] **Step 3: Apply only the approved legacy-container cleanup rule**

Inspect the exact names `hrm-nginx`, `hrm-php`, `hrm-postgres`, and `hrm-redis`. Remove an exact target only when it exists, is stopped, and its labels match the approved legacy Compose stack. Since the current inventory has none of these names, record cleanup as not applicable and do not remove similarly named canonical containers manually.

Expected: no unrelated container or volume is removed.

- [ ] **Step 4: Rebuild and recreate the backend stack from `BE/`**

Run:
```bash
docker compose -f BE/docker-compose.yml up -d --build --remove-orphans
```
Expected: Compose recreates `doan2` services with working-directory labels rooted at `BE/`, without deleting named volumes.

- [ ] **Step 5: Rebuild and recreate the AI stack from `ai/`**

Run:
```bash
docker compose -f ai/compose.yaml up -d --build backend ollama
```
Expected: `resume-backend` and `ollama` use the `ai/` Compose project definition and become running without replacing `autorecruit-main_ollama_data`.

- [ ] **Step 6: Verify health, mounts, and data continuity**

Run container/Compose status checks, inspect project working-directory labels and mounts, wait for PostgreSQL/Redis health, then check:

```bash
curl -fsS http://localhost/api/v1/health
curl -fsS http://127.0.0.1:8000/health
```

Repeat the same PostgreSQL counts, Redis `DBSIZE`, AI SQLite hash/table-count inventory, Ollama volume/model inventory, and volume inspection from Step 1.

Expected: services are healthy; source mounts point at `BE/`, `FE/`, or `ai/`; persistent resource IDs match; core row/model counts are not reduced; AI data matches the preserved new-path copy.

---

### Task 4: Review the Delivery and Prepare `production`

**Files:**
- Review: all paths in `git diff origin/production...test`
- Modify only if required by failed gates or review findings.

**Interfaces:**
- Consumes: passing local gates and rebuilt canonical runtime.
- Produces: reviewed, clean, latest-upstream-based `production` branch ready for normal push.

- [ ] **Step 1: Run repository integrity checks**

Run:
```bash
git diff --check
git fsck --no-progress
git status --short --branch
```
Repeat prohibited-path, secret-sensitive metadata, and oversized-object scans. Leave unrelated untracked files untouched and unstaged.

Expected: no integrity or whitespace errors and no prohibited staged/tracked delivery file.

- [ ] **Step 2: Request independent code review**

Invoke `superpowers:requesting-code-review` and review the complete `origin/production...test` diff for source-tree path drift, lost upstream fixes, CI/deploy safety, data-preservation risk, and runtime regressions. Apply and retest confirmed findings.

Expected: no unresolved material finding.

- [ ] **Step 3: Commit repository-scoped delivery corrections**

Stage only intentional tracked source/configuration/plan changes and commit with the required trailer. Never use `git add -A` while local untracked artifacts are present.

- [ ] **Step 4: Refresh and integrate latest production without rewriting the remote**

Run:
```bash
git fetch origin production
if ! git merge-base --is-ancestor origin/production test; then
  git rebase origin/production test
fi
git switch production
git merge --ff-only test
```
Resolve any rebase conflict by preserving upstream business behavior at the new `FE/`/`BE/` path, rerun affected gates, and never force push.

Expected: `production` is a direct descendant of the latest `origin/production` and contains the reviewed integration commits.

- [ ] **Step 5: Run the final verification gate before push**

Invoke `superpowers:verification-before-completion`; rerun all commands needed to prove ancestry, clean intended diff, builds/tests, Compose validity, container health/data continuity, and exact local SHA.

Expected: every required local acceptance criterion has fresh evidence.

---

### Task 5: Normal-Push `production` and Monitor Exact-SHA CI/CD

**Interfaces:**
- Consumes: verified local `production` SHA.
- Produces: the same SHA on `origin/production` with successful `CI` and `Deploy production` workflow runs.

- [ ] **Step 1: Recheck remote immediately before push**

Run:
```bash
git fetch origin production
test "$(git rev-parse origin/production)" = "$(git merge-base origin/production HEAD)"
```
If the check fails, rebase and rerun affected gates before proceeding.

- [ ] **Step 2: Push normally and verify remote equality**

Run:
```bash
git push origin production
test "$(git rev-parse HEAD)" = "$(git ls-remote origin refs/heads/production | cut -f1)"
```
Expected: standard push succeeds and remote SHA equals local SHA.

- [ ] **Step 3: Find workflow runs for the exact SHA**

Use `gh run list`/GitHub API filtered by branch `production` and the pushed SHA. Capture the run IDs and URLs for both `CI` and `Deploy production`.

Expected: both workflows reference the exact pushed SHA.

- [ ] **Step 4: Follow both workflows to terminal state**

Use `gh run watch <run-id> --exit-status` or equivalent API polling. If a repository-caused run fails, inspect logs, invoke systematic debugging, reproduce locally, fix, rerun the affected/full gates, commit, normal-push again, and monitor the new exact SHA. Do not bypass CI or manually deploy around it.

Expected: both exact-SHA workflows conclude `success`; otherwise preserve the precise external blocker as unresolved.

---

### Task 6: Verify VPS Runtime and Commit Equivalence

**Interfaces:**
- Consumes: successful exact-SHA deployment workflow.
- Produces: post-deployment public evidence and the strongest authorized VPS equivalence proof.

- [ ] **Step 1: Verify frontend and discover a deployed static asset**

Fetch `https://devtapcode.io.vn/`, require HTTP 200, parse one current `/assets/...` URL from returned HTML, fetch that exact asset, and require HTTP 200 with non-zero length.

- [ ] **Step 2: Verify API health and a safe API route**

Run:
```bash
curl -fsS -D - https://devtapcode.io.vn/api/v1/health
curl -fsS -D - https://devtapcode.io.vn/api/v1
```
Expected: health succeeds and the safe root route returns the documented API response without authentication bypass.

- [ ] **Step 3: Establish commit equivalence**

Prove `origin/production` equals the successful CI/deploy workflow `head_sha`. If approved local SSH authentication already works, compare `/opt/hrm` `HEAD` and non-secret container/data metadata directly. If SSH authentication is unavailable, do not weaken controls; state that direct filesystem SHA verification is access-limited and use exact workflow SHA plus post-deploy public runtime evidence.

- [ ] **Step 4: Record final review and verification evidence**

Write workspace-local reports under:

```text
docs/reviews/2026-09-10-production-sync-delivery.md
docs/verification/2026-09-10-production-sync-delivery.md
```

Include commands, exit results/counts, before/after persistent-resource evidence, commit SHA, workflow URLs/statuses, public endpoint/asset evidence, and any access limitation. Exclude secrets, private data, and credentials.

- [ ] **Step 5: Evaluate the active goal**

Declare completion only if every acceptance criterion in `.claude/goals/production-sync-build-deploy.md` has evidence. Any failed workflow, unhealthy service, data-continuity gap, or missing required access remains explicitly unresolved and keeps the goal active.
