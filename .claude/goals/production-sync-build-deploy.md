# AUTHORITATIVE GOAL: PRODUCTION SYNC, DOCKER REBUILD, CI/CD AND VPS VERIFICATION

## USER_OBJECTIVE
Commit and push the reorganized HRM project to branch `production`, preserve all latest production business fixes, rebuild a single canonical Docker environment locally without losing data, follow GitHub CI/CD through completion, and verify that the VPS runs the same production commit successfully.

The project must remain behaviorally aligned with the current CodeGraph and executable contracts across `FE/`, `BE/`, and `ai/`.

## SELECTED_PLAN
The user approved the design in `docs/superpowers/specs/2026-09-10-production-sync-delivery-design.md`:

1. Use **checkpoint + rebase**, not merge and not force push.
2. Preserve Docker data volumes.
3. Remove the four stopped duplicate HRM containers owned by legacy `D:\php` while preserving their volumes.
4. Recreate/build the canonical Docker projects `doan2` and `autorecruit-main` from the new source paths.
5. Run the complete local build/test/contract matrix and CodeGraph synchronization.
6. Commit and normal-push to `production` only after local gates pass.
7. Follow GitHub `CI` and `Deploy production` for the exact pushed SHA to terminal success, fixing and retesting any repository-caused failure.
8. Verify public VPS frontend, static asset, and API health after deployment, and establish commit equivalence through workflow SHA and, when authorized access permits, remote Git SHA.

## APPROVED_SCOPE
### Included
- Fetch and inspect `origin/production`.
- Preserve/integrate the four upstream commits currently ahead of local `production`, plus any additional upstream commits that appear before push.
- Create a local backup branch/checkpoint before rebase.
- Update remaining source-tree path references in scripts, CI/CD, docs used operationally, and test tooling.
- Update `.gitignore` to exclude generated browser profiles/caches, dependencies, build artifacts, CodeGraph database, local databases, secrets, and files inappropriate for GitHub.
- Rebase the local restructure commit(s) onto latest `origin/production`, preserving upstream business behavior.
- Sync and query workspace CodeGraph for affected architecture and business paths.
- Run FE, BE, AI, endpoint-contract, browser, bridge, Compose, syntax, and build checks.
- Inspect Docker containers, volumes, networks, images, and source mounts.
- Remove these legacy stopped containers only: `hrm-nginx`, `hrm-php`, `hrm-postgres`, `hrm-redis` owned by Compose project `php` at `D:\php`.
- Preserve every Docker named volume.
- Rebuild/recreate and health-check canonical HRM Docker services.
- Commit the final changes and normal-push branch `production`.
- Monitor/fix GitHub CI and production deployment for the exact SHA.
- Verify production via GitHub deployment evidence and public HTTPS endpoints; use authorized SSH only if available.

### Explicitly excluded
- Force push.
- Deleting any Docker volume.
- Resetting/migrating fresh the local or VPS database.
- Touching unrelated Docker container `9router` or unrelated projects beyond the four explicitly approved stopped legacy HRM containers.
- Copying local `.env`, secrets, database contents, Redis state, or Ollama data to VPS.
- Weakening SSH host-key/authentication controls or bypassing CI/CD.
- Material HRM business-logic redesign or unrelated refactoring.
- Manual production deployment that bypasses the approved GitHub Actions workflow unless the user separately approves it after a workflow blocker is established.

## ACCEPTANCE_CRITERIA
1. Final local `HEAD` is based on latest `origin/production` and contains all upstream business commits plus the source-tree reorganization.
2. No upstream fix is lost during rebase or conflict resolution.
3. CodeGraph index at `D:\HRM\.codegraph` is up to date, and targeted architecture/impact review shows no unaddressed source-tree drift.
4. No secret, `.env`, runtime DB, dependency tree, build output, CodeGraph DB, browser cache/profile, or GitHub-prohibited file is included in the commit.
5. FE production build succeeds from `FE/`.
6. FE regression scripts, endpoint audit, and Playwright auth suite pass.
7. ZK bridge test passes.
8. Laravel reports its version/routes correctly, Composer validates, and the complete Laravel test suite passes.
9. AutoRecruit unit tests pass and its Compose configuration is valid.
10. Both Docker Compose configurations are valid and resolve to new paths.
11. Four approved legacy `D:\php` containers are removed, no volumes are deleted, and unrelated containers remain untouched.
12. Exactly one canonical local HRM stack remains for each intended service; containers have correct Compose labels and new source mounts.
13. Canonical local HRM services build/start successfully; required health checks pass and existing database data remains present.
14. Final commit is created on `production` with the required Claude co-author trailer.
15. Normal push to `origin/production` succeeds without force.
16. GitHub `CI` succeeds for the exact final SHA.
17. GitHub `Deploy production` succeeds for the exact final SHA.
18. `https://devtapcode.io.vn/`, at least one current static asset, and `https://devtapcode.io.vn/api/v1/health` respond successfully after deployment.
19. `origin/production` SHA equals the SHA handled by successful deployment; remote `/opt/hrm` SHA is also compared if approved SSH authentication is available.
20. Final report states all commands/results, commit SHA, workflow URLs/statuses, production checks, and any access-limited verification clearly.

