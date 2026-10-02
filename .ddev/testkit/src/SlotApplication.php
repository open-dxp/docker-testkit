<?php

declare(strict_types=1);

namespace Testkit;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class SlotApplication
{
    private const string COMPOSER = '/usr/local/bin/composer';

    public function __construct(
        private Configuration $configuration,
        private Target $target,
        private Slot $slot,
        private string $phpVersion,
        private string $databaseServer,
    ) {
    }

    public function applicationDirectory(): string
    {
        return $this->target->isProject
            ? $this->slot->path . '/' . $this->target->applicationPath
            : $this->slot->path;
    }

    public function packageDirectory(): string
    {
        return $this->target->isProject
            ? $this->slot->path
            : $this->slot->path . '/vendor/' . $this->target->package;
    }

    /**
     * The target's own variables come first, so the testkit's database and environment win.
     *
     * @return array<string, string>
     */
    public function runEnvironment(): array
    {
        return [
            ...$this->target->readWebEnvironment(),
            'APP_ENV' => 'test',
            'DATABASE_URL' => sprintf('mysql://root:root@%s:3306/%s', $this->databaseServer, $this->slot->databaseName),
            'DATABASE_SERVER_VERSION' => $this->databaseServerVersion(),
            'TMPDIR' => $this->slot->path . '/.tmp',
        ];
    }

    /**
     * @return list<string>
     */
    public function phpCommand(string ...$arguments): array
    {
        return ['php' . $this->phpVersion, ...array_values($arguments)];
    }

    /**
     * A bundle's baseline already lands in the source copy through the symlink in vendor. A project's lands in the slot.
     */
    public function copyBaselinesToSource(int $writtenSince): void
    {
        if (!$this->target->isProject) {
            return;
        }

        foreach ($this->findFilesOutsideVendor($this->slot->path, 'phpstan-baseline.neon') as $baseline) {
            if (filemtime($baseline) >= $writtenSince) {
                copy($baseline, $this->target->sourceDirectory . substr($baseline, strlen($this->slot->path)));
            }
        }
    }

    public function buildOrUpdate(bool $fresh, bool $tookOverSlot): void
    {
        $fingerprint = $this->computeFingerprint();
        $fingerprintFile = $this->slot->path . '/.fingerprint';

        if ($fresh || $tookOverSlot || !is_file($fingerprintFile) || file_get_contents($fingerprintFile) !== $fingerprint) {
            $this->build();
            file_put_contents($fingerprintFile, $fingerprint);

            return;
        }

        $this->copyChangedFiles();
    }

    private function build(): void
    {
        printf("Building slot %d for %s with PHP %s and %s\n", $this->slot->number, $this->target->package, $this->phpVersion, $this->databaseServer);

        ProcessRunner::mustRun('Emptying the slot', ['rm', '-rf', $this->slot->path], '/');
        mkdir($this->slot->path . '/.tmp', 0777, true);
        $this->recreateDatabase();

        $this->target->isProject ? $this->buildProject() : $this->buildBundle();

        ProcessRunner::mustRun('Installing OpenDXP', $this->phpCommand('vendor/bin/opendxp-test', 'install'), $this->applicationDirectory(), $this->runEnvironment());
    }

