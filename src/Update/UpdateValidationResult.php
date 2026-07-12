<?php

declare(strict_types=1);

namespace InstallerToolkit\Update;

/**
 * Result of validating an uploaded .update package.
 *
 * Returned by UpdateService::validateUpdateZip(). The standalone updater
 * reimplements validation framework-free (templates/update), so this DTO only
 * needs to travel within the booted application — it no longer implements
 * Wireable now that the Livewire-driven upload page is gone.
 */
class UpdateValidationResult
{
    public function __construct(
        public bool $valid,
        public string $version = '',
        public string $currentVersion = '',
        public ?string $error = null,
        public ?int $filesCount = null,
        public ?string $minimumPhp = null,
    ) {}
}
