#!/usr/bin/env python3

"""Runs a package's tests in an application built around it."""

from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
from contextlib import contextmanager
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import yaml  # noqa: E402

import slots  # noqa: E402
from slot import (  # noqa: E402
    FOUNDATION,
    application,
    apply_template,
    dependencies_are_stale,
    local_checkouts,
    substitutions_for,
    sync_tests,
    kernel_class,
    write,
)
from target import PROJECT, resolve  # noqa: E402

DDEV = Path(__file__).resolve().parent.parent
TESTKIT = DDEV.parent
ADMIN = ("admin", "testtesttest")


def settings() -> dict:
    merged: dict = {}

    for name in ("testkit.dist.yaml", "testkit.yaml"):
        path = DDEV / name
        if path.exists():
            merged.update(yaml.safe_load(path.read_text()) or {})

    return merged


def locate(name: str, roots: list[Path]) -> Path:
    if Path(name).is_dir():
        return Path(name)

    for root in roots:
        if (root / name).is_dir():
            return root / name

    raise SystemExit(f"no directory at '{name}', and none by that name in the roots")


def inside(path: Path) -> str:
    return str(Path("/var/www/html") / path.relative_to(TESTKIT))


# Installing a bundle writes configuration, and a console command with APP_DEBUG=1 rebuilds the
# container whenever one of the files it was built from has changed. That is eleven seconds per
# command on a CI runner. Building the slot does not need the debug container, the tests do.
WITHOUT_DEBUG = {"APP_DEBUG": "0"}


def prefixed(environment: dict[str, str] | None) -> list[str]:
    return ["env", *(f"{key}={value}" for key, value in environment.items())] if environment else []


def run(command: list[str], where: Path, environment: dict[str, str] | None = None) -> int:
    return subprocess.call(
        ["ddev", "exec", "-d", inside(where), *prefixed(environment), *command], cwd=TESTKIT,
    )


def must(command: list[str], where: Path, what: str,
         environment: dict[str, str] | None = None) -> None:
    """A step that has to work. Carrying on after it leaves a slot that only looks built."""
    if run(command, where, environment) != 0:
        raise SystemExit(f"{what} failed")


def free_slot(app: Path) -> None:
    """A run stopped on the host leaves its process in the container, holding the database."""
    subprocess.call(
        ["ddev", "exec", "for p in /proc/[0-9]*; do "
         f'[ "$(readlink $p/cwd)" = "{inside(app)}" ] && kill -9 "${{p#/proc/}}"; '
         "done; true"],
        cwd=TESTKIT,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )


def reset_database(name: str, host: str) -> None:
    """Every slot gets a database of its own, emptied before the application is installed into it.

    A slot is reused for another package, and the tables of the one before would still be there.
    Installing over them leaves a schema that belongs to neither, and a column whose type no
    installed bundle registers any more stops the next install.
    """
    subprocess.check_call(
        # No backticks around the name: ddev hands the command to a shell, which would read
        # them as a substitution. Slot names are plain identifiers, so none are needed.
        ["ddev", "exec", "mysql", f"--host={host}", "--user=root", "--password=root",
         "-e", f"DROP DATABASE IF EXISTS {name}; CREATE DATABASE {name}; "
               f"GRANT ALL ON {name}.* TO 'db'@'%';"],
        cwd=TESTKIT,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )


def install_opendxp(app: Path, slot, env: dict[str, str], database: str, host: str) -> None:
    """Gives the slot a schema to test against."""
    # The installer boots through OpenDxp\Bootstrap and never reads the .env beside the
    # application, so the configuration it compiles has to find its values in the environment.
    exported = " ".join(f"{key}='{value}'" for key, value in {**env, **WITHOUT_DEBUG}.items())

    must(["sh", "-c", f"{exported} OPENDXP_PROJECT_ROOT='{inside(app)}'"
          f" vendor/bin/opendxp-install"
          f" --mysql-host-socket={host} --mysql-username=db --mysql-password=db"
          f" --mysql-database={database} --admin-username={ADMIN[0]} --admin-password={ADMIN[1]}"
          f" --skip-database-config --no-interaction"], app, "installing opendxp")

    # The installer already builds the classes and marks the migrations as done. Only the bundles
    # are left, and the classes a bundle brings with it.
    install_bundles(app)
    rebuild_classes(app)

    # phpstan reads the debug container, and only a debug boot writes it.
    must(["bin/console", "cache:warmup", "-q"], app, "warming the test cache")


