# OpenDXP Testkit

Runs the tests of an OpenDXP bundle or project somewhere that is not your working copy.

A test suite installs a database, generates classes, writes caches and rebuilds the app around
itself. You do not want that happening in the directory you are editing. The testkit keeps a copy
of your code in a container, runs the tests there and leaves your checkout alone.

## Requirements

DDEV.

## Getting started

```bash
git clone https://github.com/opendxp/docker-testkit
cd docker-testkit
ddev start
ddev opendxp-tests-run ~/projects/opendxp/skeleton/lib/formbuilder-bundle codecept --composer
```

The first run of anything installs its dependencies and takes a few minutes. Later runs do not.

## Configuring it

`.ddev/testkit.dist.yaml` holds what the testkit offers. Copy any key you want to change into
`.ddev/testkit.yaml`, which is not versioned:

```yaml
roots: []
slots: 5

php:
    versions: ["8.3", "8.4", "8.5"]
    default: "8.4"

databases:
    mysql: "8.4"
    mariadb: "10.7"
    default: mysql
```

Changing it needs one `ddev restart`. The ports, the php-fpm pools and the database containers are
written from this file when the containers come up. A restart also empties the workspaces the runs
live in, so the next run of each package installs again, which Cleaning up goes into.

### What a suite needs beside the application

Some suites talk to something that is not the application: redis, a pdf renderer, a mail catcher.
`services` gives each one a container, and `environment` is handed to the web container where the
tests read it:

```yaml
services:
    redis: redis:7-alpine

environment:
    OPENDXP_TEST_REDIS_DSN: "redis://redis:6379"
```

The service name is its host name inside the network. What a suite expects is in the `env:` block of
its CI workflow, which is also where to look when a test is green there and red here.

### Working on a dependency

If you are editing a package that the thing under test requires, point `paths` at your checkout.
composer links it into `vendor` instead of installing the released version, so your edits are live
in the next run:

```yaml
paths:
    open-dxp/opendxp-codeception-framework: /home/you/projects/opendxp/codeception-framework
```

## Running something

```bash
ddev opendxp-tests-run <path> <task> [args]
```

Give it the directory of a bundle or of a project. It works out for itself which it is looking at,
and where the tests are:

```bash
ddev opendxp-tests-run ~/projects/opendxp/skeleton/lib/formbuilder-bundle codecept tests/Acceptance/ExtJs/SaveFormCest.php
ddev opendxp-tests-run ~/projects/my-shop/backend codecept tests/Functional
ddev opendxp-tests-run ~/projects/my-shop/backend/app/lib/some-bundle phpstan
```

A path always works, and that is the whole interface. `roots` is a shortcut on top of it, for when
you keep bundles together and are tired of typing the same prefix. In `.ddev/testkit.yaml`:

```yaml
roots: ["/home/you/projects/opendxp/skeleton/lib"]
```

```bash
ddev opendxp-tests-run formbuilder-bundle codecept
```

An argument that is an existing directory is used as it stands, whether `roots` is set or not.
Anything else is looked for as `<root>/<name>`, in the order the roots are listed, and the first one
that exists wins. Leave `roots` empty and you give a path every time, which is what you end up doing
anyway once your work spans several places.

A bundle gets an OpenDXP application built around it; a project brings its own.

Tasks are `codecept`, `phpstan`, `deptrac`, `arkitect`, or `all`.

Flags:

|                |                                                   |
|----------------|---------------------------------------------------|
| `--php 8.3`    | run on another php version                        |
| `--db mariadb` | run against another database                      |
| `--composer`   | reinstall dependencies before the run             |
| `--lowest`     | install the lowest versions the constraints allow |
| `--debug`      | pass `--debug` to codeception                     |

Every run prints where its report is:

```
formbuilder-bundle (bundle) -> slot 2 · php 8.4 · mysql · port 41002
...
report: https://opendxp-docker-testkit.ddev.site/analysis/slot-2.html
```

That page shows what each tool found, with the failures and deptrac's layer graph.

