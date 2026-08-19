#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

# Highest semantic/version tag, e.g. v1.2.3
TAG="$(git tag --sort=-version:refname | head -n 1)"

if [[ -z "$TAG" ]]; then
    echo "Error: No Git tags found."
    exit 1
fi

# Strip optional leading "v"
VERSION="${TAG#v}"

PACKAGE_NAME="$(basename "$REPO_ROOT")"

DIST_DIR="$REPO_ROOT/dist"
BUILD_DIR="$(mktemp -d)"

cleanup() {
    rm -rf "$BUILD_DIR"
}
trap cleanup EXIT

echo "Building release:"
echo "  Tag:     $TAG"
echo "  Version: $VERSION"

mkdir -p "$DIST_DIR"

# Export exactly the files from the latest tag
git archive "$TAG" | tar -x -C "$BUILD_DIR"

COMPOSER_JSON="$BUILD_DIR/composer.json"

if [[ ! -f "$COMPOSER_JSON" ]]; then
    echo "Error: composer.json not found in tag $TAG"
    exit 1
fi

if grep -q '"version"[[:space:]]*:' "$COMPOSER_JSON"; then
    echo "Error: composer.json already contains a version field."
    exit 1
fi

# Add version immediately after the opening brace.
# Use a temporary file instead of sed -i so this works cleanly on macOS.
sed "1s/{/{\\
    \"version\": \"$VERSION\",/" "$COMPOSER_JSON" > "$COMPOSER_JSON.tmp"

mv "$COMPOSER_JSON.tmp" "$COMPOSER_JSON"

ZIP_FILE="$DIST_DIR/${PACKAGE_NAME}-${VERSION}.zip"

rm -f "$ZIP_FILE"

(
    cd "$BUILD_DIR"
    zip -qr "$ZIP_FILE" .
)

echo
echo "Created:"
echo "  $ZIP_FILE"
