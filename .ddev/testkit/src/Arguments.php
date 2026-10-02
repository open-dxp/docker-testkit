<?php

declare(strict_types=1);

namespace Testkit;

use RuntimeException;

final readonly class Arguments
{
    private const array FLAGS = ['--fresh', '--all', '--fail-if-busy'];
    private const array OPTIONS = ['--php', '--db', '--tag'];

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
     * @param list<string> $arguments
     */
    public static function parse(array $arguments): self
    {
        $flags = [];
        $options = [];
        $positional = [];

        while ($arguments !== []) {
            $argument = array_shift($arguments);

            if ($argument === '--') {
                return new self($flags, $options, $positional, $arguments);
            }

            if (in_array($argument, self::FLAGS, true)) {
                $flags[] = substr($argument, 2);
            } elseif (in_array($argument, self::OPTIONS, true)) {
                $options[substr($argument, 2)] = array_shift($arguments) ?? throw new RuntimeException($argument . ' needs a value.');
            } elseif (str_starts_with($argument, '--')) {
                throw new RuntimeException(sprintf('Unknown option %s.', $argument));
            } else {
                $positional[] = $argument;
            }
        }

        return new self($flags, $options, $positional, []);
    }

    public function has(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }
}
