<?php

/**
 * Validation of an uploaded .update package: manifest sanity, version
 * ordering, checksum, Ed25519 signature, and a path-traversal scan of the
 * inner zip. This is a dependency-free port of the Laravel package's
 * UpdateService::validateUpdateZip() — the canonical payload format below
 * MUST stay in lockstep with InstallerToolkit\Update\UpdateSignature.
 */
trait ValidatesUpdatePackage
{
    private const SIGNATURE_FORMAT_VERSION = 1;

    /**
     * @return array{valid: bool, error: ?string, version: ?string, files_count: ?int, minimum_php: ?string, signed_by: ?string}
     */
    private function validateUpdatePackage(string $zipPath, string $uploadId): array
    {
        $fail = fn (string $error) => [
            'valid' => false, 'error' => $error, 'version' => null,
            'files_count' => null, 'minimum_php' => null, 'signed_by' => null,
        ];

        $currentVersion = $this->currentVersion();

        if ($currentVersion === null) {
            return $fail('Could not determine the installed version from config/app.php.');
        }

        if (! file_exists($zipPath)) {
            return $fail('Update file not found.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return $fail('Unable to open update file. The zip may be corrupted.');
        }

        $manifestContent = $zip->getFromName('manifest.json');
        if ($manifestContent === false) {
            $zip->close();

            return $fail('Invalid update package: missing manifest.json.');
        }

        $manifest = json_decode($manifestContent, true);
        if (! is_array($manifest)) {
            $zip->close();

            return $fail('Invalid update package: manifest.json is malformed.');
        }

        if (($manifest['type'] ?? '') !== 'update') {
            $zip->close();

            return $fail('Invalid update package: not an update package.');
        }

        $updateVersion = (string) ($manifest['version'] ?? '');
        if (! preg_match('/^\d+\.\d+\.\d+$/', $updateVersion)) {
            $zip->close();

            return $fail("Invalid version format in update package: '{$updateVersion}'.");
        }

        // Ordinarily the package must be strictly newer. The one exception:
        // config/app.php's version literal is overwritten by extraction
        // partway through applying an update, before post_update/verify/
        // finalize complete — so a run that fails after extraction but
        // before finalize leaves the site on the new version literal.
        // Without this exception, re-uploading that exact same package to
        // finish the job would be rejected as "not newer than current",
        // stranding the site between versions with no way to retry. The
        // on-disk marker written at taskPrepare() (and cleared at finalize/
        // restore) is what distinguishes an interrupted run from a normal
        // completed update — and it survives a lost browser session, which
        // is precisely the stranding scenario.
        $sameVersionInterrupted = version_compare($updateVersion, $currentVersion, '==')
            && $this->interruptedUpdateVersion() === $updateVersion;

        if (version_compare($updateVersion, $currentVersion, '<=') && ! $sameVersionInterrupted) {
            $zip->close();

            return $fail("Update version ({$updateVersion}) must be newer than the current version ({$currentVersion}).");
        }

        $minimumVersion = (string) ($manifest['minimum_version'] ?? '1.0.0');
        if (version_compare($currentVersion, $minimumVersion, '<')) {
            $zip->close();

            return $fail("This update requires version {$minimumVersion} or later. You are running {$currentVersion}.");
        }

        $minimumPhp = (string) ($manifest['minimum_php'] ?? '8.2.0');
        if (version_compare(PHP_VERSION, $minimumPhp, '<')) {
            $zip->close();

            return $fail("This update requires PHP {$minimumPhp} or later. You are running PHP ".PHP_VERSION.'.');
        }

        $innerZipName = $this->innerZipName();

        if ($zip->locateName($innerZipName) === false) {
            $zip->close();

            return $fail("Invalid update package: missing {$innerZipName}.");
        }

        // Stream the inner zip out to the staging file — real packages run
        // to hundreds of MB, and getFromName() would load the whole thing
        // into memory (fatal under a shared host's memory_limit). The staged
        // copy serves the checksum and traversal checks below, then becomes
        // the extraction source, so this copy happens exactly once.
        $stagedPath = $this->stagedZipPath($uploadId);

        try {
            $this->copyZipEntryToFile($zip, $innerZipName, $stagedPath);
        } catch (RuntimeException) {
            $zip->close();
            @unlink($stagedPath);

            return $fail('Failed to read the inner update archive. The file may be corrupted.');
        }

        $zip->close();

        $expectedChecksum = (string) ($manifest['checksum'] ?? '');
        if ($expectedChecksum !== '' && hash_file('sha256', $stagedPath) !== $expectedChecksum) {
            @unlink($stagedPath);

            return $fail('Update package integrity check failed. The file may be corrupted.');
        }

        $signatureError = $this->verifyManifestSignature($manifest, $updateVersion, $minimumVersion, $expectedChecksum);
        if ($signatureError !== null) {
            @unlink($stagedPath);

            return $fail($signatureError);
        }

        // Verify no path traversal in the inner zip.
        $innerZip = new ZipArchive;
        if ($innerZip->open($stagedPath) === true) {
            for ($i = 0; $i < $innerZip->numFiles; $i++) {
                $name = (string) $innerZip->getNameIndex($i);
                if (str_contains($name, '..')) {
                    $innerZip->close();
                    @unlink($stagedPath);

                    return $fail('Update package contains invalid file paths.');
                }
            }
            $innerZip->close();
        }

        return [
            'valid' => true,
            'error' => null,
            'version' => $updateVersion,
            'files_count' => isset($manifest['files_count']) ? (int) $manifest['files_count'] : null,
            'minimum_php' => $minimumPhp,
            'signed_by' => isset($manifest['key_id']) ? (string) $manifest['key_id'] : null,
        ];
    }

    /**
     * Verify the manifest's Ed25519 signature. Returns null when the package
     * is acceptable (either verified, or no trusted keys are configured so
     * verification is not required), or an error message when it must be
     * rejected. Fail-closed, identical to the Laravel-side check.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function verifyManifestSignature(array $manifest, string $version, string $minimumVersion, string $checksum): ?string
    {
        $trustedKeys = $this->trustedKeys();

        if ($trustedKeys === []) {
            return null;
        }

        if (! extension_loaded('sodium')) {
            return 'Signature verification is required but the sodium PHP extension is not available on this server.';
        }

        $signature = (string) ($manifest['signature'] ?? '');
        $keyId = (string) ($manifest['key_id'] ?? '');

        if ($signature === '' || $keyId === '') {
            return 'This update package is not signed, but signature verification is required on this server.';
        }

        if (! isset($trustedKeys[$keyId])) {
            return "This update package is signed with an unrecognized key ('{$keyId}').";
        }

        // Must byte-match InstallerToolkit\Update\UpdateSignature::canonicalPayload().
        $payload = implode("\n", [
            'update-signature-v'.self::SIGNATURE_FORMAT_VERSION,
            $this->slug(),
            $version,
            $minimumVersion,
            $checksum,
            $keyId,
        ]);

        $publicKey = base64_decode($trustedKeys[$keyId], true);
        $signatureRaw = base64_decode($signature, true);

        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $signatureRaw === false || strlen($signatureRaw) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signatureRaw, $payload, $publicKey)) {
            return 'Update package signature verification failed. The file may have been tampered with.';
        }

        return null;
    }

    /**
     * Stream a single zip entry to a file on disk without buffering the
     * whole entry in memory.
     */
    private function copyZipEntryToFile(ZipArchive $zip, string $entryName, string $targetPath): void
    {
        $source = $zip->getStream($entryName);
        if ($source === false) {
            throw new RuntimeException("Failed to read {$entryName} from the archive.");
        }

        $target = fopen($targetPath, 'wb');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException("Failed to open {$targetPath} for writing.");
        }

        $copied = stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        if ($copied === false) {
            @unlink($targetPath);
            throw new RuntimeException("Failed to write {$targetPath}. Check disk space and permissions.");
        }
    }
}
