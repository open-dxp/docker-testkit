# OpenDXP Testkit — Claude Agent Guide

This directory is the global DDEV test environment for all OpenDXP bundles.
The MCP server in `mcp-server/` provides Claude agents in bundle directories with the necessary tools.

---

## Architecture

```
docker-testkit/          ← this directory
├── mcp-server/
│   └── index.js         ← MCP server (loaded by Claude in a bundle session)
├── .ddev/
│   └── .env             ← TEST_BUNDLE_DIR_NAME determines which bundle is being tested
└── app/                 ← temporary directory, rsynced from the bundle on every test run
```

Code is always written in the **bundle directory** — `app/` is a temporary sync target only.
Tests are executed via `ddev opendxp-tests-run` from this testkit directory.

---

## MCP Tools

### `get_status`
Returns the current ddev status and the configured bundle.

### `set_bundle(bundle_name)`
- Writes `TEST_BUNDLE_DIR_NAME` to `.ddev/.env`
- Starts ddev if not running, restarts if the bundle changed
- Must be called before the first test run of a session when needed

### `run_codeception(test_path?, debug?, with_composer?)`
- `test_path` — optional: path to a specific test (e.g. `tests/Acceptance/Form/SimpleFormCest.php`)
- `debug` — passes `--debug` to codecept (default: `false`)
- `with_composer` — runs composer install before tests (default: `false`)
- **`with_composer: true` is required after every `ddev start` / `ddev restart`**

### `run_phpstan(level?)`
- `level` — PHPStan level 0–9 (default: `4`)

---

## .ddev/.env (reference)

```
TEST_BUNDLE_LOCAL_PATH=/home/user/Projects/opendxp.io/skeleton/lib
OPENDXP_CODECEPTION_FRAMEWORK_LOCAL_PATH=/home/user/Projects/opendxp.io/skeleton/lib/opendxp-codeception-framework
TEST_BUNDLE_DIR_NAME=ecommerce-bundle
```

`set_bundle` only modifies `TEST_BUNDLE_DIR_NAME` — all other variables are left untouched.

---

## Bundle directory structure (reference)

```
my-bundle/
├── .mcp.json              ← gitignored — points to this MCP server
├── CLAUDE.md              ← agent guide for the bundle (copied from bundle-CLAUDE.md.dist)
├── src/
└── tests/
    ├── Acceptance/
    ├── Functional/
    ├── Unit/
    └── output/            ← gitignored — latest_codeception_response.txt written here
```