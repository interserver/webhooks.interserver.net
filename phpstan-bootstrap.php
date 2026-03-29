<?php
declare(strict_types=1);

// Stub constants defined in gitignored src/config.php so PHPStan can analyse without it.
if (!defined('GITHUB_WEBHOOKS_SECRET')) {
    define('GITHUB_WEBHOOKS_SECRET', '');
}
