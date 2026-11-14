#!/usr/bin/env bash
# 本地打包发行包（含 vendor，用户免 Composer）
# 用法: bash build-release.sh v1.0.0
set -euo pipefail
VERSION="${1:-$(date +%Y%m%d)}"
ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT="$ROOT/dist"
NAME="imgbed-$VERSION"

rm -rf "$OUT/$NAME" "$OUT/$NAME.zip" "$OUT/$NAME.zip.sha256"
mkdir -p "$OUT/$NAME"
mkdir -p "$OUT/$NAME/runtime" "$OUT/$NAME/public/storage/images"

rsync -a \
  --exclude '.git' --exclude 'runtime/*' --exclude 'public/storage/images/*' \
  --exclude 'dist' --exclude 'media' --exclude '.env' --exclude 'node_modules' \
  --exclude 'docs/screenshots/*' --exclude 'docs/assets/*' \
  "$ROOT/" "$OUT/$NAME/"

cd "$OUT"
zip -qr "$NAME.zip" "$NAME"
sha256sum "$NAME.zip" > "$NAME.zip.sha256"

echo "打包完成: $OUT/$NAME.zip"
echo "校验文件: $OUT/$NAME.zip.sha256"
