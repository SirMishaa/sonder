#!/usr/bin/env bash
#
# Downloads the standalone Mercure 1.0 hub binary for local dev and CI/Cloud
# builds. FrankenPHP's own built-in Mercure hub still ships the pre-1.0
# protocol (github.com/dunglas/mercure v0.24.2 as of this writing), which
# cannot verify the authorization_details JWTs Laravel's Mercure broadcaster
# mints, so this project runs a separate, pinned 1.0 hub instead. See the
# revision note on Task 1 in
# docs/superpowers/plans/2026-09-13-youtube-music-sync-job.md for the full
# investigation.
set -euo pipefail

VERSION="v1.0.0"
OS="$(uname -s)"
ARCH="$(uname -m)"

case "$OS-$ARCH" in
    Linux-x86_64) ASSET="mercure_Linux_x86_64.tar.gz" ;;
    Linux-aarch64) ASSET="mercure_Linux_arm64.tar.gz" ;;
    Darwin-x86_64) ASSET="mercure_Darwin_x86_64.tar.gz" ;;
    Darwin-arm64) ASSET="mercure_Darwin_arm64.tar.gz" ;;
    *)
        echo "Unsupported platform: $OS-$ARCH" >&2
        exit 1
        ;;
esac

URL="https://github.com/dunglas/mercure/releases/download/${VERSION}/${ASSET}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

echo "Downloading Mercure ${VERSION} for ${OS}-${ARCH}..."
curl -fsSL "$URL" -o "$TMP_DIR/mercure.tar.gz"
tar -xzf "$TMP_DIR/mercure.tar.gz" -C "$TMP_DIR" mercure
mv "$TMP_DIR/mercure" ./mercure
chmod +x ./mercure

echo "Installed: $(./mercure version)"
