<?php

declare(strict_types=1);

namespace CodeOn\Framework\Tests\License;

use CodeOn\Framework\License\LicenseSchedule;
use PHPUnit\Framework\TestCase;

final class LicenseScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        wp_test_reset();
        // A new request: nothing armed yet.
        $armed = new \ReflectionProperty(LicenseSchedule::class, 'armed');
        $armed->setValue(null, false);
    }

    public function test_a_check_is_due_when_never_made_or_about_eight_hours_old(): void
    {
        $now = 1_800_000_000;
        $this->assertTrue(LicenseSchedule::isDue(0, $now));
        $this->assertFalse(LicenseSchedule::isDue($now - 60, $now));
        $this->assertFalse(LicenseSchedule::isDue($now - LicenseSchedule::DUE_AFTER + 1, $now));
        $this->assertTrue(LicenseSchedule::isDue($now - LicenseSchedule::DUE_AFTER, $now));
        // The next 8-hourly event after a check always finds it due.
        $this->assertTrue(LicenseSchedule::isDue($now - LicenseSchedule::EVERY + 30, $now));
    }

    public function test_every_plugin_checks_in_the_one_shared_run_only_when_due(): void
    {
        $now = time();
        $calls = [];
        $plugins = ['never-checked' => 0, 'checked-a-minute-ago' => $now - 60, 'checked-9-hours-ago' => $now - 9 * 3600];
        foreach ($plugins as $name => $last) {
            LicenseSchedule::register(
                static function () use (&$calls, $name): void {
                    $calls[] = $name;
                },
                static fn (): int => $last
            );
        }
        do_action('init');

        $events = array_values(array_filter($GLOBALS['wp_test']['cron'], static fn (array $e): bool => $e['hook'] === LicenseSchedule::HOOK));
        $this->assertCount(1, $events, 'One shared event, however many plugins register.');
        $this->assertSame(LicenseSchedule::INTERVAL, $events[0]['recurrence']);
        $this->assertGreaterThan($now, $events[0]['time']);

        do_action(LicenseSchedule::HOOK);
        $this->assertSame(['never-checked', 'checked-9-hours-ago'], $calls);
    }

    public function test_the_shared_event_is_not_scheduled_twice_across_requests(): void
    {
        LicenseSchedule::register(static function (): void {}, static fn (): int => 0);
        do_action('init');
        $armed = new \ReflectionProperty(LicenseSchedule::class, 'armed');
        $armed->setValue(null, false);
        do_action('init');
        $this->assertCount(1, $GLOBALS['wp_test']['cron']);
    }

    public function test_an_admin_visit_queues_a_check_only_when_the_last_one_is_two_days_old(): void
    {
        $now = 1_800_000_000;
        LicenseSchedule::queueIfStale($now - 3600, $now);
        LicenseSchedule::queueIfStale(0, $now);
        $this->assertSame([], $GLOBALS['wp_test']['as'], 'Recent or never-made checks queue nothing.');

        $GLOBALS['wp_test']['can'] = false;
        LicenseSchedule::queueIfStale($now - 3 * 86400, $now);
        $this->assertSame([], $GLOBALS['wp_test']['as'], 'Only an administrator\'s visit counts.');

        $GLOBALS['wp_test']['can'] = true;
        LicenseSchedule::queueIfStale($now - 3 * 86400, $now);
        LicenseSchedule::queueIfStale($now - 3 * 86400, $now);
        $this->assertSame([LicenseSchedule::HOOK], $GLOBALS['wp_test']['as'], 'Queued once.');
    }

    public function test_nothing_runs_on_an_admin_page_while_checks_are_recent(): void
    {
        $checks = 0;
        LicenseSchedule::register(
            static function () use (&$checks): void {
                $checks++;
            },
            static fn (): int => time() - 3600
        );
        for ($i = 0; $i < 30; $i++) {
            do_action('admin_init');
        }
        $this->assertSame(0, $checks);
        $this->assertSame([], $GLOBALS['wp_test']['as']);
    }

    public function test_the_old_per_plugin_check_is_removed_once(): void
    {
        $legacy = 'balance_sync/license_revalidate';
        wp_schedule_event(time() + 60, 'daily', $legacy);
        as_enqueue_async_action($legacy);
        LicenseSchedule::register(static function (): void {}, static fn (): int => 0, $legacy);
        do_action('init');

        $this->assertFalse(wp_next_scheduled($legacy));
        $this->assertSame([], $GLOBALS['wp_test']['as']);
        $this->assertSame([$legacy], get_option(LicenseSchedule::RETIRED_OPTION));

        // Already retired: a plugin that schedules it again (an old copy)
        // is left alone instead of being fought every request.
        wp_schedule_event(time() + 60, 'daily', $legacy);
        do_action('init');
        $this->assertNotFalse(wp_next_scheduled($legacy));
    }
}
