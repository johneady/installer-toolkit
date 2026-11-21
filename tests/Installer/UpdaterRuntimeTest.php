<?php

/**
 * End-to-end coverage for the standalone updater.php — the framework-free
 * tool that runs unattended on customer servers and does the most
 * destructive work in this toolkit (backup, extraction, restore). Unlike
 * BuildScriptTest/PackageSandboxCommandTest, which only assert the assembled
 * script executes, this drives the real HTTP surface: token gate, chunked
 * upload + package validation, the prepare→backup→extract→post_update→
 * verify→finalize task pipeline, and the restore path after a forced
 * failure.
 *
 * The assembled updater.php is served by PHP's built-in server, exactly the
 * pattern already proven in PackageSandboxCommandTest's
 * "the assembled updater.php executes rather than being served as source".
 * The post-update hook is a minimal test double (not the real
 * post_update.stub.php, which requires a bootable Laravel app) — its
 * contract is just `include` returning ['success' => bool, 'output' =>
 * string], so a double is a faithful substitute for exercising the pipeline
 * around it.
 */

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Assemble the real updater.php via bin/build and lay it down inside a fresh
 * sandbox app directory, along with the minimal config/app.php,
 * config/updates.php, and storage/ tree it needs to run without booting
 * Laravel.
 *
 * @return array{sandbox: string, slug: string, appDir: string}
 */
function buildUpdaterSandbox(string $slug, string $currentVersion, array $updatesConfigOverrides = []): array
{
    $projectDir = storage_path('app/updater-rt-build-'.uniqid());
    File::ensureDirectoryExists($projectDir.'/package');
    file_put_contents($projectDir.'/package/package-config.php', '<?php return '.var_export([
        'name' => 'Updater RT App',
        'slug' => $slug,
        'essential_seeders' => [],
        'sample_seeders' => [],
    ], true).';');

    // bin/build derives the generated updater's MIN_PHP_VERSION from here.
    file_put_contents($projectDir.'/composer.json', json_encode(['require' => ['php' => '^8.2']]));

    $outputDir = storage_path('app/updater-rt-out-'.uniqid());
    $build = new Process(['php', toolkitRoot().'/bin/build', $projectDir, $outputDir]);
    $build->mustRun();

    $sandbox = storage_path('app/updater-rt-sandbox-'.uniqid());
    $appDir = $sandbox.'/'.$slug;
    File::ensureDirectoryExists($appDir.'/public');
    File::ensureDirectoryExists($appDir.'/storage/framework');
    File::ensureDirectoryExists($appDir.'/storage/logs');
    File::ensureDirectoryExists($appDir.'/config');
    File::ensureDirectoryExists($appDir.'/database/migrations');

    copy($outputDir.'/updater.php', $appDir.'/public/updater.php');

    file_put_contents($appDir.'/config/app.php', "<?php\n\nreturn ['version' => '{$currentVersion}'];\n");

    $updatesConfig = array_merge([
        'slug' => $slug,
        'updater' => ['storage_dir' => 'storage/app/updater'],
        'protected_paths' => ['.env'],
        'signing' => ['trusted_keys' => []],
        'backup' => [
            'enabled' => false,
            'keep' => 3,
            'include_vendor' => false,
            'exclude' => [],
            'database' => ['enabled' => false],
        ],
    ], $updatesConfigOverrides);

    file_put_contents($appDir.'/config/updates.php', '<?php return '.var_export($updatesConfig, true).';');

    File::ensureDirectoryExists($appDir.'/storage/app/updater');

    return ['sandbox' => $sandbox, 'slug' => $slug, 'appDir' => $appDir];
}

/**
 * Build a valid .update package whose inner zip carries a test-double
 * post_update hook instead of the real Laravel-booting stub, so the pipeline
 * can be exercised without a bootable application. Returns the package path.
 */
