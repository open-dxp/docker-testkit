#!/usr/bin/env node

import {spawnSync} from 'child_process';
import path from 'path';
import {fileURLToPath} from 'url';

let Server, StdioServerTransport, CallToolRequestSchema, ListToolsRequestSchema;

try {
    ({Server} = await import('@modelcontextprotocol/sdk/server/index.js'));
    ({StdioServerTransport} = await import('@modelcontextprotocol/sdk/server/stdio.js'));
    ({CallToolRequestSchema, ListToolsRequestSchema} = await import('@modelcontextprotocol/sdk/types.js'));
} catch {
    process.stderr.write('opendxp-testkit: dependencies are missing. Run ddev start in the testkit once.\n');
    process.exit(1);
}

const TESTKIT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'testkit');
const ONE_RUN = 45 * 60 * 1000;

const INSTRUCTIONS = `
The OpenDXP testkit runs the tests and static checks of a bundle, of core or of a project against a
real OpenDXP installation.

The working tree stays as it is. The testkit copies it into a slot of its own, builds an
application around it and runs Pest or the checks there. A working tree therefore has no vendor
directory and needs none. Do not look for one, do not run composer, pest or phpstan on the host.

Every tool takes the absolute path of the checkout to run. That is usually your working directory.

The first run of a checkout with a php version and a database builds its slot and takes minutes.
Later runs take seconds, until its composer.json changes. Run the narrowest thing that answers the
question: one file, one filter.

Every test runs in a transaction that is rolled back. A test that writes outside the transaction
leaves that write in the slot for the next run.
`.trim();

const location = {type: 'string', description: 'The absolute path of the bundle, core or project checkout.'};
const php = {type: 'string', description: 'A php version the testkit offers, e.g. "8.3". Leave out for the default.'};
const database = {type: 'string', description: 'A database server the testkit offers, e.g. "mariadb". Leave out for the default.'};

const TOOLS = [
    {
        name: 'run_tests',
        description: 'Runs the Pest tests of a checkout. Returns how many tests ran, which failed and why.',
        inputSchema: {
            type: 'object',
            properties: {
                path: location,
                filter: {type: 'string', description: 'A test file below tests/ or a --filter expression. Leave out to run everything.'},
                php,
                database,
                fresh: {type: 'boolean', description: 'Build the slot again from nothing, for example after a dependency was released.'},
                seed: {type: 'integer', description: 'The Faker seed a failed run printed. It repeats that run with the same data.'},
            },
            required: ['path'],
        },
    },
    {
        name: 'run_analysis',
        description: 'Runs the static checks of a checkout: lint always, phpstan, deptrac and phparkitect when it configures them.',
        inputSchema: {
            type: 'object',
            properties: {
                path: location,
                check: {type: 'string', enum: ['lint', 'phpstan', 'deptrac', 'phparkitect'], description: 'One check. Leave out for all of them.'},
                baseline: {type: 'boolean', description: 'Write the PHPStan baseline into the checkout instead of reporting. Everything PHPStan finds becomes accepted, so this is a decision, not a fix.'},
                php,
                database,
            },
            required: ['path'],
        },
    },
    {
        name: 'release_slots',
        description: 'Empties the slots of a checkout, or every slot. A slot with a run in it stays.',
        inputSchema: {
            type: 'object',
            properties: {
                path: {type: 'string', description: 'The absolute path of a checkout. Leave out to empty every slot.'},
            },
        },
    },
    {
        name: 'testkit_status',
        description: 'The php versions, databases and services the testkit offers, and what its slots hold.',
        inputSchema: {type: 'object', properties: {}},
    },
];

function testkit(args, environment = {}) {
    const result = spawnSync(TESTKIT, args, {
        encoding: 'utf8',
        timeout: ONE_RUN,
        env: {...process.env, ...environment, NO_COLOR: '1'},
    });
    const output = [result.stdout, result.stderr].filter(Boolean).join('\n').trim();

    return {text: output || 'The testkit produced no output.', isError: result.status !== 0};
}

function slotOptions({php, database, fresh}) {
    return [
        ...(php ? ['--php', php] : []),
        ...(database ? ['--db', database] : []),
        ...(fresh ? ['--fresh'] : []),
    ];
}

const handlers = {
    run_tests: ({path, filter, seed, ...rest}) => testkit([
        'test', path, ...slotOptions(rest), '--', '--colors=never',
        ...(filter ? (filter.startsWith('tests/') ? [filter] : ['--filter', filter]) : []),
    ], seed === undefined ? {} : {FOUNDRY_FAKER_SEED: String(seed)}),
    run_analysis: ({path, check, baseline, ...rest}) => testkit([
        'analyse', path, ...(check ? [check] : []), ...(baseline ? ['--baseline'] : []), ...slotOptions(rest),
    ]),
    release_slots: ({path}) => testkit(['release', path ?? '--all']),
    testkit_status: () => testkit(['status']),
};

const server = new Server({name: 'opendxp-testkit', version: '2.0.0'}, {capabilities: {tools: {}}, instructions: INSTRUCTIONS});

server.setRequestHandler(ListToolsRequestSchema, async () => ({tools: TOOLS}));

server.setRequestHandler(CallToolRequestSchema, async (request) => {
    const handler = handlers[request.params.name];

    if (!handler) {
        return {content: [{type: 'text', text: `Unknown tool: ${request.params.name}`}], isError: true};
    }

    const {text, isError} = handler(request.params.arguments ?? {});

    return {content: [{type: 'text', text}], isError};
});

await server.connect(new StdioServerTransport());