def rebuild_classes(app: Path) -> None:
    """Turns a project's class definitions into tables and php classes.

    A project versions the definitions under var/classes. Whether it also versions the php classes
    OpenDXP generates from them differs per project, and the tables never travel, so both are made
    here from what the definitions say. This creates tables, and the tests are isolated by a
    transaction that any of that would end, so it happens while the slot is built and never later.
    """
    if not list(app.glob("var/classes/definition_*.php")):
        return

    must(["bin/console", "opendxp:deployment:classes-rebuild", "--create-classes", "-n", "-q"],
         app, "rebuilding the data object classes", WITHOUT_DEBUG)


def install_bundles(app) -> None:
    """Installs the bundles the application registers.

    A bundle that is enabled but not installed creates its tables the first time something asks
    for them. That happens in the middle of a test, and the DDL commits the transaction the tests
    rely on for isolation, so everything after it fails. A project installs its bundles, and so
    does this application.
    """
    listing = subprocess.run(
        ["ddev", "exec", "-d", inside(app), *prefixed(WITHOUT_DEBUG),
         "bin/console", "opendxp:bundle:list", "--json"],
        cwd=TESTKIT, capture_output=True, text=True,
    )

    if listing.returncode != 0:
        raise SystemExit(f"could not read the bundle list:\n{listing.stderr or listing.stdout}")

    for bundle in json.loads(listing.stdout):
        if bundle["Enabled"] and bundle["Installable"] and not bundle["Installed"]:
            must(["bin/console", "opendxp:bundle:install", bundle["Bundle"],
                  "--no-post-change-commands", "-q"], app,
                 f"installing {bundle['Bundle']}", WITHOUT_DEBUG)

    # --no-post-change-commands above: the command otherwise runs assets:install and cache:clear
    # after every single bundle, and the cache:clear rebuilds the container each time.
    must(["bin/console", "assets:install", "public", "-q"], app, "installing the bundle assets",
         WITHOUT_DEBUG)


def report(slot, package: str) -> None:
    """Renders what the run left behind, and says where to look at it."""
    subprocess.call(
        ["ddev", "exec", "php", "/mnt/ddev_config/tools/index.php",
         f"app/slot-{slot.number}", package],
        cwd=TESTKIT,
        stdout=subprocess.DEVNULL,
    )

    site = subprocess.run(
        ["ddev", "describe", "-j"], cwd=TESTKIT, capture_output=True, text=True,
    )

    host = "opendxp-docker-testkit.ddev.site"

    if site.returncode == 0:
        import json

        try:
            host = json.loads(site.stdout)["raw"]["primary_url"].removeprefix("https://")
        except (KeyError, ValueError):
            pass

    print(f"\nreport: https://{host}/analysis/slot-{slot.number}.html")


def declared_environment(root: Path) -> dict[str, str]:
    """What a project tells its own containers, so its application can start in a slot too.

    A project keeps its keys and its write targets in the ddev configuration next to it, not in the
    repository. Its bundles read them while the container is built, so a run without them stops
    before the first test.
    """
    config = root / ".ddev" / "config.yaml"

    if not config.is_file():
        return {}

    declared = yaml.safe_load(config.read_text()) or {}
    environment = {}

    for entry in declared.get("web_environment") or []:
        key, sep, value = str(entry).partition("=")

        if sep:
            environment[key.strip()] = value

    return environment


def install_project(app: Path, local: dict[str, Path]) -> None:
    """Installs a project exactly as it is locked, and substitutes the checkouts named in `paths:`.

    A project ships a lock file and its tests belong against what it ships, so the default is a
    plain install: every package, the test foundation included, comes out at the version the
    project locked.

    The checkouts beside it are deliberately not offered wholesale. A path repository is canonical
    and outranks the registry, so a working copy of a bundle would shadow the version the project
    locked, and a project would stop being tested against what it ships. Naming one under `paths:`
    is how you say you want exactly that, which is what building a project and a bundle at the
    same time needs.

    Its own composer scripts boot its console, which needs an environment that only exists once
    the workspace is installed. What they would do, warming the cache and publishing the assets,
    the testkit does itself right after.
    """
    wanted = substitutions_for(app / "composer.json", local)

    if not wanted:
        must(["composer", "install", "--no-interaction", "--no-progress", "--no-scripts"],
             app, "installing the project")

        return

    for name, (checkout, _) in wanted.items():
        must(["composer", "config", f"repositories.{name.replace('/', '-')}", "path", str(checkout)],
             app, f"offering {name} to composer")

    # Against a working copy neither the version the project asks for nor its stability applies,
    # so these requirements are resolved again. What they bring may move a package the project had
    # locked, which is why their dependencies come along. Everything else keeps the locked version.
    for section in ("require", "require-dev"):
        named = [f"{name}:*@dev" for name, (_, where) in wanted.items() if where == section]

        if not named:
            continue

        # Requiring a package in the other section moves it there, and the project decided that.
        development = ["--dev"] if section == "require-dev" else []

        must(["composer", "require", *development, "--no-interaction", "--no-progress",
              "--no-scripts", "--with-all-dependencies", *named], app, "installing the project")


