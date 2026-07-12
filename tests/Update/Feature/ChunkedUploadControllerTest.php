<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use InstallerToolkit\Update\Http\ChunkedUploadController;
use Symfony\Component\HttpKernel\Exception\HttpException;

function controller(): ChunkedUploadController
{
    return app(ChunkedUploadController::class);
}

it('aborts when the configured authorizer denies the upload', function (): void {
    config(['updates.authorize_upload' => fn (Request $request) => false]);

    $request = Request::create('/initialize', 'POST', [
        'fileName' => 'x.update',
        'fileSize' => 10,
        'totalChunks' => 1,
    ]);

    expect(fn () => controller()->initialize($request))
        ->toThrow(HttpException::class);
});

it('authorizes via the configured callback and initializes a session', function (): void {
    config(['updates.authorize_upload' => fn (Request $request) => true]);

    $request = Request::create('/initialize', 'POST', [
        'fileName' => 'x.update',
        'fileSize' => 10,
        'totalChunks' => 1,
    ]);

    $response = controller()->initialize($request);

    expect($response->getStatusCode())->toBe(200);

    $data = json_decode($response->getContent(), true);
    expect($data)->toHaveKey('uploadId')
        ->and(preg_match('/^[a-f0-9]{32}$/', $data['uploadId']))->toBe(1);
});

it('denies non-admin users by default', function (): void {
    config(['updates.authorize_upload' => null]);

    $user = new class
    {
        public bool $is_admin = false;
    };

    $request = Request::create('/initialize', 'POST', [
        'fileName' => 'x.update',
        'fileSize' => 10,
        'totalChunks' => 1,
    ]);
    $request->setUserResolver(fn () => $user);

    expect(fn () => controller()->initialize($request))
        ->toThrow(HttpException::class);
});

it('allows admin users by default', function (): void {
    config(['updates.authorize_upload' => null]);

    $user = new class
    {
        public bool $is_admin = true;
    };

    $request = Request::create('/initialize', 'POST', [
        'fileName' => 'x.update',
        'fileSize' => 10,
        'totalChunks' => 1,
    ]);
    $request->setUserResolver(fn () => $user);

    $response = controller()->initialize($request);

    expect($response->getStatusCode())->toBe(200);
});

it('assembles chunks into a single .update file', function (): void {
    config(['updates.authorize_upload' => fn (Request $request) => true]);

    $initRequest = Request::create('/initialize', 'POST', [
        'fileName' => 'x.update',
        'fileSize' => 9,
        'totalChunks' => 2,
    ]);

    $uploadId = json_decode(controller()->initialize($initRequest)->getContent(), true)['uploadId'];

    foreach (range(0, 1) as $index) {
        $chunk = UploadedFile::fake()->createWithContent("chunk_{$index}", "part-{$index}");

        $chunkRequest = Request::create('/chunk', 'POST', [
            'uploadId' => $uploadId,
            'chunkIndex' => $index,
        ]);
        $chunkRequest->files->set('chunk', $chunk);

        controller()->chunk($chunkRequest);
    }

    $assembleRequest = Request::create('/assemble', 'POST', ['uploadId' => $uploadId]);
    $assembleResponse = controller()->assemble($assembleRequest);

    expect($assembleResponse->getStatusCode())->toBe(200);
    expect(file_exists(storage_path("app/pending-update-{$uploadId}.update")))->toBeTrue();
});
