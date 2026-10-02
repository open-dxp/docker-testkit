# OpenDXP Testkit

Runs the tests of an OpenDXP bundle somewhere that is not your working copy.

A test suite installs a database, writes caches and builds an application around itself. You do not
want that happening in the directory you are editing. The testkit builds that application in a
workspace of its own, runs the tests there and leaves your checkout alone.

## Requirements

DDEV.

## Getting started

```bash
git clone https://github.com/opendxp/docker-testkit
cd docker-testkit
```

Tell it where your checkouts are. Copy `.ddev/testkit.dist.yaml` to `.ddev/testkit.yaml` and set
`roots` to the directory your bundles live in:

```yaml
roots: ["/home/you/projects/opendxp/skeleton/lib"]
```

Then start it and run something:

```bash
ddev start
ddev test toolbox-bundle
```

The first run of a bundle installs its dependencies and takes a few minutes. Later runs of the same
bundle take seconds.

## Running something

```bash
ddev test <package> [--php 8.4] [--db mysql] [--fresh] [-- <pest args>]
```

The package is a directory, or the name of one below a root:

```bash
ddev test toolbox-bundle
ddev test /home/you/projects/opendxp/skeleton/lib/toolbox-bundle
```

Anything after `--` goes to pest as it stands:

```bash
ddev test toolbox-bundle -- --filter=Headline
ddev test toolbox-bundle -- tests/Area/GalleryTest.php
ddev test toolbox-bundle -- --exclude-group=browser
```

| flag           |                                             |
|----------------|---------------------------------------------|
| `--php 8.3`    | run on another php version                  |
| `--db mariadb` | run against another database                |
| `--fresh`      | throw the workspace away and build it again |

Every run prints where its report is:

```
report: https://opendxp-docker-testkit.ddev.site/analysis/slot-1.html
```

## The static checks

```bash
ddev analyse <package> [lint|phpstan|deptrac|phparkitect] [-- <tool args>]
```

`lint` always runs: it checks the container, the yaml configuration and the twig templates, and
needs nothing from the package. The other three are decided by the package. A `phpstan.neon` next
to the `composer.json` means phpstan runs, a `deptrac.yaml` means deptrac runs, a `phparkitect.php`
means phparkitect runs. Name one to run only that one.

```bash
ddev analyse toolbox-bundle
ddev analyse content-crafter-bundle deptrac
```

They answer different questions. phpstan finds type errors and impossible code. deptrac checks that
the layers of a package only depend on what they are allowed to. phparkitect checks rules about
classes, their names, where they live and what they may use.

Every check runs in a slot, because the tools come from the application's vendor and phpstan reads
the compiled container as well. A package that configures a tool requires it in its `require-dev`.
The slot is the same workspace `ddev test` uses, so running both costs that build once.

## What a package needs

Three things, all of them in the package itself. The testkit reads them and builds the application
around what it finds.

A `composer.json` that maps a namespace onto `tests/`:

```json
{
    "autoload-dev": {
        "psr-4": {
            "OpenDxp\\Bundle\\ToolboxBundle\\Tests\\": "tests/"
        }
    }
}
```

A `phpunit.xml.dist` beside it. Paths in that file are relative to the application the tests run in,
where the package is a dependency, so the bootstrap is under `vendor`:

```xml
<phpunit bootstrap="vendor/open-dxp/test-foundation/bootstrap.php">
```

And a `tests/TestKernel.php` that says which bundles the application registers. It extends the one
that `open-dxp/test-foundation` ships.

The foundation belongs under `require-dev` like any other test dependency, and the version named
there is the version a run installs:

```json
{
    "require-dev": {
        "open-dxp/test-foundation": "1.x-dev"
    }
}
```

Whatever else the package lists under `require-dev` is installed as well. Composer never installs
the `require-dev` of a dependency, so the testkit does it, because a package under test is being
developed and not consumed.

### Something you want in the tests but not in the package

A bundle can be tested against another bundle it does not require. Name it under `extra`:

```json
{
    "extra": {
        "opendxp-test": {
            "optional": ["open-dxp/content-crafter-bundle"]
        }
    }
}
```

It is installed when it is present in one of your roots, and left out when it is not. Tests that
need it check for the class themselves. This is not `suggest`, which tells a user what they might
want, and not `require-dev`, which every contributor has to be able to install.

## Configuring it

`.ddev/testkit.dist.yaml` holds what the testkit offers. Copy any key you want to change into
`.ddev/testkit.yaml`, which is not versioned. What you do not name there is taken from the dist
file.

```yaml
roots: ["/home/you/projects/opendxp/skeleton/lib"]
slots: 5

paths:
    open-dxp/test-foundation: "/home/you/projects/opendxp/skeleton/lib/test-foundation"

php:
    versions: ["8.3", "8.4", "8.5"]
    default: "8.4"

databases:
    mysql: "8.4"
    mariadb: "10.7"
    default: mysql
```

Changing any of it needs one `ddev restart`. The ports, the php-fpm pools, the database containers
and the mounts are written from this file when the containers come up. A restart also empties every
workspace, so the next run of each package installs again.

### Working on a package your tests depend on

`paths` takes a package from a working copy instead of from the registry, and the runner puts that
working copy into `vendor` on every run, so an edit there is live in the next one.

