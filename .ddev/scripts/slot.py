"""Builds a slot: a throwaway application with the package under test installed into it."""

from __future__ import annotations

import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

from target import BUNDLE, PROJECT, Target

FOUNDATION = "open-dxp/test-foundation"


def local_checkouts(config: dict) -> dict[str, Path]:
    """Packages to take from a working copy instead of from the registry, named under `paths:`.

    Nothing declared means nothing is substituted, and a package is installed at the version its
    own manifest asks for. Someone writing tests wants that. Someone working on the foundation
    itself names it here and gets their checkout linked into vendor.
    """
    declared = {}

    for name, location in (config.get("paths") or {}).items():
        checkout = Path(location).expanduser()

        if not (checkout / "composer.json").is_file():
            raise SystemExit(f"paths: {name} points at {checkout}, which holds no composer.json")

        declared[name] = checkout

    return declared


def substitutions_for(manifest: Path, local: dict[str, Path]) -> dict[str, tuple[Path, str]]:
    """The declared checkouts an application actually depends on, and where it declares them.

    A `paths:` entry is configuration for the whole testkit and may name a package that belongs to
    something else entirely, so one this application never requires is passed over. The section it
    sits in is carried along, because requiring a package again in the other one moves it there.
    """
    declared = json.loads(manifest.read_text())
    found = {}

    for name, checkout in local.items():
        for section in ("require", "require-dev"):
            if name in declared.get(section, {}):
                found[name] = (checkout, section)
                break

    return found


def repository_of(checkout: Path) -> dict:
    return {"type": "path", "url": str(checkout), "options": {"symlink": True}}


def foundation_constraint(package_manifest: Path) -> str:
    """What a package asks of the foundation, so a run installs the version it declares."""
    declared = json.loads(package_manifest.read_text())

    for section in ("require-dev", "require"):
        if FOUNDATION in declared.get(section, {}):
            return declared[section][FOUNDATION]

    raise SystemExit(f"{package_manifest} does not require {FOUNDATION}")


def configuration_of(target: Target, filename: str) -> Path | None:
    """Where a tool keeps its configuration in this repository.

    A bundle keeps it at the top, beside the composer.json, because the package is the repository.
    A project is a repository with an application inside it, and the configuration sits beside that
    application or above it, so the search starts there and walks up.
    """
    if target.kind == BUNDLE:
        here = target.root
    else:
        here = target.app

    while True:
        if (here / filename).is_file():
            return here / filename

        if here == target.root:
            return None

        here = here.parent


def tests_namespace(package_manifest: Path) -> str:
    """The namespace a package maps onto its tests directory, read from autoload-dev."""
    autoload = json.loads(package_manifest.read_text()).get("autoload-dev", {}).get("psr-4", {})

    for prefix, where in autoload.items():
        if prefix and Path(where).name == "tests":
            return prefix

    raise SystemExit(
        f"{package_manifest} declares no autoload-dev psr-4 entry for tests/. "
        "Add one, for example \"OpenDxp\\\\Bundle\\\\ToolboxBundle\\\\Tests\\\\\": \"tests/\"."
    )


def kernel_class(package: Path) -> str:
    """The kernel a package's tests boot, read from the phpunit configuration it ships."""
    for name in ("phpunit.xml", "phpunit.xml.dist"):
        configuration = package / name

        if not configuration.is_file():
            continue

        found = re.search(r'name="KERNEL_CLASS" value="([^"]+)"', configuration.read_text())

        if found:
            return found.group(1)

    raise SystemExit(
        f"{package} names no KERNEL_CLASS. Add it to the <php> section of phpunit.xml.dist, "
        'for example <env name="KERNEL_CLASS" value="OpenDxp\\Bundle\\ToolboxBundle'
        '\\Tests\\Application\\TestKernel"/>.'
    )


def optional_of(package_manifest: Path) -> list[str]:
    """Packages a package's tests can exercise but must never require.

    `require-dev` would make them a condition for contributing, and `suggest` speaks to the
    people who install the package, not to its test application. Composer has no key for
    "add this to my test application when it is there", so it goes under `extra`, which is
    where composer expects a tool to keep what only that tool reads.
    """
    extra = json.loads(package_manifest.read_text()).get("extra", {})
    wanted = extra.get("opendxp-test", {}).get("optional", [])

    return list(wanted)