function buildUpdaterRtPackage(string $path, string $slug, string $version, string $postUpdateBody, array $manifestOverrides = []): string
{
    $innerPath = sys_get_temp_dir().'/updater-rt-inner-'.uniqid().'.zip';

    $inner = new ZipArchive;
    $inner->open($innerPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $inner->addFromString("{$slug}/config/app.php", "<?php\n\nreturn ['version' => '{$version}'];\n");
    $inner->addFromString("{$slug}/marker.txt", "applied {$version}\n");
    $inner->addFromString("{$slug}/new-feature/nested/marker.txt", "applied {$version}\n");
    $inner->addFromString("{$slug}/.updater/post_update.php", $postUpdateBody);
    $inner->close();

    $checksum = hash_file('sha256', $innerPath);

    $manifest = array_merge([
        'type' => 'update',
        'version' => $version,
        'minimum_version' => '1.0.0',
        'minimum_php' => '8.2.0',
        'checksum' => $checksum,
        'built_at' => date('c'),
        'files_count' => 4,
        'post_update' => '.updater/post_update.php',
    ], $manifestOverrides);

    $outer = new ZipArchive;
    File::ensureDirectoryExists(dirname($path));
    $outer->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $outer->addFromString('manifest.json', json_encode($manifest));
    $outer->addFile($innerPath, "{$slug}.zip");
    $outer->close();

    @unlink($innerPath);

    return $path;
}

const UPDATER_RT_SUCCEEDING_HOOK = '<?php return ["success" => true, "output" => "ok"];';
const UPDATER_RT_FAILING_HOOK = '<?php return ["success" => false, "output" => "boom"];';

/**
 * Serve the sandbox on PHP's built-in server and return the base URI plus a
 * cookie-jar HTTP client already pointed at it. The router only needs to
 * expose the single updater.php entry point directly (no app routing).
 *
 * @return array{process: Process, baseUri: string, client: PendingRequest}
 */
function serveUpdaterSandbox(array $sandbox): array
{
    $port = 0;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket !== false) {
        $name = stream_socket_get_name($socket, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($socket);
    }

    $docRoot = $sandbox['appDir'].'/public';
    $process = new Process(['php', '-S', "127.0.0.1:{$port}", '-t', $docRoot]);
    $process->start();

    $baseUri = "http://127.0.0.1:{$port}";
    $client = Http::withOptions(['cookies' => new CookieJar, 'timeout' => 10])->baseUrl($baseUri);

    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        if (! $process->isRunning()) {
            throw new RuntimeException('PHP built-in server failed to start: '.$process->getErrorOutput());
        }
        try {
            $client->get('/updater.php');
            break;
        } catch (Throwable) {
            usleep(100_000);
        }
    }

    return ['process' => $process, 'baseUri' => $baseUri, 'client' => $client];
}

function extractCsrfToken(string $html): string
{
    if (! preg_match('/window\.CSRF_TOKEN\s*=\s*"([a-f0-9]+)"/', $html, $m)) {
        throw new RuntimeException('Could not find window.CSRF_TOKEN on the updater page.');
    }

    return $m[1];
}

/**
 * POST a form-encoded request without mutating the given client.
 *
 * PendingRequest::asForm()/asMultipart()/withOptions() all mutate $this in
 * place and return it rather than a clone — calling them directly on a
 * client shared across a whole test would leave every later request (GETs
 * included) carrying the wrong Content-Type/options. Every POST helper below
 * clones first so $client itself stays pristine; the clone still shares the
 * original's cookie jar (PHP object properties are shallow-copied, and the
 * jar is passed by reference into Guzzle), so the updater session persists
 * across calls exactly as it would for a real browser tab.
 */
function postForm(PendingRequest $client, string $url, array $data, array $options = []): Response
{
    return (clone $client)->withOptions($options)->asForm()->post($url, $data);
}

/**
 * Upload a .update package to the running updater over the real chunked
 * upload endpoints (single chunk, since test fixtures are tiny), mirroring
 * the browser JS in RendersUpdaterSteps. Returns the decoded final response.
 */
function uploadUpdatePackage(PendingRequest $client, string $csrf, string $packagePath): array
{
    $bytes = file_get_contents($packagePath);
    $size = strlen($bytes);

    $init = postForm($client, '/updater.php?ajax=upload-init', [
        '_csrf' => $csrf,
        'name' => basename($packagePath),
        'size' => $size,
        'total_chunks' => 1,
    ])->json();

    expect($init['success'] ?? false)->toBeTrue();

    $uploadId = $init['upload_id'];

    $response = (clone $client)->asMultipart()->post('/updater.php?ajax=upload-chunk', [
        ['name' => '_csrf', 'contents' => $csrf],
        ['name' => 'upload_id', 'contents' => $uploadId],
        ['name' => 'index', 'contents' => '0'],
        ['name' => 'chunk', 'contents' => $bytes, 'filename' => 'chunk'],
    ]);

    return $response->json();
}

/**
 * Drive the update-task pipeline to completion (or until a task reports
 * failure), returning the failing task's response when one fails. Pass a
 * subset of tasks to simulate a run interrupted partway through — the
 * browser-session loss the interrupted-update recovery flow exists for.
 *
 * @param  list<string>|null  $tasks
 * @return array{completed: list<string>, failure: ?array}
 */
function runUpdateTaskPipeline(PendingRequest $client, string $csrf, ?array $tasks = null): array
{
    $tasks ??= ['prepare', 'backup_files', 'backup_db', 'extract', 'post_update', 'verify', 'finalize'];
    $completed = [];

    foreach ($tasks as $task) {
        for ($i = 0; $i < 50; $i++) {
            $response = postForm($client, "/updater.php?ajax=update-task&task={$task}", ['_csrf' => $csrf])->json();

            if (! ($response['success'] ?? false)) {
                return ['completed' => $completed, 'failure' => $response];
            }

            if (($response['task_done'] ?? true) === false) {
                continue;
            }

            break;
        }

        $completed[] = $task;
    }

    return ['completed' => $completed, 'failure' => null];
}

/**
 * Pass the recovery-token gate the way an operator does and return the CSRF
 * token from the post-unlock home screen. The happy-path pipeline test walks
 * this flow inline because the gate itself is under test there; every other
 * test just needs an authorized session.
 */
