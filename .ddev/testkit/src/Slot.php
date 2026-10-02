<?php

declare(strict_types=1);

namespace Testkit;

final readonly class Slot
{
    public function __construct(
        public int $number,
        public string $path,
        public string $databaseName,
    ) {
    }
}
