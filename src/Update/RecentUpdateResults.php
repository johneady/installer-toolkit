<?php

declare(strict_types=1);

namespace InstallerToolkit\Update;

/**
 * Reads the standalone updater's results log so the admin panel can show
 * recent update outcomes.
 *
 * The updater writes one JSON file per run to
 * storage/app/updater/results/{Ymd-His}-{status}.json. Filenames embed the
 * timestamp in a lexically-sortable form, so this reader orders newest-first
 * without parsing each file. Corrupted or truncated writes are skipped rather
 * than surfaced, since the updater never blocks a build on a log write.
 */
class RecentUpdateResults
{
    public function __construct(private ?string $directory = null)
    {
        // Mirrors the updater's resultsDir() (updaterStorageDir().'/results'),
        // reading the same config value so the two can never diverge.
        $this->directory = $directory ?? base_path((string) config('updates.updater.storage_dir', 'storage/app/updater').'/results');
    }

    /**
     * @return list<UpdateResult>
     */
    public function take(int $limit = 5): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }

        $files = glob($this->directory.'/*.json') ?: [];

        // Filenames begin with Ymd-His, so a lexical descending sort equals
        // newest-first without touching each file's contents.
        rsort($files);

        $results = [];

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (! is_array($data)) {
                continue;
            }

            $results[] = UpdateResult::fromArray($data, basename($file));

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * The earliest update result that records a versionFrom, used to infer
     * the originally installed version (the version the app ran before its
     * first update). Walks oldest-first and skips results without a
     * versionFrom, so an early failed run that never captured the version
     * falls through to the next record rather than pinning a wrong version.
     * Returns null when no results exist or none carry a versionFrom.
     */
    public function oldest(): ?UpdateResult
    {
        if (! is_dir($this->directory)) {
            return null;
        }

        $files = glob($this->directory.'/*.json') ?: [];

        if ($files === []) {
            return null;
        }

        // Filenames begin with Ymd-His, so an ascending sort puts the oldest
        // first. Walk from there, skipping corrupt files and results that
        // never captured a versionFrom.
        sort($files);

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (! is_array($data)) {
                continue;
            }

            $result = UpdateResult::fromArray($data, basename($file));

            if ($result->versionFrom !== null) {
                return $result;
            }
        }

        return null;
    }
}
