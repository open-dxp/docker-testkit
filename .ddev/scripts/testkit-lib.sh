#!/usr/bin/env bash

# A slot is a directory, a database and a port that belong together. They are written into the
# apache config when the container starts, so nothing is configured while a run is going on.

. "$(dirname "${BASH_SOURCE[0]}")/../testkit.env"

TESTKIT_SLOT_FILE=".ddev/slots.tsv"

testkit_locate() {
    local name="$1" root

    for root in $TESTKIT_ROOTS; do
        [ -d "$root/$name" ] && { printf '%s' "$root/$name"; return 0; }
    done

    echo "Error: no directory at '$name', and none by that name in ${TESTKIT_ROOTS:-the roots, which are empty}" >&2
    return 1
}


testkit_port() {
    local slot="$1" version="$2" index=1 v

    for v in $TESTKIT_PHP_VERSIONS; do
        [ "$v" = "$version" ] && break
        index=$((index + 1))
    done

    printf '%d' $((index * 10000 + 21000 + slot))
}

testkit_php_known() {
    local v
    for v in $TESTKIT_PHP_VERSIONS; do
        [ "$v" = "$1" ] && return 0
    done
    echo "Error: php $1 is not offered. testkit.yaml has: $TESTKIT_PHP_VERSIONS" >&2
    return 1
}

testkit_db_host() {
    local pair
    for pair in $TESTKIT_DB_HOSTS; do
        [ "${pair%%:*}" = "$1" ] && { printf '%s' "${pair#*:}"; return 0; }
    done
    echo "Error: database $1 is not offered. testkit.yaml has: ${TESTKIT_DB_HOSTS//:*/}" >&2
    return 1
}

testkit_database() {
    printf 'db_slot_%d' "$1"
}

