<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

return new class extends Migration
{
    public function up(): void
    {
        $types = DB::table('request_types')
            ->get(['id', 'tenant_id', 'approval_flow_id'])
            ->keyBy('id');
        $flows = DB::table('approval_flows')
            ->get(['id', 'tenant_id', 'request_type_id'])
            ->keyBy('id');
        $legacyByType = $flows->filter(fn (object $flow): bool => $flow->request_type_id !== null)
            ->groupBy(fn (object $flow): int => (int) $flow->request_type_id);

        $conflicts = [];
        $resolved = [];

        foreach ($types as $type) {
            $typeId = (int) $type->id;
            $tenantId = (int) $type->tenant_id;
            $canonicalId = $type->approval_flow_id !== null ? (int) $type->approval_flow_id : null;
            $legacyIds = collect($legacyByType->get($typeId, collect()))
                ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

            if (count($legacyIds) > 1) {
                $conflicts[] = [$tenantId, $typeId, $canonicalId, implode('|', $legacyIds), 'MULTIPLE_LEGACY_FLOWS'];
                continue;
            }

            if ($canonicalId !== null) {
                $canonical = $flows->get($canonicalId);
                if (! $canonical || (int) $canonical->tenant_id !== $tenantId) {
                    $conflicts[] = [$tenantId, $typeId, $canonicalId, implode('|', $legacyIds), 'CANONICAL_FLOW_MISSING_OR_CROSS_TENANT'];
                    continue;
                }
                if ($canonical->request_type_id !== null && (int) $canonical->request_type_id !== $typeId) {
                    $conflicts[] = [$tenantId, $typeId, $canonicalId, implode('|', $legacyIds), 'CANONICAL_FLOW_POINTS_TO_OTHER_TYPE'];
                    continue;
                }
                if ($legacyIds !== [] && $legacyIds[0] !== $canonicalId) {
                    $conflicts[] = [$tenantId, $typeId, $canonicalId, implode('|', $legacyIds), 'CANONICAL_AND_LEGACY_DISAGREE'];
                    continue;
                }
                $resolved[$typeId] = $canonicalId;
                continue;
            }

            if ($legacyIds !== []) {
                $legacy = $flows->get($legacyIds[0]);
                if (! $legacy || (int) $legacy->tenant_id !== $tenantId) {
                    $conflicts[] = [$tenantId, $typeId, null, implode('|', $legacyIds), 'LEGACY_FLOW_CROSS_TENANT'];
                    continue;
                }
                $resolved[$typeId] = $legacyIds[0];
            }
        }

        foreach ($flows as $flow) {
            if ($flow->request_type_id === null) {
                continue;
            }
            $type = $types->get((int) $flow->request_type_id);
            if (! $type || (int) $type->tenant_id !== (int) $flow->tenant_id) {
                $conflicts[] = [
                    (int) $flow->tenant_id,
                    $flow->request_type_id,
                    null,
                    (string) $flow->id,
                    'LEGACY_TYPE_MISSING_OR_CROSS_TENANT',
                ];
            }
        }

        $typesByFlow = [];
        foreach ($resolved as $typeId => $flowId) {
            $typesByFlow[$flowId][] = $typeId;
        }
        foreach ($typesByFlow as $flowId => $typeIds) {
            if (count($typeIds) > 1) {
                $conflicts[] = [null, implode('|', $typeIds), $flowId, null, 'FLOW_ASSIGNED_TO_MULTIPLE_TYPES'];
            }
        }

        if ($conflicts !== []) {
            $path = $this->writeConflictReport($conflicts);
            throw new RuntimeException("Request approval-flow backfill stopped; inspect {$path}");
        }

        DB::transaction(function () use ($resolved): void {
            foreach ($resolved as $typeId => $flowId) {
                DB::table('request_types')->where('id', $typeId)->update([
                    'approval_flow_id' => $flowId,
                    'updated_at' => now(),
                ]);
                DB::table('approval_flows')->where('id', $flowId)->update([
                    'request_type_id' => $typeId,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Canonicalization only mirrors two existing relationships; it is safe to retain on rollback.
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function writeConflictReport(array $rows): string
    {
        $directory = storage_path('app/private/migration-reports');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/request-flow-conflicts-'.now()->format('Ymd-His').'.csv';
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write request approval-flow conflict report');
        }
        fputcsv($handle, ['tenant_id', 'request_type_id', 'canonical_flow_id', 'legacy_flow_ids', 'reason']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }
};
