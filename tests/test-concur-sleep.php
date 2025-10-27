<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Sleep\SleepFeature;
use SConcur\SConcur;
use SConcur\Tests\Impl\TestContainer;

require_once __DIR__ . '/../vendor/autoload.php';

TestContainer::resolve();

$start = microtime(true);

$callbacks = [
    function (Context $context) {
        SleepFeature::sleep(context: $context, seconds: 1);
    },
    function (Context $context) {
        SleepFeature::usleep(context: $context, microseconds: 1_000_000);
    },
    function (Context $context) {
        SleepFeature::sleep(context: $context, seconds: 1);
    },
    function (Context $context) {
        SleepFeature::usleep(context: $context, microseconds: 1_000_000);
    },
    function (Context $context) {
        SleepFeature::sleep(context: $context, seconds: 1);
    },
    function (Context $context) {
        SleepFeature::usleep(context: $context, microseconds: 1_000_000);
    },
    function (Context $context) {
        SleepFeature::sleep(context: $context, seconds: 1);
    },
    function (Context $context) {
        SleepFeature::usleep(context: $context, microseconds: 1_000_000);
    },
    function (Context $context) {
        SleepFeature::sleep(context: $context, seconds: 1);
    },
    function (Context $context) {
        SleepFeature::usleep(context: $context, microseconds: 1_000_000);
    },
];

$generator = SConcur::run(
    callbacks: $callbacks,
    timeoutSeconds: 3,
    limitCount: 5,
);

foreach ($generator as $key => $result) {
    echo "success: $key\n";
}

$totalTime = microtime(true) - $start;
$memPeak   = round(memory_get_peak_usage(true) / 1024 / 1024, 4);

echo "Mem peak:\t$memPeak\n";
echo "Total time:\t$totalTime\n";
