<?php

declare(strict_types=1);

namespace CodeOn\Framework\Tests\WooCommerce\Payments;

use CodeOn\Framework\WooCommerce\Payments\InstallmentEstimator;
use CodeOn\Framework\WooCommerce\Payments\ProductCalculator;
use PHPUnit\Framework\TestCase;

final class ProductCalculatorTest extends TestCase
{
    protected function setUp(): void
    {
        wp_test_reset();
    }

    /** @return array<string,mixed> */
    private function loan(array $overrides = []): array
    {
        return array_merge([
            'kind'         => ProductCalculator::KIND_LOAN,
            'layout'       => 'card',
            'product_id'   => 42,
            'price'        => 1299.0,
            'range_min'    => 100,
            'range_max'    => 10000,
            'apr'          => 26,
            'terms'        => InstallmentEstimator::DEFAULT_MONTHS,
            'default_term' => 24,
            'bank'         => 'Bank of Georgia',
            'label'        => 'Installment',
            'logo'         => 'https://shop.test/wp-content/plugins/x/assets/icons/bog.svg',
            'logo_width'   => 26,
            'logo_height'  => 23,
            'accent'       => '#FF671D',
            'textdomain'   => 'codeon-bog-installments',
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function config(string $html): array
    {
        $this->assertSame(1, preg_match('/data-codeon-pc="([^"]*)"/', $html, $m));
        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    }

    public function test_the_same_day_next_month_or_the_last_day_of_a_shorter_month(): void
    {
        $from = new \DateTimeImmutable('2026-11-09');
        $this->assertSame('2026-12-09', ProductCalculator::addMonths($from, 1)->format('Y-m-d'));
        $this->assertSame('2027-02-09', ProductCalculator::addMonths($from, 3)->format('Y-m-d'));
        $this->assertSame('2027-02-28', ProductCalculator::addMonths(new \DateTimeImmutable('2027-01-31'), 1)->format('Y-m-d'));
        $this->assertSame('2028-02-29', ProductCalculator::addMonths(new \DateTimeImmutable('2028-01-31'), 1)->format('Y-m-d'));
        $this->assertSame('2026-04-30', ProductCalculator::addMonths(new \DateTimeImmutable('2025-12-31'), 4)->format('Y-m-d'));
    }

    public function test_terms_are_cleaned_and_card_buttons_keep_the_default(): void
    {
        $this->assertSame([6, 12, 24], ProductCalculator::cleanTerms(['24', 6, 12, 6, 0, -3, 'x']));
        $this->assertSame(InstallmentEstimator::DEFAULT_MONTHS, ProductCalculator::cleanTerms([]));

        // Eight standard terms → the usual five buttons.
        $this->assertSame([6, 12, 24, 36, 48], ProductCalculator::cardTerms(InstallmentEstimator::DEFAULT_MONTHS, 24));
        // A default outside the usual set is added in order.
        $this->assertSame([6, 12, 18, 24, 36, 48], ProductCalculator::cardTerms(InstallmentEstimator::DEFAULT_MONTHS, 18));
        // Few terms: all of them.
        $this->assertSame([3, 9, 15], ProductCalculator::cardTerms([3, 9, 15], 9));
        // Many unusual terms: the first six.
        $this->assertSame([3, 4, 5, 7, 8, 9], ProductCalculator::cardTerms([3, 4, 5, 7, 8, 9, 10, 11], 4));
    }

    public function test_price_range_rules(): void
    {
        $this->assertTrue(ProductCalculator::inRange(500, 100, 10000));
        $this->assertTrue(ProductCalculator::inRange(500, 0, 0));
        $this->assertFalse(ProductCalculator::inRange(50, 100, 10000));
        $this->assertFalse(ProductCalculator::inRange(20000, 100, 10000));
        $this->assertFalse(ProductCalculator::inRange(0, 0, 0));

        $this->assertTrue(ProductCalculator::overlapsRange(50, 500, 100, 10000));
        $this->assertTrue(ProductCalculator::overlapsRange(5000, 20000, 100, 10000));
        $this->assertFalse(ProductCalculator::overlapsRange(10, 90, 100, 10000));
        $this->assertFalse(ProductCalculator::overlapsRange(12000, 20000, 100, 10000));
    }

    public function test_colours_are_validated(): void
    {
        $this->assertSame('#ff671d', ProductCalculator::hexColour(' #FF671D '));
        $this->assertSame('#aabbcc', ProductCalculator::hexColour('#abc'));
        $this->assertNull(ProductCalculator::hexColour('red'));
        $this->assertNull(ProductCalculator::hexColour('#ff671d;background:url(x)'));
        $this->assertSame('rgba(255,103,29,0.1)', ProductCalculator::tint('#ff671d', 0.1));
        $this->assertSame('rgba(17,24,39,0.5)', ProductCalculator::tint('nope', 0.5));
    }

    public function test_settings_default_to_off_and_reject_unknown_values(): void
    {
        $off = ProductCalculator::settings('codeon_bog_loan', ProductCalculator::KIND_LOAN);
        $this->assertFalse($off['enabled']);
        $this->assertSame('card', $off['layout']);
        $this->assertSame(ProductCalculator::POSITION_AUTO, $off['position']);
        $this->assertSame('', $off['color']);

        update_option('woocommerce_codeon_bog_loan_settings', [
            'product_widget'          => 'yes',
            'product_widget_layout'   => 'schedule', // a split layout on a loan gateway
            'product_widget_position' => 'shortcode',
            'product_widget_color'    => '#0F766E',
        ]);
        $on = ProductCalculator::settings('codeon_bog_loan', ProductCalculator::KIND_LOAN);
        $this->assertTrue($on['enabled']);
        $this->assertTrue(ProductCalculator::isEnabled('codeon_bog_loan'));
        $this->assertSame('card', $on['layout']);
        $this->assertSame(ProductCalculator::POSITION_SHORTCODE, $on['position']);
        $this->assertSame('#0f766e', $on['color']);

        update_option('woocommerce_codeon_tbc_bnpl_settings', ['product_widget_layout' => 'schedule', 'product_widget_parts' => '99', 'product_widget_color' => 'javascript:x']);
        $split = ProductCalculator::settings('codeon_tbc_bnpl', ProductCalculator::KIND_SPLIT);
        $this->assertSame('schedule', $split['layout']);
        $this->assertSame(24, $split['parts']);
        $this->assertSame('', $split['color']);
    }

    public function test_loan_card_shows_the_default_term_and_the_monthly_amount(): void
    {
        $html = ProductCalculator::render($this->loan());

        $expected = number_format(InstallmentEstimator::monthlyPayment(1299, 24, 26), 2, '.', ',');
        $this->assertSame('69.98', $expected);
        $this->assertStringContainsString('<span data-pc-amount>69.98</span> ₾', $html);
        $this->assertStringContainsString('codeon-pc--card', $html);
        $this->assertStringNotContainsString(' hidden', $html);
        $this->assertSame(5, substr_count($html, 'data-pc-term="'));
        $this->assertStringContainsString('class="codeon-pc__chip is-active" data-pc-term="24" aria-pressed="true"', $html);
        $this->assertStringContainsString('--codeon-pc-accent:#ff671d;--codeon-pc-tint:rgba(255,103,29,0.1);', $html);
        $this->assertStringContainsString('aria-label="Bank of Georgia — Installment"', $html);

        $config = $this->config($html);
        $this->assertSame('loan', $config['kind']);
        $this->assertSame(42, $config['product']);
        $this->assertSame([6, 12, 24, 36, 48], $config['terms']);
        $this->assertSame(24, $config['term']);
        $this->assertSame('%d months', $config['monthsText']);
    }

    public function test_calculator_and_line_layouts(): void
    {
        $calc = ProductCalculator::render($this->loan(['layout' => 'calculator']));
        $this->assertStringContainsString('max="7"', $calc);
        $this->assertStringContainsString('value="4"', $calc); // 24 is the fifth of eight terms
        $this->assertStringContainsString('aria-valuetext="24 months"', $calc);
        $this->assertSame(8, substr_count($calc, 'data-pc-mark="'));
        $this->assertStringContainsString('--codeon-pc-fill:57.1429%', $calc);

        $line = ProductCalculator::render($this->loan(['layout' => 'line']));
        $this->assertStringContainsString('≈ <span data-pc-amount>69.98</span> ₾', $line);
        $this->assertStringContainsString('Installment for 24 months', $line);
        $this->assertStringContainsString('alt="Bank of Georgia"', $line);
        $this->assertStringNotContainsString('codeon-pc__head', $line);
    }

    public function test_a_fee_raises_the_amount_the_monthly_payment_is_worked_out_on(): void
    {
        $html = ProductCalculator::render($this->loan(['multiplier' => 1.05, 'apr' => 30, 'default_term' => 12, 'terms' => [6, 12, 24]]));
        $expected = number_format(InstallmentEstimator::monthlyPayment(1299 * 1.05, 12, 30), 2, '.', ',');
        $this->assertStringContainsString('<span data-pc-amount>' . $expected . '</span>', $html);
        $this->assertSame(1.05, $this->config($html)['mult']);
    }

    public function test_split_amounts_add_up_to_the_price(): void
    {
        $this->assertSame(['first' => 33.34, 'rest' => 33.33], ProductCalculator::splitAmounts(100, 3));
        $this->assertSame(['first' => 324.75, 'rest' => 324.75], ProductCalculator::splitAmounts(1299, 4));
        $this->assertSame(['first' => 0.03, 'rest' => 0.02], ProductCalculator::splitAmounts(0.09, 4));
        foreach ([[19.99, 4], [1234.57, 3], [0.1, 7], [99999.99, 24]] as [$price, $parts]) {
            $s = ProductCalculator::splitAmounts($price, $parts);
            $this->assertSame((int) round($price * 100), (int) round($s['first'] * 100) + (int) round($s['rest'] * 100) * ($parts - 1));
            $this->assertGreaterThanOrEqual($s['rest'], $s['first']);
        }
    }

    public function test_a_fee_counts_toward_the_banks_limits(): void
    {
        // 9,999 with a 5% fee is 10,498.95 — above a 10,000 maximum.
        $this->assertSame('', ProductCalculator::render($this->loan(['price' => 9999, 'multiplier' => 1.05])));
        $this->assertNotSame('', ProductCalculator::render($this->loan(['price' => 9000, 'multiplier' => 1.05])));
    }

    public function test_out_of_range_prices(): void
    {
        // Simple product below the minimum: nothing at all.
        $this->assertSame('', ProductCalculator::render($this->loan(['price' => 50])));
        // Variable product whose cheapest variation is below the minimum but
        // others are in range: rendered hidden, the script shows it on a pick.
        $html = ProductCalculator::render($this->loan(['price' => 50, 'min_price' => 50, 'max_price' => 900]));
        $this->assertStringContainsString(' hidden>', $html);
        // An unknown layout falls back to the card.
        $this->assertStringContainsString('codeon-pc--card', ProductCalculator::render($this->loan(['layout' => 'bogus'])));
    }

    public function test_split_layouts(): void
    {
        $base = ['kind' => ProductCalculator::KIND_SPLIT, 'parts' => 4, 'label' => 'Part by part', 'range_min' => 0, 'range_max' => 0];

        $line = ProductCalculator::render($this->loan(['layout' => 'line'] + $base));
        $this->assertStringContainsString('4 × <span data-pc-part>324.75</span> ₾', $line);
        $this->assertStringContainsString('· Part by part, 0%', $line);

        $card = ProductCalculator::render($this->loan(['layout' => 'card'] + $base));
        $this->assertStringContainsString('4 equal payments, 0%', $card);
        $this->assertSame(4, substr_count($card, '<li class="codeon-pc__part">'));
        $this->assertSame(10, substr_count($card, 'class="is-on"')); // 1 + 2 + 3 + 4
        $this->assertStringContainsString('In 1 month', $card);
        $this->assertStringContainsString('In 3 months', $card);

        $schedule = ProductCalculator::render($this->loan(['layout' => 'schedule', 'parts' => 3, 'price' => 100] + $base));
        $this->assertSame(3, substr_count($schedule, '<li class="codeon-pc__row'));
        // The payments add up to the price: 33.34 + 33.33 + 33.33.
        $this->assertSame(1, substr_count($schedule, '<span data-pc-first>33.34</span>'));
        $this->assertSame(2, substr_count($schedule, '<span data-pc-part>33.33</span>'));
        $this->assertStringContainsString('<span data-pc-total>100.00</span>', $schedule);
        $this->assertStringContainsString('data-pc-date="2"', $schedule);
        $config = $this->config($schedule);
        $this->assertSame('split', $config['kind']);
        $this->assertSame(3, $config['parts']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $config['today']);
    }

    public function test_bank_text_and_bad_colours_are_escaped(): void
    {
        $html = ProductCalculator::render($this->loan([
            'bank'   => '<script>alert(1)</script>',
            'label'  => '"><img src=x onerror=alert(1)>',
            'accent' => 'red;background:url(javascript:alert(1))',
            'logo'   => 'javascript:alert(1)',
        ]));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('--codeon-pc-accent:#111827;', $html);
    }

    public function test_form_fields(): void
    {
        $loan = ProductCalculator::formFields(ProductCalculator::KIND_LOAN, '#ff671d', 'codeon_bog_installment_calculator', 'codeon-bog-installments');
        $this->assertSame('no', $loan[ProductCalculator::OPT_ENABLED]['default']);
        $this->assertSame(['line', 'card', 'calculator'], array_keys($loan[ProductCalculator::OPT_LAYOUT]['options']));
        $this->assertStringContainsString('[codeon_bog_installment_calculator]', $loan[ProductCalculator::OPT_POSITION]['description']);
        $this->assertArrayNotHasKey(ProductCalculator::OPT_PARTS, $loan);

        $split = ProductCalculator::formFields(ProductCalculator::KIND_SPLIT, '#00aeef', 'codeon_tbc_bnpl_calculator', 'codeon-tbc-card-payment', true);
        $this->assertSame(['line', 'card', 'schedule'], array_keys($split[ProductCalculator::OPT_LAYOUT]['options']));
        $this->assertSame('4', $split[ProductCalculator::OPT_PARTS]['default']);
        $this->assertArrayHasKey(ProductCalculator::OPT_MIN, $split);
        $this->assertArrayHasKey(ProductCalculator::OPT_MAX, $split);
    }
}
