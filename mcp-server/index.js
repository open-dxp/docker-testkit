#!/usr/bin/env node

import {Server} from '@modelcontextprotocol/sdk/server/index.js';
import {StdioServerTransport} from '@modelcontextprotocol/sdk/server/stdio.js';
import {CallToolRequestSchema, ListToolsRequestSchema} from '@modelcontextprotocol/sdk/types.js';
import {spawnSync} from 'child_process';
import fs from 'fs';
import path from 'path';
import {fileURLToPath} from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const TESTKIT_DIR = path.resolve(path.join(__dirname, '..'));
const ENV_FILE = path.join(TESTKIT_DIR, '.ddev', '.env');

function readEnvFile() {
    if (!fs.existsSync(ENV_FILE)) {
        throw new Error(`Env file not found: ${ENV_FILE}. Copy .ddev/.env.dist to .ddev/.env and configure it.`);
    }
    return fs.readFileSync(ENV_FILE, 'utf8');
}

function parseEnvValue(content, key) {
    const match = content.match(new RegExp(`^${key}=(.*)$`, 'm'));
    return match ? match[1].trim() : null;
}

function updateEnvValue(key, value) {
    const content = readEnvFile();
    const updated = content.replace(new RegExp(`^${key}=.*$`, 'm'), `${key}=${value}`);
    fs.writeFileSync(ENV_FILE, updated);
}

function runCommand(cmd, args = [], timeoutMs = 600_000) {
    const result = spawnSync(cmd, args, {
        cwd: TESTKIT_DIR,
        encoding: 'utf8',
        timeout: timeoutMs,
        env: {...process.env},
    });

    return {
        stdout: result.stdout || '',
        stderr: result.stderr || '',
        exitCode: result.status ?? -1,
        success: result.status === 0,
        timedOut: result.status === null && result.error?.code === 'ETIMEDOUT',
    };
}

function getDdevStatus() {
    const result = runCommand('ddev', ['status'], 15_000);
    const combined = result.stdout + result.stderr;
    const stripped = combined.replace(/\x1b\[[0-9;]*m/g, '');
    const running = result.success && stripped.includes('OK');
    return {running, output: combined};
}

const server = new Server(
    {name: 'opendxp-testkit', version: '1.0.0'},
    {capabilities: {tools: {}}}
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: [
        {
            name: 'get_status',
            description: 'Returns the current ddev status and which bundle is configured in the testkit.',
            inputSchema: {type: 'object', properties: {}, required: []},
        },
        {
            name: 'set_bundle',
            description:
                'Configures the testkit for a specific bundle. ' +
                'Updates TEST_BUNDLE_DIR_NAME in .ddev/.env and starts or restarts ddev if needed. ' +
                'After a ddev start/restart, run run_codeception with with_composer=true for the first test run.',
            inputSchema: {
                type: 'object',
                properties: {
                    bundle_name: {
                        type: 'string',
                        description: 'The bundle directory name, e.g. "ecommerce-bundle"',
                    },
                },
                required: ['bundle_name'],
            },
        },
        {
            name: 'run_codeception',
            description:
                'Runs Codeception tests. Call set_bundle first to ensure the correct bundle is active. ' +
                'Use with_composer=true after every ddev start or restart.',
            inputSchema: {
                type: 'object',
                properties: {
                    test_path: {
                        type: 'string',
                        description:
                            'Optional path to a specific test file or directory relative to the bundle root, ' +
                            'e.g. "tests/Acceptance/Form/SimpleFormCest.php"',
                    },
                    debug: {
                        type: 'boolean',
                        description: 'Pass --debug to codecept for verbose output (default: false)',
                        default: false,
                    },
                    with_composer: {
                        type: 'boolean',
                        description: 'Run composer install before tests — required after every ddev start/restart (default: false)',
                        default: false,
                    },
                },
                required: [],
            },
        },
        {
            name: 'run_phpstan',
            description:
                'Runs PHPStan static analysis. Call set_bundle first to ensure the correct bundle is active.',
            inputSchema: {
                type: 'object',
                properties: {
                    level: {
                        type: 'number',
                        description: 'PHPStan analysis level, 0–9 (default: 4)',
                        default: 4,
                    },
                },
                required: [],
            },
        },
    ],
}));

