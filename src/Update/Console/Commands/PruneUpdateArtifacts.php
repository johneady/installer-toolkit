<?php

declare(strict_types=1);

namespace InstallerToolkit\Update\Console\Commands;

use Illuminate\Console\Command;

/**
 * Deletes abandoned upload artifacts from the standalone updater's storage
 * directory (config updates.updater.storage_dir): chunked upload parts,
 * assembled packages, and staged inner zips. The updater itself clears these
 * on the next upload-init, but an upload that is validated and then never
 * applied would otherwise sit on disk (roughly 2x the package size) forever.
 *
 * Backups, results, the handoff token, and the update-in-progress marker are
 * deliberately untouched — they have their own lifecycles (finalize prunes
 * backups; handoff tokens are single-use with a TTL).
 */
class PruneUpdateArtifacts extends Command
{
    protected $signature = 'update:prune {--hours= : Age in hours after which abandoned upload artifacts are deleted (defaults to config updates.prune_hours)}';

    protected $description = 'Delete stale upload parts, pending packages, and staged zips left behind by abandoned self-update uploads';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? config('updates.prune_hours', 24));
        $cutoff = now()->subHours($hours)->getTimestamp();

        // base_path(), not storage_path(), mirroring HandoffToken and the
        // updater's own updaterStorageDir() so a custom Laravel storage path
        // cannot desync this command from where the updater actually writes.
        $dir = base_path((string) config('updates.updater.storage_dir', 'storage/app/updater'));

        $this->info("Pruning update artifacts older than {$hours} hour(s)...");

        $count = 0;
        $count += $this->pruneFiles($dir.'/upload-*.part', $cutoff);
        $count += $this->pruneFiles($dir.'/pending-*.update', $cutoff);
        $count += $this->pruneFiles($dir.'/staged-*.zip', $cutoff);

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
}
