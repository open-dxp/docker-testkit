<?php

declare(strict_types=1);

namespace Testkit;

use RuntimeException;

final readonly class Configuration
{
    /**
     * @param list<string>          $phpVersions
     * @param array<string, string> $databaseServers  server name => version
     * @param array<string, string> $composerSymlinks package name => host path
     * @param list<string>          $services
     */
    private function __construct(
        public int $slots,
        public array $phpVersions,
        public string $defaultPhpVersion,
        public array $databaseServers,
        public string $defaultDatabaseServer,
        public ?string $registry,
        public array $composerSymlinks,
        public array $services,
        public string $memory,
    ) {
    }

    public static function load(string $ddevDirectory): self
    {
        $merged = self::readYaml($ddevDirectory . '/testkit.dist.yaml');

        if (is_file($ddevDirectory . '/testkit.yaml')) {
            $merged = self::mergeRecursively($merged, self::readYaml($ddevDirectory . '/testkit.yaml'));
        }

        $configuration = new self(
            (int) $merged['slots'],
            array_values(array_map('strval', $merged['php']['versions'])),
            (string) $merged['php']['default'],
            array_map('strval', $merged['databases']['servers']),
            (string) $merged['databases']['default'],
            $merged['registry'] ?? null,
            $merged['composer_symlinks'] ?? [],
            $merged['services'] ?? [],
            (string) $merged['memory'],
        );

        $configuration->assertPhpVersion($configuration->defaultPhpVersion);
        $configuration->versionOfDatabaseServer($configuration->defaultDatabaseServer);

        if ($configuration->slots < 1) {
            throw new RuntimeException('slots has to be at least 1.');
        }

        return $configuration;
    }

    public function versionOfDatabaseServer(string $server): string
    {
        return $this->databaseServers[$server]
            ?? throw new RuntimeException(sprintf('There is no database server called %s. testkit.yaml offers %s.', $server, implode(', ', array_keys($this->databaseServers))));
    }

    public function assertPhpVersion(string $version): void
    {
        if (!in_array($version, $this->phpVersions, true)) {
            throw new RuntimeException(sprintf('PHP %s is not offered. testkit.yaml offers %s.', $version, implode(', ', $this->phpVersions)));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function readYaml(string $file): array
    {
        $parsed = yaml_parse_file($file);

        if (!is_array($parsed)) {
            throw new RuntimeException(sprintf('%s is not valid YAML.', $file));
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function mergeRecursively(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $bothAreMaps = is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null);
            $base[$key] = $bothAreMaps ? self::mergeRecursively($base[$key], $value) : $value;
        }

        return $base;
    }
}
