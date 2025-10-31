<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb;

use MongoDB\Collection;
use MongoDB\InsertOneResult as DriverInsertOneResult;
use RuntimeException;
use SConcur\Entities\Context;
use SConcur\Exceptions\ContinueException;
use SConcur\Exceptions\FeatureResultNotFoundException;
use SConcur\Features\MethodEnum;
use SConcur\Features\Mongodb\Operations\InsertOne\InsertOneResult as PackageInsertOneResult;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;
use SConcur\SConcur;

readonly class MongodbFeature
{
    private function __construct()
    {
    }

    /**
     * @throws FeatureResultNotFoundException
     * @throws ContinueException
     */
    public static function insertOne(
        Context $context,
        Collection $collection,
        ConnectionParameters $connection,
        array $document,
    ): DriverInsertOneResult|PackageInsertOneResult {
        if (!SConcur::isConcurrency()) {
            return $collection->insertOne($document);
        }

        $connector = SConcur::getServerConnector()->clone($context);

        $serialized = DocumentSerializer::serialize($document);

        $runningTask = $connector->write(
            context: $context,
            method: MethodEnum::Mongodb,
            payload: static::serialize(
                connection: $connection,
                command: CommandEnum::InsertOne,
                data: $serialized,
            )
        );

        $connector->disconnect();

        SConcur::wait($runningTask->key);

        $result = SConcur::detectResult(taskKey: $runningTask->key);

        if ($result->isError) {
            throw new RuntimeException(
                $result->payload ?: 'Unknown error',
            );
        }

        $docResult = (array) DocumentSerializer::unserialize($result->payload)->toPHP();

        return new PackageInsertOneResult(
            insertedId: $docResult['insertedid'],
        );
    }

    protected static function serialize(
        ConnectionParameters $connection,
        CommandEnum $command,
        string $data
    ): string {
        return json_encode([
            'ul' => $connection->uri,
            'db' => $connection->database,
            'cl' => $connection->collection,
            'cm' => $command->value,
            'dt' => $data,
        ]);
    }
}