def require_dev_of(package_manifest: Path) -> list[str]:
    dev = json.loads(package_manifest.read_text()).get("require-dev", {})
    return [name for name in dev if name != FOUNDATION and "/" in name]


def dependencies_are_stale(target: Path, local: dict[str, Path], built: Path) -> bool:
    """Whether the installed dependencies still match what the packages ask for.

    The package under test and every checkout named under `paths:` are path repositories, so their
    requirements can change on disk while the slot stays as it was. Composer cannot see that, so a
    manifest newer than the last build means the slot has to resolve again. The stamp is the reference and not the slot's lock file: the
    build writes the slot's own manifest, which would otherwise always look newer than the lock.
    """
    if not built.is_file():
        return True

    watched = [checkout / "composer.json" for checkout in [target, *local.values()]]

    return max(m.stat().st_mtime for m in watched) > built.stat().st_mtime


# What a run writes into the slot and what no repository tracks. public belongs here because the
# bundles install their images and scripts into it, and the admin answers with an error when one
# of them is missing.
GENERATED = {"vendor", "var", "public", "node_modules", ".git"}


def application(slot: Path, target: Target) -> Path:
    """Where the application sits inside the slot.

    A bundle has none and is given one at the top of the slot. A project brings its own and keeps
    it where it keeps it, so its own paths, its configuration and its autoloading all still hold.
    """
    return slot if target.kind == BUNDLE else slot / target.app.relative_to(target.root)


def mirror(root: Path, slot: Path) -> None:
    """Makes the slot hold the working copy, beside what a run generates.

    Everything git would show is copied, committed or not, because a test run is what a developer
    reaches for while the work is still open. What git ignores stays behind: those are one machine's
    artefacts, and a slot builds its own.
    """
    listing = subprocess.run(
        ["git", "-C", str(root), "ls-files", "-z", "--cached", "--others", "--exclude-standard"],
        capture_output=True, text=True, check=True,
    )
    tracked = {name for name in listing.stdout.split("\0") if name}

    for name in tracked:
        source, here = root / name, slot / name

        if not source.is_file():
            continue

        here.parent.mkdir(parents=True, exist_ok=True)

        # copy2 and not copyfile: the slot keeps the times of the working copy, and the build
        # decides whether it has to run again by comparing them.
        if not here.exists() or source.stat().st_mtime > here.stat().st_mtime:
            shutil.copy2(source, here)

    for here in slot.rglob("*"):
        if not here.is_file():
            continue

        name = here.relative_to(slot)

        if str(name) in tracked or set(name.parts) & GENERATED:
            continue

        here.unlink()


