<?php

/**
 * Chunked .update upload, in plain PHP. The browser slices the file and
 * POSTs sequential chunks, each comfortably below the ~2 MB
 * upload_max_filesize floor common on shared hosting — so a multi-hundred-MB
 * package uploads reliably without any php.ini changes.
 */
trait HandlesUploads
{
    /** Server-side ceiling per chunk; the client sends 1.5 MB chunks. */
    private const MAX_CHUNK_BYTES = 4194304;

    /** Refuse packages over this size outright (corrupt/abusive uploads). */
    private const MAX_PACKAGE_BYTES = 2147483648;

    private function uploadPartPath(string $uploadId): string
    {
        return $this->updaterStorageDir().'/upload-'.$uploadId.'.part';
    }

    private function pendingPackagePath(string $uploadId): string
    {
        return $this->updaterStorageDir().'/pending-'.$uploadId.'.update';
    }

    private function stagedZipPath(string $uploadId): string
    {
        return $this->updaterStorageDir().'/staged-'.$uploadId.'.zip';
    }

    private function handleUploadInit(): void
    {
        $name = (string) ($_POST['name'] ?? '');
        $size = (int) ($_POST['size'] ?? 0);
        $totalChunks = (int) ($_POST['total_chunks'] ?? 0);

        if (! str_ends_with(strtolower($name), '.update')) {
            $this->jsonResponse(['success' => false, 'message' => 'Please choose a .update package file.']);
        }

        if ($size <= 0 || $size > self::MAX_PACKAGE_BYTES || $totalChunks <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Invalid upload size.']);
        }

        // Discard any previous attempt's artifacts before starting fresh.
        $this->discardPackageArtifacts();

        $uploadId = bin2hex(random_bytes(16));

        $_SESSION['updater']['upload'] = [
            'id' => $uploadId,
            'size' => $size,
            'total_chunks' => $totalChunks,
            'next_index' => 0,
        ];
        unset($_SESSION['updater']['package']);

        $this->jsonResponse(['success' => true, 'upload_id' => $uploadId]);
    }

    private function handleUploadChunk(): void
    {
        $upload = $_SESSION['updater']['upload'] ?? null;
        $uploadId = (string) ($_POST['upload_id'] ?? '');
        $index = (int) ($_POST['index'] ?? -1);

        if (! is_array($upload) || $uploadId === '' || ! hash_equals($upload['id'], $uploadId)) {
            $this->jsonResponse(['success' => false, 'message' => 'No upload in progress. Start again.']);
        }

        if ($index !== (int) $upload['next_index']) {
            $this->jsonResponse(['success' => false, 'message' => "Chunk out of order (expected {$upload['next_index']}, got {$index}). Start the upload again."]);
        }

        $chunk = $_FILES['chunk'] ?? null;

        if (! is_array($chunk) || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->jsonResponse(['success' => false, 'message' => 'Chunk upload failed (PHP upload error '.(int) ($chunk['error'] ?? -1).'). Check post_max_size/upload_max_filesize.']);
        }

        if ($chunk['size'] > self::MAX_CHUNK_BYTES) {
            $this->jsonResponse(['success' => false, 'message' => 'Chunk too large.']);
        }

        $partPath = $this->uploadPartPath($uploadId);
        $target = fopen($partPath, $index === 0 ? 'wb' : 'ab');
        $source = fopen($chunk['tmp_name'], 'rb');

        if ($target === false || $source === false) {
            $this->jsonResponse(['success' => false, 'message' => 'Failed to write the upload to storage/app/updater. Check permissions and disk space.']);
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        $_SESSION['updater']['upload']['next_index'] = $index + 1;

        if ($index + 1 < (int) $upload['total_chunks']) {
            $this->jsonResponse([
                'success' => true,
                'complete' => false,
                'received' => $index + 1,
                'total' => (int) $upload['total_chunks'],
            ]);
        }

        // Last chunk: assemble and validate.
        $received = filesize($partPath);
        if ($received !== (int) $upload['size']) {
            @unlink($partPath);
            unset($_SESSION['updater']['upload']);
            $this->jsonResponse(['success' => false, 'message' => "Upload incomplete: received {$received} of {$upload['size']} bytes. Try again."]);
        }

        $pendingPath = $this->pendingPackagePath($uploadId);
        if (! rename($partPath, $pendingPath)) {
            $this->jsonResponse(['success' => false, 'message' => 'Failed to finalize the uploaded package.']);
        }

        unset($_SESSION['updater']['upload']);

        $validation = $this->validateUpdatePackage($pendingPath, $uploadId);

        if (! $validation['valid']) {
            @unlink($pendingPath);
            @unlink($this->stagedZipPath($uploadId));
            $this->jsonResponse(['success' => true, 'complete' => true, 'valid' => false, 'message' => $validation['error']]);
        }

        $_SESSION['updater']['package'] = [
            'valid' => true,
            'upload_id' => $uploadId,
            'path' => $pendingPath,
            'staged_zip' => $this->stagedZipPath($uploadId),
            'version' => $validation['version'],
            'files_count' => $validation['files_count'],
            'minimum_php' => $validation['minimum_php'],
            'signed_by' => $validation['signed_by'],
        ];

        $this->jsonResponse([
            'success' => true,
            'complete' => true,
            'valid' => true,
            'version' => $validation['version'],
        ]);
    }

    /**
     * Remove any pending/staged package artifacts from earlier attempts so
     * abandoned uploads never pile up in storage.
     */
    private function discardPackageArtifacts(): void
    {
        // Three globs instead of GLOB_BRACE — brace expansion is a glibc
        // extension and silently unavailable on musl-based hosts.
        foreach (['upload-', 'pending-', 'staged-'] as $prefix) {
            foreach (glob($this->updaterStorageDir().'/'.$prefix.'*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
}
