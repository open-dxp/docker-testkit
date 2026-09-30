"""Hands out the directory a run works in, and keeps two runs out of the same one."""

from __future__ import annotations

import fcntl
import json
import os
import time
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path

DDEV = Path(__file__).resolve().parent.parent
TESTKIT = DDEV.parent
BOOK = DDEV / "slots.json"
LOCKS = Path(os.environ.get("TMPDIR", "/tmp"))


@dataclass(frozen=True)
class Slot:
    number: int
    path: Path
    database: str

    @property
    def held(self) -> Path:
        return LOCKS / f"opendxp-slot-{os.getuid()}-{self.number}.lock"


def _read() -> dict[str, dict]:
    return json.loads(BOOK.read_text()) if BOOK.exists() else {}


def _write(book: dict[str, dict]) -> None:
    BOOK.write_text(json.dumps(book, indent=4, sort_keys=True) + "\n")


@contextmanager
def _exclusive():
    LOCKS.mkdir(parents=True, exist_ok=True)
    handle = open(LOCKS / f"opendxp-slots-{os.getuid()}.lock", "w")
    try:
        fcntl.flock(handle, fcntl.LOCK_EX)
        yield
    finally:
        fcntl.flock(handle, fcntl.LOCK_UN)
        handle.close()


def _running(number: int) -> bool:
    """A slot whose lock cannot be taken is being used by another run right now."""
    path = LOCKS / f"opendxp-slot-{os.getuid()}-{number}.lock"

    try:
        handle = open(path, "w")
    except OSError:
        return False

    try:
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        return True
    else:
        fcntl.flock(handle, fcntl.LOCK_UN)
        return False
    finally:
        handle.close()


def book() -> dict[int, str]:
    """Which slot holds which key."""
    return {int(number): entry["key"] for number, entry in _read().items()}


def holding(package: str) -> int | None:
    """The slot a package was last built in, when one still holds it."""
    for number, key in book().items():
        if key.startswith(f"{package}|"):
            return number

    return None


def running(number: int) -> bool:
    """Whether a run is working in this slot right now."""
    return _running(number)


def claim(key: str, how_many: int) -> tuple[Slot, bool]:
    """Returns the slot for this key, and whether it held something else before.

    A key keeps the slot it used last, so its vendor directory survives between runs. When
    every slot is taken, the one used longest ago gives way, but never one that a run is
    working in: evicting that means waiting for it to finish and then throwing away an
    installation that is demonstrably in use.
    """
    with _exclusive():
        book = _read()
        mine = next((n for n, entry in book.items() if entry["key"] == key), None)
        replaced = False

        if mine is None:
            free = [str(n) for n in range(1, how_many + 1) if str(n) not in book]

            if free:
                mine = free[0]
            else:
                idle = sorted(
                    (entry["used"], n) for n, entry in book.items() if not _running(int(n))
                )
                mine = idle[0][1] if idle else sorted(book, key=lambda n: book[n]["used"])[0]
                replaced = True

        book[mine] = {"key": key, "used": int(time.time())}
        _write(book)

    number = int(mine)

    return Slot(number, TESTKIT / "app" / f"slot-{number}", f"slot{number}"), replaced


@contextmanager
def held(slot: Slot):
    """Marks the slot as in use for as long as the run lasts."""
    handle = open(slot.held, "w")
    try:
        fcntl.flock(handle, fcntl.LOCK_EX)
        yield
    finally:
        fcntl.flock(handle, fcntl.LOCK_UN)
        handle.close()


def release(number: int) -> None:
    with _exclusive():
        book = _read()
        book.pop(str(number), None)
        _write(book)
