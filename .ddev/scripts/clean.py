#!/usr/bin/env python3

"""Empties slots: their directory, their database and their entry in the book."""

from __future__ import annotations

import argparse
import shutil
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import slots  # noqa: E402

TESTKIT = Path(__file__).resolve().parent.parent.parent


def drop_database(name: str) -> None:
    subprocess.call(
        ["ddev", "exec", "mysql", "-uroot", "-proot", "-e", f"DROP DATABASE IF EXISTS {name};"],
        cwd=TESTKIT,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )


def empty(number: int) -> None:
    path = TESTKIT / "app" / f"slot-{number}"

    if path.exists():
        shutil.rmtree(path)

    drop_database(f"slot{number}")
    slots.release(number)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("target", nargs="?", help="a package name, or --all for every slot")
    parser.add_argument("--all", action="store_true")
    parser.add_argument("--fail-if-busy", action="store_true")
    args = parser.parse_args()

    wanted = [
        number
        for number, key in slots.book().items()
        if args.all or (args.target and key.startswith(f"{args.target}|"))
    ]

    if args.fail_if_busy:
        book = slots.book()
        busy = [n for n in wanted if slots.running(n)]

        if busy:
            for number in busy:
                print(f"slot {number} is running {book[number]}", file=sys.stderr)

            print("Nothing was released.", file=sys.stderr)

            return 1

    for number in wanted:
        print(f"emptying slot {number}")
        empty(number)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
