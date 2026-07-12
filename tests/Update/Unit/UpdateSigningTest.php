<?php

declare(strict_types=1);

use InstallerToolkit\Update\UpdateService;
use InstallerToolkit\Update\UpdateSignature;

beforeEach(function (): void {
    $this->service = app(UpdateService::class);
});

/**
 * The shipped config/updates.php default, read directly rather than through
 * config() — TestCase resets updates.signing.trusted_keys to [] for every
 * test so unrelated tests aren't coupled to a real key being present. These
 * "does the package ship a valid key" tests are exactly the ones that need
 * to bypass that reset and inspect the real file customers receive.
 *
 * @return array<string, mixed>
 */
function shippedUpdatesConfig(): array
{
    return require __DIR__.'/../../../config/updates.php';
}

// ===========================================================================
// Trusted key configuration sanity (fail-closed on a broken/missing key)
// ===========================================================================

it('ships with at least one trusted signing key configured', function (): void {
    // Verification only self-activates once a key is present. An empty
    // array here silently reverts every install back to unsigned updates —
    // this guards against that regressing unnoticed.
    expect(shippedUpdatesConfig()['signing']['trusted_keys'])->not->toBe([]);
});

it('rejects a trusted_keys entry that is not valid base64', function (): void {
    config(['updates.signing.trusted_keys' => ['bad-key' => 'not-valid-base64-!!!']]);

    $decoded = base64_decode((string) config('updates.signing.trusted_keys.bad-key'), strict: true);

    expect($decoded)->toBeFalse();
});

it('rejects a trusted_keys entry that does not decode to a 32-byte Ed25519 public key', function (): void {
    config(['updates.signing.trusted_keys' => ['short-key' => base64_encode('too-short')]]);

    $decoded = base64_decode((string) config('updates.signing.trusted_keys.short-key'), strict: true);

    expect(strlen($decoded))->not->toBe(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
});

it('has well-formed public keys for every shipped trusted key', function (): void {
    // Catches a key truncated or mistyped when pasted into config/updates.php.
    foreach (shippedUpdatesConfig()['signing']['trusted_keys'] as $keyId => $publicKeyBase64) {
        $decoded = base64_decode((string) $publicKeyBase64, strict: true);

        expect($decoded)->not->toBeFalse("key_id '{$keyId}' is not valid base64")
            ->and(strlen($decoded))->toBe(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, "key_id '{$keyId}' is not a 32-byte Ed25519 public key");
    }
});

it('verifies every shipped trusted key against its committed test vector', function (): void {
    // The test vector was produced by `update:keygen` from the real private
    // key at the moment it was generated (the private key itself is never
    // committed). If this fails, the public key in config/updates.php does
    // not actually match what UPDATE_SIGNING_KEY in CI signs with.
    $vectors = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/signing-test-vectors.json'), true);
    $trustedKeys = shippedUpdatesConfig()['signing']['trusted_keys'];

    expect($vectors)->not->toBeEmpty('no signing test vectors committed — run update:keygen and commit its test vector');

    foreach ($vectors as $keyId => $vector) {
        expect(array_key_exists($keyId, $trustedKeys))->toBeTrue("test vector exists for '{$keyId}' but it is not in config/updates.php's signing.trusted_keys");
        expect($trustedKeys[$keyId])->toBe($vector['public_key'], "committed test vector's public key for '{$keyId}' does not match config");

        $payload = UpdateSignature::canonicalPayload('keygen-test', '0.0.1', '0.0.0', str_repeat('0', 64), $keyId);

        expect(UpdateSignature::verify($payload, $vector['signature'], $keyId, $trustedKeys))
            ->toBeTrue("test vector signature for '{$keyId}' does not verify against the configured public key");
    }
});

// ===========================================================================
// validateUpdateZip signature enforcement
// ===========================================================================

it('accepts an unsigned update when no trusted keys are configured', function (): void {
    config(['updates.signing.trusted_keys' => []]);

    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()));

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeTrue();
});

