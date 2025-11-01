<?php

declare(strict_types=1);

use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use SConcur\Entities\Context;
use SConcur\Features\Mongodb\MongodbFeature;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\SConcur;
use SConcur\Tests\Impl\TestContainer;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/../vendor/autoload.php';

TestContainer::resolve();

$total      = (int) ($_SERVER['argv'][1] ?? 5);
$limitCount = (int) ($_SERVER['argv'][2] ?? 0);

$counter = $total;

/** @var array<Closure> $callbacks */
$callbacks = [];

$start = microtime(true);

$uri = TestMongodbUriResolver::get();

echo "Mongodb URI: $uri\n\n";

$databaseName   = 'test';
$collectionName = 'test';

$driverCollection = (new Client($uri))->selectCollection(
    databaseName: $databaseName,
    collectionName: $collectionName
);

$connection = new ConnectionParameters(
    uri: $uri,
    database: $databaseName,
    collection: $collectionName,
);

while ($counter--) {
    $callbacks["insert-$counter"] = static function (Context $context) use (
        $driverCollection,
        $connection,
    ) {
        return MongodbFeature::insertOne(
            context:$context,
            collection: $driverCollection,
            connection: $connection,
            document: [
                'uniq'      => uniqid(),
                'bool'      => true,
                'date'      => new UTCDateTime(),
                'dates'     => [
                    new UTCDateTime(),
                    new UTCDateTime(),
                    'dates'     => [
                        new UTCDateTime(),
                        new UTCDateTime(),
                    ],
                    'dates_ass' => [
                        'one' => new UTCDateTime(),
                        'two' => new UTCDateTime(),
                    ],
                ],
                'dates_ass' => [
                    'one'       => new UTCDateTime(),
                    'two'       => new UTCDateTime(),
                    'dates'     => [
                        new UTCDateTime(),
                        new UTCDateTime(),
                    ],
                    'dates_ass' => [
                        'one' => new UTCDateTime(),
                        'two' => new UTCDateTime(),
                    ],
                ],
            ]
        )->getInsertedId();
    };
}

$insertedIds = [];

foreach (SConcur::run($callbacks, $limitCount) as $key => $result) {
    $insertedIds[$key] = $result->result;

    echo "success:\n";
    print_r($result->result);
}

$totalTime = microtime(true) - $start;
$memPeak   = round(memory_get_peak_usage(true) / 1024 / 1024, 4);

echo "\n\nTotal call:\t$total\n";
echo "Thr limit:\t$limitCount\n";
echo "Mem peak:\t$memPeak\n";
echo "Total time:\t$totalTime\n";
