<?php

namespace App\Console\Commands;

class ImportLegacyRecruitment extends BaseLegacyImportCommand
{
    protected $signature = 'legacy:import-recruitment';

    protected $description = 'Import legacy recruitment and interview records.';

    protected array $tables = [
        'recruitment_positions',
        'recruitment_candidates',
        'recruitment_candidate_cvs',
        'interview_schedules',
        'recruitment_candidate_manager_reviews',
        'recruitment_ai_scoring_jobs',
        'recruitment_rejected_archive',
    ];
}
