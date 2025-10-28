<?php

declare(strict_types=1);

ini_set('memory_limit', '1024M');

use SConcur\Entities\Context;
use SConcur\Features\Sleep\SleepFeature;
use SConcur\SConcur;
use SConcur\Tests\Impl\TestContainer;

require_once __DIR__ . '/../vendor/autoload.php';

TestContainer::resolve();

$start = microtime(true);

$callbacks = [];

$total   = (int) ($_SERVER['argv'][1] ?? 5);
$seconds = (int) ($_SERVER['argv'][2] ?? 1);
$timeout = (int) ($_SERVER['argv'][3] ?? 2);

echo "Total call:\t$total\n";
echo "Seconds:\t$seconds\n";
echo "Timeout:\t$timeout\n";
echo "\n";

foreach (range(1, $total) as $item) {
    $callbacks[] = static function (Context $context) use ($seconds) {
        SleepFeature::sleep(context: $context, seconds: $seconds);
    };
}

$generator = SConcur::run(
    callbacks: $callbacks,
    timeoutSeconds: $timeout,
    limitCount: 0,
);

foreach ($generator as $result) {
    echo "success: $result->key\n";
}

$totalTime = microtime(true) - $start;
$memPeak   = round(memory_get_peak_usage(true) / 1024 / 1024, 4);

echo "Mem peak:\t$memPeak\n";
echo "Total time:\t$totalTime\n";
