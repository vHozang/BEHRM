<?php

namespace App\Console\Commands;

class ImportLegacyEmployees extends BaseLegacyImportCommand
{
    protected $signature = 'legacy:import-employees';

    protected $description = 'Import legacy employees and employee role links.';

    protected array $tables = [
        'employees', 'employee_roles', 'qualifications', 'certificates',
        'identity_documents', 'social_insurance_info', 'dependents',
        'employment_histories',
    ];
}
