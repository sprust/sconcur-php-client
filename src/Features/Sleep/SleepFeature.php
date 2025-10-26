<?php

declare(strict_types=1);

namespace SConcur\Features\Sleep;

use SConcur\Entities\Context;
use SConcur\Exceptions\ContinueException;
use SConcur\Exceptions\FeatureResultNotFoundException;
use SConcur\Features\MethodEnum;
use SConcur\SConcur;

readonly class SleepFeature
{
    private function __construct()
    {
    }

    /**
     * @throws ContinueException
     * @throws FeatureResultNotFoundException
     */
    public static function sleep(Context $context, int $seconds): void
    {
        static::usleep(context: $context, microseconds: $seconds * 1_000_000);
    }

    /**
     * @throws ContinueException
     * @throws FeatureResultNotFoundException
     */
    public static function usleep(Context $context, int $microseconds): void
    {
        if (!SConcur::isConcurrency()) {
            static::handleHere($microseconds);

            return;
        }

        $runningTask = SConcur::getServerConnector()->write(
            context: $context,
            method: MethodEnum::Sleep,
            payload: (string) $microseconds
        );

        SConcur::wait($runningTask->key);

        SConcur::detectResult(taskKey: $runningTask->key);
    }

    protected static function handleHere(int $microseconds): void
    {
        usleep($microseconds);
    }
}