    private function buildBundle(): void
    {
        // The copy of the target comes first, so it wins over a linked checkout or a release of the same package.
        $repositories = [
            ComposerSymlink::pathRepository($this->target->package, $this->target->sourceDirectory, $this->target->newestTag),
            ...array_map(static fn (ComposerSymlink $symlink): array => $symlink->repository(), $this->loadComposerSymlinks()),
        ];

        if ($this->configuration->registry !== null) {
            $repositories[] = ['type' => 'composer', 'url' => $this->configuration->registry];
        }

        file_put_contents($this->slot->path . '/composer.json', json_encode([
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'repositories' => $repositories,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        ProcessRunner::mustRun(
            'Installing the test foundation',
            $this->phpCommand(self::COMPOSER, 'require', '--no-interaction', '--no-progress', '--no-plugins', '--no-scripts', 'open-dxp/test-foundation:' . $this->target->foundationConstraint()),
            $this->slot->path,
        );

        ProcessRunner::mustRun(
            'Building the application around ' . $this->target->package,
            $this->phpCommand('vendor/bin/opendxp-test', 'bundle', $this->target->sourceDirectory),
            $this->slot->path,
            $this->runEnvironment(),
        );
    }

    private function buildProject(): void
    {
        $this->copyProject();
        $applicationDirectory = $this->applicationDirectory();
        $symlinks = $this->lockedComposerSymlinks($applicationDirectory . '/composer.lock');

        if ($symlinks === []) {
            ProcessRunner::mustRun('Installing the dependencies', $this->phpCommand(self::COMPOSER, 'install', '--no-interaction', '--no-progress', '--no-scripts'), $applicationDirectory);

            return;
        }

        foreach ($symlinks as $symlink) {
            ProcessRunner::mustRun(
                'Linking ' . $symlink->package,
                $this->phpCommand(self::COMPOSER, 'config', 'repositories.' . str_replace('/', '-', $symlink->package), json_encode($symlink->repository(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                $applicationDirectory,
            );
        }

        $linkedPackages = array_map(static fn (ComposerSymlink $symlink): string => $symlink->package, $symlinks);

        ProcessRunner::mustRun(
            'Installing the dependencies',
            $this->phpCommand(self::COMPOSER, 'update', '--no-interaction', '--no-progress', '--no-scripts', '--with-all-dependencies', ...$linkedPackages),
            $applicationDirectory,
        );
    }

    /**
     * A bundle is linked into vendor, so only its tests are copied. A project is copied as a whole.
     */
    private function copyChangedFiles(): void
    {
        if ($this->target->isProject) {
            $this->copyProject();

            return;
        }

        ProcessRunner::mustRun('Copying the tests', ['rsync', '-a', '--delete', $this->target->sourceDirectory . '/tests/', $this->slot->path . '/tests/'], '/');
        copy($this->target->sourceDirectory . '/phpunit.xml.dist', $this->slot->path . '/phpunit.xml');
    }

    /**
     * What the build generated stays: a P rule without *** would protect the directory but not its files.
     */
    private function copyProject(): void
    {
        $application = '/' . $this->target->applicationPath;

        ProcessRunner::mustRun('Copying the project', [
            'rsync', '-a', '--delete',
            '--filter=P ' . $application . '/vendor/***',
            '--filter=P ' . $application . '/var/***',
            '--filter=P ' . $application . '/public/bundles/***',
            '--filter=P ' . $application . '/.env',
            '--filter=P /.fingerprint',
            '--filter=P /.tmp/***',
            $this->target->sourceDirectory . '/', $this->slot->path . '/',
        ], '/');
    }

    /**
     * The slot may have used another database server before, so its database is dropped on every server.
     */
    private function recreateDatabase(): void
    {
        foreach (array_keys($this->configuration->databaseServers) as $server) {
            ProcessRunner::mustRun('Dropping the database on ' . $server, self::mysqlCommand($server, 'DROP DATABASE IF EXISTS ' . $this->slot->databaseName), '/');
        }

        ProcessRunner::mustRun(
            'Creating the database',
            self::mysqlCommand($this->databaseServer, 'CREATE DATABASE ' . $this->slot->databaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),
            '/',
        );
    }

    /**
     * @return list<string>
     */
    public static function mysqlCommand(string $server, string $statement): array
    {
        return ['mysql', '--no-defaults', '--host=' . $server, '--user=root', '--password=root', '--execute=' . $statement];
    }

    /**
     * Doctrine accepts a server version only with its patch number, and MariaDB only with its prefix.
     */
    private function databaseServerVersion(): string
    {
        $version = $this->configuration->versionOfDatabaseServer($this->databaseServer);
        $version = substr_count($version, '.') === 1 ? $version . '.0' : $version;

        return $this->databaseServer === 'mariadb' ? 'mariadb-' . $version : $version;
    }

    private function computeFingerprint(): string
    {
        $inputs = [
            $this->phpVersion,
            $this->databaseServer,
            $this->configuration->versionOfDatabaseServer($this->databaseServer),
            (string) $this->configuration->registry,
        ];

        foreach ($this->target->fingerprintFiles() as $file) {
            $inputs[] = is_file($file) ? (string) file_get_contents($file) : '';
        }

        foreach ($this->loadComposerSymlinks() as $symlink) {
            $inputs[] = $symlink->package . (string) file_get_contents($symlink->mountPoint() . '/composer.json');
        }

        return hash('sha256', implode("\0", $inputs));
    }

    /**
     * @return list<string>
     */
    private function findFilesOutsideVendor(string $directory, string $fileName): array
    {
        $found = [];
        $entries = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $entry): bool => !in_array($entry->getFilename(), ['vendor', 'var', 'node_modules'], true),
        ));

        foreach ($entries as $entry) {
            if ($entry->getFilename() === $fileName) {
                $found[] = $entry->getPathname();
            }
        }

        return $found;
    }

    /**
     * @return list<ComposerSymlink>
     */
    private function loadComposerSymlinks(): array
    {
        $symlinks = [];

        foreach ($this->configuration->composerSymlinks as $package => $hostPath) {
            $symlink = new ComposerSymlink($package, $hostPath);
            $symlink->assertMounted();
            $symlinks[] = $symlink;
        }

        return $symlinks;
    }

    /**
     * Only a package the project has locked can be linked, because composer update refuses any other.
     *
     * @return list<ComposerSymlink>
     */
    private function lockedComposerSymlinks(string $lockFile): array
    {
        $lock = json_decode((string) file_get_contents($lockFile), true, flags: JSON_THROW_ON_ERROR);
        $lockedPackages = array_column([...$lock['packages'] ?? [], ...$lock['packages-dev'] ?? []], 'name');

        return array_values(array_filter(
            $this->loadComposerSymlinks(),
            static fn (ComposerSymlink $symlink): bool => in_array($symlink->package, $lockedPackages, true),
        ));
    }
}
