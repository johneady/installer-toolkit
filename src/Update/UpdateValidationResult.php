<?php

declare(strict_types=1);

namespace InstallerToolkit\Update;

use Livewire\Wireable;

/**
 * Result of validating an uploaded .update package.
 */
class UpdateValidationResult implements Wireable
{
    public function __construct(
        public bool $valid,
        public string $version = '',
        public string $currentVersion = '',
        public ?string $error = null,
        public ?int $filesCount = null,
        public ?string $minimumPhp = null,
    ) {}

    /**
     * @return array{valid: bool, version: string, currentVersion: string, error: ?string, filesCount: ?int, minimumPhp: ?string}
     */
    public function toLivewire(): array
    {
        return [
            'valid' => $this->valid,
            'version' => $this->version,
            'currentVersion' => $this->currentVersion,
            'error' => $this->error,
            'filesCount' => $this->filesCount,
            'minimumPhp' => $this->minimumPhp,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromLivewire($value): static
    {
        return new static(
            valid: (bool) ($value['valid'] ?? false),
            version: (string) ($value['version'] ?? ''),
            currentVersion: (string) ($value['currentVersion'] ?? ''),
            error: $value['error'] ?? null,
            filesCount: isset($value['filesCount']) ? (int) $value['filesCount'] : null,
            minimumPhp: $value['minimumPhp'] ?? null,
        );
    }
}
