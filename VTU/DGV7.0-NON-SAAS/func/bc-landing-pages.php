<?php
/**
 * §41 — public landing pages: Refer & Earn, the Services index and one
 * dedicated page per service (services/<slug>.php).
 *
 * Modelled on the "refer and earn" explainer pattern popularised by fintech
 * landing pages (big display type, three-step cards, earn calculator, FAQ),
 * but written for this platform's actual referral engine:
 *   - referral coins unlock after $100 of verified qualifying settlements;
 *   - pending referral coins never enter the spendable points ledger;
 *   - unlocked coins convert through the admin-reviewed conversion workflow.
 * Every number on the page comes from the vendor's own Loyalty Settings, so
 * the marketing copy can never drift from the money rules.
 *
 * Side-effect free: including this file only defines functions.
 */

if (!function_exists('bc_landing_context')) {
    /** Resolve the vendor + programme terms for the public pages. */
    function bc_landing_context() {
        global $connection_server;
        $ctx = array(
            'vendor_id' => 0, 'site_name' => 'Our Platform', 'logo' => '',
            'brand' => '#5a31f4', 'reward_usd' => 0.0, 'qualifying_usd' => 100.0,
            'bonus_coins' => 1000, 'coin_rate' => 500.0, 'coin_min' => 100,
            'usd_rate' => 0.0,
        );
        if (!($connection_server instanceof mysqli)) return $ctx;

        $host = strtolower(trim(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]));
        $vendor = null;
        foreach (array_unique(array($host, preg_replace('/^www\./', '', $host))) as $h) {
            if ($h === '') continue;
            $stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_vendors WHERE website_url = ? AND status = 1 LIMIT 1");
            if (!$stmt) break;
            mysqli_stmt_bind_param($stmt, 's', $h);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res && ($row = mysqli_fetch_assoc($res))) { $vendor = $row; mysqli_stmt_close($stmt); break; }
            mysqli_stmt_close($stmt);
        }
        if (!$vendor) {
            $q = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE status = 1 ORDER BY id ASC LIMIT 1");
            if ($q) $vendor = mysqli_fetch_assoc($q);
        }
        if (!$vendor) return $ctx;
        $ctx['vendor_id'] = (int)$vendor['id'];

        $vid = (int)$vendor['id'];
        $q = mysqli_query($connection_server, "SELECT site_title, site_logo FROM sas_site_details WHERE vendor_id='$vid' LIMIT 1");
        if ($q && ($sd = mysqli_fetch_assoc($q))) {
            if (!empty($sd['site_title'])) $ctx['site_name'] = (string)$sd['site_title'];
            if (!empty($sd['site_logo'])) $ctx['logo'] = (string)$sd['site_logo'];
        }
        if ($ctx['site_name'] === 'Our Platform' && !empty($vendor['business_name'])) $ctx['site_name'] = (string)$vendor['business_name'];

        $q = mysqli_query($connection_server, "SELECT primary_color FROM sas_vendor_style_templates WHERE vendor_id='$vid' LIMIT 1");
        if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r['primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$r['primary_color'])) {
            $ctx['brand'] = (string)$r['primary_color'];
        }

        if (!function_exists('bc_referral_settings') && is_file(__DIR__ . '/bc-fintech-services.php')) {
            include_once __DIR__ . '/bc-fintech-services.php';
        }
        if (function_exists('bc_referral_settings')) {
            $rs = bc_referral_settings($vid);
            $ctx['reward_usd'] = (float)$rs['reward_usd'];
            $ctx['qualifying_usd'] = (float)$rs['qualifying_usd'];
            $ctx['bonus_coins'] = (int)$rs['first_purchase_coins'];
        }
        if (function_exists('bc_get_coin_settings')) {
            $cs = bc_get_coin_settings($vid);
            $ctx['coin_rate'] = (float)($cs['points_conversion_rate'] ?? 500);
            $ctx['coin_min'] = (int)($cs['min_points_conversion'] ?? 100);
        }
        if (function_exists('getLiveExchangeRate')) {
            $rate = (float)getLiveExchangeRate('USD', 'NGN', $vid);
            if ($rate > 0) $ctx['usd_rate'] = $rate;
        }
        return $ctx;
    }
}

