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
        require_once __DIR__ . '/bc-public-meridian.php';
        $m = bc_meridian_context();
        $ctx = array(
            'vendor_id' => $m['vendor_id'], 'site_name' => $m['site_name'], 'short_brand' => $m['short_brand'],
            'logo' => $m['logo_url'], 'logo_url' => $m['logo_url'], 'site_desc' => $m['site_desc'],
            'brand' => $m['primary_color'], 'primary_color' => $m['primary_color'],
            'app_url' => $m['app_url'], 'app_badge_html' => $m['app_badge_html'],
            'email' => $m['email'], 'phone' => $m['phone'], 'address' => $m['address'],
            'whatsapp' => $m['whatsapp'], 'whatsapp_url' => $m['whatsapp_url'],
            'rates' => $m['rates'], 'registration_open' => $m['registration_open'],
            'reward_usd' => 0.0, 'qualifying_usd' => 100.0,
            'bonus_coins' => 1000, 'coin_rate' => 500.0, 'coin_min' => 100,
            'usd_rate' => (float)($m['rates']['USD'] ?? 0.0),
        );
        if (!($connection_server instanceof mysqli) || $ctx['vendor_id'] <= 0) return $ctx;

        $vid = (int)$ctx['vendor_id'];
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
        if (function_exists('getLiveExchangeRate') && $ctx['usd_rate'] <= 0) {
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
                'name' => 'Airtime Top-Up', 'icon' => '📱', 'app' => '/web/Airtime.php', 'tint' => '#b8f1cc',
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
                'name' => 'Data Bundles', 'icon' => '📶', 'app' => '/web/Data.php', 'tint' => '#d9d6fe',
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
                'name' => 'Cable TV', 'icon' => '📺', 'app' => '/web/Cable.php', 'tint' => '#fde68a',
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
                'name' => 'Electricity Bills', 'icon' => '💡', 'app' => '/web/Electric.php', 'tint' => '#fde68a',
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
                'name' => 'Exam PINs', 'icon' => '🎓', 'app' => '/web/Exam.php', 'tint' => '#fbcfe8',
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
                'name' => 'Betting Wallet Funding', 'icon' => '🎯', 'app' => '/web/Betting.php', 'tint' => '#b8f1cc',
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
                'name' => 'Gift Cards', 'icon' => '🎁', 'app' => '/web/GiftCard.php', 'tint' => '#fbcfe8',
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
                'name' => 'Crypto Hub', 'icon' => '🪙', 'app' => '/web/CryptoHub.php', 'tint' => '#d9d6fe',
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
                'name' => 'International Transfer', 'icon' => '🌍', 'app' => '/web/IntlTransfer.php', 'tint' => '#b8f1cc',
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
                'name' => 'Virtual Dollar Cards', 'icon' => '💳', 'app' => '/web/VirtualCard.php', 'tint' => '#fde68a',
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
                'name' => 'Travel eSIM', 'icon' => '✈️', 'app' => '/web/ESIM.php', 'tint' => '#d9d6fe',
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
                'name' => 'Bulk SMS', 'icon' => '✉️', 'app' => '/web/BulkSMS.php', 'tint' => '#fbcfe8',
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
            'bank-transfer' => array(
                'name' => 'Local Bank Transfers', 'icon' => '🏦', 'app' => '/web/SendFund.php', 'tint' => '#b8f1cc',
                'tagline' => 'Fast, reliable transfers from your wallet to any local bank account.',
                'hero' => 'Send funds directly from your wallet to commercial and microfinance bank accounts across Nigeria with recipient name lookup and instant receipts.',
                'features' => array(
                    array('Recipient name verification', 'Confirm the destination account name before authorizing your transfer.'),
                    array('Transaction PIN & OTP security', 'Every payout is protected by your transaction PIN and configured step-up security checks.'),
                    array('Wide bank coverage', 'Transfer seamlessly to major commercial banks, digital banks, and MFBs.'),
                    array('Traceable receipts', 'Download or share bank transfer receipts with session IDs and references anytime.'),
                ),
                'steps' => array('Choose destination bank and enter account number', 'Confirm verified recipient name and amount', 'Authorize with your security PIN — funds settle promptly.'),
                'audience' => 'Individuals paying bills or family, freelancers withdrawing earnings, and merchants settling suppliers.',
                'faqs' => array(
                    array('How are transfer fees shown?', 'The exact fee and total debit are displayed before you confirm.'),
                    array('Can I verify the recipient first?', 'Yes — account name lookup runs before you authorize payment.'),
                    array('Where do receipts live?', 'Every transfer receipt is stored in your transaction history.'),
                ),
            ),
            'share-fund' => array(
                'name' => 'Share Funds (P2P)', 'icon' => '🤝', 'app' => '/web/ShareFund.php', 'tint' => '#d9d6fe',
                'tagline' => 'Instant wallet-to-wallet transfers between platform users.',
                'hero' => 'Move wallet funds to friends, family, teammates, or resellers on the platform in seconds — with recipient verification and full ledger records.',
                'features' => array(
                    array('Instant wallet settlement', 'Peer transfers reflect immediately in the recipient’s wallet.'),
                    array('Recipient lookup', 'Verify the recipient username and profile details before sending.'),
                    array('PIN-protected checkout', 'Transfers require your transaction PIN so your balance stays protected.'),
                    array('Share history', 'Review every sent and received peer transfer in Share Fund History.'),
                ),
                'steps' => array('Enter the recipient username', 'Confirm their verified details and amount', 'Authorize with your PIN — the transfer arrives instantly.'),
                'audience' => 'Friends splitting bills, families sharing allowances, and resellers funding sub-agents.',
                'faqs' => array(
                    array('Are P2P transfers instant?', 'Yes — wallet-to-wallet transfers settle immediately once confirmed.'),
                    array('Can I reverse a sent transfer?', 'Confirmed transfers cannot be reversed automatically, so always verify the recipient name first.'),
                    array('Is there a record?', 'Both sender and recipient see the entry in their Share Fund history.'),
                ),
            ),
            'nin-card' => array(
                'name' => 'Digital NIN Slip', 'icon' => '🪪', 'app' => '/web/NINCard.php', 'tint' => '#fde68a',
                'tagline' => 'Verify and generate printable digital NIN slips securely.',
                'hero' => 'Retrieve verified NIN identity details and generate standard, improved, or premium printable slip layouts directly from your account.',
                'features' => array(
                    array('Multiple slip layouts', 'Choose the format you need with crisp, print-ready output.'),
                    array('Direct record verification', 'Queries authorized verification channels before rendering your slip.'),
                    array('History & re-downloads', 'Access previously generated NIN slips from your NIN Card History.'),
                    array('Transparent pricing', 'The applicable verification fee is displayed clearly before submission.'),
                ),
                'steps' => array('Enter the NIN or phone number as supported', 'Confirm the fee and layout option', 'Retrieve and print your verified digital NIN slip.'),
                'audience' => 'Individuals needing a quick printable slip and authorized verification centres.',
                'faqs' => array(
                    array('Where can I re-download a slip?', 'Open NIN Card History in your account to view past verified slips.'),
                    array('What if verification fails?', 'Failed lookups that do not return a record are handled according to provider status and refund rules.'),
                    array('Is my data kept private?', 'Identity requests are restricted to your authenticated session.'),
                ),
            ),
            'bvn-verification' => array(
                'name' => 'BVN Verification', 'icon' => '🛡️', 'app' => '/web/BVNVerification.php', 'tint' => '#b8f1cc',
                'tagline' => 'Fast identity verification for higher limits and compliance.',
                'hero' => 'Verify Bank Verification Number (BVN) records securely to unlock higher wallet limits, virtual accounts, and regulated service tiers.',
                'features' => array(
                    array('Instant status check', 'Submit your verification details and receive real-time feedback.'),
                    array('Higher tier access', 'Unlock eligible services, virtual accounts, and expanded transaction limits.'),
                    array('Protected submission', 'Encrypted transport and authenticated session controls keep your details safe.'),
                    array('Clear fee display', 'Any verification fee configured by the platform is shown upfront.'),
                ),
                'steps' => array('Sign in and open BVN Verification', 'Enter your BVN and required details', 'Submit to verify and upgrade your account status.'),
                'audience' => 'Verified personal and business users unlocking full wallet and card capabilities.',
                'faqs' => array(
                    array('Why is BVN verification needed?', 'It helps confirm account ownership, prevent fraud, and enable higher limits and virtual bank accounts.'),
                    array('Does verification share my bank balance?', 'No — identity verification only confirms matching identity records.'),
                    array('Need help with a mismatch?', 'Contact support with your registered email and error details.'),
                ),
            ),
        );
    }
}

