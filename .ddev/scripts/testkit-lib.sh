#!/usr/bin/env bash

# A workspace is a directory, a database and a port that belong together and never change:
# slot 7 is always app/slot-7, db_slot_7 and port 31007. They are declared in
# .ddev/apache/testkit-slots.conf and read when the container starts, so nothing has to be
# configured while a run is going on. What moves is only which bundle sits in which slot.

TESTKIT_SLOTS=10
TESTKIT_SLOT_FILE=".ddev/slots.tsv"

testkit_env() {
    ddev exec printenv "$1" 2>/dev/null | tr -d '\r'
}

testkit_bundle_root() {
    testkit_env TEST_BUNDLE_LOCAL_PATH
}

testkit_bundle_dir() {
    printf '%s/%s' "$(testkit_bundle_root)" "$1"
}

testkit_port() {
    printf '%d' $((31000 + $1))
}

testkit_database() {
    printf 'db_slot_%d' "$1"
}

testkit_require_bundle() {
    local bundle="$1" command="$2"

    if [ -z "$bundle" ]; then
        echo "Error: no bundle given." >&2
        echo "Usage: ddev $command <bundle> ..." >&2
        echo "Available:" >&2
        ls -1 "$(testkit_bundle_root)" 2>/dev/null | sed 's/^/  /' >&2
        return 1
    fi

    if [ ! -d "$(testkit_bundle_dir "$bundle")" ]; then
        echo "Error: no such bundle: $(testkit_bundle_dir "$bundle")" >&2
        return 1
    fi
}

# A bundle keeps the slot it had, so its vendor directory is still there next time. Only a bundle
# that has never run takes a free slot, and only when none is free does the one idle longest go.
# Held under a lock of its own: this is short, and two bundles starting at once must not pick the
# same number.
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

# Whether the slot held a different bundle before. Its vendor directory belongs to that one and
# has to go with it.
testkit_slot_changed() {
    [ ! -f "app/slot-$1/composer.json" ] && return 0
    [ "$(cut -f2 <<<"$(awk -F'\t' -v s="$1" '$1 == s' "$TESTKIT_SLOT_FILE")")" != "$2" ]
}

testkit_ensure_database() {
    ddev mysql -uroot -proot -e "
        CREATE DATABASE IF NOT EXISTS \`$1\`;
        GRANT ALL ON \`$1\`.* TO 'db'@'%';
        FLUSH PRIVILEGES;
    " >/dev/null 2>&1
}

# Only what the bundle is. vendor stays unless the slot changed hands, which is what makes a
# second run on the same bundle quick.
testkit_sync_bundle() {
    local bundle="$1" slot="$2" keep_vendor="$3" target source
    source="$(testkit_bundle_dir "$bundle")"
    target="app/slot-$slot"

    mkdir -p "$target"

    local vendor=()
    [ "$keep_vendor" = "true" ] && vendor=(--exclude="/vendor")

    rsync -a --info=stats1 \
        --delete \
        "${vendor[@]}" \
        --exclude="opendxp-codeception-framework" \
        --exclude="/var" \
        --exclude="/tests/_data/downloads" \
        --exclude="/.deptrac.cache" \
        --exclude="docs" \
        --include="SKILL.md" \
        --exclude="*.md" \
        --exclude=".git" \
        --exclude=".gitattributes" \
        --exclude=".gitignore" \
        --exclude=".php-cs-fixer-finder.dist.php" \
        "$source/" \
        "$target/"

    ln -sfn "$(testkit_env OPENDXP_CODECEPTION_FRAMEWORK_LOCAL_PATH)" "$target/opendxp-codeception-framework"

    # Headless chrome writes to its own download directory whatever it is told, and there is one
    # of those per browser, not per slot. Every slot reads the same one through this link.
    mkdir -p "$target/tests/_data"
    ln -sfn ../../../../.ddev/downloads "$target/tests/_data/downloads"

    # So a human can see which slot holds what.
    ln -sfn "slot-$slot" "app/$bundle"
}
