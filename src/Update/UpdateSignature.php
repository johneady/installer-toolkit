<?php

declare(strict_types=1);

namespace InstallerToolkit\Update;

/**
 * Builds the canonical string signed/verified for .update manifests, signs
 * it, and verifies a signature against it.
 *
 * This is the single source of truth for the signing format: installer-toolkit
 * (johneady/installer-toolkit) requires this package and calls sign() directly
 * rather than duplicating the payload format, so the two can never drift apart.
 */
class UpdateSignature
{
    public const FORMAT_VERSION = 1;

    /**
     * The exact bytes that get signed. Binding slug + version + minimum_version
     * (not just the checksum) is what stops an attacker from replaying an old,
     * validly-signed package under a different version number, or retargeting
     * a package built for one app's slug onto another.
     */
    public static function canonicalPayload(string $slug, string $version, string $minimumVersion, string $checksum, string $keyId): string
    {
        return implode("\n", [
            'update-signature-v'.self::FORMAT_VERSION,
            $slug,
            $version,
            $minimumVersion,
            $checksum,
            $keyId,
        ]);
    }

    /**
     * @return array{key_id: string, signature: string, signature_algorithm: string}
     */
    public static function sign(string $slug, string $version, string $minimumVersion, string $checksum, string $keyId, string $privateKeyBase64): array
    {
        $privateKey = base64_decode($privateKeyBase64, strict: true);

        if ($privateKey === false || strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('UPDATE_SIGNING_KEY is not a valid Ed25519 private key.');
        }

        $payload = self::canonicalPayload($slug, $version, $minimumVersion, $checksum, $keyId);
        $signature = base64_encode(sodium_crypto_sign_detached($payload, $privateKey));

        return [
            'key_id' => $keyId,
            'signature' => $signature,
            'signature_algorithm' => 'ed25519',
        ];
    }

    /**
     * @param  array<string, string>  $trustedKeys  key_id => base64-encoded Ed25519 public key
     */
    public static function verify(string $payload, string $signatureBase64, string $keyId, array $trustedKeys): bool
    {
        if (! isset($trustedKeys[$keyId])) {
            return false;
        }

        $publicKey = base64_decode($trustedKeys[$keyId], strict: true);
        $signature = base64_decode($signatureBase64, strict: true);

        if ($publicKey === false || $signature === false) {
            return false;
        }

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $payload, $publicKey);
    }
}
