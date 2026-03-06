# OpenDXP Testkit

A DDEV-based local test environment for OpenDXP bundles. It rsyncs your bundle into a containerized Symfony/Pimcore application and runs Codeception, PHPStan, and PHP CS Fixer tests against it.

### Support Table

| Branch  | Supported OpenDXP Versions | Supported Symfony Versions |
|---------|----------------------------|----------------------------|
| **1.x** | `1.x`                      | `^7.4`                     |

***

## Prerequisites

- [DDEV](https://ddev.readthedocs.io) v1.23+ installed and running
- A local clone of [opendxp-codeception-framework](https://github.com/opendxp/opendxp-codeception-framework)

## Setup

**1. Clone this repository**

```bash
git clone https://github.com/opendxp/docker-testkit
cd docker-testkit
```

**2. Configure the environment**

```bash
cp .ddev/.env.dist .ddev/.env
```

Edit `.ddev/.env` and set the following variables:

| Variable                                   | Description                                                                                          |
|--------------------------------------------|------------------------------------------------------------------------------------------------------|
| `TEST_BUNDLE_LOCAL_PATH`                   | Absolute path to the **parent directory** containing your bundle (e.g. `/home/you/projects/opendxp`) |
| `TEST_BUNDLE_DIR_NAME`                     | Directory name of the bundle to test (e.g. `formbuilder-bundle`)                                     |
| `OPENDXP_CODECEPTION_FRAMEWORK_LOCAL_PATH` | Absolute path to your local clone of `opendxp-codeception-framework`                                 |
| `GITHUB_PRIVATE_ACCESS_TOKEN`              | *(Optional)* GitHub personal access token — only needed for private package access                   |

**3. Start DDEV**

```bash
ddev start
```

> **Note:** `app/` is a temporary directory that is wiped and recreated on every `ddev start`. Always use `--composer` on the first run after starting or restarting DDEV.

## Running Tests

```bash
# First run, or after ddev start/restart — installs composer dependencies
ddev opendxp-tests-run codecept --composer

# Subsequent runs — bundle files are rsynced automatically, no restart needed
ddev opendxp-tests-run codecept
```

### Flags

| Flag         | Description                                              |
|--------------|----------------------------------------------------------|
| `--composer` | Runs `composer update` inside the container before tests |
| `--debug`    | Passes `--debug` to Codeception                          |
| `--lowest`   | Runs composer with `--prefer-lowest`                     |

## Examples

### Codeception

```bash
# All tests
ddev opendxp-tests-run codecept --debug

# Acceptance test
ddev opendxp-tests-run codecept tests/Acceptance/Form/SimpleFormWithDivLayoutCest.php --debug

# Functional test
ddev opendxp-tests-run codecept tests/Functional/Attributes/FormAttributesCest.php --debug

# Unit test
ddev opendxp-tests-run codecept tests/Unit/Config/ActiveElementsTest.php --debug
```

### PHPStan

```bash
ddev opendxp-tests-run phpstan -l 4
```

### Xdebug

```bash
ddev xdebug on
ddev opendxp-tests-run codecept-xdebug YOUR_IP tests/Acceptance/Form/SimpleFormWithDivLayoutCest.php --debug
```

Set the following path mappings in your IDE (PhpStorm → PHP → Servers):

```
/var/www/public/vendor/opendxp
/var/www/public/vendor/symfony
```

## Additional Test Assets

Place additional static assets in an `assets-test/` folder at the repository root. If the folder exists, a symlink to `app/public/assets-test` is created automatically.

## PhpStorm

Exclude the following directories from indexing:

- `.ddev/`
- `app/`

## AI-assisted Testing (MCP)

This testkit ships with an MCP server (`mcp-server/index.js`) that exposes test tooling to [Claude Code](https://claude.ai/code).
Once connected, Claude can start the environment, run and re-run tests, and fix failures autonomously — without manual terminal interaction.

To connect a bundle to the MCP server, add a `.mcp.json` file in your bundle root:

```json
{
    "mcpServers": {
        "opendxp-testkit": {
            "type": "stdio",
            "command": "node",
            "args": ["/absolute/path/to/docker-testkit/mcp-server/index.js"]
        }
    }
}
```

The MCP server provides the following tools:

| Tool              | Description                                                        |
|-------------------|--------------------------------------------------------------------|
| `get_status`      | Returns the current DDEV status and configured bundle              |
| `set_bundle`      | Sets the active bundle and starts/restarts DDEV if needed          |
| `run_codeception` | Runs Codeception tests (optionally with `--debug` or `--composer`) |
| `run_phpstan`     | Runs PHPStan at a configurable level                               |

For a full walkthrough see: [Testing with AI (Claude)](https://github.com/open-dxp/opendxp/doc/19_Development_Tools_and_Details/50_Testing_with_AI.md)
