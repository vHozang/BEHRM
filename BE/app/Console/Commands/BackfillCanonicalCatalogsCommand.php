<?php

namespace App\Console\Commands;

use App\Services\CatalogBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BackfillCanonicalCatalogsCommand extends Command
{
    protected $signature = 'hrm:backfill-catalogs
        {--plan : Create an immutable backfill plan without changing database rows}
        {--apply= : Apply a previously generated plan id}
        {--dry-run : Deprecated alias for --plan}
        {--tenant= : Limit planning to one tenant id}
        {--resource=* : Limit planning to one or more supported resources}
        {--chunk=750 : Operations per transaction, from 500 to 1000}
        {--resume : Resume a partially applied plan}
        {--max-runtime=900 : Pause safely after this many seconds; 0 disables the limit}';

    protected $description = 'Plan and apply legacy catalog backfills safely in bounded transactions';

    public function handle(CatalogBackfillService $service): int
    {
        $planning = (bool) $this->option('plan') || (bool) $this->option('dry-run');
        $planId = trim((string) $this->option('apply'));
        if ($planning === ($planId !== '')) {
            $this->error('Choose exactly one mode: --plan (or --dry-run) or --apply=<plan-id>.');

            return self::INVALID;
        }

        try {
            if ($planning) {
                if ($this->option('resume')) {
                    throw new RuntimeException('--resume is only valid with --apply.');
                }
                $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
                if ($tenantId !== null && ! DB::table('tenants')->where('id', $tenantId)->exists()) {
                    throw new RuntimeException('Tenant not found.');
                }
                $result = $service->createPlan($tenantId, array_values($this->option('resource')));
                $manifest = $result['manifest'];
                $resolutions = json_decode(
                    (string) file_get_contents(dirname($result['path']).'/resolutions.json'),
                    true,
                ) ?: [];
                $this->table(['Metric', 'Count'], [
                    ['References planned', $manifest['operation_count']],
                    ['Catalog resolutions', count($resolutions)],
                ]);
                $this->line('PLAN_ID='.$result['plan_id']);
                $this->line('PLAN_PATH='.$result['path']);
                if ($manifest['status'] !== 'READY') {
                    $this->error('Plan is BLOCKED by ambiguous catalog matches. Review its private CSV report.');

                    return self::FAILURE;
                }
                $this->info('Catalog backfill plan is READY. No database rows were changed.');

                return self::SUCCESS;
            }

            if ($this->option('tenant') !== null || $this->option('resource') !== []) {
                throw new RuntimeException('--tenant and --resource belong to --plan; apply uses the scope stored in its manifest.');
            }
            $result = $service->applyPlan(
                $planId,
                (int) $this->option('chunk'),
                (int) $this->option('max-runtime'),
                (bool) $this->option('resume'),
            );
            $this->line("PLAN_ID={$result['plan_id']}");
            $this->line("PROGRESS={$result['processed']}/{$result['total']}");
            if ($result['status'] === 'PAUSED') {
                $this->warn('Backfill paused at a transaction boundary. Rerun with --apply and --resume.');

                return 75;
            }
            $this->info('Catalog backfill completed successfully.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