function unlockUpdater(PendingRequest $client, array $sandbox): string
{
    $gateBody = $client->get('/updater.php')->body();
    $csrf = extractCsrfToken($gateBody);
    $recoveryToken = trim(file_get_contents($sandbox['appDir'].'/storage/app/updater/access-token.txt'));

    postForm($client, '/updater.php', ['_csrf' => $csrf, 'token' => $recoveryToken], ['allow_redirects' => false]);

    return extractCsrfToken($client->get('/updater.php')->body());
}

/**
 * A brand-new browser against the same running updater: its own cookie jar
 * means its own PHP session, which is exactly what "the operator closed
 * the browser mid-update and came back" leaves server-side — none of the
 * run state the original session held.
 *
 * @return array{client: PendingRequest, csrf: string}
 */
function freshUpdaterSession(array $sandbox, array $served): array
{
    $client = Http::withOptions(['cookies' => new CookieJar, 'timeout' => 10])->baseUrl($served['baseUri']);

    return ['client' => $client, 'csrf' => unlockUpdater($client, $sandbox)];
}

afterEach(function () {
    File::deleteDirectory(storage_path('app'));
});

test('the full upload-through-finalize pipeline updates the sandboxed app to the new version', function () {
    $sandbox = buildUpdaterSandbox('rt-happy', '1.0.0');
    $served = serveUpdaterSandbox($sandbox);

    try {
        $client = $served['client'];

        // No token yet: the gate renders instead of the home screen.
        $gateBody = $client->get('/updater.php')->body();
        expect($gateBody)->toContain('Access Token');

        // Recovery token: the operator's filesystem-proof path, since no
        // admin-panel handoff exists in this sandbox.
        $recoveryToken = trim(file_get_contents($sandbox['appDir'].'/storage/app/updater/access-token.txt'));
        expect($recoveryToken)->not->toBe('');

        $csrf = extractCsrfToken($gateBody);
        $unlock = postForm($client, '/updater.php', ['_csrf' => $csrf, 'token' => $recoveryToken], ['allow_redirects' => false]);
        expect($unlock->redirect())->toBeTrue();

        // The token file is single-use — it must be consumed now.
        expect(file_exists($sandbox['appDir'].'/storage/app/updater/access-token.txt'))->toBeFalse();

        $homeBody = $client->get('/updater.php')->body();
        expect($homeBody)->toContain('v1.0.0');
        $csrf = extractCsrfToken($homeBody);

        $packagePath = storage_path('app/rt-happy-'.uniqid().'.update');
        buildUpdaterRtPackage($packagePath, 'rt-happy', '1.1.0', UPDATER_RT_SUCCEEDING_HOOK);

        $uploadResult = uploadUpdatePackage($client, $csrf, $packagePath);
        expect($uploadResult['success'] ?? false)->toBeTrue()
            ->and($uploadResult['complete'] ?? false)->toBeTrue()
            ->and($uploadResult['valid'] ?? false)->toBeTrue()
            ->and($uploadResult['version'] ?? null)->toBe('1.1.0');

        $reviewBody = $client->get('/updater.php')->body();
        expect($reviewBody)->toContain('Apply Update');
        $csrf = extractCsrfToken($reviewBody);

        $start = postForm($client, '/updater.php', ['_csrf' => $csrf, 'action' => 'start-update'], ['allow_redirects' => false]);
        expect($start->redirect())->toBeTrue();

        $progressBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($progressBody);

        $result = runUpdateTaskPipeline($client, $csrf);
        expect($result['failure'])->toBeNull('Task pipeline failed: '.json_encode($result['failure']))
            ->and($result['completed'])->toBe(['prepare', 'backup_files', 'backup_db', 'extract', 'post_update', 'verify', 'finalize']);

        // Extraction actually landed the new file tree.
        expect(trim((string) file_get_contents($sandbox['appDir'].'/marker.txt')))->toBe('applied 1.1.0')
            ->and(file_get_contents($sandbox['appDir'].'/config/app.php'))->toContain("'version' => '1.1.0'");

        // Maintenance mode was lifted and the run's lock released.
        expect(file_exists($sandbox['appDir'].'/storage/framework/down'))->toBeFalse()
            ->and(file_exists($sandbox['appDir'].'/storage/app/updater/update.lock'))->toBeFalse();

        // A result file recording success now exists for the admin panel.
        $resultFiles = glob($sandbox['appDir'].'/storage/app/updater/results/*-applied.json');
        expect($resultFiles)->not->toBeEmpty();
        $resultPayload = json_decode(file_get_contents($resultFiles[0]), true);
        expect($resultPayload['version_from'])->toBe('1.0.0')
            ->and($resultPayload['version_to'])->toBe('1.1.0');

        @unlink($packagePath);
    } finally {
        $served['process']->stop(3);
        File::deleteDirectory($sandbox['sandbox']);
    }
});

