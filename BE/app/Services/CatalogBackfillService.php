<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class CatalogBackfillService
{
    private const PLAN_VERSION = 1;

    private const SCAN_CHUNK = 1000;

    /** @var array<string, array{code:string,name:string,status:?string}> */
    private array $catalogs = [
        'asset_categories' => ['code' => 'category_code', 'name' => 'category_name', 'status' => 'status'],
        'asset_locations' => ['code' => 'location_code', 'name' => 'location_name', 'status' => 'status'],
        'suppliers' => ['code' => 'supplier_code', 'name' => 'supplier_name', 'status' => 'status'],
        'service_categories' => ['code' => 'category_code', 'name' => 'category_name', 'status' => 'status'],
        'request_types' => ['code' => 'request_type_code', 'name' => 'request_type_name', 'status' => 'status'],
        'news_categories' => ['code' => 'category_code', 'name' => 'category_name', 'status' => 'status'],
        'document_types' => ['code' => 'document_type_code', 'name' => 'document_type_name', 'status' => null],
        'qualification_types' => ['code' => 'qualification_type_code', 'name' => 'qualification_type_name', 'status' => null],
        'insurance_types' => ['code' => 'insurance_type_code', 'name' => 'insurance_type_name', 'status' => 'status'],
        'banks' => ['code' => 'bank_code', 'name' => 'bank_name', 'status' => 'status'],
        'nationalities' => ['code' => 'nationality_code', 'name' => 'nationality_name', 'status' => 'status'],
    ];

    /** @var array<int, string> */
    private array $booleanStatusCatalogs = ['banks', 'nationalities'];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $resourceMaps = [
        'assets' => [
            ['source' => 'assets', 'target' => 'category_id', 'catalog' => 'asset_categories', 'legacy_keys' => ['category', 'category_name'], 'legacy_store_key' => 'legacy_category', 'json' => 'meta'],
            ['source' => 'assets', 'target' => 'supplier_id', 'catalog' => 'suppliers', 'legacy_keys' => ['supplier', 'supplier_name', 'vendor'], 'legacy_store_key' => 'legacy_supplier', 'json' => 'meta'],
            ['source' => 'assets', 'target' => 'location_id', 'catalog' => 'asset_locations', 'legacy_keys' => ['location', 'location_name'], 'legacy_store_key' => 'legacy_location', 'json' => 'meta'],
        ],
        'service_tickets' => [
            ['source' => 'service_tickets', 'target' => 'category_id', 'catalog' => 'service_categories', 'legacy_keys' => ['category', 'category_name'], 'legacy_store_key' => 'legacy_category', 'json' => 'meta'],
        ],
        'news' => [
            ['source' => 'news', 'target' => 'category_id', 'catalog' => 'news_categories', 'legacy_keys' => ['category', 'category_name'], 'legacy_store_key' => 'legacy_category', 'json' => 'meta'],
        ],
        'requests' => [
            ['source' => 'requests', 'target' => 'request_type_id', 'catalog' => 'request_types', 'legacy_keys' => ['type', 'request_type', 'request_type_name'], 'legacy_store_key' => 'legacy_request_type', 'json' => 'meta'],
        ],
        'identity_documents' => [
            ['source' => 'identity_documents', 'target' => 'document_type_id', 'catalog' => 'document_types', 'legacy_keys' => ['document_type', 'document_type_name', 'type'], 'legacy_store_key' => 'legacy_document_type', 'json' => 'meta'],
        ],
        'qualifications' => [
            ['source' => 'qualifications', 'target' => 'qualification_type_id', 'catalog' => 'qualification_types', 'legacy_keys' => ['qualification_type', 'qualification_type_name', 'type'], 'legacy_store_key' => 'legacy_qualification_type', 'json' => 'meta'],
        ],
        'insurance_claims' => [
            ['source' => 'insurance_claims', 'target' => 'insurance_type_id', 'catalog' => 'insurance_types', 'legacy_keys' => ['insurance_type', 'insurance_type_name', 'type'], 'legacy_store_key' => 'legacy_insurance_type', 'json' => 'meta'],
            ['source' => 'insurance_claims', 'target' => 'bank_id', 'catalog' => 'banks', 'legacy_keys' => ['bank', 'bank_name'], 'legacy_store_key' => 'legacy_bank_name', 'json' => 'meta'],
        ],
        'employees' => [
            ['source' => 'employees', 'target' => 'bank_id', 'catalog' => 'banks', 'legacy_keys' => ['bank_name'], 'legacy_store_key' => 'legacy_bank_name', 'json' => 'profile', 'json_target' => true],
            ['source' => 'employees', 'target' => 'nationality_id', 'catalog' => 'nationalities', 'legacy_keys' => ['nationality_name', 'nationality'], 'legacy_store_key' => 'legacy_nationality_name', 'json' => 'profile', 'json_target' => true],
            ['source' => 'employees', 'target' => 'qualification_type_id', 'catalog' => 'qualification_types', 'legacy_keys' => ['education_level'], 'legacy_store_key' => 'legacy_education_level', 'json' => 'profile', 'json_target' => true],
        ],
    ];

    /** @var array<string, array<int, object>> */
    private array $catalogRows = [];

    /** @var array<string, array<int, string>> */
    private array $reservedCodes = [];

    /** @return array<int, string> */
    public function resources(): array
    {
        return array_keys($this->resourceMaps);
    }

    /** @return array<string, mixed> */
    public function createPlan(?int $tenantId, array $resources): array
    {
        $this->catalogRows = [];
        $this->reservedCodes = [];
        $resources = $resources === [] ? $this->resources() : array_values(array_unique($resources));
        $this->assertResources($resources);

        $planId = (string) Str::uuid();
        $directory = $this->planDirectory($planId);
        File::ensureDirectoryExists($directory, 0700, true);
        $operationsPath = $directory.'/operations.ndjson';
        $handle = fopen($operationsPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create catalog backfill operations file.');
        }

        $operationCount = 0;
        $mapCounts = [];
        $resolutions = [];
        $ambiguities = [];
        $sourceHash = hash_init('sha256');

        try {
            foreach ($resources as $resource) {
                foreach ($this->resourceMaps[$resource] as $map) {
                    if (! $this->mapExists($map)) {
                        continue;
                    }
                    $mapId = $this->mapId($resource, $map);
                    $mapCounts[$mapId] = 0;
                    $this->eachEligibleRow($map, $tenantId, function (object $row, array $json) use (
                        $resource,
                        $map,
                        $mapId,
                        &$operationCount,
                        &$mapCounts,
                        &$resolutions,
                        &$ambiguities,
                        $handle,
                        $sourceHash,
                    ): void {
                        $reference = $this->reference($resource, $map, $mapId, $row, $json);
                        $resolutionKey = $this->resolutionKey($reference);
                        if (! isset($resolutions[$resolutionKey])) {
                            $resolution = $this->planResolution($reference);
                            if ($resolution['mode'] === 'ambiguous') {
                                $ambiguities[] = [
                                    $reference['source_table'],
                                    $reference['source_id'],
                                    $reference['tenant_id'],
                                    $reference['catalog'],
                                    $reference['legacy_value'],
                                    implode('|', $resolution['matching_ids']),
                                ];
                            }
                            $resolutions[$resolutionKey] = $resolution;
                        }

                        $operationCount++;
                        $mapCounts[$mapId]++;
                        $operation = [
                            'index' => $operationCount,
                            ...$reference,
                            'resolution_key' => $resolutionKey,
                            'source_fingerprint' => $this->sourceFingerprint($reference),
                        ];
                        $line = $this->canonicalJson($operation)."\n";
                        fwrite($handle, $line);
                        hash_update($sourceHash, $line);
                    });
                }
            }
        } finally {
            fclose($handle);
        }

        $resolutionsPath = $directory.'/resolutions.json';
        $this->atomicWriteJson($resolutionsPath, $resolutions);
        $ambiguityPath = null;
        if ($ambiguities !== []) {
            $ambiguityPath = $directory.'/ambiguities.csv';
            $this->writeCsv($ambiguityPath, [
                'source_table', 'source_id', 'tenant_id', 'catalog', 'legacy_value', 'matching_ids',
            ], $ambiguities);
        }

        $manifest = [
            'version' => self::PLAN_VERSION,
            'plan_id' => $planId,
            'status' => $ambiguities === [] ? 'READY' : 'BLOCKED',
            'tenant_id' => $tenantId,
            'resources' => $resources,
            'created_at' => now()->toIso8601String(),
            'operation_count' => $operationCount,
            'map_counts' => $mapCounts,
            'source_checksum' => hash_final($sourceHash),
            'operations_file' => basename($operationsPath),
            'operations_sha256' => hash_file('sha256', $operationsPath),
            'resolutions_file' => basename($resolutionsPath),
            'resolutions_sha256' => hash_file('sha256', $resolutionsPath),
            'ambiguities_file' => $ambiguityPath ? basename($ambiguityPath) : null,
        ];
        $manifestPath = $directory.'/manifest.json';
        $this->atomicWriteJson($manifestPath, $manifest);
        File::put($directory.'/manifest.sha256', hash_file('sha256', $manifestPath)."\n");
        $this->restrictPlanPermissions($directory);

        return ['plan_id' => $planId, 'path' => $manifestPath, 'manifest' => $manifest];
    }

    /** @return array{status:string,processed:int,total:int,plan_id:string} */
    public function applyPlan(string $planId, int $chunkSize, int $maxRuntime, bool $resume): array
    {
        $this->catalogRows = [];
        $this->reservedCodes = [];
        if (! preg_match('/^[0-9a-f-]{36}$/i', $planId)) {
            throw new RuntimeException('Invalid catalog backfill plan id.');
        }
        $directory = $this->planDirectory($planId);
        $manifest = $this->loadAndVerifyManifest($directory);
        if (($manifest['status'] ?? null) !== 'READY') {
            throw new RuntimeException('Catalog backfill plan is not apply-ready.');
        }
        if ($chunkSize < 500 || $chunkSize > 1000) {
            throw new RuntimeException('Chunk size must be between 500 and 1000.');
        }
        if ($maxRuntime < 0) {
            throw new RuntimeException('Maximum runtime cannot be negative.');
        }

        $locks = [];
        $lockTtl = max(120, $maxRuntime > 0 ? $maxRuntime + 60 : 3600);
        $resources = $manifest['resources'];
        sort($resources);
        try {
            foreach ($resources as $resource) {
                $scope = $manifest['tenant_id'] === null ? 'all' : (string) $manifest['tenant_id'];
                $lock = Cache::lock("catalog-backfill:{$scope}:{$resource}", $lockTtl);
                if (! $lock->get()) {
                    throw new RuntimeException('Another catalog backfill is running for this tenant/resource scope.');
                }
                $locks[] = $lock;
            }

            return $this->applyLocked($directory, $manifest, $chunkSize, $maxRuntime, $resume);
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /** @return array{status:string,processed:int,total:int,plan_id:string} */
    private function applyLocked(string $directory, array $manifest, int $chunkSize, int $maxRuntime, bool $resume): array
    {
        $checkpointPath = $directory.'/checkpoint.json';
        $checkpoint = File::exists($checkpointPath)
            ? $this->readJson($checkpointPath)
            : $this->initialCheckpoint($manifest);
        $total = (int) $manifest['operation_count'];
        $processed = (int) ($checkpoint['completed_operations'] ?? 0);

        if (($checkpoint['status'] ?? null) === 'COMPLETE' || $processed >= $total) {
            return ['status' => 'COMPLETE', 'processed' => $total, 'total' => $total, 'plan_id' => $manifest['plan_id']];
        }
        if ($processed > 0 && ! $resume) {
            throw new RuntimeException('This plan is partially applied; rerun with --resume.');
        }
        if (($checkpoint['status'] ?? null) === 'STALE') {
            throw new RuntimeException('This plan is stale and must be regenerated.');
        }

        $resolutions = $this->readJson($directory.'/'.$manifest['resolutions_file']);
        try {
            $resolutionIds = $this->resolveCatalogIds($resolutions, false);
            $alreadyApplied = $this->verifySourceSnapshot($directory, $manifest, $checkpoint, $resolutionIds);
            $resolutionIds = $this->resolveCatalogIds($resolutions, true);
        } catch (RuntimeException $exception) {
            $checkpoint['status'] = 'STALE';
            $checkpoint['last_error'] = $exception->getMessage();
            $checkpoint['updated_at'] = now()->toIso8601String();
            $this->atomicWriteJson($checkpointPath, $checkpoint);
            throw $exception;
        }

        try {
            $startedAt = microtime(true);
            $batch = [];
            foreach ($this->readOperations($directory.'/'.$manifest['operations_file']) as $operation) {
                if ((int) $operation['index'] <= $processed) {
                    continue;
                }
                $batch[] = $operation;
                if (count($batch) < $chunkSize) {
                    continue;
                }
                $checkpoint = $this->applyBatch($batch, $resolutionIds, $checkpoint, $checkpointPath);
                $processed = (int) $checkpoint['completed_operations'];
                $batch = [];
                if ($this->runtimeExceeded($startedAt, $maxRuntime) && $processed < $total) {
                    $checkpoint['status'] = 'PAUSED';
                    $checkpoint['updated_at'] = now()->toIso8601String();
                    $this->atomicWriteJson($checkpointPath, $checkpoint);

                    return ['status' => 'PAUSED', 'processed' => $processed, 'total' => $total, 'plan_id' => $manifest['plan_id']];
                }
            }
            if ($batch !== []) {
                $checkpoint = $this->applyBatch($batch, $resolutionIds, $checkpoint, $checkpointPath);
            }

            // Detect relevant rows added while the chunked apply was running.
            $this->verifySourceSnapshot($directory, $manifest, $checkpoint, $resolutionIds);
            $checkpoint['status'] = 'COMPLETE';
            $checkpoint['completed_operations'] = $total;
            $checkpoint['already_applied_before_resume'] = $alreadyApplied;
            $checkpoint['completed_at'] = now()->toIso8601String();
            $checkpoint['updated_at'] = now()->toIso8601String();
            $this->atomicWriteJson($checkpointPath, $checkpoint);
        } catch (RuntimeException $exception) {
            $checkpoint['status'] = 'STALE';
            $checkpoint['last_error'] = $exception->getMessage();
            $checkpoint['updated_at'] = now()->toIso8601String();
            $this->atomicWriteJson($checkpointPath, $checkpoint);
            throw $exception;
        }

        return ['status' => 'COMPLETE', 'processed' => $total, 'total' => $total, 'plan_id' => $manifest['plan_id']];
    }

    protected function runtimeExceeded(float $startedAt, int $maxRuntime): bool
    {
        return $maxRuntime > 0 && microtime(true) - $startedAt >= $maxRuntime;
    }

    /** @return array<string, mixed> */
    private function applyBatch(array $batch, array $resolutionIds, array $checkpoint, string $checkpointPath): array
    {
        DB::transaction(function () use ($batch, $resolutionIds): void {
            foreach ($batch as $operation) {
                $catalogId = $resolutionIds[$operation['resolution_key']] ?? null;
                if (! is_int($catalogId) || $catalogId <= 0) {
                    throw new RuntimeException('Catalog resolution is missing for operation '.$operation['index'].'.');
                }
                $this->applyOperation($operation, $catalogId);
            }
        });
        foreach ($batch as $operation) {
            $mapId = $operation['map_id'];
            $checkpoint['completed_by_map'][$mapId] = (int) ($checkpoint['completed_by_map'][$mapId] ?? 0) + 1;
        }
        $last = end($batch);
        $checkpoint['completed_operations'] = (int) $last['index'];
        $checkpoint['status'] = 'RUNNING';
        $checkpoint['updated_at'] = now()->toIso8601String();
        $this->atomicWriteJson($checkpointPath, $checkpoint);

        return $checkpoint;
    }

    private function applyOperation(array $operation, int $catalogId): void
    {
        $row = DB::table($operation['source_table'])
            ->where('id', $operation['source_id'])
            ->where('tenant_id', $operation['tenant_id'])
            ->lockForUpdate()
            ->first();
        if (! $row) {
            throw new RuntimeException('Source row disappeared for operation '.$operation['index'].'.');
        }
        $json = $this->decodeJson($row->{$operation['json_column']} ?? null);
        $reference = $this->referenceFromOperation($operation, $row, $json);
        if (! hash_equals($operation['source_fingerprint'], $this->sourceFingerprint($reference))) {
            throw new RuntimeException('Source data changed for operation '.$operation['index'].'.');
        }
        $currentTarget = $operation['json_target']
            ? ($json[$operation['target_column']] ?? null)
            : ($row->{$operation['target_column']} ?? null);
        if ((int) $currentTarget === $catalogId) {
            return;
        }
        if ($currentTarget !== null && $currentTarget !== '') {
            throw new RuntimeException('Catalog target changed for operation '.$operation['index'].'.');
        }

        if (! array_key_exists($operation['legacy_store_key'], $json)) {
            $json[$operation['legacy_store_key']] = $operation['legacy_value'];
        }
        $payload = ['updated_at' => now()];
        if ($operation['json_target']) {
            $json[$operation['target_column']] = $catalogId;
            $payload[$operation['json_column']] = json_encode($json, JSON_UNESCAPED_UNICODE);
        } else {
            $payload[$operation['target_column']] = $catalogId;
            $payload[$operation['json_column']] = json_encode($json, JSON_UNESCAPED_UNICODE);
        }
        DB::table($operation['source_table'])
            ->where('id', $operation['source_id'])
            ->where('tenant_id', $operation['tenant_id'])
            ->update($payload);
    }

    /** @return array<string, int|null> */
    private function resolveCatalogIds(array $resolutions, bool $createMissing): array
    {
        $ids = [];
        foreach ($resolutions as $key => $resolution) {
            if (($resolution['mode'] ?? null) === 'ambiguous') {
                throw new RuntimeException('Plan contains an ambiguous catalog resolution.');
            }
            $catalog = $resolution['catalog'];
            $definition = $this->catalogs[$catalog];
            if ($resolution['mode'] === 'existing') {
                $row = DB::table($catalog)
                    ->where('id', $resolution['id'])
                    ->where('tenant_id', $resolution['tenant_id'])
                    ->first();
                if (! $row || ! hash_equals($resolution['catalog_fingerprint'], $this->catalogFingerprint($catalog, $row))) {
                    throw new RuntimeException('Existing catalog data changed after the plan was created.');
                }
                $ids[$key] = (int) $row->id;
                continue;
            }

            $payload = $resolution['payload'];
            $row = DB::table($catalog)
                ->where('tenant_id', $resolution['tenant_id'])
                ->where($definition['code'], $payload[$definition['code']])
                ->first();
            if ($row) {
                if (! $this->plannedCatalogRowMatches($catalog, $row, $payload)) {
                    throw new RuntimeException('Planned catalog code is now used by different data.');
                }
                $ids[$key] = (int) $row->id;
                continue;
            }
            if ($this->catalogMatches($catalog, (int) $resolution['tenant_id'], $resolution['legacy_value']) !== []) {
                throw new RuntimeException('Catalog matching data changed after the plan was created.');
            }
            if (! $createMissing) {
                $ids[$key] = null;
                continue;
            }
            $ids[$key] = (int) DB::transaction(fn () => DB::table($catalog)->insertGetId([
                ...$payload,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $this->catalogRows = [];
        }

        return $ids;
    }

    private function plannedCatalogRowMatches(string $catalog, object $row, array $payload): bool
    {
        $definition = $this->catalogs[$catalog];
        if ($this->normalize($row->{$definition['code']} ?? null) !== $this->normalize($payload[$definition['code']])) {
            return false;
        }
        if ($this->normalize($row->{$definition['name']} ?? null) !== $this->normalize($payload[$definition['name']])) {
            return false;
        }
        if ($definition['status'] !== null) {
            $actual = $row->{$definition['status']} ?? null;
            $expected = $payload[$definition['status']];
            if (is_bool($expected) ? (bool) $actual !== $expected : (string) $actual !== (string) $expected) {
                return false;
            }
        }
        $actualMeta = $this->decodeJson($row->meta ?? null);
        $expectedMeta = $this->decodeJson($payload['meta'] ?? null);

        return ($actualMeta['imported_from_legacy'] ?? false) === true
            && ($actualMeta['legacy_value'] ?? null) === ($expectedMeta['legacy_value'] ?? null);
    }

    private function verifySourceSnapshot(string $directory, array $manifest, array $checkpoint, array $resolutionIds): int
    {
        $completed = (int) ($checkpoint['completed_operations'] ?? 0);
        $completedByMap = array_map('intval', $checkpoint['completed_by_map'] ?? []);
        $alreadyAppliedByMap = [];
        $alreadyApplied = 0;
        $batch = [];
        $verifyBatch = function (array $operations) use ($completed, $resolutionIds, &$alreadyAppliedByMap, &$alreadyApplied): void {
            $groups = [];
            foreach ($operations as $operation) {
                $groups[$operation['source_table']][] = $operation;
            }
            foreach ($groups as $source => $sourceOperations) {
                $rows = DB::table($source)
                    ->whereIn('id', array_values(array_unique(array_column($sourceOperations, 'source_id'))))
                    ->get()
                    ->keyBy('id');
                foreach ($sourceOperations as $operation) {
                    $row = $rows->get($operation['source_id']);
                    if (! $row || (int) ($row->tenant_id ?? 1) !== (int) $operation['tenant_id']) {
                        throw new RuntimeException('Source row set changed after the plan was created.');
                    }
                    $json = $this->decodeJson($row->{$operation['json_column']} ?? null);
                    $reference = $this->referenceFromOperation($operation, $row, $json);
                    if (! hash_equals($operation['source_fingerprint'], $this->sourceFingerprint($reference))) {
                        throw new RuntimeException('Source data changed after the plan was created.');
                    }
                    $target = $operation['json_target']
                        ? ($json[$operation['target_column']] ?? null)
                        : ($row->{$operation['target_column']} ?? null);
                    $expected = $resolutionIds[$operation['resolution_key']] ?? null;
                    if ((int) $operation['index'] <= $completed) {
                        if (! $expected || (int) $target !== (int) $expected) {
                            throw new RuntimeException('A completed operation no longer matches its checkpoint.');
                        }
                    } elseif ($target !== null && $target !== '') {
                        if (! $expected || (int) $target !== (int) $expected) {
                            throw new RuntimeException('A pending catalog target changed after planning.');
                        }
                        $alreadyApplied++;
                        $alreadyAppliedByMap[$operation['map_id']] = (int) ($alreadyAppliedByMap[$operation['map_id']] ?? 0) + 1;
                    }
                }
            }
        };

        foreach ($this->readOperations($directory.'/'.$manifest['operations_file']) as $operation) {
            $batch[] = $operation;
            if (count($batch) === self::SCAN_CHUNK) {
                $verifyBatch($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $verifyBatch($batch);
        }

        foreach ($manifest['resources'] as $resource) {
            foreach ($this->resourceMaps[$resource] as $map) {
                if (! $this->mapExists($map)) {
                    continue;
                }
                $mapId = $this->mapId($resource, $map);
                $planned = (int) ($manifest['map_counts'][$mapId] ?? 0);
                $expectedEligible = $planned
                    - (int) ($completedByMap[$mapId] ?? 0)
                    - (int) ($alreadyAppliedByMap[$mapId] ?? 0);
                if ($this->countEligibleRows($map, $manifest['tenant_id']) !== $expectedEligible) {
                    throw new RuntimeException('Eligible source row count changed for '.$mapId.'.');
                }
            }
        }

        return $alreadyApplied;
    }

    /** @return array<string, mixed> */
    private function planResolution(array $reference): array
    {
        $matches = $this->catalogMatches($reference['catalog'], $reference['tenant_id'], $reference['legacy_value']);
        if (count($matches) > 1) {
            return [
                'mode' => 'ambiguous',
                'catalog' => $reference['catalog'],
                'tenant_id' => $reference['tenant_id'],
                'legacy_value' => $reference['legacy_value'],
                'matching_ids' => array_map(fn ($row): int => (int) $row->id, $matches),
            ];
        }
        if (count($matches) === 1) {
            $row = $matches[0];

            return [
                'mode' => 'existing',
                'catalog' => $reference['catalog'],
                'tenant_id' => $reference['tenant_id'],
                'legacy_value' => $reference['legacy_value'],
                'id' => (int) $row->id,
                'catalog_fingerprint' => $this->catalogFingerprint($reference['catalog'], $row),
            ];
        }

        return [
            'mode' => 'create',
            'catalog' => $reference['catalog'],
            'tenant_id' => $reference['tenant_id'],
            'legacy_value' => $reference['legacy_value'],
            'payload' => $this->catalogPayload($reference['catalog'], $reference['tenant_id'], $reference['legacy_value']),
        ];
    }

    /** @return array<int, object> */
    private function catalogMatches(string $catalog, int $tenantId, ?string $legacyValue): array
    {
        $definition = $this->catalogs[$catalog];
        $cacheKey = $catalog.'|'.$tenantId;
        if (! isset($this->catalogRows[$cacheKey])) {
            $this->catalogRows[$cacheKey] = DB::table($catalog)->where('tenant_id', $tenantId)->get()->all();
        }
        $rows = $this->catalogRows[$cacheKey];
        $normalized = $this->normalize($legacyValue);
        $codeLookup = $normalized === '' ? 'unclassified' : $normalized;
        $codeMatches = array_values(array_filter(
            $rows,
            fn ($row): bool => $this->normalize($row->{$definition['code']} ?? null) === $codeLookup,
        ));

        return $codeMatches !== [] ? $codeMatches : array_values(array_filter(
            $rows,
            fn ($row): bool => $this->normalize($row->{$definition['name']} ?? null) === $normalized,
        ));
    }

    /** @return array<string, mixed> */
    private function catalogPayload(string $catalog, int $tenantId, ?string $legacyValue): array
    {
        $definition = $this->catalogs[$catalog];
        $name = trim((string) $legacyValue) ?: 'Chưa phân loại';
        $payload = [
            'tenant_id' => $tenantId,
            $definition['code'] => $this->catalogCode($catalog, $tenantId, $legacyValue),
            $definition['name'] => $name,
            'meta' => json_encode(['imported_from_legacy' => true, 'legacy_value' => $legacyValue], JSON_UNESCAPED_UNICODE),
        ];
        if ($definition['status'] !== null) {
            $payload[$definition['status']] = in_array($catalog, $this->booleanStatusCatalogs, true) ? true : 'ACTIVE';
        }

        return $payload;
    }

    private function catalogCode(string $catalog, int $tenantId, ?string $legacyValue): string
    {
        $base = $this->normalize($legacyValue) === ''
            ? 'UNCLASSIFIED'
            : ('IMP_'.substr(Str::upper(Str::slug((string) $legacyValue, '_')) ?: 'IMPORTED', 0, 55));
        $key = $catalog.'|'.$tenantId;
        if (! isset($this->reservedCodes[$key])) {
            $definition = $this->catalogs[$catalog];
            $this->reservedCodes[$key] = DB::table($catalog)
                ->where('tenant_id', $tenantId)
                ->pluck($definition['code'])
                ->map(fn ($code): string => $this->normalize(is_string($code) ? $code : null))
                ->all();
        }
        $candidate = $base;
        $suffix = 0;
        while (in_array($this->normalize($candidate), $this->reservedCodes[$key], true)) {
            $suffix++;
            $candidate = substr($base, 0, 60)."_{$suffix}";
        }
        $this->reservedCodes[$key][] = $this->normalize($candidate);

        return $candidate;
    }

    private function eachEligibleRow(array $map, ?int $tenantId, callable $callback): void
    {
        $query = DB::table($map['source'])->orderBy('id');
        if (Schema::hasColumn($map['source'], 'tenant_id') && $tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        if (! ($map['json_target'] ?? false)) {
            $query->whereNull($map['target']);
        }
        $query->chunkById(self::SCAN_CHUNK, function ($rows) use ($map, $callback): void {
            foreach ($rows as $row) {
                $json = $this->decodeJson($row->{$map['json']} ?? null);
                if ($map['source'] === 'employees'
                    && in_array($json['system_account'] ?? false, [true, 1, '1', 'true', 't'], true)) {
                    continue;
                }
                if (($map['json_target'] ?? false) && ! empty($json[$map['target']])) {
                    continue;
                }
                $callback($row, $json);
            }
        }, 'id');
    }

    private function countEligibleRows(array $map, ?int $tenantId): int
    {
        $count = 0;
        $this->eachEligibleRow($map, $tenantId, function () use (&$count): void {
            $count++;
        });

        return $count;
    }

    /** @return array<string, mixed> */
    private function reference(string $resource, array $map, string $mapId, object $row, array $json): array
    {
        return [
            'resource' => $resource,
            'map_id' => $mapId,
            'source_table' => $map['source'],
            'source_id' => (int) $row->id,
            'tenant_id' => (int) ($row->tenant_id ?? config('hrm.default_tenant_id', 1)),
            'target_column' => $map['target'],
            'catalog' => $map['catalog'],
            'legacy_value' => $this->firstValue($json, $map['legacy_keys']),
            'legacy_store_key' => $map['legacy_store_key'],
            'json_column' => $map['json'],
            'json_target' => (bool) ($map['json_target'] ?? false),
        ];
    }

    private function referenceFromOperation(array $operation, object $row, array $json): array
    {
        $map = $this->findMap($operation['resource'], $operation['map_id']);

        return $this->reference($operation['resource'], $map, $operation['map_id'], $row, $json);
    }

    private function sourceFingerprint(array $reference): string
    {
        return hash('sha256', $this->canonicalJson([
            'resource' => $reference['resource'],
            'map_id' => $reference['map_id'],
            'source_table' => $reference['source_table'],
            'source_id' => $reference['source_id'],
            'tenant_id' => $reference['tenant_id'],
            'legacy_value' => $reference['legacy_value'],
        ]));
    }

    private function catalogFingerprint(string $catalog, object $row): string
    {
        $definition = $this->catalogs[$catalog];

        return hash('sha256', $this->canonicalJson([
            'id' => (int) $row->id,
            'tenant_id' => (int) $row->tenant_id,
            'code' => $row->{$definition['code']} ?? null,
            'name' => $row->{$definition['name']} ?? null,
            'status' => $definition['status'] ? ($row->{$definition['status']} ?? null) : null,
        ]));
    }

    private function resolutionKey(array $reference): string
    {
        return hash('sha256', implode('|', [
            $reference['tenant_id'], $reference['catalog'], $this->normalize($reference['legacy_value']),
        ]));
    }

    private function mapId(string $resource, array $map): string
    {
        return $resource.':'.$map['target'];
    }

    private function findMap(string $resource, string $mapId): array
    {
        foreach ($this->resourceMaps[$resource] ?? [] as $map) {
            if ($this->mapId($resource, $map) === $mapId) {
                return $map;
            }
        }
        throw new RuntimeException('Unknown catalog backfill mapping '.$mapId.'.');
    }

    private function mapExists(array $map): bool
    {
        return Schema::hasTable($map['source'])
            && Schema::hasTable($map['catalog'])
            && (($map['json_target'] ?? false) || Schema::hasColumn($map['source'], $map['target']))
            && Schema::hasColumn($map['source'], $map['json']);
    }

    private function assertResources(array $resources): void
    {
        $invalid = array_values(array_diff($resources, $this->resources()));
        if ($invalid !== []) {
            throw new RuntimeException('Unknown catalog backfill resource(s): '.implode(', ', $invalid).'.');
        }
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function readOperations(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read catalog backfill operations file.');
        }
        try {
            while (($line = fgets($handle)) !== false) {
                yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, mixed> */
    private function loadAndVerifyManifest(string $directory): array
    {
        $manifestPath = $directory.'/manifest.json';
        $checksumPath = $directory.'/manifest.sha256';
        if (! File::exists($manifestPath) || ! File::exists($checksumPath)) {
            throw new RuntimeException('Catalog backfill plan not found.');
        }
        $expected = trim((string) File::get($checksumPath));
        if (! hash_equals($expected, hash_file('sha256', $manifestPath))) {
            throw new RuntimeException('Catalog backfill manifest checksum mismatch.');
        }
        $manifest = $this->readJson($manifestPath);
        if ((int) ($manifest['version'] ?? 0) !== self::PLAN_VERSION) {
            throw new RuntimeException('Unsupported catalog backfill plan version.');
        }
        foreach (['operations', 'resolutions'] as $file) {
            $path = $directory.'/'.$manifest[$file.'_file'];
            if (! File::exists($path) || ! hash_equals($manifest[$file.'_sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException(ucfirst($file).' checksum mismatch.');
            }
        }

        return $manifest;
    }

    /** @return array<string, mixed> */
    private function initialCheckpoint(array $manifest): array
    {
        return [
            'version' => self::PLAN_VERSION,
            'plan_id' => $manifest['plan_id'],
            'status' => 'PENDING',
            'completed_operations' => 0,
            'completed_by_map' => [],
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function planDirectory(string $planId): string
    {
        return storage_path('app/private/catalog-backfill/plans/'.$planId);
    }

    private function canonicalJson(array $value): string
    {
        ksort($value);

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function atomicWriteJson(string $path, array $data): void
    {
        File::ensureDirectoryExists(dirname($path), 0700, true);
        $temporary = $path.'.tmp-'.Str::random(8);
        File::put($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        chmod($temporary, 0600);
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot atomically write catalog backfill state.');
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid catalog backfill JSON file.');
        }

        return $decoded;
    }

    private function restrictPlanPermissions(string $directory): void
    {
        @chmod($directory, 0700);
        foreach (File::files($directory) as $file) {
            @chmod($file->getPathname(), 0600);
        }
    }

    private function writeCsv(string $path, array $header, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create catalog backfill CSV report.');
        }
        try {
            fputcsv($handle, $header);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
        } finally {
            fclose($handle);
        }
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '');
    }

    /** @param array<string, mixed> $values @param array<int, string> $keys */
    private function firstValue(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values) && trim((string) $values[$key]) !== '') {
                return (string) $values[$key];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
