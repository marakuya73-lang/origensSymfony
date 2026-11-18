#!/usr/bin/env bash
# Shared paths for origens-pull and origens-backup.
# The database and the photos live outside the git checkout.

set -euo pipefail

PROJECT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
PHP_BIN=${PHP_BIN:-php}
DATA_ROOT=${ORIGENS_DATA_ROOT:-"$(dirname "$PROJECT")/origens-data"}
DATA_ROOT=$(cd "$(dirname "$DATA_ROOT")" && pwd)/$(basename "$DATA_ROOT")

case "$DATA_ROOT/" in
    "$PROJECT/"*)
        echo "origens: ORIGENS_DATA_ROOT must be outside the project ($PROJECT)." >&2
        exit 1
        ;;
esac

mkdir -p "$DATA_ROOT/backups" "$DATA_ROOT/uploads/artefacts"

db_from_url() {
    local url=$1
    case "$url" in
        sqlite:////*)
            printf '/%s\n' "${url#sqlite:////}"
            ;;
        sqlite:///*)
            printf '%s/%s\n' "$PROJECT" "${url#sqlite:///}"
            ;;
        *)
            echo "origens: unsupported DATABASE_URL ($url)." >&2
            exit 1
            ;;
    esac
}

current_db() {
    local line url
    line=$(grep -E '^DATABASE_URL=' "$PROJECT/.env.local" 2>/dev/null | tail -1 || true)
    if [ -n "$line" ]; then
        url=${line#DATABASE_URL=}
        url=${url#\"}
        url=${url%\"}
        db_from_url "$url"
        return
    fi
    printf '%s\n' "$PROJECT/var/data_prod.db"
}

backup_db() {
    local source=$1
    local stamp backup
    if [ ! -f "$source" ]; then
        echo "origens: no database at $source yet; nothing to back up."
        return 0
    fi
    stamp=$(date -u +%Y%m%dT%H%M%SZ)
    backup="$DATA_ROOT/backups/origens-$stamp.sqlite"
    if command -v sqlite3 >/dev/null 2>&1; then
        sqlite3 "$source" ".backup '$backup'"
    else
        cp -a "$source" "$backup"
    fi
    if [ ! -s "$backup" ]; then
        echo "origens: backup failed ($backup). Stopped; the database was not changed." >&2
        exit 1
    fi
    echo "origens: backup $backup"
    mapfile -t old < <(ls -1t "$DATA_ROOT/backups"/origens-*.sqlite 2>/dev/null || true)
    if [ "${#old[@]}" -gt 10 ]; then
        rm -f "${old[@]:10}"
    fi
}