if (!function_exists('bc_landing_services')) {
    /** The public service catalogue. 'app' is the logged-in product page. */
    function bc_landing_services() {
        return array(
            'airtime' => array(
                'name' => 'Airtime Top-Up', 'icon' => '📱', 'app' => 'web/Airtime.php', 'tint' => '#b8f1cc',
                'tagline' => 'Every network. Every amount. In seconds.',
                'hero' => 'Recharge MTN, Airtel, Glo and 9mobile lines instantly — for yourself, your family, or a whole contact list at once. No queues, no scratch cards, no failed-trip to the kiosk.',
                'features' => array(
                    array('Instant delivery', 'Top-ups land in seconds, 24/7 — including weekends and public holidays.'),
                    array('All Nigerian networks', 'MTN, Airtel, Glo and 9mobile on one screen, with automatic network detection from the phone number.'),
                    array('Bulk recharge', 'Use Bulk Airtime to recharge dozens of lines in a single upload — built for teams, schools and resellers.'),
                    array('Earn loyalty coins', 'Every recharge earns coins you can convert back to wallet funds.'),
                    array('Receipts & history', 'Every top-up is logged with a reference you can requery anytime.'),
                ),
                'steps' => array('Fund your wallet or pay at checkout', 'Enter the number — the network is detected for you', 'Confirm. The line is credited instantly.'),
                'audience' => 'Parents keeping family lines alive, businesses recharging staff lines, and resellers serving their street.',
                'faqs' => array(
                    array('How fast is delivery?', 'Most top-ups complete in under 10 seconds. If a network delays, the transaction stays visible in your history with a requery option.'),
                    array('Can I recharge another person?', 'Yes — any valid Nigerian number. You will see the detected network before you confirm.'),
                    array('What if a recharge fails?', 'Failed top-ups are reversed to your wallet automatically; the reference makes every case traceable.'),
                ),
            ),
            'data' => array(
                'name' => 'Data Bundles', 'icon' => '📶', 'app' => 'web/Data.php', 'tint' => '#d9d6fe',
                'tagline' => 'SME, corporate gifting & direct data at honest prices.',
                'hero' => 'From 1GB daily plans to heavy corporate-gifting bundles, buy data that delivers fast and lasts as promised — with plan validity and size shown before you pay.',
                'features' => array(
                    array('Every data type', 'SME, Corporate Gifting (CG), Direct Data and shared-data plans across all major networks.'),
                    array('Transparent plans', 'Size, validity and price are printed on every plan — no hidden deductions.'),
                    array('Data cards too', 'Generate printable data PIN cards for resale or gifting from the Data Card page.'),
                    array('Built for resellers', 'Tiered account-level pricing means your margin grows with your level.'),
                    array('Delivery tracking', 'Watch each order deliver, and requery any pending one in a click.'),
                ),
                'steps' => array('Pick a network and plan', 'Enter the beneficiary number', 'Pay from wallet — data delivers in seconds.'),
                'audience' => 'Students, remote workers, cafés and data resellers who buy in volume.',
                'faqs' => array(
                    array('What is Corporate Gifting data?', 'CG data is bought in bulk by businesses and shared out in smaller plans — usually the cheapest per-GB way to buy.'),
                    array('How do I check my balance?', 'Each network has a USSD balance code; your delivery message includes the right one for your plan.'),
                    array('Can I buy for someone else?', 'Yes — enter their number at checkout and they receive the data directly.'),
                ),
            ),
            'cable-tv' => array(
                'name' => 'Cable TV', 'icon' => '📺', 'app' => 'web/Cable.php', 'tint' => '#fde68a',
                'tagline' => 'DStv, GOtv, StarTimes & Showmax — renewed before the match starts.',
                'hero' => 'Verify your smartcard or IUC number, see the account name and due amount, and renew in under a minute. No decoder downtime, no agent commutes.',
                'features' => array(
                    array('Name verification first', 'We show the account holder name before you pay, so money never goes to the wrong decoder.'),
                    array('All major providers', 'DStv, GOtv, StarTimes and Showmax subscriptions and renewals.'),
                    array('Change or renew', 'Renew the current bouquet or switch to a new one — the due amount is calculated for you.'),
                    array('Instant activation', 'Most renewals activate within minutes of payment.'),
                ),
                'steps' => array('Choose provider and enter smartcard/IUC', 'Confirm the name and bouquet shown', 'Pay — activation follows automatically.'),
                'audience' => 'Homes, hotels, viewing centres and estates managing many decoders.',
                'faqs' => array(
                    array('Why do you show a name first?', 'To stop wrong-decoder payments — the #1 cause of cable TV payment disputes.'),
                    array('Can I pay before expiry?', 'Yes. Paying early simply extends your current cycle.'),
                    array('What if activation delays?', 'Keep your reference; support can requery the provider with it, and unresolved payments are reversed.'),
                ),
            ),
            'electricity' => array(
                'name' => 'Electricity Bills', 'icon' => '💡', 'app' => 'web/Electric.php', 'tint' => '#fde68a',
                'tagline' => 'Prepaid tokens & postpaid bills for every DisCo.',
                'hero' => 'Buy prepaid meter tokens or settle postpaid bills for EKEDC, IKEDC, AEDC, PHED and every other distribution company — with the meter name verified before payment.',
                'features' => array(
                    array('All 11+ DisCos', 'Eko, Ikeja, Abuja, Port Harcourt, Ibadan, Enugu, Jos, Kano, Kaduna, Benin, Yola and Aba Power.'),
                    array('Meter verification', 'Prepaid and postpaid meters are validated, with the registered name shown before you pay.'),
                    array('Token in seconds', 'Your 20-digit token appears on screen and in your history immediately after payment.'),
                    array('Minimums that flex', 'Buy from small top-ups to full monthly settlements in one transaction.'),
                ),
                'steps' => array('Select your DisCo and meter type', 'Enter meter number — verify the name', 'Pay and receive your token instantly.'),
                'audience' => 'Tenants, landlords, facility managers and anyone tired of estimated billing queues.',
                'faqs' => array(
                    array('Where is my token?', 'On the success screen, in your transaction history, and sent to your email. It never expires before use.'),
                    array('Prepaid or postpaid — which am I?', 'If you load tokens with a keypad meter, you are prepaid. If you receive monthly bills, you are postpaid.'),
                    array('Wrong meter number?', 'Name verification stops most mistakes. If the name is not yours, do not pay.'),
                ),
            ),
            'exam-pins' => array(
                'name' => 'Exam PINs', 'icon' => '🎓', 'app' => 'web/Exam.php', 'tint' => '#fbcfe8',
                'tagline' => 'WAEC, NECO, NABTEB & JAMB e-PINs, delivered instantly.',
                'hero' => 'Buy result-checker and registration e-PINs without leaving home. PINs are delivered on screen and to your email, ready to use immediately.',
                'features' => array(
                    array('All major boards', 'WAEC, NECO, NABTEB and JAMB PINs in one place.'),
                    array('Instant delivery', 'PIN and serial appear immediately — screenshot it, print it, or find it later in history.'),
                    array('Buy in bulk', 'Schools and cybercafés can stock PINs ahead of result season.'),
                    array('Genuine stock', 'PINs are sourced through authorised channels and verified before listing.'),
                ),
                'steps' => array('Choose the exam board and quantity', 'Pay from your wallet', 'Your PIN(s) appear instantly — copy or print.'),
                'audience' => 'Students, parents, schools, and exam-prep centres.',
                'faqs' => array(
                    array('How many times can a result PIN be used?', 'Result checker PINs typically allow up to 5 checks of the same result — the board rules apply.'),
                    array('I lost my PIN. Can I recover it?', 'Yes — every PIN you buy stays in your transaction history.'),
                    array('Do PINs expire?', 'Registration PINs follow the board\'s registration window; result PINs remain valid for their result year.'),
                ),
            ),
            'betting' => array(
                'name' => 'Betting Wallet Funding', 'icon' => '🎯', 'app' => 'web/Betting.php', 'tint' => '#b8f1cc',
                'tagline' => 'Fund Bet9ja, BetKing, 1xBet & 15+ bookmakers instantly.',
                'hero' => 'Top up your betting wallet the moment the odds move. We verify your customer ID with the bookmaker before taking a kobo.',
                'features' => array(
                    array('18+ bookmakers', 'Bet9ja, BetKing, Nairabet, 1xBet, Betway, MSport, BangBet and more.'),
                    array('ID verification', 'Your betting account name is confirmed with the bookmaker before payment.'),
                    array('24/7 funding', 'Matches do not wait for bank hours — neither do we.'),
                    array('Agent friendly', 'Shops funding for walk-in customers get reseller pricing by account level.'),
                ),
                'steps' => array('Pick your bookmaker', 'Enter your customer ID — verify the name', 'Pay; your betting wallet is credited in seconds.'),
                'audience' => 'Punters and betting-shop agents who fund customer accounts all day.',
                'faqs' => array(
                    array('How fast is credit?', 'Usually seconds. Keep your reference if a bookmaker delays — we can requery it.'),
                    array('Wrong customer ID?', 'The name check exists to stop that. If the name shown is not yours, cancel and re-enter.'),
                    array('Is responsible gaming supported?', 'Yes — fund only what you can afford to lose. Betting is 18+ and should stay entertainment.'),
                ),
            ),
            'gift-cards' => array(
                'name' => 'Gift Cards', 'icon' => '🎁', 'app' => 'web/GiftCard.php', 'tint' => '#fbcfe8',
                'tagline' => 'Thousands of global brands, delivered by email.',
                'hero' => 'Buy Amazon, Apple, Steam, Netflix, Spotify and thousands more gift cards across dozens of countries — priced in naira, delivered in minutes.',
                'features' => array(
                    array('Global catalogue', 'Thousands of products across the US, UK, EU and more, with live availability.'),
                    array('Transparent pricing', 'The naira price — FX and margin included — is shown before you pay. No surprises at checkout.'),
                    array('Email delivery', 'Codes are delivered to your email and stored in your history.'),
                    array('Send to anyone', 'Buy for yourself or gift directly to a friend\'s email.'),
                ),
                'steps' => array('Browse brands and pick a country + amount', 'See the exact naira price', 'Pay — the code lands in your email.'),
                'audience' => 'Gamers, streamers, remote workers paying for global tools, and gift-givers.',
                'faqs' => array(
                    array('Why does country matter?', 'Most gift cards are region-locked by the brand. Buy the country that matches the account you will redeem on.'),
                    array('How fast is delivery?', 'Most codes arrive within minutes; every order is tracked in your history.'),
                    array('Can I get a refund on a delivered code?', 'Delivered codes generally cannot be recalled by the brand, so double-check the region before paying.'),
                ),
            ),
            'crypto' => array(
                'name' => 'Crypto Hub', 'icon' => '🪙', 'app' => 'web/CryptoHub.php', 'tint' => '#d9d6fe',
                'tagline' => 'Buy, sell & swap crypto at live rates — settled to your wallet.',
                'hero' => 'Trade BTC, ETH, USDT, USDC, SOL and more with live pricing, clear fees, and naira settlement straight to your wallet. No exchange account gymnastics.',
                'features' => array(
                    array('Live rates, locked quotes', 'You see the exact rate and fee before confirming — the quote you accept is the trade you get.'),
                    array('Major assets', 'BTC, ETH, USDT, USDC, SOL, ADA, TRX, DOGE and more.'),
                    array('Naira in, naira out', 'Buy with wallet funds; sell back to naira without leaving the platform.'),
                    array('Counts toward referral volume', 'Crypto trades build your referral qualifying volume — invite friends and both of you earn.'),
                ),
                'steps' => array('Choose the asset and direction (buy/sell/swap)', 'Review the live quote and fee', 'Confirm — settlement lands in your wallet.'),
                'audience' => 'First-time buyers, freelancers receiving crypto, and traders who want naira rails without an exchange.',
                'faqs' => array(
                    array('Do I need an external wallet?', 'No for buying and selling here — trades settle to your platform wallet. You can provide an external address where supported.'),
                    array('Are rates live?', 'Yes — quotes refresh with the market and expire quickly so you never trade on stale prices.'),
                    array('Is crypto risky?', 'Prices move fast. Only trade what you understand and can afford to lose.'),
                ),
            ),
            'international-transfer' => array(
                'name' => 'International Transfer', 'icon' => '🌍', 'app' => 'web/IntlTransfer.php', 'tint' => '#b8f1cc',
                'tagline' => 'Send money abroad — including direct settlement to China.',
                'hero' => 'Pay suppliers, family and partners overseas at live FX rates, with wire payouts, multi-currency options and direct settlement routes to China for traders.',
                'features' => array(
                    array('Live FX, no hidden spread games', 'The rate and total fee are shown before you send — the recipient gets what the quote says.'),
                    array('China settlement', 'Direct supplier settlement routes built for importers paying factories and agents.'),
                    array('Tuition worldwide', 'Pay school fees to institutions abroad with student details validated before settlement.'),
                    array('Counts toward referral volume', 'Every transfer builds referral qualifying volume for you and your inviter.'),
                ),
                'steps' => array('Enter destination, amount and recipient', 'Review the live rate, fee and arrival estimate', 'Send — track it to settlement in your history.'),
                'audience' => 'Importers, parents paying tuition, and families supporting loved ones abroad.',
                'faqs' => array(
                    array('How long do transfers take?', 'Most corridors settle same-day to a few business days depending on destination banking. Your quote shows the estimate.'),
                    array('What do I need for China settlement?', 'Supplier bank details (or platform ID where supported) and the invoice amount. The form guides you field by field.'),
                    array('Are there limits?', 'Limits depend on verification level; your available limit is shown before you confirm.'),
                ),
            ),
            'virtual-cards' => array(
                'name' => 'Virtual Dollar Cards', 'icon' => '💳', 'app' => 'web/VirtualCard.php', 'tint' => '#fde68a',
                'tagline' => 'A USD card for global payments, issued in minutes.',
                'hero' => 'Create a virtual dollar card for subscriptions, ads, and international checkout — fund it from your wallet, freeze it anytime, and track every spend.',
                'features' => array(
                    array('Issued in minutes', 'No bank visit. Create, fund, and start paying online immediately.'),
                    array('Fund from wallet', 'Top up in USD from your naira wallet at the shown rate.'),
                    array('Freeze anytime', 'Lost details? Freeze the card in one tap and unfreeze when ready.'),
                    array('Spend tracking', 'Every transaction is listed with merchant and amount.'),
                ),
                'steps' => array('Request your card and complete verification', 'Fund it from your wallet', 'Use the details anywhere USD cards are accepted online.'),
                'audience' => 'Freelancers paying for tools, advertisers, and shoppers on international stores.',
                'faqs' => array(
                    array('Where can I use it?', 'Most online stores and services that accept USD debit cards, including ads platforms and SaaS subscriptions.'),
                    array('Can I withdraw from the card?', 'You can liquidate the card balance back to your wallet from the card page.'),
                    array('Is there a minimum funding?', 'Yes — the issue page shows the current minimum before you create the card.'),
                ),
            ),
            'esim' => array(
                'name' => 'Travel eSIM', 'icon' => '✈️', 'app' => 'web/ESIM.php', 'tint' => '#d9d6fe',
                'tagline' => 'Land connected in 190+ countries.',
                'hero' => 'Buy a data plan before you fly, scan the QR code, and land with working internet — no airport SIM queues, no roaming shock.',
                'features' => array(
                    array('190+ countries', 'Single-country, regional and global plans.'),
                    array('Instant QR delivery', 'Your eSIM QR and instructions arrive immediately after payment.'),
                    array('Keep your number', 'eSIM data runs alongside your physical SIM — WhatsApp and calls keep working.'),
                    array('Top up anytime', 'Running low mid-trip? Add data from your account anywhere.'),
                ),
                'steps' => array('Pick your destination and plan size', 'Pay and receive your QR code', 'Scan to install — activate on landing.'),
                'audience' => 'Travellers, digital nomads, airline crew and business flyers.',
                'faqs' => array(
                    array('Does my phone support eSIM?', 'Most flagship phones from 2019 onward do. Check the compatibility note on the purchase page before paying.'),
                    array('When should I install it?', 'Install before departure (needs Wi-Fi), then switch it on when you land.'),
                    array('Can I top up?', 'Yes — most plans support top-ups from your account while travelling.'),
                ),
            ),
            'bulk-sms' => array(
                'name' => 'Bulk SMS', 'icon' => '✉️', 'app' => 'web/BulkSMS.php', 'tint' => '#fbcfe8',
                'tagline' => 'Reach every customer phone in one campaign.',
                'hero' => 'Send branded SMS to thousands of customers at once — school fees reminders, church broadcasts, promo alerts — with delivery reports per message.',
                'features' => array(
                    array('Custom sender ID', 'Messages arrive from your business name, not a random number (registration supported).'),
                    array('Smart uploads', 'Paste numbers, upload a file, or pick from saved groups — duplicates are cleaned automatically.'),
                    array('Delivery reports', 'See delivered, pending and failed per recipient, with unit refunds for failed routes where applicable.'),
                    array('Scheduling', 'Write now, send at 8am tomorrow — campaigns can be scheduled ahead.'),
                ),
                'steps' => array('Buy SMS units to your wallet', 'Compose, pick recipients, review the unit cost', 'Send now or schedule — then watch delivery live.'),
                'audience' => 'Schools, churches, cooperatives, retailers and political/community organisers.',
                'faqs' => array(
                    array('How are units charged?', 'By page (160 characters) per recipient on most routes; the composer shows the exact unit cost before sending.'),
                    array('What is a sender ID?', 'The name shown as the sender. Short business names work best; we guide you through registration.'),
                    array('Do failed messages cost units?', 'Failed deliveries on refundable routes are credited back automatically.'),
                ),
            ),
        );
    }
}