if (!function_exists('bc_landing_head')) {
    function bc_landing_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

    function bc_landing_head($ctx, $title, $desc, $world = 'personal') {
        require_once __DIR__ . '/bc-public-meridian.php';
        $world = ($world === 'business') ? 'business' : 'personal';
        $style_vars = ($world === 'personal') ? bc_meridian_personal_style_vars($ctx['primary_color'] ?? '#287BFF') : '';
        $js_config = array(
            'siteName' => $ctx['site_name'],
            'appUrl' => $ctx['app_url'] ?? '',
            'downloadPageUrl' => '/DownloadApp.php',
            'businessUrl' => '/Business.php',
            'personalUrl' => '/',
            'registerUrl' => '/web/Register.php',
            'loginUrl' => '/web/Login.php',
            'rates' => $ctx['rates'] ?? array(),
        );
        ?><!DOCTYPE html>
<html lang="en" data-theme="dark" data-world="<?php echo bc_landing_e($world); ?>"<?php if ($style_vars !== ''): ?> style="<?php echo bc_landing_e($style_vars); ?>"<?php endif; ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?php echo bc_landing_e($title); ?> | <?php echo bc_landing_e($ctx['site_name']); ?></title>
<meta name="description" content="<?php echo bc_landing_e($desc); ?>">
<link rel="stylesheet" href="/asset/meridian-1/meridian-1.css">
<link rel="stylesheet" href="/asset/meridian-1/meridian-theme.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<script>window.MERIDIAN_CONFIG = <?php echo json_encode($js_config, JSON_UNESCAPED_SLASHES); ?>;</script>
</head>
<body>
<main class="m-sub-main" style="padding-top:78px">
<?php
    }

    function bc_landing_nav($ctx, $active = '', $world = 'personal') {
        require_once __DIR__ . '/bc-public-meridian.php';
        $world = ($world === 'business') ? 'business' : 'personal';
        echo bc_meridian_header_and_menu($ctx, $world);
    }

    function bc_landing_foot($ctx) {
        require_once __DIR__ . '/bc-public-meridian.php';
        require_once __DIR__ . '/bc-public-pages.php';
        echo '</main>';
        ob_start();
        bc_public_directory_html();
        $directory_html = ob_get_clean();
        echo bc_meridian_footer($ctx, $directory_html);
        echo '<script src="/asset/meridian-1/meridian-1.js"></script></body></html>';
    }
}

