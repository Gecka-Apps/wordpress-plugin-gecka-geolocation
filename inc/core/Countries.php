<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation;

/**
 * Names of the countries from their code alone, as a plugin keeping the code
 * of a lookup shows them later.
 *
 * The list below holds the codes the flags cover, worded as intl words them
 * in English and translated with the plugin; a code it does not hold is no
 * country, though intl may name it, as ZZ (unknown region). The intl
 * extension of PHP names the codes of the list in any language. Without it,
 * or for a code it does not know, as PC (Pacific Community), the name comes
 * from the list.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Countries
{
    /**
     * Codes of the list: ISO 3166-1 alpha-2, plus EU, UN, XK and PC.
     */
    public const CODES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS',
        'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN',
        'CO', 'CP', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DG', 'DJ', 'DK', 'DM', 'DO', 'DZ',
        'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET', 'EU', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB',
        'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW',
        'GY', 'HK', 'HM', 'HN', 'HR', 'HT', 'HU', 'IC', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR',
        'IS', 'IT', 'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY',
        'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME',
        'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW',
        'MX', 'MY', 'MZ', 'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM',
        'PA', 'PC', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA',
        'RE', 'RO', 'RS', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL',
        'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ',
        'TK', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'UN', 'US', 'UY',
        'UZ', 'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'XK', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    /**
     * Names of the list translated, by locale.
     *
     * @var array<string, array<string, string>>
     */
    private static array $names = [];

    /**
     * Name of a country in a language
     *
     * @param string      $country ISO 3166-1 alpha-2 code, as NC.
     * @param string|null $locale  Locale of WordPress, as fr_FR; the one of the request by default.
     *
     * @return string|null The name, or the code itself when the list does not hold it; null for a code that is not two letters.
     */
    public static function name(string $country, ?string $locale = null): ?string
    {
        $country = strtoupper(trim($country));

        if (! preg_match('/^[A-Z]{2}$/', $country)) {
            return null;
        }

        if (! in_array($country, self::CODES, true)) {
            return $country;
        }

        $locale ??= function_exists('determine_locale') ? determine_locale() : get_locale();

        if (class_exists('Locale')) {
            $name = \Locale::getDisplayRegion('-' . $country, $locale);

            if (is_string($name) && $name !== '' && $name !== $country) {
                return $name;
            }
        }

        return self::fromList($country, $locale) ?? $country;
    }

    /**
     * Name of a country from the list of the plugin, translated in a
     * language when WordPress has it installed and can switch to it, which
     * it can from after_setup_theme on
     *
     * @param string      $country ISO 3166-1 alpha-2 code, uppercase.
     * @param string|null $locale  Locale of WordPress; the one of the request by default.
     *
     * @return string|null Null for a code the list does not hold.
     */
    public static function fromList(string $country, ?string $locale = null): ?string
    {
        if (! in_array($country, self::CODES, true)) {
            return null;
        }

        // Before init the translations are not loaded yet: nothing is kept.
        if (! did_action('init')) {
            return self::names()[$country];
        }

        $current = function_exists('determine_locale') ? determine_locale() : get_locale();
        $wanted = $locale ?? $current;

        if (isset(self::$names[$wanted])) {
            return self::$names[$wanted][$country];
        }

        $switched = $wanted !== $current && did_action('after_setup_theme') && switch_to_locale($wanted);

        try {
            return (self::$names[$switched ? $wanted : $current] ??= self::names())[$country];
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
        }
    }

    /**
     * The names of the list, by code
     *
     * @return array<string, string>
     */
    private static function names(): array
    {
        return [
            'AD' => _x('Andorra', 'country', 'gecka-geolocation'),
            'AE' => _x('United Arab Emirates', 'country', 'gecka-geolocation'),
            'AF' => _x('Afghanistan', 'country', 'gecka-geolocation'),
            'AG' => _x('Antigua & Barbuda', 'country', 'gecka-geolocation'),
            'AI' => _x('Anguilla', 'country', 'gecka-geolocation'),
            'AL' => _x('Albania', 'country', 'gecka-geolocation'),
            'AM' => _x('Armenia', 'country', 'gecka-geolocation'),
            'AO' => _x('Angola', 'country', 'gecka-geolocation'),
            'AQ' => _x('Antarctica', 'country', 'gecka-geolocation'),
            'AR' => _x('Argentina', 'country', 'gecka-geolocation'),
            'AS' => _x('American Samoa', 'country', 'gecka-geolocation'),
            'AT' => _x('Austria', 'country', 'gecka-geolocation'),
            'AU' => _x('Australia', 'country', 'gecka-geolocation'),
            'AW' => _x('Aruba', 'country', 'gecka-geolocation'),
            'AX' => _x('Åland Islands', 'country', 'gecka-geolocation'),
            'AZ' => _x('Azerbaijan', 'country', 'gecka-geolocation'),
            'BA' => _x('Bosnia & Herzegovina', 'country', 'gecka-geolocation'),
            'BB' => _x('Barbados', 'country', 'gecka-geolocation'),
            'BD' => _x('Bangladesh', 'country', 'gecka-geolocation'),
            'BE' => _x('Belgium', 'country', 'gecka-geolocation'),
            'BF' => _x('Burkina Faso', 'country', 'gecka-geolocation'),
            'BG' => _x('Bulgaria', 'country', 'gecka-geolocation'),
            'BH' => _x('Bahrain', 'country', 'gecka-geolocation'),
            'BI' => _x('Burundi', 'country', 'gecka-geolocation'),
            'BJ' => _x('Benin', 'country', 'gecka-geolocation'),
            'BL' => _x('St. Barthélemy', 'country', 'gecka-geolocation'),
            'BM' => _x('Bermuda', 'country', 'gecka-geolocation'),
            'BN' => _x('Brunei', 'country', 'gecka-geolocation'),
            'BO' => _x('Bolivia', 'country', 'gecka-geolocation'),
            'BQ' => _x('Caribbean Netherlands', 'country', 'gecka-geolocation'),
            'BR' => _x('Brazil', 'country', 'gecka-geolocation'),
            'BS' => _x('Bahamas', 'country', 'gecka-geolocation'),
            'BT' => _x('Bhutan', 'country', 'gecka-geolocation'),
            'BV' => _x('Bouvet Island', 'country', 'gecka-geolocation'),
            'BW' => _x('Botswana', 'country', 'gecka-geolocation'),
            'BY' => _x('Belarus', 'country', 'gecka-geolocation'),
            'BZ' => _x('Belize', 'country', 'gecka-geolocation'),
            'CA' => _x('Canada', 'country', 'gecka-geolocation'),
            'CC' => _x('Cocos (Keeling) Islands', 'country', 'gecka-geolocation'),
            'CD' => _x('Congo - Kinshasa', 'country', 'gecka-geolocation'),
            'CF' => _x('Central African Republic', 'country', 'gecka-geolocation'),
            'CG' => _x('Congo - Brazzaville', 'country', 'gecka-geolocation'),
            'CH' => _x('Switzerland', 'country', 'gecka-geolocation'),
            'CI' => _x('Côte d’Ivoire', 'country', 'gecka-geolocation'),
            'CK' => _x('Cook Islands', 'country', 'gecka-geolocation'),
            'CL' => _x('Chile', 'country', 'gecka-geolocation'),
            'CM' => _x('Cameroon', 'country', 'gecka-geolocation'),
            'CN' => _x('China', 'country', 'gecka-geolocation'),
            'CO' => _x('Colombia', 'country', 'gecka-geolocation'),
            'CP' => _x('Clipperton Island', 'country', 'gecka-geolocation'),
            'CR' => _x('Costa Rica', 'country', 'gecka-geolocation'),
            'CU' => _x('Cuba', 'country', 'gecka-geolocation'),
            'CV' => _x('Cape Verde', 'country', 'gecka-geolocation'),
            'CW' => _x('Curaçao', 'country', 'gecka-geolocation'),
            'CX' => _x('Christmas Island', 'country', 'gecka-geolocation'),
            'CY' => _x('Cyprus', 'country', 'gecka-geolocation'),
            'CZ' => _x('Czechia', 'country', 'gecka-geolocation'),
            'DE' => _x('Germany', 'country', 'gecka-geolocation'),
            'DG' => _x('Diego Garcia', 'country', 'gecka-geolocation'),
            'DJ' => _x('Djibouti', 'country', 'gecka-geolocation'),
            'DK' => _x('Denmark', 'country', 'gecka-geolocation'),
            'DM' => _x('Dominica', 'country', 'gecka-geolocation'),
            'DO' => _x('Dominican Republic', 'country', 'gecka-geolocation'),
            'DZ' => _x('Algeria', 'country', 'gecka-geolocation'),
            'EC' => _x('Ecuador', 'country', 'gecka-geolocation'),
            'EE' => _x('Estonia', 'country', 'gecka-geolocation'),
            'EG' => _x('Egypt', 'country', 'gecka-geolocation'),
            'EH' => _x('Western Sahara', 'country', 'gecka-geolocation'),
            'ER' => _x('Eritrea', 'country', 'gecka-geolocation'),
            'ES' => _x('Spain', 'country', 'gecka-geolocation'),
            'ET' => _x('Ethiopia', 'country', 'gecka-geolocation'),
            'EU' => _x('European Union', 'country', 'gecka-geolocation'),
            'FI' => _x('Finland', 'country', 'gecka-geolocation'),
            'FJ' => _x('Fiji', 'country', 'gecka-geolocation'),
            'FK' => _x('Falkland Islands', 'country', 'gecka-geolocation'),
            'FM' => _x('Micronesia', 'country', 'gecka-geolocation'),
            'FO' => _x('Faroe Islands', 'country', 'gecka-geolocation'),
            'FR' => _x('France', 'country', 'gecka-geolocation'),
            'GA' => _x('Gabon', 'country', 'gecka-geolocation'),
            'GB' => _x('United Kingdom', 'country', 'gecka-geolocation'),
            'GD' => _x('Grenada', 'country', 'gecka-geolocation'),
            'GE' => _x('Georgia', 'country', 'gecka-geolocation'),
            'GF' => _x('French Guiana', 'country', 'gecka-geolocation'),
            'GG' => _x('Guernsey', 'country', 'gecka-geolocation'),
            'GH' => _x('Ghana', 'country', 'gecka-geolocation'),
            'GI' => _x('Gibraltar', 'country', 'gecka-geolocation'),
            'GL' => _x('Greenland', 'country', 'gecka-geolocation'),
            'GM' => _x('Gambia', 'country', 'gecka-geolocation'),
            'GN' => _x('Guinea', 'country', 'gecka-geolocation'),
            'GP' => _x('Guadeloupe', 'country', 'gecka-geolocation'),
            'GQ' => _x('Equatorial Guinea', 'country', 'gecka-geolocation'),
            'GR' => _x('Greece', 'country', 'gecka-geolocation'),
            'GS' => _x('South Georgia & South Sandwich Islands', 'country', 'gecka-geolocation'),
            'GT' => _x('Guatemala', 'country', 'gecka-geolocation'),
            'GU' => _x('Guam', 'country', 'gecka-geolocation'),
            'GW' => _x('Guinea-Bissau', 'country', 'gecka-geolocation'),
            'GY' => _x('Guyana', 'country', 'gecka-geolocation'),
            'HK' => _x('Hong Kong SAR China', 'country', 'gecka-geolocation'),
            'HM' => _x('Heard & McDonald Islands', 'country', 'gecka-geolocation'),
            'HN' => _x('Honduras', 'country', 'gecka-geolocation'),
            'HR' => _x('Croatia', 'country', 'gecka-geolocation'),
            'HT' => _x('Haiti', 'country', 'gecka-geolocation'),
            'HU' => _x('Hungary', 'country', 'gecka-geolocation'),
            'IC' => _x('Canary Islands', 'country', 'gecka-geolocation'),
            'ID' => _x('Indonesia', 'country', 'gecka-geolocation'),
            'IE' => _x('Ireland', 'country', 'gecka-geolocation'),
            'IL' => _x('Israel', 'country', 'gecka-geolocation'),
            'IM' => _x('Isle of Man', 'country', 'gecka-geolocation'),
            'IN' => _x('India', 'country', 'gecka-geolocation'),
            'IO' => _x('British Indian Ocean Territory', 'country', 'gecka-geolocation'),
            'IQ' => _x('Iraq', 'country', 'gecka-geolocation'),
            'IR' => _x('Iran', 'country', 'gecka-geolocation'),
            'IS' => _x('Iceland', 'country', 'gecka-geolocation'),
            'IT' => _x('Italy', 'country', 'gecka-geolocation'),
            'JE' => _x('Jersey', 'country', 'gecka-geolocation'),
            'JM' => _x('Jamaica', 'country', 'gecka-geolocation'),
            'JO' => _x('Jordan', 'country', 'gecka-geolocation'),
            'JP' => _x('Japan', 'country', 'gecka-geolocation'),
            'KE' => _x('Kenya', 'country', 'gecka-geolocation'),
            'KG' => _x('Kyrgyzstan', 'country', 'gecka-geolocation'),
            'KH' => _x('Cambodia', 'country', 'gecka-geolocation'),
            'KI' => _x('Kiribati', 'country', 'gecka-geolocation'),
            'KM' => _x('Comoros', 'country', 'gecka-geolocation'),
            'KN' => _x('St. Kitts & Nevis', 'country', 'gecka-geolocation'),
            'KP' => _x('North Korea', 'country', 'gecka-geolocation'),
            'KR' => _x('South Korea', 'country', 'gecka-geolocation'),
            'KW' => _x('Kuwait', 'country', 'gecka-geolocation'),
            'KY' => _x('Cayman Islands', 'country', 'gecka-geolocation'),
            'KZ' => _x('Kazakhstan', 'country', 'gecka-geolocation'),
            'LA' => _x('Laos', 'country', 'gecka-geolocation'),
            'LB' => _x('Lebanon', 'country', 'gecka-geolocation'),
            'LC' => _x('St. Lucia', 'country', 'gecka-geolocation'),
            'LI' => _x('Liechtenstein', 'country', 'gecka-geolocation'),
            'LK' => _x('Sri Lanka', 'country', 'gecka-geolocation'),
            'LR' => _x('Liberia', 'country', 'gecka-geolocation'),
            'LS' => _x('Lesotho', 'country', 'gecka-geolocation'),
            'LT' => _x('Lithuania', 'country', 'gecka-geolocation'),
            'LU' => _x('Luxembourg', 'country', 'gecka-geolocation'),
            'LV' => _x('Latvia', 'country', 'gecka-geolocation'),
            'LY' => _x('Libya', 'country', 'gecka-geolocation'),
            'MA' => _x('Morocco', 'country', 'gecka-geolocation'),
            'MC' => _x('Monaco', 'country', 'gecka-geolocation'),
            'MD' => _x('Moldova', 'country', 'gecka-geolocation'),
            'ME' => _x('Montenegro', 'country', 'gecka-geolocation'),
            'MF' => _x('St. Martin', 'country', 'gecka-geolocation'),
            'MG' => _x('Madagascar', 'country', 'gecka-geolocation'),
            'MH' => _x('Marshall Islands', 'country', 'gecka-geolocation'),
            'MK' => _x('North Macedonia', 'country', 'gecka-geolocation'),
            'ML' => _x('Mali', 'country', 'gecka-geolocation'),
            'MM' => _x('Myanmar (Burma)', 'country', 'gecka-geolocation'),
            'MN' => _x('Mongolia', 'country', 'gecka-geolocation'),
            'MO' => _x('Macao SAR China', 'country', 'gecka-geolocation'),
            'MP' => _x('Northern Mariana Islands', 'country', 'gecka-geolocation'),
            'MQ' => _x('Martinique', 'country', 'gecka-geolocation'),
            'MR' => _x('Mauritania', 'country', 'gecka-geolocation'),
            'MS' => _x('Montserrat', 'country', 'gecka-geolocation'),
            'MT' => _x('Malta', 'country', 'gecka-geolocation'),
            'MU' => _x('Mauritius', 'country', 'gecka-geolocation'),
            'MV' => _x('Maldives', 'country', 'gecka-geolocation'),
            'MW' => _x('Malawi', 'country', 'gecka-geolocation'),
            'MX' => _x('Mexico', 'country', 'gecka-geolocation'),
            'MY' => _x('Malaysia', 'country', 'gecka-geolocation'),
            'MZ' => _x('Mozambique', 'country', 'gecka-geolocation'),
            'NA' => _x('Namibia', 'country', 'gecka-geolocation'),
            'NC' => _x('New Caledonia', 'country', 'gecka-geolocation'),
            'NE' => _x('Niger', 'country', 'gecka-geolocation'),
            'NF' => _x('Norfolk Island', 'country', 'gecka-geolocation'),
            'NG' => _x('Nigeria', 'country', 'gecka-geolocation'),
            'NI' => _x('Nicaragua', 'country', 'gecka-geolocation'),
            'NL' => _x('Netherlands', 'country', 'gecka-geolocation'),
            'NO' => _x('Norway', 'country', 'gecka-geolocation'),
            'NP' => _x('Nepal', 'country', 'gecka-geolocation'),
            'NR' => _x('Nauru', 'country', 'gecka-geolocation'),
            'NU' => _x('Niue', 'country', 'gecka-geolocation'),
            'NZ' => _x('New Zealand', 'country', 'gecka-geolocation'),
            'OM' => _x('Oman', 'country', 'gecka-geolocation'),
            'PA' => _x('Panama', 'country', 'gecka-geolocation'),
            'PC' => _x('Pacific Community', 'country', 'gecka-geolocation'),
            'PE' => _x('Peru', 'country', 'gecka-geolocation'),
            'PF' => _x('French Polynesia', 'country', 'gecka-geolocation'),
            'PG' => _x('Papua New Guinea', 'country', 'gecka-geolocation'),
            'PH' => _x('Philippines', 'country', 'gecka-geolocation'),
            'PK' => _x('Pakistan', 'country', 'gecka-geolocation'),
            'PL' => _x('Poland', 'country', 'gecka-geolocation'),
            'PM' => _x('St. Pierre & Miquelon', 'country', 'gecka-geolocation'),
            'PN' => _x('Pitcairn Islands', 'country', 'gecka-geolocation'),
            'PR' => _x('Puerto Rico', 'country', 'gecka-geolocation'),
            'PS' => _x('Palestinian Territories', 'country', 'gecka-geolocation'),
            'PT' => _x('Portugal', 'country', 'gecka-geolocation'),
            'PW' => _x('Palau', 'country', 'gecka-geolocation'),
            'PY' => _x('Paraguay', 'country', 'gecka-geolocation'),
            'QA' => _x('Qatar', 'country', 'gecka-geolocation'),
            'RE' => _x('Réunion', 'country', 'gecka-geolocation'),
            'RO' => _x('Romania', 'country', 'gecka-geolocation'),
            'RS' => _x('Serbia', 'country', 'gecka-geolocation'),
            'RU' => _x('Russia', 'country', 'gecka-geolocation'),
            'RW' => _x('Rwanda', 'country', 'gecka-geolocation'),
            'SA' => _x('Saudi Arabia', 'country', 'gecka-geolocation'),
            'SB' => _x('Solomon Islands', 'country', 'gecka-geolocation'),
            'SC' => _x('Seychelles', 'country', 'gecka-geolocation'),
            'SD' => _x('Sudan', 'country', 'gecka-geolocation'),
            'SE' => _x('Sweden', 'country', 'gecka-geolocation'),
            'SG' => _x('Singapore', 'country', 'gecka-geolocation'),
            'SH' => _x('St. Helena', 'country', 'gecka-geolocation'),
            'SI' => _x('Slovenia', 'country', 'gecka-geolocation'),
            'SJ' => _x('Svalbard & Jan Mayen', 'country', 'gecka-geolocation'),
            'SK' => _x('Slovakia', 'country', 'gecka-geolocation'),
            'SL' => _x('Sierra Leone', 'country', 'gecka-geolocation'),
            'SM' => _x('San Marino', 'country', 'gecka-geolocation'),
            'SN' => _x('Senegal', 'country', 'gecka-geolocation'),
            'SO' => _x('Somalia', 'country', 'gecka-geolocation'),
            'SR' => _x('Suriname', 'country', 'gecka-geolocation'),
            'SS' => _x('South Sudan', 'country', 'gecka-geolocation'),
            'ST' => _x('São Tomé & Príncipe', 'country', 'gecka-geolocation'),
            'SV' => _x('El Salvador', 'country', 'gecka-geolocation'),
            'SX' => _x('Sint Maarten', 'country', 'gecka-geolocation'),
            'SY' => _x('Syria', 'country', 'gecka-geolocation'),
            'SZ' => _x('Eswatini', 'country', 'gecka-geolocation'),
            'TC' => _x('Turks & Caicos Islands', 'country', 'gecka-geolocation'),
            'TD' => _x('Chad', 'country', 'gecka-geolocation'),
            'TF' => _x('French Southern Territories', 'country', 'gecka-geolocation'),
            'TG' => _x('Togo', 'country', 'gecka-geolocation'),
            'TH' => _x('Thailand', 'country', 'gecka-geolocation'),
            'TJ' => _x('Tajikistan', 'country', 'gecka-geolocation'),
            'TK' => _x('Tokelau', 'country', 'gecka-geolocation'),
            'TL' => _x('Timor-Leste', 'country', 'gecka-geolocation'),
            'TM' => _x('Turkmenistan', 'country', 'gecka-geolocation'),
            'TN' => _x('Tunisia', 'country', 'gecka-geolocation'),
            'TO' => _x('Tonga', 'country', 'gecka-geolocation'),
            'TR' => _x('Türkiye', 'country', 'gecka-geolocation'),
            'TT' => _x('Trinidad & Tobago', 'country', 'gecka-geolocation'),
            'TV' => _x('Tuvalu', 'country', 'gecka-geolocation'),
            'TW' => _x('Taiwan', 'country', 'gecka-geolocation'),
            'TZ' => _x('Tanzania', 'country', 'gecka-geolocation'),
            'UA' => _x('Ukraine', 'country', 'gecka-geolocation'),
            'UG' => _x('Uganda', 'country', 'gecka-geolocation'),
            'UM' => _x('U.S. Outlying Islands', 'country', 'gecka-geolocation'),
            'UN' => _x('United Nations', 'country', 'gecka-geolocation'),
            'US' => _x('United States', 'country', 'gecka-geolocation'),
            'UY' => _x('Uruguay', 'country', 'gecka-geolocation'),
            'UZ' => _x('Uzbekistan', 'country', 'gecka-geolocation'),
            'VA' => _x('Vatican City', 'country', 'gecka-geolocation'),
            'VC' => _x('St. Vincent & Grenadines', 'country', 'gecka-geolocation'),
            'VE' => _x('Venezuela', 'country', 'gecka-geolocation'),
            'VG' => _x('British Virgin Islands', 'country', 'gecka-geolocation'),
            'VI' => _x('U.S. Virgin Islands', 'country', 'gecka-geolocation'),
            'VN' => _x('Vietnam', 'country', 'gecka-geolocation'),
            'VU' => _x('Vanuatu', 'country', 'gecka-geolocation'),
            'WF' => _x('Wallis & Futuna', 'country', 'gecka-geolocation'),
            'WS' => _x('Samoa', 'country', 'gecka-geolocation'),
            'XK' => _x('Kosovo', 'country', 'gecka-geolocation'),
            'YE' => _x('Yemen', 'country', 'gecka-geolocation'),
            'YT' => _x('Mayotte', 'country', 'gecka-geolocation'),
            'ZA' => _x('South Africa', 'country', 'gecka-geolocation'),
            'ZM' => _x('Zambia', 'country', 'gecka-geolocation'),
            'ZW' => _x('Zimbabwe', 'country', 'gecka-geolocation'),
        ];
    }
}
