#!/bin/sh
set -eu

cd "$(dirname "$0")/../.."
PHP55_BIN=${PHP55_BIN:-php}
count=0
for file in $(find src public bin -type f -name '*.php'); do
    "$PHP55_BIN" -l "$file" >/dev/null
    count=$((count + 1))
done
"$PHP55_BIN" -l bootstrap.php >/dev/null
count=$((count + 1))
printf 'Linted %s production PHP files\n' "$count"
