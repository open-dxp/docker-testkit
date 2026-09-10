<?php

declare(strict_types=1);

/**
 * Draws the layer graph deptrac just measured, as graphviz source on stdout.
 *
 * Only the violations are drawn. The allowed graph carries no information: a layered architecture
 * says every layer may use everything below it, so drawing it produces a filled triangle. What is
 * worth looking at is the edges that should not be there, and how heavy each one is.
 *
 * Usage: php layer-graph.php <deptrac config> <deptrac json report> | dot -Tpng -o graph.png
 */

require '/tool/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

final readonly class LayerGraph
{
    /**
     * @param array<string, list<string>>    $ruleset  layer to the layers it may depend on
     * @param list<string>                   $outside  layers that lie outside the analysed code
     * @param array<string, array<string, int>> $violations from-layer to to-layer to edge count
     */
    private function __construct(
        private array $ruleset,
        private array $outside,
        private array $violations,
    ) {
    }

    public static function fromReport(string $configFile, string $reportFile): self
    {
        $config = Yaml::parseFile($configFile)['deptrac'];
        $ruleset = array_map(static fn (?array $allowed): array => $allowed ?? [], $config['ruleset']);

        $outside = array_values(array_diff(
            array_column($config['layers'], 'name'),
            array_keys($ruleset),
        ));

        return new self($ruleset, $outside, self::readViolations($reportFile));
    }

    /**
     * @return array<string, array<string, int>>
     */
    private static function readViolations(string $reportFile): array
    {
        $report = json_decode((string) file_get_contents($reportFile), true, flags: JSON_THROW_ON_ERROR);
        $violations = [];

        foreach ($report['files'] as $messages) {
            foreach ($messages['messages'] as $message) {
                if (preg_match('/\((\w+) on (\w+)\)$/', $message['message'], $found) === 1) {
                    $violations[$found[1]][$found[2]] ??= 0;
                    ++$violations[$found[1]][$found[2]];
                }
            }
        }

        return $violations;
    }

    public function draw(): string
    {
        $tiers = $this->tiers();
        $lines = [
            'digraph layers {',
            '  rankdir=BT;',
            '  bgcolor="white";',
            '  node [shape=box style="rounded,filled" fontname="DejaVu Sans" fontsize=11'
                . ' fillcolor="#f4f4f4" color="#cccccc" fontcolor="#333333" margin="0.16,0.09"];',
            '  edge [fontname="DejaVu Sans" fontsize=10];',
        ];

        foreach ($this->outside as $layer) {
            $lines[] = sprintf(
                '  "%s" [shape=note fillcolor="#eaeaea" color="#bbbbbb"];',
                $layer,
            );
        }

        // One rank per tier keeps the stack readable: what a layer sits on is drawn below it. What
        // lies outside the bundle goes on top of all of it.
        $ranks = $tiers;
        $ranks[] = $this->outside;

        foreach ($ranks as $layers) {
            $lines[] = '  { rank=same; ' . implode(' ', array_map(
                static fn (string $layer): string => sprintf('"%s";', $layer),
                $layers,
            )) . ' }';
        }

        // Graphviz orders ranks by the edges between them, and most layers hold no violation at
        // all. Without a chain the untouched tiers float and the stack stops being a stack.
        foreach ($this->chain($ranks) as [$lower, $upper]) {
            $lines[] = sprintf('  "%s" -> "%s" [style=invis];', $lower, $upper);
        }

        foreach ($this->violations as $from => $targets) {
            foreach ($targets as $to => $count) {
                $lines[] = sprintf(
                    '  "%s" -> "%s" [label=" %d" color="#c0392b" fontcolor="#c0392b" penwidth=%.1f];',
                    $from,
                    $to,
                    $count,
                    min(1.0 + $count / 4, 5.0),
                );
            }
        }

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /**
     * One pair per step of the stack, each naming a layer in the tier below and one above.
     *
     * @param list<list<string>> $ranks
     *
     * @return list<array{string, string}>
     */
    private function chain(array $ranks): array
    {
        $chain = [];

        for ($step = 1; $step < count($ranks); ++$step) {
            $chain[] = [$ranks[$step - 1][0], $ranks[$step][0]];
        }

        return $chain;
    }

    /**
     * Where each layer sits in the stack: one above the highest layer it is allowed to depend on.
     * Read off the ruleset rather than written down again, so the picture cannot drift from the
     * rules. Violations are left out of it - an edge that breaks the stack does not define it.
     *
     * @return list<list<string>>
     */
    private function tiers(): array
    {
        $tier = [];

        foreach (array_keys($this->ruleset) as $layer) {
            $this->placeLayer($layer, $tier, []);
        }

        $tiers = [];

        foreach ($tier as $layer => $level) {
            $tiers[$level][] = $layer;
        }

        ksort($tiers);

        return array_values($tiers);
    }

    /**
     * @param array<string, int> $tier   filled in as layers are placed
     * @param list<string>       $onPath guards against a cycle in the ruleset, which would be a
     *                                  finding of its own and must not hang the drawing
     */
    private function placeLayer(string $layer, array &$tier, array $onPath): int
    {
        if (isset($tier[$layer])) {
            return $tier[$layer];
        }

        if (in_array($layer, $onPath, true)) {
            return 0;
        }

        $onPath[] = $layer;
        $below = [0];

        foreach ($this->ruleset[$layer] as $dependency) {
            if (isset($this->ruleset[$dependency])) {
                $below[] = $this->placeLayer($dependency, $tier, $onPath) + 1;
            }
        }

        return $tier[$layer] = max($below);
    }
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: layer-graph.php <deptrac config> <deptrac json report>\n");
    exit(1);
}

echo LayerGraph::fromReport($argv[1], $argv[2])->draw();
