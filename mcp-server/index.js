#!/usr/bin/env node

import {spawnSync} from 'child_process';
import fs from 'fs';
import path from 'path';
import {fileURLToPath} from 'url';

let Server, StdioServerTransport, CallToolRequestSchema, ListToolsRequestSchema;

try {
    ({Server} = await import('@modelcontextprotocol/sdk/server/index.js'));
    ({StdioServerTransport} = await import('@modelcontextprotocol/sdk/server/stdio.js'));
    ({CallToolRequestSchema, ListToolsRequestSchema} = await import('@modelcontextprotocol/sdk/types.js'));
} catch {
    process.stderr.write('opendxp-testkit: dependencies are missing. Run ddev start in the testkit once, or npm install in mcp-server.\n');
    process.exit(1);
}

const TESTKIT = path.resolve(path.join(path.dirname(fileURLToPath(import.meta.url)), '..'));

const INSTRUCTIONS = `
The OpenDXP testkit runs a package's tests against a real OpenDXP installation.

Code stays where it is. The testkit builds an application around the package in a slot of its own,
installs the dependencies, installs OpenDXP and runs Pest there. A working tree therefore has no
vendor directory and needs none: do not look for one, do not run composer or pest on the host, and
do not check whether a tool is available.

Every test runs inside a transaction that is rolled back afterwards, so a run leaves the database
as it found it and two runs never see each other's data.

A run costs minutes the first time a package, php version and database meet, because that slot is
built from nothing. The same combination afterwards is seconds. Run the narrowest thing that
answers the question: one file, one filter. Running everything is a decision, not a precaution.

The static checks work the same way. They run in the same slot, so a package that was tested is
analysed without building anything again.

Name a package by its path. A bare name works for anything in a configured root, and list_bundles
shows those.
`.trim();

function ddev(args, timeoutMs) {
    const result = spawnSync('ddev', args, {cwd: TESTKIT, encoding: 'utf8', timeout: timeoutMs});

    return {
        ok: result.status === 0,
        output: [result.stdout, result.stderr].filter(Boolean).join('\n').trim(),
    };
}

function roots() {
    for (const name of ['testkit.yaml', 'testkit.dist.yaml']) {
        const file = path.join(TESTKIT, '.ddev', name);

        if (!fs.existsSync(file)) {
            continue;
        }

        const found = fs.readFileSync(file, 'utf8').match(/^roots:\s*(\[.*\])\s*$/m);

        if (found) {
            return JSON.parse(found[1]);
        }
    }

    return [];
}

const TOOLS = [
    {
        name: 'list_bundles',
        description: 'The packages that can be named without a path, because they sit in a configured root.',
        inputSchema: {type: 'object', properties: {}},
    },
    {
        name: 'run_tests',
        description:
            'Runs a package\'s tests in a slot of its own. Returns what the run found: how many tests ran, '
            + 'which failed and why. That output is the answer.',
        inputSchema: {
            type: 'object',
            properties: {
                bundle: {type: 'string', description: 'A path to a package, or the name of one in a configured root.'},
                filter: {type: 'string', description: 'A test file or a --filter expression. Leave out to run everything, which is slower.'},
                php: {type: 'string', description: 'A php version the testkit offers, e.g. "8.3". Leave out for the default.'},
                database: {type: 'string', description: 'A database the testkit offers, e.g. "mariadb". Leave out for the default.'},
                fresh: {type: 'boolean', description: 'Build the slot again from nothing. Only needed when the dependencies changed.'},
            },
            required: ['bundle'],
        },
    },
    {
        name: 'release_slots',
        description: 'Empties a slot so the next run builds it again. Refuses while a run is using it.',
        inputSchema: {
            type: 'object',
            properties: {
                bundle: {type: 'string', description: 'One package by name. Leave out to empty every slot.'},
            },
        },
    },
    {
        name: 'run_analysis',
        description:
            'Runs the static checks in a slot of its own: the lints always, and phpstan, deptrac '
            + 'and phparkitect where the package configures them. Returns what each check found.',
        inputSchema: {
            type: 'object',
            properties: {
                bundle: {type: 'string', description: 'A path to a package, or the name of one in a configured root.'},
                check: {
                    type: 'string',
                    enum: ['lint', 'phpstan', 'deptrac', 'phparkitect'],
                    description: 'One check. Leave out for all of them.',
                },
                php: {type: 'string', description: 'A php version the testkit offers, e.g. "8.3". Leave out for the default.'},
                database: {type: 'string', description: 'A database the testkit offers, e.g. "mariadb". Leave out for the default.'},
            },
            required: ['bundle'],
        },
    },
    {
        name: 'testkit_status',
        description: 'Which php versions and databases this testkit offers, and what the slots hold.',
        inputSchema: {type: 'object', properties: {}},
    },
];

function listBundles() {
    const lines = [];

    for (const root of roots()) {
        if (!fs.existsSync(root)) {
            continue;
        }

        for (const entry of fs.readdirSync(root).sort()) {
            if (fs.existsSync(path.join(root, entry, 'composer.json'))) {
                lines.push(entry);
            }
        }
    }

    return lines.length
        ? `Named without a path because they sit in a configured root.\n\n${lines.join('\n')}`
        : 'No roots are configured. Name a package by its path.';
}

function status() {
    const {output} = ddev(['slots'], 60 * 1000);

    return output || 'The testkit did not answer.';
}

function runTests({bundle, filter, php, database, fresh}) {
    const args = ['test', bundle];

    if (php) args.push('--php', php);
    if (database) args.push('--db', database);
    if (fresh) args.push('--fresh');
    if (filter) args.push(filter);

    const {output} = ddev(args, 45 * 60 * 1000);

    return output || 'The run produced no output.';
}

function runAnalysis({bundle, check, php, database}) {
    const args = ['analyse', bundle];

    if (php) args.push('--php', php);
    if (database) args.push('--db', database);
    if (check) args.push(check);

    const {output} = ddev(args, 45 * 60 * 1000);

    return output || 'The analysis produced no output.';
}

function releaseSlots({bundle}) {
    const {output} = ddev(bundle ? ['release', bundle] : ['release', '--all'], 5 * 60 * 1000);

    return output || 'Nothing to empty.';
}

const server = new Server(
    {name: 'opendxp-testkit', version: '2.0.0'},
    {capabilities: {tools: {}}, instructions: INSTRUCTIONS},
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({tools: TOOLS}));

server.setRequestHandler(CallToolRequestSchema, async (request) => {
    const args = request.params.arguments || {};

    const answer = {
        list_bundles: listBundles,
        testkit_status: status,
        run_tests: () => runTests(args),
        run_analysis: () => runAnalysis(args),
        release_slots: () => releaseSlots(args),
    }[request.params.name];

    if (!answer) {
        return {content: [{type: 'text', text: `Unknown tool: ${request.params.name}`}], isError: true};
    }

    return {content: [{type: 'text', text: answer()}]};
});

await server.connect(new StdioServerTransport());