test('a failing post_update task offers restore, and restore rolls the app back to the pre-update state', function () {
    $sandbox = buildUpdaterSandbox('rt-restore', '2.0.0', [
        'backup' => [
            'enabled' => true,
            'keep' => 3,
            'include_vendor' => true,
            'exclude' => [],
            'database' => ['enabled' => false],
        ],
    ]);

    // A pre-existing file the update will overwrite, and one it will add —
    // both must be restored to their prior state (present/absent) by rollback.
    file_put_contents($sandbox['appDir'].'/original.txt', 'pre-update content');

    $served = serveUpdaterSandbox($sandbox);

    try {
        $client = $served['client'];

        $csrf = unlockUpdater($client, $sandbox);

        $packagePath = storage_path('app/rt-restore-'.uniqid().'.update');
        buildUpdaterRtPackage($packagePath, 'rt-restore', '2.1.0', UPDATER_RT_FAILING_HOOK);

        $uploadResult = uploadUpdatePackage($client, $csrf, $packagePath);
        expect($uploadResult['valid'] ?? false)->toBeTrue();

        $reviewBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($reviewBody);

        postForm($client, '/updater.php', ['_csrf' => $csrf, 'action' => 'start-update'], ['allow_redirects' => false]);

        $progressBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($progressBody);

        $result = runUpdateTaskPipeline($client, $csrf);

        // The post_update hook is designed to fail: the pipeline must stop
        // there, having completed everything up to and including extract.
        expect($result['failure'])->not->toBeNull()
            ->and($result['completed'])->toBe(['prepare', 'backup_files', 'backup_db', 'extract'])
            ->and($result['failure']['can_restore'] ?? false)->toBeTrue();

        // The failed update did land its new files before post_update ran,
        // including a file nested two directories deep (new-feature/nested/)
        // that restore's cleanup phase will need to prune along with it.
        expect(trim((string) file_get_contents($sandbox['appDir'].'/marker.txt')))->toBe('applied 2.1.0')
            ->and(is_dir($sandbox['appDir'].'/new-feature/nested'))->toBeTrue();

        // Drive the restore task to completion the same way the browser's
        // restore button does (see runUpdateTaskPipeline's docblock for why
        // 'restore' isn't in the normal forward-pipeline task list: it loops
        // internally through files → cleanup → database phases).
        $restoreDone = false;
        for ($i = 0; $i < 50; $i++) {
            $response = postForm($client, '/updater.php?ajax=update-task&task=restore', ['_csrf' => $csrf])->json();
            expect($response['success'] ?? false)->toBeTrue('Restore task failed: '.json_encode($response));

            if (($response['task_done'] ?? false) === true) {
                $restoreDone = true;
                break;
            }
        }
        expect($restoreDone)->toBeTrue();

        // Rollback restored the pre-update version and file, and removed the
        // file the failed update had added — including pruning the now-empty
        // new-feature/nested/ directories the update introduced, not just
        // the file inside them.
        expect(file_get_contents($sandbox['appDir'].'/config/app.php'))->toContain("'version' => '2.0.0'")
            ->and(trim((string) file_get_contents($sandbox['appDir'].'/original.txt')))->toBe('pre-update content')
            ->and(file_exists($sandbox['appDir'].'/marker.txt'))->toBeFalse()
            ->and(is_dir($sandbox['appDir'].'/new-feature'))->toBeFalse();

        // Maintenance mode was lifted and the lock released after rollback too.
        expect(file_exists($sandbox['appDir'].'/storage/framework/down'))->toBeFalse()
            ->and(file_exists($sandbox['appDir'].'/storage/app/updater/update.lock'))->toBeFalse();

        $resultFiles = glob($sandbox['appDir'].'/storage/app/updater/results/*-rolled_back.json');
        expect($resultFiles)->not->toBeEmpty();

        @unlink($packagePath);
    } finally {
        $served['process']->stop(3);
        File::deleteDirectory($sandbox['sandbox']);
    }
});

