<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Exception;

use PHPUnit\Framework\TestCase;
use Webhooks\Cli\Exception\ExceptionCodes;
use Webhooks\Cli\Exception\ReviewCliException;

class ReviewCliExceptionTest extends TestCase
{
    public function testCreateGeneralException(): void
    {
        $ex = ReviewCliException::general('Something went wrong');

        $this->assertSame(ExceptionCodes::GENERAL_ERROR, $ex->getErrorCode());
        $this->assertSame('Something went wrong', $ex->getMessage());
        $this->assertSame(ExceptionCodes::GENERAL_ERROR, $ex->getErrorCode());
    }

    public function testCreateInvalidArgumentsException(): void
    {
        $ex = ReviewCliException::invalidArguments('Invalid repo format');

        $this->assertSame(ExceptionCodes::INVALID_ARGUMENTS, $ex->getErrorCode());
        $this->assertSame('Invalid repo format', $ex->getMessage());
    }

    public function testCreateNotFoundException(): void
    {
        $ex = ReviewCliException::notFound('PR not found');

        $this->assertSame(ExceptionCodes::NOT_FOUND, $ex->getErrorCode());
        $this->assertSame('PR not found', $ex->getMessage());
    }

    public function testCreateValidationException(): void
    {
        $ex = ReviewCliException::validation('Token is required');

        $this->assertSame(ExceptionCodes::VALIDATION_ERROR, $ex->getErrorCode());
        $this->assertSame('Token is required', $ex->getMessage());
    }

    public function testCreateRedisException(): void
    {
        $ex = ReviewCliException::redis('Connection refused');

        $this->assertSame(ExceptionCodes::REDIS_ERROR, $ex->getErrorCode());
        $this->assertSame('Connection refused', $ex->getMessage());
    }

    public function testCreateGithubApiException(): void
    {
        $ex = ReviewCliException::githubApi('API rate limit exceeded');

        $this->assertSame(ExceptionCodes::GITHUB_API_ERROR, $ex->getErrorCode());
        $this->assertSame('API rate limit exceeded', $ex->getMessage());
    }

    public function testCreateCheckoutException(): void
    {
        $ex = ReviewCliException::checkout('Clone failed');

        $this->assertSame(ExceptionCodes::CHECKOUT_ERROR, $ex->getErrorCode());
        $this->assertSame('Clone failed', $ex->getMessage());
    }

    public function testCreateTimeoutException(): void
    {
        $ex = ReviewCliException::timeout('Operation timed out');

        $this->assertSame(ExceptionCodes::TIMEOUT, $ex->getErrorCode());
        $this->assertSame('Operation timed out', $ex->getMessage());
    }

    public function testExceptionWithPrevious(): void
    {
        $previous = new \RuntimeException('Previous error');
        $ex = ReviewCliException::general('New error', $previous);

        $this->assertSame($previous, $ex->getPrevious());
    }

    public function testIsSilentFlag(): void
    {
        $ex = new ReviewCliException(
            ExceptionCodes::GENERAL_ERROR,
            'Silent error',
            null,
            true
        );

        $this->assertTrue($ex->isSilent());
    }

    public function testGetErrorName(): void
    {
        $this->assertSame('Success', ReviewCliException::getErrorName(ExceptionCodes::SUCCESS));
        $this->assertSame('General Error', ReviewCliException::getErrorName(ExceptionCodes::GENERAL_ERROR));
        $this->assertSame('Invalid Arguments', ReviewCliException::getErrorName(ExceptionCodes::INVALID_ARGUMENTS));
        $this->assertSame('Not Found', ReviewCliException::getErrorName(ExceptionCodes::NOT_FOUND));
        $this->assertSame('Validation Error', ReviewCliException::getErrorName(ExceptionCodes::VALIDATION_ERROR));
        $this->assertSame('Redis Error', ReviewCliException::getErrorName(ExceptionCodes::REDIS_ERROR));
        $this->assertSame('GitHub API Error', ReviewCliException::getErrorName(ExceptionCodes::GITHUB_API_ERROR));
        $this->assertSame('Checkout Error', ReviewCliException::getErrorName(ExceptionCodes::CHECKOUT_ERROR));
        $this->assertSame('Timeout', ReviewCliException::getErrorName(ExceptionCodes::TIMEOUT));
    }

    public function testGetErrorNameReturnsUnknownForInvalidCode(): void
    {
        $this->assertSame('Unknown Error', ReviewCliException::getErrorName(999));
    }

    public function testFormatForCliWithoutMessage(): void
    {
        $ex = new ReviewCliException(ExceptionCodes::NOT_FOUND);
        $formatted = $ex->formatForCli('github-review');

        $this->assertStringContainsString('Not Found', $formatted);
        $this->assertStringContainsString('github-review', $formatted);
    }

    public function testFormatForCliWithMessage(): void
    {
        $ex = new ReviewCliException(ExceptionCodes::INVALID_ARGUMENTS, 'Invalid repo format');
        $formatted = $ex->formatForCli('github-review');

        $this->assertStringContainsString('Invalid Arguments', $formatted);
        $this->assertStringContainsString('Invalid repo format', $formatted);
        $this->assertStringContainsString('github-review', $formatted);
    }

    public function testExceptionCodesConstants(): void
    {
        $this->assertSame(0, ExceptionCodes::SUCCESS);
        $this->assertSame(1, ExceptionCodes::GENERAL_ERROR);
        $this->assertSame(2, ExceptionCodes::INVALID_ARGUMENTS);
        $this->assertSame(3, ExceptionCodes::NOT_FOUND);
        $this->assertSame(4, ExceptionCodes::VALIDATION_ERROR);
        $this->assertSame(5, ExceptionCodes::REDIS_ERROR);
        $this->assertSame(6, ExceptionCodes::GITHUB_API_ERROR);
        $this->assertSame(7, ExceptionCodes::CHECKOUT_ERROR);
        $this->assertSame(8, ExceptionCodes::TIMEOUT);
    }
}
