#!/usr/bin/env sh
set -eu

RAW_FILE="${1:-coverage-php.txt}"

if [ ! -f "$RAW_FILE" ]; then
  echo "ERROR: coverage output file not found: $RAW_FILE" >&2
  exit 1
fi

VALUE="$(
  sed 's/\x1B\[[0-9;]*[A-Za-z]//g' "$RAW_FILE" \
    | awk '/^[[:space:]]*Lines:[[:space:]]+/ { gsub(/%/, "", $2); print $2; exit }'
)"

if [ -z "${VALUE:-}" ]; then
  echo "ERROR: Could not extract PHP Lines coverage percentage from ${RAW_FILE}" >&2
  exit 1
fi

echo "Global PHP coverage (Lines): ${VALUE}%"

MIN="${COVERAGE_MIN:-99}"
awk -v v="$VALUE" -v m="$MIN" 'BEGIN { if (v + 0 < m + 0) exit 1 }' || {
  echo "ERROR: PHP Lines coverage ${VALUE}% is below ${MIN}% (REQ-TEST-003)" >&2
  exit 1
}