test('an interrupted update shows a recovery notice, and a fresh session can restore the backup and leave maintenance mode', function () {
    $sandbox = buildUpdaterSandbox('rt-interrupted', '3.0.0', [
        'backup' => [
            'enabled' => true,
            'keep' => 3,
            'include_vendor' => true,
            'exclude' => [],
            'database' => ['enabled' => false],
        ],
    ]);

    file_put_contents($sandbox['appDir'].'/original.txt', 'pre-update content');

    $served = serveUpdaterSandbox($sandbox);

    try {
        $client = $served['client'];

        // Session A drives the run through extract, then "closes the
        // browser": every request from here on uses a fresh session.
        $csrf = unlockUpdater($client, $sandbox);

        $packagePath = storage_path('app/rt-interrupted-'.uniqid().'.update');
        buildUpdaterRtPackage($packagePath, 'rt-interrupted', '3.1.0', UPDATER_RT_SUCCEEDING_HOOK);

        $uploadResult = uploadUpdatePackage($client, $csrf, $packagePath);
        expect($uploadResult['valid'] ?? false)->toBeTrue();

        $reviewBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($reviewBody);

        postForm($client, '/updater.php', ['_csrf' => $csrf, 'action' => 'start-update'], ['allow_redirects' => false]);

        $progressBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($progressBody);

        $result = runUpdateTaskPipeline($client, $csrf, ['prepare', 'backup_files', 'backup_db', 'extract']);
        expect($result['failure'])->toBeNull('Interrupted pipeline leg failed: '.json_encode($result['failure']));

        // The interrupted run left the site down mid-update: maintenance
        // mode on, the on-disk marker in place, the run's lock young.
        expect(file_exists($sandbox['appDir'].'/storage/framework/down'))->toBeTrue()
            ->and(file_exists($sandbox['appDir'].'/storage/app/updater/update-in-progress.json'))->toBeTrue()
            ->and(file_exists($sandbox['appDir'].'/storage/app/updater/update.lock'))->toBeTrue()
            ->and(file_get_contents($sandbox['appDir'].'/config/app.php'))->toContain("'version' => '3.1.0'");

        // A fresh session reaches the home screen and is told what
        // happened, with the rollback and resume paths both offered.
        $fresh = freshUpdaterSession($sandbox, $served);
        $freshClient = $fresh['client'];

        $homeBody = $freshClient->get('/updater.php')->body();
        expect($homeBody)->toContain('Interrupted update detected')
            ->and($homeBody)->toContain('maintenance mode')
            ->and($homeBody)->toContain('restore-interrupted')
            ->and($homeBody)->toContain('will accept it and resume');

        $csrf = extractCsrfToken($homeBody);
        $restore = postForm($freshClient, '/updater.php', ['_csrf' => $csrf, 'action' => 'restore-interrupted'], ['allow_redirects' => false]);
        expect($restore->redirect())->toBeTrue();

        $progressBody = $freshClient->get('/updater.php')->body();
        expect($progressBody)->toContain('Restoring the pre-update backup');

        $csrf = extractCsrfToken($progressBody);

        $restoreDone = false;
        for ($i = 0; $i < 50; $i++) {
            $response = postForm($freshClient, '/updater.php?ajax=update-task&task=restore', ['_csrf' => $csrf])->json();
            expect($response['success'] ?? false)->toBeTrue('Recovery restore failed: '.json_encode($response));

            if (($response['task_done'] ?? false) === true) {
                $restoreDone = true;
                break;
            }
        }
        expect($restoreDone)->toBeTrue();

        // The rollback returned the app to its pre-update state and — the
        // actual complaint — brought the site out of maintenance mode,
        // clearing the marker and the abandoned lock along the way.
        expect(file_get_contents($sandbox['appDir'].'/config/app.php'))->toContain("'version' => '3.0.0'")
            ->and(trim((string) file_get_contents($sandbox['appDir'].'/original.txt')))->toBe('pre-update content')
            ->and(file_exists($sandbox['appDir'].'/marker.txt'))->toBeFalse()
            ->and(file_exists($sandbox['appDir'].'/storage/framework/down'))->toBeFalse()
            ->and(file_exists($sandbox['appDir'].'/storage/app/updater/update.lock'))->toBeFalse()
            ->and(file_exists($sandbox['appDir'].'/storage/app/updater/update-in-progress.json'))->toBeFalse();

        $homeAfter = $freshClient->get('/updater.php?screen=home')->body();
        expect($homeAfter)->not->toContain('Interrupted update detected');

        $resultFiles = glob($sandbox['appDir'].'/storage/app/updater/results/*-rolled_back.json');
        expect($resultFiles)->not->toBeEmpty();

        // The journal's "from" is the pre-update version recorded in the
        // backup's metadata — not the version literal extraction already
        // overwrote — so the admin panel shows v3.0.0 → v3.1.0 rolled back.
        $journal = json_decode((string) file_get_contents($resultFiles[0]), true);
        expect($journal['version_from'] ?? null)->toBe('3.0.0')
            ->and($journal['version_to'] ?? null)->toBe('3.1.0');

        @unlink($packagePath);
    } finally {
        $served['process']->stop(3);
        File::deleteDirectory($sandbox['sandbox']);
    }
});

