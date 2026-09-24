<?php

declare(strict_types=1);

namespace Epay\Resource;

use DateTimeInterface;
use Epay\Exception\EpayValidationException;
use Epay\Support\Validator;
use Epay\TransactionPage;
use Epay\Transport;

/** Read operations over transaction history. */
final class Transactions
{
    /** Fixed page size of `GET /v1/transactions`. */
    public const PAGE_SIZE = 10;

    /** Every status value the API recognises. */
    public const STATUSES = [
        'pending',
        'processing',
        'completed',
        'failed',
        'cancelled',
        'reversed',
        'refunding',
        'refunded',
    ];

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Returns a page of transactions, newest first, 10 per page.
     *
     * Filters persist across pages. When both dates are given the API rejects
     * ranges longer than 90 days.
     *
     * Iterate the returned page to walk every following page lazily.
     *
     * @param array{
     *     from?: string|DateTimeInterface,
     *     to?: string|DateTimeInterface,
     *     currency?: string,
     *     status?: string,
     *     cursor?: string
     * } $params
     *
     * @throws EpayValidationException if the range is inverted or a filter is malformed.
     */
    public function list(array $params = []): TransactionPage
    {
        $query = [];

        if (isset($params['from'])) {
            $query['from'] = Validator::dateParam($params['from'], 'from');
        }
        if (isset($params['to'])) {
            $query['to'] = Validator::dateParam($params['to'], 'to');
        }
        if (isset($query['from'], $query['to']) && $query['from'] > $query['to']) {
            throw new EpayValidationException(sprintf(
                'from (%s) must not be later than to (%s)',
                $query['from'],
                $query['to'],
            ));
        }

        if (isset($params['currency'])) {
            $query['currency'] = Validator::currency($params['currency'], 'currency');
        }

        if (isset($params['status'])) {
            if (!in_array($params['status'], self::STATUSES, true)) {
                throw new EpayValidationException(sprintf(
                    'status must be one of %s, received "%s"',
                    implode(', ', self::STATUSES),
                    $params['status'],
                ));
            }
            $query['status'] = $params['status'];
        }

        if (isset($params['cursor'])) {
            $query['cursor'] = $params['cursor'];
        }

        $response = $this->transport->request(method: 'GET', path: '/transactions', query: $query);

        return new TransactionPage(
            $response,
            fn (string $cursor): TransactionPage => $this->list(
                array_merge($params, ['cursor' => $cursor]),
            ),
        );
    }

    /**
     * Returns the current state of a single transaction.
     *
     * @return array<string, mixed>
     *
     * @throws \Epay\Exception\EpayNotFoundException `404` — no such transaction.
     */
    public function retrieve(string $reference): array
    {
        return $this->transport->request(
            method: 'GET',
            path: '/transactions/' . rawurlencode(Validator::reference($reference)),
        );
    }

    /**
     * Returns every event recorded against a transaction, earliest first.
     *
     * `events` is empty until the first provider event arrives.
     *
     * @return array{reference: string, events: list<array<string, mixed>>}
     *
     * @throws \Epay\Exception\EpayNotFoundException `404` — no such transaction.
     */
    public function timeline(string $reference): array
    {
        return $this->transport->request(
            method: 'GET',
            path: '/transactions/' . rawurlencode(Validator::reference($reference)) . '/timeline',
        );
    }
}
