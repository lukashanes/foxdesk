#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if ! command -v node >/dev/null 2>&1; then
  echo "node is required to read version.json" >&2
  exit 127
fi

if ! command -v zip >/dev/null 2>&1; then
  echo "zip is required to build the update package" >&2
  exit 127
fi

VERSION="$(node -e "const v=require('./version.json'); if(!v.version) process.exit(1); process.stdout.write(v.version)")"
BUILD_DIR="build/update-${VERSION}"
FILES_DIR="${BUILD_DIR}/files"
ZIP_PATH="build/foxdesk-update-${VERSION}.zip"

rm -rf "$BUILD_DIR" "$ZIP_PATH" "${ZIP_PATH}.sha256"
mkdir -p "$FILES_DIR"

copy_if_exists() {
  local path="$1"
  if [[ -e "$path" ]]; then
    mkdir -p "$FILES_DIR/$(dirname "$path")"
    cp -p "$path" "$FILES_DIR/$path"
  fi
}

copy_dir_if_exists() {
  local path="$1"
  if [[ -d "$path" ]]; then
    mkdir -p "$FILES_DIR/$path"
    rsync -a \
      --exclude='.DS_Store' \
      --exclude='*.log' \
      "$path/" "$FILES_DIR/$path/"
  fi
}

top_level_files=(
  ".htaccess"
  "attachment.php"
  "config.example.php"
  "image.php"
  "index.php"
  "install.php"
  "INSTALL.md"
  "LICENSE.md"
  "MANUAL.md"
  "manifest.php"
  "pwa-icon.php"
  "README.md"
  "rescue.php"
  "SELF_HOSTED_TO_SAAS_MIGRATION.md"
  "sw.js"
  "tailwind.min.css"
  "theme.css"
  "upgrade.php"
  "version.json"
)

for file in "${top_level_files[@]}"; do
  copy_if_exists "$file"
done

for dir in assets bin includes pages migrations; do
  copy_dir_if_exists "$dir"
done

find "$FILES_DIR" \
  -type f \
  \( -name '.DS_Store' -o -name '*.map' \) \
  -delete

cp -p version.json "$BUILD_DIR/version.json"

(cd "$FILES_DIR" && find . -type f | sed 's#^\./##' | LC_ALL=C sort) > "$BUILD_DIR/filelist.txt"

(cd "$BUILD_DIR" && zip -qr "../foxdesk-update-${VERSION}.zip" version.json files)

if command -v shasum >/dev/null 2>&1; then
  shasum -a 256 "$ZIP_PATH" > "${ZIP_PATH}.sha256"
elif command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$ZIP_PATH" > "${ZIP_PATH}.sha256"
fi

echo "Built $ZIP_PATH"
echo "Files: $(wc -l < "$BUILD_DIR/filelist.txt" | tr -d ' ')"
