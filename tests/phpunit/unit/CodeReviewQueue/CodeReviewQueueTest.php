<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewQueue;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the push-support additions to CodeReviewQueue: the "kind" field,
 * buildPushEnvelope(), and the jobKind() back-compat resolver. These exercise
 * only pure envelope/kind logic — no Redis is touched.
 */
class CodeReviewQueueTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../src/CodeReviewQueue.php';
    }

    public function testBuildEnvelopeMarksPrKind(): void
    {
        $envelope = \CodeReviewQueue::buildEnvelope([
            'repo' => 'owner/repo',
            'pr_number' => 42,
            'action' => 'opened',
            'head_branch' => 'feature/x',
            'base_branch' => 'main',
            'pr_url' => 'https://github.com/owner/repo/pull/42',
            'author' => 'octocat',
            'author_url' => 'https://github.com/octocat',
            'sha' => 'abc123',
        ]);

        $this->assertSame('pr', $envelope['kind']);
        $this->assertSame('owner/repo', $envelope['repo']);
        $this->assertSame(42, $envelope['pr_number']);
        $this->assertSame(0, $envelope['retry_count']);
    }

    public function testBuildPushEnvelopeShape(): void
    {
        $envelope = \CodeReviewQueue::buildPushEnvelope([
            'repo' => 'owner/repo',
            'ref' => 'main',
            'before_sha' => 'aaaaaaa',
            'after_sha' => 'bbbbbbb',
            'author' => 'octocat',
            'author_url' => 'https://github.com/octocat',
            'is_bot' => false,
        ]);

        $this->assertSame('push', $envelope['kind']);
        $this->assertSame('push', $envelope['action']);
        $this->assertSame('owner/repo', $envelope['repo']);
        $this->assertSame('main', $envelope['ref']);
        $this->assertSame('aaaaaaa', $envelope['before_sha']);
        $this->assertSame('bbbbbbb', $envelope['after_sha']);
        $this->assertSame('octocat', $envelope['author']);
        $this->assertFalse($envelope['is_bot']);
        $this->assertSame(['full'], $envelope['audit_types']);
        $this->assertSame([], $envelope['options']);
        $this->assertSame(0, $envelope['retry_count']);
        // No PR fields on a push envelope.
        $this->assertArrayNotHasKey('pr_number', $envelope);
    }

    public function testBuildPushEnvelopeDefaultsBeforeShaEmpty(): void
    {
        $envelope = \CodeReviewQueue::buildPushEnvelope([
            'repo' => 'owner/repo',
            'ref' => 'feature/new',
            'after_sha' => 'bbbbbbb',
        ]);

        // Brand-new branch: before_sha absent -> empty (worker uses after^).
        $this->assertSame('', $envelope['before_sha']);
        $this->assertSame('bbbbbbb', $envelope['after_sha']);
    }

    public function testBuildPushEnvelopeCarriesAuditTypesAndOptions(): void
    {
        $envelope = \CodeReviewQueue::buildPushEnvelope([
            'repo' => 'owner/repo',
            'ref' => 'main',
            'after_sha' => 'bbbbbbb',
            'audit_types' => ['security', 'logic'],
            'options' => ['combine' => true],
        ]);

        $this->assertSame(['security', 'logic'], $envelope['audit_types']);
        $this->assertSame(['combine' => true], $envelope['options']);
    }

    public function testJobKindDefaultsToPrWhenMissing(): void
    {
        // Back-compat: envelopes enqueued before push support have no "kind".
        $this->assertSame('pr', \CodeReviewQueue::jobKind(['repo' => 'owner/repo', 'pr_number' => 1]));
    }

    public function testJobKindExplicitPr(): void
    {
        $this->assertSame('pr', \CodeReviewQueue::jobKind(['kind' => 'pr']));
    }

    public function testJobKindPush(): void
    {
        $this->assertSame('push', \CodeReviewQueue::jobKind(['kind' => 'push']));
    }

    public function testJobKindUnknownFallsBackToPr(): void
    {
        $this->assertSame('pr', \CodeReviewQueue::jobKind(['kind' => 'something-else']));
    }
}
