<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Redis;

use SConcur\Features\Redis\Connection;
use SConcur\Features\Redis\Dto\Message;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestRedisResolver;
use SConcur\WaitGroup;

class RedisSubscribeTest extends BaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        TestRedisResolver::flush();

        $this->connection = TestRedisResolver::getConnection();
    }

    public function testMessagesArriveOnASubscribedChannel(): void
    {
        $received = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () use (&$received): void {
                $subscription = $this->connection->subscribe(channels: ['news']);

                // Publishing from another coroutine, so the two run at the same time: a
                // subscriber that had to publish to itself would prove nothing.
                Scheduler::get()->spawn(
                    callback: function (): void {
                        $this->connection->command('PUBLISH', ['news', 'first']);
                        $this->connection->command('PUBLISH', ['news', 'second']);
                    },
                );

                foreach ($subscription as $message) {
                    $received[] = $message->payload;

                    if (count($received) === 2) {
                        break;
                    }
                }

                $subscription->close();
            },
        );

        $waitGroup->waitAll();

        self::assertSame(['first', 'second'], $received);
    }

    public function testAPatternSubscriptionReportsThePatternAndTheChannel(): void
    {
        $message = null;

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () use (&$message): void {
                $subscription = $this->connection->subscribe(patterns: ['user:*']);

                Scheduler::get()->spawn(
                    callback: function (): void {
                        $this->connection->command('PUBLISH', ['user:7', 'hello']);
                    },
                );

                $message = $subscription->read();

                $subscription->close();
            },
        );

        $waitGroup->waitAll();

        self::assertInstanceOf(Message::class, $message);
        self::assertSame('user:7', $message->channel);
        self::assertSame('user:*', $message->pattern);
        self::assertSame('hello', $message->payload);
        self::assertTrue($message->fromPattern());
    }

    public function testChannelsCanBeAddedToALiveSubscription(): void
    {
        $received = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () use (&$received): void {
                $subscription = $this->connection->subscribe(channels: ['first']);

                $subscription->add(channels: ['second']);

                Scheduler::get()->spawn(
                    callback: function (): void {
                        $this->connection->command('PUBLISH', ['second', 'from-second']);
                    },
                );

                $message = $subscription->read();

                $received[] = $message?->channel;

                $subscription->close();
            },
        );

        $waitGroup->waitAll();

        self::assertSame(['second'], $received);
    }

    public function testAnAbandonedSubscriptionIsClosedWithItsFlow(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function (): void {
                $subscription = $this->connection->subscribe(channels: ['abandoned']);

                self::assertNotSame('', $subscription->subscriptionId);

                // Walk away without closing. The flow ending must release the
                // subscription and its connection; the dangling-task check in tearDown is
                // what proves it did.
            },
        );

        $waitGroup->waitAll();

        // The connection still serves commands, so the abandoned subscription took only
        // its own socket with it.
        self::assertTrue($this->connection->ping());
    }

    public function testASubscriptionCanBeClosedTwice(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function (): void {
                $subscription = $this->connection->subscribe(channels: ['twice']);

                $subscription->close();
                $subscription->close();

                self::assertTrue($subscription->isClosed());
            },
        );

        $waitGroup->waitAll();
    }
}
