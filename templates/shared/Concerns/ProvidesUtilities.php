<?php

/**
 * Small dependency-free helpers shared between the installer and updater.
 */
trait ProvidesUtilities
{
    /**
     * Test writability by actually creating and deleting a temp file.
     * More trustworthy than is_writable(), which can report false
     * positives under ACLs, disk quotas, or open_basedir restrictions.
     */
    private function verifyWritableByTest(string $dir): bool
    {
        if (! is_dir($dir)) {
            return false;
        }

        $testFile = rtrim($dir, '/').'/.install-write-test-'.uniqid();
        if (@file_put_contents($testFile, 'test') === false) {
            return false;
        }
        @unlink($testFile);

        return true;
    }

    /**
     * Deliberately a separate implementation from
     * PackageBuildCommand::formatBytes() (that one is Composer-autoloaded
     * CLI code with its own pinned output format) — this one must stay
     * dependency-free so bin/build can inline it into the standalone
     * installer/updater, and formats for the wizard UI rather than a CLI
     * summary table.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $value >= 100 ? 0 : 1).' '.$units[$i];
    }

    /**
     * Whether a submitted port string is a valid TCP port number. Used for
     * both db_port and mail_port on the installer's wizard forms — an
     * interior newline or non-numeric garbage in either would otherwise be
     * written straight into .env unescaped and unquoted, corrupting the file
     * or being silently misinterpreted.
     */
    private function isValidPort(string $port): bool
    {
        return ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535;
    }
}
