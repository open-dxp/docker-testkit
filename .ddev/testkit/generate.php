<?php

declare(strict_types=1);

namespace Testkit;

use RuntimeException;
use Throwable;

require __DIR__ . '/src/Configuration.php';
require __DIR__ . '/src/GeneratedFiles.php';
require __DIR__ . '/src/ComposerSymlink.php';

const LABELS = [
    'com.ddev.site-name' => '${DDEV_SITENAME}',
    'com.ddev.approot' => '${DDEV_APPROOT}',
];

/**
 * @return array<string, string> file name => content
 */
function serviceComposeFiles(Configuration $configuration): array
{
    $composeFiles = [];

    foreach ($configuration->services as $service) {
        $template = __DIR__ . '/services/' . $service . '.yaml';

        if (!is_file($template)) {
            $offeredServices = array_map(static fn (string $path): string => basename($path, '.yaml'), glob(__DIR__ . '/services/*.yaml') ?: []);

            throw new RuntimeException(sprintf('There is no service called %s. The testkit offers %s.', $service, implode(', ', $offeredServices)));
        }

        $composeFiles['docker-compose.' . $service . '.yaml'] = (string) file_get_contents($template);
    }

    return $composeFiles;
}

/**
 * @return array<string, string> file name => content
 */
function databaseComposeFiles(Configuration $configuration): array
{
    $composeFiles = [];

    foreach ($configuration->databaseServers as $server => $version) {
        $composeFiles['docker-compose.' . $server . '.yaml'] = composeFile([
            $server => [
                'image' => $server . ':' . $version,
                'container_name' => 'ddev-${DDEV_SITENAME}-' . $server,
                'labels' => LABELS,
                'environment' => ['MYSQL_ROOT_PASSWORD' => 'root'],
            ],
        ]);
    }

    return $composeFiles;
}

function webComposeFile(Configuration $configuration): string
{
    $web = [
        'mem_limit' => $configuration->memory,
        'memswap_limit' => $configuration->memory,
    ];

    foreach ($configuration->composerSymlinks as $package => $hostPath) {
        $web['volumes'][] = [
            'type' => 'bind',
            'source' => $hostPath,
            'target' => (new ComposerSymlink($package, $hostPath))->mountPoint(),
            'read_only' => true,
        ];
    }

    return composeFile(['web' => $web]);
}

/**
 * @param array<string, mixed> $services
 */
function composeFile(array $services): string
{
    $yaml = yaml_emit(['services' => $services], YAML_UTF8_ENCODING);

    return substr($yaml, strlen("---\n"), -strlen("...\n"));
}

try {
    $ddevDirectory = dirname(__DIR__);
    $configuration = Configuration::load($ddevDirectory);

    $notices = (new GeneratedFiles($ddevDirectory))->replaceWith([
        ...serviceComposeFiles($configuration),
        ...databaseComposeFiles($configuration),
        'docker-compose.testkit.yaml' => webComposeFile($configuration),
    ]);

    foreach ($notices as $notice) {
        fwrite(STDERR, $notice . "\n");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");

    exit(1);
}
