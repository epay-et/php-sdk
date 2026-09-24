<?php

declare(strict_types=1);

namespace Epay;

use Closure;
use Generator;
use IteratorAggregate;
use Traversable;

/**
 * One page of transactions, plus the means to walk the rest.
 *
 * Iterating the page walks every remaining transaction across every following
 * page, fetching lazily:
 *
 * ```php
 * foreach ($epay->transactions->list(['status' => 'completed']) as $transaction) {
 *     echo $transaction['reference'];
 * }
 * ```
 *
 * Use {@see data()} instead when you want exactly the current page.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class TransactionPage implements IteratorAggregate
{
    /** @var list<array<string, mixed>> */
    private array $data;

    private ?string $nextCursor;

    private bool $hasMore;

    /**
     * @param array<string, mixed>|null $response
     * @param Closure(string): self $fetchNext
     */
    public function __construct(?array $response, private readonly Closure $fetchNext)
    {
        $this->data = $response['data'] ?? [];
        $this->nextCursor = $response['nextCursor'] ?? null;
        $this->hasMore = (bool) ($response['hasMore'] ?? false);
    }

    /**
     * Up to 10 transactions, newest first — exactly this page.
     *
     * @return list<array<string, mixed>>
     */
    public function data(): array
    {
        return $this->data;
    }

    /** Cursor for the next page, or null on the last page. */
    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    /** Whether more transactions exist beyond this page. */
    public function hasMore(): bool
    {
        return $this->hasMore;
    }

    /** Fetches the next page, or returns null on the last page. */
    public function nextPage(): ?self
    {
        if (!$this->hasMore || $this->nextCursor === null) {
            return null;
        }

        return ($this->fetchNext)($this->nextCursor);
    }

    /**
     * Yields this page and each following page in turn.
     *
     * @return Generator<int, self>
     */
    public function pages(): Generator
    {
        $page = $this;
        while ($page !== null) {
            yield $page;
            $page = $page->nextPage();
        }
    }

    /**
     * Yields every transaction from this page onward, fetching pages on demand.
     *
     * @return Traversable<int, array<string, mixed>>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->pages() as $page) {
            yield from $page->data();
        }
    }

    /**
     * Collects transactions from this page onward into an array.
     *
     * @param int|null $limit Stop after this many. Omit to collect everything,
     *                        which for a busy account may be many requests.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(?int $limit = null): array
    {
        $collected = [];
        foreach ($this as $transaction) {
            $collected[] = $transaction;
            if ($limit !== null && count($collected) >= $limit) {
                break;
            }
        }

        return $collected;
    }
}
