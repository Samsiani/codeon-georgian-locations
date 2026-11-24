<?php

declare(strict_types=1);

namespace CodeOn\Framework\Tests\WooCommerce\Payments;

use CodeOn\Framework\WooCommerce\Payments\LocalSandbox;
use PHPUnit\Framework\TestCase;

final class LocalSandboxTest extends TestCase
{
    protected function setUp(): void
    {
        wp_test_reset();
    }

    public function test_admin_notice_defers_callable_message_until_rendered(): void
    {
        $evaluated = 0;
        $on = false;

        LocalSandbox::adminNotice(
            static function () use (&$on): bool {
                return $on;
            },
            static function () use (&$evaluated): string {
                $evaluated++;
                return 'Sandbox active';
            }
        );

        $this->assertSame(0, $evaluated, 'Callable message must not run at registration time.');

        ob_start();
        do_action('admin_notices');
        $offHtml = (string) ob_get_clean();
        $this->assertSame('', $offHtml);
        $this->assertSame(0, $evaluated, 'Callable message must not run when notice is inactive.');

        $on = true;
        ob_start();
        do_action('admin_notices');
        $onHtml = (string) ob_get_clean();
        $this->assertSame(1, $evaluated);
        $this->assertStringContainsString('Sandbox active', $onHtml);
    }

    public function test_admin_notice_still_accepts_plain_string(): void
    {
        LocalSandbox::adminNotice(static fn (): bool => true, 'Plain notice');

        ob_start();
        do_action('admin_notices');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Plain notice', $html);
    }
}