# A project keeps variables its application needs in its own .ddev/config.yaml, and the testkit is a
# different ddev project that never sees them.
testkit_project_environment() {
    local dir config
    dir=$(cd "$1" && pwd)

    while [ "$dir" != "/" ]; do
        config="$dir/.ddev/config.yaml"
        [ -f "$config" ] && break
        dir=$(dirname "$dir")
    done

    [ -f "$config" ] || return 0

    # Written as KEY='value' with the shell's own escaping, because the file is read back with
    # ". file". A value is a YAML plain scalar unless it is quoted, so a trailing comment belongs to
    # the comment and not to the value.
    awk '
        BEGIN { q = sprintf("%c", 39) }

        /^web_environment:/ { inside = 1; next }
        inside && /^[^[:space:]]/ { inside = 0 }

        inside && /^[[:space:]]*-[[:space:]]*[A-Za-z_][A-Za-z0-9_]*=/ {
            line = $0
            sub(/^[[:space:]]*-[[:space:]]*/, "", line)

            equals = index(line, "=")
            key = substr(line, 1, equals - 1)
            value = substr(line, equals + 1)

            if (value ~ /^".*"$/ || value ~ /^.*$/ && substr(value, 1, 1) == q && substr(value, length(value)) == q) {
                value = substr(value, 2, length(value) - 2)
            } else {
                sub(/[[:space:]]+#.*$/, "", value)
            }

            gsub(q, q "\\" q q, value)
            print key "=" q value q
        }
    ' "$config"
}

testkit_slot_key() {
    awk -F'\t' -v s="$1" '$1 == s { print $2; exit }' "$TESTKIT_SLOT_FILE" 2>/dev/null
}

testkit_slot_used() {
    awk -F'\t' -v s="$1" '$1 == s { print $3; exit }' "$TESTKIT_SLOT_FILE" 2>/dev/null
}

testkit_slot_lock_file() {
    printf '%s/opendxp-tests-%s-slot-%s.lock' "${TMPDIR:-/tmp}" "$(id -u)" "$1"
}

# A lock of its own, held only while a number is picked: two runs starting at the same moment must
# not pick the same slot.
testkit_claim_slot() {
    local bundle="$1" slot="" taken="" n now
    now=$(date +%s)

    touch "$TESTKIT_SLOT_FILE"

    exec 8>"${TMPDIR:-/tmp}/opendxp-slots-$(id -u).lock"
    flock 8

    slot=$(awk -F'\t' -v b="$bundle" '$2 == b { print $1; exit }' "$TESTKIT_SLOT_FILE")

    if [ -z "$slot" ]; then
        taken=$(cut -f1 "$TESTKIT_SLOT_FILE")

        for n in $(seq 1 "$TESTKIT_SLOTS"); do
            if ! printf '%s\n' "$taken" | grep -qx "$n"; then
                slot="$n"
                break
            fi
        done
    fi

    # Oldest first, but skip what is running: evicting a busy slot means waiting for that run to
    # finish and then throwing away an installation that is demonstrably in use.
    if [ -z "$slot" ]; then
        for n in $(sort -t"$(printf '\t')" -k3,3n "$TESTKIT_SLOT_FILE" | cut -f1); do
            if flock -n "$(testkit_slot_lock_file "$n")" true 2>/dev/null; then
                slot="$n"
                break
            fi
        done
    fi

    if [ -z "$slot" ]; then
        slot=$(sort -t"$(printf '\t')" -k3,3n "$TESTKIT_SLOT_FILE" | head -1 | cut -f1)
    fi

    awk -F'\t' -v s="$slot" '$1 != s' "$TESTKIT_SLOT_FILE" > "$TESTKIT_SLOT_FILE.tmp"
    printf '%s\t%s\t%s\n' "$slot" "$bundle" "$now" >> "$TESTKIT_SLOT_FILE.tmp"
    sort -n "$TESTKIT_SLOT_FILE.tmp" > "$TESTKIT_SLOT_FILE"
    rm -f "$TESTKIT_SLOT_FILE.tmp"

    flock -u 8
    exec 8>&-

    printf '%s' "$slot"
}

testkit_slot_changed() {
    local key="$1" app_rel="$2" slot

    slot=$(awk -F'\t' -v k="$key" '$2 == k { print $1; exit }' "$TESTKIT_SLOT_FILE" 2>/dev/null)

    [ -z "$slot" ] || [ ! -d "app/slot-$slot${app_rel:+/$app_rel}/vendor" ]
}

# "db" is ddev's own server, which only answers through ddev. The others are plain containers.
testkit_mysql() {
    local host="$1" sql="$2"

    if [ "$host" = "db" ]; then
        ddev mysql -uroot -proot -e "$sql" >/dev/null 2>&1
        return
    fi

    docker exec "ddev-${DDEV_SITENAME:-$(basename "$PWD")}-$host" \
        mysql -uroot -proot -e "$sql" >/dev/null 2>&1
}

testkit_ensure_database() {
    testkit_mysql "$2" "
        CREATE DATABASE IF NOT EXISTS \`$1\`;
        GRANT ALL ON \`$1\`.* TO 'db'@'%';
        FLUSH PRIVILEGES;
    "
}

testkit_reset_database() {
    testkit_mysql "$2" "DROP DATABASE IF EXISTS \`$1\`;"
    testkit_ensure_database "$1" "$2"
}

# A slot's database lives on whichever server it last ran against, which nothing records, so all of
# them are asked.
testkit_release_slot() {
    local slot="$1" pair

    for pair in $TESTKIT_DB_HOSTS; do
        testkit_mysql "${pair#*:}" "DROP DATABASE IF EXISTS \`$(testkit_database "$slot")\`;"
    done

    rm -rf "app/slot-$slot"

    # Apache serves the slot from this directory and refuses to start without it. A slot above the
    # configured count has no vhost, so it is simply gone.
    [ "$slot" -le "$TESTKIT_SLOTS" ] && mkdir -p "app/slot-$slot/public"

    if [ -f "$TESTKIT_SLOT_FILE" ]; then
        awk -F'\t' -v s="$slot" '$1 != s' "$TESTKIT_SLOT_FILE" > "$TESTKIT_SLOT_FILE.tmp"
        mv "$TESTKIT_SLOT_FILE.tmp" "$TESTKIT_SLOT_FILE"
    fi
}

testkit_slot_stale() {
    local slot="$1"

    [ -n "$(testkit_slot_key "$slot")" ] && return 1
    [ "$slot" -gt "$TESTKIT_SLOTS" ] && return 0
    [ "$(du -sm "app/slot-$slot" | cut -f1)" -gt 1 ]
}

# The exclude list comes from git status, not from rsync's --filter=':- .gitignore'. rsync reads a
# .gitignore per directory and never the ones above the directory being synced, and it applies the
# first matching rule where git applies the last, which inverts every "!" exception.
testkit_sync() {
    local source="$1" slot="$2" keep_vendor="$3" app_rel="$4"
    local target="app/slot-$slot"
    local prefix vendor=()

    case "$source" in ""|/) echo "Error: refusing to sync from '$source'" >&2; return 1 ;; esac

    mkdir -p "$target"
    # vendor and var hold what this slot generated and are not in the source, so --delete would
    # take them every run. Protecting them stops the deletion, not the writing.
    if [ "$keep_vendor" = "true" ]; then
        vendor=(
            --filter="protect /${app_rel:+$app_rel/}vendor/***"
            --filter="protect /${app_rel:+$app_rel/}var/***"
        )
    fi

    prefix=$(git -C "$source" rev-parse --show-prefix)

    git -C "$source" status --porcelain --ignored . \
        | sed -n "s|^!! ${prefix}||p" \
        | rsync -a --info=stats1 --delete \
            --exclude-from=- \
            --filter="protect /tests/_output/***" \
            "${vendor[@]}" \
            "$source/" "$target/"

    # Headless chrome ignores the download directory it is given and writes to its own.
    mkdir -p "$target/tests/_data"
    ln -sfn ../../../../.ddev/downloads "$target/tests/_data/downloads"
}
