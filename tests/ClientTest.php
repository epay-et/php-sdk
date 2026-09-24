<?php

declare(strict_types=1);

namespace Epay\Tests;

use Epay\Epay;
use Epay\Exception\EpayApiException;
use Epay\Exception\EpayAuthenticationException;
use Epay\Exception\EpayBadRequestException;
use Epay\Exception\EpayConfigException;
use Epay\Exception\EpayNotFoundException;
use Epay\Exception\EpayPermissionDeniedException;
use Epay\Exception\EpayRateLimitException;
use Epay\Exception\EpayServerException;
use Epay\Exception\EpayValidationException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A scripted PSR-18 client, so tests never touch a network. */
final class ScriptedHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $calls = [];

    /** @param list<ResponseInterface|\Throwable> $replies */
    public function __construct(private array $replies)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls[] = $request;

        $reply = array_shift($this->replies);
        if ($reply === null) {
            throw new \AssertionError(sprintf(
                'unexpected extra request %s %s',
                $request->getMethod(),
                (string) $request->getUri(),
            ));
        }
        if ($reply instanceof \Throwable) {
            throw $reply;
        }

        return $reply;
    }

    public function decodedBody(int $index = 0): mixed
    {
        $contents = (string) $this->calls[$index]->getBody();

        return $contents === '' ? null : json_decode($contents, true);
    }
}

final class ClientTest extends TestCase
{
    private const API_KEY = 'sk_test_0123456789abcdef';

    private const SESSION = [
        'reference' => 'PAB12CD3420260813',
        'checkoutUrl' => 'https://checkout.epayethiopia.com/pay/PAB12CD3420260813',
        'status' => 'success',
        'expiresAt' => '2026-08-13T12:30:00.000Z',
    ];

    /**
     * @param list<ResponseInterface|\Throwable> $replies
     * @param array<string, mixed> $options
     *
     * @return array{Epay, ScriptedHttpClient}
     */
    private function client(array $replies, array $options = []): array
    {
        $http = new ScriptedHttpClient($replies);
        $epay = new Epay(array_merge([
            'api_key' => self::API_KEY,
            'max_retries' => 0,
            'base_url' => 'https://api.epayethiopia.com/v1',
        ], $options), $http);

        return [$epay, $http];
    }

    /** @param array<string, string> $headers */
    private static function json(int $status, mixed $body, array $headers = []): Response
    {
        if ($body === null) {
            return new Response($status, $headers);
        }

        $encoded = json_encode($body, JSON_THROW_ON_ERROR);

        return new Response($status, $headers + ['Content-Type' => 'application/json'], $encoded);
    }

    // --- construction -------------------------------------------------------

    public function testTheClientDerivesModeFromTheKeyPrefix(): void
    {
        [$live] = $this->client([], ['api_key' => 'sk_live_abc123']);
        [$test] = $this->client([], ['api_key' => 'sk_test_abc123']);

        self::assertSame('live', $live->mode());
        self::assertFalse($live->isSandbox());
        self::assertTrue($test->isSandbox());
    }

    public function testANonSecretKeyIsRejected(): void
    {
        $this->expectException(EpayConfigException::class);
        new Epay(['api_key' => 'pk_live_abc123'], new ScriptedHttpClient([]));
    }

    public function testToStringNeverLeaksTheApiKey(): void
    {
        [$epay] = $this->client([], ['api_key' => 'sk_live_supersecretvalue1234']);

        $rendered = (string) $epay;
        self::assertStringNotContainsString('supersecretvalue', $rendered);
        self::assertStringContainsString('sk_live_…1234', $rendered);
    }

    // --- initialize ---------------------------------------------------------

