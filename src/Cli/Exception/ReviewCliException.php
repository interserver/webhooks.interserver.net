<?php
declare(strict_types=1);

namespace Webhooks\Cli\Exception;

use Exception;
use Throwable;

/**
 * Base exception for the GitHub Review CLI.
 *
 * Uses error codes defined in ExceptionCodes for consistent CLI exit codes.
 */
class ReviewCliException extends Exception
{
    private int $errorCode;
    private bool $isSilent;

    /**
     * @param int $errorCode One of the ExceptionCodes constants
     * @param string $message Human-readable error message
     * @param Throwable|null $previous Previous exception if any
     * @param bool $isSilent If true, don't show the message in non-interactive mode
     */
    public function __construct(
        int $errorCode,
        string $message = '',
        ?Throwable $previous = null,
        bool $isSilent = false
    ) {
        $this->errorCode = $errorCode;
        $this->isSilent = $isSilent;

        parent::__construct($message, 0, $previous);
    }

    /**
     * Get the error code for CLI exit status.
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * Check if this exception should be silent in non-interactive mode.
     */
    public function isSilent(): bool
    {
        return $this->isSilent;
    }

    /**
     * Create a general error exception.
     */
    public static function general(string $message, ?Throwable $previous = null): self
    {
        return new self(ExceptionCodes::GENERAL_ERROR, $message, $previous);
    }

    /**
     * Create an invalid arguments exception.
     */
    public static function invalidArguments(string $message, ?Throwable $previous = null): self
    {
        return new self(ExceptionCodes::INVALID_ARGUMENTS, $message, $previous);
    }

    /**
     * Create a not found exception.
     */
    public static function notFound(string $message, ?Throwable $previous = null): self
    {
        return new self(ExceptionCodes::NOT_FOUND, $message, $previous);
    }

    /**
     * Create a validation error exception.
     */
    public static function validation(string $message, ?Throwable $previous = null): self
    {
        return new self(ExceptionCodes::VALIDATION_ERROR, $message, $previous);
    }

    /**
     * Create a Redis error exception.
     */
    public static function redis(string $message, ?Throwable $previous = null): self
    {
        return new self(ExceptionCodes::REDIS_ERROR, $message, $previous);
    }

    /**
     * Create a GitHub API error exception.
     */
    public static function githubApi(string $message, ?Throwable $previous = null): self
    {
        return new self(ExceptionCodes::GITHUB_API_ERROR, $message, $previous);
    }

    /**
     * Create a checkout error exception.
     */
    public static function checkout(string $message, ?Throwable $previous = null): self
    {
        return new self(ExceptionCodes::CHECKOUT_ERROR, $message, $previous);
    }

    /**
     * Create a timeout exception.
     */
    public static function timeout(string $message, ?Throwable $previous = null): self
    {
        return new self(ExceptionCodes::TIMEOUT, $message, $previous);
    }

    /**
     * Get error name from code.
     */
    public static function getErrorName(int $code): string
    {
        return match ($code) {
            ExceptionCodes::SUCCESS => 'Success',
            ExceptionCodes::GENERAL_ERROR => 'General Error',
            ExceptionCodes::INVALID_ARGUMENTS => 'Invalid Arguments',
            ExceptionCodes::NOT_FOUND => 'Not Found',
            ExceptionCodes::VALIDATION_ERROR => 'Validation Error',
            ExceptionCodes::REDIS_ERROR => 'Redis Error',
            ExceptionCodes::GITHUB_API_ERROR => 'GitHub API Error',
            ExceptionCodes::CHECKOUT_ERROR => 'Checkout Error',
            ExceptionCodes::TIMEOUT => 'Timeout',
            default => 'Unknown Error',
        };
    }

    /**
     * Format exception for CLI error output.
     */
    public function formatForCli(string $commandName = 'github-review'): string
    {
        $errorName = self::getErrorName($this->errorCode);
        $message = $this->getMessage();

        if ($message === '') {
            return sprintf(
                '<error>%s: %s</error>',
                $commandName,
                $errorName
            );
        }

        return sprintf(
            '<error>%s: %s</error>' . "\n\n  %s",
            $commandName,
            $errorName,
            $message
        );
    }
}
