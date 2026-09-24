#!/usr/bin/env python3

"""Works out what a path points at.

The package is the nearest composer.json, looked for upwards first and then one or two levels
down from the repository root, which is how a project keeps its app in app/ or backend/app/.
composer's own `type` says whether an app has to be built first or is already there. The test
root is wherever the codeception config sits, which for a project is beside the app rather than
in it. Prints: test root, app directory, kind, name.
"""

import json
import pathlib
import subprocess
import sys

CONFIGS = ("codeception.yml", "codeception.dist.yml")
SKIP = {"vendor", "node_modules", "var", "public", "tests"}


def fail(message: str) -> None:
    print(message, file=sys.stderr)
    raise SystemExit(1)


def repository_root(start: pathlib.Path) -> pathlib.Path:
    try:
        out = subprocess.run(["git", "-C", str(start), "rev-parse", "--show-toplevel"],
                             capture_output=True, text=True, check=True).stdout.strip()
        return pathlib.Path(out)
    except (subprocess.CalledProcessError, FileNotFoundError):
        fail(f"{start} is not inside a git repository, so there is no telling where it ends.")


def read_type(app: pathlib.Path) -> str:
    try:
        return json.loads((app / "composer.json").read_text()).get("type", "")
    except (OSError, ValueError) as error:
        fail(f"{app}/composer.json cannot be read: {error}")


def find_app(start: pathlib.Path, root: pathlib.Path) -> pathlib.Path:
    for directory in (start, *start.parents):
        if (directory / "composer.json").exists():
            return directory
        if directory == root:
            break

    found = [c.parent for c in sorted(root.glob("*/composer.json")) if c.parent.name not in SKIP]
    found += [c.parent for c in sorted(root.glob("*/*/composer.json")) if not SKIP & set(c.parts)]

    if not found:
        fail(f"No composer.json in {root} or below it.")

    if len(found) == 1:
        return found[0]

    projects = [c for c in found if read_type(c) == "project"]

    if len(projects) != 1:
        names = ", ".join(str(c.relative_to(root)) for c in found)
        fail(f"{root} holds several packages ({names}) and not exactly one of them says it is a "
             f"project. Point at the one you mean.")

    return projects[0]


def find_test_root(app: pathlib.Path, root: pathlib.Path) -> pathlib.Path:
    for directory in (app, *app.parents):
        if any((directory / name).exists() for name in CONFIGS):
            return directory
        if directory == root:
            break

    # No test suite here. phpstan and the architecture checks still work.
    return app


start = pathlib.Path(sys.argv[1]).resolve()

if not start.is_dir():
    fail(f"No such directory: {sys.argv[1]}")

root = repository_root(start)
app = find_app(start, root)
kind = "project" if read_type(app) == "project" else "bundle"
test_root = find_test_root(app, root)

print(f"{test_root}\t{app}\t{kind}\t{root.name if kind == 'project' else app.name}")
