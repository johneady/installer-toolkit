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
}
