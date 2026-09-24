<?php

declare(strict_types=1);

namespace Epay\Laravel;

use Closure;
use Epay\Epay;
use Epay\Exception\EpayWebhookSignatureException;
use Epay\Webhooks;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Middleware that rejects any request whose `X-Epay-Signature` header does not
 * match the body, and stashes the verified event on the request.
 *
 * Register the route outside the CSRF-protected group — ePay is a third party
 * and sends no CSRF token. In `bootstrap/app.php` (Laravel 11+):
 *
 * ```php
 * ->withMiddleware(function (Middleware $middleware) {
 *     $middleware->validateCsrfTokens(except: ['webhooks/epay']);
 *     $middleware->alias(['epay.webhook' => VerifyEpayWebhook::class]);
 * })
 * ```
 *
 * Then:
 *
 * ```php
 * Route::post('/webhooks/epay', function (Request $request) {
 *     $event = VerifyEpayWebhook::event($request);
 *     ProcessEpayEvent::dispatch($event);   // acknowledge fast, process later
 *
 *     return response()->noContent(200);
 * })->middleware('epay.webhook');
 * ```
 */
final class VerifyEpayWebhook
{
    /** Request attribute the verified event is stored under. */
    public const ATTRIBUTE = 'epay_event';

    public function __construct(private readonly Epay $epay)
    {
    }

    /**
     * Verifies the signature, or aborts with `401`.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $event = $this->epay->webhooks->constructEvent(
                // getContent() returns the raw body Laravel buffered, not a
                // re-encoded array; re-encoding would change the digest.
                $request->getContent(),
                // headers->get() collapses a repeated header to its first
                // value; Request::header() can hand back an array.
                $request->headers->get(Webhooks::SIGNATURE_HEADER),
            );
        } catch (EpayWebhookSignatureException $error) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, $error->getMessage(), $error);
        }

        $request->attributes->set(self::ATTRIBUTE, $event);

        return $next($request);
    }

    /**
     * Reads the verified event off a request.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException if the middleware did not run, rather than
     *                           handing the caller an unverified payload.
     */
    public static function event(Request $request): array
    {
        $event = $request->attributes->get(self::ATTRIBUTE);

        if (!is_array($event)) {
            throw new \RuntimeException(
                'No verified ePay event on the request. Add the VerifyEpayWebhook middleware '
                . 'to this route.',
            );
        }

        return $event;
    }
}
