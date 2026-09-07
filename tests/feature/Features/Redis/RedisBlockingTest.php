<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Redis;

use SConcur\Features\Redis\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestRedisResolver;
use SConcur\WaitGroup;

/**
 * The reason blocking commands get a connection of their own.
 *
 * Redis serves one connection strictly in order, so a BLPOP sharing a socket with ordinary
 * commands would hold every one of them up for as long as it waits. These tests measure
 * exactly that.
 */
class RedisBlockingTest extends BaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        TestRedisResolver::flush();

        $this->connection = TestRedisResolver::getConnection();
    }

    public function testABlockingCommandDoesNotHoldUpOtherCommands(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function (): void {
                $this->connection->blPop(['idle:queue'], timeoutSeconds: 1.0);
            },
        );

        $elapsedMs = 0.0;

        $waitGroup->add(
            callback: function () use (&$elapsedMs): void {
                $startTime = microtime(true);

                for ($index = 0; $index < 20; ++$index) {
                    $this->connection->set("fast:$index", '1');
                }

                $elapsedMs = (microtime(true) - $startTime) * 1000;
            },
        );

        $waitGroup->waitAll();

        self::assertTrue(
            $elapsedMs < 500,
            "Twenty ordinary commands took {$elapsedMs}ms while a BLPOP was waiting — they were queued behind it",
        );
    }

    public function testBlockingPopReturnsTheKeyThatAnswered(): void
    {
        $this->connection->rPush('answered', 'value');

        $popped = $this->connection->blPop(['empty', 'answered'], timeoutSeconds: 1.0);

        self::assertSame(['answered', 'value'], $popped);
    }

    public function testBlockingPopReturnsNullWhenNothingArrives(): void
    {
        $popped = $this->connection->blPop(['nothing'], timeoutSeconds: 0.3);

        self::assertNull($popped);
    }

    public function testBlockingPopSeesAValuePushedWhileItWaits(): void
    {
        $waitGroup = WaitGroup::create();

        $popped = null;

        $waitGroup->add(
            callback: function () use (&$popped): void {
                $popped = $this->connection->blPop(['late'], timeoutSeconds: 2.0);
            },
        );

        $waitGroup->add(
            callback: function (): void {
                $this->connection->command('SET', ['marker', '1']);
                $this->connection->rPush('late', 'arrived');
            },
        );

        $waitGroup->waitAll();

        self::assertSame(['late', 'arrived'], $popped);
    }

    public function testADeadlineShorterThanTheWaitIsRefused(): void
    {
        $connection = TestRedisResolver::getConnection(timeoutMs: 200);

        $this->expectExceptionMessageMatches('/raise timeoutMs/');

        // The raw path, where the caller sets both numbers: the facade raises the deadline
        // itself, which is what makes blPop above work.
        $connection->command('BLPOP', ['queue', 5], blocking: true);
    }

    public function testWaitingForeverNeedsNoDeadline(): void
    {
        $connection = TestRedisResolver::getConnection(timeoutMs: 1000);

        $this->expectExceptionMessageMatches('/wait forever/');

        $connection->command('BLPOP', ['queue', 0], blocking: true);
    }
}
