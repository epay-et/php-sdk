<?php

declare(strict_types=1);

namespace Epay\Tests;

use Epay\Exception\EpayConfigException;
use Epay\Exception\EpayValidationException;
use Epay\Exception\EpayWebhookSignatureException;
use Epay\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private const PAYLOAD = '{"event":"payment.success","mode":"live","reference":"PAB12CD3420260813",'
        . '"merchantReference":"order_123","amount":"250.00","serviceFee":"7.50","currency":"ETB",'
        . '"status":"completed","paymentMethod":"telebirr","customer":{"name":"Abebe Bikila",'
        . '"email":"abebe@example.com","phone":"+251911234567"},"paidAt":"2026-08-13T11:47:00.000Z",'
        . '"createdAt":"2026-08-13T11:30:00.000Z"}';

    private function header(?string $payload = null, string $secret = self::SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload ?? self::PAYLOAD, $secret);
    }

    private function webhooks(): Webhooks
    {
        return new Webhooks(self::SECRET);
    }

    public function testTheHeaderNameMatchesTheDocs(): void
    {
        self::assertSame('X-Epay-Signature', Webhooks::SIGNATURE_HEADER);
    }

    public function testSigningMatchesAHandRolledHmac(): void
    {
        $expected = hash_hmac('sha256', self::PAYLOAD, self::SECRET);
        self::assertSame($expected, $this->webhooks()->sign(self::PAYLOAD));
    }

    public function testAGenuineDeliveryVerifies(): void
    {
        self::assertTrue($this->webhooks()->verify(self::PAYLOAD, $this->header()));
    }

    public function testThePrefixIsOptionalAndCaseInsensitive(): void
    {
        $hex = $this->webhooks()->sign(self::PAYLOAD);

        self::assertTrue($this->webhooks()->verify(self::PAYLOAD, $hex));
        self::assertTrue($this->webhooks()->verify(self::PAYLOAD, 'SHA256=' . strtoupper($hex)));
        self::assertTrue($this->webhooks()->verify(self::PAYLOAD, '  sha256=' . $hex . '  '));
    }

    public function testATamperedBodyOrWrongSecretFails(): void
    {
        $tampered = str_replace('250.00', '1.00', self::PAYLOAD);

        self::assertFalse($this->webhooks()->verify($tampered, $this->header()));
        self::assertFalse($this->webhooks()->verify(self::PAYLOAD, $this->header(self::PAYLOAD, 'wrong')));
        self::assertFalse($this->webhooks()->verify(self::PAYLOAD, 'sha256=abc'));
    }

    #[DataProvider('malformedHeaders')]
    public function testAMissingOrMalformedHeaderFailsWithoutThrowing(?string $header): void
    {
        self::assertFalse($this->webhooks()->verify(self::PAYLOAD, $header));
    }

    /** @return iterable<string, array{string|null}> */
    public static function malformedHeaders(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'prefix only' => ['sha256='];
        yield 'not hex' => ['not-hex!!'];
        yield 'non hex digest' => ['sha256=zzzz'];
    }

    public function testConstructEventReturnsTheDecodedEvent(): void
    {
        $event = $this->webhooks()->constructEvent(self::PAYLOAD, $this->header());

        self::assertSame('payment.success', $event['event']);
        self::assertSame('PAB12CD3420260813', $event['reference']);
        self::assertSame('+251911234567', $event['customer']['phone']);
        self::assertTrue(Webhooks::isKnownEvent($event));
    }

    public function testConstructEventFailsClosedOnAMissingHeader(): void
    {
        $this->expectException(EpayWebhookSignatureException::class);
        $this->webhooks()->constructEvent(self::PAYLOAD, null);
    }

    public function testConstructEventFailsClosedOnATamperedBody(): void
    {
        $this->expectException(EpayWebhookSignatureException::class);
        $this->webhooks()->constructEvent(str_replace('250.00', '1.00', self::PAYLOAD), $this->header());
    }

    public function testAVerifiedBodyThatIsNotAnObjectIsRejected(): void
    {
        $body = '[1,2,3]';

        $this->expectException(EpayValidationException::class);
        $this->webhooks()->constructEvent($body, $this->header($body));
    }

    public function testAnUnknownEventNameVerifiesButIsFlagged(): void
    {
        $body = '{"event":"payment.disputed","reference":"P1"}';
        $event = $this->webhooks()->constructEvent($body, $this->header($body));

        self::assertSame('payment.disputed', $event['event']);
        self::assertFalse(Webhooks::isKnownEvent($event));
    }

    public function testAnUnconfiguredSecretExplainsItself(): void
    {
        $webhooks = new Webhooks(null);
        self::assertFalse($webhooks->isConfigured());

        // A per-call secret still works.
        self::assertTrue($webhooks->verify(self::PAYLOAD, $this->header(), self::SECRET));

        $this->expectException(EpayConfigException::class);
        $webhooks->verify(self::PAYLOAD, $this->header());
    }
}
