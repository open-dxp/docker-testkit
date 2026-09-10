#!/usr/bin/env bash

_OPENDXP_CODECEPTION_FRAMEWORK_PATH='/tmp/opendxp-codeception-framework'
_BUNDLE_PATH=$(ddev exec echo '$TEST_BUNDLE_LOCAL_PATH')
_BUNDLE_DIR_NAME=$(ddev exec echo '$TEST_BUNDLE_DIR_NAME')
_BUNDLE_SYNC_DIR="$_BUNDLE_PATH/$_BUNDLE_DIR_NAME"

sync_bundle_into_app() {
    if [ ! -d "$_BUNDLE_SYNC_DIR" ]; then
        echo "Error: Source directory does not exist: $_BUNDLE_SYNC_DIR" >&2
        return 1
    fi

    if [ ! -d "app" ]; then
        echo "Error: TargetDirectory does not exist: app" >&2
        return 1
    fi

    rsync -a --info=stats1 \
        --delete \
        --exclude="$(basename $_OPENDXP_CODECEPTION_FRAMEWORK_PATH)" \
        --exclude="/vendor" \
        --exclude="/var/cache" \
        --exclude="/var/analysis" \
        --exclude="/.deptrac.cache" \
        --exclude="docs" \
        --include="SKILL.md" \
        --exclude="*.md" \
        --exclude=".git" \
        --exclude=".gitattributes" \
        --exclude=".gitignore" \
        --exclude=".php-cs-fixer-finder.dist.php" \
        "$_BUNDLE_SYNC_DIR/" \
        "app/"
}