test('an interrupted run that never completed its backup offers no rollback, even though older backups survive', function () {
    $sandbox = buildUpdaterSandbox('rt-oldbackup', '6.0.0', [
        'backup' => [
            'enabled' => true,
            'keep' => 3,
            'include_vendor' => true,
            'exclude' => [],
            'database' => ['enabled' => false],
        ],
    ]);

    $served = serveUpdaterSandbox($sandbox);

    try {
        $client = $served['client'];

        // A first update completes normally and leaves its backup behind
        // (prune keeps the newest 3).
        $firstPath = storage_path('app/rt-oldbackup-first-'.uniqid().'.update');
        buildUpdaterRtPackage($firstPath, 'rt-oldbackup', '6.1.0', UPDATER_RT_SUCCEEDING_HOOK);

        $csrf = unlockUpdater($client, $sandbox);
        $uploadResult = uploadUpdatePackage($client, $csrf, $firstPath);
        expect($uploadResult['valid'] ?? false)->toBeTrue();

        $reviewBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($reviewBody);
        postForm($client, '/updater.php', ['_csrf' => $csrf, 'action' => 'start-update'], ['allow_redirects' => false]);

        $progressBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($progressBody);

        $result = runUpdateTaskPipeline($client, $csrf);
        expect($result['failure'])->toBeNull();

        $oldBackups = glob($sandbox['appDir'].'/storage/app/updater/backups/*', GLOB_ONLYDIR) ?: [];
        expect($oldBackups)->not->toBeEmpty();

        // A file added after the first update — rolling back to the old
        // backup would delete it, which is exactly why the old backup must
        // never be offered for the second run's recovery.
        file_put_contents($sandbox['appDir'].'/keeper.txt', 'added between updates');

        // Separate the old backup's creation time from the second run's
        // marker timestamp — recovery qualifies a backup by comparing the
        // two, and the test runs fast enough for them to land in the same
        // second without this.
        sleep(2);

        // The second run dies right after prepare: marker written,
        // maintenance mode on, but its own backup never started.
        $client->get('/updater.php?screen=home');
        $homeBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($homeBody);

        $secondPath = storage_path('app/rt-oldbackup-second-'.uniqid().'.update');
        buildUpdaterRtPackage($secondPath, 'rt-oldbackup', '6.2.0', UPDATER_RT_SUCCEEDING_HOOK);

        $uploadResult = uploadUpdatePackage($client, $csrf, $secondPath);
        expect($uploadResult['valid'] ?? false)->toBeTrue();

        $reviewBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($reviewBody);
        postForm($client, '/updater.php', ['_csrf' => $csrf, 'action' => 'start-update'], ['allow_redirects' => false]);

        $progressBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($progressBody);

        $result = runUpdateTaskPipeline($client, $csrf, ['prepare']);
        expect($result['failure'])->toBeNull();

        expect(file_exists($sandbox['appDir'].'/storage/app/updater/update-in-progress.json'))->toBeTrue()
            ->and(file_exists($sandbox['appDir'].'/storage/framework/down'))->toBeTrue();

        // The fresh session is told about the interruption, but offered no
        // rollback: the only backup on disk predates the run.
        $fresh = freshUpdaterSession($sandbox, $served);
        $freshClient = $fresh['client'];

        $homeBody = $freshClient->get('/updater.php')->body();
        expect($homeBody)->toContain('Interrupted update detected')
            ->and($homeBody)->toContain('never extracted either')
            ->and($homeBody)->not->toContain('restore-interrupted');

        // A hand-crafted restore-interrupted POST is refused for the same
        // reason rather than rolling back to the stale backup.
        $csrf = extractCsrfToken($homeBody);
        $response = postForm($freshClient, '/updater.php', ['_csrf' => $csrf, 'action' => 'restore-interrupted'], ['allow_redirects' => false]);
        expect($response->redirect())->toBeFalse()
            ->and($response->body())->toContain('No usable backup from the interrupted update');

        // Nothing rolled back: the site still carries the first update's
        // version, the keeper file, and the old backup.
        expect(file_get_contents($sandbox['appDir'].'/config/app.php'))->toContain("'version' => '6.1.0'")
            ->and(trim((string) file_get_contents($sandbox['appDir'].'/keeper.txt')))->toBe('added between updates')
            ->and(glob($sandbox['appDir'].'/storage/app/updater/results/*-rolled_back.json'))->toBeEmpty();

        @unlink($firstPath);
        @unlink($secondPath);
    } finally {
        $served['process']->stop(3);
        File::deleteDirectory($sandbox['sandbox']);
    }
});

test('a fresh session can resume an interrupted update by re-uploading the same package', function () {
    $sandbox = buildUpdaterSandbox('rt-resume', '4.0.0', [
        'backup' => [
            'enabled' => true,
            'keep' => 3,
            'include_vendor' => true,
            'exclude' => [],
            'database' => ['enabled' => false],
        ],
    ]);

    file_put_contents($sandbox['appDir'].'/original.txt', 'pre-update content');

    $served = serveUpdaterSandbox($sandbox);

    try {
        $client = $served['client'];

        // Session A gets through extract, then disappears.
        $csrf = unlockUpdater($client, $sandbox);

        $packagePath = storage_path('app/rt-resume-'.uniqid().'.update');
        buildUpdaterRtPackage($packagePath, 'rt-resume', '4.1.0', UPDATER_RT_SUCCEEDING_HOOK);

        $uploadResult = uploadUpdatePackage($client, $csrf, $packagePath);
        expect($uploadResult['valid'] ?? false)->toBeTrue();

        $reviewBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($reviewBody);

        postForm($client, '/updater.php', ['_csrf' => $csrf, 'action' => 'start-update'], ['allow_redirects' => false]);

        $progressBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($progressBody);

        $result = runUpdateTaskPipeline($client, $csrf, ['prepare', 'backup_files', 'backup_db', 'extract']);
        expect($result['failure'])->toBeNull();

        expect(file_exists($sandbox['appDir'].'/storage/framework/down'))->toBeTrue();

        // The fresh session re-uploads the exact same package: extraction
        // already bumped the version literal to 4.1.0, so only the
        // interrupted-run marker lets validation accept it — and the young
        // abandoned lock must not block the resumed run's prepare.
        $fresh = freshUpdaterSession($sandbox, $served);
        $freshClient = $fresh['client'];
        $csrf = $fresh['csrf'];

        $uploadResult = uploadUpdatePackage($freshClient, $csrf, $packagePath);
        expect($uploadResult['valid'] ?? false)->toBeTrue()
            ->and($uploadResult['version'] ?? null)->toBe('4.1.0');

        $reviewBody = $freshClient->get('/updater.php')->body();
        $csrf = extractCsrfToken($reviewBody);

        postForm($freshClient, '/updater.php', ['_csrf' => $csrf, 'action' => 'start-update'], ['allow_redirects' => false]);

        $progressBody = $freshClient->get('/updater.php')->body();
        $csrf = extractCsrfToken($progressBody);

        $result = runUpdateTaskPipeline($freshClient, $csrf);
        expect($result['failure'])->toBeNull('Resumed pipeline failed: '.json_encode($result['failure']))
            ->and($result['completed'])->toBe(['prepare', 'backup_files', 'backup_db', 'extract', 'post_update', 'verify', 'finalize']);

        // The resumed run finished the update and brought the site back.
        expect(file_get_contents($sandbox['appDir'].'/config/app.php'))->toContain("'version' => '4.1.0'")
            ->and(trim((string) file_get_contents($sandbox['appDir'].'/original.txt')))->toBe('pre-update content')
            ->and(trim((string) file_get_contents($sandbox['appDir'].'/marker.txt')))->toBe('applied 4.1.0')
            ->and(file_exists($sandbox['appDir'].'/storage/framework/down'))->toBeFalse()
            ->and(file_exists($sandbox['appDir'].'/storage/app/updater/update.lock'))->toBeFalse()
            ->and(file_exists($sandbox['appDir'].'/storage/app/updater/update-in-progress.json'))->toBeFalse();

        $resultFiles = glob($sandbox['appDir'].'/storage/app/updater/results/*-applied.json');
        expect($resultFiles)->not->toBeEmpty();

        @unlink($packagePath);
    } finally {
        $served['process']->stop(3);
        File::deleteDirectory($sandbox['sandbox']);
    }
});

