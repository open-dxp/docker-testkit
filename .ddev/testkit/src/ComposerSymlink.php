<?php

declare(strict_types=1);

namespace Testkit;

use RuntimeException;

final readonly class ComposerSymlink
{
    public function __construct(
        public string $package,
        public string $hostPath,
    ) {
    }

    public function mountPoint(): string
    {
        return '/mnt/composer-symlinks/' . $this->package;
    }

    public function assertMounted(): void
    {
        $manifest = $this->mountPoint() . '/composer.json';

        if (!is_file($manifest)) {
            throw new RuntimeException(sprintf('composer_symlinks: %s has no composer.json, or ddev was not restarted since it was added.', $this->hostPath));
        }

        $packageName = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR)['name'] ?? null;

        if ($packageName !== $this->package) {
            throw new RuntimeException(sprintf('composer_symlinks: %s holds %s, not %s.', $this->hostPath, $packageName ?? 'a package without a name', $this->package));
        }
    }

    /**
     * A branch has no version that a constraint such as ^1.3 accepts, so a checkout reports the major
     * version of its newest tag followed by .99.99. Without a tag, composer takes the branch.
     */
    public static function versionFromTag(string $tag): ?string
    {
        return preg_match('/(\d+)\./', $tag, $match) ? $match[1] . '.99.99' : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function pathRepository(string $package, string $directory, string $newestTag): array
    {
        $repository = ['type' => 'path', 'url' => $directory, 'options' => ['symlink' => true]];
        $version = self::versionFromTag($newestTag);

        if ($version !== null) {
            $repository['options']['versions'] = [$package => $version];
        }

        return $repository;
    }

    /**
     * @return array<string, mixed>
     */
    public function repository(): array
    {
        $newestTag = trim((string) shell_exec(sprintf('git -C %s describe --tags --abbrev=0 2>/dev/null', escapeshellarg($this->mountPoint()))));

        return self::pathRepository($this->package, $this->mountPoint(), $newestTag);
    }
}
