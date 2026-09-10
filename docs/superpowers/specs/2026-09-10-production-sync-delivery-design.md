# Production Sync and Delivery Design

**Date:** 2026-09-10
**Status:** Approved in conversation; awaiting written-spec review
**Target branch:** `production`
**Repository:** `https://github.com/vHozang/BEHRM.git`

## 1. Objective

Deliver the reorganized HRM source tree to the `production` branch, rebuild and verify the canonical Docker stacks locally, allow the existing GitHub Actions pipeline to deploy the same commit to the VPS, and prove that the public production application is healthy without changing HRM business behavior.

The final repository layout remains:

```text
D:\HRM\
├── FE/
├── BE/
├── ai/
├── docs/
├── .claude/
├── .codegraph/
├── .github/
├── .vscode/
├── .git/
└── .gitignore
```

## 2. Current State and Constraints

- Local branch `production` is four commits behind `origin/production` and has no local commit ahead before this delivery task.
- The four upstream commits contain business fixes in recruitment, attendance, approvals, organization charts, resource guards, shifts, and related FE screens/tests.
- The local working tree contains the approved source-tree reorganization and path/configuration fixes.
- CodeGraph is workspace-local at `D:\HRM\.codegraph` and must remain aligned with the final source.
- The public production endpoints currently respond successfully, but they run the pre-reorganization deployment.
- Direct SSH as `deloy@180.93.42.137` is not available from this local session because the GitHub Actions deployment key is not present locally. The deployment workflow has its own GitHub secret.
- No secret, `.env`, database file, dependency directory, build output, CodeGraph database, or browser cache/profile may be committed.
- No force push, database reset, `migrate:fresh`, or Docker-volume deletion is allowed.

## 3. Selected Git Integration Strategy

Use **checkpoint plus rebase**:

1. Refresh remote refs and verify `origin/production` has not changed unexpectedly.
2. Create a local backup branch pointing to the pre-integration state.
3. Correct remaining source-tree path issues and repository ignore rules.
4. Keep meaningful test artifacts (scripts, workbooks, JSON results, screenshots) but exclude generated browser profiles/caches and files exceeding GitHub's 100 MiB object limit.
5. Create a checkpoint commit for the reorganization.
6. Rebase that commit onto the latest `origin/production`.
7. Resolve rename/modify conflicts by preserving all upstream business fixes and expressing them at the new `FE/` and `BE/` paths.
8. Confirm the result is a direct descendant of `origin/production` and use a normal push only.

A merge commit and force push are explicitly excluded.

## 4. Business-Behavior and CodeGraph Integrity

The source-tree change must not alter business behavior. Verification consists of:

- Syncing CodeGraph after integration and requiring its index to report up to date.
- Re-running CodeGraph preflight over FE ↔ BE ↔ AI integration boundaries and the upstream-changed business areas.
- Checking that all four upstream commits are ancestors of final `HEAD`.
- Running FE regression tests, endpoint-contract audit, Playwright auth tests, the ZK bridge test, the full Laravel suite, AutoRecruit unit tests, Compose validation, and production build.
- Confirming Laravel route inventory and FE API call inventory remain contract-compatible.
- Reviewing all rebase conflict resolutions for accidental deletion or regression of upstream fixes.

CodeGraph is evidence of architecture/call-path consistency, while executable tests remain the correctness gate.

## 5. Repository Artifact Policy

Commit:

- `FE/`, `BE/`, and `ai/` source and lockfiles.
- `docs/` project documentation, test scripts, workbooks, reports, JSON results, and meaningful lightweight evidence.
- `.github` workflows, `.vscode` settings, `.claude` workspace goal/spec metadata, root `.gitignore`, and `.codegraph/.gitignore`.

Do not commit:

- `.codegraph/codegraph.db` or transient CodeGraph files.
- `FE/node_modules`, `FE/dist`, Playwright reports/results.
- `BE/.env`, `BE/vendor`, caches, logs, runtime uploads, generated PDFs, or database dumps not already deliberately tracked.
- `ai/data/*.db`, Python caches, virtual environments, models, or generated training output.
- Browser profiles and component caches under `docs/test-cases/production_ui_evidence`.
- Any file over GitHub's 100 MiB limit unless an existing intentional LFS policy is present (none is currently planned).

Generated browser evidence remains available locally where useful but is ignored by Git.

## 6. Docker Local Design

### 6.1 Canonical stacks

- Backend stack: Compose project `doan2`, configuration `BE/docker-compose.yml`.
- AI stack: Compose project `autorecruit-main`, configuration `ai/compose.yaml`.
- Shared network: `doan2_hrm_network`.

### 6.2 Data preservation

Preserve these named volumes:

