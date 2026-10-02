<?php

declare(strict_types=1);

namespace Testkit;

use RuntimeException;

final class ProcessRunner
{
    /**
     * Prints the output only when the command fails.
     *
     * @param list<string>          $command
     * @param array<string, string> $environment
     */
    public static function mustRun(string $description, array $command, string $workingDirectory, array $environment = []): void
    {
        echo $description, "\n";

        $log = tmpfile();
        $exitCode = self::execute($command, $workingDirectory, $environment, $log);
        rewind($log);
        $output = (string) stream_get_contents($log);
        fclose($log);

        if ($exitCode !== 0) {
            echo $output;

            throw new RuntimeException(sprintf('%s failed.', $description));
        }
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     */
    public static function run(array $command, string $workingDirectory, array $environment = []): int
    {
        return self::execute($command, $workingDirectory, $environment, STDOUT);
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     * @param resource              $output
     */
    private static function execute(array $command, string $workingDirectory, array $environment, mixed $output): int
    {
        // proc_open drops a variable with an empty value from a map, but not from a list of pairs.
        $variables = [];

        foreach ([...getenv(), ...$environment] as $name => $value) {
            $variables[] = $name . '=' . $value;
        }

        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes, $workingDirectory, $variables); // @phpstan-ignore argument.type

        if ($process === false) {
            throw new RuntimeException(sprintf('%s could not be started.', $command[0]));
        }

        return proc_close($process);
    }
}
