<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Redis;

use SConcur\Features\Redis\Connection;
use SConcur\Features\Redis\Dto\ErrorReply;
use SConcur\Features\Redis\Pipeline;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestRedisResolver;

class RedisPipelineTest extends BaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        TestRedisResolver::flush();

        $this->connection = TestRedisResolver::getConnection();
    }

    public function testRepliesComeBackInOrder(): void
    {
        $replies = $this->connection->pipeline()
            ->command('SET', ['a', '1'])
            ->command('INCR', ['a'])
            ->command('GET', ['a'])
            ->execute();

        self::assertSame(['OK', 2, '2'], $replies);
    }

    public function testAFailedCommandDoesNotFailTheBatch(): void
    {
        $this->connection->set('string', 'value');

        $replies = $this->connection->pipeline()
            ->command('SET', ['ok', '1'])
            ->command('LPUSH', ['string', 'x'])
            ->command('GET', ['ok'])
            ->execute();

        self::assertSame('OK', $replies[0]);
        self::assertInstanceOf(ErrorReply::class, $replies[1]);
        self::assertSame('WRONGTYPE', $replies[1]->code);
        self::assertSame('1', $replies[2]);
    }

    public function testAPipelineIsEmptiedByExecute(): void
    {
        $pipeline = $this->connection->pipeline();

        $pipeline->command('SET', ['a', '1']);

        self::assertSame(1, $pipeline->count());

        $pipeline->execute();

        self::assertSame(0, $pipeline->count());
    }

    public function testATransactionRunsAsOneUnit(): void
    {
        $replies = $this->connection->transaction(
            static function (Pipeline $transaction): void {
                $transaction->command('SET', ['tx', '1']);
                $transaction->command('INCR', ['tx']);
            },
        );

        self::assertSame(['OK', 2], $replies);
        self::assertSame('2', $this->connection->get('tx'));
    }

    public function testATransactionReportsAFailedCommandInItsPlace(): void
    {
        $this->connection->set('string', 'value');

        $replies = $this->connection->transaction(
            static function (Pipeline $transaction): void {
                $transaction->command('SET', ['ok', '1']);
                $transaction->command('LPUSH', ['string', 'x']);
            },
        );

        self::assertSame('OK', $replies[0]);
        self::assertInstanceOf(ErrorReply::class, $replies[1]);
    }

    public function testABlockingCommandIsRefusedInsideAPipeline(): void
    {
        $this->expectExceptionMessageMatches('/cannot run inside a pipeline/');

        $this->connection->pipeline()
            ->command('BLPOP', ['queue', '1'])
            ->execute();
    }
}
