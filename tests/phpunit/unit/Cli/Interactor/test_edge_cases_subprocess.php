#!/usr/bin/env php
<?php
/**
 * Subprocess test helper for InteractiveTui edge cases.
 *
 * This script tests the behavior of InteractiveTui when STDIN is not available.
 * It must be run as a separate process since we need to close STDIN before
 * the tests run.
 *
 * Usage: php tests/phpunit/unit/Cli/Interactor/test_edge_cases_subprocess.php
 */

declare(strict_types=1);

require_once '/home/sites/webhooks.interserver.net/vendor/autoload.php';

use Symfony\Component\Console\Output\NullOutput;
use Webhooks\Cli\Interactor\InteractiveTui;

$output = new NullOutput();

// Close STDIN to simulate not having a TTY
fclose(STDIN);

// Now try to detect if we're in a TTY
$tui = new InteractiveTui($output);

// Use reflection to check isAtty
$reflection = new ReflectionClass(InteractiveTui::class);
$isAttyProperty = $reflection->getProperty('isAtty');
$isAttyProperty->setAccessible(true);
$isAtty = $isAttyProperty->getValue($tui);

// Check detectAtty result
$detectAttyMethod = $reflection->getMethod('detectAtty');
$detectAttyMethod->setAccessible(true);
$detectAttyResult = $detectAttyMethod->invoke($tui);

// Output results as JSON for parsing
$result = [
    'isAtty' => $isAtty,
    'detectAttyResult' => $detectAttyResult,
    'stdinIsResource' => is_resource(STDIN) ? 'resource' : gettype(STDIN),
];

echo json_encode($result) . PHP_EOL;
