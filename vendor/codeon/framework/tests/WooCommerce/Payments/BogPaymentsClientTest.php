<?php

declare(strict_types=1);

namespace CodeOn\Framework\Tests\WooCommerce\Payments;

use CodeOn\Framework\Logging\Logger;
use CodeOn\Framework\WooCommerce\Payments\BogPayments\Client;
use PHPUnit\Framework\TestCase;

/**
 * Pre-authorization calls go to BOG's documented endpoints
 * (api.bog.ge/docs/payments: Complete Pre-Authorization).
 */
final class BogPaymentsClientTest extends TestCase
{
    protected function setUp(): void
    {
        wp_test_reset();
        $GLOBALS['wp_test']['http'] = [];
        // A cached token, so the client goes straight to the call under test.
        $GLOBALS['wp_test']['transients'] = ['*' => ['token' => 'tok', 'expires_at' => time() + 600]];
    }

    private function client(): Client
    {
        return new Client('id', 'secret', Client::ENV_PRODUCTION, 'codeon-bog-card-payment', '0.0.0', new Logger('test'));
    }

    /** @return array{url:string,method:string,body:?string} */
    private function lastRequest(): array
    {
        $calls = $GLOBALS['wp_test']['http'];
        $this->assertCount(1, $calls);
        return $calls[0];
    }

    public function testFullConfirmationPostsApproveWithoutAmount(): void
    {
        $this->client()->completePreauth('bog-1', 'FULL_COMPLETE');

        $r = $this->lastRequest();
        $this->assertSame('POST', $r['method']);
        $this->assertStringEndsWith('/payments/v1/payment/authorization/approve/bog-1', $r['url']);
        $this->assertSame('{}', $r['body']);
    }

    public function testPartialConfirmationSendsTheAmount(): void
    {
        $this->client()->completePreauth('bog-1', 'PARTIAL_COMPLETE', 10.5, 'GEL');

        $r = $this->lastRequest();
        $this->assertSame('POST', $r['method']);
        $this->assertStringEndsWith('/payments/v1/payment/authorization/approve/bog-1', $r['url']);
        $this->assertSame('{"amount":"10.50"}', $r['body']);
    }

    public function testCancelPostsToTheCancelEndpoint(): void
    {
        $this->client()->completePreauth('bog-1', 'CANCEL');

        $r = $this->lastRequest();
        $this->assertSame('POST', $r['method']);
        $this->assertStringEndsWith('/payments/v1/payment/authorization/cancel/bog-1', $r['url']);
        $this->assertSame('{}', $r['body']);
    }
}
