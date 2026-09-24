<?php

declare(strict_types=1);

namespace Epay\Exception;

/** `401` — the API key is missing, malformed, invalid, or revoked. */
final class EpayAuthenticationException extends EpayApiException
{
}
