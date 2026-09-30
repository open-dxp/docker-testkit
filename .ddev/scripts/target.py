"""Works out what a path is and where its parts live."""

from __future__ import annotations

import json
from dataclasses import dataclass
from pathlib import Path

BUNDLE = "bundle"
PROJECT = "project"


@dataclass(frozen=True)
class Target:
    kind: str
    """bundle or project."""

    package: str
    """The composer name, open-dxp/toolbox-bundle."""

    root: Path
    """The repository, where a branch is switched."""

    app: Path | None
    """The application. A project brings one, a bundle is given one."""

    tests: Path
    """Where the tests live."""


def _name(manifest: Path) -> str:
    return json.loads(manifest.read_text()).get("name", "")


def resolve(path: Path) -> Target:
    root = path.resolve()

    if not root.is_dir():
        raise SystemExit(f"no directory at {root}")

    # A bundle is a package: its manifest sits at the top and it has no application of its own.
    manifest = root / "composer.json"
    if manifest.is_file() and json.loads(manifest.read_text()).get("type") != "project":
        return Target(BUNDLE, _name(manifest), root, None, root / "tests")

    # A project keeps the application one level down and its tests inside it, the same place a
    # bundle keeps them. Headless puts the application under backend/, standalone at the top.
    for prefix in (Path(), Path("backend")):
        app = root / prefix / "app"
        if (app / "composer.json").is_file():
            return Target(PROJECT, _name(app / "composer.json"), root, app, app / "tests")

    raise SystemExit(f"{root} is neither a bundle nor a project")


if __name__ == "__main__":
    import sys

    t = resolve(Path(sys.argv[1]))
    print(f"{t.kind}\t{t.package}\t{t.root}\t{t.app or ''}\t{t.tests}")
