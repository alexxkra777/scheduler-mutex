<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Mutex;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SchedulerTest\Contract\Exception\MutexException;
use SchedulerTest\Infrastructure\Mutex\FileLock;
use SchedulerTest\Infrastructure\Mutex\FileMutex;

/**
 * Cross-process claims are checked with real child processes (Fixtures/lock-holder.php).
 * Sync goes through files with bounded waits, never through plain sleeps.
 */
final class FileMutexTest extends TestCase
{
    private const TEMP_PREFIX = 'scheduler-test-mutex-';
    private const KEY = 'scheduler:app:test:task-1';
    private const WAIT_SECONDS = 5.0;

    private string $root;
    private string $lockDir;

    /** @var list<resource> */
    private array $processes = [];

    /** @var list<string> dirs that the test chmod'ed and tearDown must open again */
    private array $chmodded = [];

    /** @var list<resource> fork-child-holder.php runs; each leads its own process group */
    private array $forkHolders = [];

    private int $fileCounter = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/' . self::TEMP_PREFIX . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root, 0700));
        $this->lockDir = $this->root . '/locks';
    }

    protected function tearDown(): void
    {
        // pcntl_fork() children aren't known to proc_close(). The fork holder leads its own
        // process group, so killing the group while the holder lives takes them all down
        foreach ($this->forkHolders as $proc) {
            $status = proc_get_status($proc);
            if ($status['running']) {
                posix_kill(-$status['pid'], 9);
            }
        }
        $this->forkHolders = [];

        // kill and reap every child, even if the test failed half way
        foreach ($this->processes as $proc) {
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc, 9);
            }
            proc_close($proc);
        }
        $this->processes = [];

        foreach ($this->chmodded as $path) {
            @chmod($path, 0700);
        }
        self::removeTree($this->root);
    }

    // --- cross-process ---

    public function testOtherProcessGetsBusyWhileHeldAndAcquiresAfterRelease(): void
    {
        $release = $this->path('release');
        [$holder, $holderResult] = $this->startHolder(self::KEY, $release);
        self::assertSame('acquired', $this->waitForResult($holderResult));

        self::assertSame('busy', $this->runOnce(self::KEY));

        touch($release);
        $this->waitForExit($holder);

        self::assertSame('acquired', $this->runOnce(self::KEY));
    }

    public function testCurrentProcessGetsNullWhileOtherProcessHolds(): void
    {
        $release = $this->path('release');
        [$holder, $holderResult] = $this->startHolder(self::KEY, $release);
        self::assertSame('acquired', $this->waitForResult($holderResult));

        self::assertNull($this->mutex()->tryAcquire(self::KEY));

        touch($release);
        $this->waitForExit($holder);

        $lock = $this->mutex()->tryAcquire(self::KEY);
        self::assertInstanceOf(FileLock::class, $lock);
        $lock->release();
    }

    public function testLockIsFreedWhenHolderIsKilled(): void
    {
        // no release file - the holder would sit there until its own 30 s timeout
        [$holder, $holderResult] = $this->startHolder(self::KEY, $this->path('never'));
        self::assertSame('acquired', $this->waitForResult($holderResult));
        self::assertNull($this->mutex()->tryAcquire(self::KEY));

        self::assertTrue(proc_terminate($holder, 9));
        $status = $this->waitForExit($holder);
        self::assertTrue($status['signaled'], 'holder should die from the signal, not exit normally');
        self::assertSame(9, $status['termsig']);

        $lock = $this->mutex()->tryAcquire(self::KEY);
        self::assertInstanceOf(FileLock::class, $lock);
        $lock->release();
    }

    public function testLockIsFreedWhenKilledHolderLeftAChildProcessBehind(): void
    {
        // a task that runs an external command must not pass the lock descriptor on to it,
        // otherwise the orphaned child would keep the lock after the holder is killed
        $ready = $this->root . '/child.pid';
        $holder = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/holder-with-child.php', $this->lockDir, 'job:with-child', $ready],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($holder);

        $deadline = microtime(true) + 5.0;
        while (!is_file($ready) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        self::assertFileExists($ready, 'holder never reported its child');
        $childPid = (int) file_get_contents($ready);

        try {
            self::assertTrue(proc_terminate($holder, 9));
            $deadline = microtime(true) + 5.0;
            while (proc_get_status($holder)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFalse(proc_get_status($holder)['running']);

            $lock = (new FileMutex($this->lockDir))->tryAcquire('job:with-child');
            self::assertNotNull($lock, 'orphaned child process still holds the lock');
            $lock->release();
        } finally {
            if ($childPid > 0) {
                posix_kill($childPid, 9);
            }
            proc_close($holder);
        }
    }

    public function testForkedChildCannotFreeTheParentsLock(): void
    {
        // fork without exec inside a protected task is not supported, but a child that
        // touches the inherited handle must not take the lock away from the running parent
        if (!function_exists('pcntl_fork')) {
            self::fail('The pcntl extension is required for this test (pcntl_fork() is missing)');
        }

        $sync = $this->root . '/fork-sync';
        self::assertTrue(mkdir($sync, 0700));

        $log = $this->path('fork-holder-log');
        $holder = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/fork-child-holder.php', $this->lockDir, self::KEY, $sync],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        self::assertIsResource($holder, 'proc_open failed');
        $this->processes[] = $holder;
        $this->forkHolders[] = $holder;

        $ready = $this->waitForResult($sync . '/ready');
        self::assertMatchesRegularExpression('/\Aacquired \d+\z/', $ready, 'fork holder: ' . $ready);
        $parentPid = (int) substr($ready, strlen('acquired '));
        self::assertSame('busy', $this->runOnce(self::KEY));

        // (a) the child calls release() on the inherited handle
        touch($sync . '/release.go');
        $child = $this->waitForResult($sync . '/release.child');
        self::assertSame('exit 0', $this->waitForResult($sync . '/release.reaped'));
        self::assertSame('busy', $this->runOnce(self::KEY), 'release() in the forked child freed the parent\'s lock');
        $lines = explode("\n", $child);
        self::assertCount(3, $lines, $child);
        self::assertMatchesRegularExpression('/\Aparent=' . $parentPid . ' child=(\d+)\z/', $lines[0]);
        $childPid = (int) substr($lines[0], strrpos($lines[0], '=') + 1);
        self::assertNotSame($parentPid, $childPid);
        foreach ([$lines[1], $lines[2]] as $attempt) {
            self::assertStringStartsWith('exception: ', $attempt, 'release() in a forked child must throw MutexReleaseException');
            self::assertStringContainsString((string) $parentPid, $attempt);
            self::assertStringContainsString((string) $childPid, $attempt);
        }

        // (b) the child drops the handle, destructor runs, the child is still alive
        touch($sync . '/drop.go');
        self::assertStringEndsWith("\ndropped", $this->waitForResult($sync . '/drop.child'));
        self::assertSame('busy', $this->runOnce(self::KEY), 'destructor in the forked child freed the parent\'s lock');
        touch($sync . '/drop.continue');
        self::assertSame('exit 0', $this->waitForResult($sync . '/drop.reaped'));
        self::assertSame('busy', $this->runOnce(self::KEY));

        // (c) the child exits normally while it still has the handle
        touch($sync . '/exit.go');
        self::assertSame('exit 0', $this->waitForResult($sync . '/exit.reaped'));
        self::assertSame('busy', $this->runOnce(self::KEY), 'normal exit of the forked child freed the parent\'s lock');

        // the parent still owns it and can release it explicitly
        touch($sync . '/release');
        self::assertSame('released', $this->waitForResult($sync . '/released'));
        $status = $this->waitForExit($holder);
        self::assertSame(0, $status['exitcode']);

        self::assertSame('acquired', $this->runOnce(self::KEY));
    }

    public function testExactlyOneOfManySimultaneousProcessesWins(): void
    {
        $contenders = 8;

        for ($round = 1; $round <= 4; $round++) {
            $go = $this->path("go-$round");
            $release = $this->path("release-$round");

            $results = [];
            $procs = [];
            for ($i = 0; $i < $contenders; $i++) {
                $result = $this->path("race-$round-$i");
                $procs[] = $this->spawn([$this->lockDir, self::KEY, $result, $go, $release]);
                $results[] = $result;
            }

            // everybody is spinning on the go file before we fire
            foreach ($results as $result) {
                $this->waitForFile($result . '.ready');
            }
            touch($go);

            // the winner keeps holding until all results are in, so a late starter can't win too
            $outcomes = array_map(fn (string $r) => $this->waitForResult($r), $results);
            $counts = array_count_values($outcomes);
            self::assertSame(
                ['acquired' => 1, 'busy' => $contenders - 1],
                ['acquired' => $counts['acquired'] ?? 0, 'busy' => $counts['busy'] ?? 0],
                "round $round: " . implode(', ', $outcomes),
            );

            touch($release);
            foreach ($procs as $proc) {
                $this->waitForExit($proc);
            }
        }
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $release = $this->path('release');
        [$holder, $holderResult] = $this->startHolder('key.one', $release);
        self::assertSame('acquired', $this->waitForResult($holderResult));

        self::assertSame('acquired', $this->runOnce('key.two'));

        $mine = $this->mutex()->tryAcquire('key.three');
        self::assertInstanceOf(FileLock::class, $mine);
        self::assertNull($this->mutex()->tryAcquire('key.one'));
        $mine->release();

        touch($release);
        $this->waitForExit($holder);
    }

    // --- same process ---

    public function testSecondAcquireInSameProcessIsNullUntilRelease(): void
    {
        $mutex = $this->mutex();
        $first = $mutex->tryAcquire(self::KEY);
        self::assertInstanceOf(FileLock::class, $first);

        self::assertNull($mutex->tryAcquire(self::KEY));
        self::assertNull($this->mutex()->tryAcquire(self::KEY), 'another FileMutex instance on the same dir');

        $first->release();

        $second = $mutex->tryAcquire(self::KEY);
        self::assertInstanceOf(FileLock::class, $second);
        $second->release();
    }

    public function testReleaseTwiceIsNoOp(): void
    {
        $lock = $this->mutex()->tryAcquire(self::KEY);
        self::assertNotNull($lock);

        $lock->release();
        $lock->release();

        $again = $this->mutex()->tryAcquire(self::KEY);
        self::assertNotNull($again);
        $again->release();
    }

    public function testOldHandleCannotFreeNewerLockInSameProcess(): void
    {
        $old = $this->mutex()->tryAcquire(self::KEY);
        self::assertNotNull($old);
        $old->release();

        $new = $this->mutex()->tryAcquire(self::KEY);
        self::assertNotNull($new);

        $old->release();

        self::assertNull($this->mutex()->tryAcquire(self::KEY));
        self::assertSame('busy', $this->runOnce(self::KEY));

        $new->release();
    }

    public function testOldHandleCannotFreeLockTakenByAnotherProcess(): void
    {
        $old = $this->mutex()->tryAcquire(self::KEY);
        self::assertNotNull($old);
        $old->release();

        $release = $this->path('release');
        [$holder, $holderResult] = $this->startHolder(self::KEY, $release);
        self::assertSame('acquired', $this->waitForResult($holderResult));

        $old->release();
        unset($old); // destructor must not do anything either

        self::assertNull($this->mutex()->tryAcquire(self::KEY));
        self::assertSame('busy', $this->runOnce(self::KEY));

        touch($release);
        $this->waitForExit($holder);
    }

    public function testDestructorDropsTheLockAsSafetyNet(): void
    {
        $lock = $this->mutex()->tryAcquire(self::KEY);
        self::assertNotNull($lock);
        self::assertSame('busy', $this->runOnce(self::KEY));

        unset($lock);

        self::assertSame('acquired', $this->runOnce(self::KEY));
    }

    public function testLockFileIsKeptAfterRelease(): void
    {
        $lock = $this->mutex()->tryAcquire(self::KEY);
        self::assertNotNull($lock);
        $lock->release();

        self::assertFileExists($this->lockDir . '/' . hash('sha256', self::KEY) . '.lock');
    }

    public function testLockDirectoryIsCreatedRecursively(): void
    {
        $deep = $this->root . '/a/b/c';
        $lock = (new FileMutex($deep . '/'))->tryAcquire(self::KEY);
        self::assertNotNull($lock);
        self::assertDirectoryExists($deep);
        $lock->release();
    }

    // --- clone / serialize ---

    public function testCloneThrowsAndOriginalKeepsTheLock(): void
    {
        $lock = $this->mutex()->tryAcquire(self::KEY);
        self::assertNotNull($lock);

        try {
            clone $lock;
            self::fail('clone should throw');
        } catch (\LogicException) {
        }

        // the failed copy is gone; the original must still own the lock
        self::assertNull($this->mutex()->tryAcquire(self::KEY));
        self::assertSame('busy', $this->runOnce(self::KEY));

        $lock->release();
        self::assertSame('acquired', $this->runOnce(self::KEY));
    }

    public function testSerializeThrows(): void
    {
        $lock = $this->mutex()->tryAcquire(self::KEY);
        self::assertNotNull($lock);

        try {
            serialize($lock);
            self::fail('serialize should throw');
        } catch (\LogicException) {
        }

        self::assertSame('busy', $this->runOnce(self::KEY));
        $lock->release();
    }

    public function testUnserializeThrows(): void
    {
        $this->expectException(\LogicException::class);
        unserialize(sprintf('O:%d:"%s":0:{}', strlen(FileLock::class), FileLock::class));
    }

    public function testMagicSleepAndWakeupThrowWhenCalledDirectly(): void
    {
        $lock = $this->mutex()->tryAcquire(self::KEY);
        self::assertNotNull($lock);

        foreach (['__sleep', '__wakeup'] as $method) {
            try {
                $lock->{$method}();
                self::fail("$method should throw");
            } catch (\LogicException) {
            }
        }
        $lock->release();
    }

    // --- validation ---

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield '257 chars' => [str_repeat('a', 257)];
        yield 'space' => ['a b'];
        yield 'slash' => ['a/b'];
        yield 'dot dot slash' => ['../etc'];
        yield 'non-ascii' => ['ключ'];
        yield 'trailing newline' => ["abc\n"];
        yield 'nul byte' => ["a\0b"];
        yield 'backslash' => ['a\\b'];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeyIsRejectedBeforeTouchingDisk(string $key): void
    {
        try {
            $this->mutex()->tryAcquire($key);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }

        self::assertDirectoryDoesNotExist($this->lockDir);
    }

    public function testBoundaryKeysAreAccepted(): void
    {
        foreach ([str_repeat('a', 256), 'A', 'scheduler:my-app:prod.eu:task_1.x'] as $key) {
            $lock = $this->mutex()->tryAcquire($key);
            self::assertNotNull($lock, $key);
            $lock->release();
        }
    }

    public function testEmptyDirectoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileMutex('');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function directoriesWithNulByte(): iterable
    {
        yield 'in the middle' => ["/scheduler-invalid-\0path"];
        yield 'at the end' => ["locks\0"];
        yield 'only nul' => ["\0"];
    }

    // mkdir()/fopen() throw a raw ValueError on NUL - the constructor has to stop it first
    #[DataProvider('directoriesWithNulByte')]
    public function testDirectoryWithNulByteIsRejectedByTheConstructor(string $suffix): void
    {
        try {
            new FileMutex($this->root . $suffix);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('NUL', $e->getMessage());
        }

        // nothing was created next to the test root
        self::assertSame([], array_values(array_diff(scandir($this->root) ?: [], ['.', '..'])));
    }

    public function testRelativeDirectoryWithSpacesIsAccepted(): void
    {
        $cwd = getcwd();
        self::assertIsString($cwd);
        chdir($this->root);

        try {
            $lock = (new FileMutex('lock dir/with "quotes"'))->tryAcquire(self::KEY);
            self::assertNotNull($lock);
            self::assertDirectoryExists($this->root . '/lock dir/with "quotes"');
            self::assertNull((new FileMutex($this->root . '/lock dir/with "quotes"'))->tryAcquire(self::KEY));
            $lock->release();
        } finally {
            chdir($cwd);
        }
    }

    // --- technical failures are not "busy" ---

    public function testDirectoryPathBeingARegularFileThrows(): void
    {
        $file = $this->path('plain-file');
        file_put_contents($file, 'x');

        $this->expectException(MutexException::class);
        (new FileMutex($file))->tryAcquire(self::KEY);
    }

    public function testDirectoryUnderARegularFileThrows(): void
    {
        $file = $this->path('plain-file');
        file_put_contents($file, 'x');

        $this->expectException(MutexException::class);
        $this->expectExceptionMessageMatches('/Cannot create lock directory/');
        (new FileMutex($file . '/sub'))->tryAcquire(self::KEY);
    }

    public function testReadOnlyDirectoryThrows(): void
    {
        $this->skipIfRoot();
        mkdir($this->lockDir, 0700);
        chmod($this->lockDir, 0500);
        $this->chmodded[] = $this->lockDir;

        $this->expectException(MutexException::class);
        $this->expectExceptionMessageMatches('/Cannot open lock file/');
        $this->mutex()->tryAcquire(self::KEY);
    }

    public function testUnwritableLockFileThrows(): void
    {
        $this->skipIfRoot();
        mkdir($this->lockDir, 0700);
        $lockFile = $this->lockDir . '/' . hash('sha256', self::KEY) . '.lock';
        touch($lockFile);
        chmod($lockFile, 0400);

        $this->expectException(MutexException::class);
        $this->mutex()->tryAcquire(self::KEY);
    }

    public function testLockPathBeingADirectoryThrows(): void
    {
        mkdir($this->lockDir . '/' . hash('sha256', self::KEY) . '.lock', 0700, true);

        $this->expectException(MutexException::class);
        $this->mutex()->tryAcquire(self::KEY);
    }

    // --- helpers ---

    private function mutex(): FileMutex
    {
        return new FileMutex($this->lockDir);
    }

    private function path(string $name): string
    {
        return $this->root . '/' . $name . '-' . (++$this->fileCounter);
    }

    /**
     * @return array{resource, string}
     */
    private function startHolder(string $key, string $releaseFile): array
    {
        $result = $this->path('holder');

        return [$this->spawn([$this->lockDir, $key, $result, '-', $releaseFile]), $result];
    }

    /**
     * Try the key once in a fresh process and return its verdict.
     */
    private function runOnce(string $key): string
    {
        $result = $this->path('once');
        $proc = $this->spawn([$this->lockDir, $key, $result]);
        $outcome = $this->waitForResult($result);
        $this->waitForExit($proc);

        return $outcome;
    }

    /**
     * @param list<string> $args
     *
     * @return resource
     */
    private function spawn(array $args)
    {
        $log = $this->path('child-log');
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/lock-holder.php', ...$args],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        self::assertIsResource($proc, 'proc_open failed');
        $this->processes[] = $proc;

        return $proc;
    }

    private function waitForFile(string $file): void
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $file);
            if (file_exists($file)) {
                return;
            }
            usleep(1000);
        }
        self::fail("Timed out waiting for $file");
    }

    private function waitForResult(string $file): string
    {
        $this->waitForFile($file);

        return (string) file_get_contents($file);
    }

    /**
     * @param resource $proc
     *
     * @return array<string, mixed>
     */
    private function waitForExit($proc): array
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                return $status;
            }
            usleep(1000);
        }
        self::fail('Timed out waiting for child process to exit');
    }

    private function skipIfRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores file permissions');
        }
    }

    private static function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            @chmod($path, 0700);
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);

            return;
        }
        unlink($path);
    }
}
