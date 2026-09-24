<?php

declare(strict_types=1);

namespace Epay\Support;

use DateTimeInterface;
use Epay\Exception\EpayValidationException;

/** Validation and normalization helpers for request values. */
final class Validator
{
    /** Largest amount the API accepts. */
    public const MAX_AMOUNT = '999999999.99';

    /** Maximum span the transaction list endpoint allows between `from` and `to`. */
    public const MAX_LIST_RANGE_DAYS = 90;

    /** Amounts the API accepts: up to 9 integer digits and 2 decimal places. */
    private const AMOUNT_PATTERN = '/^\d{1,9}(\.\d{1,2})?$/';

    /**
     * Normalizes an amount to the numeric string the API expects.
     *
     * The result always carries exactly two decimal places, so `250`, `'250'`,
     * and `'250.5'` become `'250.00'`, `'250.00'`, and `'250.50'`.
     *
     * Prefer a string: floats cannot represent every decimal amount exactly.
     * Integers and floats are accepted and rounded to two places by
     * number_format, then validated the same way a string would be.
     *
     * @throws EpayValidationException if the value is not a positive amount in range.
     */
    public static function amount(string|int|float $value): string
    {
        if (is_string($value)) {
            $candidate = trim($value);
        } else {
            if (is_float($value) && (is_nan($value) || is_infinite($value))) {
                throw new EpayValidationException('amount must be a finite number');
            }
            if ($value <= 0) {
                throw new EpayValidationException(
                    sprintf('amount must be greater than 0, received %s', var_export($value, true)),
                );
            }
            $candidate = number_format((float) $value, 2, '.', '');
        }

        // The pattern caps the integer part at 9 digits, which is exactly the
        // MAX_AMOUNT ceiling, so no separate range comparison is needed.
        if (preg_match(self::AMOUNT_PATTERN, $candidate) !== 1) {
            throw new EpayValidationException(sprintf(
                'amount must be a positive numeric string of at most %s, with at most 9 '
                . 'integer digits and 2 decimal places, received "%s"',
                self::MAX_AMOUNT,
                is_string($value) ? $value : var_export($value, true),
            ));
        }

        [$whole, $fraction] = array_pad(explode('.', $candidate, 2), 2, '');

        // Reject zero without float math: any 0(.00) form is invalid.
        if (ltrim($whole, '0') === '' && rtrim($fraction, '0') === '') {
            throw new EpayValidationException(
                sprintf('amount must be greater than 0, received "%s"', $candidate),
            );
        }

        // Pad to a consistent 2 decimal places so logs and reconciliation line up.
        return $whole . '.' . str_pad($fraction, 2, '0');
    }

    /**
     * Normalizes an Ethiopian mobile number to `+251XXXXXXXXX`.
     *
     * Accepts local (`0911234567`), bare national (`911234567`), and
     * international (`251911234567`, `+251911234567`) forms, with spaces,
     * dashes, and parentheses anywhere. Both Ethio Telecom (`9…`) and Safaricom
     * Ethiopia (`7…`) ranges are recognised.
     *
     * @throws EpayValidationException if the value is not a recognised Ethiopian mobile number.
     */
    public static function phone(string $value): string
    {
        $cleaned = preg_replace('/[\s()\-.]/', '', $value) ?? '';
        $digits = str_starts_with($cleaned, '+') ? substr($cleaned, 1) : $cleaned;

        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            throw new EpayValidationException(sprintf(
                'customerPhone must contain only digits and separators, received "%s"',
                $value,
            ));
        }

        $subscriber = match (true) {
            preg_match('/^251[79]\d{8}$/', $digits) === 1 => substr($digits, 3),
            preg_match('/^0[79]\d{8}$/', $digits) === 1 => substr($digits, 1),
            preg_match('/^[79]\d{8}$/', $digits) === 1 => $digits,
            default => null,
        };

        if ($subscriber === null) {
            throw new EpayValidationException(sprintf(
                'customerPhone must be an Ethiopian mobile number such as +251911234567 '
                . 'or 0911234567, received "%s"',
                $value,
            ));
        }

        return '+251' . $subscriber;
    }

    /**
     * Validates a 3-letter ISO 4217 code and uppercases it.
     *
     * @throws EpayValidationException if the value is not three letters.
     */
    public static function currency(string $value, string $field = 'currencyCode'): string
    {
        $code = strtoupper(trim($value));
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new EpayValidationException(sprintf(
                '%s must be a 3-letter ISO 4217 code such as ETB, received "%s"',
                $field,
                $value,
            ));
        }

        return $code;
    }

    /** Normalizes a date to the `YYYY-MM-DD` form the list filters take. */
    public static function dateParam(string|DateTimeInterface $value, string $field): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = trim($value);
        if ($text === '') {
            throw new EpayValidationException(sprintf('%s must not be empty', $field));
        }

        return $text;
    }

    /**
     * Validates a transaction reference is present and trims it.
     *
     * @throws EpayValidationException if the reference is empty.
     */
    public static function reference(string $value): string
    {
        $reference = trim($value);
        if ($reference === '') {
            throw new EpayValidationException('reference must be a non-empty string');
        }

        return $reference;
    }

    /** Masks a secret so it can be logged safely. */
    public static function maskSecret(string $value): string
    {
        if (strlen($value) <= 12) {
            return '***';
        }

        return substr($value, 0, 8) . '…' . substr($value, -4);
    }
}
