<?php

declare(strict_types=1);

namespace Testkit;

use RuntimeException;

final readonly class Arguments
{
    private const array FLAGS = ['--fresh', '--all', '--fail-if-busy', '--baseline'];
    private const array OPTIONS = ['--php', '--db', '--tag', '--run'];

    /**
     * @param list<string>          $flags      such as fresh or all
     * @param array<string, string> $options    such as php => 8.3
     * @param list<string>          $positional
     * @param list<string>          $passedOn   everything after --
     */
    public function __construct(
        public array $flags,
        public array $options,
        public array $positional,
        public array $passedOn,
    ) {
    }

    /**
     * With $passOnTheRest, everything the testkit does not know goes to the tool, in its order. The
     * first positional argument is always the target.
     *
     * @param list<string> $arguments
     */
    public static function parse(array $arguments, bool $passOnTheRest = false): self
    {
        $flags = [];
        $options = [];
        $positional = [];
        $passedOn = [];

        while ($arguments !== []) {
            $argument = array_shift($arguments);

            if ($argument === '--') {
                return new self($flags, $options, $positional, [...$passedOn, ...$arguments]);
            }

            if (in_array($argument, self::FLAGS, true)) {
                $flags[] = substr($argument, 2);
            } elseif (in_array($argument, self::OPTIONS, true)) {
                $options[substr($argument, 2)] = array_shift($arguments) ?? throw new RuntimeException($argument . ' needs a value.');
            } elseif ($passOnTheRest && ($positional !== [] || str_starts_with($argument, '-'))) {
                $passedOn[] = $argument;
            } elseif (str_starts_with($argument, '--')) {
                throw new RuntimeException(sprintf('Unknown option %s.', $argument));
            } else {
                $positional[] = $argument;
            }
        }

        return new self($flags, $options, $positional, $passedOn);
    }

    public function has(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }
}
