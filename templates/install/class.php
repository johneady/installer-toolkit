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

    // Constants the traits read live here, not in the traits: a trait cannot
    // declare constants before PHP 8.2, and one would stop this whole file
    // compiling on PHP 8.0/8.1, so a customer on an old host would get a fatal
    // error instead of the requirements page telling them to upgrade. A test
    // (TemplatePhpCompatibilityTest) keeps them out of the traits.

    // ─── Used by RunsInstallTasks ───

    /** Default files-per-batch; shrunk adaptively — see taskExtract(). */
    private const EXTRACT_DEFAULT_BATCH_SIZE = 500;

    /**
     * Target wall-clock seconds per extract batch. extractTo() extracts an
     * entire batch in one atomic C call that cannot be interrupted
     * mid-flight, so — unlike the updater's per-file extraction loop — this
     * can only shrink the *next* batch's size when the previous one ran
     * long, not bail out partway through the current one.
     */
    private const EXTRACT_BATCH_BUDGET_SECONDS = 8;

    /**
     * Plain-English hints for the common failure classes an installer hits
     * on shared hosting (bad DB credentials, missing extensions,
     * permissions), keyed by the raw exception text patterns that indicate
     * each cause. A rule matches when the raw message contains every
     * pattern in its list (one pattern for a simple substring match; two
     * for an AND-style compound match like the "Class ... not found" rule).
     *
     * This is the single authored copy — ensureOptimizerEndpoint() exports
     * this same array into the generated install-optimize.php so the
     * standalone optimizer process (which has no access to this class, see
     * its class docblock) shows identical hints instead of a hand-kept
     * second copy that can drift out of sync.
     *
     * @var list<array{patterns: list<string>, hint: string}>
     */
    private const FAILURE_HINTS = [
        ['patterns' => ['SQLSTATE[HY000] [2002]'], 'hint' => 'Could not reach the database server. Double-check the database host and port, and make sure the database server is running and accessible from this server.'],
        ['patterns' => ['Connection refused'], 'hint' => 'Could not reach the database server. Double-check the database host and port, and make sure the database server is running and accessible from this server.'],
        ['patterns' => ['SQLSTATE[HY000] [1045]'], 'hint' => 'The database username or password was rejected. Double-check the database credentials.'],
        ['patterns' => ['Access denied for user'], 'hint' => 'The database username or password was rejected. Double-check the database credentials.'],
        ['patterns' => ['SQLSTATE[HY000] [1049]'], 'hint' => 'The database name does not exist on the server. Create the database first, or check for a typo in the database name.'],
        ['patterns' => ['Unknown database'], 'hint' => 'The database name does not exist on the server. Create the database first, or check for a typo in the database name.'],
        ['patterns' => ['Permission denied'], 'hint' => 'The web server does not have permission to read or write a required file or directory. Check file ownership and permissions.'],
        ['patterns' => ['failed to open stream'], 'hint' => 'The web server does not have permission to read or write a required file or directory. Check file ownership and permissions.'],
        ['patterns' => ['Class', 'not found'], 'hint' => 'A required PHP class is missing, which usually means a Composer dependency did not get uploaded correctly. Try re-uploading the application package.'],
    ];

    /**
     * Upper bound on how many migration files to run per HTTP request.
     *
     * Booting the framework — registering and booting every service
     * provider — is by far the dominant cost of each request. Each batch is
     * now run as a single `migrate` Artisan call (passing every file in the
     * batch to `--path` at once, see runMigrateBatch()), so — unlike a naive
     * one-`migrate`-call-per-file loop — the per-file cost within a batch is
     * just the migration itself, not a repeated command dispatch. Amortising
     * one boot *and* one command dispatch across a larger batch is what
     * keeps the wizard's migrate step close to a one-shot `migrate:fresh`.
     *
     * This is only a cap on batch size — see MAX_REQUEST_SECONDS for the
     * separate wall-clock guard against a batch of unusually slow
     * migrations blowing a shared host's gateway timeout.
     */
    private const MIGRATIONS_PER_REQUEST = 50;

    /**
     * Maximum wall-clock seconds a single migrate-batch request is expected
     * to spend, used only to shrink the *next* batch's size when the
     * previous batch ran close to or over this budget — a single `migrate`
     * call cannot be interrupted mid-flight once dispatched, so this can no
     * longer stop a batch partway through the way it did when each file was
     * its own command call. Generous relative to typical 30s+ gateway
     * timeouts to leave headroom for a slow framework boot.
     */
    private const MAX_REQUEST_SECONDS = 15;

    /**
     * Essential seeder classes that are always run. These set up core
     * configuration and other foundational data the application needs
     * to function (e.g. an admin user, default settings, navigation).
     *
     * Customize this list for your application's own seeders.
     *
     * @var list<string>
     */
    private const ESSENTIAL_SEED_CLASSES = [
        'Database\\Seeders\\AdminUserSeeder',
        'Database\\Seeders\\SettingSeeder',
    ];

    /**
     * Additional seeder classes that populate the site with demonstration
     * data, run only when the user opts in to sample data during install.
     *
     * Customize this list for your application's own seeders.
     *
     * @var list<string>
     */
    private const SAMPLE_SEED_CLASSES = [
        // 'Database\\Seeders\\DemoContentSeeder',
    ];

    // [[INSTALLER_TRAITS]]
    // [[/INSTALLER_TRAITS]]
}
