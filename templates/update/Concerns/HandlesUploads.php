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

        // Running out of space mid-run is one of the ugliest ways an update
        // can fail on shared hosting — reject up front rather than
        // discovering it partway through backup or extraction. (The size
        // heuristic lives in diskSpaceShortfall(), shared with the review
        // screen and the start-update guard.)
        if ($shortfall = $this->diskSpaceShortfall($size, $this->updaterStorageDir())) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Not enough free disk space for this update. Needs roughly '.$this->formatBytes($shortfall['needed']).', but only '.$this->formatBytes($shortfall['free']).' is available.',
            ]);
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
            // A retried final chunk whose original response was lost: the
            // upload state was already cleared when the package finished
            // assembling and validating, but the validated package it
            // produced is still in the session — replay that completion
            // response instead of failing the client into a full re-upload.
            $package = $_SESSION['updater']['package'] ?? null;
            if ($uploadId !== '' && is_array($package)
                && hash_equals((string) ($package['upload_id'] ?? ''), $uploadId)) {
                $this->jsonResponse([
                    'success' => true,
                    'complete' => true,
                    'valid' => true,
                    'version' => $package['version'],
                ]);
            }

            $this->jsonResponse(['success' => false, 'message' => 'No upload in progress. Start again.']);
        }

        // The client resending the chunk immediately before next_index is
        // not "out of order" — it's a retry after a dropped response (the
        // chunk landed and was acked server-side, but the ack never reached
        // the browser). That exact chunk is already fully appended to
        // partPath, so just re-acknowledge it instead of failing the whole
        // multi-hundred-MB upload over one flaky response. Retrying the
        // *final* chunk this way falls through to the assembly/validation
        // logic below exactly as if it were the original request for that
        // index, rather than re-reporting an incomplete state.
        $isRetryOfPreviousChunk = $index === (int) $upload['next_index'] - 1;

        if ($isRetryOfPreviousChunk && $index + 1 < (int) $upload['total_chunks']) {
            $this->jsonResponse([
                'success' => true,
                'complete' => false,
                'received' => (int) $upload['next_index'],
                'total' => (int) $upload['total_chunks'],
            ]);
        }

        if (! $isRetryOfPreviousChunk && $index !== (int) $upload['next_index']) {
            $this->jsonResponse(['success' => false, 'message' => "Chunk out of order (expected {$upload['next_index']}, got {$index}). Start the upload again."]);
        }

        $partPath = $this->uploadPartPath($uploadId);

        // A retry of the final chunk: its bytes are already appended to
        // partPath from the original request (only the response was lost),
        // so skip straight to assembly/validation below instead of
        // re-appending and duplicating the tail of the file.
        if (! $isRetryOfPreviousChunk) {
            $chunk = $_FILES['chunk'] ?? null;

            if (! is_array($chunk) || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $this->jsonResponse(['success' => false, 'message' => 'Chunk upload failed (PHP upload error '.(int) ($chunk['error'] ?? -1).'). Check post_max_size/upload_max_filesize.']);
            }

            if (! is_uploaded_file($chunk['tmp_name'])) {
                $this->jsonResponse(['success' => false, 'message' => 'Chunk upload rejected (not a genuine upload).']);
            }

            if ($chunk['size'] > self::MAX_CHUNK_BYTES) {
                $this->jsonResponse(['success' => false, 'message' => 'Chunk too large.']);
            }

            $appendOffset = $index === 0 ? 0 : (int) @filesize($partPath);

            $target = fopen($partPath, $index === 0 ? 'wb' : 'ab');
            $source = fopen($chunk['tmp_name'], 'rb');

            if ($target === false || $source === false) {
                $this->jsonResponse(['success' => false, 'message' => 'Failed to write the upload to storage/app/updater. Check permissions and disk space.']);
            }

            $copied = stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);

            // A short write (e.g. the disk filled up mid-chunk) must fail
            // this chunk immediately rather than silently ack a truncated
            // write — otherwise it only surfaces once every chunk of a
            // multi-hundred-MB upload has been sent, as a generic "upload
            // incomplete" with no indication which chunk actually failed.
            // Roll the part file back to its pre-append length first: the
            // client's lost-response retry re-sends this same index, and it
            // must append onto clean state, not after the truncated bytes.
            if ($copied !== $chunk['size']) {
                if (($handle = @fopen($partPath, 'r+b')) !== false) {
                    ftruncate($handle, $appendOffset);
                    fclose($handle);
                }

                $this->jsonResponse(['success' => false, 'message' => 'Failed to write the full chunk to storage/app/updater. Check available disk space.']);
            }

            $_SESSION['updater']['upload']['next_index'] = $index + 1;
        }

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