## Slots

Five runs can happen at once. Each gets a slot: its own directory, its own database, its own port.
A slot is claimed for one combination of package, php version and database, and kept, so the same
run twice does not reinstall anything. When all five are taken and something new arrives, the slot
idle longest is reused.

Two people, or two agents, working on two bundles never wait for each other. Two runs of the same
thing do, because they would share a database.

All five taken is not an error. The run takes the slot idle longest and installs into it, which
costs that one install. Only when all five have a run in them does anything wait, and then there is
nothing to do but let one finish.

`ddev opendxp-doctor` lists every slot with its size and what holds it.

## Cleaning up

Stopping the testkit empties it. `ddev stop` drops every slot database, removes every slot directory
and clears the reports. What comes back up is a testkit that has never run anything, and the first
run of each bundle installs again.

Nothing is cleaned between runs. That is what makes the second run of the same thing take seconds
instead of ten minutes, and it is why a slot is worth keeping while the testkit is up.

```bash
ddev opendxp-tests-clean formbuilder-bundle
ddev opendxp-tests-clean --all
ddev opendxp-tests-clean
```

Those do the same by hand: one package, everything, or only what nothing holds any more. A slot with
a run in it is skipped and named, the rest are released.

On the way down the rule is stricter, because half an empty testkit still running is worse than
none. One running test and nothing is released and the stop fails:

```
slot 1 is running formbuilder-bundle|php8.4|mysql
Nothing was released.
Failed to stop project opendxp-docker-testkit
```

Wait for the run, or stop it, then stop again.

## What gets copied

Whatever git tracks. Your `.gitignore` decides: `vendor`, caches and logs stay behind, and the
parts of `var/` your repository keeps come along. If a test needs a file that is not committed,
it will fail here — which is what it would do for anyone else who checks out your work.

Nothing is written back. `app/` is scratch space and holds exactly two things: one directory per
slot, and `public/`, which is the docroot ddev serves the reports from.

## Agents

`mcp-server/` is an MCP server for Claude Code, opencode and anything else that speaks MCP. It tells
the agent what the testkit is, runs the tasks and hands back which tests failed and why, instead of a
wall of console output. Its tools are `list_bundles`, `run_tests`, `testkit_status` and
`release_slots`.

`ddev start` installs its dependencies on the first boot, inside the container, so the machine that
runs the agent needs `node` but not `npm`. Started before that has ever happened, the server says so
and exits.

It runs on the host, not in a container, because its job starts on the host: reading a working copy
that can be anywhere, claiming a slot, driving `ddev`. None of that is reachable from inside the web
container.

Nothing discovers this checkout by itself. Whoever registers the server says where it is, and that is
also the switch: a developer who never registers it never sees the tools.

### Claude Code

```bash
claude mcp add --scope user opendxp-testkit -- node /path/to/docker-testkit/mcp-server/index.js
```

User scope means any directory, any project. `claude mcp list` shows whether it connected. This
configuration belongs to one developer already, so there is nothing to switch off for the people who
do not want it.

### opencode

A shared configuration cannot carry a path that differs per machine, and it should not offer the
tools in CI or in the web space. So the server is declared once and switched on from the plugin:

```json
"testkit": {
    "type": "local",
    "command": ["node", "{env:OPENDXP_TESTKIT}/mcp-server/index.js"],
    "disabled": true
}
```

`decideTestkit()` reads `OPENDXP_TESTKIT` and enables the server when it points at a testkit.
Variable unset or path wrong and it stays off. The `CI` and `OPENCODE_SPACE=web` guard that already
covers the other servers covers this one too.

Each developer who wants it sets the variable once:

```bash
export OPENDXP_TESTKIT=/path/to/docker-testkit
```

### What the agent is told

The server sends its own instructions when a client connects, so this does not have to be repeated in
every project. The two that matter most: the working tree has no `vendor` and needs none, so there is
nothing to check before running a task, and `run_tests` returns the failures themselves, so the
report page is for the user and not something to fetch.
