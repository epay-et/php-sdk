<?php

declare(strict_types=1);

namespace Epay\Exception;

/** The request exceeded the configured timeout. */
final class EpayTimeoutException extends EpayConnectionException
{
}
