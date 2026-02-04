#!/usr/bin/env bash
set -euo pipefail

# Aqto API Wizard dev helper
# - Builds frontend assets
# - Clears Symfony cache
# - Starts PHP built-in server

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

HOST="${HOST:-localhost}"
PORT="${PORT:-8000}"
DOCROOT="${DOCROOT:-public}"

usage() {
  cat <<EOF
Usage: ./DEV_RELOADER.sh [--host <host>] [--port <port>] [--docroot <dir>]

Environment variables (optional):
  HOST, PORT, DOCROOT

Examples:
  ./DEV_RELOADER.sh
  ./DEV_RELOADER.sh --port 8001
  HOST=0.0.0.0 PORT=8000 ./DEV_RELOADER.sh
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help)
      usage
      exit 0
      ;;
    --host)
      HOST="${2:-}"; shift 2
      ;;
    --port)
      PORT="${2:-}"; shift 2
      ;;
    --docroot)
      DOCROOT="${2:-}"; shift 2
      ;;
    *)
      echo "Unknown arg: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

echo "==> Building assets (npm run build)"
npm run build

echo "==> Clearing Symfony cache"
php bin/console cache:clear

echo "==> Starting server: http://${HOST}:${PORT} (docroot: ${DOCROOT})"
echo "    Press Ctrl+C to stop."
php -S "${HOST}:${PORT}" -t "${DOCROOT}"