server.setRequestHandler(CallToolRequestSchema, async (request) => {

    const {name, arguments: args = {}} = request.params;

    try {

        if (name === 'get_status') {
            const envContent = readEnvFile();
            const bundleName = parseEnvValue(envContent, 'TEST_BUNDLE_DIR_NAME');
            const bundlePath = parseEnvValue(envContent, 'TEST_BUNDLE_LOCAL_PATH');
            const {running, output} = getDdevStatus();

            return {
                content: [{
                    type: 'text',
                    text: [
                        `Testkit directory : ${TESTKIT_DIR}`,
                        `Configured bundle : ${bundleName}`,
                        `Bundle local path : ${bundlePath}`,
                        `ddev running      : ${running}`,
                        '',
                        'ddev status output:',
                        output.trim(),
                    ].join('\n'),
                }],
            };
        }

        if (name === 'set_bundle') {
            const {bundle_name} = args;
            const lines = [];

            const envContent = readEnvFile();
            const currentBundle = parseEnvValue(envContent, 'TEST_BUNDLE_DIR_NAME');
            const {running} = getDdevStatus();

            if (currentBundle === bundle_name && running) {
                return {
                    content: [{
                        type: 'text',
                        text: `Bundle "${bundle_name}" is already configured and ddev is running. Nothing to do.`,
                    }],
                };
            }

            if (currentBundle !== bundle_name) {
                updateEnvValue('TEST_BUNDLE_DIR_NAME', bundle_name);
                lines.push(`Updated TEST_BUNDLE_DIR_NAME: "${currentBundle}" → "${bundle_name}"`);
            }

            if (running) {
                lines.push('ddev is running — restarting...');
                const r = runCommand('ddev', ['restart'], 180_000);
                lines.push(r.stdout.trim());
                if (!r.success) {
                    throw new Error(`ddev restart failed:\n${r.stderr}`);
                }
            } else {
                lines.push('ddev is not running — starting...');
                const r = runCommand('ddev', ['start'], 180_000);
                lines.push(r.stdout.trim());
                if (!r.success) {
                    throw new Error(`ddev start failed:\n${r.stderr}`);
                }
            }

            lines.push('');
            lines.push('Ready. For the first test run use run_codeception with with_composer=true.');

            return {content: [{type: 'text', text: lines.join('\n')}]};
        }

        if (name === 'run_codeception') {
            const {test_path, debug = false, with_composer = false} = args;

            const ddevArgs = ['opendxp-tests-run', 'codecept'];

            if (test_path) {
                ddevArgs.push(test_path);
            }
            if (debug) {
                ddevArgs.push('--debug');
            }
            if (with_composer) {
                ddevArgs.push('--composer');
            }

            const result = runCommand('ddev', ddevArgs, 600_000);
            const filterLine = (line) => !line.startsWith('Make Service');
            const filterOutput = (raw) => {
                const lines = raw.split('\n');
                const start = lines.findIndex((l) => l.includes('Codeception PHP Testing'));
                return (start === -1 ? lines : lines.slice(start)).filter(filterLine).join('\n');
            };

            const filteredStdout = filterOutput(result.stdout || '');
            const filteredStderr = filterOutput(result.stderr || '');
            let output = [filteredStdout, filteredStderr ? `STDERR:\n${filteredStderr}` : '']
                .filter(Boolean)
                .join('\n')
                .trim();

            // Append Response artifact content if present (strip ANSI codes first for reliable matching)
            const combined = ((result.stdout || '') + (result.stderr || '')).replace(/\x1b\[[0-9;]*m/g, '');
            const responseMatch = combined.match(/^(?:Response|Html):\s*(.+)$/m);
            if (responseMatch) {
                const containerPath = responseMatch[1].trim();
                const localPath = containerPath.replace('/var/www/html/app', path.join(TESTKIT_DIR, 'app'));
                if (fs.existsSync(localPath)) {
                    let content = fs.readFileSync(localPath, 'utf8');
                    const MAX_CHARS = 30_000;
                    if (content.length > MAX_CHARS) {
                        content = content.slice(0, MAX_CHARS) + '\n[... truncated]';
                    }
                    output += `\n\n--- Response artifact ---\n${content}`;
                }
            }

            return {
                content: [{type: 'text', text: output || '(no output)'}],
                isError: !result.success,
            };
        }

        if (name === 'run_phpstan') {
            const {level = 4} = args;

            const result = runCommand('ddev', ['opendxp-tests-run', 'phpstan', '-l', String(level)], 300_000);
            const output = [result.stdout, result.stderr ? `STDERR:\n${result.stderr}` : '']
                .filter(Boolean)
                .join('\n')
                .trim();

            return {
                content: [{type: 'text', text: output || '(no output)'}],
                isError: !result.success,
            };
        }

        throw new Error(`Unknown tool: ${name}`);

    } catch (error) {
        return {
            content: [{type: 'text', text: `Error: ${error.message}`}],
            isError: true,
        };
    }
});

const transport = new StdioServerTransport();
await server.connect(transport);