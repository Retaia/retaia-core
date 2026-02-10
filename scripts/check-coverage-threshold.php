#!/usr/bin/env php
<?php

declare(strict_types=1);

$reportPath = $argv[1] ?? 'var/coverage/clover.xml';
$threshold = (float) ($argv[2] ?? '80');

if (!is_file($reportPath)) {
    fwrite(STDERR, sprintf("Coverage report not found: %s\n", $reportPath));
    exit(1);
}

$xml = simplexml_load_file($reportPath);
if (!$xml instanceof SimpleXMLElement) {
    fwrite(STDERR, sprintf("Invalid Clover report: %s\n", $reportPath));
    exit(1);
}

$coveragePercent = null;

if (isset($xml['line-rate']) && is_numeric((string) $xml['line-rate'])) {
    $coveragePercent = ((float) $xml['line-rate']) * 100;
}

if ($coveragePercent === null && isset($xml->project->metrics)) {
    $metrics = $xml->project->metrics;
    $statements = (int) ($metrics['statements'] ?? 0);
    $covered = (int) ($metrics['coveredstatements'] ?? 0);
    if ($statements > 0) {
        $coveragePercent = ($covered / $statements) * 100;
    }
}

if ($coveragePercent === null) {
    fwrite(STDERR, "Unable to compute line coverage from Clover report.\n");
    exit(1);
}

$coveragePercent = round($coveragePercent, 2);
fwrite(STDOUT, sprintf("Line coverage: %.2f%% (threshold: %.2f%%)\n", $coveragePercent, $threshold));

if ($coveragePercent < $threshold) {
    fwrite(STDERR, sprintf("Coverage threshold failed: %.2f%% < %.2f%%\n", $coveragePercent, $threshold));
    exit(1);
}

fwrite(STDOUT, "Coverage threshold satisfied.\n");
exit(0);
