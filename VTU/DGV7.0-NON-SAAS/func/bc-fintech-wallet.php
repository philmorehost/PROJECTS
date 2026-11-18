<?php
/**
 * DGV7 phase 2 — wallet and FX presentation.
 *
 * Pure presentation over rows the caller already holds: no queries, no writes, no provider calls.
 * Everything here is written to be safe on a page that renders before any fintech migration has
 * run, because the dashboard is not the place to discover a missing table.
 */

if (!function_exists('bc_wallet_currency_catalog')) {
    /**
     * The currencies the product intends to support, with the account that custodies each.
     *
     * ONLY NGN is custodied today. The others are listed so the dashboard can show the shape of
     * the product, but bc_wallet_cards() refuses to present them as open until a real balance
     * exists. Do not add a currency here and assume it is funded — custody is a business decision,
     * not a config flag.
     */
    function bc_wallet_currency_catalog() {
        return [
            'NGN' => ['symbol' => chr(0x20A6), 'label' => 'Nigerian Naira', 'custodied' => true,  'decimals' => 2],
            'USD' => ['symbol' => '$',          'label' => 'US Dollar',      'custodied' => false, 'decimals' => 2],
            'GBP' => ['symbol' => chr(0xC2) . chr(0xA3), 'label' => 'Pound Sterling', 'custodied' => false, 'decimals' => 2],
            'EUR' => ['symbol' => chr(0xE2) . chr(0x82) . chr(0xAC), 'label' => 'Euro', 'custodied' => false, 'decimals' => 2],
        ];
    }
}

if (!function_exists('bc_wallet_format')) {
    /** Format an amount for display. Returns null for a value that is not a finite number. */
    function bc_wallet_format($amount, $currency = 'NGN') {
        if ($amount === null || $amount === '' || !is_numeric($amount)) return null;
        $value = (float)$amount;
        if (!is_finite($value)) return null;

        $catalog = bc_wallet_currency_catalog();
        $currency = strtoupper(trim((string)$currency));
        $decimals = isset($catalog[$currency]) ? (int)$catalog[$currency]['decimals'] : 2;
        $symbol = isset($catalog[$currency]) ? $catalog[$currency]['symbol'] : '';

        // Negative balances are a data problem, not a display problem: show the magnitude and let
        // the caller decide, rather than rendering a minus sign that looks like a debt.
        return $symbol . number_format(abs($value), $decimals);
    }
}

if (!function_exists('bc_wallet_cards')) {
    /**
     * Wallet cards for the dashboard.
     *
     * A currency is "open" only when it is custodied AND a real balance is present. A custodied
     * currency with a zero balance reads "Not open yet" rather than showing 0.00, because a zero
     * that looks like a balance invites a support ticket that says "where is my money".
     *
     * @param array $balances  map of CURRENCY => amount
     */
    function bc_wallet_cards(array $balances = []) {
        $cards = [];
        foreach (bc_wallet_currency_catalog() as $code => $meta) {
            $raw = $balances[$code] ?? null;
            $has_balance = $raw !== null && is_numeric($raw) && (float)$raw != 0.0;
            $open = !empty($meta['custodied']) && $has_balance;

            $cards[] = [
                'code'    => $code,
                'label'   => $meta['label'],
                'symbol'  => $meta['symbol'],
                'open'    => $open,
                'amount'  => $open ? bc_wallet_format($raw, $code) : null,
                'note'    => $open ? 'Available' : 'Not open yet',
            ];
        }
        return $cards;
    }
}

if (!function_exists('bc_fx_rate_snapshot')) {
    /**
     * The rate strip.
     *
     * Returns ['available' => bool, 'rates' => [...], 'note' => string]. When no feed is configured
     * this reports unavailable and the caller must print the note. It must NOT fall back to zeros:
     * a zero rate is indistinguishable from a real quotation of zero and would be acted upon.
     *
     * @param array|null $pairs map of 'USD/NGN' => rate, from whatever feed the caller wired.
     */
    function bc_fx_rate_snapshot($pairs = null) {
        if (!is_array($pairs) || !$pairs) {
            return [
                'available' => false,
                'rates'     => [],
                'note'      => 'Live rates are temporarily unavailable.',
            ];
        }

        $rates = [];
        foreach ($pairs as $pair => $rate) {
            if (!is_numeric($rate) || (float)$rate <= 0) continue;
            $rates[(string)$pair] = (float)$rate;
        }

        if (!$rates) {
            return [
                'available' => false,
                'rates'     => [],
                'note'      => 'Live rates are temporarily unavailable.',
            ];
        }

        return ['available' => true, 'rates' => $rates, 'note' => 'Indicative mid-market rates.'];
    }
}

if (!function_exists('bc_fintech_tier_badge')) {
    /**
     * The account badge. Unknown is labelled honestly rather than defaulted to personal: telling a
     * business customer they are personal is worse than telling them nothing.
     */
    function bc_fintech_tier_badge($tier) {
        $tier = strtolower(trim((string)$tier));
        if ($tier === 'business') return ['class' => 'tier-business', 'label' => 'Business account'];
        if ($tier === 'personal') return ['class' => 'tier-personal', 'label' => 'Personal account'];
        return ['class' => 'tier-unknown', 'label' => 'Account type not set'];
    }
}
