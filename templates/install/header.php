<?php

/**
 * Web Installer
 *
 * A standalone, self-contained installation wizard for PHP applications.
 * Upload this file alongside the accompanying zip to your web server's document root
 * and visit this file in your browser to begin the installation.
 *
 * www.yourdomain.com/install.php
 */

// ============================================================================
// DO NOT TOUCH ANY CODE BELOW
// ============================================================================

// The values below are placeholders. bin/build replaces this entire block
// with real values from the consuming app's package/package-config.php
// (zip filename, app folder, min_php_version) every time it generates that
// app's install.php — editing them here has no effect on any deployed app.
// [[INSTALLER_CONFIG]]
define('ZIP_FILENAME', 'Generated at build time');
define('APP_FOLDER', 'Generated at build time');
define('MIN_PHP_VERSION', 'Generated at build time');
// [[/INSTALLER_CONFIG]]

define('EULA_TEXT', <<<'EULA'
END USER LICENSE AGREEMENT (EULA)

IMPORTANT: PLEASE READ THIS LICENSE AGREEMENT CAREFULLY BEFORE INSTALLING OR USING THIS SOFTWARE.

By installing, copying, or otherwise using this software ("Software"), you agree to be bound by the terms of this End User License Agreement ("Agreement"). If you do not agree to these terms, do not install or use the Software.

1. LICENSE GRANT
The licensor grants you a non-exclusive, non-transferable license to install and use the Software on a single website or server for your personal or business purposes, subject to the terms of this Agreement.

2. RESTRICTIONS
You may not:
(a) Redistribute, sublicense, sell, lease, or otherwise transfer the Software to any third party.
(b) Modify, reverse engineer, decompile, or disassemble the Software except as permitted by applicable law.
(c) Remove or alter any proprietary notices, labels, or marks on the Software.
(d) Use the Software to operate a service bureau or provide hosting services to third parties.

3. OWNERSHIP
The Software is licensed, not sold. The licensor retains all rights, title, and interest in and to the Software, including all intellectual property rights.

4. DISCLAIMER OF WARRANTIES
THE SOFTWARE IS PROVIDED "AS IS" WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NONINFRINGEMENT. THE LICENSOR DOES NOT WARRANT THAT THE SOFTWARE WILL BE ERROR-FREE OR UNINTERRUPTED.

5. LIMITATION OF LIABILITY
IN NO EVENT SHALL THE LICENSOR BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, INCLUDING BUT NOT LIMITED TO LOSS OF PROFITS, DATA, OR USE, ARISING OUT OF OR IN CONNECTION WITH THIS AGREEMENT OR THE USE OF THE SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGES. THE LICENSOR'S TOTAL LIABILITY SHALL NOT EXCEED THE AMOUNT PAID FOR THE SOFTWARE.

6. INDEMNIFICATION
You agree to indemnify and hold harmless the licensor from any claims, damages, losses, or expenses arising from your use of the Software or your breach of this Agreement.

7. SUPPORT AND UPDATES
The licensor is not obligated to provide support, maintenance, or updates for the Software unless separately agreed upon. Any updates provided shall be subject to this Agreement.

8. TERMINATION
This Agreement is effective until terminated. The licensor may terminate this Agreement immediately if you breach any of its terms. Upon termination, you must cease all use of the Software and destroy all copies.

9. GOVERNING LAW
This Agreement shall be governed by and construed in accordance with the laws of the jurisdiction in which the licensor resides, without regard to its conflict of law provisions.

10. ENTIRE AGREEMENT
This Agreement constitutes the entire agreement between you and the licensor regarding the Software and supersedes all prior agreements, understandings, and communications.

By proceeding with the installation, you acknowledge that you have read, understood, and agree to be bound by this Agreement.
EULA
);

// ============================================================================
// Installer Class
// ============================================================================
