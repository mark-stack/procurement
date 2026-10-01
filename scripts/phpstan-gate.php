<?php

declare(strict_types=1);

/**
 * PHPStan, gated against the number of errors this repository has agreed to live with.
 *
 * The count is a gate, not a silence - every accepted error still prints on every run, because an
 * accepted-defect list nobody reads is how a concession becomes permanent. PHPStan's own baseline
 * file was the other option and was turned down for exactly that: it suppresses what it records,
 * and reportUnmatchedIgnoredErrors then fails the build of whoever fixes one.
 *
 * Reads the number from docs/quality-gates.md so there is one copy of it, next to the reasoning.
 */

const EXIT_PASS = 0;
const EXIT_OVER_BASELINE = 1;
const EXIT_COULD_NOT_RUN = 2;

$root = dirname(__DIR__);
$noteFile = $root.'/docs/quality-gates.md';
$noteLabel = 'Accepted PHPStan errors:';

$baseline = readBaseline($noteFile, $noteLabel);

[$exitCode, $stdout, $stderr] = runPhpstan($root);

$report = json_decode($stdout, true);

/*
 * PHPStan exits non-zero whenever it found something, so the exit code alone cannot tell a run that
 * worked from one that died. Unreadable JSON is the signal that it died - a parse error in app/, an
 * out-of-memory kill, a broken extension - and none of those are an error count to compare.
 */
if (! is_array($report) || ! isset($report['totals'])) {
    fwrite(STDERR, "PHPStan did not produce a report.\n\n");
    fwrite(STDERR, trim($stdout === '' ? $stderr : $stdout)."\n");

    annotate('error', 'PHPStan could not be run. See the log for its output.');

    exit(EXIT_COULD_NOT_RUN);
}

$found = (int) ($report['totals']['file_errors'] ?? 0) + (int) ($report['totals']['errors'] ?? 0);

printErrors($report, $root);

echo PHP_EOL;
echo 'Accepted baseline: '.$baseline.' ('.relativePath($noteFile, $root).')'.PHP_EOL;
echo 'Found:             '.$found.PHP_EOL;
echo PHP_EOL;

if ($found > $baseline) {
    $risen = $found - $baseline;
    $message = 'PHPStan is '.$risen.' error'.($risen === 1 ? '' : 's').' above the accepted baseline of '
        .$baseline.'. Fix the new errors, or - if they are genuinely acceptable - raise the number in '
        .relativePath($noteFile, $root).' and write down why.';

    fwrite(STDERR, $message.PHP_EOL);
    annotate('error', $message);
    summarise('🔴 PHPStan', $baseline, $found, $message);

    exit(EXIT_OVER_BASELINE);
}

if ($found < $baseline) {
    /*
     * A drop is not a failure - TASK-010 asked for a gate that fires on a rise only, and failing the
     * build of somebody who improved the code teaches them not to. It is still worth saying loudly,
     * because a baseline left above the real count is a gate that has quietly stopped gating.
     */
    $fixed = $baseline - $found;
    $message = $fixed.' PHPStan error'.($fixed === 1 ? ' has' : 's have').' been fixed since the baseline '
        .'was recorded. Lower it to '.$found.' in '.relativePath($noteFile, $root).' in this change, so '
        .'the gate keeps holding the line where the code actually is.';

    echo $message.PHP_EOL;
    annotate('warning', $message);
    summarise('🟡 PHPStan', $baseline, $found, $message);

    exit(EXIT_PASS);
}

echo 'PHPStan is level with its baseline.'.PHP_EOL;
summarise('🟢 PHPStan', $baseline, $found, 'Level with the accepted baseline.');

exit(EXIT_PASS);

/**
 * The agreed number, read from the note that explains it.
 */
function readBaseline(string $noteFile, string $label): int
{
    if (! is_readable($noteFile)) {
        fwrite(STDERR, 'Cannot read '.$noteFile.", which is where the accepted PHPStan count lives.\n");

        exit(EXIT_COULD_NOT_RUN);
    }

    $matched = preg_match(
        '/^'.preg_quote($label, '/').'\s*(\d+)\s*$/m',
        (string) file_get_contents($noteFile),
        $matches
    );

    if ($matched !== 1) {
        fwrite(STDERR, 'No "'.$label.' <number>" line in '.$noteFile.". The gate has nothing to compare against.\n");

        exit(EXIT_COULD_NOT_RUN);
    }

    return (int) $matches[1];
}

/**
 * @return array{0: int, 1: string, 2: string}
 */
function runPhpstan(string $root): array
{
    $command = sprintf(
        '%s %s analyse --no-progress --no-interaction --memory-limit=1G --error-format=json',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root.'/vendor/bin/phpstan'),
    );

    $pipes = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );

    if (! is_resource($process)) {
        fwrite(STDERR, "Could not start PHPStan.\n");

        exit(EXIT_COULD_NOT_RUN);
    }

    //Read both pipes before closing, or PHPStan blocks on a full stderr buffer while this waits on it
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

/**
 * Every error, every run. This is the accepted-defect list being read out rather than filed away.
 *
 * @param  array<string, mixed>  $report
 */
function printErrors(array $report, string $root): void
{
    /** @var array<string, array{messages: array<int, array<string, mixed>>}> $files */
    $files = $report['files'] ?? [];

    foreach ($files as $path => $file) {
        echo relativePath((string) $path, $root).PHP_EOL;

        foreach ($file['messages'] as $message) {
            echo sprintf(
                '  %-5s %s  [%s]'.PHP_EOL,
                $message['line'] ?? '?',
                preg_replace('/\s+/', ' ', (string) ($message['message'] ?? '')),
                $message['identifier'] ?? 'no identifier',
            );
        }
    }

    /** @var array<int, string> $general */
    $general = $report['errors'] ?? [];

    foreach ($general as $error) {
        echo 'Not tied to a file: '.$error.PHP_EOL;
    }
}

function relativePath(string $path, string $root): string
{
    return str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : $path;
}

/**
 * A line against the change in the GitHub UI, where somebody reading the pull request will see it.
 */
function annotate(string $level, string $message): void
{
    if (getenv('GITHUB_ACTIONS') !== 'true') {
        return;
    }

    //Annotations are one line each, and a literal newline would end the command early
    echo '::'.$level.' title=PHPStan baseline::'.str_replace("\n", ' ', $message).PHP_EOL;
}

function summarise(string $heading, int $baseline, int $found, string $message): void
{
    $summaryFile = getenv('GITHUB_STEP_SUMMARY');

    if ($summaryFile === false || $summaryFile === '') {
        return;
    }

    file_put_contents(
        $summaryFile,
        '### '.$heading.PHP_EOL.PHP_EOL
            .'| Accepted baseline | Found |'.PHP_EOL
            .'| --- | --- |'.PHP_EOL
            .'| '.$baseline.' | '.$found.' |'.PHP_EOL.PHP_EOL
            .$message.PHP_EOL,
        FILE_APPEND
    );
}
