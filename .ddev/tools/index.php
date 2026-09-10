<?php

declare(strict_types=1);

final readonly class ToolRun
{
    public function __construct(
        public string $tool,
        public string $about,
        public int $ranAt,
        public ?int $violations,
        public string $output,
        public array $artefacts,
    ) {
    }
}

function readRuns(string $dir): array
{
    $runs = [];

    if (is_readable($dir . '/deptrac.txt')) {
        $report = is_readable($dir . '/deptrac.json')
            ? json_decode((string) file_get_contents($dir . '/deptrac.json'), true)['Report'] ?? []
            : [];

        $runs[] = new ToolRun(
            tool: 'deptrac',
            about: 'Which layer may depend on which. Rules in deptrac.yaml.',
            ranAt: (int) filemtime($dir . '/deptrac.txt'),
            violations: isset($report['Violations']) ? (int) $report['Violations'] : null,
            output: (string) file_get_contents($dir . '/deptrac.txt'),
            artefacts: artefactsIn($dir, 'deptrac', [
                'deptrac.png'  => 'layer graph',
                'deptrac.json' => 'every edge, as JSON',
                'deptrac.txt'  => 'console output',
            ]),
        );
    }

    if (is_readable($dir . '/phparkitect.txt')) {
        $said = (string) file_get_contents($dir . '/phparkitect.txt');

        $runs[] = new ToolRun(
            tool: 'PHPArkitect',
            about: 'What shape a class has: readonly, final, its name, what it implements. Rules in phparkitect.php.',
            ranAt: (int) filemtime($dir . '/phparkitect.txt'),
            violations: countArkitectViolations($said),
            output: $said,
            artefacts: artefactsIn($dir, 'phparkitect', ['phparkitect.txt' => 'console output']),
        );
    }

    return $runs;
}

function countArkitectViolations(string $said): ?int
{
    if (preg_match('/(\d+) VIOLATIONS DETECTED/', $said, $found) === 1) {
        return (int) $found[1];
    }

    return str_contains($said, 'NO VIOLATIONS') ? 0 : null;
}

function artefactsIn(string $dir, string $tool, array $wanted): array
{
    $there = [];

    foreach ($wanted as $file => $label) {
        if (is_readable($dir . '/' . $file)) {
            $there[$label] = $file;
        }
    }

    return $there;
}

function tail(string $text, int $lines): string
{
    return implode("\n", array_slice(preg_split('/\R/', rtrim($text)) ?: [], -$lines));
}

function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$dir = $argv[1] ?? '';

if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Usage: index.php <directory>\n");
    exit(1);
}

$runs = readRuns($dir);

ob_start();
require __DIR__ . '/index.template.php';
file_put_contents($dir . '/index.html', (string) ob_get_clean());

printf("report: %s/index.html\n", $dir);
