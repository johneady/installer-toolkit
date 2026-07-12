<?php

declare(strict_types=1);

namespace InstallerToolkit\Update\Events;

use InstallerToolkit\Update\Models\UpdateHistory;

class UpdateRolledBack
{
    public function __construct(
        public UpdateHistory $history,
    ) {}
}
