#!/usr/bin/env python3

"""Runs the static checks a package configures."""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from run import inside, locate, settings, split_off_tool_arguments, workspace  # noqa: E402
from slot import configuration_of  # noqa: E402
from target import BUNDLE, Target, resolve  # noqa: E402

DDEV = Path(__file__).resolve().parent.parent
TESTKIT = DDEV.parent

# What a tool is configured by, and the subcommand it is called with. A package without that file
# is not checked by that tool.
TOOLS = {
    "phpstan": ("phpstan.neon", ["analyse"]),
    "deptrac": ("deptrac.yaml", ["analyse"]),
    "phparkitect": ("phparkitect.php", ["check"]),
}

# The checks that need no configuration. They read the application, not the package.
LINT = "lint"

CHECKS = [LINT, *TOOLS]


def configured(target: Target) -> list[str]:
    return [LINT, *(t for t, (config, _) in TOOLS.items() if configuration_of(target, config))]


def in_slot(app: Path, command: list[str], environment: dict[str, str] | None = None) -> int:
    prefix = ["env", *(f"{k}={v}" for k, v in environment.items())] if environment else []

    return subprocess.call(
        ["ddev", "exec", "-d", inside(app), *prefix, "php", "-d", "memory_limit=2G", *command],
        cwd=TESTKIT,
    )


def templates(app: Path, target: Target) -> list[str]:
    """Every directory of templates the application can see, its own and the package's."""
    candidates = ["themes", "templates"]

    if target.kind == BUNDLE:
        candidates.append(f"vendor/{target.package}/templates")

    return [where for where in candidates if (app / where).is_dir()]


def lint(app: Path, target: Target) -> int:
    outcome = in_slot(app, ["bin/console", "lint:container"])
    outcome = in_slot(app, ["bin/console", "lint:yaml", "config"]) or outcome

    for where in templates(app, target):
        outcome = in_slot(
            app, ["bin/console", "lint:twig", where, "--no-debug", "--env=production"],
        ) or outcome

    return outcome


def arguments(tool: str, slot, app: Path, target: Target, rest: list[str]) -> list[str]:
    filename, command = TOOLS[tool]

    if target.kind == BUNDLE:
        # The bundle is a dependency of the application it is tested in, so its configuration and
        # its sources are both under vendor.
        config = f"vendor/{target.package}/{filename}"
    else:
        # The project is the application, and keeps its configuration where it keeps it.
        config = inside(slot.path / configuration_of(target, filename).relative_to(target.root))

    if tool == "phparkitect":
        return [*command, f"--config={config}", *rest]

    if tool == "deptrac":
        return [*command, "-c", config, *rest]

    # Naming the sources is what makes phpstan read a bundle: without them the relative paths in
    # the configuration find nothing and the run reports success over an empty file list. A package
    # that keeps them elsewhere, core among them, says so in its own configuration.
    sources = []

    if target.kind == BUNDLE and (app / "vendor" / target.package / "src").is_dir():
        sources = [f"vendor/{target.package}/src"]

    return [*command, "-c", config, *rest, *sources]


def main() -> int:
    mine, rest = split_off_tool_arguments(sys.argv[1:])

    parser = argparse.ArgumentParser(epilog="anything after -- is passed on to the tool")
    parser.add_argument("target")
    parser.add_argument("--php")
    parser.add_argument("--db")
    parser.add_argument("check", nargs="?",
                        help=f"one of {', '.join(CHECKS)}; all of them by default")
    args = parser.parse_args(mine)

    if args.check and args.check not in CHECKS:
        raise SystemExit(f"unknown check {args.check}; expected one of {', '.join(CHECKS)}")

    roots = [Path(r).expanduser() for r in settings().get("roots") or []]
    wanted = [args.check] if args.check else configured(resolve(locate(args.target, roots)))

    outcome = 0

    # Every check reads the built application: the tools come out of its vendor, and phpstan needs
    # the compiled container. Building one is not running tests, so a slot that already stands for
    # this package is used as it is.
    with workspace(args.target, args.php, args.db) as (slot, app, target):
        for check in wanted:
            print(f"\n--- {check}", flush=True)

            if check == LINT:
                outcome = lint(app, target) or outcome
                continue

            # phpstan caches its verdicts under the system temp directory, which every slot in
            # this container shares. One slot's "class not found" would otherwise be served to
            # another.
            here = inside(app)
            environment = {"TMPDIR": f"{here}/var/phpstan"} if check == "phpstan" else None

            outcome = in_slot(
                app,
                [f"vendor/bin/{check}", *arguments(check, slot, app, target, rest)],
                environment,
            ) or outcome

    return outcome


if __name__ == "__main__":
    raise SystemExit(main())
