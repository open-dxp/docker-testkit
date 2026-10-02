<?php

declare(strict_types=1);

namespace Testkit;

use RuntimeException;

final readonly class Slots
{
    public function __construct(
        private string $applicationsDirectory,
        private int $count,
    ) {
        @mkdir($this->applicationsDirectory . '/.locks', 0777, true);
    }

    /**
     * Takes the slot this key used last, else a free one, else the idle one used longest ago.
     *
     * @return array{Slot, bool} the slot, and whether it held another key before
     */
    public function claim(string $key): array
    {
        return $this->withBookLock(function () use ($key): array {
            $book = $this->readBook();
            $number = array_search($key, array_column($book, 'key', 'number'), true);
            $tookOver = false;

            if ($number === false) {
                $freeNumbers = array_diff(range(1, $this->count), array_keys($book));
                $tookOver = $freeNumbers === [];
                $number = $tookOver ? $this->findLongestIdle($book) : min($freeNumbers);
            }

            $book[$number] = ['number' => $number, 'key' => $key, 'used' => time()];
            $this->writeBook($book);

            return [$this->slotNumbered((int) $number), $tookOver];
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function runExclusively(Slot $slot, callable $callback): mixed
    {
        $lock = self::openLockFile($this->slotLockFile($slot->number));
        flock($lock, LOCK_EX);

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function isRunning(int $number): bool
    {
        $lock = self::openLockFile($this->slotLockFile($number));
        $isFree = flock($lock, LOCK_EX | LOCK_NB);

        if ($isFree) {
            flock($lock, LOCK_UN);
        }

        fclose($lock);

        return !$isFree;
    }

    /**
     * @return array<int, string> slot number => key
     */
    public function occupiedSlots(): array
    {
        return array_column($this->readBook(), 'key', 'number');
    }

    public function release(int $number): void
    {
        $this->withBookLock(function () use ($number): void {
            $book = $this->readBook();
            unset($book[$number]);
            $this->writeBook($book);
        });
    }

    public function slotNumbered(int $number): Slot
    {
        return new Slot($number, $this->applicationsDirectory . '/slot-' . $number, 'slot' . $number);
    }

    /**
     * @param array<int, array{number: int, key: string, used: int}> $book
     */
    private function findLongestIdle(array $book): int
    {
        uasort($book, static fn (array $a, array $b): int => $a['used'] <=> $b['used']);

        foreach (array_keys($book) as $number) {
            if (!$this->isRunning($number)) {
                return $number;
            }
        }

        return array_key_first($book) ?? throw new RuntimeException('The book holds no slot.');
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withBookLock(callable $callback): mixed
    {
        $lock = self::openLockFile($this->applicationsDirectory . '/.locks/book');
        flock($lock, LOCK_EX);

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<int, array{number: int, key: string, used: int}>
     */
    private function readBook(): array
    {
        $bookFile = $this->applicationsDirectory . '/slots.json';

        if (!is_file($bookFile)) {
            return [];
        }

        return array_column(json_decode((string) file_get_contents($bookFile), true, flags: JSON_THROW_ON_ERROR), null, 'number');
    }

    /**
     * @param array<int, array{number: int, key: string, used: int}> $book
     */
    private function writeBook(array $book): void
    {
        ksort($book);
        file_put_contents($this->applicationsDirectory . '/slots.json', json_encode(array_values($book), JSON_PRETTY_PRINT) . "\n");
    }

    /**
     * @return resource
     */
    private static function openLockFile(string $path): mixed
    {
        return fopen($path, 'c') ?: throw new RuntimeException(sprintf('%s cannot be opened.', $path));
    }

    private function slotLockFile(int $number): string
    {
        return $this->applicationsDirectory . '/.locks/slot-' . $number;
    }
}
