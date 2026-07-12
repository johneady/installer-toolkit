<?php

declare(strict_types=1);

namespace InstallerToolkit\Update\Events;

class UpdateStarted
{
    public function __construct(
        public string $versionFrom,

        public string $versionTo,

        public ?string $backupId,
    ) {}
}