test('the database backup requirement fails when the configured mysqldump binary cannot run', function () {
    $sandbox = buildUpdaterSandbox('rt-mysqldump-missing', '1.0.0', [
        'backup' => [
            'enabled' => true,
            'keep' => 3,
            'include_vendor' => false,
            'exclude' => [],
            'database' => ['enabled' => true, 'mysqldump_path' => '/nonexistent/mysqldump'],
        ],
    ]);

    $served = serveUpdaterSandbox($sandbox);

    try {
        $client = $served['client'];

        unlockUpdater($client, $sandbox);

        $homeBody = $client->get('/updater.php')->body();

        // The check must fail (as a warning, not a critical) and say what
        // the consequence is — proc_open merely existing proves nothing
        // about the binary itself.
        expect($homeBody)->toContain('Database Backup (mysqldump)')
            ->and($homeBody)->toContain('/nonexistent/mysqldump could not be executed')
            ->and($homeBody)->toContain('files-only backup')
            ->and($homeBody)->toContain('mysqldump_path');
    } finally {
        $served['process']->stop(3);
        File::deleteDirectory($sandbox['sandbox']);
    }
});

test('the database backup requirement passes when the configured binary runs', function () {
    // PHP_BINARY stands in for a working mysqldump: any executable that
    // answers `--version` with exit 0 satisfies the probe.
    $sandbox = buildUpdaterSandbox('rt-mysqldump-ok', '1.0.0', [
        'backup' => [
            'enabled' => true,
            'keep' => 3,
            'include_vendor' => false,
            'exclude' => [],
            'database' => ['enabled' => true, 'mysqldump_path' => PHP_BINARY],
        ],
    ]);

    $served = serveUpdaterSandbox($sandbox);

    try {
        $client = $served['client'];

        unlockUpdater($client, $sandbox);

        $homeBody = $client->get('/updater.php')->body();

        expect($homeBody)->toContain('the database will be dumped before updating')
            ->and($homeBody)->not->toContain('could not be executed');
    } finally {
        $served['process']->stop(3);
        File::deleteDirectory($sandbox['sandbox']);
    }
});

test('a files-only database backup is recorded in the backup metadata and the results journal', function () {
    $sandbox = buildUpdaterSandbox('rt-filesonly', '5.0.0', [
        'backup' => [
            'enabled' => true,
            'keep' => 3,
            'include_vendor' => true,
            'exclude' => [],
            'database' => ['enabled' => true, 'mysqldump_path' => '/nonexistent/mysqldump'],
        ],
    ]);

    $served = serveUpdaterSandbox($sandbox);

    try {
        $client = $served['client'];

        $csrf = unlockUpdater($client, $sandbox);

        $packagePath = storage_path('app/rt-filesonly-'.uniqid().'.update');
        buildUpdaterRtPackage($packagePath, 'rt-filesonly', '5.1.0', UPDATER_RT_SUCCEEDING_HOOK);

        $uploadResult = uploadUpdatePackage($client, $csrf, $packagePath);
        expect($uploadResult['valid'] ?? false)->toBeTrue();

        $reviewBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($reviewBody);

        postForm($client, '/updater.php', ['_csrf' => $csrf, 'action' => 'start-update'], ['allow_redirects' => false]);

        $progressBody = $client->get('/updater.php')->body();
        $csrf = extractCsrfToken($progressBody);

        // The missing binary degrades the backup to files-only but must
        // not block the update itself.
        $result = runUpdateTaskPipeline($client, $csrf);
        expect($result['failure'])->toBeNull('Pipeline with files-only backup failed: '.json_encode($result['failure']));

        // The degrade is recorded on disk, not just scrolled away in the
        // progress log: metadata.json says what the backup contains, and
        // the results journal carries it to the admin panel.
        $backupDirs = glob($sandbox['appDir'].'/storage/app/updater/backups/*', GLOB_ONLYDIR) ?: [];
        expect($backupDirs)->not->toBeEmpty();

        $metadata = json_decode((string) file_get_contents($backupDirs[0].'/metadata.json'), true);
        expect(is_array($metadata))->toBeTrue()
            ->and((string) ($metadata['database'] ?? ''))->toContain('files-only backup');

        $resultFiles = glob($sandbox['appDir'].'/storage/app/updater/results/*-applied.json');
        expect($resultFiles)->not->toBeEmpty();

        $journal = json_decode((string) file_get_contents($resultFiles[0]), true);
        expect(is_array($journal))->toBeTrue()
            ->and((string) ($journal['backup_database'] ?? ''))->toContain('files-only backup');

        @unlink($packagePath);
    } finally {
        $served['process']->stop(3);
        File::deleteDirectory($sandbox['sandbox']);
    }
});

