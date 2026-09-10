#!/usr/bin/env bash
# Build the Vue/Vite SPA inside a Linux container FS (the host node_modules is
# Windows-installed and cannot run vite build), then copy dist back to the
# host. Run from the FE directory with:
#   docker run --rm -v D:/HRM/FE:/host node:20-bookworm bash /host/_build.sh > _build.log 2>&1
set -euo pipefail

echo "==> Preparing /build"
rm -rf /build
mkdir -p /build
cp /host/package.json /build/
cp /host/vite.config.js /build/
cp /host/tailwind.config.js /build/
cp /host/postcss.config.js /build/
[ -f /host/package-lock.json ] && cp /host/package-lock.json /build/ || true
cp /host/index.html /build/
cp -r /host/src /build/src
cp -r /host/public /build/public
[ -d /host/shared ] && cp -r /host/shared /build/shared || true

cd /build
echo "==> npm install"
npm install --no-audit --no-fund

echo "==> vite build"
NODE_ENV=production node_modules/.bin/vite build

echo "==> copying dist back to host"
rm -rf /host/dist
cp -r /build/dist /host/dist

echo "==> DONE"
ls -la /host/dist
