<?php

declare(strict_types=1);

namespace Epay\Tests;

use Epay\Exception\EpayValidationException;
use Epay\Support\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function testAmountNormalizesToTwoDecimals(string|int|float $value, string $expected): void
    {
        self::assertSame($expected, Validator::amount($value));
    }

    /** @return iterable<array{string|int|float, string}> */
    public static function validAmounts(): iterable
    {
        yield ['250.00', '250.00'];
        yield ['250', '250.00'];
        yield ['250.5', '250.50'];
        yield ['  42.10  ', '42.10'];
        yield [250, '250.00'];
        yield [1, '1.00'];
        yield [1.5, '1.50'];
        yield ['0.01', '0.01'];
        yield ['999999999.99', '999999999.99'];
    }

    #[DataProvider('invalidAmounts')]
    public function testAmountRejectsWhatTheApiWould(string|int|float $value): void
    {
        $this->expectException(EpayValidationException::class);
        Validator::amount($value);
    }

    /** @return iterable<string, array{string|int|float}> */
    public static function invalidAmounts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative int' => [-1];
        yield 'zero string' => ['0'];
        yield 'zero decimal' => ['0.00'];
        yield 'negative string' => ['-5.00'];
        yield 'three decimals' => ['1.234'];
        yield 'ten integer digits' => ['1000000000.00'];
        yield 'over ceiling' => [1000000000];
        yield 'not numeric' => ['abc'];
        yield 'empty' => [''];
        yield 'exponent' => ['1e3'];
        yield 'nan' => [NAN];
        yield 'infinity' => [INF];
    }

    #[DataProvider('validPhones')]
    public function testPhoneNormalizesToInternationalForm(string $value): void
    {
        self::assertSame('+251911234567', Validator::phone($value));
    }

    /** @return iterable<array{string}> */
    public static function validPhones(): iterable
    {
        yield ['+251911234567'];
        yield ['251911234567'];
        yield ['0911234567'];
        yield ['911234567'];
        yield ['+251 91 123 4567'];
        yield ['0911-234-567'];
        yield ['(0911) 234 567'];
    }

    public function testPhoneAcceptsTheSafaricomRange(): void
    {
        self::assertSame('+251711234567', Validator::phone('0711234567'));
    }

    #[DataProvider('sandboxMagicNumbers')]
    public function testPhoneHandlesTheSandboxMagicNumbers(string $magic): void
    {
        // From the Test Accounts reference.
        self::assertSame('+' . $magic, Validator::phone($magic));
    }

    /** @return iterable<array{string}> */
    public static function sandboxMagicNumbers(): iterable
    {
        yield ['251900000000'];
        yield ['251900000001'];
        yield ['251900000002'];
        yield ['251900000003'];
        yield ['251900000004'];
    }

    #[DataProvider('invalidPhones')]
    public function testPhoneRejectsNonEthiopianMobiles(string $value): void
    {
        $this->expectException(EpayValidationException::class);
        Validator::phone($value);
    }

    /** @return iterable<array{string}> */
    public static function invalidPhones(): iterable
    {
        yield ['+251811234567'];
        yield ['0811234567'];
        yield ['09112345678'];
        yield ['091123456'];
        yield [''];
        yield ['+1 555 0100'];
        yield ['abc'];
    }

    public function testCurrencyUppercasesAndValidates(): void
    {
        self::assertSame('ETB', Validator::currency('etb'));
        self::assertSame('ETB', Validator::currency(' ETB '));

        $this->expectException(EpayValidationException::class);
        Validator::currency('ET');
    }

    public function testReferenceRejectsBlankValues(): void
    {
        self::assertSame('PAB1', Validator::reference('  PAB1  '));

        $this->expectException(EpayValidationException::class);
        Validator::reference('   ');
    }

    public function testMaskSecretHidesTheMiddle(): void
    {
        self::assertSame('sk_live_…1234', Validator::maskSecret('sk_live_supersecretvalue1234'));
        self::assertSame('***', Validator::maskSecret('short'));
    }
}
