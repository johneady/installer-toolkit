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

        // mod_rewrite check
        $modRewrite = $this->checkModRewrite();
        $results[] = [
            'name' => 'Apache mod_rewrite',
            'detail' => $modRewrite['passed']
                ? $modRewrite['detail']
                : $modRewrite['detail'].' If enabled: clean, SEO-friendly URLs without index.php in the path.',
            'passed' => $modRewrite['passed'],
            'critical' => false, // warning only
        ];

        // Writable directory
        $results[] = [
            'name' => 'Document Root Writable',
            'detail' => is_writable(__DIR__) ? 'Writable' : 'Not writable',
            'passed' => is_writable(__DIR__),
            'critical' => true,
        ];

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

        // Zip file exists
        $results[] = [
            'name' => 'Application Package',
            'detail' => file_exists(__DIR__.'/'.ZIP_FILENAME) ? 'Found '.ZIP_FILENAME : ZIP_FILENAME.' Not found',
            'passed' => file_exists(__DIR__.'/'.ZIP_FILENAME),
            'critical' => true,
        ];

        return $results;
    }

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
            $protocol = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
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
