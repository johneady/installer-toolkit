
// ============================================================================
// Run the installer
// ============================================================================

/**
 * Render a clean, standalone error page when an unhandled exception
 * escapes the installer. Provides the full error detail in a copy-
 * pasteable block and directs the user to raise a support ticket.
 */
function renderFatalErrorPage(Throwable $e): void
{
    // If headers haven't been sent yet, set a 500 status.
    if (! headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    $version = defined('INSTALLER_VERSION') ? htmlspecialchars(INSTALLER_VERSION) : 'unknown';
    $phpVersion = htmlspecialchars(PHP_VERSION);
    $timestamp = date('Y-m-d H:i:s T');
    $url = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '');
    $errorClass = htmlspecialchars(get_class($e));
    $message = htmlspecialchars($e->getMessage());
    $file = htmlspecialchars($e->getFile());
    $line = (int) $e->getLine();

    // Build the plain-text error block the user can copy
    $traceLines = [];
    foreach ($e->getTrace() as $i => $frame) {
        $f = $frame['file'] ?? '[internal]';
        $l = $frame['line'] ?? 0;
        $fn = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
        $traceLines[] = "#{$i} {$f}:{$l} {$fn}()";
        if ($i >= 9) { // limit trace depth for readability
            $traceLines[] = '... (truncated)';
            break;
        }
    }
    $traceText = implode("\n", $traceLines);

    $copyBlock = htmlspecialchars(
        "=== Application Installer Error ===\n"
        ."Timestamp:  {$timestamp}\n"
        ."URL:        {$url}\n"
        ."Installer:  v{$version}\n"
        ."PHP:        {$phpVersion}\n"
        ."Error:      {$errorClass}\n"
        ."Message:    {$e->getMessage()}\n"
        ."File:       {$file}\n"
        ."Line:       {$line}\n"
        ."\nStack Trace:\n{$traceText}\n"
        ."=====================================\n"
    );

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Error — Application Installer</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2rem 1rem;
            line-height: 1.6;
        }
        .wrapper {
            width: 100%;
            max-width: 860px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            color: #475569;
        }
        .brand-icon {
            font-size: 1.5rem;
        }
        .card {
            background: #ffffff;
            border: 1.5px solid #fecaca;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 0 0 4px rgba(239,68,68,0.08), 0 20px 40px rgba(0,0,0,0.08);
        }
        .card-header {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid #fecaca;
        }
        .card-header .icon {
            font-size: 2rem;
            flex-shrink: 0;
        }
        .card-header h1 {
            font-size: 1.375rem;
            font-weight: 800;
            color: #991b1b;
            letter-spacing: -0.02em;
        }
        .card-header p {
            font-size: 0.875rem;
            color: #b91c1c;
            margin-top: 0.25rem;
        }
        .card-body {
            padding: 1.75rem 2rem;
        }
        .section-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            margin-bottom: 0.625rem;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: max-content 1fr;
            gap: 0.375rem 1rem;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }
        .meta-grid dt {
            font-weight: 600;
            color: #475569;
            white-space: nowrap;
        }
        .meta-grid dd {
            color: #0f172a;
            word-break: break-word;
        }
        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 0.5rem;
            padding: 1rem 1.25rem;
            font-size: 0.9375rem;
            color: #b91c1c;
            font-weight: 600;
            margin-bottom: 1.75rem;
            line-height: 1.5;
        }
        .copy-section {
            margin-bottom: 1.75rem;
        }
        .copy-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.625rem;
        }
        .copy-btn {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 0.375rem;
            color: #475569;
            font-size: 0.8125rem;
            font-weight: 600;
            padding: 0.375rem 0.875rem;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
        }
        .copy-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .copy-btn.copied {
            background: #dcfce7;
            border-color: #86efac;
            color: #166534;
        }
        .error-block {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.625rem;
            padding: 1rem 1.25rem;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 0.8125rem;
            color: #475569;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 320px;
            overflow-y: auto;
            line-height: 1.7;
        }
        .support-box {
            background: #eff6ff;
            border: 1.5px solid #bfdbfe;
            border-radius: 0.75rem;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }
        .support-box .support-icon {
            font-size: 1.75rem;
            flex-shrink: 0;
            line-height: 1;
            margin-top: 0.125rem;
        }
        .support-box h3 {
            font-size: 1rem;
            font-weight: 700;
            color: #1d4ed8;
            margin-bottom: 0.375rem;
        }
        .support-box p {
            font-size: 0.875rem;
            color: #475569;
            margin-bottom: 0.625rem;
        }
        .support-link {
            display: inline-block;
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.875rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            text-decoration: none;
            transition: opacity 0.15s;
        }
        .support-link:hover {
            opacity: 0.85;
        }
        .footer {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.75rem;
            color: #94a3b8;
        }
        @media (max-width: 600px) {
            .card-body { padding: 1.25rem; }
            .card-header { padding: 1.25rem; }
            .meta-grid { grid-template-columns: 1fr; gap: 0.25rem; }
            .meta-grid dt { margin-top: 0.5rem; }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="brand">
            <span class="brand-icon">⚙️</span>
            Application Installer v{$version}
        </div>

        <div class="card">
            <div class="card-header">
                <div class="icon">🚨</div>
                <div>
                    <h1>An Unexpected Error Occurred</h1>
                    <p>The installer encountered an unhandled exception and could not continue.</p>
                </div>
            </div>

            <div class="card-body">
                <p class="section-title">Error Details</p>
                <dl class="meta-grid">
                    <dt>Type</dt><dd>{$errorClass}</dd>
                    <dt>File</dt><dd>{$file} (line {$line})</dd>
                    <dt>PHP</dt><dd>{$phpVersion}</dd>
                    <dt>Time</dt><dd>{$timestamp}</dd>
                </dl>

                <div class="error-message">{$message}</div>

                <div class="copy-section">
                    <div class="copy-header">
                        <p class="section-title" style="margin:0;">Full Error Report — Copy This When Raising a Ticket</p>
                        <button class="copy-btn" id="copy-btn" onclick="copyError()">Copy to Clipboard</button>
                    </div>
                    <pre class="error-block" id="error-block">{$copyBlock}</pre>
                </div>

                <div class="support-box">
                    <div class="support-icon">💬</div>
                    <div>
                        <h3>Need Help?</h3>
                        <p>
                            Copy the error report above and raise a support ticket. Our team will help
                            you resolve the issue as quickly as possible.
                        </p>
                        <a href="https://support.powerphpscripts.com" target="_blank" rel="noopener noreferrer" class="support-link">
                            Raise a Support Ticket &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="footer">Application Installer v{$version} &mdash; <a href="https://support.powerphpscripts.com" target="_blank" rel="noopener noreferrer" style="color:#334155;">support.powerphpscripts.com</a></div>
    </div>

    <script>
        function copyError() {
            var text = document.getElementById('error-block').textContent;
            var btn  = document.getElementById('copy-btn');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    btn.textContent = 'Copied!';
                    btn.classList.add('copied');
                    setTimeout(function() {
                        btn.textContent = 'Copy to Clipboard';
                        btn.classList.remove('copied');
                    }, 2500);
                }).catch(function() { fallbackCopy(text, btn); });
            } else {
                fallbackCopy(text, btn);
            }
        }
        function fallbackCopy(text, btn) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity  = '0';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                btn.textContent = 'Copied!';
                btn.classList.add('copied');
                setTimeout(function() {
                    btn.textContent = 'Copy to Clipboard';
                    btn.classList.remove('copied');
                }, 2500);
            } catch(e) {}
            document.body.removeChild(ta);
        }
    </script>
</body>
</html>
HTML;
}

try {
    (new Installer)->run();
} catch (Throwable $e) {
    renderFatalErrorPage($e);
}
