#!/usr/bin/env python3

"""Writes what the containers need from testkit.yaml: the apache ports, one fpm pool per php
version and the compose file for the extra database servers. Runs before the containers start,
so nothing has to be configured while they are up."""

import json
import pathlib
import subprocess
import re
import sys

DDEV = pathlib.Path(__file__).resolve().parent.parent


def read_config() -> dict:
    """A small reader for the shape this one file has: no anchors, no nesting beyond two levels."""
    config: dict = {}

    for name in ("testkit.dist.yaml", "testkit.yaml"):
        path = DDEV / name

        if not path.exists():
            continue

        section = None

        for line in path.read_text().splitlines():
            if not line.strip() or line.lstrip().startswith("#"):
                continue

            key, _, value = line.partition(":")
            value = value.split("#")[0].strip()

            if not line.startswith(" "):
                section = key.strip()
                if value:
                    config[section] = json.loads(value) if value.startswith("[") else value.strip('"\'')
                else:
                    config.setdefault(section, {})
            else:
                target = config.setdefault(section, {})
                target[key.strip()] = json.loads(value) if value.startswith("[") else value.strip('"\'')

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

def dev_version_of(location: str) -> str:
    """A checkout carries no release number, so composer names it after the branch it is on. A
    branch that reads like a version becomes "1.x-dev", anything else becomes "dev-my-branch"."""
    try:
        branch = subprocess.run(["git", "-C", location, "branch", "--show-current"],
                                capture_output=True, text=True, check=True).stdout.strip()
    except (subprocess.CalledProcessError, FileNotFoundError):
        branch = ""

    if not branch:
        return "dev-HEAD"

    return f"{branch}-dev" if re.fullmatch(r"v?\d+(\.[\dx]+)*", branch) else f"dev-{branch}"


# A local checkout composer should link into vendor has to be visible in the container first.
paths = config.get("paths", {})
mounts = ["# Written by testkit-generate.py from testkit.yaml. Do not edit.\n", "services:", "    web:", "        volumes:"]

for package, location in paths.items():
    mounts.append(f'            - "{location}:/mnt/testkit-paths/{package}"')

(DDEV / "docker-compose.paths.yaml").write_text(
    "\n".join(mounts) + "\n" if paths else
    "# Written by testkit-generate.py from testkit.yaml. Do not edit.\n# No local paths configured.\n"
)

# The same numbers again, in a shape the shell can read without parsing yaml.
(DDEV / "testkit.env").write_text(
    f"TESTKIT_SLOTS={slots}\n"
    f"TESTKIT_PHP_VERSIONS=\"{' '.join(versions)}\"\n"
    f"TESTKIT_PHP_DEFAULT={config['php']['default']}\n"
    f"TESTKIT_DB_DEFAULT={config['databases']['default']}\n"
    f"TESTKIT_DB_HOSTS=\"{' '.join(f'{n}:{"db" if n == "mysql" else n}' for n in databases)}\"\n"
    f"TESTKIT_PATHS=\"{' '.join(f'{k}={dev_version_of(v)}' for k, v in paths.items())}\"\n"
    f"TESTKIT_ROOTS=\"{' '.join(config.get('roots', []) or [])}\"\n"
)

for slot in range(1, slots + 1):
    (DDEV.parent / "app" / f"slot-{slot}" / "public").mkdir(parents=True, exist_ok=True)

print(f"{slots} slots, php {', '.join(versions)}, databases {', '.join(databases)}", file=sys.stderr)