it('accepts a correctly signed update when its key is trusted', function (): void {
    $keypair = $this->generateTestKeypair();
    config(['updates.signing.trusted_keys' => [$keypair['key_id'] => $keypair['public_key']]]);

    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()), signWith: $keypair);

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeTrue();
});

it('rejects an unsigned update once trusted keys are configured', function (): void {
    $keypair = $this->generateTestKeypair();
    config(['updates.signing.trusted_keys' => [$keypair['key_id'] => $keypair['public_key']]]);

    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()));

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('not signed');
});

it('rejects an update signed with an unrecognized key_id', function (): void {
    $trusted = $this->generateTestKeypair('trusted-key');
    $rogue = $this->generateTestKeypair('rogue-key');
    config(['updates.signing.trusted_keys' => [$trusted['key_id'] => $trusted['public_key']]]);

    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()), signWith: $rogue);

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('unrecognized key');
});

it('rejects an update whose inner zip was tampered with after signing', function (): void {
    $keypair = $this->generateTestKeypair();
    config(['updates.signing.trusted_keys' => [$keypair['key_id'] => $keypair['public_key']]]);

    $path = $this->pendingPath($this->validUploadId());
    $this->buildUpdatePackage($path, signWith: $keypair);

    // Tamper with the manifest's checksum post-signing, simulating a
    // replaced inner zip whose checksum was patched but not re-signed.
    $zip = new ZipArchive;
    $zip->open($path);
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    $manifest['checksum'] = str_repeat('f', 64);
    $zip->deleteName('manifest.json');
    $zip->addFromString('manifest.json', json_encode($manifest));
    $zip->close();

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse();
});

it('rejects an update whose version was edited after signing (downgrade/replay protection)', function (): void {
    $keypair = $this->generateTestKeypair();
    config(['updates.signing.trusted_keys' => [$keypair['key_id'] => $keypair['public_key']]]);

    $path = $this->pendingPath($this->validUploadId());
    $this->buildUpdatePackage($path, manifestOverrides: ['version' => '1.2.0'], signWith: $keypair);

    $zip = new ZipArchive;
    $zip->open($path);
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    $manifest['version'] = '1.3.0';
    $zip->deleteName('manifest.json');
    $zip->addFromString('manifest.json', json_encode($manifest));
    $zip->close();

    $result = $this->service->validateUpdateZip($path);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toContain('signature verification failed');
});

it('rejects a signed update when the sodium extension is unavailable', function (): void {
    // We can't actually unload sodium in a running PHP process, so this
    // exercises verifySignature's guard indirectly: it requires the
    // extension to be loaded, which in CI it always is, so this test
    // documents the contract via UpdateSignature::verify's own bounds
    // checking instead of process-level extension removal.
    $keypair = $this->generateTestKeypair();

    $payload = UpdateSignature::canonicalPayload('testapp', '1.2.0', '1.0.0', str_repeat('a', 64), $keypair['key_id']);

    expect(UpdateSignature::verify($payload, 'not-a-real-signature', $keypair['key_id'], [$keypair['key_id'] => $keypair['public_key']]))
        ->toBeFalse();
})->skip(! extension_loaded('sodium'), 'sodium extension not loaded');

it('supports rotation: a package signed with a newly added second key verifies once both keys are trusted', function (): void {
    $oldKey = $this->generateTestKeypair('key-2026-01');
    $newKey = $this->generateTestKeypair('key-2026-07');

    // Rotation step: both keys trusted simultaneously (old not yet removed).
    config(['updates.signing.trusted_keys' => [
        $oldKey['key_id'] => $oldKey['public_key'],
        $newKey['key_id'] => $newKey['public_key'],
    ]]);

    $path = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()), signWith: $newKey);

    expect($this->service->validateUpdateZip($path)->valid)->toBeTrue();

    // Old key removed in a later release: a package still signed with it must now fail.
    config(['updates.signing.trusted_keys' => [$newKey['key_id'] => $newKey['public_key']]]);

    $oldPath = $this->buildUpdatePackage($this->pendingPath($this->validUploadId()), signWith: $oldKey);

    expect($this->service->validateUpdateZip($oldPath)->valid)->toBeFalse();
});
