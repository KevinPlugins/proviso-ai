#!/usr/bin/env bash
#
# Build a distributable zip for WordPress.org.
#
# Ships only what a site needs to run the plugin: PHP, the compiled admin app,
# and readme.txt. Everything else — Vue sources, tests, tooling config, dot
# files — is left behind, which is both what the directory expects and what
# keeps the download small.
#
# Usage:
#   bin/build-zip.sh            build release/proviso-<version>.zip
#   bin/build-zip.sh --check    dry run: list what would ship, build nothing
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="$(basename "$ROOT")"
MAIN="$ROOT/$SLUG.php"
DIST="$ROOT/release"
CHECK=0
[[ "${1:-}" == "--check" ]] && CHECK=1

red()  { printf '\033[31m%s\033[0m\n' "$*"; }
grn()  { printf '\033[32m%s\033[0m\n' "$*"; }
ylw()  { printf '\033[33m%s\033[0m\n' "$*"; }
die()  { red "error: $*"; exit 1; }

[[ -f "$MAIN" ]] || die "main plugin file not found: $MAIN"

# --- version, read from the header and cross-checked against readme.txt ------
VERSION="$(grep -m1 -E '^\s*\*\s*Version:' "$MAIN" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
[[ -n "$VERSION" ]] || die "could not read Version from the plugin header"

STABLE="$(grep -m1 -E '^Stable tag:' "$ROOT/readme.txt" 2>/dev/null | sed -E 's/^Stable tag:[[:space:]]*//' | tr -d '[:space:]' || true)"
if [[ -n "$STABLE" && "$STABLE" != "$VERSION" ]]; then
	die "version mismatch — header says $VERSION, readme.txt Stable tag says $STABLE"
fi

echo "building $SLUG $VERSION"

# --- preflight ---------------------------------------------------------------
# The compiled admin app is the one artefact that is generated rather than
# written, so it is the one that can silently be missing or stale.
[[ -f "$ROOT/assets/app.js" && -f "$ROOT/assets/app.css" ]] \
	|| die "assets/app.js or assets/app.css missing — run the app build first (cd app && npm run build)"

if [[ -d "$ROOT/app/src" ]]; then
	NEWEST_SRC="$(find "$ROOT/app/src" -type f -newer "$ROOT/assets/app.js" -print -quit 2>/dev/null || true)"
	[[ -n "$NEWEST_SRC" ]] && ylw "warning: app/src is newer than assets/app.js — the bundle may be stale"
fi

# PHP syntax gate. Shipping a parse error is unrecoverable for a user.
ERRS=0
while IFS= read -r -d '' f; do
	php -l "$f" >/dev/null 2>&1 || { red "  syntax error: ${f#"$ROOT"/}"; ERRS=1; }
done < <(find "$ROOT" -name '*.php' -not -path '*/app/*' -not -path '*/tests/*' -not -path '*/release/*' -print0)
[[ "$ERRS" -eq 0 ]] || die "fix the syntax errors above"

# --- staging -----------------------------------------------------------------
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
STAGE="$TMP/$SLUG"
mkdir -p "$STAGE"

# rsync honours .distignore, so the exclude list lives in one place and the
# same rules apply whether you build here or with wp dist-archive.
EXCLUDES=()
if [[ -f "$ROOT/.distignore" ]]; then
	while IFS= read -r line; do
		[[ -z "$line" || "$line" == \#* ]] && continue
		EXCLUDES+=( --exclude="${line#/}" )
	done < "$ROOT/.distignore"
fi
# Belt and braces: never ship VCS metadata or editor debris.
EXCLUDES+=( --exclude='.git' --exclude='.git/**' --exclude='.github' --exclude='.DS_Store'
            --exclude='release' --exclude='node_modules' --exclude='*.map'
            --exclude='.claude' --exclude='composer.json' --exclude='composer.lock'
            --exclude='package.json' --exclude='package-lock.json' --exclude='phpunit.xml*' )

rsync -a "${EXCLUDES[@]}" "$ROOT"/ "$STAGE"/

# --- sanity on the staged copy ----------------------------------------------
[[ -f "$STAGE/$SLUG.php" ]] || die "staged tree is missing $SLUG.php"
[[ -f "$STAGE/readme.txt" ]] || die "staged tree is missing readme.txt"

LEAKED="$(cd "$STAGE" && find . \( -name 'node_modules' -o -name '.git' -o -name '*.map' -o -name '.DS_Store' \) -print -quit)"
[[ -z "$LEAKED" ]] || die "development files leaked into the build: $LEAKED"

if [[ "$CHECK" -eq 1 ]]; then
	echo
	echo "would ship:"
	( cd "$TMP" && find "$SLUG" -type f | sort | sed 's/^/  /' )
	echo
	echo "size: $(du -sh "$STAGE" | cut -f1)"
	exit 0
fi

# --- zip ---------------------------------------------------------------------
mkdir -p "$DIST"
ZIP="$DIST/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$TMP" && zip -qr "$ZIP" "$SLUG" -x '*.DS_Store' )

echo
echo "contents:"
unzip -l "$ZIP" | awk 'NR>3 && $4!="" && $1!="---------" {printf "  %-46s %8s\n", $4, $1}' | grep -v '/$'
echo
grn "built  $ZIP"
echo "size   $(du -h "$ZIP" | cut -f1)"
echo "files  $(unzip -l "$ZIP" | tail -1 | awk '{print $2}')"

# --- reminders that are easy to forget --------------------------------------
echo
[[ -f "$ROOT/uninstall.php" ]] || ylw "note: no uninstall.php — tables and options will survive deletion"
ls "$ROOT"/.wordpress-org/screenshot-*.png >/dev/null 2>&1 \
	|| ylw "note: no .wordpress-org/screenshot-*.png — readme.txt lists screenshots"
