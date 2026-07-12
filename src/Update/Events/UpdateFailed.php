<?php

declare(strict_types=1);

namespace InstallerToolkit\Update\Events;

use Throwable;

class UpdateFailed
{
    public function __construct(
        public string $versionFrom,

        public string $versionTo,

        public Throwable $exception,

        public ?string $backupId = null,
    ) {}
}
