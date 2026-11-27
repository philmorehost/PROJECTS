<?php
/** Closed route catalogue shared by native service entry points. Never accept URLs. */
function bc_mobile_catalogue() {
    return array(
        'airtime'=>array('VTU','Airtime','Airtime.php','airtime'),
        'data'=>array('VTU','Data bundles','Data.php','data'),
        'cable'=>array('VTU','Cable TV','Cable.php','cable'),
        'electric'=>array('VTU','Electricity','Electric.php','electric'),
        'exam'=>array('VTU','Exam PINs','Exam.php','exam'),
        'betting'=>array('VTU','Betting','Betting.php','betting'),
        'sms'=>array('VTU','Bulk SMS','BulkSMS.php','bulk_sms'),
        'print'=>array('VTU','Print cards','PrintHub.php','data_card'),
        'nin'=>array('Identity','NIN card','NINCard.php','nin_card'),
        'bvn'=>array('Identity','BVN verification','BVNVerification.php','bvn_verify'),
        'crypto'=>array('Cards & Crypto','Crypto','CryptoHub.php','crypto_hub'),
        'cards'=>array('Cards & Crypto','Virtual card','VirtualCard.php','virtual_card'),
        'giftcards'=>array('Cards & Crypto','Gift cards','GiftCard.php','gift_card'),
        'local'=>array('Send','Send locally','SendFund.php','bank_transfer'),
        'international'=>array('Send','Send Money Abroad','IntlTransfer.php','intl_transfer'),
        'share'=>array('Send','Send to a user','ShareFund.php',''),
        'invoice'=>array('Wallet','Receive by invoice','Receive.php','flutterwave'),
        'fx'=>array('Wallet','Live currency converter','LiveRates.php',''),
        'fund'=>array('Wallet','Add funds','Fund.php',''),
        'accounts'=>array('Wallet','Virtual accounts','VirtualBanks.php',''),
        'esim'=>array('Global','eSIM','ESIM.php','esim'),
        'tuition'=>array('Global','Pay Tuition Worldwide','Tuition.php','tuition'),
        'pos'=>array('Global','Request POS Device','POSRequest.php','pos_request'),
        'kyc'=>array('Account','Identity verification','KYCVerification.php',''),
        'referral'=>array('Rewards','Referral growth','Referral.php','referral'),
        'coins'=>array('Rewards','Convert coins','CoinConversion.php','vtu_coins'),
        'points'=>array('Rewards','Points history','PointsHistory.php','vtu_coins'),
        'pin'=>array('Account','Security PIN','SecurityPIN.php',''),
        'settings'=>array('Account','Account settings','AccountSettings.php',''),
        'history'=>array('Account','Transaction history','Transactions.php',''),
        'assistant'=>array('Support','Support','Hub.php?g=settings-help','')
    );
}
function bc_mobile_destination($slug, $vid) {
    $row = bc_mobile_catalogue()[$slug] ?? null;
    if (!$row) return null;
    $target_file = explode('?', (string)$row[2], 2)[0];
    if ($target_file === '' || !is_file(__DIR__ . '/../web/' . $target_file)) return null;
    if ($row[3] !== '' && !isServiceEnabled($row[3], (int)$vid)) return null;
    return '/web/' . $row[2];
}
