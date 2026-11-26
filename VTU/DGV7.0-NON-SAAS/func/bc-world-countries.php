<?php
/**
 * Worldwide country catalog and international E.164/local phone validation.
 * Covers all ISO 3166-1 countries/territories so registration is available globally
 * while remaining backward-compatible with local Nigerian 11-digit phone inputs.
 */

if (!function_exists('bc_world_countries')) {
    function bc_world_countries() {
        static $countries = null;
        if ($countries !== null) return $countries;
        $countries = [
            'AF' => ['name' => 'Afghanistan', 'dial' => '+93'],
            'AX' => ['name' => 'Aland Islands', 'dial' => '+358'],
            'AL' => ['name' => 'Albania', 'dial' => '+355'],
            'DZ' => ['name' => 'Algeria', 'dial' => '+213'],
            'AS' => ['name' => 'American Samoa', 'dial' => '+1684'],
            'AD' => ['name' => 'Andorra', 'dial' => '+376'],
            'AO' => ['name' => 'Angola', 'dial' => '+244'],
            'AI' => ['name' => 'Anguilla', 'dial' => '+1264'],
            'AQ' => ['name' => 'Antarctica', 'dial' => '+672'],
            'AG' => ['name' => 'Antigua and Barbuda', 'dial' => '+1268'],
            'AR' => ['name' => 'Argentina', 'dial' => '+54'],
            'AM' => ['name' => 'Armenia', 'dial' => '+374'],
            'AW' => ['name' => 'Aruba', 'dial' => '+297'],
            'AU' => ['name' => 'Australia', 'dial' => '+61'],
            'AT' => ['name' => 'Austria', 'dial' => '+43'],
            'AZ' => ['name' => 'Azerbaijan', 'dial' => '+994'],
            'BS' => ['name' => 'Bahamas', 'dial' => '+1242'],
            'BH' => ['name' => 'Bahrain', 'dial' => '+973'],
            'BD' => ['name' => 'Bangladesh', 'dial' => '+880'],
            'BB' => ['name' => 'Barbados', 'dial' => '+1246'],
            'BY' => ['name' => 'Belarus', 'dial' => '+375'],
            'BE' => ['name' => 'Belgium', 'dial' => '+32'],
            'BZ' => ['name' => 'Belize', 'dial' => '+501'],
            'BJ' => ['name' => 'Benin', 'dial' => '+229'],
            'BM' => ['name' => 'Bermuda', 'dial' => '+1441'],
            'BT' => ['name' => 'Bhutan', 'dial' => '+975'],
            'BO' => ['name' => 'Bolivia', 'dial' => '+591'],
            'BQ' => ['name' => 'Bonaire, Sint Eustatius and Saba', 'dial' => '+599'],
            'BA' => ['name' => 'Bosnia and Herzegovina', 'dial' => '+387'],
            'BW' => ['name' => 'Botswana', 'dial' => '+267'],
            'BR' => ['name' => 'Brazil', 'dial' => '+55'],
            'IO' => ['name' => 'British Indian Ocean Territory', 'dial' => '+246'],
            'VG' => ['name' => 'British Virgin Islands', 'dial' => '+1284'],
            'BN' => ['name' => 'Brunei Darussalam', 'dial' => '+673'],
            'BG' => ['name' => 'Bulgaria', 'dial' => '+359'],
            'BF' => ['name' => 'Burkina Faso', 'dial' => '+226'],
            'BI' => ['name' => 'Burundi', 'dial' => '+257'],
            'CV' => ['name' => 'Cabo Verde', 'dial' => '+238'],
            'KH' => ['name' => 'Cambodia', 'dial' => '+855'],
            'CM' => ['name' => 'Cameroon', 'dial' => '+237'],
            'CA' => ['name' => 'Canada', 'dial' => '+1'],
            'KY' => ['name' => 'Cayman Islands', 'dial' => '+1345'],
            'CF' => ['name' => 'Central African Republic', 'dial' => '+236'],
            'TD' => ['name' => 'Chad', 'dial' => '+235'],
            'CL' => ['name' => 'Chile', 'dial' => '+56'],
            'CN' => ['name' => 'China', 'dial' => '+86'],
            'CX' => ['name' => 'Christmas Island', 'dial' => '+61'],
            'CC' => ['name' => 'Cocos (Keeling) Islands', 'dial' => '+61'],
            'CO' => ['name' => 'Colombia', 'dial' => '+57'],
            'KM' => ['name' => 'Comoros', 'dial' => '+269'],
            'CG' => ['name' => 'Congo', 'dial' => '+242'],
            'CD' => ['name' => 'Congo, Democratic Republic of the', 'dial' => '+243'],
            'CK' => ['name' => 'Cook Islands', 'dial' => '+682'],
            'CR' => ['name' => 'Costa Rica', 'dial' => '+506'],
            'CI' => ['name' => "Cote d'Ivoire", 'dial' => '+225'],
            'HR' => ['name' => 'Croatia', 'dial' => '+385'],
            'CU' => ['name' => 'Cuba', 'dial' => '+53'],
            'CW' => ['name' => 'Curacao', 'dial' => '+599'],
            'CY' => ['name' => 'Cyprus', 'dial' => '+357'],
            'CZ' => ['name' => 'Czech Republic', 'dial' => '+420'],
            'DK' => ['name' => 'Denmark', 'dial' => '+45'],
            'DJ' => ['name' => 'Djibouti', 'dial' => '+253'],
            'DM' => ['name' => 'Dominica', 'dial' => '+1767'],
            'DO' => ['name' => 'Dominican Republic', 'dial' => '+1809'],
            'EC' => ['name' => 'Ecuador', 'dial' => '+593'],
            'EG' => ['name' => 'Egypt', 'dial' => '+20'],
            'SV' => ['name' => 'El Salvador', 'dial' => '+503'],
            'GQ' => ['name' => 'Equatorial Guinea', 'dial' => '+240'],
            'ER' => ['name' => 'Eritrea', 'dial' => '+291'],
            'EE' => ['name' => 'Estonia', 'dial' => '+372'],
            'SZ' => ['name' => 'Eswatini', 'dial' => '+268'],
            'ET' => ['name' => 'Ethiopia', 'dial' => '+251'],
            'FK' => ['name' => 'Falkland Islands', 'dial' => '+500'],
            'FO' => ['name' => 'Faroe Islands', 'dial' => '+298'],
            'FJ' => ['name' => 'Fiji', 'dial' => '+679'],
            'FI' => ['name' => 'Finland', 'dial' => '+358'],
            'FR' => ['name' => 'France', 'dial' => '+33'],
            'GF' => ['name' => 'French Guiana', 'dial' => '+594'],
            'PF' => ['name' => 'French Polynesia', 'dial' => '+689'],
            'GA' => ['name' => 'Gabon', 'dial' => '+241'],
            'GM' => ['name' => 'Gambia', 'dial' => '+220'],
            'GE' => ['name' => 'Georgia', 'dial' => '+995'],
            'DE' => ['name' => 'Germany', 'dial' => '+49'],
            'GH' => ['name' => 'Ghana', 'dial' => '+233'],
            'GI' => ['name' => 'Gibraltar', 'dial' => '+350'],
            'GR' => ['name' => 'Greece', 'dial' => '+30'],
            'GL' => ['name' => 'Greenland', 'dial' => '+299'],
            'GD' => ['name' => 'Grenada', 'dial' => '+1473'],
            'GP' => ['name' => 'Guadeloupe', 'dial' => '+590'],
            'GU' => ['name' => 'Guam', 'dial' => '+1671'],
            'GT' => ['name' => 'Guatemala', 'dial' => '+502'],
            'GG' => ['name' => 'Guernsey', 'dial' => '+44'],
            'GN' => ['name' => 'Guinea', 'dial' => '+224'],
            'GW' => ['name' => 'Guinea-Bissau', 'dial' => '+245'],
            'GY' => ['name' => 'Guyana', 'dial' => '+592'],
            'HT' => ['name' => 'Haiti', 'dial' => '+509'],
            'VA' => ['name' => 'Holy See (Vatican City)', 'dial' => '+379'],
            'HN' => ['name' => 'Honduras', 'dial' => '+504'],
            'HK' => ['name' => 'Hong Kong', 'dial' => '+852'],
            'HU' => ['name' => 'Hungary', 'dial' => '+36'],
            'IS' => ['name' => 'Iceland', 'dial' => '+354'],
            'IN' => ['name' => 'India', 'dial' => '+91'],
            'ID' => ['name' => 'Indonesia', 'dial' => '+62'],
            'IR' => ['name' => 'Iran', 'dial' => '+98'],
            'IQ' => ['name' => 'Iraq', 'dial' => '+964'],
            'IE' => ['name' => 'Ireland', 'dial' => '+353'],
            'IM' => ['name' => 'Isle of Man', 'dial' => '+44'],
            'IL' => ['name' => 'Israel', 'dial' => '+972'],
            'IT' => ['name' => 'Italy', 'dial' => '+39'],
            'JM' => ['name' => 'Jamaica', 'dial' => '+1876'],
            'JP' => ['name' => 'Japan', 'dial' => '+81'],
            'JE' => ['name' => 'Jersey', 'dial' => '+44'],
            'JO' => ['name' => 'Jordan', 'dial' => '+962'],
            'KZ' => ['name' => 'Kazakhstan', 'dial' => '+7'],
            'KE' => ['name' => 'Kenya', 'dial' => '+254'],
            'KI' => ['name' => 'Kiribati', 'dial' => '+686'],
            'KP' => ['name' => 'Korea, North', 'dial' => '+850'],
            'KR' => ['name' => 'Korea, South', 'dial' => '+82'],
            'XK' => ['name' => 'Kosovo', 'dial' => '+383'],
            'KW' => ['name' => 'Kuwait', 'dial' => '+965'],
            'KG' => ['name' => 'Kyrgyzstan', 'dial' => '+996'],
            'LA' => ['name' => 'Laos', 'dial' => '+856'],
            'LV' => ['name' => 'Latvia', 'dial' => '+371'],
            'LB' => ['name' => 'Lebanon', 'dial' => '+961'],
            'LS' => ['name' => 'Lesotho', 'dial' => '+266'],
            'LR' => ['name' => 'Liberia', 'dial' => '+231'],
            'LY' => ['name' => 'Libya', 'dial' => '+218'],
            'LI' => ['name' => 'Liechtenstein', 'dial' => '+423'],
            'LT' => ['name' => 'Lithuania', 'dial' => '+370'],
            'LU' => ['name' => 'Luxembourg', 'dial' => '+352'],
            'MO' => ['name' => 'Macao', 'dial' => '+853'],
            'MG' => ['name' => 'Madagascar', 'dial' => '+261'],
            'MW' => ['name' => 'Malawi', 'dial' => '+265'],
            'MY' => ['name' => 'Malaysia', 'dial' => '+60'],
            'MV' => ['name' => 'Maldives', 'dial' => '+960'],
            'ML' => ['name' => 'Mali', 'dial' => '+223'],
            'MT' => ['name' => 'Malta', 'dial' => '+356'],
            'MH' => ['name' => 'Marshall Islands', 'dial' => '+692'],
            'MQ' => ['name' => 'Martinique', 'dial' => '+596'],
            'MR' => ['name' => 'Mauritania', 'dial' => '+222'],
            'MU' => ['name' => 'Mauritius', 'dial' => '+230'],
            'YT' => ['name' => 'Mayotte', 'dial' => '+262'],
            'MX' => ['name' => 'Mexico', 'dial' => '+52'],
            'FM' => ['name' => 'Micronesia', 'dial' => '+691'],
            'MD' => ['name' => 'Moldova', 'dial' => '+373'],
            'MC' => ['name' => 'Monaco', 'dial' => '+377'],
            'MN' => ['name' => 'Mongolia', 'dial' => '+976'],
            'ME' => ['name' => 'Montenegro', 'dial' => '+382'],
            'MS' => ['name' => 'Montserrat', 'dial' => '+1664'],
            'MA' => ['name' => 'Morocco', 'dial' => '+212'],
            'MZ' => ['name' => 'Mozambique', 'dial' => '+258'],
            'MM' => ['name' => 'Myanmar', 'dial' => '+95'],
            'NA' => ['name' => 'Namibia', 'dial' => '+264'],
            'NR' => ['name' => 'Nauru', 'dial' => '+674'],
            'NP' => ['name' => 'Nepal', 'dial' => '+977'],
            'NL' => ['name' => 'Netherlands', 'dial' => '+31'],
            'NC' => ['name' => 'New Caledonia', 'dial' => '+687'],
            'NZ' => ['name' => 'New Zealand', 'dial' => '+64'],
            'NI' => ['name' => 'Nicaragua', 'dial' => '+505'],
            'NE' => ['name' => 'Niger', 'dial' => '+227'],
            'NG' => ['name' => 'Nigeria', 'dial' => '+234'],
            'NU' => ['name' => 'Niue', 'dial' => '+683'],
            'NF' => ['name' => 'Norfolk Island', 'dial' => '+672'],
            'MK' => ['name' => 'North Macedonia', 'dial' => '+389'],
            'MP' => ['name' => 'Northern Mariana Islands', 'dial' => '+1670'],
            'NO' => ['name' => 'Norway', 'dial' => '+47'],
            'OM' => ['name' => 'Oman', 'dial' => '+968'],
            'PK' => ['name' => 'Pakistan', 'dial' => '+92'],
            'PW' => ['name' => 'Palau', 'dial' => '+680'],
            'PS' => ['name' => 'Palestine', 'dial' => '+970'],
            'PA' => ['name' => 'Panama', 'dial' => '+507'],
            'PG' => ['name' => 'Papua New Guinea', 'dial' => '+675'],
            'PY' => ['name' => 'Paraguay', 'dial' => '+595'],
            'PE' => ['name' => 'Peru', 'dial' => '+51'],
            'PH' => ['name' => 'Philippines', 'dial' => '+63'],
            'PL' => ['name' => 'Poland', 'dial' => '+48'],
            'PT' => ['name' => 'Portugal', 'dial' => '+351'],
            'PR' => ['name' => 'Puerto Rico', 'dial' => '+1787'],
            'QA' => ['name' => 'Qatar', 'dial' => '+974'],
            'RE' => ['name' => 'Reunion', 'dial' => '+262'],
            'RO' => ['name' => 'Romania', 'dial' => '+40'],
            'RU' => ['name' => 'Russia', 'dial' => '+7'],
            'RW' => ['name' => 'Rwanda', 'dial' => '+250'],
            'BL' => ['name' => 'Saint Barthelemy', 'dial' => '+590'],
            'SH' => ['name' => 'Saint Helena', 'dial' => '+290'],
            'KN' => ['name' => 'Saint Kitts and Nevis', 'dial' => '+1869'],
            'LC' => ['name' => 'Saint Lucia', 'dial' => '+1758'],
            'MF' => ['name' => 'Saint Martin', 'dial' => '+590'],
            'PM' => ['name' => 'Saint Pierre and Miquelon', 'dial' => '+508'],
            'VC' => ['name' => 'Saint Vincent and the Grenadines', 'dial' => '+1784'],
            'WS' => ['name' => 'Samoa', 'dial' => '+685'],
            'SM' => ['name' => 'San Marino', 'dial' => '+378'],
            'ST' => ['name' => 'Sao Tome and Principe', 'dial' => '+239'],
            'SA' => ['name' => 'Saudi Arabia', 'dial' => '+966'],
            'SN' => ['name' => 'Senegal', 'dial' => '+221'],
            'RS' => ['name' => 'Serbia', 'dial' => '+381'],
            'SC' => ['name' => 'Seychelles', 'dial' => '+248'],
            'SL' => ['name' => 'Sierra Leone', 'dial' => '+232'],
            'SG' => ['name' => 'Singapore', 'dial' => '+65'],
            'SX' => ['name' => 'Sint Maarten', 'dial' => '+1721'],
            'SK' => ['name' => 'Slovakia', 'dial' => '+421'],
            'SI' => ['name' => 'Slovenia', 'dial' => '+386'],
            'SB' => ['name' => 'Solomon Islands', 'dial' => '+677'],
            'SO' => ['name' => 'Somalia', 'dial' => '+252'],
            'ZA' => ['name' => 'South Africa', 'dial' => '+27'],
            'SS' => ['name' => 'South Sudan', 'dial' => '+211'],
            'ES' => ['name' => 'Spain', 'dial' => '+34'],
            'LK' => ['name' => 'Sri Lanka', 'dial' => '+94'],
            'SD' => ['name' => 'Sudan', 'dial' => '+249'],
            'SR' => ['name' => 'Suriname', 'dial' => '+597'],
            'SE' => ['name' => 'Sweden', 'dial' => '+46'],
            'CH' => ['name' => 'Switzerland', 'dial' => '+41'],
            'SY' => ['name' => 'Syria', 'dial' => '+963'],
            'TW' => ['name' => 'Taiwan', 'dial' => '+886'],
            'TJ' => ['name' => 'Tajikistan', 'dial' => '+992'],
            'TZ' => ['name' => 'Tanzania', 'dial' => '+255'],
            'TH' => ['name' => 'Thailand', 'dial' => '+66'],
            'TL' => ['name' => 'Timor-Leste', 'dial' => '+670'],
            'TG' => ['name' => 'Togo', 'dial' => '+228'],
            'TK' => ['name' => 'Tokelau', 'dial' => '+690'],
            'TO' => ['name' => 'Tonga', 'dial' => '+676'],
            'TT' => ['name' => 'Trinidad and Tobago', 'dial' => '+1868'],
            'TN' => ['name' => 'Tunisia', 'dial' => '+216'],
            'TR' => ['name' => 'Turkey', 'dial' => '+90'],
            'TM' => ['name' => 'Turkmenistan', 'dial' => '+993'],
            'TC' => ['name' => 'Turks and Caicos Islands', 'dial' => '+1649'],
            'TV' => ['name' => 'Tuvalu', 'dial' => '+688'],
            'UG' => ['name' => 'Uganda', 'dial' => '+256'],
            'UA' => ['name' => 'Ukraine', 'dial' => '+380'],
            'AE' => ['name' => 'United Arab Emirates', 'dial' => '+971'],
            'GB' => ['name' => 'United Kingdom', 'dial' => '+44'],
            'US' => ['name' => 'United States', 'dial' => '+1'],
            'UY' => ['name' => 'Uruguay', 'dial' => '+598'],
            'VI' => ['name' => 'U.S. Virgin Islands', 'dial' => '+1340'],
            'UZ' => ['name' => 'Uzbekistan', 'dial' => '+998'],
            'VU' => ['name' => 'Vanuatu', 'dial' => '+678'],
            'VE' => ['name' => 'Venezuela', 'dial' => '+58'],
            'VN' => ['name' => 'Vietnam', 'dial' => '+84'],
            'WF' => ['name' => 'Wallis and Futuna', 'dial' => '+681'],
            'EH' => ['name' => 'Western Sahara', 'dial' => '+212'],
            'YE' => ['name' => 'Yemen', 'dial' => '+967'],
            'ZM' => ['name' => 'Zambia', 'dial' => '+260'],
            'ZW' => ['name' => 'Zimbabwe', 'dial' => '+263'],
        ];
        return $countries;
    }
}