    public function testInitializePostsToTheDocumentedPath(): void
    {
        [$epay, $http] = $this->client([self::json(200, self::SESSION)]);

        $session = $epay->payments->initialize([
            'amount' => '250.00',
            'currencyCode' => 'ETB',
            'customerPhone' => '+251911234567',
            'merchantReference' => 'order_123',
        ]);

        self::assertSame(self::SESSION, $session);
        self::assertCount(1, $http->calls);
        self::assertSame('POST', $http->calls[0]->getMethod());
        self::assertSame(
            'https://api.epayethiopia.com/v1/transactions/initialize',
            (string) $http->calls[0]->getUri(),
        );
        self::assertSame('Bearer ' . self::API_KEY, $http->calls[0]->getHeaderLine('Authorization'));
        self::assertSame('application/json', $http->calls[0]->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('epay-php/', $http->calls[0]->getHeaderLine('User-Agent'));
    }

    public function testInitializeNormalizesAmountCurrencyAndPhone(): void
    {
        [$epay, $http] = $this->client([self::json(200, self::SESSION)]);

        $epay->payments->initialize([
            'amount' => 250,
            'currencyCode' => 'etb',
            'customerPhone' => '0911234567',
        ]);

        self::assertSame([
            'amount' => '250.00',
            'currencyCode' => 'ETB',
            'customerPhone' => '+251911234567',
        ], $http->decodedBody());
    }

    public function testInitializeOmitsOptionalFieldsNotSet(): void
    {
        [$epay, $http] = $this->client([self::json(200, self::SESSION)]);

        $epay->payments->initialize([
            'amount' => '10.00',
            'currencyCode' => 'ETB',
            'customerPhone' => '0911234567',
            'email' => 'abebe@example.com',
        ]);

        $body = $http->decodedBody();
        self::assertSame('abebe@example.com', $body['email']);
        self::assertArrayNotHasKey('merchantReference', $body);
        self::assertArrayNotHasKey('callbackUrl', $body);
    }

    public function testInitializeSendsTheCallerIdempotencyKey(): void
    {
        [$epay, $http] = $this->client([self::json(200, self::SESSION)]);

        $epay->payments->initialize(
            ['amount' => '10.00', 'currencyCode' => 'ETB', 'customerPhone' => '0911234567'],
            'order_123',
        );

        self::assertSame('order_123', $http->calls[0]->getHeaderLine('x-idempotency-key'));
    }

    public function testInitializeGeneratesAnIdempotencyKeyWhenOmitted(): void
    {
        [$epay, $http] = $this->client([self::json(200, self::SESSION)]);

        $epay->payments->initialize([
            'amount' => '10.00',
            'currencyCode' => 'ETB',
            'customerPhone' => '0911234567',
        ]);

        self::assertStringStartsWith('epay_', $http->calls[0]->getHeaderLine('x-idempotency-key'));
    }

    public function testInitializeValidatesBeforeSpendingARequest(): void
    {
        [$epay, $http] = $this->client([]);

        $caught = null;
        try {
            $epay->payments->initialize([
                'amount' => '-1',
                'currencyCode' => 'ETB',
                'customerPhone' => '0911234567',
            ]);
        } catch (EpayValidationException $error) {
            $caught = $error;
        }

        self::assertInstanceOf(EpayValidationException::class, $caught);
        self::assertCount(0, $http->calls, 'no request should reach the network');
    }

    // --- verify and cancel --------------------------------------------------

    public function testVerifyHitsTheVerifyPath(): void
    {
        [$epay, $http] = $this->client([self::json(200, ['status' => 'completed', 'serviceFee' => '7.50'])]);

        $receipt = $epay->payments->verify('PAB12CD3420260813');

        self::assertSame(
            'https://api.epayethiopia.com/v1/transactions/PAB12CD3420260813/verify',
            (string) $http->calls[0]->getUri(),
        );
        self::assertSame('7.50', $receipt['serviceFee']);
    }

    public function testVerifyReportsAnIncompleteTransaction(): void
    {
        [$epay] = $this->client([
            self::json(400, [
                'status' => 'error',
                'data' => ['message' => 'Transaction is not completed. Current status: pending'],
            ]),
        ]);

        $this->expectException(EpayBadRequestException::class);
        $this->expectExceptionMessageMatches('/Current status: pending/');
        $epay->payments->verify('PAB1');
    }

    public function testCancelResolvesOn204(): void
    {
        [$epay, $http] = $this->client([new Response(204)]);

        $epay->payments->cancel('PAB12CD3420260813');

        self::assertSame('POST', $http->calls[0]->getMethod());
        self::assertSame(
            'https://api.epayethiopia.com/v1/transactions/PAB12CD3420260813/cancel',
            (string) $http->calls[0]->getUri(),
        );
    }

    public function testCancelIsNeverRetried(): void
    {
        [$epay, $http] = $this->client(
            [self::json(500, null), self::json(500, null), self::json(500, null), self::json(500, null)],
            ['max_retries' => 3],
        );

        $caught = null;
        try {
            $epay->payments->cancel('PAB1');
        } catch (EpayApiException $error) {
            $caught = $error;
        }

        self::assertInstanceOf(EpayServerException::class, $caught);
        self::assertCount(1, $http->calls, 'a non-idempotent POST must be attempted exactly once');
    }

    public function testReferencesAreUrlEncoded(): void
    {
        [$epay, $http] = $this->client([self::json(200, [])]);

        $epay->transactions->retrieve('PAB 1/2');

        self::assertSame(
            'https://api.epayethiopia.com/v1/transactions/PAB%201%2F2',
            (string) $http->calls[0]->getUri(),
        );
    }

    // --- transactions -------------------------------------------------------

    public function testListBuildsTheDocumentedQuery(): void
    {
        [$epay, $http] = $this->client([
            self::json(200, ['data' => [], 'nextCursor' => null, 'hasMore' => false]),
        ]);

        $epay->transactions->list([
            'status' => 'completed',
            'currency' => 'etb',
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ]);

        $query = [];
        parse_str($http->calls[0]->getUri()->getQuery(), $query);

        self::assertSame('completed', $query['status']);
        self::assertSame('ETB', $query['currency']);
        self::assertSame('2026-08-01', $query['from']);
        self::assertSame('2026-08-31', $query['to']);
    }

    public function testListRejectsAnInvertedRangeAndUnknownStatus(): void
    {
        [$epay, $http] = $this->client([]);

        $inverted = null;
        try {
            $epay->transactions->list(['from' => '2026-08-31', 'to' => '2026-08-01']);
        } catch (EpayValidationException $error) {
            $inverted = $error;
        }
        self::assertInstanceOf(EpayValidationException::class, $inverted);

        $unknownStatus = null;
        try {
            $epay->transactions->list(['status' => 'expired']);
        } catch (EpayValidationException $error) {
            $unknownStatus = $error;
        }
        self::assertInstanceOf(EpayValidationException::class, $unknownStatus);

        self::assertCount(0, $http->calls);
    }

    public function testIteratingAPageWalksEveryFollowingPage(): void
    {
        $transaction = static fn (string $reference): array => [
            'reference' => $reference,
            'merchantReference' => null,
            'status' => 'completed',
            'amount' => '10.00',
            'currencyCode' => 'ETB',
            'paidAt' => null,
            'createdAt' => '2026-08-13T11:30:00.000Z',
        ];

        [$epay, $http] = $this->client([
            self::json(200, [
                'data' => [$transaction('P1'), $transaction('P2')],
                'nextCursor' => 'cur_1',
                'hasMore' => true,
            ]),
            self::json(200, ['data' => [$transaction('P3')], 'nextCursor' => 'cur_2', 'hasMore' => true]),
            self::json(200, ['data' => [$transaction('P4')], 'nextCursor' => null, 'hasMore' => false]),
        ]);

        $seen = [];
        foreach ($epay->transactions->list(['status' => 'completed']) as $row) {
            $seen[] = $row['reference'];
        }

        self::assertSame(['P1', 'P2', 'P3', 'P4'], $seen);
        self::assertCount(3, $http->calls);

        // The cursor advances while the filter persists across pages.
        foreach ([1 => 'cur_1', 2 => 'cur_2'] as $index => $cursor) {
            $query = [];
            parse_str($http->calls[$index]->getUri()->getQuery(), $query);
            self::assertSame($cursor, $query['cursor']);
            self::assertSame('completed', $query['status']);
        }
    }

    public function testToArrayStopsFetchingOnceTheLimitIsReached(): void
    {
        $row = ['reference' => 'P', 'status' => 'pending'];

        [$epay, $http] = $this->client([
            self::json(200, ['data' => [$row, $row], 'nextCursor' => 'cur_1', 'hasMore' => true]),
            self::json(200, ['data' => [$row], 'nextCursor' => 'cur_2', 'hasMore' => true]),
        ]);

        $collected = $epay->transactions->list()->toArray(3);

        self::assertCount(3, $collected);
        self::assertCount(2, $http->calls, 'should not fetch a page it does not need');
    }

    // --- payment providers --------------------------------------------------

    public function testProvidersAreUnwrappedFromTheEnvelope(): void
    {
        [$epay, $http] = $this->client([
            self::json(200, [
                'data' => [['providerCode' => 'telebirr', 'providerName' => 'Telebirr', 'flowCode' => 'ussd']],
            ]),
        ]);

        $providers = $epay->paymentProviders->list();

        self::assertSame(
            'https://api.epayethiopia.com/v1/payment-providers/list',
            (string) $http->calls[0]->getUri(),
        );
        self::assertSame('telebirr', $providers[0]['providerCode']);
    }

    public function testListEnabledFiltersOutDisabledProviders(): void
    {
        [$epay] = $this->client([
            self::json(200, [
                'data' => [
                    ['providerCode' => 'cbe_birr', 'isEnabled' => true],
                    ['providerCode' => 'awash_bank', 'isEnabled' => false],
                ],
            ]),
        ]);

        $enabled = $epay->paymentProviders->listEnabled();

        self::assertCount(1, $enabled);
        self::assertSame('cbe_birr', $enabled[0]['providerCode']);
    }

    public function testAMissingPermissionSurfacesAsPermissionDenied(): void
    {
        [$epay] = $this->client([
            self::json(403, [
                'statusCode' => 403,
                'message' => 'Missing get_platform_payment_provider permission',
                'error' => 'Forbidden',
            ]),
        ]);

        $this->expectException(EpayPermissionDeniedException::class);
        $this->expectExceptionMessageMatches('/get_platform_payment_provider/');
        $epay->paymentProviders->getAll();
    }

    // --- transport behaviour ------------------------------------------------

    public function testA429IsRetriedAndReusesTheIdempotencyKey(): void
    {
        [$epay, $http] = $this->client(
            [
                self::json(429, ['status' => 'error', 'data' => ['message' => 'Rate limit exceeded']], ['Retry-After' => '0']),
                self::json(200, self::SESSION),
            ],
            ['max_retries' => 2],
        );

        $session = $epay->payments->initialize(
            ['amount' => '10.00', 'currencyCode' => 'ETB', 'customerPhone' => '0911234567'],
            'order_9',
        );

        self::assertSame(self::SESSION, $session);
        self::assertCount(2, $http->calls);
        self::assertSame('order_9', $http->calls[0]->getHeaderLine('x-idempotency-key'));
        self::assertSame(
            'order_9',
            $http->calls[1]->getHeaderLine('x-idempotency-key'),
            'the retry must carry the same key or it would double-charge',
        );
    }

    public function testA5xxOnAGetIsRetried(): void
    {
        [$epay, $http] = $this->client(
            [self::json(503, null), self::json(200, ['reference' => 'P1'])],
            ['max_retries' => 1],
        );

        self::assertSame('P1', $epay->transactions->retrieve('P1')['reference']);
        self::assertCount(2, $http->calls);
    }

    public function testA4xxIsNotRetried(): void
    {
        [$epay, $http] = $this->client(
            [self::json(401, ['status' => 'error', 'data' => ['message' => 'Invalid API key']])],
            ['max_retries' => 3],
        );

        $caught = null;
        try {
            $epay->transactions->retrieve('P1');
        } catch (EpayApiException $error) {
            $caught = $error;
        }

        self::assertInstanceOf(EpayAuthenticationException::class, $caught);
        self::assertCount(1, $http->calls);
    }

    public function testTheRateLimitErrorExposesRetryAfter(): void
    {
        [$epay] = $this->client(
            [self::json(429, null, ['Retry-After' => '30'])],
            ['max_retries' => 0],
        );

        $caught = null;
        try {
            $epay->transactions->retrieve('P1');
        } catch (EpayApiException $error) {
            $caught = $error;
        }

        self::assertInstanceOf(EpayRateLimitException::class, $caught);
        self::assertSame(30.0, $caught->retryAfterSeconds());
    }

    public function testErrorsCarryTheStatusBodyAndRequest(): void
    {
        $body = ['status' => 'error', 'data' => ['message' => 'gone']];
        [$epay] = $this->client([self::json(404, $body)]);

        $caught = null;
        try {
            $epay->transactions->retrieve('P1');
        } catch (EpayApiException $error) {
            $caught = $error;
        }

        self::assertInstanceOf(EpayNotFoundException::class, $caught);
        self::assertSame(404, $caught->status);
        self::assertSame($body, $caught->body);
        self::assertSame('GET /transactions/P1', $caught->request);
    }

    public function testDefaultHeadersAreMergedIntoEveryRequest(): void
    {
        [$epay, $http] = $this->client(
            [self::json(200, [])],
            ['default_headers' => ['X-Trace-Id' => 'trace_1']],
        );

        $epay->transactions->retrieve('P1');

        self::assertSame('trace_1', $http->calls[0]->getHeaderLine('X-Trace-Id'));
    }

    public function testTheEscapeHatchReachesUnmodelledEndpoints(): void
    {
        [$epay, $http] = $this->client([self::json(200, ['ok' => true])]);

        $data = $epay->request('GET', '/some/future/endpoint', ['limit' => 10]);

        self::assertSame(['ok' => true], $data);
        self::assertSame(
            'https://api.epayethiopia.com/v1/some/future/endpoint?limit=10',
            (string) $http->calls[0]->getUri(),
        );
    }
}
