#!/bin/bash

# Define the files to update
PLUGIN_FILE="archived-post-status.php"
README_FILE="readme.txt"
PACKAGE_FILE="package.json"
COMPOSER_FILE="composer.json"
README_MD_FILE="readme.md"
CHANGELOG_FILE="changelog.md"

# Function to extract the current version from package.json
get_current_version() {
  jq -r '.version' "$PACKAGE_FILE"
}

# Function to increment version based on the provided flag
increment_version() {
  local version=$1
  local major minor patch
  IFS='.' read -r major minor patch <<< "$version"

  case $2 in
    --major)
      major=$((major + 1))
      minor=0
      patch=0
      ;;
    --minor)
      minor=$((minor + 1))
      patch=0
      ;;
    --patch)
      patch=$((patch + 1))
      ;;
    *)
      echo "Invalid flag. Use --major, --minor, or --patch."
      exit 1
      ;;
  esac

  echo "$major.$minor.$patch"
}

# Initialize variables
NEW_VERSION=""
CHANGELOG_MESSAGE=""

# Parse arguments
while [[ $# -gt 0 ]]
do
  key="$1"

  case $key in
    --major|--minor|--patch)
      CURRENT_VERSION=$(get_current_version)
      if [[ -z "$CURRENT_VERSION" ]]; then
        echo "Error: Could not extract current version from $PACKAGE_FILE"
        exit 1
      fi
      NEW_VERSION=$(increment_version "$CURRENT_VERSION" "$1")
      shift
      ;;
    -m|--message)
      CHANGELOG_MESSAGE="$2"
      shift
      shift
      ;;
    -t|--title)
      CHANGELOG_TITLE="$2"
      shift
      shift
      ;;
    *)
      if [[ -z $NEW_VERSION ]]; then
        NEW_VERSION="$1"
      fi
      shift
      ;;
  esac
done

# Default to patch increment if no version provided
if [[ -z $NEW_VERSION ]]; then
  CURRENT_VERSION=$(get_current_version)
  if [[ -z "$CURRENT_VERSION" ]]; then
    echo "Error: Could not extract current version from $PACKAGE_FILE"
    exit 1
  fi
  NEW_VERSION=$(increment_version "$CURRENT_VERSION" --patch)
fi

echo "New version: $NEW_VERSION"  # Debug statement

# Update the version in package.json using jq with 4 spaces indentation, then convert spaces to tabs
jq ".version = \"$NEW_VERSION\"" "$PACKAGE_FILE" | sed 's/    /\t/g' > "$PACKAGE_FILE.tmp" && mv "$PACKAGE_FILE.tmp" "$PACKAGE_FILE"

# Update the version in composer.json using jq with 4 spaces indentation, then convert spaces to tabs
jq ".version = \"$NEW_VERSION\"" "$COMPOSER_FILE" | sed 's/    /\t/g' > "$COMPOSER_FILE.tmp" && mv "$COMPOSER_FILE.tmp" "$COMPOSER_FILE"

# Update the version in package-lock.json
npm install --package-lock-only

# Update the version in composer.lock
composer update --lock --no-install

# Update the version in the PHP doc block in plugin.php
sed -i.bak -E "s/(Version: +).*/\1$NEW_VERSION/" "$PLUGIN_FILE"

# Update the version constant definition in plugin.php
sed -i.bak -E "s/(define\( 'ARCHIVED_POST_STATUS_VERSION', ').*(' \);)/\1$NEW_VERSION\2/" "$PLUGIN_FILE"

# Update the stable version in readme.txt
sed -i.bak -E "s/(Stable tag: +).*/\1$NEW_VERSION/" "$README_FILE"

# Update the stable version in readme.md
sed -i.bak -E "s/(**Stable tag:** +).*/\1$NEW_VERSION/" "$README_MD_FILE"

# Add a new entry to the changelog.md above the top-most H2 header
DATE=$(date +"%Y-%m-%d")
if [[ -n $CHANGELOG_TITLE ]]; then
  CHANGELOG_HEADER="## [$NEW_VERSION] $CHANGELOG_TITLE - $DATE"
else
  CHANGELOG_HEADER="## [$NEW_VERSION] - $DATE"
fi
if [[ -n $CHANGELOG_MESSAGE ]]; then
  CHANGELOG_ENTRY="$CHANGELOG_HEADER\n$CHANGELOG_MESSAGE\n"
else
  CHANGELOG_ENTRY="$CHANGELOG_HEADER\n"
fi
awk -v entry="$CHANGELOG_ENTRY" 'BEGIN { print entry } /^## / { p=1 } p { print } !p' "$CHANGELOG_FILE" > "$CHANGELOG_FILE.tmp" && mv "$CHANGELOG_FILE.tmp" "$CHANGELOG_FILE"

# Clean up backup files created by sed
rm -f "$PLUGIN_FILE.bak" "$README_FILE.bak" "$README_MD_FILE.bak"

echo "Version updated to $NEW_VERSION in $PACKAGE_FILE, $PLUGIN_FILE, $README_FILE, $README_MD_FILE, and $CHANGELOG_FILE"
