<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

afterEach(function () {
    File::deleteDirectory(storage_path('app'));
});

test('bin/build injects the app name and produces a syntactically valid install.php', function () {
    $projectDir = storage_path('app/build-test-'.uniqid());
    $outputDir = storage_path('app/build-test-output-'.uniqid());
    File::ensureDirectoryExists($projectDir.'/package');

    // The apostrophe in the name exercises single-quote escaping in the
    // generated define() — a naive interpolation would break the installer.
    $config = [
        'name' => "Fake App's Suite",
        'slug' => 'fake-app',
        'min_php_version' => '8.3.0',
        'essential_seeders' => [],
        'sample_seeders' => [],
    ];
    file_put_contents(
        $projectDir.'/package/package-config.php',
        '<?php return '.var_export($config, true).';'
    );

    $build = new Process(['php', toolkitRoot().'/bin/build', $projectDir, $outputDir]);
    $build->mustRun();

    $installPhp = $outputDir.'/install.php';
    expect(file_exists($installPhp))->toBeTrue();

    $source = file_get_contents($installPhp);

    // The generated installer must be valid PHP.
    $lint = new Process(['php', '-l', $installPhp]);
    $lint->run();
    expect($lint->isSuccessful())->toBeTrue();

    // Every [[INSTALLER_*]] assembly marker must be resolved — a leftover
    // would mean the template failed to substitute a block.
    expect($source)->not->toContain('[[INSTALLER_')
        // The product name is injected (escaped) and the slug still is.
        ->and($source)->toContain("define('APP_NAME', 'Fake App\\'s Suite');")
        ->and($source)->toContain("define('APP_FOLDER', 'fake-app');")
        // Recommended DB name/username seeds are present in the source.
        ->and($source)->toContain("\$dbBase.'_db'")
        ->and($source)->toContain("\$dbBase.'_user'")
        // The footer leads with the product name, tool label + version
        // beneath (shared chrome interpolates both at render time).
        ->and($source)->toContain('{$productName}')
        ->and($source)->toContain('{$toolLabel} v{$version}')
        ->and($source)->toContain("return 'Application Installer';")
        // mod_rewrite is checked asynchronously, so the checking row and its
        // AJAX endpoint must both be present in the assembled installer.
        ->and($source)->toContain('id="mod-rewrite-row"')
        ->and($source)->toContain('ajax=mod-rewrite');

    // The standalone updater is assembled alongside the installer and must
    // be equally self-contained and valid.
    $updaterPhp = $outputDir.'/updater.php';
    expect(file_exists($updaterPhp))->toBeTrue();

    $updaterLint = new Process(['php', '-l', $updaterPhp]);
    $updaterLint->run();
    expect($updaterLint->isSuccessful())->toBeTrue();

    $updaterSource = file_get_contents($updaterPhp);
    expect($updaterSource)->not->toContain('[[INSTALLER_')
        ->and($updaterSource)->toContain("define('APP_NAME', 'Fake App\\'s Suite');")
        ->and($updaterSource)->toContain("define('APP_FOLDER', 'fake-app');")
        ->and($updaterSource)->toContain("define('UPDATER_VERSION',")
        ->and($updaterSource)->toContain("return 'Application Updater';")
        // The updater must never pull in the app's framework to do its own
        // work — the only require of vendor/ belongs to the post-update
        // hook, which ships in the package, not in updater.php.
        ->and($updaterSource)->not->toContain('vendor/autoload.php')
        // Core pipeline pieces are present.
        ->and($updaterSource)->toContain('ajax=update-task')
        ->and($updaterSource)->toContain('ajax=upload-chunk')
        ->and($updaterSource)->toContain('update-signature-v')
        ->and($updaterSource)->toContain('.updater/post_update.php');

    // The post-update hook stub ships alongside for package:build to embed.
    $hook = $outputDir.'/post_update.php';
    expect(file_exists($hook))->toBeTrue();

    $hookLint = new Process(['php', '-l', $hook]);
    $hookLint->run();
    expect($hookLint->isSuccessful())->toBeTrue();

    // readme.html is templated too: the quoted PHP version must come from
    // the app's min_php_version ('8.3.0' displayed as '8.3+'), never a
    // hardcoded default, and no marker may survive substitution.
    $readme = file_get_contents($outputDir.'/readme.html');
    expect($readme)->toContain('PHP 8.3+')
        ->and($readme)->not->toContain('[[MIN_PHP_VERSION]]');

    File::deleteDirectory($projectDir);
    File::deleteDirectory($outputDir);
});
