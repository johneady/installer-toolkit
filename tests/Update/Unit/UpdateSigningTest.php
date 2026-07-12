<?php

declare(strict_types=1);

use InstallerToolkit\Update\UpdateSignature;

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
    return require toolkitRoot().'/config/updates.php';
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

it('rejects a signature that does not verify against the trusted key', function (): void {
    // Exercises UpdateSignature::verify's negative path directly: a malformed
    // signature must never verify, regardless of the trusted-keys config.
    $keypair = $this->generateTestKeypair();

    $payload = UpdateSignature::canonicalPayload('testapp', '1.2.0', '1.0.0', str_repeat('a', 64), $keypair['key_id']);

    expect(UpdateSignature::verify($payload, 'not-a-real-signature', $keypair['key_id'], [$keypair['key_id'] => $keypair['public_key']]))
        ->toBeFalse();
})->skip(! extension_loaded('sodium'), 'sodium extension not loaded');
