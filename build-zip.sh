#!/bin/bash

set -e

PLUGIN_SLUG="aisk-ai-chat-for-fluentcart"
TMP_DIR="../${PLUGIN_SLUG}-release"
ZIP_PATH="../${PLUGIN_SLUG}.zip"

# Clean up any previous temp or zip
rm -rf "$TMP_DIR"
rm -f "$ZIP_PATH"

# Copy plugin to temp directory with correct folder name
mkdir "$TMP_DIR"
rsync -av --exclude='.git*' --exclude='.github' --exclude='.wordpress-org' --exclude='node_modules' --exclude='wp-cli.phar' --exclude='*.log' --exclude='.user.ini' --exclude='.DS_Store' --exclude='test.*' --exclude='build-zip.sh' --exclude='report.txt' ./ "$TMP_DIR/$PLUGIN_SLUG/"

# Zip the temp directory
cd "$TMP_DIR"
zip -r "$ZIP_PATH" "$PLUGIN_SLUG"
mv "$ZIP_PATH" ../
cd ..
rm -rf "$TMP_DIR"

echo "✅ Zip archive created at: $ZIP_PATH"
