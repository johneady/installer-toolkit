<?php

class Installer
{
    private array $errors = [];

    private int $totalSteps = 9;

    private array $stepNames = [
        1 => 'License',
        2 => 'Requirements',
        3 => 'Settings',
        7 => 'Install',
        8 => 'Cron Job',
        9 => 'Complete',
    ];

    private array $settingsSubSteps = [3, 4, 5, 6];

    private array $settingsSubStepNames = [
        3 => 'Database',
        4 => 'Application',
        5 => 'Email',
        6 => 'Admin Account',
    ];

// [[INSTALLER_TRAITS]]
// [[/INSTALLER_TRAITS]]
}
