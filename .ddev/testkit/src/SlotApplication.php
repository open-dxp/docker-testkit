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
    private const string INSTALLATION_DUMP = '/.installation.sql';
    private const string INSTALLATION_CHECKSUM = '/.installation.checksum';

    // Each slot answers on names of its own below the wildcard that .ddev/config.yaml registers: one for the
    // application, one ending in "-api" for an application that serves its API on a host of its own, and one with a
    // port of its own for a frontend.
    private const string HOST = 'slot-%d.testkit.ddev.site';
    private const string API_HOST = 'slot-%d-api.testkit.ddev.site';
    private const string WEB_HOST = 'slot-%d-web.testkit.ddev.site';
    private const int WEB_PORT = 3000;
    private const string SITE = '/etc/apache2/sites-enabled/testkit-slot-%d.conf';
    // Beside the slot, because a project is copied into the slot with --delete.
    private const string FRONTEND = '/var/www/html/app/.frontends/slot-%d';
    private const string BROWSERS = '/var/www/html/app/.playwright';
    private const string WEB_PHP_SOCKET = '/run/php/php-fpm.sock';
    private const string PHP_POOL = '/var/www/html/app/.php-fpm/php%s';

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
        return $this->target->applicationDirectoryIn($this->slot->path);
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
        return [...$this->target->readWebEnvironment(), ...$this->slotEnvironment()];
    }

    /**
     * Adds the values of testkit.yaml for the variables the target declares. A variable without a value stays unset.
     *
     * @return array<string, string>
     */
    public function testEnvironment(): array
    {
        $declaredValues = array_intersect_key(
            $this->configuration->environment,
            array_flip($this->target->readEnvironmentNames()),
        );

        return [...$this->target->readWebEnvironment(), ...$declaredValues, ...$this->slotEnvironment()];
    }

    /**
     * @return list<string>
     */
    public function phpCommand(string ...$arguments): array
    {
        return ['php' . $this->phpVersion, ...array_values($arguments)];
    }

    /**
     * Answers requests for the slot with its test kernel, its database, its environment and its PHP version, until the
     * container restarts. The host of the frontend is passed on to a frontend the slot runs.
     *
     * @return string the address of the slot
     */
    public function serve(): string
    {
        $host = sprintf(self::HOST, $this->slot->number);
        $root = $this->applicationDirectory() . '/public';
        // A request runs the kernel of the tests in this application, as the console of the tests does. The packages
        // lie behind symlinks, so the root is named rather than found.
        $served = [
            ...$this->testEnvironment(),
            'OPENDXP_PROJECT_ROOT' => $this->applicationDirectory(),
            'OPENDXP_KERNEL_CLASS' => $this->readKernelClass(),
            // Mails of a served slot land in the Mailpit of the container, where a frontend suite can read them.
            'MAILER_DSN' => 'smtp://localhost:1025',
        ];
        $environment = implode('', array_map(
            static fn (string $name, string $value): string => sprintf("    SetEnv %s \"%s\"\n", $name, addcslashes($value, '"\\')),
            array_keys($served),
            $served,
        ));

        $backend = self::buildSite(
            sprintf("    ServerName %s\n    ServerAlias %s\n    DocumentRoot %s\n", $host, sprintf(self::API_HOST, $this->slot->number), $root)
            . sprintf("    <Directory \"%s/\">\n        AllowOverride All\n        Require all granted\n        FallbackResource /index.php\n    </Directory>\n", $root)
            // The web server hands PHP to its own version inside an <If>, which Apache applies last. An <If> of the
            // host comes after it.
            . sprintf("    <FilesMatch \"\\.php$\">\n        <If \"-f %%{REQUEST_FILENAME}\">\n            SetHandler \"proxy:unix:%s|fcgi://localhost\"\n        </If>\n    </FilesMatch>\n", $this->startPhp())
            . "    SetEnvIf X-Forwarded-Proto \"https\" HTTPS=on\n" . $environment,
        );
        $frontend = self::buildSite(sprintf(
            "    ServerName %s\n    ProxyPreserveHost On\n    ProxyPass / http://localhost:%d/\n    ProxyPassReverse / http://localhost:%d/\n",
            sprintf(self::WEB_HOST, $this->slot->number),
            $this->webPort(),
            $this->webPort(),
        ));

        file_put_contents(sprintf(self::SITE, $this->slot->number), $backend . $frontend);
        ProcessRunner::mustRun('Reloading the web server', ['sudo', 'sh', '-c', 'a2enmod -q proxy_http && apache2ctl graceful'], '/');

        return 'https://' . $host;
    }

    /**
     * Copies the frontend next to the slot and installs its packages and its browser, each only when it changed.
     *
     * @return string the directory the frontend runs in
     */
    public function prepareFrontend(string $sourceDirectory): string
    {
        $directory = sprintf(self::FRONTEND, $this->slot->number);
        ProcessRunner::mustRun('Copying the frontend', [
            'rsync', '-a', '--delete', '--exclude=/node_modules', '--exclude=/.next*', '--exclude=/.lock-hash',
            $sourceDirectory . '/', $directory . '/',
        ], '/');

        $lockHash = hash_file('sha256', $directory . '/package-lock.json') ?: '';

        if (@file_get_contents($directory . '/.lock-hash') !== $lockHash) {
            ProcessRunner::mustRun('Installing the packages of the frontend', ['npm', 'ci', '--no-audit', '--no-fund'], $directory);
            file_put_contents($directory . '/.lock-hash', $lockHash);
        }

        ProcessRunner::mustRun('Installing the browser of the frontend', ['npx', 'playwright', 'install', 'chromium'], $directory, $this->frontendEnvironment());

        return $directory;
    }

    /**
     * What a frontend needs to find the slot: the API, its own host and port, and the browser the testkit keeps.
     *
     * @return array<string, string>
     */
    public function frontendEnvironment(): array
    {
        return [
            'API_BASE_URI' => 'https://' . sprintf(self::API_HOST, $this->slot->number),
            'E2E_BASE_URL' => 'https://' . sprintf(self::WEB_HOST, $this->slot->number),
            'E2E_PORT' => (string) $this->webPort(),
            'NEXT_DIST_DIR' => '.next-e2e',
            'MAILPIT_API' => 'http://localhost:8025/api/v1',
            // The router of ddev signs with the mkcert root, which node does not know on its own.
            'NODE_EXTRA_CA_CERTS' => '/etc/ssl/certs/ca-certificates.crt',
            'PLAYWRIGHT_BROWSERS_PATH' => self::BROWSERS,
        ];
    }

    /**
     * The web server runs the PHP of the container. Another version gets a process of its own, shared by every slot
     * of that version.
     *
     * @return string the socket that runs the PHP of the slot
     */
    private function startPhp(): string
    {
        if ($this->phpVersion === PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) {
            return self::WEB_PHP_SOCKET;
        }

        $pool = sprintf(self::PHP_POOL, $this->phpVersion);
        $pid = (int) @file_get_contents($pool . '.pid');

        if ($pid === 0 || !posix_kill($pid, 0)) {
            @mkdir(dirname($pool), 0777, true);
            file_put_contents($pool . '.conf', sprintf(
                "[global]\npid = %1\$s.pid\nerror_log = /proc/self/fd/2\n\n[slots]\nlisten = %1\$s.sock\nlisten.mode = 0666\n"
                . "pm = ondemand\npm.max_children = 8\nclear_env = no\n",
                $pool,
            ));
            ProcessRunner::mustRun('Starting PHP ' . $this->phpVersion, ['php-fpm' . $this->phpVersion, '--fpm-config', $pool . '.conf'], '/');
        }

        return $pool . '.sock';
    }

    private function readKernelClass(): string
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $file) {
            $configuration = @simplexml_load_file($this->applicationDirectory() . '/' . $file);
            $kernelClass = $configuration === false ? [] : $configuration->xpath('/phpunit/php/*[@name="KERNEL_CLASS"]/@value');

            if (($kernelClass[0] ?? null) !== null) {
                return (string) $kernelClass[0];
            }
        }

        throw new \RuntimeException('The phpunit.xml of the application names no KERNEL_CLASS.');
    }

    private function webPort(): int
    {
        return self::WEB_PORT + $this->slot->number;
    }

    private static function buildSite(string $body): string
    {
        $site = '';

        foreach (['80' => '', '443' => "    SSLEngine on\n    SSLCertificateFile /etc/ssl/certs/master.crt\n    SSLCertificateKeyFile /etc/ssl/certs/master.key\n"] as $port => $tls) {
            $site .= "<VirtualHost *:{$port}>\n{$tls}{$body}    ErrorLog /dev/stdout\n</VirtualHost>\n";
        }

        return $site;
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

    /**
     * A slot without a dump holds a database of unknown state, so it is built again.
     */
    public function buildOrUpdate(bool $fresh, bool $tookOverSlot): void
    {
        $fingerprint = $this->computeFingerprint();
        $fingerprintFile = $this->slot->path . '/.fingerprint';
        $isOutdated = !is_file($fingerprintFile) || file_get_contents($fingerprintFile) !== $fingerprint;

        if ($fresh || $tookOverSlot || $isOutdated || !is_file($this->slot->path . self::INSTALLATION_DUMP)) {
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

        ProcessRunner::mustRun('Saving the installed database', [
            'mysqldump', '--no-defaults', '--host=' . $this->databaseServer, '--user=root', '--password=root',
            '--routines', '--result-file=' . $this->slot->path . self::INSTALLATION_DUMP, $this->slot->databaseName,
        ], '/');
    }

    /**
     * Every test run starts from the database as the installation left it, as in CI.
     */
    public function restoreInstalledDatabase(): void
    {
        // A run rolls its tests back, so the database is mostly as installed, and checking it costs far less than a restore.
        if ($this->readChecksum() === @file_get_contents($this->slot->path . self::INSTALLATION_CHECKSUM)) {
            return;
        }

        // The client knows USE and SOURCE only at the start of a line. Without the abort, a failed SOURCE exits with 0.
        $statements = sprintf(
            "DROP DATABASE IF EXISTS %1\$s;\n%2\$s;\nUSE %1\$s;\nSOURCE %3\$s",
            $this->slot->databaseName,
            $this->createDatabaseStatement(),
            $this->slot->path . self::INSTALLATION_DUMP,
        );

        ProcessRunner::mustRun(
            'Restoring the installed database',
            [...self::mysqlCommand($this->databaseServer, $statements), '--abort-source-on-error'],
            '/',
        );
        file_put_contents($this->slot->path . self::INSTALLATION_CHECKSUM, $this->readChecksum());
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
        $application = $this->target->applicationPath === '' ? '' : '/' . $this->target->applicationPath;

        ProcessRunner::mustRun('Copying the project', [
            'rsync', '-a', '--delete',
            '--filter=P ' . $application . '/vendor/***',
            '--filter=P ' . $application . '/var/***',
            '--filter=P ' . $application . '/public/bundles/***',
            '--filter=P ' . $application . '/.env',
            '--filter=P /.fingerprint',
            '--filter=P ' . self::INSTALLATION_DUMP,
            '--filter=P ' . self::INSTALLATION_CHECKSUM,
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
            self::mysqlCommand($this->databaseServer, $this->createDatabaseStatement()),
            '/',
        );
    }

    /**
     * Sums the content of every table, so a run that left the database as installed is recognised.
     */
    private function readChecksum(): string
    {
        $tables = ProcessRunner::read('Listing the tables', [
            ...self::mysqlCommand($this->databaseServer, 'SHOW TABLES'), '--skip-column-names', $this->slot->databaseName,
        ], '/');
        $names = array_filter(explode("\n", trim($tables)));

        if ($names === []) {
            return '';
        }

        $checksums = ProcessRunner::read('Checking the database', [
            ...self::mysqlCommand($this->databaseServer, 'CHECKSUM TABLE `' . implode('`, `', $names) . '`'),
            '--skip-column-names',
            $this->slot->databaseName,
        ], '/');

        return hash('xxh128', $checksums);
    }

    private function createDatabaseStatement(): string
    {
        return 'CREATE DATABASE ' . $this->slot->databaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /**
     * @return list<string>
     */
    public static function mysqlCommand(string $server, string $statement): array
    {
        return ['mysql', '--no-defaults', '--host=' . $server, '--user=root', '--password=root', '--execute=' . $statement];
    }

    /**
     * @return array<string, string>
     */
    private function slotEnvironment(): array
    {
        return [
            'APP_ENV' => 'test',
            'DATABASE_URL' => sprintf('mysql://root:root@%s:3306/%s', $this->databaseServer, $this->slot->databaseName),
            'DATABASE_SERVER_VERSION' => $this->databaseServerVersion(),
            'TMPDIR' => $this->slot->path . '/.tmp',
            // The host a frontend of the slot answers on, for a story that sets up a site for it.
            'E2E_HOST' => sprintf(self::WEB_HOST, $this->slot->number),
        ];
    }

    /**
     * Doctrine needs the patch number of a server version, and a MariaDB version as the server reports it.
     */
    private function databaseServerVersion(): string
    {
        $version = $this->configuration->versionOfDatabaseServer($this->databaseServer);
        $version = substr_count($version, '.') === 1 ? $version . '.0' : $version;

        return $this->databaseServer === 'mariadb' ? $version . '-MariaDB' : $version;
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
