<?php

declare(strict_types=1);

namespace InstallerToolkit\Update\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use InstallerToolkit\Update\Models\UpdateHistory;

class UpdateHistoryCommand extends Command
{
    protected $signature = 'update:history {--limit=20 : Number of records to show}';

    protected $description = 'Display the update application history';

    public function handle(): int
    {
        if (! Schema::hasTable('update_history')) {
            $this->info('The update_history table does not exist. Publish and run the migration first.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $records = UpdateHistory::latest()->limit($limit)->get();

        if ($records->isEmpty()) {
            $this->info('No updates have been recorded yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'From', 'To', 'Status', 'Backup', 'Date'],
            $records->map(fn (UpdateHistory $h) => [
                $h->id,
                $h->version_from,
                $h->version_to,
                $h->status,
                $h->backup_id ?? '—',
                optional($h->created_at)->toDateTimeString() ?? '—',
            ])
        );

        return self::SUCCESS;
    }
}