@contextmanager
def workspace(name: str, php: str | None = None, database: str | None = None, fresh: bool = False):
    """Claims a slot and makes sure an application stands in it, then holds it for the caller.

    Building is not the same as testing. A static check needs the dependencies resolved and the
    container compiled, and nothing beyond that, so it asks for a workspace the same way a test run
    does and finds one already standing when there is one.
    """
    config = settings()
    roots = [Path(r).expanduser() for r in config.get("roots") or []]
    local = local_checkouts(config)
    php = php or config["php"]["default"]
    database = database or config["databases"]["default"]
    host = "db" if database == "mysql" else database

    target = resolve(locate(name, roots))
    slot, replaced = slots.claim(f"{target.package}|php{php}|{database}", int(config.get("slots", 5)))

    with slots.held(slot):
        app = application(slot.path, target)
        # A bundle declares its tests in its own manifest, a project in the application's.
        kernel = kernel_class(target.app or target.root)
        # A build that stopped half way leaves a vendor directory and a lock file behind, which
        # is enough to look finished. Only the stamp says a slot really is, and it is written
        # last, once the application is installed and can be booted.
        built = app / "var" / ".built"
        build = (
            fresh
            or replaced
            or dependencies_are_stale(target.app or target.root, local, built)
        )

        if fresh and slot.path.exists():
            shutil.rmtree(slot.path)

        environment = {
            # What the project tells its own containers comes first, so what the runner decides
            # about the database and the kernel still wins.
            **declared_environment(target.root),
            "APP_ENV": "test",
            "APP_DEBUG": "1",
            "APP_SECRET": "test",
            "KERNEL_CLASS": kernel,
            "OPENDXP_KERNEL_CLASS": kernel,
            "DATABASE_URL": f"mysql://db:db@{host}:3306/{slot.database}",
            "DATABASE_SERVER_VERSION": str(config["databases"][database]),
            "TEST_TIMEZONE": "Europe/Zurich",
            "TEST_DOMAIN": "opendxp-testing.test",
        }

        write(slot.path, target, local, config.get("registry"), environment)

        # Before the build: installing OpenDXP boots the kernel, and the kernel is one of the
        # files that arrives with the tests. A project's tests come with its mirror already.
        if target.kind != PROJECT:
            sync_tests(slot.path, target)

        (app / "var" / "report").mkdir(parents=True, exist_ok=True)

        free_slot(app)

        # The geo database, where an application expects it. A bundle that answers by country reads
        # it from there, and the CI puts it in the same place.
        geo = TESTKIT / "GeoLite2-City.mmdb"

        if geo.is_file():
            (app / "var" / "config").mkdir(parents=True, exist_ok=True)
            (app / "var" / "config" / geo.name).unlink(missing_ok=True)
            # The link is read inside the container, so it has to name the path there.
            (app / "var" / "config" / geo.name).symlink_to(inside(geo))

        if build:
            reset_database(slot.database, host)

            if target.kind == PROJECT:
                install_project(app, local)
            else:
                # A stale lock describes a resolution that no longer holds, so it goes.
                (app / "composer.lock").unlink(missing_ok=True)
                must(["composer", "install", "--no-interaction", "--no-progress"],
                     app, "installing the dependencies")
                apply_template(app, app / "vendor" / FOUNDATION)

            install_opendxp(app, slot, environment, slot.database, host)

            built.touch()

        yield slot, app, target


def split_off_tool_arguments(argv: list[str]) -> tuple[list[str], list[str]]:
    """Everything after a bare `--` belongs to the tool, not to the runner.

    argparse cannot do this itself: a trailing `nargs="*"` swallows the flags meant for the tool
    and then rejects them as unknown. Cutting the list first leaves it an unambiguous one.
    """
    if "--" not in argv:
        return argv, []

    cut = argv.index("--")

    return argv[:cut], argv[cut + 1:]


def main() -> int:
    mine, pest = split_off_tool_arguments(sys.argv[1:])

    parser = argparse.ArgumentParser(epilog="anything after -- is passed on to pest")
    parser.add_argument("target")
    parser.add_argument("--php")
    parser.add_argument("--db")
    parser.add_argument("--fresh", action="store_true", help="build the slot again from nothing")
    args = parser.parse_args(mine)

    with workspace(args.target, args.php, args.db, args.fresh) as (slot, app, target):
        outcome = run(
            ["php", "-d", "memory_limit=3G",
             "vendor/bin/pest", "--log-junit", "var/report/pest.junit.xml", *pest],
            app,
        )

        report(slot, target.package)

        return outcome


if __name__ == "__main__":
    raise SystemExit(main())
