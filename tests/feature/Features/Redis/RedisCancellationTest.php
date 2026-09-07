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
 * Stopping the flow under a running command, a cursor and a subscription.
 *
 * What proves it is the server's own client count, not the task count: a flow being
 * stopped zeroes the latter by bookkeeping whether or not the task noticed its token, so a
 * handler that ignored cancellation would leave these tests green. A socket the core
 * failed to release is visible only on the other side.
 */
class RedisCancellationTest extends BaseTestCase
{
    private Connection $connection;

    private int $baselineConnections = 0;

    protected function setUp(): void
    {
        parent::setUp();

        TestRedisResolver::flush();

        $this->connection = TestRedisResolver::getConnection();

        // Taken after the pool is warm, so the baseline counts the connections the
        // feature keeps rather than the ones it is about to open.
        $this->connection->ping();

        $this->baselineConnections = TestRedisResolver::countServerConnections();
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

        $this->assertConnectionsSettleBack();
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

        $this->assertConnectionsSettleBack();
    }

    public function testStoppingAFlowUnderACursor(): void
    {
        $pipeline = $this->connection->pipeline();

        for ($index = 0; $index < 500; ++$index) {
            $pipeline->command('SET', ["cancel:scan:$index", '1']);
        }

        $pipeline->execute();

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () use ($waitGroup): void {
                try {
                    foreach ($this->connection->scan(match: 'cancel:scan:*', count: 5, batchSize: 1) as $ignored) {
                        // Stopped in the middle of the walk, with the cursor open.
                        $waitGroup->stop();
                    }
                } catch (Throwable) {
                    //
                }
            },
        );

        try {
            $waitGroup->waitAll();
        } catch (Throwable) {
            //
        }

        $this->assertConnectionsSettleBack();
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

    /**
     * The server's client count comes back to where it started. Retried for a
     * moment, because the core releases a stopped task's connection as soon as it
     * unwinds and PHP gets there first.
     */
    private function assertConnectionsSettleBack(): void
    {
        $baseline = $this->baselineConnections;

        for ($attempt = 0; $attempt < 40; ++$attempt) {
            $connections = TestRedisResolver::countServerConnections();

            if ($connections <= $baseline + 4) {
                self::assertLessThanOrEqual($baseline + 4, $connections);

                return;
            }

            usleep(50_000);
        }

        self::fail(
            'the stopped flow left connections behind: '
            . TestRedisResolver::countServerConnections() . " against a baseline of $baseline",
        );
    }
}
