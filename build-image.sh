#!/usr/bin/env bash
set -euo pipefail

xboard_root=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)
xboard_commit=$(git -C "$xboard_root" rev-parse HEAD)
xboard_created=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
xboard_stamp=$(TZ=Asia/Shanghai date '+%Y%m%d-%H%M%S')
xboard_tag="${xboard_stamp}-${xboard_commit:0:12}"
xboard_image="${XBOARD_IMAGE:-xboard}:${xboard_tag}"
xboard_context=$(mktemp -d "${TMPDIR:-/tmp}/xboard-image.XXXXXXXX")
trap 'rm -rf "$xboard_context"' EXIT

# Build the Dockerfile and configuration from the same commit as the app source.
git -C "$xboard_root" archive "$xboard_commit" | tar -x -C "$xboard_context"

printf 'Building %s\nSource commit: %s\n' "$xboard_image" "$xboard_commit"
docker build --pull \
    --build-arg REPO_URL=https://github.com/put-go/Xboard \
    --build-arg BRANCH_NAME=master \
    --build-arg SOURCE_COMMIT="$xboard_commit" \
    --build-arg CACHEBUST="$xboard_commit" \
    --build-arg BUILD_DATE="$xboard_created" \
    --build-arg IMAGE_VERSION="$xboard_tag" \
    --tag "$xboard_image" \
    "$xboard_context"

printf '\nBuilt image: %s\nUse this exact image in compose.yaml.\n' "$xboard_image"