- `doan2_postgres_data`
- `doan2_redis_data`
- `doan2_mineru_output`
- `autorecruit-main_ollama_data`

Record volume/container state before cleanup. Never pass `--volumes` to teardown commands.

### 6.3 Cleanup and rebuild

- Remove the four stopped, duplicate HRM-named containers owned by the unrelated legacy Compose project at `D:\php`: `hrm-nginx`, `hrm-php`, `hrm-postgres`, and `hrm-redis`.
- Preserve all volumes belonging to that legacy project.
- Do not touch unrelated running container `9router`.
- Recreate canonical HRM containers from the new configuration and source mounts.
- Build images without relying on stale source paths.
- Confirm every HRM container has one canonical name, the correct Compose project label, and mounts rooted in `D:\HRM\BE`, `D:\HRM\FE`, or `D:\HRM\ai`.

### 6.4 Local runtime gates

- PostgreSQL and Redis healthy.
- Laravel PHP, worker, scheduler, Reverb, and Nginx running.
- Local frontend and `/api/v1/health` return success.
- AutoRecruit health returns success when its profile is started.
- Persistent database data remains present after recreation.

## 7. CI/CD Design

Before push:

- Validate workflow YAML and deployment shell syntax.
- Execute the same commands as `.github/workflows/ci.yml` locally, including clean dependency installations where practical.
- Validate paths in `.github/workflows/ci.yml` and `.github/workflows/deploy-production.yml` against `FE/`, `BE/`, `ai/`, and `docs/operations/deploy/`.

After normal push:

1. Capture the pushed commit SHA.
2. Observe both `CI` and `Deploy production` workflow runs for that exact SHA until terminal status.
3. If a workflow fails due to repository code/configuration, inspect logs, reproduce locally, fix, rerun all affected checks, commit, and push normally again.
4. Do not bypass or disable CI/deployment gates.

GitHub CLI is not installed locally. Monitoring may use authenticated/public GitHub APIs, workflow URLs, or installation of a suitable client only if necessary and permitted. Private logs requiring authentication may need the user to authenticate interactively.

## 8. VPS Deployment and Equivalence

The deployment workflow is the authorized mechanism for VPS mutation. It must:

- Checkout/reset `/opt/hrm` to the exact pushed `production` commit.
- Build the FE from `FE/` and deploy its archive into `/opt/hrm/FE/dist`.
- Run production deployment from `docs/operations/deploy/deploy-production.sh`.
- Operate Laravel from `/opt/hrm/BE`.
- Apply forward-only migrations using `php artisan migrate --force`.
- Preserve VPS `.env`, Docker volumes, database data, and secrets.

Equivalence evidence:

- The successful deploy workflow must reference the same SHA as `origin/production`.
- If workflow output exposes the remote SHA, compare it directly.
- If local SSH remains unavailable, do not bypass SSH controls; use workflow success plus public endpoint and asset checks as the verification boundary.
- Verify `https://devtapcode.io.vn/api/v1/health`, the frontend root, at least one emitted static asset, and a safe API route after deploy.
- Confirm responses are produced after the successful deployment timestamp and no stale path remains in workflow configuration.

Runtime environment files and database contents are intentionally environment-specific and are not expected to byte-match local state.

## 9. Failure Handling and Rollback

- If upstream changes again before push, fetch and rebase again, then rerun affected verification.
- If conflict resolution could materially change business behavior, stop and request user approval.
- If Docker rebuild fails, preserve volumes, diagnose configuration/image issues, fix, and recreate only affected services.
- If CI fails, do not deploy manually around it.
- If deployment fails before health checks pass, inspect the workflow logs and fix forward. Do not reset or erase production data.
- If direct VPS access is required but unavailable, report the exact blocker and ask the user to provide/authenticate the approved access method rather than weakening security.

## 10. Acceptance Criteria

1. Final `HEAD` contains all four upstream production fixes and the approved reorganization.
2. CodeGraph is synchronized and the architecture/business-boundary audit finds no drift.
3. No prohibited runtime/generated/oversized files are included in the commit.
4. The complete local build and test matrix passes.
5. Only one canonical local HRM container stack remains; the four approved legacy `D:\php` containers are removed while all volumes are preserved.
6. The canonical local Docker stack builds, starts, and passes health checks from the new paths.
7. A normal commit and normal push to `production` succeed; no force push is used.
8. `CI` succeeds for the exact pushed SHA.
9. `Deploy production` succeeds for the exact pushed SHA.
10. Public frontend, static asset, and API health checks pass after deploy.
11. Final `origin/production` SHA equals the deployed workflow SHA; VPS source equivalence is established to the strongest level available without bypassing SSH authentication.
12. No commit contains secrets and no Docker/VPS volume is deleted.
