<?php

class Updater
{
    private array $errors = [];

    /** @var array<string, mixed>|null Cached, resolved config/updates.php values. */
    private ?array $updatesConfig = null;

    private int $totalSteps = 4;

    private array $stepNames = [
        1 => 'Status',
        2 => 'Review',
        3 => 'Update',
        4 => 'Complete',
    ];

    // [[INSTALLER_TRAITS]]
    // [[/INSTALLER_TRAITS]]
}
