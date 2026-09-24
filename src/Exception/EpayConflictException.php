<?php

declare(strict_types=1);

namespace Epay\Exception;

/** `409` — the request conflicts with the current state of the resource. */
final class EpayConflictException extends EpayApiException
{
}