if (!function_exists('bc_world_country_lookup')) {
    function bc_world_country_lookup($country_input) {
        $raw = trim((string)$country_input);
        if ($raw === '') return null;
        $countries = bc_world_countries();
        $upper = strtoupper($raw);
        if (isset($countries[$upper])) {
            return ['code' => $upper, 'name' => $countries[$upper]['name'], 'dial' => $countries[$upper]['dial']];
        }
        $lower = strtolower($raw);
        foreach ($countries as $code => $info) {
            if (strtolower($info['name']) === $lower) {
                return ['code' => $code, 'name' => $info['name'], 'dial' => $info['dial']];
            }
        }
        return null;
    }
}

if (!function_exists('bc_valid_world_country')) {
    function bc_valid_world_country($country_input) {
        return bc_world_country_lookup($country_input) !== null;
    }
}

if (!function_exists('bc_normalize_world_phone')) {
    /**
     * Validate and normalize any worldwide phone number (ITU-T E.164 or local with country).
     * Preserves 11-digit Nigerian local format ('080...') when entered without '+',
     * and normalizes international numbers to canonical '+<country><number>' (7-15 digits).
     */
    function bc_normalize_world_phone($phone, $country_input = '') {
        if (!is_string($phone) && !is_int($phone)) return '';
        $raw = trim((string)$phone);
        if ($raw === '') return '';
        $clean = preg_replace('/[\s\-\(\)\.]+/', '', $raw);
        if ($clean === '' || !preg_match('/^\+?[0-9]+$/', $clean)) return '';
        if (strpos($clean, '00') === 0 && strlen($clean) > 4) {
            $clean = '+' . substr($clean, 2);
        }

        $digits_only = ltrim($clean, '+');
        if (strlen($digits_only) < 7 || strlen($digits_only) > 15) return '';
        if (preg_match('/^0+$/', $digits_only) || preg_match('/([0-9])\1{7,}/', $digits_only)) return '';

        if (strpos($clean, '+') === 0) {
            if (!preg_match('/^\+[1-9][0-9]{6,14}$/', $clean)) return '';
            return $clean;
        }

        $country = bc_world_country_lookup($country_input);
        if ($country !== null) {
            $dial_digits = ltrim($country['dial'], '+');
            if ($country['code'] === 'NG' && preg_match('/^0[0-9]{10}$/', $clean)) {
                return $clean;
            }
            if (strpos($clean, $dial_digits) === 0 && strlen($clean) >= max(7, strlen($dial_digits) + 5) && strlen($clean) <= 15) {
                if ($dial_digits !== '1' || strlen($clean) === 11) {
                    return '+' . $clean;
                }
            }
            $national = preg_replace('/^0/', '', $clean);
            $combined = $dial_digits . $national;
            if (preg_match('/^[1-9][0-9]{6,14}$/', $combined)) {
                return '+' . $combined;
            }
            return '';
        }

        // Legacy / countryless calls: accept 11-digit Nigerian local or 7-15 digit international.
        if (preg_match('/^0[0-9]{10}$/', $clean)) {
            return $clean;
        }
        if (preg_match('/^[1-9][0-9]{6,14}$/', $clean)) {
            return '+' . $clean;
        }
        return '';
    }
}

if (!function_exists('bc_ensure_user_country_column')) {
    function bc_ensure_user_country_column($connection_server, $table = 'sas_users') {
        static $cache = [];
        $key = $table;
        if (isset($cache[$key])) return $cache[$key];
        if (!$connection_server || !in_array($table, ['sas_users', 'sas_vendors', 'sas_pending_vendors'], true)) {
            return $cache[$key] = false;
        }
        try {
            $q = @mysqli_query($connection_server, "SHOW COLUMNS FROM `$table` LIKE 'country'");
            if ($q && mysqli_num_rows($q) > 0) {
                return $cache[$key] = true;
            }
            if (@mysqli_query($connection_server, "ALTER TABLE `$table` ADD COLUMN `country` VARCHAR(100) NULL DEFAULT NULL AFTER `home_address`")) {
                return $cache[$key] = true;
            }
        } catch (Throwable $e) {
            // Fall through safely if ALTER is restricted.
        }
        return $cache[$key] = false;
    }
}
