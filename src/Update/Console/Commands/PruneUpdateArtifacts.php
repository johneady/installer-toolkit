<?php

declare(strict_types=1);

namespace InstallerToolkit\Update\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PruneUpdateArtifacts extends Command
{
    protected $signature = 'update:prune {--hours=24 : Age in hours after which abandoned upload artifacts are deleted}';

    protected $description = 'Delete stale pending update files, staging directories, and progress files left behind by abandoned self-update uploads';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours)->getTimestamp();

        $this->info("Pruning update artifacts older than {$hours} hour(s)...");

        $count = 0;
        $count += $this->pruneFiles(storage_path('app/pending-update-*.update'), $cutoff);
        $count += $this->pruneFiles(storage_path('app/update-progress-*.json'), $cutoff);
        $count += $this->pruneFiles(storage_path('app/update-check-*.zip'), $cutoff);
        $count += $this->pruneDirectories(storage_path('app/update-staging-*'), $cutoff);
        $count += $this->pruneDirectories(storage_path('app/update-chunks/*'), $cutoff);

        $this->info("Pruned {$count} stale update artifact(s).");

        return self::SUCCESS;
    }

    private function pruneFiles(string $pattern, int $cutoff): int
    {
        $pruned = 0;

        foreach ((glob($pattern) ?: []) as $path) {
            if (is_file($path) && filemtime($path) < $cutoff) {
                @unlink($path);
                $pruned++;
            }
        }

        return $pruned;
    }

    private function pruneDirectories(string $pattern, int $cutoff): int
    {
        $pruned = 0;

        foreach ((glob($pattern) ?: []) as $path) {
            if (is_dir($path) && filemtime($path) < $cutoff) {
                File::deleteDirectory($path);
                $pruned++;
            }
        }

        return $pruned;
    }
}
