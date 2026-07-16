<?php
declare(strict_types=1);

namespace Webhooks\Cli\Exception;

/**
 * Exception codes for the GitHub Review CLI.
 */
final class ExceptionCodes
{
    public const SUCCESS = 0;
    public const GENERAL_ERROR = 1;
    public const INVALID_ARGUMENTS = 2;
    public const NOT_FOUND = 3;
    public const VALIDATION_ERROR = 4;
    public const REDIS_ERROR = 5;
    public const GITHUB_API_ERROR = 6;
    public const CHECKOUT_ERROR = 7;
    public const TIMEOUT = 8;

    private function __construct()
    {
    }
}
