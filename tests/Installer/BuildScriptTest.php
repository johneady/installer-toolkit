<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * The repo root the bin/build script reads its templates from. bin/build
 * resolves this itself via dirname(__DIR__), so it is independent of CWD —
 * we only need an absolute path to invoke it.
 */
function toolkitRoot(): string
{
    return realpath(dirname(__DIR__, 2));
}

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
        // The footer leads with the product name, installer version beneath.
        ->and($source)->toContain('{$productName}')
        ->and($source)->toContain('Application Installer v{$version}')
        // mod_rewrite is checked asynchronously, so the checking row and its
        // AJAX endpoint must both be present in the assembled installer.
        ->and($source)->toContain('id="mod-rewrite-row"')
        ->and($source)->toContain('ajax=mod-rewrite');

    // readme.html is templated too: the quoted PHP version must come from
    // the app's min_php_version ('8.3.0' displayed as '8.3+'), never a
    // hardcoded default, and no marker may survive substitution.
    $readme = file_get_contents($outputDir.'/readme.html');
    expect($readme)->toContain('PHP 8.3+')
        ->and($readme)->not->toContain('[[MIN_PHP_VERSION]]');

    File::deleteDirectory($projectDir);
    File::deleteDirectory($outputDir);
});
