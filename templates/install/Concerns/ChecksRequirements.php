<?php

trait ChecksRequirements
{
    // ========================================================================
    // Requirements Check
    // ========================================================================

    private function checkRequirements(): array
    {
        $results = [];

        // PHP Version
        $results[] = [
            'name' => 'PHP Version >= '.MIN_PHP_VERSION,
            'detail' => 'Current: '.PHP_VERSION,
            'passed' => version_compare(PHP_VERSION, MIN_PHP_VERSION, '>='),
            'critical' => true,
        ];

        // Required PHP extensions (critical)
        $extensions = ['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'bcmath', 'fileinfo', 'zip', 'gd', 'curl', 'iconv', 'intl'];
        foreach ($extensions as $ext) {
            $results[] = [
                'name' => "PHP Extension: {$ext}",
                'detail' => extension_loaded($ext) ? 'Loaded' : 'Not loaded',
                'passed' => extension_loaded($ext),
                'critical' => true,
            ];
        }

        // Optional PHP extensions (non-critical — fallbacks exist)
        $optionalExtensionFallbacks = [
            'imagick' => [
                'fallback' => 'Image processing will fall back to the GD extension instead.',
                'benefit' => 'If installed: higher-quality image resizing/cropping, broader format support (e.g. TIFF, PSD), and better performance on large images.',
            ],
            'gmp' => [
                'fallback' => 'Cryptographic math will fall back to a slower native PHP implementation instead.',
                'benefit' => 'If installed: faster, more efficient arbitrary-precision math for cryptographic operations.',
            ],
        ];
        foreach ($optionalExtensionFallbacks as $ext => $info) {
            $results[] = [
                'name' => "PHP Extension: {$ext} (optional)",
                'detail' => extension_loaded($ext) ? 'Loaded' : "Not loaded — {$info['fallback']} {$info['benefit']}",
                'passed' => extension_loaded($ext),
                'critical' => false,
            ];
        }

        // ZipArchive
        $results[] = [
            'name' => 'ZipArchive Class',
            'detail' => class_exists('ZipArchive') ? 'Available' : 'Not available',
            'passed' => class_exists('ZipArchive'),
            'critical' => true,
        ];

        // mod_rewrite is intentionally NOT checked here: on PHP-FPM/CGI hosts
        // (where apache_get_modules() is unavailable) its self-HTTP probe can
        // take several seconds, which would block the whole requirements page.
        // It is verified asynchronously instead — see renderRequirements() and
        // the ?ajax=mod-rewrite endpoint. It is non-critical (warning only).

        // Writable directory — verified with a real write test, not just
        // is_writable(), which can report false positives on hosts with
        // ACLs, disk quotas, or open_basedir restrictions.
        $docRootWritable = $this->verifyWritableByTest(__DIR__);
        $results[] = [
            'name' => 'Document Root Writable',
            'detail' => $docRootWritable ? 'Writable (verified by write test)' : 'Not writable — the installer must be able to create files and folders here.',
            'passed' => $docRootWritable,
            'critical' => true,
        ];

        // Pre-existing files from a previous install attempt must be
        // overwritable. Files uploaded via FTP/SSH are often owned by a
        // different user than PHP runs as — a common shared-hosting pitfall
        // that would otherwise fail halfway through extraction.
        $existingPaths = [
            'Existing .htaccess' => __DIR__.'/.htaccess',
            'Existing App Folder ('.APP_FOLDER.')' => __DIR__.'/'.APP_FOLDER,
            'Existing .env File' => __DIR__.'/'.APP_FOLDER.'/.env',
        ];
        foreach ($existingPaths as $label => $path) {
            if (! file_exists($path)) {
                continue;
            }
            $writable = is_dir($path) ? $this->verifyWritableByTest($path) : is_writable($path);
            $results[] = [
                'name' => $label.' Writable',
                'detail' => $writable
                    ? 'Writable — will be overwritten during installation'
                    : 'Not writable — likely left over from a previous attempt and owned by a different user. Delete it or fix its permissions (e.g. via your hosting file manager), then re-run this check.',
                'passed' => $writable,
                'critical' => true,
            ];
        }

        // Restricted core functions (must not be in disable_functions — used by Laravel Scheduler & Symfony Process)
        $requiredCoreFunctions = ['exec', 'shell_exec', 'proc_open', 'proc_close'];
        $disabledFunctions = array_map('trim', explode(',', ini_get('disable_functions')));
        $blockedFunctions = array_filter($requiredCoreFunctions, fn ($f) => in_array($f, $disabledFunctions));
        $results[] = [
            'name' => 'Core Process Functions',
            'detail' => empty($blockedFunctions)
                ? 'exec, shell_exec, proc_open, proc_close are available'
                : 'Disabled via disable_functions: '.implode(', ', $blockedFunctions).' If enabled: scheduled tasks (Laravel Scheduler) and background processes (Symfony Process) can run correctly.',
            'passed' => empty($blockedFunctions),
            'critical' => false,
        ];

        // Zip file exists and is readable
        $zipPath = __DIR__.'/'.ZIP_FILENAME;
        $zipExists = file_exists($zipPath);
        $zipReadable = $zipExists && is_readable($zipPath);
        $results[] = [
            'name' => 'Application Package',
            'detail' => ! $zipExists
                ? ZIP_FILENAME.' Not found'
                : (! $zipReadable
                    ? 'Found '.ZIP_FILENAME.' but it is not readable. Fix its permissions (e.g. 0644) and re-run this check.'
                    : 'Found '.ZIP_FILENAME),
            'passed' => $zipReadable,
            'critical' => true,
        ];

        // Free disk space — extraction needs the working copy of the zip
        // plus the extracted files, roughly 3x the zip size. Heuristic, so
        // a warning rather than a hard block.
        if ($zipReadable) {
            $needed = (int) filesize($zipPath) * 3;
            $free = function_exists('disk_free_space') ? @disk_free_space(__DIR__) : false;
            $results[] = [
                'name' => 'Free Disk Space',
                'detail' => $free === false
                    ? 'Could not determine free space. Ensure at least '.$this->formatBytes($needed).' is available before continuing.'
                    : $this->formatBytes((int) $free).' free ('.($free >= $needed ? 'at least' : 'less than the recommended').' '.$this->formatBytes($needed).' needed)',
                'passed' => $free === false || $free >= $needed,
                'critical' => false,
            ];
        }

        return $results;
    }

    // verifyWritableByTest() and formatBytes() live in the shared
    // ProvidesUtilities trait (templates/shared/Concerns).

    private function checkModRewrite(): array
    {
        // Method 1: apache_get_modules (works when PHP is Apache module)
        if (function_exists('apache_get_modules')) {
            $modules = apache_get_modules();
            if (in_array('mod_rewrite', $modules)) {
                return ['passed' => true, 'detail' => 'Enabled (detected via Apache modules)'];
            }

            return ['passed' => false, 'detail' => 'Not detected in Apache modules'];
        }

        // Method 2: For CGI/FPM - create a temporary .htaccess test
        $testDir = __DIR__.'/_rewrite_test_'.uniqid();
        $testHtaccess = $testDir.'/.htaccess';
        $testTarget = $testDir.'/target.php';

        try {
            if (! mkdir($testDir, 0755, true)) {
                return ['passed' => true, 'detail' => 'Could not verify (unable to create test directory). Ensure mod_rewrite is enabled.'];
            }

            file_put_contents($testTarget, '<?php echo "OK";');
            file_put_contents($testHtaccess, "RewriteEngine On\nRewriteRule ^test$ target.php [L]");

            // Build the test URL
            $protocol = requestIsHttps() ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
            $scriptDir = $scriptDir === '/' ? '' : $scriptDir;
            $testUrl = "{$protocol}://{$host}{$scriptDir}/".basename($testDir).'/test';

            // Attempt a self-request
            $result = false;
            if (function_exists('curl_init')) {
                $ch = curl_init($testUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $result = ($httpCode === 200 && trim($response) === 'OK');
            } elseif (ini_get('allow_url_fopen')) {
                $ctx = stream_context_create(['http' => ['timeout' => 5], 'ssl' => ['verify_peer' => false]]);
                $response = @file_get_contents($testUrl, false, $ctx);
                $result = ($response !== false && trim($response) === 'OK');
            }

            // Cleanup
            @unlink($testTarget);
            @unlink($testHtaccess);
            @rmdir($testDir);

            if ($result) {
                return ['passed' => true, 'detail' => 'Enabled (verified via rewrite test)'];
            }

            return ['passed' => true, 'detail' => 'Could not verify automatically. Please ensure mod_rewrite is enabled in your Apache configuration.'];
        } catch (Exception $e) {
            // Cleanup on error
            @unlink($testTarget ?? '');
            @unlink($testHtaccess ?? '');
            @rmdir($testDir ?? '');

            return ['passed' => true, 'detail' => 'Could not verify (error during test). Ensure mod_rewrite is enabled.'];
        }
    }
}
