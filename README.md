# OpenDXP Testkit

Runs the tests and static checks of an OpenDXP bundle, of OpenDXP core or of an OpenDXP project on
your machine.

A test suite needs an installed OpenDXP application, a database and a compiled container. The
testkit builds that application in a slot of its own and runs Pest or the static checks there. Your
checkout stays as it is. Nothing is installed into it and nothing is written back.

The application is built with the same commands CI uses, so a run here and a run in CI come to the
same result.

## Requirements

- Docker and [DDEV](https://ddev.com)
- git, rsync and bash
- Node.js, only for the MCP server

## Getting started

```bash
git clone https://github.com/open-dxp/docker-testkit
cd docker-testkit
ddev start
```

Then run the tests of a checkout:

```bash
/path/to/docker-testkit/testkit test /path/to/your/bundle
```

The target is always the whole git repository, wherever inside it you start. Without a path, the
testkit takes the current directory. To call it as `testkit` from anywhere, link
it into your PATH:

```bash
ln -s /path/to/docker-testkit/testkit ~/.local/bin/testkit
```

If you prefer not to change your PATH, an alias does the same:

1. Open `~/.bashrc` or `~/.zshrc`.
2. Add `alias testkit=/path/to/docker-testkit/testkit`.
3. Open a new terminal.

Inside the testkit directory, `ddev test`, `ddev analyse`, `ddev release` and `ddev slots` work as well. They need a path.

## Versions

The testkit is not a Composer package. The branch `2.x` is the current version. It installs the test
foundation in the version the package requires.

## Commands

```
testkit test    [path] [--php 8.3] [--db mariadb] [--fresh] [pest options and test paths]
testkit analyse [path] [lint|phpstan|deptrac|phparkitect] [--baseline] [--php 8.3] [--db mariadb]
testkit status
testkit release [path | --all]
```

Everything the testkit does not know goes to Pest as it stands:

```bash
testkit test tests/Feature/Area/ImageTest.php
testkit test --filter=Headline
testkit test --exclude-group=browser
```

A path counts as the target only when it is a checkout, with a `composer.json` or a `.git`.

`analyse` runs `lint` always: the container, the YAML configuration and the Twig templates. It runs
phpstan when the package has a `phpstan.neon`, deptrac when it has a `deptrac.yaml` and phparkitect
when it has a `phparkitect.php`. Name one check to run only that one.

`analyse --baseline` writes the PHPStan baseline beside the package's `phpstan.neon` into your
checkout. It is the only command that writes into a checkout.

`--fresh` builds the slot again from nothing. Use it when a dependency was released and you want it.

Foundry fills the factories with random data and prints the seed at the end of every run. The same
seed repeats a failed run with the same data:

```bash
FOUNDRY_FAKER_SEED=819793 testkit test --filter=Headline
```

## What a package needs

A bundle or project uses `open-dxp/test-foundation`. Its `composer.json` requires the foundation in
`require-dev` and maps a namespace onto `tests/`:

```json
{
    "require-dev": {
        "open-dxp/test-foundation": "^1.0"
    },
    "autoload-dev": {
        "psr-4": {
            "OpenDxp\\Bundle\\ToolboxBundle\\Tests\\": "tests/"
        }
    }
}
```

Its `phpunit.xml.dist` names the bootstrap and the test kernel:

```xml
<phpunit bootstrap="vendor/open-dxp/test-foundation/bootstrap.php">
    <php>
        <env name="KERNEL_CLASS" value="OpenDxp\Bundle\ToolboxBundle\Tests\Application\TestKernel"/>
    </php>
</phpunit>
```

Its `phpstan.neon` names the paths to analyse and reads the container of the test kernel:

```neon
parameters:
    paths:
        - src
    symfony:
        containerXmlPath: %currentWorkingDirectory%/var/cache/test/TestContainerDebug.xml
```

A bundle may name packages its tests use but the bundle does not require. They are installed for
the tests:

```json
{
    "extra": {
        "opendxp-test": {
            "optional": ["open-dxp/content-crafter-bundle"]
        }
    }
}
```

A project that runs on DDEV keeps its local variables under `web_environment` in
`.ddev/config.yaml`. The testkit hands them to every command in the slot. A project without DDEV
puts the variables its tests need into a versioned `.env.test`, which Symfony loads during tests.

`analyse` lints the templates of a project in the environment it is deployed to. That is `prod` or
`production`, whichever the project configures under `config/packages/`, and `prod` otherwise.

The README of `open-dxp/test-foundation` describes how to write the tests.

## Configuration

`.ddev/testkit.dist.yaml` holds the defaults. Put what you want to change into `.ddev/testkit.yaml`,
which is not versioned. Maps are merged key by key, lists are replaced. A change needs
`ddev restart`, which empties every slot.

| Key                 | Meaning                                                                           |
|---------------------|-----------------------------------------------------------------------------------|
| `slots`             | How many runs may happen at the same time. The default is 5.                      |
| `php`               | The PHP versions offered and the default one.                                     |
| `databases`         | The database servers offered and the default one.                                 |
| `registry`          | A composer repository besides packagist, for closed-source packages.              |
| `composer_symlinks` | Packages taken from a checkout on your machine instead of from a registry.        |
| `services`          | Services a suite needs, from the catalogue.                                       |
| `memory`            | The memory limit of the testkit container. All slots share it. The default is 8g. |

### Working on a dependency

`composer_symlinks` maps a package name to a checkout:

```yaml
composer_symlinks:
    open-dxp/test-foundation: /home/you/projects/test-foundation
    open-dxp/opendxp: /home/you/projects/opendxp
```

Every slot that needs such a package links your checkout into `vendor`, and an edit there is live in
the next run. The checkout is mounted read-only. Its `composer.json` has to carry the package name
you give.

A branch has no version a constraint such as `^1.3` would accept. A linked checkout therefore counts
as the major version of its newest tag followed by `.99.99`, for example `1.99.99`.

### Services

The testkit offers the services CI offers, with the same images and variable names:

| Service      | Variable                   |
|--------------|----------------------------|
| `redis`      | `OPENDXP_TEST_REDIS_DSN`   |
| `opensearch` | `OPENDXP_OPEN_SEARCH_HOST` |

```yaml
services: [redis, opensearch]
```

On start, each listed service gets a file `.ddev/docker-compose.<service>.yaml`. If you need a
different setting, edit that file. The testkit then leaves it alone. When you remove the service from
the list, the testkit deletes the file unless you edited it.

## Slots

Each run gets a slot: a directory with its own application and its own database. A slot belongs to
one checkout, one PHP version and one database server, and either to the tests or to the static
checks. The second run of the same combination reuses it and takes seconds.

The static checks have a slot of their own because a test installs the class definitions of its
fixtures for good. In its own slot, the analysis sees only what the installation built, as it does
in CI.

A slot is built again when the `composer.json` of the checkout or of a linked package changes, or
the lock file or class definitions of a project. Everything else is copied into the slot before
every run.

When every slot is taken, the slot that was idle longest is built again for the new run. Two runs of
the same combination wait for each other.

Every test runs in a transaction that is rolled back. A test that writes outside the transaction,
for example by creating a table, leaves that write in the slot for the next run.

```bash
testkit status
testkit release
testkit release --all
```

`ddev stop` releases every slot. It refuses to stop while a run is in a slot.

## Agents

`mcp-server/` is an MCP server for Claude Code and any other client that speaks MCP. Its tools are
`run_tests`, `run_analysis`, `release_slots` and `testkit_status`. Each takes the path of a checkout.

```bash
claude mcp add --scope user opendxp-testkit -- node /path/to/docker-testkit/mcp-server/index.js
```

`ddev start` installs the server's dependencies on the first start.
