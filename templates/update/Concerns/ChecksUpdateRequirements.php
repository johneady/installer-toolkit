<?php

trait ChecksUpdateRequirements
{
    /**
     * Environment checks shown on the updater's status screen. Mirrors the
     * installer's requirements step, scoped to what an update actually
     * needs: the zip/sodium extensions, writable application directories,
     * and a parseable current version.
     */
    private function checkUpdateRequirements(): array
    {
        $results = [];

        $results[] = [
            'name' => 'PHP Version >= '.MIN_PHP_VERSION,
            'detail' => 'Current: '.PHP_VERSION,
            'passed' => version_compare(PHP_VERSION, MIN_PHP_VERSION, '>='),
            'critical' => true,
            'group' => 'php',
        ];

        foreach (['zip', 'json'] as $ext) {
            $results[] = [
                'name' => "PHP Extension: {$ext}",
                'detail' => extension_loaded($ext) ? 'Loaded' : 'Not loaded',
                'passed' => extension_loaded($ext),
                'critical' => true,
                'group' => 'php',
            ];
        }

        // Signature verification is fail-closed: when the app config trusts
        // signing keys, a missing sodium extension blocks updates entirely,
        // so surface it as critical in exactly that case.
        $signatureRequired = $this->trustedKeys() !== [];
        $results[] = [
            'name' => 'PHP Extension: sodium'.($signatureRequired ? '' : ' (optional)'),
            'detail' => extension_loaded('sodium')
                ? 'Loaded'
                : ($signatureRequired
                    ? 'Not loaded — this application requires signed updates, which cannot be verified without sodium.'
                    : 'Not loaded — update packages will not be signature-verified.'),
            'passed' => extension_loaded('sodium'),
            'critical' => $signatureRequired,
            'group' => 'php',
        ];

        // Writability, verified by real write tests. The app root receives
        // extracted files, storage/ holds the working area, public/ must
        // accept the refreshed updater.php.
        foreach ([
            'Application Root Writable' => $this->appRoot(),
            'storage/app Writable' => $this->appRoot().'/storage/app',
            'public/ Writable' => $this->appRoot().'/public',
        ] as $label => $dir) {
            $writable = $this->verifyWritableByTest($dir);
            $results[] = [
                'name' => $label,
                'detail' => $writable ? 'Writable (verified by write test)' : "Not writable: {$dir}",
                'passed' => $writable,
                'critical' => true,
            ];
        }

        $version = $this->currentVersion();
        $results[] = [
            'name' => 'Installed Version Detected',
            'detail' => $version !== null
                ? "v{$version} (from config/app.php)"
                : 'Could not read a version literal from config/app.php — updates cannot be version-checked.',
            'passed' => $version !== null,
            'critical' => true,
        ];

        // Database backup tooling — non-critical: a missing mysqldump means
        // the update proceeds with a files-only backup.
        $backup = $this->backupConfig();
        if (($backup['enabled'] ?? true) && ($backup['database']['enabled'] ?? true)) {
            $db = $this->databaseSettings();

            if (in_array($db['connection'], ['mysql', 'mariadb'], true)) {
                $available = function_exists('proc_open');
                $results[] = [
                    'name' => 'Database Backup (mysqldump)',
                    'detail' => $available
                        ? 'proc_open available — the database will be dumped before updating.'
                        : 'proc_open is disabled — the update will run with a files-only backup.',
                    'passed' => $available,
                    'critical' => false,
                ];
            }
        }

        $free = function_exists('disk_free_space') ? @disk_free_space($this->appRoot()) : false;
        if ($free !== false) {
            $results[] = [
                'name' => 'Free Disk Space',
                'detail' => $this->formatBytes((int) $free).' free. Updating needs roughly the uploaded package size × 4 (upload + staging + backup + extraction).',
                'passed' => true,
                'critical' => false,
            ];
        }

        return $results;
    }

    private function allCriticalRequirementsPass(): bool
    {
        foreach ($this->checkUpdateRequirements() as $result) {
            if ($result['critical'] && ! $result['passed']) {
                return false;
            }
        }

        return true;
    }
}
