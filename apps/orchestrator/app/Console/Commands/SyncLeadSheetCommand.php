<?php

namespace App\Console\Commands;

use App\Services\Isir\LeadSheetSyncService;
use Illuminate\Console\Command;

class SyncLeadSheetCommand extends Command
{
    protected $signature = 'leads:sync-sheet {--direction=both : push, pull, or both}';

    protected $description = 'Synchronize leads with Google Sheets.';

    public function handle(LeadSheetSyncService $service): int
    {
        $direction = (string) $this->option('direction');

        if (! in_array($direction, ['push', 'pull', 'both'], true)) {
            $this->error('Direction must be one of: push, pull, both.');

            return self::INVALID;
        }

        $result = $service->sync($direction);

        $this->info(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
