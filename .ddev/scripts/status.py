#!/usr/bin/env python3

"""What this testkit offers and what its slots hold."""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import yaml  # noqa: E402

import slots  # noqa: E402

DDEV = Path(__file__).resolve().parent.parent


def settings() -> dict:
    merged: dict = {}

    for name in ("testkit.dist.yaml", "testkit.yaml"):
        path = DDEV / name
        if path.exists():
            merged.update(yaml.safe_load(path.read_text()) or {})

    return merged


config = settings()
databases = {name: v for name, v in config["databases"].items() if name != "default"}

print(f"php        {', '.join(config['php']['versions'])}  (default {config['php']['default']})")
print(f"databases  {', '.join(databases)}  (default {config['databases']['default']})")
print(f"slots      {config.get('slots', 5)}")
print()

held = slots.book()

for number in range(1, int(config.get("slots", 5)) + 1):
    key = held.get(number)
    mark = "  running" if key and slots.running(number) else ""
    print(f"  slot {number}    {key or 'free'}{mark}")
