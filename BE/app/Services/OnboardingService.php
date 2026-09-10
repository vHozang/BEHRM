<?php

namespace App\Services;

use App\Models\OnboardingChecklist;
use App\Models\OnboardingTask;
use Illuminate\Support\Facades\DB;

/**
 * Light onboarding / offboarding checklist engine.
 *
 * Provides the standard Vietnamese task lists for a new hire (ONBOARDING) and a
 * leaver (OFFBOARDING), creates a checklist instance pre-filled with them, and
 * keeps the checklist status in sync with its task completion.
 */
class OnboardingService
{
    public const TYPES = ['ONBOARDING', 'OFFBOARDING'];

    /**
     * Standard task titles for a checklist type (đúng nghiệp vụ DN Việt Nam).
     *
     * @return array<int, string>
     */
    public function defaultTasks(string $type): array
    {
        // Tenant-configurable (Settings UI) with the VN-standard list as fallback.
        $key = strtoupper($type) === 'OFFBOARDING' ? 'onboarding.tasks_offboarding' : 'onboarding.tasks_onboarding';
        $tasks = \App\Support\HrmConfig::get($key, []);

        return is_array($tasks) && ! empty($tasks) ? array_values($tasks) : [];
    }

    /**
     * Create a checklist for an employee pre-filled with the standard tasks.
     *
     * @param  array<int, string>|null  $taskTitles  override the default task list
     */
    public function createChecklist(int $employeeId, string $type, ?string $startDate, ?string $notes, ?array $taskTitles = null): OnboardingChecklist
    {
        $type = in_array(strtoupper($type), self::TYPES, true) ? strtoupper($type) : 'ONBOARDING';
        $titles = $taskTitles ?? $this->defaultTasks($type);

        return DB::transaction(function () use ($employeeId, $type, $startDate, $notes, $titles): OnboardingChecklist {
            $checklist = OnboardingChecklist::create([
                'employee_id' => $employeeId,
                'type' => $type,
                'status' => 'IN_PROGRESS',
                'start_date' => $startDate,
                'notes' => $notes,
            ]);

            foreach (array_values($titles) as $i => $title) {
                $title = trim((string) $title);
                if ($title === '') {
                    continue;
                }
                // Omit is_done — the column defaults to false. Writing a PHP
                // bool through Eloquent binds an integer that PG's boolean
                // column rejects (see recomputeStatus' whereRaw note).
                OnboardingTask::create([
                    'checklist_id' => $checklist->id,
                    'title' => $title,
                    'sort_order' => $i,
                ]);
            }

            return $checklist;
        });
    }

    /**
     * Recompute and persist the checklist status from its tasks.
     * CANCELLED is sticky (never auto-flipped). Returns the resolved status.
     */
    public function recomputeStatus(OnboardingChecklist $checklist): string
    {
        if ($checklist->status === 'CANCELLED') {
            return 'CANCELLED';
        }

        // whereRaw: `->where('is_done', true)` binds a PHP bool that PG rejects
        // as `boolean = integer` on this stack — use a raw boolean literal.
        $total = $checklist->tasks()->count();
        $done = $checklist->tasks()->whereRaw('is_done = true')->count();

        $status = ($total > 0 && $done === $total) ? 'COMPLETED' : 'IN_PROGRESS';

        if ($status !== $checklist->status) {
            $checklist->update(['status' => $status]);
        }

        return $status;
    }
}
