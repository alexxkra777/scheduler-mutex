<?php

declare(strict_types=1);

namespace SchedulerTest\Examples;

/**
 * Tiny helper so the demo tasks leave visible traces. One line per event,
 * appended with an exclusive lock so parallel processes don't mix lines.
 */
final class OutputFile
{
    public function __construct(private readonly string $path)
    {
    }

    public function write(string $line): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create directory %s', $dir));
        }

        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vP');
        $written = file_put_contents($this->path, sprintf("%s pid=%d %s\n", $stamp, getmypid(), $line), FILE_APPEND | LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException(sprintf('Cannot write to %s', $this->path));
        }
    }
}