Name a package here only while you work on that package itself, which in practice means
`open-dxp/test-foundation`. Name nothing and every package is installed at the version the package
under test asks for, which is what someone writing tests wants.

The foundation is the one package a root never supplies. Every test in every repository stands on
it, so which copy a run uses is a decision you write down, not something inferred from where a
checkout happens to sit.

### What a suite needs beside the application

Some suites talk to something that is not the application. `services` gives each one a container,
and `environment` is handed to the web container where the tests read it:

```yaml
services:
    redis: redis:7-alpine

environment:
    OPENDXP_TEST_REDIS_DSN: "redis://redis:6379"
```

The service name is its host name inside the network.

A suite that answers by country reads the GeoLite2 city database. Put `GeoLite2-City.mmdb` in the
testkit directory and every workspace gets it in `var/config`, where the application looks:

```bash
wget https://raw.githubusercontent.com/wp-statistics/GeoLite2-City/master/GeoLite2-City.mmdb.gz -O - \
    | gunzip -c > GeoLite2-City.mmdb
```

## Slots

Five runs can happen at once. Each one gets a slot: its own directory, its own database, its own
port. A slot is claimed for one combination of package, php version and database, and it is kept, so
running the same thing twice installs nothing the second time.

Two people, or two agents, working on two bundles never wait for each other. Two runs of the same
bundle do, because they would share a database.

When all five are taken and something new arrives, the slot that has been idle longest is reused.
That costs one install. Only when all five have a run in them does anything wait.

```bash
ddev slots
```

```
php        8.3, 8.4, 8.5  (default 8.4)
databases  mysql, mariadb  (default mysql)
slots      5

  slot 1    open-dxp/toolbox-bundle|php8.4|mysql
  slot 2    free
```

## Cleaning up

Nothing is cleaned between runs. That is what makes the second run of the same bundle take seconds,
and it is why a slot is worth keeping while the testkit is up.

```bash
ddev release toolbox-bundle
ddev release --all
```

The first empties one slot, the second empties all of them. A slot with a run in it is skipped and
named.

Stopping the testkit empties it. `ddev stop` releases every slot first, and refuses to stop while a
test is running, because half an empty testkit is worse than none:

```
slot 1 is running open-dxp/toolbox-bundle|php8.4|mysql
Nothing was released.
```

Wait for the run to finish, then stop again.

## How your code gets into the workspace

Each root is mounted into the web container at the same absolute path it has on your machine, so
host and container agree on where a checkout lives.

Every directory directly below a root that has a `composer.json` is offered to composer as a path
repository, `open-dxp/test-foundation` excepted. The workspace requires the package under test, so
composer installs your working copy instead of a released version.

Composer builds that copy from an archive, which means `.gitattributes` export-ignore keeps files
out of it and it is only built again once the package has a new commit. The runner therefore puts
your working copy over it on every run, everything git would show, committed or not. An edit is live
in the next run, and a file a release leaves out, `phpstan.neon` among them, is there.

The tests are copied to the top of the workspace instead, because that is where the runner and the
autoloader look for them. Pest names a test after its path below the workspace, and a path outside
it turns every failure into an absolute path from your home directory.

A project is the exception. It ships a lock file and its tests belong against what it ships, so the
checkouts beside it are not offered and it is installed exactly as it is locked. Only a package
named under `paths` is substituted.

Nothing is written back to your checkout. `app/` is scratch space and holds one directory per slot.

## Agents

`mcp-server/` is an MCP server for Claude Code, opencode and anything else that speaks MCP. It runs
the tests and hands back which ones failed and why, instead of a wall of console output. Its tools
are `list_bundles`, `run_tests`, `run_analysis`, `release_slots` and `testkit_status`.

`ddev start` installs its dependencies on the first boot, inside the container. Started before that
has ever happened, the server says so and exits.

It runs on the host, not in a container, because its job starts on the host: reading a working copy
that can be anywhere, claiming a slot, driving `ddev`. None of that is reachable from inside the web
container.

Nothing discovers this checkout by itself. Whoever registers the server says where it is, and that
is also the switch. A developer who never registers it never sees the tools.

### Claude Code

```bash
claude mcp add --scope user opendxp-testkit -- node /path/to/docker-testkit/mcp-server/index.js
```

User scope means any directory, any project. `claude mcp list` shows whether it connected.

### opencode

A shared configuration cannot carry a path that differs per machine, and it should not offer the
tools in CI or in the web space. So the server is declared once and switched on from the plugin:

```json
{
    "testkit": {
        "type": "local",
        "command": ["node", "{env:OPENDXP_TESTKIT}/mcp-server/index.js"],
        "disabled": true
    }
}
```

`decideTestkit()` reads `OPENDXP_TESTKIT` and enables the server when it points at a testkit. If the
variable is unset or the path is wrong, it stays off. The `CI` and `OPENCODE_SPACE=web` guard that
already covers the other servers covers this one too.

Each developer who wants it sets the variable once:

```bash
export OPENDXP_TESTKIT=/path/to/docker-testkit
```

### What the agent is told

The server sends its own instructions when a client connects, so this does not have to be repeated
in every project. The two that matter most: the working tree has no `vendor` and needs none, so
there is nothing to install before running anything, and `run_tests` returns the failures
themselves, so the report page is for the user and not something to fetch.