if (!function_exists('bc_landing_head')) {
    function bc_landing_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

    function bc_landing_head($ctx, $title, $desc) {
        $brand = $ctx['brand'];
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo bc_landing_e($title); ?> | <?php echo bc_landing_e($ctx['site_name']); ?></title>
<meta name="description" content="<?php echo bc_landing_e($desc); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--brand:<?php echo bc_landing_e($brand); ?>;--ink:#101828;--cream:#f6f3ec;--card:#ffffff;--muted:#5b6472;--line:#e7e2d6}
*{box-sizing:border-box}html{scroll-behavior:smooth}
body{margin:0;font-family:'Inter',system-ui,sans-serif;background:var(--cream);color:var(--ink)}
h1,h2,h3,.disp{font-family:'Archivo',sans-serif;letter-spacing:-.02em}
a{color:inherit}
.wrap{max-width:1140px;margin:0 auto;padding:0 20px}
.nav{position:sticky;top:14px;z-index:50;display:flex;justify-content:center;padding:0 12px}
.nav-in{background:rgba(16,24,40,.94);color:#fff;border-radius:999px;padding:10px 12px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:center;box-shadow:0 12px 32px rgba(16,24,40,.25)}
.nav-in a{padding:9px 15px;border-radius:999px;font-weight:600;font-size:14px;text-decoration:none}
.nav-in a:hover{background:rgba(255,255,255,.12)}
.nav-in a.on{background:var(--brand)}
.nav-in .cta{background:#fff;color:var(--ink)}
.hero{padding:72px 0 30px;text-align:center}
.kick{display:inline-block;background:#fff;border:1px solid var(--line);border-radius:999px;padding:7px 14px;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.08em}
.hero h1{font-size:clamp(42px,7.2vw,84px);line-height:.98;margin:22px 0 14px;font-weight:900;text-transform:uppercase}
.hero h1 .hl{color:var(--brand)}
.hero p{max-width:640px;margin:0 auto;color:var(--muted);font-size:19px;line-height:1.55}
.btn{display:inline-block;border-radius:999px;padding:15px 30px;font-weight:700;text-decoration:none;font-size:16px}
.btn-dark{background:var(--ink);color:#fff}.btn-brand{background:var(--brand);color:#fff}
.hero-cta{margin-top:28px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
.stats{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;margin:38px 0 8px}
.stat{background:#fff;border:1px solid var(--line);border-radius:18px;padding:16px 22px;text-align:left;min-width:170px}
.stat b{font-family:'Archivo';font-size:24px;display:block}
.stat span{color:var(--muted);font-size:13px}
.cards3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin:34px 0}
.step{border-radius:26px;padding:28px;min-height:230px}
.step .n{font-family:'Archivo';font-weight:900;font-size:15px;text-transform:uppercase;opacity:.65}
.step h3{font-size:28px;margin:10px 0 8px}
.step p{margin:0;line-height:1.55}
section.blk{padding:56px 0}
.blk h2{font-size:clamp(30px,4.4vw,48px);text-transform:uppercase;margin:0 0 10px}
.blk .sub{color:var(--muted);max-width:620px;line-height:1.6}
.feat{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-top:28px}
.feat .f{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:22px}
.feat .f b{display:block;font-size:17px;margin-bottom:6px}
.feat .f p{margin:0;color:var(--muted);line-height:1.55}
.calc{background:var(--ink);color:#fff;border-radius:30px;padding:34px;margin:20px 0}
.calc h2{color:#fff}
.calc-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-top:18px}
.calc label{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.75;display:block;margin-bottom:8px}
.calc input{width:100%;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2);color:#fff;border-radius:14px;padding:14px;font-size:18px;font-weight:700}
.calc .out{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:18px;padding:20px}
.calc .out b{font-family:'Archivo';font-size:30px;display:block;color:#b8f1cc}
.calc .out span{opacity:.75;font-size:14px;line-height:1.5;display:block}
details{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin:10px 0}
summary{font-weight:700;cursor:pointer}
details p{color:var(--muted);line-height:1.6;margin:10px 0 0}
.grid-svc{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:30px}
.svc{background:#fff;border:1px solid var(--line);border-radius:24px;padding:26px;text-decoration:none;display:block;transition:transform .15s}
.svc:hover{transform:translateY(-4px)}
.svc .ic{font-size:34px}
.svc h3{margin:12px 0 6px;font-size:22px}
.svc p{color:var(--muted);margin:0;line-height:1.55}
.svc .go{margin-top:14px;font-weight:700;color:var(--brand)}
.cta-band{background:var(--brand);color:#fff;border-radius:30px;padding:44px 30px;text-align:center;margin:30px 0 60px}
.cta-band h2{color:#fff;margin:0 0 8px}
footer{border-top:1px solid var(--line);padding:34px 0 44px;color:var(--muted);font-size:14px}
footer .cols{display:flex;gap:34px;flex-wrap:wrap;justify-content:space-between}
footer a{display:block;margin:7px 0;text-decoration:none}
footer b{color:var(--ink)}
@media(max-width:860px){.cards3,.grid-svc{grid-template-columns:1fr}.feat,.calc-grid{grid-template-columns:1fr}.hero{padding:48px 0 20px}}
</style>
</head>
<body>
<?php
    }

    function bc_landing_nav($ctx, $active = '') {
        $items = array(
            'home' => array('/', 'Home'),
            'services' => array('Services.php', 'Services'),
            'refer' => array('ReferAndEarn.php', 'Refer & Earn'),
            'login' => array('web/Login.php', 'Log in'),
        );
        ?>
<div class="nav"><nav class="nav-in">
    <?php foreach ($items as $key => $it): ?>
        <a href="<?php echo bc_landing_e($it[0]); ?>"<?php echo $active === $key ? ' class="on"' : ''; ?>><?php echo bc_landing_e($it[1]); ?></a>
    <?php endforeach; ?>
    <a class="cta" href="web/Register.php">Start free</a>
</nav></div>
<?php
    }

    function bc_landing_foot($ctx) {
        ?>
<footer><div class="wrap"><div class="cols">
    <div><b><?php echo bc_landing_e($ctx['site_name']); ?></b><br>Everyday bills, global money — one wallet.</div>
    <div><b>Services</b>
        <a href="Services.php">All services</a>
        <?php foreach (array_slice(bc_landing_services(), 0, 5, true) as $slug => $s): ?><a href="services/<?php echo bc_landing_e($slug); ?>.php"><?php echo bc_landing_e($s['name']); ?></a><?php endforeach; ?>
    </div>
    <div><b>Earn</b>
        <a href="ReferAndEarn.php">Refer &amp; Earn</a>
        <a href="web/Referral.php">My referral dashboard</a>
        <a href="web/CoinConversion.php">Convert coins</a>
    </div>
    <div><b>Account</b>
        <a href="web/Register.php">Create account</a>
        <a href="web/Login.php">Log in</a>
        <a href="blog.php">Blog</a>
        <a href="Privacy.php">Privacy Policy</a>
        <a href="Terms.php">Terms of Service</a>
        <a href="web/DeleteAccount.php">Delete my account</a>
    </div>
</div>
<p style="margin-top:26px">© <?php echo date('Y'); ?> <?php echo bc_landing_e($ctx['site_name']); ?>. Virtual assets and international transfers carry risk — only transact what you understand and can afford.</p>
</div></footer>
</body>
</html>
<?php
    }
}

if (!function_exists('bc_landing_render_referral')) {
    function bc_landing_render_referral($ctx) {
        $coins = (int)$ctx['bonus_coins'];
        $rate = max(0.000001, (float)$ctx['coin_rate']);
        bc_landing_head($ctx, 'Refer & Earn — ' . number_format($coins) . ' coins per qualified friend',
            'Invite friends, track their verified transaction progress and unlock referral coins.');
        bc_landing_nav($ctx, 'refer');
        ?>
<div class="wrap"><div class="hero"><span class="kick">Grow together</span>
<h1>Real friends.<br><span class="hl">Real progress.</span></h1>
<p>Earn <strong><?php echo bc_landing_e(number_format($coins)); ?> coins</strong> when a friend you refer completes <strong>$100</strong> in verified Crypto or International Transfers. Referral coins stay locked until the conditions are met.</p>
<div class="hero-cta"><a class="btn btn-brand" href="web/Referral.php">See my referral growth</a><a class="btn btn-dark" href="#how">How it works</a></div>
<div class="stats"><div class="stat"><b><?php echo number_format($coins); ?> coins</b><span>current reward per qualified friend</span></div><div class="stat"><b>$100</b><span>minimum verified transaction volume</span></div><div class="stat"><b>0 spendable</b><span>until your friend qualifies</span></div><div class="stat"><b>One reward</b><span>per genuine referred friend</span></div></div></div>
<div class="cards3" id="how"><div class="step" style="background:#b8f1cc"><div class="n">01</div><h3>Invite a friend</h3><p>Share your personal link. Your friend must sign up using your referral code.</p></div><div class="step" style="background:#d9d6fe"><div class="n">02</div><h3>Watch their growth</h3><p>Track each friend's verified USD volume, percentage completed and amount remaining toward $100. Pending requests and quotes do not count.</p></div><div class="step" style="background:#fde68a"><div class="n">03</div><h3>Unlock your coins</h3><p>After confirmed qualifying settlements and identity checks, the referrer's coins unlock for conversion. No separate cash reward is paid to either wallet.</p></div></div>
<section class="blk"><h2>Understand your reward</h2><div class="feat"><div class="f"><b>Only settled activity</b><p>Completed Crypto trades and International Transfers count after provider confirmation. Deposits, funding, internal transfers, fees, failed transactions, requests and quotes do not count.</p></div><div class="f"><b>Locked means locked</b><p>Pending referral coins cannot be spent, converted or withdrawn. A reversal that brings qualifying volume below $100 revokes the reward.</p></div><div class="f"><b>Coins to wallet funds</b><p>The current conversion rate is <?php echo bc_landing_e(number_format($ctx['coin_rate'], 2)); ?> coins per ₦1. Conversion requests require at least <?php echo bc_landing_e(number_format($ctx['coin_min'])); ?> unlocked coins and admin approval. Bank withdrawals remain subject to wallet limits.</p></div><div class="f"><b>Genuine referrals only</b><p>Self-referrals do not qualify. Active accounts, distinct contact details, verified emails and settlement review are required. Rewards cannot be earned repeatedly by reversing and repeating a transaction.</p></div></div></section>
<div class="cta-band"><h2>Your next milestone starts with a friend.</h2><p>See who joined, what remains and which rewards are unlocked.</p><a class="btn btn-dark" href="web/Referral.php">Open referral dashboard</a></div></div>
<?php bc_landing_foot($ctx);
    }
}

if (!function_exists('bc_landing_render_services_index')) {
    function bc_landing_render_services_index($ctx) {
        bc_landing_head($ctx, 'Services', 'Every service on ' . $ctx['site_name'] . ' — bills, data, crypto, transfers, cards and more, explained.');
        bc_landing_nav($ctx, 'services');
        ?>
<div class="wrap">
    <div class="hero">
        <span class="kick">Services</span>
        <h1>Everything your<br><span class="hl">wallet can do.</span></h1>
        <p>One account for everyday bills and global money. Pick a service to see exactly how it works, what it costs, and how fast it delivers.</p>
    </div>
    <div class="grid-svc">
        <?php foreach (bc_landing_services() as $slug => $s): ?>
        <a class="svc" href="services/<?php echo bc_landing_e($slug); ?>.php">
            <div class="ic"><?php echo $s['icon']; ?></div>
            <h3><?php echo bc_landing_e($s['name']); ?></h3>
            <p><?php echo bc_landing_e($s['tagline']); ?></p>
            <div class="go">Explore →</div>
        </a>
        <?php endforeach; ?>
    </div>
    <div class="cta-band">
        <h2>One wallet. Every service.</h2>
        <p style="opacity:.9">Fund once and spend across bills, crypto, transfers and cards — earning coins as you go.</p>
        <a class="btn btn-dark" href="web/Register.php">Create free account</a>
    </div>
</div>
<?php
        bc_landing_foot($ctx);
    }
}

if (!function_exists('bc_landing_render_service')) {
    function bc_landing_render_service($ctx, $slug) {
        $all = bc_landing_services();
        if (!isset($all[$slug])) { $slug = 'airtime'; }
        $s = $all[$slug];
        bc_landing_head($ctx, $s['name'], $s['tagline'] . ' — on ' . $ctx['site_name'] . '.');
        bc_landing_nav($ctx, 'services');
        ?>
<div class="wrap">
    <div class="hero">
        <span class="kick"><?php echo $s['icon']; ?> <?php echo bc_landing_e($s['name']); ?></span>
        <h1><?php echo bc_landing_e($s['tagline']); ?></h1>
        <p><?php echo bc_landing_e($s['hero']); ?></p>
        <div class="hero-cta">
            <a class="btn btn-dark" href="<?php echo bc_landing_e($s['app']); ?>">Use <?php echo bc_landing_e($s['name']); ?></a>
            <a class="btn btn-brand" href="Services.php">All services</a>
        </div>
    </div>

    <section class="blk">
        <h2>Why <?php echo bc_landing_e($ctx['site_name']); ?></h2>
        <div class="feat">
            <?php foreach ($s['features'] as $f): ?>
            <div class="f"><b><?php echo bc_landing_e($f[0]); ?></b><p><?php echo bc_landing_e($f[1]); ?></p></div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="cards3">
        <?php $tints = array('#b8f1cc', '#d9d6fe', '#fde68a'); foreach ($s['steps'] as $i => $step): ?>
        <div class="step" style="background:<?php echo $tints[$i % 3]; ?>"><div class="n">Step <?php echo $i + 1; ?></div><h3><?php echo $i === 0 ? 'Start' : ($i === 1 ? 'Confirm' : 'Done'); ?></h3><p><?php echo bc_landing_e($step); ?></p></div>
        <?php endforeach; ?>
    </div>

    <section class="blk">
        <h2>Who it is for</h2>
        <p class="sub"><?php echo bc_landing_e($s['audience']); ?></p>
    </section>

    <section class="blk" style="padding-top:6px">
        <h2>Questions</h2>
        <?php foreach ($s['faqs'] as $q): ?>
        <details><summary><?php echo bc_landing_e($q[0]); ?></summary><p><?php echo bc_landing_e($q[1]); ?></p></details>
        <?php endforeach; ?>
    </section>

    <section class="blk" style="padding-top:6px">
        <h2>Keep exploring</h2>
        <div class="grid-svc">
            <?php $shown = 0; foreach ($all as $oslug => $os): if ($oslug === $slug || $shown >= 3) continue; $shown++; ?>
            <a class="svc" href="<?php echo bc_landing_e($oslug); ?>.php">
                <div class="ic"><?php echo $os['icon']; ?></div>
                <h3><?php echo bc_landing_e($os['name']); ?></h3>
                <p><?php echo bc_landing_e($os['tagline']); ?></p>
                <div class="go">Explore →</div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="cta-band">
        <h2>Try <?php echo bc_landing_e($s['name']); ?> now</h2>
        <p style="opacity:.9">Free account, instant wallet, coins on every purchase.</p>
        <a class="btn btn-dark" href="web/Register.php">Create free account</a>
    </div>
</div>
<?php
        bc_landing_foot($ctx);
    }
}

if (!function_exists('bc_landing_legal_sections')) {
    /**
     * §43 — Privacy / Terms content. One source, two renderers: the root
     * landing pages (Privacy.php / Terms.php) and web/policy/*.php, which
     * homepage templates 2 & 3 already link to. {site} is replaced with the
     * vendor's trading name at render time.
     */
    function bc_landing_legal_sections($kind) {
        if ($kind === 'terms') {
            return array(
                array('title' => '1. Acceptance of these terms', 'body' => array(
                    'By creating an account or using {site} (the "Platform"), you agree to these Terms of Service and our Privacy Policy. If you do not agree, do not use the Platform. We may update these terms; continued use after an update means you accept the revised terms.')),
                array('title' => '2. Our services', 'body' => array(
                    '{site} provides digital and financial services which may include airtime and data, cable TV and electricity payments, exam PINs, betting wallet funding, gift cards, cryptocurrency exchange, international transfers, virtual cards, eSIM and bulk SMS. Services are delivered through our own systems and third-party providers; availability can vary by provider and region.')),
                array('title' => '3. Accounts and eligibility', 'body' => array(
                    'You must be at least 18 years old (or the age of majority where you live) and provide accurate registration details. You are responsible for activity under your account and for keeping your password, security PIN and one-time codes confidential. Identity verification (KYC) may be required for some services and limits.')),
                array('title' => '4. Wallet, payments and fees', 'body' => array(
                    'Your wallet holds prepaid funds for spending on the Platform; it is not a bank account and balances do not earn interest. Prices, provider fees, FX rates and margins are displayed before you confirm a transaction — check them, because confirmed transactions are processed immediately. Where a provider fails a transaction, the amount is reversed to your wallet.')),
                array('title' => '5. Cryptocurrency and international transfers', 'body' => array(
                    'Virtual asset prices are volatile and can lose value quickly; past performance never guarantees future results. International transfers are subject to destination-country rules, compliance checks and settlement times shown at quote. Only transact what you understand and can afford to lose.')),
                array('title' => '6. Referrals and loyalty coins', 'body' => array(
                    'Referral rewards and loyalty coins follow the programme terms shown on our Refer & Earn page and in bc-admin Loyalty Settings, which may change. Self-referrals, duplicate accounts and other abuse do not earn and may lead to forfeiture and suspension. Coins have no cash value outside the Platform conversion programme.')),
                array('title' => '7. Acceptable use', 'body' => array(
                    'You must not use the Platform for fraud, money laundering, terrorist financing, sanctions evasion, or any unlawful purpose; must not attempt to compromise our systems; and must only transact with funds and instruments you lawfully control. We may monitor, decline or reverse transactions that breach this clause or legal obligations.')),
                array('title' => '8. Suspension, closure and account deletion', 'body' => array(
                    'We may suspend or close accounts that breach these terms, present legal or security risk, or remain inactive under our retention rules. You may request deletion of your account at any time from the Delete My Account page; deletion is reviewed and approved by our team, after which the account is closed and, on final erasure, permanently removed subject to legal retention duties.')),
                array('title' => '9. Liability', 'body' => array(
                    'The Platform is provided "as is". To the maximum extent permitted by law, {site} is not liable for indirect or consequential losses, provider outages, or losses from market movements. Nothing in these terms limits liability that cannot lawfully be limited.')),
                array('title' => '10. Contact', 'body' => array(
                    'Questions about these terms? Reach us through the support chat on this site.')),
            );
        }
        return array(
            array('title' => '1. Overview', 'body' => array(
                'This Privacy Policy explains what personal information {site} ("we", "us") collects, how we use it, who we share it with, and the choices you have. By using our website, apps and services you agree to this policy.')),
            array('title' => '2. Information we collect', 'body' => array(
                'Account information — name, username, email address, phone number and password (stored hashed).',
                'Verification information — where required for KYC, identity documents, BVN/NIN and related checks.',
                'Transaction information — services you buy, amounts, wallet and ledger history, beneficiaries and delivery details.',
                'Technical information — device, browser, IP address and usage logs needed to run and secure the service.')),
            array('title' => '3. How we use your information', 'body' => array(
                'To create and operate your account, deliver the services you buy, process payments and prevent fraud. To verify identity where the law or our risk rules require it. To send service messages (receipts, security codes, account alerts) and, with your consent where required, product updates. To improve our services and meet legal, tax and regulatory obligations.')),
            array('title' => '4. Who we share it with', 'body' => array(
                'We share the minimum necessary with: payment and service providers who fulfil your transactions (networks, billers, card and transfer partners); identity-verification providers; and regulators, law-enforcement or courts where legally required. We do not sell your personal information.')),
            array('title' => '5. Cookies and analytics', 'body' => array(
                'We use cookies and similar technologies that are necessary for sign-in, security and preferences, and — where configured — privacy-respecting analytics and advertising pixels described in the site\'s cookie notices. You can control cookies in your browser settings.')),
            array('title' => '6. How we protect your information', 'body' => array(
                'Passwords and security PINs are stored hashed, sensitive operations require step-up verification, sessions can be revoked, and access to production data is restricted to authorised staff. No system is perfectly secure, but we work to protect your information against unauthorised access, loss or misuse.')),
            array('title' => '7. How long we keep it', 'body' => array(
                'We keep account and transaction records for as long as your account is open and afterwards only as long as financial, tax and anti-money-laundering laws require, after which records are deleted or anonymised.')),
            array('title' => '8. Your rights', 'body' => array(
                'Subject to applicable law you may request access to, correction of, or deletion of your personal information, and object to certain processing. You can request account deletion from our Delete My Account page (web/DeleteAccount.php): we verify it is really you with a code sent to your registered email, our team reviews the request, and on approval your account is closed and later permanently erased subject to section 7.')),
            array('title' => '9. Children', 'body' => array(
                'Our services are not directed at children below the age of majority, and we do not knowingly collect their information.')),
            array('title' => '10. Changes and contact', 'body' => array(
                'We may update this policy and will post the new version here with a revised date. For privacy questions or requests, contact us through the support chat on this site.')),
        );
    }

    function bc_landing_render_legal($ctx, $kind) {
        $is_terms = ($kind === 'terms');
        $title = $is_terms ? 'Terms of Service' : 'Privacy Policy';
        bc_landing_head($ctx, $title, $title . ' for ' . $ctx['site_name'] . ' — plain-language rules for using our services.');
        bc_landing_nav($ctx, '');
        ?>
<div class="wrap">
    <div class="hero">
        <span class="kick"><?php echo $is_terms ? '📜 Terms' : '🔒 Privacy'; ?></span>
        <h1><?php echo bc_landing_e($title); ?></h1>
        <p>Last updated <?php echo date('F d, Y'); ?>. Written in plain language — the short version is: we collect what we need to run your account, we never sell it, and you can ask us to delete it.</p>
        <div class="hero-cta">
            <a class="btn btn-dark" href="<?php echo $is_terms ? 'Privacy.php' : 'Terms.php'; ?>">Read the <?php echo $is_terms ? 'Privacy Policy' : 'Terms of Service'; ?></a>
            <a class="btn btn-brand" href="web/DeleteAccount.php">Delete my account</a>
        </div>
    </div>
    <section class="blk" style="padding-top:16px">
        <?php foreach (bc_landing_legal_sections($kind) as $sec): ?>
        <details open>
            <summary><?php echo bc_landing_e(str_replace('{site}', $ctx['site_name'], $sec['title'])); ?></summary>
            <?php foreach ($sec['body'] as $para): ?><p><?php echo bc_landing_e(str_replace('{site}', $ctx['site_name'], $para)); ?></p><?php endforeach; ?>
        </details>
        <?php endforeach; ?>
    </section>
    <div class="cta-band">
        <h2>Questions about your data?</h2>
        <p style="opacity:.9">Message us any time through support, or manage your account below.</p>
        <a class="btn btn-dark" href="web/DeleteAccount.php">Request account deletion</a>
    </div>
</div>
<?php
        bc_landing_foot($ctx);
    }
}
