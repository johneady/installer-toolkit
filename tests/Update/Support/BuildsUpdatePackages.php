<?php

declare(strict_types=1);

namespace InstallerToolkit\Tests\Update\Support;

use Illuminate\Support\Facades\File;
use InstallerToolkit\Update\UpdateSignature;
use ZipArchive;

trait BuildsUpdatePackages
{
    protected string $testSlug = 'testapp';

    /**
     * Build a valid .update package (outer zip: manifest.json + {slug}.zip).
     *
     * @param  array<string, string>  $innerFiles  Relative paths (without the slug prefix) => contents.
     * @param  array<string, mixed>  $manifestOverrides
     * @param  array{key_id: string, private_key: string}|null  $signWith  When given, signs the manifest with this Ed25519 keypair (both base64-encoded).
     */
    protected function buildUpdatePackage(string $path, array $innerFiles = [], array $manifestOverrides = [], ?array $signWith = null): string
    {
        $innerPath = sys_get_temp_dir().'/inner-'.uniqid().'.zip';

        $inner = new ZipArchive;
        $inner->open($innerPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $innerFiles = $innerFiles ?: [
            'artisan' => '<?php // artisan stub',
            'config/app.php' => "<?php\n\nreturn ['version' => '1.2.0'];\n",
        ];

        foreach ($innerFiles as $relative => $content) {
            $inner->addFromString($this->testSlug.'/'.$relative, $content);
        }
        $inner->close();

        $checksum = hash_file('sha256', $innerPath);

        $manifest = array_merge([
            'type' => 'update',
            'version' => '1.2.0',
            'minimum_version' => '1.0.0',
            'minimum_php' => '8.2.0',
            'checksum' => $checksum,
            'built_at' => now()->toIso8601String(),
            'files_count' => count($innerFiles),
        ], $manifestOverrides);

        if ($signWith !== null) {
            $manifest = array_merge($manifest, $this->signManifest($manifest, $signWith));
        }

        $outer = new ZipArchive;
        File::ensureDirectoryExists(dirname($path));
        $outer->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $outer->addFromString('manifest.json', json_encode($manifest));
        $outer->addFile($innerPath, $this->testSlug.'.zip');
        $outer->close();

        @unlink($innerPath);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{key_id: string, private_key: string}  $signWith
     * @return array{key_id: string, signature: string}
     */
    protected function signManifest(array $manifest, array $signWith): array
    {
        $payload = UpdateSignature::canonicalPayload(
            $this->testSlug,
            (string) $manifest['version'],
            (string) $manifest['minimum_version'],
            (string) $manifest['checksum'],
            $signWith['key_id'],
        );

        $privateKey = base64_decode($signWith['private_key'], strict: true);
        $signature = base64_encode(sodium_crypto_sign_detached($payload, $privateKey));

        return ['key_id' => $signWith['key_id'], 'signature' => $signature];
    }

    /**
     * @return array{key_id: string, public_key: string, private_key: string}
     */
    protected function generateTestKeypair(string $keyId = 'test-key'): array
    {
        $keypair = sodium_crypto_sign_keypair();

        return [
            'key_id' => $keyId,
            'public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'private_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
        ];
    }

    /**
     * Build a .update whose inner {slug}.zip already exists (used to inject a
     * custom inner zip with traversal entries or other edge cases).
     */
    protected function buildUpdatePackageWithRawInner(string $path, callable $buildInner, array $manifestOverrides = []): string
    {
        $innerPath = sys_get_temp_dir().'/inner-'.uniqid().'.zip';

        $buildInner($innerPath);
        $checksum = hash_file('sha256', $innerPath);

        $manifest = array_merge([
            'type' => 'update',
            'version' => '1.2.0',
            'minimum_version' => '1.0.0',
            'minimum_php' => '8.2.0',
            'checksum' => $checksum,
            'built_at' => now()->toIso8601String(),
            'files_count' => 1,
        ], $manifestOverrides);

        $outer = new ZipArchive;
        File::ensureDirectoryExists(dirname($path));
        $outer->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $outer->addFromString('manifest.json', json_encode($manifest));
        $outer->addFile($innerPath, $this->testSlug.'.zip');
        $outer->close();

        @unlink($innerPath);

        return $path;
    }

    protected function pendingPath(string $uploadId): string
    {
        return storage_path("app/pending-update-{$uploadId}.update");
    }

    protected function validUploadId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
