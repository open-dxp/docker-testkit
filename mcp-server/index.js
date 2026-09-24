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

const TESTKIT_DIR = path.resolve(path.join(path.dirname(fileURLToPath(import.meta.url)), '..'));
const SLOT_FILE = path.join(TESTKIT_DIR, '.ddev', 'slots.tsv');
const TASKS = ['codecept', 'phpstan', 'deptrac', 'arkitect', 'all'];

const announced = new Set();

function readEnv(key) {
    const file = path.join(TESTKIT_DIR, '.ddev', 'testkit.env');

    if (!fs.existsSync(file)) {
        return null;
    }

    return (fs.readFileSync(file, 'utf8').match(new RegExp(`^${key}="?(.*?)"?$`, 'm')) || [])[1] || null;
}

const INSTRUCTIONS = `
The OpenDXP testkit runs the tests of a bundle or a project against a real OpenDXP installation.

Code stays where it is. The testkit copies it into a workspace of its own, builds an OpenDXP
application around a bundle, installs the dependencies and runs there. So the working tree has no
vendor directory and needs none. Do not look for one, do not run composer, phpunit, phpstan or
codeception on the host, and do not check whether a tool is available: run_tests takes codecept,
phpstan, deptrac, arkitect and all, always, for every bundle and project.

run_tests hands back what the run found: how many tests ran, which ones failed, and the message of
each failure. That is the answer. The report page it may name is for the user to open in a browser,
so pass the address on once and never fetch it yourself.

Runs happen on a developer machine against a real OpenDXP installation, so a full suite costs
minutes rather than seconds. Run the narrowest thing that answers the question: one path, one file, one suite. 
Running everything is allowed, but it is a decision, not a precaution. Do it when you were asked to, 
or when the change reaches across the bundle. If you cannot name the question a wider run answers that a narrower one leaves open, 
do not make it.

Name a bundle or project by its path. A bare name works for anything in a configured root, and
list_bundles shows those.

Runs happen on the php version and database the testkit defaults to. Pass php or database only when
the question is about a particular one, for instance when a bundle promises a version range and you
are checking the lower end. The first run of a combination installs its dependencies and takes
minutes; the same combination afterwards is quick.

testkit_status says which versions and databases are offered, what each slot holds and how much disk
it takes, and whether the browser has drifted far from current stable, which is worth knowing when a
result here disagrees with CI.

Slots live only while the testkit is up. Stopping it empties them, and the first run after a start
installs again, which is the slow one. Do not clean up after yourself: keeping a slot warm is the
point, and another session may be running in it.

When every slot is taken, a run takes the one idle longest and reinstalls into it, which costs one
install. That is normal and needs no permission. If instead every slot has a run in it, the new run
waits for one to finish, so say so rather than leaving the user watching nothing. release_slots is
there for when the user wants the testkit emptied; ask first and call it only on a yes.
`.trim();

