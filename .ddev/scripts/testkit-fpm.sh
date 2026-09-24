#!/usr/bin/env bash

# Starts one fpm pool per php version named in testkit.yaml. The pools listen on sockets of their
# own, which the generated apache ports point at. ddev's own pool is untouched and keeps serving
# the project itself.

set -e

for conf in /mnt/ddev_config/fpm/*.conf; do
    [ -e "$conf" ] || continue

    version=$(basename "$conf" .conf)
    binary="/usr/sbin/php-fpm$version"

    [ -x "$binary" ] || continue
    pgrep -f "php-fpm$version.*testkit" >/dev/null 2>&1 && continue

    "$binary" -y "$conf" >/dev/null 2>&1 &
done

exit 0
