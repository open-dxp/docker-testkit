#!/usr/bin/env python3

"""Writes what the containers need from testkit.yaml: the apache ports, one fpm pool per php
version and the compose file for the extra database servers. Runs before the containers start,
so nothing has to be configured while they are up."""

import pathlib
import sys

import yaml

DDEV = pathlib.Path(__file__).resolve().parent.parent


def read_config() -> dict:
    """The dist file says what the testkit offers, testkit.yaml overrides what a developer changed."""
    config: dict = {}

    for name in ("testkit.dist.yaml", "testkit.yaml"):
        path = DDEV / name

        if path.exists():
            config.update(yaml.safe_load(path.read_text()) or {})

    return config


config = read_config()
slots = int(config.get("slots", 5))
versions = list(config["php"]["versions"])
databases = {k: v for k, v in config["databases"].items() if k != "default"}

# One port per version and slot: the leading digit says the php version, the rest the slot.
# Apache learns from the port which fpm pool answers, which is the only way it can serve several
# php versions at once.
apache = ["# Written by testkit-generate.py from testkit.yaml. Do not edit.\n"]
fpm_dir = DDEV / "fpm"
fpm_dir.mkdir(exist_ok=True)

for index, version in enumerate(versions, start=1):
    socket = f"/run/php/testkit-{version}.sock"

    (fpm_dir / f"{version}.conf").write_text(
        f"[global]\n"
        f"pid = /run/php/testkit-{version}.pid\n"
        f"error_log = /proc/self/fd/2\n"
        f"daemonize = no\n\n"
        f"[testkit]\n"
        f"listen = {socket}\n"
        f"listen.mode = 0666\n"
        f"pm = ondemand\n"
        f"pm.max_children = 10\n"
        f"pm.process_idle_timeout = 60s\n"
        f"clear_env = no\n"
        f"catch_workers_output = yes\n"
    )

    for slot in range(1, slots + 1):
        port = index * 10000 + 21000 + slot
        apache.append(
            f"Listen {port}\n"
            f"<VirtualHost *:{port}>\n"
            f"    DocumentRoot /var/www/html/app/slot-{slot}/public\n"
            f"    <Directory \"/var/www/html/app/slot-{slot}/public/\">\n"
            f"        AllowOverride All\n"
            f"        Require all granted\n"
            f"    </Directory>\n"
            f"    <FilesMatch \\.php$>\n"
            f"        SetHandler \"proxy:unix:{socket}|fcgi://localhost\"\n"
            f"    </FilesMatch>\n"
            f"    ErrorLog /dev/stdout\n"
            f"    CustomLog /dev/null combined\n"
            f"</VirtualHost>\n"
        )

(DDEV / "apache" / "testkit-slots.conf").write_text("\n".join(apache))

extra = {name: version for name, version in databases.items() if name != "mysql"}
compose = ["# Written by testkit-generate.py from testkit.yaml. Do not edit.\n", "services:"]

for name, version in extra.items():
    compose.append(
        f"    {name}:\n"
        f"        image: {name}:{version}\n"
        f"        container_name: ddev-${{DDEV_SITENAME}}-{name}\n"
        f"        environment:\n"
        f"            - MYSQL_ROOT_PASSWORD=root\n"
        f"            - MYSQL_USER=db\n"
        f"            - MYSQL_PASSWORD=db\n"
        f"            - MYSQL_DATABASE=db\n"
        f"        labels:\n"
        f"            com.ddev.site-name: ${{DDEV_SITENAME}}\n"
        f"            com.ddev.approot: $DDEV_APPROOT\n"
        f"        volumes:\n"
        f"            - {name}-data:/var/lib/mysql\n"
    )

if extra:
    compose.append("volumes:")
    compose.extend(f"    {name}-data:" for name in extra)

(DDEV / "docker-compose.databases.yaml").write_text("\n".join(compose) + "\n")

# Containers a suite needs beside the application, and the variables it reads to find them. Both go
# to the web container, because that is where the tests run.
services = config.get("services") or {}
environment = config.get("environment") or {}
compose = ["# Written by testkit-generate.py from testkit.yaml. Do not edit.\n", "services:"]

for name, image in services.items():
    compose.append(
        f"    {name}:\n"
        f"        image: {image}\n"
        f"        container_name: ddev-${{DDEV_SITENAME}}-{name}\n"
        f"        labels:\n"
        f"            com.ddev.site-name: ${{DDEV_SITENAME}}\n"
        f"            com.ddev.approot: $DDEV_APPROOT\n"
    )

if environment:
    compose.append("    web:\n        environment:")
    compose.extend(f"            - {key}={value}" for key, value in environment.items())
    compose.append("")

(DDEV / "docker-compose.services.yaml").write_text("\n".join(compose) + "\n")

# Composer writes the path of a path repository into vendor as a symlink target, so host and
# container have to agree on where a checkout lives. Mounting each one onto itself is the only
# arrangement where that holds without translating paths back and forth. The roots hold the
# packages under test, `paths:` names single packages to take from a working copy, and both are
# read from inside the container.
declared = list(dict.fromkeys(
    [pathlib.Path(root) for root in config.get("roots") or []]
    + [pathlib.Path(location) for location in (config.get("paths") or {}).values()]
))

# A checkout named under `paths:` usually lies in a root already. Mounting it a second time
# would nest one bind mount inside another for no gain, so only the outermost ones are kept.
checkouts = [
    str(where) for where in declared
    if not any(other != where and other in where.parents for other in declared)
]
header = "# Written by testkit-generate.py from testkit.yaml. Do not edit.\n"

mounted = ["web"]

(DDEV / "docker-compose.mounts.yaml").write_text(
    header + "\n".join([
        "",
        "services:",
        *(
            line
            for service in mounted
            for line in [f"    {service}:", "        volumes:"]
            + [f'            - "{where}:{where}"' for where in checkouts]
        ),
    ]) + "\n" if checkouts else header + "# Nothing to mount.\n"
)

(DDEV / "docker-compose.roots.yaml").unlink(missing_ok=True)

for slot in range(1, slots + 1):
    (DDEV.parent / "app" / f"slot-{slot}" / "public").mkdir(parents=True, exist_ok=True)

print(f"{slots} slots, php {', '.join(versions)}, databases {', '.join(databases)}", file=sys.stderr)
