<?php

namespace App\Console\Commands;

class ImportLegacyCommunications extends BaseLegacyImportCommand
{
    protected $signature = 'legacy:import-communications';

    protected $description = 'Import legacy communication, notification, service, and settings records.';

    protected array $tables = [
        'news_categories', 'news', 'news_reads', 'policies',
        'policy_acknowledgments', 'notifications', 'dashboard_views',
        'report_templates', 'report_histories', 'service_categories',
        'service_tickets', 'service_ticket_updates', 'system_configs',
        'notification_configs',
    ];
}