## CONSTRAINTS
- Existing architecture: Vue/Vite frontend in `FE/`; Laravel API in `BE/`; AutoRecruit/FastAPI in `ai/`; docs and deployment scripts in `docs/`; CI workflows in `.github/`.
- Shared local Docker network remains `doan2_hrm_network`.
- Preserve volumes: `doan2_postgres_data`, `doan2_redis_data`, `doan2_mineru_output`, `autorecruit-main_ollama_data`, and every legacy `D:\php` volume.
- Do not run destructive database commands.
- Do not expose credentials, tokens, private keys, or `.env` values in logs or responses.
- Verify current remote head again immediately before rebase/push.
- Do not silently change architecture or business behavior. A material change requires new user approval.
- The user explicitly authorized commit and normal push to branch `production` for this goal.
- Commit message must end with `Co-Authored-By: Claude Code <noreply@anthropic.com>`.
- A push/deployment is outward-facing; authorization applies only to the approved `production` flow in this goal.

## RELEVANT_FILES
- `.gitignore`
- `.github/workflows/ci.yml`
- `.github/workflows/deploy-production.yml`
- `.claude/goals/production-sync-build-deploy.md`
- `.codegraph/.gitignore`
- `FE/package.json`
- `FE/package-lock.json`
- `FE/vite.config.js`
- `FE/playwright.config.mjs`
- `FE/scripts/audit-endpoints.mjs`
- `FE/tests/browser/auth-refresh.spec.mjs`
- `FE/tools/zk-bridge/`
- `BE/docker-compose.yml`
- `BE/routes/api.php`
- `BE/composer.json`
- `BE/composer.lock`
- `BE/tests/`
- `BE/app/Http/Controllers/Api/AttendanceController.php`
- `BE/app/Http/Controllers/Api/RecruitmentController.php`
- `BE/app/Http/Controllers/Api/RequestApprovalController.php`
- `BE/app/Jobs/CreateGoogleMeetJob.php`
- `BE/app/Jobs/ProcessAttendanceLog.php`
- `BE/app/Jobs/SendRecruitmentEmailJob.php`
- `BE/app/Services/OrganizationStructureService.php`
- `BE/app/Support/ResourceBusinessRules.php`
- `ai/compose.yaml`
- `ai/app/requirements.txt`
- `ai/tests/test_objective_pipeline.py`
- `docs/operations/deploy/deploy-production.sh`
- `docs/operations/deploy/provision-vps.sh`
- `docs/test-cases/*.py`
- `docs/test-cases/*.ps1`
- `docs/superpowers/specs/2026-09-10-production-sync-delivery-design.md`
- Additional files affected by upstream rebase conflicts: to be determined during implementation.

## IMPLEMENTATION_REQUIREMENTS
1. Correct stale `HRM_TestCases` paths to `docs/test-cases` in executable test tooling.
2. Add precise ignores for browser profile/cache artifacts while retaining meaningful test evidence.
3. Audit all untracked files and prevent oversized/generated data from entering Git history.
4. Verify workflow/deploy scripts use `FE/`, `BE/`, `ai/`, and `docs/operations/deploy/` correctly.
5. Create a recoverable local backup branch before integration.
6. Commit the reorganized checkpoint, fetch latest remote, and rebase onto `origin/production`.
7. Resolve conflicts by retaining upstream business fixes at new paths, then inspect the resulting diff and commit ancestry.
8. Run CodeGraph sync/preflight and full local gates after rebase.
9. Inventory and preserve Docker volumes, remove only approved legacy containers, recreate/build canonical stacks, and verify health/data continuity.
10. Commit any post-rebase fixes and normal-push `production`.
11. Monitor CI and deploy, diagnose any failure, fix/retest/repush until both succeed or an external blocker remains.
12. Verify production endpoints and commit/deployment equivalence.

## TEST_REQUIREMENTS
- `git diff --check`, Git integrity/ancestry checks, prohibited/oversized-file scan, and secret-sensitive status inspection.
- CodeGraph `sync`, `status`, and targeted exploration/impact checks.
- FE clean install and `npm run build`.
- Every FE regression script listed in `FE/package.json` that CI runs.
- `npm run test:endpoints`.
- `npm run test:auth-browser`.
- `npm test --prefix FE/tools/zk-bridge`.
- Composer strict validation and full `php artisan test --without-tty` in the supported runtime/container.
- `php artisan route:list --json`.
- AutoRecruit `python -m unittest discover -s tests -p 'test_*.py'` in the supported container.
- `docker compose config --quiet` for BE and AI.
- Shell syntax checks for deployment and MinerU scripts.
- Local Docker container health plus HTTP smoke tests for FE, BE API, and AutoRecruit when enabled.
- GitHub CI and Deploy workflow terminal results for exact pushed SHA.
- Post-deploy public HTTPS frontend/API/static-asset smoke checks.

## VERIFICATION_REQUIREMENTS
- Independently verify all upstream commits are ancestors of final HEAD.
- Independently verify no volume deletion occurred and persistent database identity/data survived container recreation.
- Independently verify container labels/mounts point only to canonical new paths.
- Independently verify no duplicate HRM container names/stacks remain.
- Independently verify the final remote SHA matches the locally pushed SHA.
- Independently verify CI and deployment runs correspond to that SHA.
- Verify VPS source SHA through authorized SSH if available; otherwise explicitly document the access limitation and use workflow SHA plus public runtime evidence without overstating direct filesystem verification.
- Only declare DONE when every acceptance criterion is met; an external/authentication blocker must remain explicitly unresolved rather than being treated as success.

## CURRENT_STATUS
NOT_STARTED
