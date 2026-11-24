<?php

declare(strict_types=1);

namespace CodeOn\Framework\Tests\License;

use CodeOn\Framework\License\TamperHeartbeat;
use PHPUnit\Framework\TestCase;

final class TamperHeartbeatTest extends TestCase
{
    protected function setUp(): void
    {
        wp_test_reset();
    }

    public function test_register_defers_cron_scheduling_until_init(): void
    {
        $cronSchedulesFired = 0;
        add_filter('cron_schedules', static function (array $schedules) use (&$cronSchedulesFired): array {
            $cronSchedulesFired++;
            return $schedules;
        });

        $heartbeat = new TamperHeartbeat(
            pluginId: 'test-plugin',
            pluginSlug: 'codeon-test-plugin',
            pluginVersion: '1.0.0',
            isTampered: static fn (): bool => false,
        );
        $heartbeat->register();

        $this->assertSame(0, $cronSchedulesFired, 'Must not trigger cron_schedules before init.');
        $this->assertSame([], $GLOBALS['wp_test']['cron']);

        do_action('init');

        $this->assertSame(1, $cronSchedulesFired);
        $this->assertCount(1, $GLOBALS['wp_test']['cron']);
        $this->assertSame('codeon-test-plugin/tamper_heartbeat', $GLOBALS['wp_test']['cron'][0]['hook']);

        // Running init again must not double-schedule.
        do_action('init');
        $this->assertCount(1, $GLOBALS['wp_test']['cron']);
    }
}
