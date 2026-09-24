<?php

declare(strict_types=1);

namespace Epay\Exception;

/** `404` — no such transaction, or no payment source on the account. */
final class EpayNotFoundException extends EpayApiException
{
}
