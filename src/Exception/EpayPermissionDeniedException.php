<?php

declare(strict_types=1);

namespace Epay\Exception;

/** `403` — the caller IP is not whitelisted, or the key lacks a permission. */
final class EpayPermissionDeniedException extends EpayApiException
{
}