function run(args, timeoutMs) {
    const result = spawnSync('ddev', args, {cwd: TESTKIT_DIR, encoding: 'utf8', timeout: timeoutMs});

    return {
        output: ((result.stdout || '') + (result.stderr || '')).replace(/\x1b\[[0-9;]*m/g, ''),
        ok: result.status === 0,
        timedOut: result.status === null,
    };
}

// The slot a run would land in, or null when the package holds none yet. The key is what the host
// command builds, so a run on another php version or database is a different slot.
function slotFor(bundle, php, database) {
    if (!fs.existsSync(SLOT_FILE)) {
        return null;
    }

    const key = [
        path.basename(bundle),
        `php${php || readEnv('TESTKIT_PHP_DEFAULT')}`,
        database || readEnv('TESTKIT_DB_DEFAULT'),
    ].join('|');

    const row = fs.readFileSync(SLOT_FILE, 'utf8')
        .split('\n')
        .map((line) => line.split('\t'))
        .find(([, held]) => held === key);

    return row ? Number(row[0]) : null;
}

// The lock a run holds for its slot, the same file .ddev/scripts/testkit-lib.sh writes.
function busySlots() {
    const dir = process.env.TMPDIR || '/tmp';
    const count = Number(readEnv('TESTKIT_SLOTS') || 0);
    const busy = [];

    for (let slot = 1; slot <= count; slot++) {
        const lock = path.join(dir, `opendxp-tests-${process.getuid()}-slot-${slot}.lock`);

        if (fs.existsSync(lock) && spawnSync('flock', ['-n', lock, 'true']).status !== 0) {
            busy.push(slot);
        }
    }

    return {busy, count};
}

// Only the files this run wrote. A slot keeps the reports of every task that ever ran in it, and a
// phpstan run that also reported last week's codeception failures would be a lie.
function readResults(slot, since) {
    const dir = path.join(TESTKIT_DIR, 'app', `slot-${slot}`, 'tests', '_output');

    if (slot === null || !fs.existsSync(dir)) {
        return [];
    }

    return fs.readdirSync(dir)
        .filter((f) => f.endsWith('.xml'))
        .filter((f) => fs.statSync(path.join(dir, f)).mtimeMs >= since)
        .map((file) => ({file, xml: fs.readFileSync(path.join(dir, file), 'utf8')}))
        // An empty or half written file is a tool that never got to report, not a run that found
        // nothing. Left in, it reads as a clean result and hides the error that caused it.
        .filter(({xml}) => xml.includes('<testsuite'))
        .map(({file, xml}) => {
            // Counted from the testcase elements, not from the testsuite attributes: a report with
            // one suite per class carries several, and reading the first one reports a fraction.
            const cases = xml.split(/<testcase\b/).slice(1);
            const failures = [];

            for (const testcase of cases) {
                if (!/<(failure|error)\b/.test(testcase)) {
                    continue;
                }

                const name = (testcase.match(/\bname="([^"]*)"/) || testcase.match(/\bclassname="([^"]*)"/) || [])[1];
                const message = (testcase.match(/<(?:failure|error)\b[^>]*\bmessage="([^"]*)"/)
                    || testcase.match(/<(?:failure|error)\b[^>]*>([\s\S]*?)<\//) || [])[1];

                failures.push({
                    test: name ?? '(unnamed)',
                    message: (message ?? '').replace(/&#\d+;|&quot;|&lt;|&gt;|&amp;/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 400),
                });
            }

            return {
                tool: file.replace(/\.junit\.xml$|\.xml$/, '').replace('report', 'codeception'),
                ran: cases.length,
                failed: failures.length,
                failures,
            };
        });
}

// phparkitect has no machine readable output, so its console goes through as it is. Without this a
// run of every task would report three tools and quietly drop the fourth.
function readArkitect(slot, since) {
    const file = path.join(TESTKIT_DIR, 'app', `slot-${slot}`, 'tests', '_output', 'arkitect.txt');

    if (slot === null || !fs.existsSync(file) || fs.statSync(file).mtimeMs < since) {
        return null;
    }

    const text = fs.readFileSync(file, 'utf8').replace(/\x1b\[[0-9;]*m/g, '').trim();

    return text === '' ? null : text.split('\n').slice(-40).join('\n');
}

const TOOLS = [
    {
        name: 'list_bundles',
        description: 'The packages that can be named without a path, because they sit in a configured root. '
            + 'Anything else is run by giving run_tests its path, so this is a convenience and not a list of what works.',
        inputSchema: {type: 'object', properties: {}, required: []},
    },
    {
        name: 'run_tests',
        description:
            'Runs one task for one bundle and reports what came back: how many ran, which failed and why. ' +
            'Give a path to run less than everything.',
        inputSchema: {
            type: 'object',
            properties: {
                bundle: {type: 'string', description: 'A path to a bundle or project, or the name of one in a configured root.'},
                task: {type: 'string', enum: TASKS, description: 'Which tool to run. Defaults to codecept.'},
                path: {
                    type: 'string',
                    description: 'A test file or directory relative to the bundle, e.g. "tests/Acceptance/ExtJs/SaveFormCest.php". Leave out to run the whole suite, which is slow.',
                },
                php: {type: 'string', description: 'A php version the testkit offers, e.g. "8.3". Leave out for the default.'},
                database: {type: 'string', description: 'A database the testkit offers, e.g. "mariadb". Leave out for the default.'},
                composer: {type: 'boolean', description: 'Reinstall dependencies first. Only needed when they changed.'},
                debug: {type: 'boolean', description: 'Pass --debug for step by step output.'},
            },
            required: ['bundle'],
        },
    },
    {
        name: 'release_slots',
        description:
            'Empties slots so the next runs start from scratch. Ask the user before calling this: ' +
            'every slot it takes costs an install to get back. Slots with a run in them are left alone.',
        inputSchema: {
            type: 'object',
            properties: {
                bundle: {type: 'string', description: 'One bundle or project by name. Leave out to empty every slot.'},
            },
            required: [],
        },
    },
    {
    name: 'testkit_status',
    description: 'Which php versions and databases this testkit offers, what the slots hold, and how current the browser is.',
    inputSchema: {type: 'object', properties: {}, required: []},
    },
];

async function callTool(name, args) {
    try {
        if (name === 'list_bundles') {
            const roots = (readEnv('TESTKIT_ROOTS') || '').split(' ').filter(Boolean);
            const held = fs.existsSync(SLOT_FILE)
                ? Object.fromEntries(fs.readFileSync(SLOT_FILE, 'utf8').split('\n').filter(Boolean)
                    .map((l) => l.split('\t')).map(([slot, key]) => [key.split('|')[0], slot]))
                : {};

            const bundles = roots.flatMap((root) => fs.readdirSync(root)
                .filter((d) => fs.existsSync(path.join(root, d, 'composer.json')))
                .map((d) => (held[d] ? `${d} (slot ${held[d]})` : d)));

            if (bundles.length === 0) {
                return {
                    content: [{type: 'text', text: 'No roots are configured. Give run_tests a path instead of a name.'}],
                };
            }

            return {
                content: [{
                    type: 'text',
                    text: [
                        `Named without a path because they sit in ${roots.join(', ')}.`,
                        'A package anywhere else is run by giving run_tests its path.',
                        '',
                        ...bundles,
                    ].join('\n'),
                }],
            };
        }

        if (name === 'run_tests') {
            const {bundle, task = 'codecept', path: testPath, php, database, composer = false, debug = false} = args;

            if (typeof bundle !== 'string' || bundle.trim() === '') {
                throw new Error('run_tests needs a bundle: a path to a bundle or project, or a name in a configured root.');
            }

            const {busy, count} = busySlots();
            const slotHeld = slotFor(bundle, php, database);

            // Blocking is what the host command does, for up to an hour. An agent waiting that long
            // on a tool call tells the user nothing, so both cases come back instead.
            if (slotHeld !== null && busy.includes(slotHeld)) {
                return {
                    content: [{
                        type: 'text',
                        text: `Slot ${slotHeld} already has a run of ${path.basename(bundle)} in it. Every task for one `
                            + 'package, php version and database shares that slot, so this would wait for the other to '
                            + 'finish. Nothing was started; run it afterwards.',
                    }],
                    isError: true,
                };
            }

            if (slotHeld === null && count > 0 && busy.length >= count) {
                return {
                    content: [{
                        type: 'text',
                        text: `All ${count} slots have a run in them. This one would wait for a slot to come free, `
                            + 'so nothing was started. Wait, or ask the user whether to empty the testkit.',
                    }],
                    isError: true,
                };
            }

            const argv = ['opendxp-tests-run', bundle, task];

            if (testPath) argv.push(testPath);
            if (php) argv.push('--php', php);
            if (database) argv.push('--db', database);
            if (debug) argv.push('--debug');
            if (composer) argv.push('--composer');

            const startedAt = Date.now();
            const result = run(argv, 1_800_000);

            if (result.timedOut) {
                return {content: [{type: 'text', text: 'The run did not finish within 30 minutes.'}], isError: true};
            }

            // The command prints the slot it picked. Deriving it from the argument instead breaks as
            // soon as a path is passed, and then no report is found and only the url is left.
            const slot = (result.output.match(/-> slot (\d+)/) || [])[1] ?? null;
            const results = readResults(slot, startedAt);
            const lines = [];

            for (const r of results) {
                if (r.ran === 0 && r.failed === 0) {
                    lines.push(`${r.tool}: nothing to report`);
                } else if (r.failed === 0) {
                    lines.push(`${r.tool}: ${r.ran} passed`);
                } else {
                    lines.push(`${r.tool}: ${r.failed} of ${r.ran} failed`);
                }

                for (const f of r.failures) {
                    lines.push(`  ${f.test}`);
                    lines.push(`    ${f.message}`);
                }
            }

            // A task without its config file is skipped by the command, not failed. Passed on as it
            // stands, so "run everything" does not come back as two broken tools.
            for (const [, task] of result.output.matchAll(/^([a-z-]+): not configured$/gm)) {
                lines.push(`${task}: not configured in this package`);
            }

            const arkitect = readArkitect(slot, startedAt);

            if (arkitect) {
                lines.push('arkitect:', arkitect);
            }

            if (lines.length === 0 && arkitect === null) {
                lines.push(result.output.trim().split('\n').slice(-25).join('\n'));
            }

            // The report page is for the user to open, and repeating its address after every run
            // teaches an agent to fetch it instead of reading what it was just handed. Once per slot.
            const report = (result.output.match(/^report: (.+)$/m) || [])[1];

            if (report && slot !== null && !announced.has(slot)) {
                announced.add(slot);
                lines.push('', `Tell the user once that this slot reports to ${report}, and that it stays there.`);
            }

            return {content: [{type: 'text', text: lines.join('\n')}], isError: !result.ok};
        }

        if (name === 'release_slots') {
            const result = run(['opendxp-tests-clean', args.bundle || '--all'], 300_000);
            const text = result.output.trim();

            return {
                content: [{type: 'text', text: text || 'Nothing was holding a slot.'}],
                isError: !result.ok,
            };
        }

        if (name === 'testkit_status') {
            const result = run(['opendxp-doctor'], 60_000);

            return {content: [{type: 'text', text: result.output.trim()}], isError: !result.ok};
        }

        throw new Error(`Unknown tool: ${name}`);
    } catch (error) {
        return {content: [{type: 'text', text: `Error: ${error.message}`}], isError: true};
    }
}

const server = new Server(
    {name: 'opendxp-testkit', version: '2.0.0'},
    {capabilities: {tools: {}}, instructions: INSTRUCTIONS},
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({tools: TOOLS}));

server.setRequestHandler(CallToolRequestSchema, async (request) =>
    callTool(request.params.name, request.params.arguments ?? {}));

await server.connect(new StdioServerTransport());
