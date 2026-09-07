<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Redis;

use SConcur\Exceptions\Redis\RedisTimeoutException;
use SConcur\Features\Redis\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestRedisResolver;
use SConcur\WaitGroup;
use Throwable;

/**
 * Stopping the flow under a running command, a cursor and a subscription. What is being
 * checked is not the exception but tearDown's dangling-task assertion: a task that ignored
 * its cancellation token is still there when the test ends.
 */
class RedisCancellationTest extends BaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        TestRedisResolver::flush();

        $this->connection = TestRedisResolver::getConnection();
    }

    public function testStoppingAFlowUnderABlockingCommand(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function (): void {
                try {
                    $this->connection->blPop(['never'], timeoutSeconds: 30.0);
                } catch (Throwable) {
                    // The unwind is the point; what it arrives as is not.
                }
            },
        );

        $waitGroup->add(
            callback: function () use ($waitGroup): void {
                $this->connection->ping();

                $waitGroup->stop();
            },
        );

        try {
            $waitGroup->waitAll();
        } catch (Throwable) {
            //
        }

        // The connection still serves commands: the stop released what the blocking
        // command held rather than leaving the pool short of it.
        self::assertTrue($this->connection->ping());
    }

    public function testStoppingAFlowUnderASubscription(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function (): void {
                try {
                    $subscription = $this->connection->subscribe(channels: ['quiet']);

                    // Nobody publishes here: the pull waits until the flow is stopped.
                    $subscription->read();
                } catch (Throwable) {
                    //
                }
            },
        );

        $waitGroup->add(
            callback: function () use ($waitGroup): void {
                $this->connection->ping();

                $waitGroup->stop();
            },
        );

        try {
            $waitGroup->waitAll();
        } catch (Throwable) {
            //
        }

        self::assertTrue($this->connection->ping());
    }

    public function testADeadlineThatRunsOutSurfacesAsATimeout(): void
    {
        $connection = TestRedisResolver::getConnection(timeoutMs: 200);

        // blocking: false on purpose — it puts a waiting command on a shared connection,
        // which is the one way to reach the deadline without a script that would stall the
        // whole server. It is also exactly what the flag is for: the caller says what the
        // command does, and here the caller is lying to make the deadline fire.
        $this->expectException(RedisTimeoutException::class);

        $connection->command('BLPOP', ['never', 5], blocking: false);
    }
}
