<?php

declare(strict_types=1);

namespace Testkit;

final class GeneratedFiles
{
    private const string MARKER = '#testkit-generated sha256:';

    /** @var list<string> */
    private array $notices = [];

    public function __construct(
        private readonly string $ddevDirectory,
    ) {
    }

    /**
     * Writes the wanted files and deletes the generated ones no longer wanted.
     *
     * @param array<string, string> $wantedFiles file name => content
     *
     * @return list<string> notices for the developer
     */
    public function replaceWith(array $wantedFiles): array
    {
        foreach ($wantedFiles as $fileName => $content) {
            $this->write($fileName, $content);
        }

        foreach (glob($this->ddevDirectory . '/docker-compose.*.yaml') ?: [] as $path) {
            if (!isset($wantedFiles[basename($path)]) && self::isGenerated($path)) {
                $this->delete($path);
            }
        }

        return $this->notices;
    }

    private function write(string $fileName, string $content): void
    {
        $path = $this->ddevDirectory . '/' . $fileName;

        if (is_file($path) && !self::isUnchanged($path)) {
            $this->notices[] = sprintf('%s was changed by hand and is left as it is.', $fileName);

            return;
        }

        file_put_contents($path, self::MARKER . hash('sha256', $content) . "\n" . $content);
    }

    private function delete(string $path): void
    {
        if (!self::isUnchanged($path)) {
            $this->notices[] = sprintf('%s is no longer needed but was changed by hand. Delete it yourself.', basename($path));

            return;
        }

        unlink($path);
    }

    private static function isUnchanged(string $path): bool
    {
        [$firstLine, $body] = explode("\n", (string) file_get_contents($path), 2) + [1 => ''];

        return $firstLine === self::MARKER . hash('sha256', $body);
    }

    private static function isGenerated(string $path): bool
    {
        return str_starts_with((string) file_get_contents($path), self::MARKER);
    }
}
