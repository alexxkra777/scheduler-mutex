<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Everything except the backend adapters must stay free of Laravel/crunz types.
 * Cheap text check over the sources - good enough to catch an accidental `use`.
 */
final class NoFrameworkLeakTest extends TestCase
{
    private const NEUTRAL_DIRS = [
        'src/Contract',
        'src/Bootstrap',
        'src/Cron',
        'src/Execution',
        'src/Infrastructure/Mutex',
        'examples/tasks',
        'examples/app',
    ];

    /** @return iterable<string, array{string}> */
    public static function neutralFiles(): iterable
    {
        $root = dirname(__DIR__, 2);
        foreach (self::NEUTRAL_DIRS as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    yield substr($file->getPathname(), strlen($root) + 1) => [$file->getPathname()];
                }
            }
        }
    }

    #[DataProvider('neutralFiles')]
    public function testNoFrameworkTypes(string $path): void
    {
        $code = (string) file_get_contents($path);

        // our own SchedulerTest\Cron namespace is fine, the vendor Cron\ one is not
        self::assertDoesNotMatchRegularExpression('/(?<!SchedulerTest\\\\)\b(Illuminate|Laravel|Crunz|Carbon|Cron|Symfony)\\\\/', $code);
    }

    public function testContractDirectoryIsScanned(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src/Contract');
    }
}
