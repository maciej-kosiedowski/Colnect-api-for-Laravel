#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Fails the build when the Clover report does not reach the required line coverage.
 *
 * Usage: php bin/check-coverage.php build/clover.xml 100
 */

/** @var list<string> $argv */
$cloverFile = $argv[1] ?? '';
$threshold = (float) ($argv[2] ?? '100');

if ($cloverFile === '' || ! is_file($cloverFile)) {
    fwrite(STDERR, sprintf('Clover report "%s" was not found.%s', $cloverFile, PHP_EOL));

    exit(1);
}

$xml = simplexml_load_file($cloverFile);

if ($xml === false) {
    fwrite(STDERR, sprintf('Clover report "%s" could not be parsed.%s', $cloverFile, PHP_EOL));

    exit(1);
}

$metrics = $xml->xpath('/coverage/project/metrics');

if (! is_array($metrics) || $metrics === []) {
    fwrite(STDERR, 'Clover report does not contain project metrics.'.PHP_EOL);

    exit(1);
}

$projectMetrics = reset($metrics);

$statements = (int) (string) $projectMetrics['statements'];
$covered = (int) (string) $projectMetrics['coveredstatements'];

if ($statements === 0) {
    fwrite(STDERR, 'Clover report contains no statements; refusing to report success.'.PHP_EOL);

    exit(1);
}

$coverage = $covered / $statements * 100;

printf(
    'Line coverage: %.2f%% (%d/%d statements), required: %.2f%%%s',
    $coverage,
    $covered,
    $statements,
    $threshold,
    PHP_EOL,
);

if ($coverage + 0.0001 < $threshold) {
    fwrite(STDERR, 'Coverage threshold not met.'.PHP_EOL);

    exit(1);
}

exit(0);
