<?php

declare(strict_types=1);

namespace Testkit;

use RuntimeException;

final readonly class Target
{
    private const array PROJECT_APPLICATION_PATHS = ['app', 'backend/app'];

    private function __construct(
        public string $key,
        public string $sourceDirectory,
        public string $package,
        public bool $isProject,
        public string $applicationPath,
        public string $newestTag,
    ) {
    }

    public static function fromSource(string $key, string $sourceDirectory, string $newestTag): self
    {
        if (!is_dir($sourceDirectory)) {
            throw new RuntimeException(sprintf('%s was not synchronised into the testkit.', $key));
        }

        $rootManifest = $sourceDirectory . '/composer.json';

        if (is_file($rootManifest) && (self::readJson($rootManifest)['type'] ?? null) !== 'project') {
            return new self($key, $sourceDirectory, self::readJson($rootManifest)['name'], false, '', $newestTag);
        }

        foreach (self::PROJECT_APPLICATION_PATHS as $applicationPath) {
            $applicationManifest = $sourceDirectory . '/' . $applicationPath . '/composer.json';

            if (is_file($applicationManifest)) {
                return new self($key, $sourceDirectory, self::readJson($applicationManifest)['name'], true, $applicationPath, $newestTag);
            }
        }

        throw new RuntimeException(sprintf('%s is neither a bundle nor a project.', $key));
    }

    public function manifestPath(): string
    {
        return $this->isProject
            ? $this->sourceDirectory . '/' . $this->applicationPath . '/composer.json'
            : $this->sourceDirectory . '/composer.json';
    }

    public function foundationConstraint(): string
    {
        // The foundation under test is the copy in the first repository, which hides every release.
        if ($this->package === 'open-dxp/test-foundation') {
            return '*';
        }

        return self::readJson($this->manifestPath())['require-dev']['open-dxp/test-foundation']
            ?? throw new RuntimeException(sprintf('%s does not require open-dxp/test-foundation in require-dev.', $this->package));
    }

    /**
     * @return list<string>
     */
    public function fingerprintFiles(): array
    {
        if (!$this->isProject) {
            return [$this->manifestPath()];
        }

        $applicationDirectory = $this->sourceDirectory . '/' . $this->applicationPath;

        return [
            $this->manifestPath(),
            $applicationDirectory . '/composer.lock',
            ...glob($applicationDirectory . '/var/classes/definition_*.php') ?: [],
        ];
    }

    /**
     * A project on ddev keeps its local variables under web_environment in .ddev/config.yaml.
     *
     * @return array<string, string>
     */
    public function readWebEnvironment(): array
    {
        $ddevConfiguration = $this->sourceDirectory . '/.ddev/config.yaml';
        $declared = is_file($ddevConfiguration) ? yaml_parse_file($ddevConfiguration) : null;
        $variables = [];

        foreach ($declared['web_environment'] ?? [] as $entry) {
            [$name, $value] = explode('=', (string) $entry, 2) + [1 => null];

            if ($value !== null) {
                $variables[trim((string) $name)] = $value;
            }
        }

        return $variables;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $file): array
    {
        return json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }
}