if (!function_exists('bc_landing_render_referral')) {
    function bc_landing_render_referral($ctx) {
        $coins = (int)$ctx['bonus_coins'];
        $qual = number_format((float)$ctx['qualifying_usd'], 0);
        bc_landing_head($ctx, 'Refer & Earn — ' . number_format($coins) . ' coins per qualified friend',
            'Invite friends, track their verified transaction progress and unlock referral coins.', 'personal');
        bc_landing_nav($ctx, 'refer', 'personal');
        ?>
<div class="wrap">
    <section class="m-sub-hero">
        <div class="m-page-split">
            <div>
                <p class="eyebrow"><i></i>Grow together</p>
                <h1>Real friends.<br>Real progress.</h1>
                <p class="lede">Earn <strong><?php echo bc_landing_e(number_format($coins)); ?> coins</strong> when a friend you refer completes <strong>$<?php echo bc_landing_e($qual); ?></strong> in verified Crypto or International Transfers. Referral coins stay locked until the conditions are met.</p>
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:24px">
                    <a class="btn primary" href="/web/Register.php">Start referring</a>
                    <a class="btn ghost" href="#how">How it works</a>
                </div>
            </div>
            <aside class="m-showcase-card">
                <p class="eyebrow"><i></i>Programme Rules</p>
                <div class="m-kv-row"><span>Reward per qualified friend</span><strong><?php echo bc_landing_e(number_format($coins)); ?> coins</strong></div>
                <div class="m-kv-row"><span>Qualifying volume</span><strong>$<?php echo bc_landing_e($qual); ?> settled</strong></div>
                <div class="m-kv-row"><span>Conversion rate</span><strong><?php echo bc_landing_e(number_format($ctx['coin_rate'], 2)); ?> coins / ₦1</strong></div>
                <div class="m-kv-row"><span>Minimum conversion</span><strong><?php echo bc_landing_e(number_format($ctx['coin_min'])); ?> unlocked coins</strong></div>
                <p class="m-notice" style="margin:4px 0 0">Pending referral coins cannot be spent, converted, or withdrawn until your friend’s qualifying settlements are confirmed.</p>
            </aside>
        </div>
        <div class="m-stats-row">
            <div class="m-stat-box"><b><?php echo bc_landing_e(number_format($coins)); ?> coins</b><span>reward per qualified friend</span></div>
            <div class="m-stat-box"><b>$<?php echo bc_landing_e($qual); ?></b><span>minimum verified transaction volume</span></div>
            <div class="m-stat-box"><b>0 spendable</b><span>until your friend qualifies</span></div>
            <div class="m-stat-box"><b>One reward</b><span>per genuine referred friend</span></div>
        </div>
    </section>

    <section class="chapter tight" id="how">
        <div class="chap-head">
            <p class="eyebrow"><i></i>Three Simple Steps</p>
            <h2 style="margin-top:10px">From invite to unlocked coins.</h2>
        </div>
        <div class="m-cards3">
            <article class="m-card"><div class="m-step-num">Step 01</div><h3>Invite a friend</h3><p>Share your personal link. Your friend must sign up using your referral code.</p></article>
            <article class="m-card"><div class="m-step-num">Step 02</div><h3>Watch their growth</h3><p>Track each friend's verified USD volume, percentage completed, and amount remaining toward $<?php echo bc_landing_e($qual); ?>. Pending requests and quotes do not count.</p></article>
            <article class="m-card"><div class="m-step-num">Step 03</div><h3>Unlock your coins</h3><p>After confirmed qualifying settlements and identity checks, your coins unlock for conversion. No separate cash reward is paid to either wallet.</p></article>
        </div>
    </section>

    <section class="chapter tight">
        <div class="chap-head">
            <p class="eyebrow"><i></i>Transparency</p>
            <h2 style="margin-top:10px">Understand your reward.</h2>
        </div>
        <div class="m-feat-grid">
            <article class="m-card"><h3>Only settled activity</h3><p>Completed Crypto trades and International Transfers count after provider confirmation. Deposits, funding, internal transfers, fees, failed transactions, requests, and quotes do not count.</p></article>
            <article class="m-card"><h3>Locked means locked</h3><p>Pending referral coins cannot be spent, converted, or withdrawn. A reversal that brings qualifying volume below $<?php echo bc_landing_e($qual); ?> revokes the reward.</p></article>
            <article class="m-card"><h3>Coins to wallet funds</h3><p>The current conversion rate is <?php echo bc_landing_e(number_format($ctx['coin_rate'], 2)); ?> coins per ₦1. Conversion requests require at least <?php echo bc_landing_e(number_format($ctx['coin_min'])); ?> unlocked coins and admin approval.</p></article>
            <article class="m-card"><h3>Genuine referrals only</h3><p>Self-referrals do not qualify. Active accounts, distinct contact details, verified emails, and settlement review are required.</p></article>
        </div>
    </section>

    <div class="m-cta-band">
        <h2>Your next milestone starts with a friend.</h2>
        <p>Sign in to copy your invite link, track referral progress, and convert unlocked coins.</p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
            <a class="btn primary" href="/web/Login.php">Open referral dashboard</a>
            <a class="btn ghost" href="/services/CoinConversion.php">Coin conversion rules</a>
        </div>
    </div>
</div>
<?php bc_landing_foot($ctx);
    }
}

