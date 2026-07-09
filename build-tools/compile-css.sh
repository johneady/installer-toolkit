#!/usr/bin/env bash
#
# Regenerates templates/install/installer.css from build-tools/hearth.css.
#
# Run this from the toolkit repo whenever hearth.css changes. This is a
# toolkit-maintainer step only — consuming projects never run this;
# bin/build just inlines the committed installer.css into the generated
# install.php.
#
# hearth.css is hand-authored (not Tailwind-generated): the installer UI
# uses a bespoke component system (h-card, h-btn, h-field, etc.) rather
# than combinations of utility classes, so there is nothing for Tailwind
# to scan for. This script just copies the file, stripping comments so the
# inlined output stays lean.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

# Strip /* ... */ comments (including multi-line) and blank lines.
perl -0777 -pe 's/\/\*.*?\*\///gs' ./hearth.css | sed '/^\s*$/d' > ../templates/install/installer.css

echo "Wrote templates/install/installer.css"
