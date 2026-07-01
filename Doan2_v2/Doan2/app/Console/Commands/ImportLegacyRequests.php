<?php

namespace App\Console\Commands;

class ImportLegacyRequests extends BaseLegacyImportCommand
{
    protected $signature = 'legacy:import-requests';

    protected $description = 'Import legacy requests and approval data.';

    protected array $tables = [
        'approval_roles', 'approval_flows', 'approval_steps', 'request_types',
        'requests', 'approval_histories', 'request_attachments',
    ];
}
