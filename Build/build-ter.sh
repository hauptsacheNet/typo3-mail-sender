#!/bin/bash
#
# Build script for creating a TER-ready extension zip
# This bundles the required PHP libraries for non-composer installations
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
EXTENSION_KEY="mail_sender"

# Get version from ext_emconf.php
VERSION=$(php -r "
    \$_EXTKEY = '$EXTENSION_KEY';
    include '$PROJECT_DIR/ext_emconf.php';
    echo \$EM_CONF[\$_EXTKEY]['version'];
")

BUILD_DIR="$PROJECT_DIR/.build"
DIST_DIR="$PROJECT_DIR/dist"
EXTENSION_DIR="$BUILD_DIR/$EXTENSION_KEY"

echo "Building TER package for $EXTENSION_KEY version $VERSION"

# Clean up previous builds
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"
mkdir -p "$DIST_DIR"
mkdir -p "$EXTENSION_DIR"

# Copy extension files (excluding development files)
echo "Copying extension files..."
cp -r "$PROJECT_DIR"/* "$EXTENSION_DIR/"

# Remove development files and directories
rm -rf "$EXTENSION_DIR/.git" 2>/dev/null || true
rm -rf "$EXTENSION_DIR/.github" 2>/dev/null || true
rm -rf "$EXTENSION_DIR/.build" 2>/dev/null || true
rm -rf "$EXTENSION_DIR/dist" 2>/dev/null || true
rm -rf "$EXTENSION_DIR/vendor" 2>/dev/null || true
rm -rf "$EXTENSION_DIR/node_modules" 2>/dev/null || true
rm -rf "$EXTENSION_DIR/Tests" 2>/dev/null || true
rm -rf "$EXTENSION_DIR/public" 2>/dev/null || true
rm -rf "$EXTENSION_DIR/var" 2>/dev/null || true
rm -rf "$EXTENSION_DIR/config" 2>/dev/null || true
rm -f "$EXTENSION_DIR/phpunit.xml.dist" 2>/dev/null || true
rm -f "$EXTENSION_DIR/composer.lock" 2>/dev/null || true
rm -rf "$EXTENSION_DIR/.idea" 2>/dev/null || true
rm -f "$EXTENSION_DIR/.DS_Store" 2>/dev/null || true
rm -f "$EXTENSION_DIR"/*.local 2>/dev/null || true
rm -rf "$EXTENSION_DIR/Resources/Private/PHP/vendor" 2>/dev/null || true
rm -f "$EXTENSION_DIR/Resources/Private/PHP/composer.lock" 2>/dev/null || true

# Install bundled libraries
echo "Installing bundled libraries..."
cd "$EXTENSION_DIR/Resources/Private/PHP"
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction

# Remove packages that TYPO3 already provides (they are marked as "replace" but composer still downloads them)
echo "Cleaning up redundant packages..."
rm -rf vendor/guzzlehttp 2>/dev/null || true
rm -rf vendor/psr/log 2>/dev/null || true
rm -rf vendor/psr/http-message 2>/dev/null || true

# Regenerate autoloader after removing packages
composer dump-autoload --optimize --classmap-authoritative --no-interaction

cd "$BUILD_DIR"

# Create the zip file
ZIP_FILE="$DIST_DIR/${EXTENSION_KEY}_${VERSION}.zip"
echo "Creating zip file: $ZIP_FILE"
zip -r "$ZIP_FILE" "$EXTENSION_KEY" -x "*.git*"

# Clean up build directory
rm -rf "$BUILD_DIR"

echo ""
echo "TER package created successfully!"
echo "  File: $ZIP_FILE"
echo "  Size: $(du -h "$ZIP_FILE" | cut -f1)"
echo ""
echo "You can upload this file to the TYPO3 Extension Repository."
