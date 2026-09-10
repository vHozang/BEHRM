<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrganizationStructureSeeder extends Seeder
{
    public function run(): void
    {
        foreach (DB::table('departments')->orderBy('id')->get() as $department) {
            $meta = $this->decode($department->meta ?? null);
            if (! in_array(strtoupper((string) ($meta['unit_type'] ?? '')), ['DEPARTMENT', 'WORKSHOP', 'TEAM'], true)) {
                $code = strtoupper((string) ($department->department_code ?? ''));
                $name = mb_strtolower(trim((string) ($department->department_name ?? '')));
                $meta['unit_type'] = str_starts_with($code, 'PX-') || str_starts_with($name, 'phân xưởng')
                    ? 'WORKSHOP'
                    : (str_starts_with($name, 'tổ') ? 'TEAM' : 'DEPARTMENT');
                DB::table('departments')->where('id', $department->id)->update([
                    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (DB::table('legal_entities')->orderBy('id')->get() as $entity) {
            $meta = $this->decode($entity->meta ?? null);
            if (strtoupper((string) ($meta['branch_type'] ?? '')) === 'HEAD_OFFICE') {
                continue;
            }

            $currentHead = ! empty($meta['head_employee_id'])
                ? DB::table('employees')
                    ->where('id', (int) $meta['head_employee_id'])
                    ->where('tenant_id', $entity->tenant_id)
                    ->where('legal_entity_id', $entity->id)
                    ->whereIn('status', ['ACTIVE', 'PROBATION'])
                    ->first(['id', 'profile'])
                : null;
            if ($currentHead && ! $this->isSystemAccount($currentHead->profile ?? null)) {
                continue;
            }

            $managerIds = DB::table('departments')
                ->where('tenant_id', $entity->tenant_id)
                ->where('legal_entity_id', $entity->id)
                ->orderBy('id')
                ->pluck('meta')
                ->map(fn ($value) => (int) ($this->decode($value)['manager_id'] ?? 0))
                ->filter()
                ->values();

            $headId = DB::table('employees')
                ->where('tenant_id', $entity->tenant_id)
                ->where('legal_entity_id', $entity->id)
                ->whereIn('status', ['ACTIVE', 'PROBATION'])
                ->whereIn('id', $managerIds)
                ->orderBy('id')
                ->get(['id', 'profile'])
                ->first(fn ($employee) => ! $this->isSystemAccount($employee->profile ?? null))?->id;

            if ($headId) {
                $meta['head_employee_id'] = (int) $headId;
                DB::table('legal_entities')->where('id', $entity->id)->update([
                    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            } elseif (array_key_exists('head_employee_id', $meta)) {
                $meta['head_employee_id'] = null;
                DB::table('legal_entities')->where('id', $entity->id)->update([
                    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function isSystemAccount(mixed $profile): bool
    {
        return in_array($this->decode($profile)['system_account'] ?? false, [true, 1, '1', 't', 'true', 'TRUE'], true);
    }
}
