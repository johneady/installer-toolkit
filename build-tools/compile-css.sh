#!/usr/bin/env bash
#
# Regenerates templates/install/installer.css from the Tailwind classes
# actually used in templates/install/**/*.php.
#
# Run this from the toolkit repo whenever a template file's markup changes
# (new classes added/removed). This is a toolkit-maintainer step only —
# consuming projects never run this; bin/build just inlines the committed
# installer.css into the generated install.php.
#
# Requires Node/npm (uses npx to fetch the Tailwind v3 CLI on demand).

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

npx -y tailwindcss@3 \
    -i ./input.css \
    -o ../templates/install/installer.css \
    -c ./tailwind.config.js \
    --minify

echo "Wrote templates/install/installer.css"
