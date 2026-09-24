<?php

declare(strict_types=1);

/**
 * Renders what a slot's last run left behind. Codeception, phpstan and deptrac all write JUnit,
 * so one reader covers three of the four tools. phparkitect has no machine readable format, so
 * its console output is shown as it is.
 */

$root = '/var/www/html';
$slotDir = $argv[1] ?? '';
$bundle = $argv[2] ?? '(unknown)';

if ($slotDir === '' || !is_dir("$root/$slotDir")) {
    fwrite(STDERR, "Usage: index.php <slot dir relative to /var/www/html> <bundle>\n");
    exit(1);
}

$slot = basename($slotDir);
$output = "$root/$slotDir/tests/_output";
$target = "$root/app/public/analysis";

@mkdir($target, 0775, true);

function readJUnit(string $file): ?array
{
    $xml = @simplexml_load_file($file);

    if ($xml === false) {
        return null;
    }

    $suites = $xml->getName() === 'testsuite' ? [$xml] : $xml->testsuite;
    $failures = [];
    $total = 0;

    foreach ($suites as $suite) {
        $total += (int) $suite['tests'];

        foreach ($suite->xpath('.//testcase') ?: [] as $case) {
            foreach ($case->children() as $problem) {
                if (in_array($problem->getName(), ['failure', 'error'], true)) {
                    $failures[] = [
                        'name' => (string) ($case['name'] ?: $case['classname']),
                        'text' => trim((string) $problem),
                    ];
                }
            }
        }
    }

    return ['total' => $total, 'failures' => $failures, 'ranAt' => filemtime($file)];
}

$tools = [
    'codeception' => ['file' => "$output/report.xml", 'about' => 'Tests. Suites in codeception.dist.yml.'],
    'phpstan'     => ['file' => "$output/phpstan.junit.xml", 'about' => 'Types and reachability. Rules in phpstan.neon.'],
    'deptrac'     => ['file' => "$output/deptrac.junit.xml", 'about' => 'Which layer may depend on which. Rules in deptrac.yaml.'],
];

$cards = [];

foreach ($tools as $name => $tool) {
    if (!is_readable($tool['file'])) {
        continue;
    }

    $run = readJUnit($tool['file']);

    if ($run === null) {
        continue;
    }

    $cards[$name] = $run + ['about' => $tool['about']];
}

$arkitect = is_readable("$output/arkitect.txt") ? (string) file_get_contents("$output/arkitect.txt") : null;
$graph = is_readable("$output/deptrac.png") ? "$slot-deptrac.png" : null;

if ($graph !== null) {
    copy("$output/deptrac.png", "$target/$graph");
}

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);

ob_start(); ?>
<!doctype html>
<meta charset="utf-8">
<title><?= $e($bundle) ?> — <?= $e($slot) ?></title>
<style>
  body { font: 15px/1.6 system-ui, sans-serif; margin: 0 auto; max-width: 62rem; padding: 2rem 1rem; color: #1a1a1a; }
  a { color: #0969da; }
  h1 { font-size: 1.5rem; margin-bottom: 0; }
  h2 { font-size: 1.05rem; margin: 0; display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
  section { border: 1px solid #e0e0e0; border-radius: 6px; padding: 1rem 1.25rem; margin-bottom: 1rem; }
  pre { background: #f6f6f6; padding: .75rem; border-radius: 4px; overflow-x: auto; font-size: .8125rem; margin: .75rem 0 0; }
  img { max-width: 100%; margin-top: .75rem; border: 1px solid #e0e0e0; border-radius: 4px; }
  .sub { color: #666; margin-bottom: 2rem; }
  .about { color: #666; font-size: .875rem; margin: .25rem 0 0; }
  .ok { color: #1a7f37; font-weight: 600; }
  .bad { color: #b3261e; font-weight: 600; }
</style>
<h1><?= $e($bundle) ?></h1>
<p class="sub"><?= $e($slot) ?></p>

<?php foreach ($cards as $name => $card): $bad = count($card['failures']); ?>
<section>
  <h2><?= $e($name) ?>
    <span class="<?= $bad ? 'bad' : 'ok' ?>"><?= $bad ? "$bad of {$card['total']} failed" : "{$card['total']} passed" ?></span>
  </h2>
  <p class="about"><?= $e($card['about']) ?> · <?= $e(date('d.m. H:i', $card['ranAt'])) ?></p>
  <?php foreach ($card['failures'] as $failure): ?>
    <pre><?= $e($failure['name']) ?>

<?= $e($failure['text']) ?></pre>
  <?php endforeach; ?>
  <?php if ($name === 'deptrac' && $graph !== null): ?><img src="<?= $e($graph) ?>" alt="layer graph"><?php endif; ?>
</section>
<?php endforeach; ?>

<?php if ($arkitect !== null): ?>
<section>
  <h2>phparkitect</h2>
  <p class="about">What shape a class has. Rules in phparkitect.php. No machine readable output, so this is the console.</p>
  <pre><?= $e(trim($arkitect)) ?></pre>
</section>
<?php endif; ?>

<?php if ($cards === [] && $arkitect === null): ?>
<p>Nothing measured yet in this slot.</p>
<?php endif; ?>
<?php
file_put_contents("$target/$slot.html", (string) ob_get_clean());
