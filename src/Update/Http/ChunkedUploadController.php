<?php

declare(strict_types=1);

namespace InstallerToolkit\Update\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use InstallerToolkit\Update\UpdateService;

class ChunkedUploadController extends Controller
{
    private const CHUNKS_DIR = 'app/update-chunks';

    /**
     * Initialize a new chunked upload session.
     */
    public function initialize(Request $request): JsonResponse
    {
        $this->authorizeUpload($request);

        $request->validate([
            'fileName' => ['required', 'string', 'regex:/\.update$/i'],
            'fileSize' => ['required', 'integer', 'min:1', 'max:107374182400'],
            'totalChunks' => ['required', 'integer', 'min:1'],
        ]);

        $uploadId = bin2hex(random_bytes(16));
        $chunksDir = storage_path(self::CHUNKS_DIR.'/'.$uploadId);

        File::ensureDirectoryExists($chunksDir);

        file_put_contents($chunksDir.'/meta.json', json_encode([
            'fileName' => $request->input('fileName'),
            'fileSize' => $request->integer('fileSize'),
            'totalChunks' => $request->integer('totalChunks'),
            'receivedChunks' => [],
        ]));

        return response()->json(['uploadId' => $uploadId]);
    }

    /**
     * Receive a single chunk.
     */
    public function chunk(Request $request): JsonResponse
    {
        $this->authorizeUpload($request);

        $request->validate([
            'uploadId' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'chunkIndex' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ]);

        $uploadId = $request->input('uploadId');
        $chunkIndex = $request->integer('chunkIndex');
        $chunksDir = storage_path(self::CHUNKS_DIR.'/'.$uploadId);
        $metaPath = $chunksDir.'/meta.json';

        if (! file_exists($metaPath)) {
            return response()->json(['error' => 'Upload session not found.'], 404);
        }

        $meta = json_decode((string) file_get_contents($metaPath), true);

        if ($chunkIndex >= $meta['totalChunks']) {
            return response()->json(['error' => 'Invalid chunk index.'], 422);
        }

        $request->file('chunk')->move($chunksDir, "chunk_{$chunkIndex}");

        // The read-modify-write of meta.json must be serialized: the shipped
        // uploader sends chunks sequentially, but a parallelized client (or a
        // retried request racing its original) would otherwise lose entries
        // from receivedChunks and strand a fully-uploaded session at 422.
        $handle = fopen($metaPath, 'c+');

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            if ($handle !== false) {
                fclose($handle);
            }

            return response()->json(['error' => 'Could not update upload session metadata.'], 500);
        }

        try {
            $meta = json_decode((string) stream_get_contents($handle), true) ?: $meta;
            $meta['receivedChunks'][] = $chunkIndex;
            $meta['receivedChunks'] = array_values(array_unique($meta['receivedChunks']));

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($meta));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return response()->json([
            'received' => count($meta['receivedChunks']),
            'total' => $meta['totalChunks'],
        ]);
    }

    /**
     * Assemble all chunks into the final .update file.
     */
    public function assemble(Request $request): JsonResponse
    {
        $this->authorizeUpload($request);

        $request->validate([
            'uploadId' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
        ]);

        $uploadId = $request->input('uploadId');
        $chunksDir = storage_path(self::CHUNKS_DIR.'/'.$uploadId);
        $metaPath = $chunksDir.'/meta.json';

        if (! file_exists($metaPath)) {
            return response()->json(['error' => 'Upload session not found.'], 404);
        }

        $meta = json_decode((string) file_get_contents($metaPath), true);

        if (count($meta['receivedChunks']) !== $meta['totalChunks']) {
            return response()->json([
                'error' => 'Not all chunks have been received.',
                'received' => count($meta['receivedChunks']),
                'total' => $meta['totalChunks'],
            ], 422);
        }

        $finalPath = app(UpdateService::class)->pendingUpdatePath($uploadId);
        $output = fopen($finalPath, 'wb');

        for ($i = 0; $i < $meta['totalChunks']; $i++) {
            $chunkPath = $chunksDir."/chunk_{$i}";
            if (! file_exists($chunkPath)) {
                fclose($output);
                @unlink($finalPath);

                return response()->json(['error' => "Missing chunk {$i}."], 422);
            }

            fwrite($output, (string) file_get_contents($chunkPath));
        }

        fclose($output);

        File::deleteDirectory($chunksDir);

        return response()->json(['assembled' => true]);
    }

    /**
     * Authorize the upload using the configured callback, falling back to the
     * default admin check (authenticated user with a truthy is_admin flag).
     */
    protected function authorizeUpload(Request $request): void
    {
        $authorizer = config('updates.authorize_upload');

        if ($authorizer !== null) {
            $allowed = app()->call($authorizer, ['request' => $request]);

            if (! $allowed) {
                abort(403, 'You do not have permission to upload updates.');
            }

            return;
        }

        if (! $request->user()?->is_admin) {
            abort(403, 'You do not have permission to upload updates.');
        }
    }
}