if (!function_exists('bc_landing_service_control_key')) {
    function bc_landing_service_control_key($slug) {
        $map = array(
            'airtime' => 'airtime',
            'data' => 'data',
            'cable-tv' => 'cable',
            'electricity' => 'electric',
            'exam-pins' => 'exam',
            'betting' => 'betting',
            'gift-cards' => 'gift_card',
            'crypto' => 'crypto_hub',
            'international-transfer' => 'intl_transfer',
            'virtual-cards' => 'virtual_card',
            'esim' => 'esim',
            'bulk-sms' => 'bulk_sms',
            'bank-transfer' => 'bank_transfer',
            'share-fund' => 'share_fund',
            'nin-card' => 'nin_card',
            'bvn-verification' => 'bvn_verify',
        );
        return $map[$slug] ?? $slug;
    }
}

if (!function_exists('bc_landing_render_services_index')) {
    function bc_landing_render_services_index($ctx) {
        require_once __DIR__ . '/bc-public-meridian.php';
        $vid = (int)($ctx['vendor_id'] ?? 0);
        $live = array();
        $coming_soon = array();
        foreach (bc_landing_services() as $slug => $s) {
            $ctrl = bc_landing_service_control_key($slug);
            if (bc_meridian_service_enabled($ctrl, $vid)) {
                $live[$slug] = $s;
            } else {
                $coming_soon[$slug] = array(
                    'service' => $s,
                    'reason' => 'Coming Soon',
                );
            }
        }
        bc_landing_head($ctx, 'Services', 'Every service on ' . $ctx['site_name'] . ' — bills, data, transfers, cards and more, explained.', 'personal');
        bc_landing_nav($ctx, 'services', 'personal');
        ?>
<div class="wrap">
    <section class="m-sub-hero">
        <div class="m-page-split">
            <div>
                <p class="eyebrow"><i></i>Services Directory</p>
                <h1>Everything your wallet can do.</h1>
                <p class="lede">One account for everyday bills, transfers, virtual cards, travel eSIMs, and identity services. Pick any active service below to see how it works, or check Coming Soon for services awaiting activation.</p>
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:24px">
                    <a class="btn primary" href="/web/Register.php">Create free account</a>
                    <a class="btn ghost" href="/Business.php">Explore Business tools</a>
                </div>
            </div>
            <aside class="m-showcase-card">
                <p class="eyebrow"><i></i>Live Platform Status</p>
                <div class="m-kv-row"><span>Active services</span><strong><?php echo count($live); ?> ready</strong></div>
                <div class="m-kv-row"><span>Coming Soon / Paused</span><strong><?php echo count($coming_soon); ?> tracked</strong></div>
                <div class="m-kv-row"><span>Account tiers</span><strong>Personal &amp; Business</strong></div>
                <p class="m-notice" style="margin:4px 0 0">Service availability updates automatically from the Site Owner Service Control Center.</p>
            </aside>
        </div>
    </section>

    <?php if (!empty($live)): ?>
    <section class="chapter tight">
        <div class="chap-head">
            <p class="eyebrow"><i></i>Available Now</p>
            <h2 style="margin-top:10px">Active services on <?php echo bc_landing_e($ctx['site_name']); ?>.</h2>
        </div>
        <div class="m-svc-grid">
            <?php foreach ($live as $slug => $s): ?>
            <a class="m-svc-card" href="/services/<?php echo bc_landing_e($slug); ?>.php">
                <div>
                    <div class="ic"><?php echo $s['icon']; ?></div>
                    <h3 style="margin-top:12px"><?php echo bc_landing_e($s['name']); ?></h3>
                    <p style="margin-top:6px"><?php echo bc_landing_e($s['tagline']); ?></p>
                </div>
                <div class="go">Explore →</div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($coming_soon)): ?>
    <div class="m-cs-band" id="coming-soon">
        <p class="eyebrow"><i></i>Coming Soon &amp; Service Rollout</p>
        <h3 style="margin-top:8px">Services currently paused in Service Control</h3>
        <p class="small" style="margin-top:4px">Services turned off in the Site Owner Service Control Center appear here until activated.</p>
        <div class="m-cs-grid">
            <?php foreach ($coming_soon as $slug => $cs): $s = $cs['service']; ?>
            <a class="m-cs-item" href="/services/<?php echo bc_landing_e($slug); ?>.php">
                <span class="m-cs-pill"><?php echo bc_landing_e($cs['reason']); ?></span>
                <h4><?php echo bc_landing_e($s['name']); ?></h4>
                <p><?php echo bc_landing_e($s['tagline']); ?></p>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="m-cta-band">
        <h2>One wallet. Every service.</h2>
        <p>Fund once and spend across active bills, transfers, and cards — earning coins as you go.</p>
        <a class="btn primary" href="/web/Register.php">Create free account</a>
    </div>
</div>
<?php
        bc_landing_foot($ctx);
    }
}

