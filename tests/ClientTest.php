<?php

declare(strict_types=1);

namespace Moolre\Tests;

use Moolre\Client;
use Moolre\Exceptions\ApiException;
use Moolre\Exceptions\ValidationException;
use Moolre\Http\HttpResponse;
use Moolre\Http\NativeTransport;
use Moolre\Requests\PaymentRequest;
use Moolre\Responses\TransactionVerification;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testItInitiatesPayment(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, '{"status":1,"data":{"authorization_url":"https://checkout.moolre.test/pay/abc","reference":"ref_123"}}'),
        ]);

        $client = new Client('pub_key', 'acct_123', $transport);

        $payment = $client->initiatePayment([
            'reference' => 'ref_123',
            'email' => 'customer@example.com',
            'amount' => '50.00',
            'currency' => 'GHS',
            'callback' => 'https://example.com/callback',
            'redirect' => 'https://example.com/thank-you',
            'expiration_time' => 15,
            'metadata' => [
                'customer_id' => '1141',
            ],
        ]);

        self::assertSame('https://checkout.moolre.test/pay/abc', $payment->authorizationUrl());
        self::assertSame('starter', $transport->requests[0]['payload']['state']);
        self::assertSame('acct_123', $transport->requests[0]['payload']['accountnumber']);
        self::assertSame('pub_key', $transport->requests[0]['headers']['X-API-PUBKEY']);
        self::assertSame('php-sdk', $transport->requests[0]['payload']['tx_source']);
        self::assertSame('https://example.com/callback', $transport->requests[0]['payload']['callback']);
        self::assertSame('https://example.com/thank-you', $transport->requests[0]['payload']['redirect']);
        self::assertSame(15, $transport->requests[0]['payload']['expiration_time']);
        self::assertSame(['customer_id' => '1141'], $transport->requests[0]['payload']['metadata']);
    }

    public function testItVerifiesPayment(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, '{"status":1,"data":{"status":1,"reference":"ref_123"}}'),
        ]);

        $client = new Client('pub_key', 'acct_123', $transport, Client::DEFAULT_BASE_URL, 60, false, 'merchant_username');
        $verification = $client->verifyPayment('ref_123');

        self::assertTrue($verification->isSuccessful());
        self::assertSame('ref_123', $verification->reference());
        self::assertSame(Client::DEFAULT_VERIFICATION_URL, $transport->requests[0]['url']);
        self::assertSame([
            'type' => 1,
            'idtype' => '1',
            'id' => 'ref_123',
            'accountnumber' => 'acct_123',
        ], $transport->requests[0]['payload']);
        self::assertSame('pub_key', $transport->requests[0]['headers']['X-API-PUBKEY']);
        self::assertSame('merchant_username', $transport->requests[0]['headers']['X-API-USER']);
    }

    public function testVerificationRequiresApiUser(): void
    {
        $this->expectException(ValidationException::class);

        (new Client('pub_key', 'acct_123', new FakeTransport([])))->verifyPayment('ref_123');
    }

    public function testItAcceptsTheStatusResponseIdAsTheReference(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, json_encode([
                'status' => 1,
                'data' => [
                    'status' => 1,
                    'id' => 'ref_123',
                ],
            ])),
        ]);

        $verification = (new Client('pub_key', 'acct_123', $transport, Client::DEFAULT_BASE_URL, 60, false, 'merchant_username'))->verifyPayment('ref_123');

        self::assertSame('ref_123', $verification->reference());
    }

    public function testItSupportsTheTransactionStatusResponseFields(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, json_encode([
                'status' => 1,
                'data' => [
                    'txstatus' => 1,
                    'externalref' => 'ref_123',
                    'amount' => '50.00',
                    'accountnumber' => 'acct_123',
                ],
            ])),
        ]);

        $verification = (new Client('pub_key', 'acct_123', $transport, Client::DEFAULT_BASE_URL, 60, false, 'merchant_username'))->verifyPayment('ref_123');

        self::assertTrue($verification->isSuccessful());
        self::assertSame('ref_123', $verification->reference());
        self::assertTrue($verification->matchesAmountAndAccountNumber('50.00', 'acct_123'));
        self::assertFalse($verification->matchesAmountAndAccountNumber('49.00', 'acct_123'));
    }

    public function testPaymentRequestKeepsNonceConstructorPosition(): void
    {
        $request = new PaymentRequest(
            'ref_123',
            'customer@example.com',
            '50.00',
            'GHS',
            'https://example.com/callback',
            'nonce_123',
            'custom-source'
        );

        self::assertSame('nonce_123', $request->toArray()['nonce_value']);
        self::assertSame('custom-source', $request->toArray()['tx_source']);
    }

    public function testItNormalizesAmountsToTwoDecimalPlaces(): void
    {
        $request = new PaymentRequest(
            'ref_123',
            'customer@example.com',
            '50.5',
            'GHS',
            'https://example.com/callback'
        );

        self::assertSame('50.50', $request->toArray()['amount']);
    }

    /**
     * @dataProvider invalidAmounts
     * @param mixed $amount
     */
    public function testItRejectsInvalidAmounts($amount): void
    {
        $this->expectException(ValidationException::class);

        new PaymentRequest(
            'ref_123',
            'customer@example.com',
            $amount,
            'GHS',
            'https://example.com/callback'
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function invalidAmounts(): array
    {
        return [
            'infinity' => [INF],
            'scientific notation' => ['1e3'],
            'three decimal places' => ['10.001'],
            'surrounding whitespace' => [' 10.00 '],
            'zero' => ['0.00'],
        ];
    }

    public function testItRejectsInvalidPaymentRequest(): void
    {
        $this->expectException(ValidationException::class);

        $client = new Client('pub_key', 'acct_123', new FakeTransport([]));
        $client->initiatePayment([
            'reference' => 'ref_123',
            'email' => 'not-an-email',
            'amount' => 10,
            'currency' => 'GHS',
            'callback' => 'https://example.com/callback',
        ]);
    }

    public function testItThrowsApiExceptionForFailedMoolreStatus(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Payment failed');

        $transport = new FakeTransport([
            new HttpResponse(200, '{"status":0,"message":"Payment failed"}'),
        ]);

        $client = new Client('pub_key', 'acct_123', $transport);
        $client->initiatePayment([
            'reference' => 'ref_123',
            'email' => 'customer@example.com',
            'amount' => 10,
            'currency' => 'GHS',
            'callback' => 'https://example.com/callback',
        ]);
    }

    public function testItRejectsPublicKeyWithLineBreaks(): void
    {
        $this->expectException(ValidationException::class);

        new Client("pub_key\r\nInjected: value", 'acct_123', new FakeTransport([]));
    }

    public function testItRejectsInsecureBaseUrlByDefault(): void
    {
        $this->expectException(ValidationException::class);

        new Client('pub_key', 'acct_123', new FakeTransport([]), 'http://localhost/moolre');
    }

    public function testItCanAllowInsecureBaseUrlForLocalTesting(): void
    {
        $client = new Client('pub_key', 'acct_123', new FakeTransport([]), 'http://localhost/moolre', 60, true);

        self::assertInstanceOf(Client::class, $client);
    }

    public function testItRejectsNonHttpBaseUrlsEvenWhenInsecureUrlsAreAllowed(): void
    {
        $this->expectException(ValidationException::class);

        new Client('pub_key', 'acct_123', new FakeTransport([]), 'ftp://example.com/moolre', 60, true);
    }

    public function testItRejectsBaseUrlsWithCredentials(): void
    {
        $this->expectException(ValidationException::class);

        new Client('pub_key', 'acct_123', new FakeTransport([]), 'https://user:pass@example.com/moolre');
    }

    public function testItRejectsInvalidPaymentArgumentTypes(): void
    {
        $this->expectException(ValidationException::class);

        $client = new Client('pub_key', 'acct_123', new FakeTransport([]));
        $client->initiatePayment('not-a-payment-request');
    }

    public function testSandboxAllowsMissingPublicKeyAndSendsApiUserHeader(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, '{"status":1,"data":{"authorization_url":"https://sandbox.moolre.com/pay/abc","reference":"ref_123"}}'),
        ]);

        $client = new Client('', 'acct_123', $transport, Client::SANDBOX_BASE_URL, 60, false, 'merchant_username');

        $client->initiatePayment([
            'reference' => 'ref_123',
            'email' => 'customer@example.com',
            'amount' => 10,
            'currency' => 'GHS',
            'callback' => 'https://example.com/callback',
        ]);

        self::assertSame(Client::SANDBOX_BASE_URL, $transport->requests[0]['url']);
        self::assertSame('merchant_username', $transport->requests[0]['headers']['X-API-USER']);
        self::assertArrayNotHasKey('X-API-PUBKEY', $transport->requests[0]['headers']);
    }

    public function testSandboxRequiresApiUserHeader(): void
    {
        $this->expectException(ValidationException::class);

        new Client('', 'acct_123', new FakeTransport([]), Client::SANDBOX_BASE_URL);
    }

    public function testLiveRequestsStillRequirePublicKey(): void
    {
        $this->expectException(ValidationException::class);

        new Client('', 'acct_123', new FakeTransport([]));
    }

    public function testLiveRequestsDoNotSendSandboxApiUserHeader(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, '{"status":1,"data":{"authorization_url":"https://checkout.moolre.test/pay/abc","reference":"ref_123"}}'),
        ]);

        $client = new Client('pub_key', 'acct_123', $transport, Client::DEFAULT_BASE_URL, 60, false, 'merchant_username');

        $client->initiatePayment([
            'reference' => 'ref_123',
            'email' => 'customer@example.com',
            'amount' => 10,
            'currency' => 'GHS',
            'callback' => 'https://example.com/callback',
        ]);

        self::assertSame('pub_key', $transport->requests[0]['headers']['X-API-PUBKEY']);
        self::assertArrayNotHasKey('X-API-USER', $transport->requests[0]['headers']);
    }

    public function testItRejectsNonWebCallbackUrl(): void
    {
        $this->expectException(ValidationException::class);

        $client = new Client('pub_key', 'acct_123', new FakeTransport([]));
        $client->initiatePayment([
            'reference' => 'ref_123',
            'email' => 'customer@example.com',
            'amount' => 10,
            'currency' => 'GHS',
            'callback' => 'ftp://example.com/callback',
        ]);
    }

    public function testItRejectsNonWebRedirectUrl(): void
    {
        $this->expectException(ValidationException::class);

        $client = new Client('pub_key', 'acct_123', new FakeTransport([]));
        $client->initiatePayment([
            'reference' => 'ref_123',
            'email' => 'customer@example.com',
            'amount' => 10,
            'currency' => 'GHS',
            'callback' => 'https://example.com/callback',
            'redirect' => 'ftp://example.com/return',
        ]);
    }

    public function testItRejectsInvalidExpirationTime(): void
    {
        $this->expectException(ValidationException::class);

        $client = new Client('pub_key', 'acct_123', new FakeTransport([]));
        $client->initiatePayment([
            'reference' => 'ref_123',
            'email' => 'customer@example.com',
            'amount' => 10,
            'currency' => 'GHS',
            'callback' => 'https://example.com/callback',
            'expiration_time' => 0,
        ]);
    }

    public function testItRejectsNonArrayMetadata(): void
    {
        $this->expectException(ValidationException::class);

        $client = new Client('pub_key', 'acct_123', new FakeTransport([]));
        $client->initiatePayment([
            'reference' => 'ref_123',
            'email' => 'customer@example.com',
            'amount' => 10,
            'currency' => 'GHS',
            'callback' => 'https://example.com/callback',
            'metadata' => 'customer_id=1141',
        ]);
    }

    public function testItRejectsVerificationReferenceMismatch(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('reference does not match');

        $transport = new FakeTransport([
            new HttpResponse(200, '{"status":1,"data":{"status":1,"reference":"different_ref"}}'),
        ]);

        $client = new Client('pub_key', 'acct_123', $transport, Client::DEFAULT_BASE_URL, 60, false, 'merchant_username');
        $client->verifyPayment('ref_123');
    }

    public function testItRejectsAmbiguousApiSuccessStatuses(): void
    {
        $this->expectException(ApiException::class);

        $transport = new FakeTransport([
            new HttpResponse(200, json_encode([
                'status' => 'true',
                'data' => [
                    'authorization_url' => 'https://checkout.moolre.test/pay/abc',
                ],
            ])),
        ]);

        $client = new Client('pub_key', 'acct_123', $transport);
        $client->initiatePayment([
            'reference' => 'ref_123',
            'email' => 'customer@example.com',
            'amount' => '10.00',
            'currency' => 'GHS',
            'callback' => 'https://example.com/callback',
        ]);
    }

    public function testItRejectsInsecureAuthorizationUrls(): void
    {
        $this->expectException(ApiException::class);

        $transport = new FakeTransport([
            new HttpResponse(200, json_encode([
                'status' => 1,
                'data' => [
                    'authorization_url' => 'http://checkout.moolre.test/pay/abc',
                ],
            ])),
        ]);

        $client = new Client('pub_key', 'acct_123', $transport);
        $client->initiatePayment([
            'reference' => 'ref_123',
            'email' => 'customer@example.com',
            'amount' => '10.00',
            'currency' => 'GHS',
            'callback' => 'https://example.com/callback',
        ]);
    }

    public function testItMatchesVerifiedPaymentDetails(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, json_encode([
                'status' => 1,
                'data' => [
                    'status' => 1,
                    'reference' => 'ref_123',
                    'amount' => '50.00',
                    'currency' => 'GHS',
                    'email' => 'Customer@Example.com',
                    'accountnumber' => 'acct_123',
                ],
            ])),
        ]);

        $verification = (new Client('pub_key', 'acct_123', $transport, Client::DEFAULT_BASE_URL, 60, false, 'merchant_username'))->verifyPayment('ref_123');

        self::assertTrue($verification->matchesPaymentDetails('50.00', 'GHS', 'customer@example.com', 'acct_123'));
        self::assertFalse($verification->matchesPaymentDetails('49.00', 'GHS', 'customer@example.com', 'acct_123'));

        $unsuccessfulVerification = TransactionVerification::fromArray([
            'status' => 1,
            'data' => [
                'status' => 0,
                'reference' => 'ref_123',
                'amount' => '50.00',
                'currency' => 'GHS',
                'email' => 'customer@example.com',
                'accountnumber' => 'acct_123',
            ],
        ]);

        self::assertFalse($unsuccessfulVerification->matchesPaymentDetails('50.00', 'GHS', 'customer@example.com', 'acct_123'));
    }

    public function testNativeTransportCanBeForcedToUseStreams(): void
    {
        $property = new \ReflectionProperty(NativeTransport::class, 'forceStreams');
        $property->setAccessible(true);

        self::assertTrue($property->getValue(new NativeTransport(null, true)));
        self::assertSame(getenv('MOOLRE_FORCE_STREAMS') === '1', $property->getValue(new NativeTransport()));
    }
}
