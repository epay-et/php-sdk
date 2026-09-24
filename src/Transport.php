<?php

declare(strict_types=1);

namespace Epay;

use Epay\Exception\EpayApiException;
use Epay\Exception\EpayConnectionException;
use Epay\Exception\EpayTimeoutException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/** HTTP transport: URL building, retries with backoff, and error mapping. */
final class Transport
{
    /** Status codes worth a second attempt, alongside anything 5xx. */
    private const RETRYABLE_STATUSES = [408, 409, 429];

    /** Base delay for exponential backoff, in microseconds. */
    private const BACKOFF_BASE_US = 500_000;

    /** Ceiling for a single backoff delay, in microseconds. */
    private const BACKOFF_MAX_US = 8_000_000;

    private ClientInterface $http;

    public function __construct(
        private readonly Config $config,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new GuzzleClient([
            'timeout' => $config->timeout,
            'connect_timeout' => $config->timeout,
            // Non-2xx responses are mapped here, not thrown by Guzzle, so the
            // retry policy and error hierarchy stay in one place.
            'http_errors' => false,
        ]);
    }

    /**
     * Sends a request, retrying transient failures, and returns the parsed body.
     *
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     *
     * @throws EpayApiException for any non-2xx the retry budget did not clear.
     * @throws EpayConnectionException when no response was ever received.
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
        ?bool $retryable = null,
    ): mixed {
        $url = $this->buildUrl($path, $query);
        $label = sprintf('%s %s', $method, $path);
        $isRetryable = $retryable ?? $method === 'GET';
        $attempts = $isRetryable ? $this->config->maxRetries + 1 : 1;

        $request = new Request(
            $method,
            $url,
            $this->buildHeaders($headers, $body !== null),
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );

        $lastError = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $isLast = $attempt === $attempts - 1;

            try {
                $response = $this->http->sendRequest($request);
            } catch (GuzzleException | ClientExceptionInterface $error) {
                $lastError = $this->translateTransportError($error, $url);
                if ($isLast) {
                    throw $lastError;
                }
                usleep($this->backoffDelay($attempt, null));
                continue;
            }

            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return $this->parseBody($response);
            }

            if ($isLast || !$this->shouldRetryStatus($status)) {
                throw EpayApiException::fromResponse(
                    $status,
                    $this->parseBody($response),
                    $this->collectHeaders($response),
                    $label,
                );
            }

            $retryAfter = $response->getHeaderLine('Retry-After');
            usleep($this->backoffDelay($attempt, $retryAfter === '' ? null : $retryAfter));
        }

        throw $lastError ?? new EpayConnectionException(
            sprintf('%s failed after %d attempts', $label, $attempts),
        );
    }

    /**
     * Joins the base URL, path, and query string, dropping null values.
     *
     * @param array<string, scalar|null> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $normalized = str_starts_with($path, '/') ? $path : '/' . $path;
        $filtered = array_filter($query, static fn ($value) => $value !== null);

        $queryString = $filtered === [] ? '' : '?' . http_build_query($filtered);

        return $this->config->baseUrl . $normalized . $queryString;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function buildHeaders(array $headers, bool $hasBody): array
    {
        $merged = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->config->apiKey,
            'User-Agent' => 'epay-php/' . Epay::VERSION,
        ];
        $merged = array_merge($merged, $this->config->defaultHeaders, $headers);

        if ($hasBody) {
            $merged['Content-Type'] = 'application/json';
        }

        return $merged;
    }

    private function shouldRetryStatus(int $status): bool
    {
        return $status >= 500 || in_array($status, self::RETRYABLE_STATUSES, true);
    }

    /**
     * Exponential backoff with jitter, honouring `Retry-After` when present.
     *
     * Jitter keeps concurrent clients from retrying in lockstep.
     */
    private function backoffDelay(int $attempt, ?string $retryAfter): int
    {
        if ($retryAfter !== null) {
            $seconds = is_numeric($retryAfter)
                ? (float) $retryAfter
                : max(0, (strtotime($retryAfter) ?: time()) - time());

            return (int) min($seconds * 1_000_000, self::BACKOFF_MAX_US);
        }

        $ceiling = (int) min(self::BACKOFF_BASE_US * (2 ** $attempt), self::BACKOFF_MAX_US);

        return (int) ($ceiling * (0.5 + (mt_rand() / mt_getrandmax()) * 0.5));
    }

    /**
     * Lowercases header names so lookups are predictable.
     *
     * @return array<string, string>
     */
    private function collectHeaders(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * Parses a response body as JSON, falling back to raw text.
     *
     * `204 No Content` (returned by cancel) and empty bodies become null.
     */
    private function parseBody(ResponseInterface $response): mixed
    {
        if ($response->getStatusCode() === 204) {
            return null;
        }

        $text = (string) $response->getBody();
        if ($text === '') {
            return null;
        }

        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $text;
        }
    }

    /**
     * Maps a transport failure onto the SDK hierarchy.
     *
     * Guzzle reports both connect and read timeouts as cURL error 28, so the
     * message is the only reliable signal for distinguishing them.
     */
    private function translateTransportError(\Throwable $error, string $url): EpayConnectionException
    {
        $message = strtolower($error->getMessage());

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return new EpayTimeoutException(
                sprintf('Request to %s timed out after %ss', $url, $this->config->timeout),
                0,
                $error,
            );
        }

        return new EpayConnectionException(
            sprintf('Could not reach the ePay API at %s: %s', $url, $error->getMessage()),
            0,
            $error,
        );
    }
}
