<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use InstallerToolkit\Tests\Fixtures\FakePackageBuildCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use UpdateToolkit\UpdateSignature;

function signingFakeCommand(array $config = []): FakePackageBuildCommand
{
    $command = new FakePackageBuildCommand;
    $command->setOutput(new OutputStyle(
        new ArrayInput([]),
        new NullOutput,
    ));

    return $command->withConfig($config);
}

function testKeypair(): array
{
    $keypair = sodium_crypto_sign_keypair();

    return [
        'public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        'private_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
    ];
}

afterEach(function () {
    File::deleteDirectory(storage_path('app'));
    File::delete(base_path('package/package-config.php'));
    putenv('UPDATE_SIGNING_KEY');
    unset($_ENV['UPDATE_SIGNING_KEY'], $_SERVER['UPDATE_SIGNING_KEY']);
});

test('generateManifest does not sign when package-config has no signing_key_id', function () {
    $stagingDir = storage_path('app/test-sign-'.uniqid());
    File::ensureDirectoryExists($stagingDir);

    $innerZipPath = $stagingDir.'/fake-app.zip';
    $innerZip = new ZipArchive;
    $innerZip->open($innerZipPath, ZipArchive::CREATE);
    $innerZip->addFromString('fake-app/artisan', '#!/usr/bin/env php');
    $innerZip->close();

    $command = signingFakeCommand();
    $manifestPath = $command->callProtected('generateManifest', $stagingDir, $innerZipPath, '1.3.0');
    $manifest = json_decode(file_get_contents($manifestPath), true);

    expect($manifest)->not->toHaveKey('signature')
        ->and($manifest)->not->toHaveKey('key_id');
});

test('generateManifest fails closed when signing_key_id is set but UPDATE_SIGNING_KEY is missing', function () {
    $stagingDir = storage_path('app/test-sign-'.uniqid());
    File::ensureDirectoryExists($stagingDir);

    $innerZipPath = $stagingDir.'/fake-app.zip';
    $innerZip = new ZipArchive;
    $innerZip->open($innerZipPath, ZipArchive::CREATE);
    $innerZip->addFromString('fake-app/artisan', '#!/usr/bin/env php');
    $innerZip->close();

    $command = signingFakeCommand(['signing_key_id' => 'key-2026-07']);

    expect(fn () => $command->callProtected('generateManifest', $stagingDir, $innerZipPath, '1.3.0'))
        ->toThrow(RuntimeException::class, 'UPDATE_SIGNING_KEY environment variable is not set');
});

test('generateManifest fails closed when UPDATE_SIGNING_KEY is not a valid Ed25519 private key', function () {
    putenv('UPDATE_SIGNING_KEY=not-a-real-key');

    $stagingDir = storage_path('app/test-sign-'.uniqid());
    File::ensureDirectoryExists($stagingDir);

    $innerZipPath = $stagingDir.'/fake-app.zip';
    $innerZip = new ZipArchive;
    $innerZip->open($innerZipPath, ZipArchive::CREATE);
    $innerZip->addFromString('fake-app/artisan', '#!/usr/bin/env php');
    $innerZip->close();

    $command = signingFakeCommand(['signing_key_id' => 'key-2026-07']);

    expect(fn () => $command->callProtected('generateManifest', $stagingDir, $innerZipPath, '1.3.0'))
        ->toThrow(RuntimeException::class, 'not a valid Ed25519 private key');
});

test('generateManifest signs the manifest when signing_key_id and UPDATE_SIGNING_KEY are both present', function () {
    $keypair = testKeypair();
    putenv('UPDATE_SIGNING_KEY='.$keypair['private_key']);

    $stagingDir = storage_path('app/test-sign-'.uniqid());
    File::ensureDirectoryExists($stagingDir);

    $innerZipPath = $stagingDir.'/fake-app.zip';
    $innerZip = new ZipArchive;
    $innerZip->open($innerZipPath, ZipArchive::CREATE);
    $innerZip->addFromString('fake-app/artisan', '#!/usr/bin/env php');
    $innerZip->close();

    $command = signingFakeCommand(['slug' => 'fake-app', 'signing_key_id' => 'key-2026-07']);
    $manifestPath = $command->callProtected('generateManifest', $stagingDir, $innerZipPath, '1.3.0');
    $manifest = json_decode(file_get_contents($manifestPath), true);

    expect($manifest['key_id'])->toBe('key-2026-07')
        ->and($manifest['signature_algorithm'])->toBe('ed25519')
        ->and($manifest['signature'])->toBeString();

    $payload = UpdateSignature::canonicalPayload('fake-app', '1.3.0', $manifest['minimum_version'], $manifest['checksum'], 'key-2026-07');
    $publicKey = base64_decode($keypair['public_key'], strict: true);
    $signature = base64_decode($manifest['signature'], strict: true);

    expect(sodium_crypto_sign_verify_detached($signature, $payload, $publicKey))->toBeTrue();
});

test('generateManifest signature does not verify against a tampered checksum (binds checksum into the signed payload)', function () {
    $keypair = testKeypair();
    putenv('UPDATE_SIGNING_KEY='.$keypair['private_key']);

    $stagingDir = storage_path('app/test-sign-'.uniqid());
    File::ensureDirectoryExists($stagingDir);

    $innerZipPath = $stagingDir.'/fake-app.zip';
    $innerZip = new ZipArchive;
    $innerZip->open($innerZipPath, ZipArchive::CREATE);
    $innerZip->addFromString('fake-app/artisan', '#!/usr/bin/env php');
    $innerZip->close();

    $command = signingFakeCommand(['slug' => 'fake-app', 'signing_key_id' => 'key-2026-07']);
    $manifestPath = $command->callProtected('generateManifest', $stagingDir, $innerZipPath, '1.3.0');
    $manifest = json_decode(file_get_contents($manifestPath), true);

    $tamperedPayload = UpdateSignature::canonicalPayload('fake-app', '1.3.0', $manifest['minimum_version'], str_repeat('0', 64), 'key-2026-07');
    $publicKey = base64_decode($keypair['public_key'], strict: true);
    $signature = base64_decode($manifest['signature'], strict: true);

    expect(sodium_crypto_sign_verify_detached($signature, $tamperedPayload, $publicKey))->toBeFalse();
});