def write(slot: Path, target: Target, local: dict[str, Path], registry: str | None,
          env: dict[str, str]) -> None:
    slot.mkdir(parents=True, exist_ok=True)

    # A project needs nothing written: it is the application, and the mirror puts all of it here.
    # What the testkit adds beside it is installed, not written, so its own lock file keeps holding.
    if target.kind == PROJECT:
        mirror(target.root, slot)

        # What differs per run lives here, the same as for a bundle. A project keeps its own .env
        # out of the repository because it holds keys, and a run has no business with those:
        # anything else a test needs, the project states in a versioned .env.test.
        (application(slot, target) / ".env").write_text(
            "".join(f"{key}={value}\n" for key, value in env.items())
        )

        return

    (slot / "var" / "cache").mkdir(parents=True, exist_ok=True)
    (slot / "var" / "log").mkdir(parents=True, exist_ok=True)

    # The docroot. The bundles install their images and scripts into it, and a run that starts
    # from nothing has to create it before anything asks for it.
    (slot / "public").mkdir(exist_ok=True)

    # The package under test comes from your working copy, that is what a run is for. Everything
    # else comes from the registry, unless you named it under `paths:`. The path repositories come
    # first, because composer takes the first repository that can answer.
    repositories = [
        repository_of(where) for where in dict.fromkeys([target.root, *local.values()])
    ]

    if registry:
        repositories.append({"type": "composer", "url": registry})

    # A declared checkout is a path repository, which outranks the registry whatever version is
    # asked for. Without one the package under test decides, the same as anywhere else.
    foundation = (
        "*" if FOUNDATION in local or target.package == FOUNDATION
        else foundation_constraint(target.root / "composer.json")
    )

    # The foundation has tests of its own, and then it is the package under test rather than
    # something installed beside it.
    required = {} if target.package == FOUNDATION else {FOUNDATION: foundation}

    manifest = json.dumps({
        "name": "open-dxp/test-slot",
        "type": "project",
        "description": f"Throwaway application for {target.package}.",
        "repositories": repositories,
        # The slot exists to test this package, so it installs what the package needs to be
        # developed as well. Composer never does that for a dependency.
        "require": {
            target.package: "*",
            **required,
            **{name: "*" for name in require_dev_of(target.root / "composer.json")},
            **{name: "*" for name in optional_of(target.root / "composer.json")},
        },
        "autoload": {
            # Where OpenDXP writes the php classes it generates from a class definition. Every
            # application declares this, and without it a data object cannot be loaded.
            "psr-4": {"OpenDxp\\Model\\DataObject\\": "var/classes/DataObject"},
        },
        "autoload-dev": {
            "psr-4": {tests_namespace(target.root / "composer.json"): "tests/"},
        },
        "config": {
            "allow-plugins": {
                "open-dxp/*": True,
                "php-http/discovery": True,
                "symfony/flex": True,
                "symfony/runtime": True,
                "pestphp/pest-plugin": True,
            },
            "sort-packages": True,
        },
        "minimum-stability": "dev",
        "prefer-stable": True,
    }, indent=4) + "\n"

    # Only when it actually changed, so its timestamp means something to the staleness check.
    here = slot / "composer.json"

    if not here.is_file() or here.read_text() != manifest:
        here.write_text(manifest)

    (slot / ".env").write_text("".join(f"{k}={v}\n" for k, v in env.items()))

    # The package describes its own test suite, versioned, the same way every Symfony bundle
    # does. The slot only supplies what differs per run, and that lives in .env.
    config = target.root / "phpunit.xml.dist"

    if not config.is_file():
        raise SystemExit(f"{target.root.name} has no phpunit.xml.dist")

    shutil.copyfile(config, slot / "phpunit.xml")


def apply_template(slot: Path, foundation: Path) -> None:
    """Puts the foundation's application template into the slot, once its dependencies are there.

    The template is the whole application a bundle is tested in, not only its configuration:
    bin/console is part of it and the installer needs it to finish. It arrives with the package,
    so it can only be copied after composer has resolved where that package comes from.
    """
    declared = json.loads((foundation / "composer.json").read_text()).get("extra", {})
    template = declared.get("app-template")

    if not template:
        raise SystemExit(f"{FOUNDATION} declares no extra.app-template")

    if not (foundation / template).is_dir():
        raise SystemExit(
            f"{FOUNDATION} was installed without its {template}/ directory, so there is no "
            f"application to build. The installed copy is at {foundation}."
        )

    for part in sorted((foundation / template).iterdir()):
        here = slot / part.name

        if part.is_dir():
            if here.exists():
                shutil.rmtree(here)
            shutil.copytree(part, here)
        else:
            shutil.copyfile(part, here)
            here.chmod(part.stat().st_mode)


def sync_tests(slot: Path, target: Target) -> None:
    """Copies the package's tests into the slot, fresh, on every run.

    Pest names a test after its path below the slot, and a symlinked package resolves outside
    it, which turns every failure line into an absolute path. Copying also means a run always
    sees the tests as they are now, without reinstalling anything.

    `_legacy` stays behind: what is still written for the old runner is not run, and when the
    directory is gone from a package, that package is migrated.
    """
    here = slot / "tests"
    before = built_from(here)

    if here.exists():
        shutil.rmtree(here)

    shutil.copytree(
        target.tests,
        here,
        ignore=shutil.ignore_patterns("_legacy"),
        symlinks=True,
    )

    # A kernel booted without the debug flag never asks whether its container is still current, so
    # it would keep answering from the configuration of the run before. Only the files the
    # container is built from matter here, and they rarely change.
    if built_from(here) != before:
        cache = slot / "var" / "cache"

        if cache.is_dir():
            shutil.rmtree(cache)


def built_from(tests: Path) -> dict[str, float]:
    """What the application is built from, and when each of it was last written."""
    if not tests.is_dir():
        return {}

    return {
        str(file.relative_to(tests)): file.stat().st_mtime
        for pattern in ("*.yaml", "*.yml", "*.php", "*.twig")
        for file in tests.rglob(pattern)
    }

