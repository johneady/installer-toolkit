<?php

declare(strict_types=1);

namespace InstallerToolkit\Update\Console\Commands;

use Illuminate\Console\Command;
use InstallerToolkit\Update\UpdateSignature;

class UpdateKeygenCommand extends Command
{
    protected $signature = 'update:keygen {--key-id= : Override the generated key_id (default: key-YYYY-MM)}';

    protected $description = 'Generate a new Ed25519 signing keypair for .update packages';

    public function handle(): int
    {
        if (! extension_loaded('sodium')) {
            $this->error('The sodium PHP extension is required to generate a signing keypair.');

            return self::FAILURE;
        }

        $keyId = (string) ($this->option('key-id') ?: 'key-'.now()->format('Y-m'));

        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
        $privateKey = base64_encode(sodium_crypto_sign_secretkey($keypair));

        // A self-contained proof, signed now while both halves of the pair are
        // in memory, that the public and private key actually match. Nothing
        // here is a secret on its own — the private key is never written to
        // disk — so it's safe to commit as a fixture in update-toolkit's tests
        // and asserted against in installer-toolkit without either repo ever
        // holding the real private key.
        $testPayload = UpdateSignature::canonicalPayload('keygen-test', '0.0.1', '0.0.0', str_repeat('0', 64), $keyId);
        $testSignature = base64_encode(sodium_crypto_sign_detached($testPayload, sodium_crypto_sign_secretkey($keypair)));

        $this->info('New signing keypair generated. This is shown once — nothing is written to disk.');
        $this->newLine();

        $this->line('<comment>key_id</comment> (manifest.json / config/updates.php):');
        $this->line($keyId);
        $this->newLine();

        $this->line('<comment>Public key</comment> — add to config/updates.php under signing.trusted_keys:');
        $this->line($publicKey);
        $this->newLine();

        $this->line('<comment>Private key</comment> — paste into the CI secret UPDATE_SIGNING_KEY, never into a repo:');
        $this->line($privateKey);
        $this->newLine();

        $this->line('<comment>Test vector</comment> — commit alongside the public key as a fixture proving the two halves match:');
        $this->line(json_encode([
            'key_id' => $keyId,
            'signature' => $testSignature,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
