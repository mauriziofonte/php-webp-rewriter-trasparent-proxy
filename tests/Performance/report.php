<?php

declare(strict_types=1);

/*
 * Prints the report of a run of tests/Performance/run.sh, writes it to
 * summary.json and summary.md of the results directory (and to the step
 * summary of GitHub Actions), and exits 1 when the gate fails.
 *
 * Usage: php report.php <results directory> [--max-ratio [<stack>=]x]... [--max-p99-ratio [<stack>=]x]... [--min-rps-ratio [<stack>=]x]... [--strict]
 *
 * The limits apply to the best value of each side over the rounds (see
 * LatencyReport). A limit without a
 * stack name applies to every stack without one of its own; the defaults
 * are the target: 1.10 for p50 and p99, 0.90 for requests per second.
 *
 * It needs no vendor/: the performance job installs no development
 * dependencies, so the class is loaded by path.
 */

use Mfonte\ProxyCdn\Tests\Performance\LatencyReport;

require_once __DIR__ . '/LatencyReport.php';

$arguments = [];
foreach (is_array($_SERVER['argv'] ?? null) ? array_slice($_SERVER['argv'], 1) : [] as $argument) {
    $arguments[] = is_string($argument) ? $argument : '';
}
$directory = null;
$limits = ['--max-ratio' => [], '--max-p99-ratio' => [], '--min-rps-ratio' => []];
$strict = false;
for ($index = 0; $index < count($arguments); ++$index) {
    $argument = $arguments[$index];
    if ($argument === '--strict') {
        $strict = true;
    } elseif (isset($limits[$argument])) {
        $value = $arguments[++$index] ?? '';
        if (preg_match('/^(?:([a-z]+)=)?([0-9]+(?:\.[0-9]+)?)$/', $value, $match) !== 1) {
            fwrite(STDERR, $argument . ' needs a ratio such as 1.25 or apache=1.50' . PHP_EOL);
            exit(2);
        }
        $limits[$argument][$match[1] === '' ? '*' : $match[1]] = (float) $match[2];
    } elseif ($directory === null) {
        $directory = $argument;
    } else {
        fwrite(STDERR, 'Unexpected argument: ' . $argument . PHP_EOL);
        exit(2);
    }
}
if ($directory === null) {
    fwrite(STDERR, 'Usage: php report.php <results directory> [--max-ratio [<stack>=]x]... [--max-p99-ratio [<stack>=]x]... [--min-rps-ratio [<stack>=]x]... [--strict]' . PHP_EOL);
    exit(2);
}

try {
    $report = new LatencyReport($directory, $limits['--max-ratio'], $limits['--max-p99-ratio'], $limits['--min-rps-ratio'], $strict);
} catch (\RuntimeException $exception) {
    fwrite(STDERR, 'Incomplete results: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$markdown = $report->markdown();
echo $markdown;
file_put_contents($directory . '/summary.md', $markdown);
file_put_contents($directory . '/summary.json', json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$stepSummary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($stepSummary) && $stepSummary !== '') {
    file_put_contents($stepSummary, $markdown . "\n", FILE_APPEND);
}

exit($report->passed() ? 0 : 1);
