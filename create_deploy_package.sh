#!/bin/bash

# Define the output filename
OUTPUT_FILE="deploy_update.zip"

echo "📦 Preparing deployment package: $OUTPUT_FILE"

# Clean caches to avoid creating a zip with cached configs
echo "🧹 Clearing caches..."
php artisan optimize:clear

# Create the zip file excluding unnecessary files
# -x excludes files/directories
# We exclude:
# - .git: Git history
# - node_modules: Frontend dependencies (usually built assets are in public)
# - tests: Tests are not needed in production
# - storage: Storage should be persistent on server, don't overwrite
# - .env: Environment file is server-specific
# - deploy_update.zip: The file itself
# - create_deploy_package.sh: This script

echo "🗜️  Zipping files..."
zip -r "$OUTPUT_FILE" . -x \
    "*.git*" \
    "node_modules/*" \
    "tests/*" \
    "storage/*.key" \
    "storage/logs/*" \
    "storage/framework/cache/*" \
    "storage/framework/sessions/*" \
    "storage/framework/views/*" \
    ".env" \
    ".env.example" \
    ".editorconfig" \
    ".gitattributes" \
    ".gitignore" \
    "phpunit.xml" \
    "README.md" \
    "DEPLOY_CPANEL.md" \
    "create_deploy_package.sh" \
    "$OUTPUT_FILE"

echo "✅ Package created: $OUTPUT_FILE"
echo "🚀 Upload this file to your cPanel 'public_html' (or api folder) and extract it to overwrite existing files."
