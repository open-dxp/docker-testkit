<?php

declare(strict_types=1);

namespace Testkit;

use RuntimeException;
use Throwable;

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/src/' . substr($class, strlen(__NAMESPACE__) + 1) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

const APPLICATIONS_DIRECTORY = '/var/www/html/app';

/**
 * @param callable(SlotApplication): int $command
 */
function runInSlot(Configuration $configuration, Slots $slots, string $key, Arguments $arguments, callable $command): int
{
    $phpVersion = $arguments->options['php'] ?? $configuration->defaultPhpVersion;
    $databaseServer = $arguments->options['db'] ?? $configuration->defaultDatabaseServer;
    $configuration->assertPhpVersion($phpVersion);
    $configuration->versionOfDatabaseServer($databaseServer);

    $target = Target::fromSource($key, APPLICATIONS_DIRECTORY . '/sources/' . $key, $arguments->options['tag'] ?? '');
    [$slot, $tookOverSlot] = $slots->claim(sprintf('%s|php%s|%s', $key, $phpVersion, $databaseServer));

    return $slots->runExclusively($slot, static function () use ($configuration, $target, $slot, $phpVersion, $databaseServer, $arguments, $tookOverSlot, $command): int {
        $application = new SlotApplication($configuration, $target, $slot, $phpVersion, $databaseServer);
        $application->buildOrUpdate($arguments->has('fresh'), $tookOverSlot);

        return $command($application);
    });
}

function runTests(Configuration $configuration, Slots $slots, string $key, Arguments $arguments): int
{
    $pestArguments = $arguments->passedOn;

    return runInSlot($configuration, $slots, $key, $arguments, static function (SlotApplication $application) use ($pestArguments): int {
        @mkdir($application->applicationDirectory() . '/var/report', 0777, true);

        return ProcessRunner::run(
            $application->phpCommand('vendor/bin/pest', '--log-junit', 'var/report/pest.junit.xml', ...$pestArguments),
            $application->applicationDirectory(),
            $application->runEnvironment(),
        );
    });
}

function runAnalysis(Configuration $configuration, Slots $slots, string $key, Arguments $arguments): int
{
    $check = $arguments->positional[1] ?? null;

    return runInSlot($configuration, $slots, $key, $arguments, static function (SlotApplication $application) use ($check): int {
        return ProcessRunner::run(
            $application->phpCommand('vendor/bin/opendxp-test', 'analyse', $application->packageDirectory(), ...($check !== null ? [$check] : [])),
            $application->applicationDirectory(),
            $application->runEnvironment(),
        );
    });
}

/**
 * Makes this process the leader of its own process group, so an abort reaches everything it started.
 */
function registerRun(Arguments $arguments): void
{
    $runId = $arguments->options['run'] ?? null;

    if ($runId === null) {
        return;
    }

    posix_setsid();
    $runFile = runFile($runId);
    file_put_contents($runFile, (string) getmypid());
    register_shutdown_function(static fn () => @unlink($runFile));
}

function abortRun(string $runId): int
{
    $processGroup = (int) @file_get_contents(runFile($runId));

    if ($processGroup < 1) {
        return 0;
    }

    posix_kill(-$processGroup, SIGTERM);

    for ($waited = 0; $waited < 50 && posix_kill(-$processGroup, 0); ++$waited) {
        usleep(100_000);
    }

    if (posix_kill(-$processGroup, 0)) {
        posix_kill(-$processGroup, SIGKILL);
    }

    @unlink(runFile($runId));

    return 0;
}

function runFile(string $runId): string
{
    return APPLICATIONS_DIRECTORY . '/.locks/run-' . basename($runId);
}

function printStatus(Configuration $configuration, Slots $slots): int
{
    printf("php        %s (default %s)\n", implode(', ', $configuration->phpVersions), $configuration->defaultPhpVersion);
    printf("databases  %s (default %s)\n", implode(', ', array_keys($configuration->databaseServers)), $configuration->defaultDatabaseServer);
    printf("services   %s\n", $configuration->services === [] ? 'none' : implode(', ', $configuration->services));
    printf("memory     %s\n\n", $configuration->memory);

    $occupiedSlots = $slots->occupiedSlots();

    for ($number = 1; $number <= $configuration->slots; ++$number) {
        $key = $occupiedSlots[$number] ?? null;
        $runningMark = $key !== null && $slots->isRunning($number) ? '  running' : '';
        printf("  slot %d  %s%s\n", $number, $key ?? 'free', $runningMark);
    }

    return 0;
}

function releaseSlots(Slots $slots, Arguments $arguments): int
{
    $key = $arguments->positional[0] ?? null;
    $releaseAll = $arguments->has('all');

    if ($key === null && !$releaseAll) {
        throw new RuntimeException('Name a path or pass --all.');
    }

    $releasedSlots = array_filter(
        $slots->occupiedSlots(),
        static fn (string $slotKey): bool => $releaseAll || str_starts_with($slotKey, $key . '|'),
    );
    $busyNumbers = array_values(array_filter(array_keys($releasedSlots), static fn (int $number): bool => $slots->isRunning($number)));

    if ($busyNumbers !== [] && $arguments->has('fail-if-busy')) {
        foreach ($busyNumbers as $number) {
            fprintf(STDERR, "slot %d is running %s\n", $number, $releasedSlots[$number]);
        }

        fwrite(STDERR, "Nothing was released.\n");

        return 1;
    }

    foreach ($releasedSlots as $number => $slotKey) {
        if (in_array($number, $busyNumbers, true)) {
            printf("slot %d is running and stays\n", $number);

            continue;
        }

        $slot = $slots->slotNumbered($number);
        $databaseServer = substr($slotKey, strrpos($slotKey, '|') + 1);

        ProcessRunner::mustRun(sprintf('Emptying slot %d (%s)', $number, $slotKey), ['rm', '-rf', $slot->path], '/');
        ProcessRunner::run(SlotApplication::mysqlCommand($databaseServer, 'DROP DATABASE IF EXISTS ' . $slot->databaseName), '/');
        $slots->release($number);
    }

    removeUnusedSources($slots);

    return 0;
}

function removeUnusedSources(Slots $slots): void
{
    $usedKeys = array_map(static fn (string $slotKey): string => explode('|', $slotKey)[0], $slots->occupiedSlots());

    foreach (glob(APPLICATIONS_DIRECTORY . '/sources/*', GLOB_ONLYDIR) ?: [] as $sourceDirectory) {
        if (!in_array(basename($sourceDirectory), $usedKeys, true)) {
            ProcessRunner::mustRun('Removing the copy of ' . basename($sourceDirectory), ['rm', '-rf', $sourceDirectory], '/');
        }
    }
}

try {
    $configuration = Configuration::load(dirname(__DIR__));
    $slots = new Slots(APPLICATIONS_DIRECTORY, $configuration->slots);
    $arguments = Arguments::parse(array_slice($argv, 2));
    $key = $arguments->positional[0] ?? null;

    if (in_array($argv[1] ?? null, ['test', 'analyse'], true)) {
        registerRun($arguments);
    }

    exit(match ($argv[1] ?? null) {
        'test' => runTests($configuration, $slots, $key ?? throw new RuntimeException('Name a target.'), $arguments),
        'analyse' => runAnalysis($configuration, $slots, $key ?? throw new RuntimeException('Name a target.'), $arguments),
        'status' => printStatus($configuration, $slots),
        'release' => releaseSlots($slots, $arguments),
        'abort' => abortRun($key ?? throw new RuntimeException('Name a run.')),
        default => throw new RuntimeException('Commands: test, analyse, status, release.'),
    });
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");

    exit(1);
}
