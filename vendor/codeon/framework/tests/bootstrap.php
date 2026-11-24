<?php
/**
 * Test bootstrap: plain PHPUnit, no WordPress. The WordPress functions
 * the classes under test call are stood in for here, in memory — hooks,
 * WP-Cron, options, Action Scheduler and the current user's capability.
 * `wp_test_reset()` clears them between tests.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

const HOUR_IN_SECONDS = 3600;
const DAY_IN_SECONDS = 86400;

function wp_test_reset(): void
{
    $GLOBALS['wp_test'] = ['hooks' => [], 'cron' => [], 'options' => [], 'as' => [], 'can' => true];
}
wp_test_reset();

function wp_test_callback_id(callable $callback): string
{
    if (is_array($callback)) {
        return (is_object($callback[0]) ? spl_object_hash($callback[0]) : $callback[0]) . '::' . $callback[1];
    }
    return is_string($callback) ? $callback : spl_object_hash($callback);
}

function add_filter(string $hook, callable $callback): bool
{
    // Like WordPress: the same callback added twice is kept once.
    $GLOBALS['wp_test']['hooks'][$hook][wp_test_callback_id($callback)] = $callback;
    return true;
}

function add_action(string $hook, callable $callback): bool
{
    return add_filter($hook, $callback);
}

function do_action(string $hook): void
{
    foreach ($GLOBALS['wp_test']['hooks'][$hook] ?? [] as $callback) {
        $callback();
    }
}

function apply_filters(string $hook, mixed $value): mixed
{
    foreach ($GLOBALS['wp_test']['hooks'][$hook] ?? [] as $callback) {
        $value = $callback($value);
    }
    return $value;
}

function wp_next_scheduled(string $hook): int|false
{
    $times = [];
    foreach ($GLOBALS['wp_test']['cron'] as $event) {
        if ($event['hook'] === $hook) {
            $times[] = $event['time'];
        }
    }
    return $times === [] ? false : min($times);
}

function wp_schedule_event(int $time, string $recurrence, string $hook): bool
{
    // WordPress refuses an interval nobody registered.
    $known = apply_filters('cron_schedules', ['hourly' => [], 'twicedaily' => [], 'daily' => []]);
    if (!isset($known[$recurrence])) {
        return false;
    }
    $GLOBALS['wp_test']['cron'][] = ['time' => $time, 'hook' => $hook, 'recurrence' => $recurrence];
    return true;
}

function wp_schedule_single_event(int $time, string $hook): bool
{
    $GLOBALS['wp_test']['cron'][] = ['time' => $time, 'hook' => $hook, 'recurrence' => false];
    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    $before = count($GLOBALS['wp_test']['cron']);
    $GLOBALS['wp_test']['cron'] = array_values(array_filter(
        $GLOBALS['wp_test']['cron'],
        static fn (array $event): bool => $event['hook'] !== $hook
    ));
    return $before - count($GLOBALS['wp_test']['cron']);
}

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['wp_test']['options'][$name] ?? $default;
}

function update_option(string $name, mixed $value, bool $autoload = true): bool
{
    $GLOBALS['wp_test']['options'][$name] = $value;
    return true;
}

function current_user_can(string $capability): bool
{
    return $GLOBALS['wp_test']['can'];
}

function as_has_scheduled_action(string $hook, array $args = [], string $group = ''): bool
{
    return in_array($hook, $GLOBALS['wp_test']['as'], true);
}

function as_enqueue_async_action(string $hook, array $args = [], string $group = ''): int
{
    $GLOBALS['wp_test']['as'][] = $hook;
    return count($GLOBALS['wp_test']['as']);
}

function as_unschedule_all_actions(string $hook, array $args = [], string $group = ''): void
{
    $GLOBALS['wp_test']['as'] = array_values(array_filter(
        $GLOBALS['wp_test']['as'],
        static fn (string $queued): bool => $queued !== $hook
    ));
}

// Translation + escaping, as WordPress does them for an untranslated site.
function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function _n(string $single, string $plural, int $number, string $domain = 'default'): string
{
    return $number === 1 ? $single : $plural;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return preg_match('#^https?://#i', $url) ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : '';
}

function wp_json_encode(mixed $data): string|false
{
    return json_encode($data);
}

// HTTP + transients for the BOG client: every transient read answers with
// `$GLOBALS['wp_test']['transients']['*']` (a cached OAuth token), and
// wp_remote_request() records the call and answers 200
// {"key":"request_received"}.
function get_transient(string $name): mixed
{
    return $GLOBALS['wp_test']['transients']['*'] ?? false;
}

function wp_generate_uuid4(): string
{
    return '00000000-0000-4000-8000-000000000000';
}

function is_wp_error(mixed $thing): bool
{
    return false;
}

/** @param array<string,mixed> $args */
function wp_remote_request(string $url, array $args = []): array
{
    $GLOBALS['wp_test']['http'][] = [
        'url'    => $url,
        'method' => (string) ($args['method'] ?? 'GET'),
        'body'   => isset($args['body']) ? (string) $args['body'] : null,
    ];
    return ['response' => ['code' => 200], 'body' => '{"key":"request_received"}'];
}

/** @param array<string,mixed> $response */
function wp_remote_retrieve_response_code(array $response): int
{
    return (int) $response['response']['code'];
}

/** @param array<string,mixed> $response */
function wp_remote_retrieve_body(array $response): string
{
    return (string) $response['body'];
}