if (!function_exists('bc_landing_render_service')) {
    function bc_landing_render_service($ctx, $slug) {
        require_once __DIR__ . '/bc-public-meridian.php';
        $all = bc_landing_services();
        if (!isset($all[$slug])) { $slug = 'airtime'; }
        $s = $all[$slug];
        $vid = (int)($ctx['vendor_id'] ?? 0);
        $ctrl = bc_landing_service_control_key($slug);
        $is_live = bc_meridian_service_enabled($ctrl, $vid);
        $world = ($slug === 'bulk-sms') ? 'business' : 'personal';
        $status_label = 'Coming Soon — Currently paused by platform operator';
        bc_landing_head($ctx, $s['name'], $s['tagline'] . ' — on ' . $ctx['site_name'] . '.', $world);
        bc_landing_nav($ctx, 'services', $world);
        ?>
<div class="wrap">
    <section class="m-sub-hero">
        <div class="m-page-split">
            <div>
                <p class="eyebrow"><i></i><?php echo $s['icon']; ?> <?php echo bc_landing_e($s['name']); ?></p>
                <?php if (!$is_live): ?>
                <div style="margin-top:14px"><span class="m-cs-pill"><?php echo bc_landing_e($status_label); ?></span></div>
                <?php endif; ?>
                <h1><?php echo bc_landing_e($s['tagline']); ?></h1>
                <p class="lede"><?php echo bc_landing_e($s['hero']); ?></p>
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:24px">
                    <?php if ($is_live): ?>
                    <a class="btn primary" href="<?php echo bc_landing_e($s['app']); ?>">Use <?php echo bc_landing_e($s['name']); ?></a>
                    <?php else: ?>
                    <a class="btn primary" href="/Services.php">Browse active services</a>
                    <?php endif; ?>
                    <a class="btn ghost" href="/Services.php">All services</a>
                </div>
            </div>
            <aside class="m-showcase-card">
                <p class="eyebrow"><i></i>Service Snapshot</p>
                <div class="m-kv-row"><span>Service</span><strong><?php echo bc_landing_e($s['name']); ?></strong></div>
                <div class="m-kv-row"><span>Status</span><strong><?php echo $is_live ? 'Active' : 'Coming Soon'; ?></strong></div>
                <div class="m-kv-row"><span>Mode</span><strong><?php echo $world === 'business' ? 'Business &amp; Merchant' : 'Personal Wallet'; ?></strong></div>
                <p class="m-notice" style="margin:4px 0 0"><strong>Ideal for:</strong> <?php echo bc_landing_e($s['audience']); ?></p>
            </aside>
        </div>
    </section>

    <section class="chapter tight">
        <div class="chap-head">
            <p class="eyebrow"><i></i>Key Capabilities</p>
            <h2 style="margin-top:10px">Why use <?php echo bc_landing_e($s['name']); ?> on <?php echo bc_landing_e($ctx['site_name']); ?>.</h2>
        </div>
        <div class="m-feat-grid">
            <?php foreach ($s['features'] as $f): ?>
            <article class="m-card"><h3><?php echo bc_landing_e($f[0]); ?></h3><p><?php echo bc_landing_e($f[1]); ?></p></article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="chapter tight">
        <div class="chap-head">
            <p class="eyebrow"><i></i>How It Works</p>
            <h2 style="margin-top:10px">Three steps from start to finish.</h2>
        </div>
        <div class="m-cards3">
            <?php foreach ($s['steps'] as $i => $step): ?>
            <article class="m-card"><div class="m-step-num">Step 0<?php echo $i + 1; ?></div><h3><?php echo $i === 0 ? 'Start' : ($i === 1 ? 'Confirm' : 'Done'); ?></h3><p><?php echo bc_landing_e($step); ?></p></article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="chapter tight m-faq">
        <div class="chap-head">
            <p class="eyebrow"><i></i>Questions</p>
            <h2 style="margin-top:10px">Frequently asked about <?php echo bc_landing_e($s['name']); ?>.</h2>
        </div>
        <?php foreach ($s['faqs'] as $q): ?>
        <details><summary><?php echo bc_landing_e($q[0]); ?></summary><p><?php echo bc_landing_e($q[1]); ?></p></details>
        <?php endforeach; ?>
    </section>

    <section class="chapter tight">
        <div class="chap-head">
            <p class="eyebrow"><i></i>Keep Exploring</p>
            <h2 style="margin-top:10px">More services on <?php echo bc_landing_e($ctx['site_name']); ?>.</h2>
        </div>
        <div class="m-svc-grid">
            <?php $shown = 0; foreach ($all as $oslug => $os): if ($oslug === $slug || $shown >= 3) continue; $shown++; ?>
            <a class="m-svc-card" href="/services/<?php echo bc_landing_e($oslug); ?>.php">
                <div>
                    <div class="ic"><?php echo $os['icon']; ?></div>
                    <h3 style="margin-top:12px"><?php echo bc_landing_e($os['name']); ?></h3>
                    <p style="margin-top:6px"><?php echo bc_landing_e($os['tagline']); ?></p>
                </div>
                <div class="go">Explore →</div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="m-cta-band">
        <h2><?php echo $is_live ? 'Ready to use ' . bc_landing_e($s['name']) . '?' : 'Explore active services on ' . bc_landing_e($ctx['site_name']); ?></h2>
        <p>Free account, instant wallet, and full transaction receipts in one place.</p>
        <a class="btn primary" href="<?php echo $is_live ? '/web/Register.php' : '/Services.php'; ?>"><?php echo $is_live ? 'Create free account' : 'Browse active services'; ?></a>
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
        bc_landing_head($ctx, $title, $title . ' for ' . $ctx['site_name'] . ' — plain-language rules for using our services.', 'personal');
        bc_landing_nav($ctx, '', 'personal');
        ?>
<div class="wrap">
    <section class="m-sub-hero">
        <div class="m-page-split">
            <div>
                <p class="eyebrow"><i></i><?php echo $is_terms ? 'Terms' : 'Privacy'; ?></p>
                <h1><?php echo bc_landing_e($title); ?></h1>
                <p class="lede">Last updated <?php echo date('F d, Y'); ?>. Written in plain language — the short version is: we collect what we need to run your account, we never sell it, and you can ask us to delete it.</p>
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:24px">
                    <a class="btn primary" href="<?php echo $is_terms ? '/Privacy.php' : '/Terms.php'; ?>">Read the <?php echo $is_terms ? 'Privacy Policy' : 'Terms of Service'; ?></a>
                    <a class="btn ghost" href="/web/DeleteAccount.php">Delete my account</a>
                </div>
            </div>
            <aside class="m-showcase-card">
                <p class="eyebrow"><i></i>Key Commitments</p>
                <div class="m-kv-row"><span>Data ownership</span><strong>We never sell your data</strong></div>
                <div class="m-kv-row"><span>Step-up controls</span><strong>PIN, 2FA &amp; session logs</strong></div>
                <div class="m-kv-row"><span>Account rights</span><strong>Deletion via email check</strong></div>
            </aside>
        </div>
    </section>
    <section class="chapter tight m-faq">
        <?php foreach (bc_landing_legal_sections($kind) as $sec): ?>
        <details open>
            <summary><?php echo bc_landing_e(str_replace('{site}', $ctx['site_name'], $sec['title'])); ?></summary>
            <?php foreach ($sec['body'] as $para): ?><p><?php echo bc_landing_e(str_replace('{site}', $ctx['site_name'], $para)); ?></p><?php endforeach; ?>
        </details>
        <?php endforeach; ?>
    </section>
    <div class="m-cta-band">
        <h2>Questions about your data?</h2>
        <p>Message us any time through support, or manage your account below.</p>
        <a class="btn primary" href="/web/DeleteAccount.php">Request account deletion</a>
    </div>
</div>
<?php
        bc_landing_foot($ctx);
    }
}