test('re-uploading the same package is rejected once applied, and an older version is rejected too', function () {
    $sandbox = buildUpdaterSandbox('rt-version-guard', '1.5.0');
    $served = serveUpdaterSandbox($sandbox);

    try {
        $client = $served['client'];

        $csrf = unlockUpdater($client, $sandbox);

        // Same version as currently installed: must be rejected as not newer.
        $samePath = storage_path('app/rt-same-'.uniqid().'.update');
        buildUpdaterRtPackage($samePath, 'rt-version-guard', '1.5.0', UPDATER_RT_SUCCEEDING_HOOK);
        $sameResult = uploadUpdatePackage($client, $csrf, $samePath);

        expect($sameResult['valid'] ?? true)->toBeFalse()
            ->and($sameResult['message'] ?? '')->toContain('must be newer than the current version');

        // Older version: also rejected.
        $olderPath = storage_path('app/rt-older-'.uniqid().'.update');
        buildUpdaterRtPackage($olderPath, 'rt-version-guard', '1.4.0', UPDATER_RT_SUCCEEDING_HOOK);
        $olderResult = uploadUpdatePackage($client, $csrf, $olderPath);

        expect($olderResult['valid'] ?? true)->toBeFalse()
            ->and($olderResult['message'] ?? '')->toContain('must be newer than the current version');

        @unlink($samePath);
        @unlink($olderPath);
    } finally {
        $served['process']->stop(3);
        File::deleteDirectory($sandbox['sandbox']);
    }
});

test('a package containing a path-traversal entry in its inner zip is rejected', function () {
    $sandbox = buildUpdaterSandbox('rt-traversal', '1.0.0');
    $served = serveUpdaterSandbox($sandbox);

    try {
        $client = $served['client'];

        $csrf = unlockUpdater($client, $sandbox);

        $innerPath = sys_get_temp_dir().'/rt-traversal-inner-'.uniqid().'.zip';
        $inner = new ZipArchive;
        $inner->open($innerPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $inner->addFromString('rt-traversal/config/app.php', "<?php\n\nreturn ['version' => '1.1.0'];\n");
        $inner->addFromString('../../evil.php', '<?php echo "pwned"; ');
        $inner->close();

        $checksum = hash_file('sha256', $innerPath);
        $manifest = [
            'type' => 'update',
            'version' => '1.1.0',
            'minimum_version' => '1.0.0',
            'minimum_php' => '8.2.0',
            'checksum' => $checksum,
            'built_at' => date('c'),
            'files_count' => 2,
        ];

        $packagePath = storage_path('app/rt-traversal-'.uniqid().'.update');
        $outer = new ZipArchive;
        $outer->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $outer->addFromString('manifest.json', json_encode($manifest));
        $outer->addFile($innerPath, 'rt-traversal.zip');
        $outer->close();
        @unlink($innerPath);

        $result = uploadUpdatePackage($client, $csrf, $packagePath);

        expect($result['valid'] ?? true)->toBeFalse()
            ->and($result['message'] ?? '')->toContain('invalid file paths');

        @unlink($packagePath);
    } finally {
        $served['process']->stop(3);
        File::deleteDirectory($sandbox['sandbox']);
    }
});

test('an unsigned package is rejected when the app requires signed updates', function () {
    $sandbox = buildUpdaterSandbox('rt-sig', '1.0.0', [
        'signing' => ['trusted_keys' => ['key-test' => base64_encode(str_repeat("\x01", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES))]],
    ]);
    $served = serveUpdaterSandbox($sandbox);

    try {
        $client = $served['client'];

        $csrf = unlockUpdater($client, $sandbox);

        $packagePath = storage_path('app/rt-sig-'.uniqid().'.update');
        buildUpdaterRtPackage($packagePath, 'rt-sig', '1.1.0', UPDATER_RT_SUCCEEDING_HOOK);

        $result = uploadUpdatePackage($client, $csrf, $packagePath);

        expect($result['valid'] ?? true)->toBeFalse()
            ->and($result['message'] ?? '')->toContain('not signed');

        @unlink($packagePath);
    } finally {
        $served['process']->stop(3);
        File::deleteDirectory($sandbox['sandbox']);
    }
});
