<?php
/**
 * Heart Attack and Stroke Risk Check engine (P3-57, FR3-49), written from the owner-signed method sheet
 * `04 Health Tools/Stage 3/P3-57-Heart-Attack-Stroke-Risk-Method-Sheet.md` (approval record
 * `approvals/stage3/heart-risk.json`, fixtures `fixtures/heart-risk/`).
 *
 * A look-up of the WHO 2019 cardiovascular disease risk charts (Kaptoge et al., Lancet Glob Health 2019, appendix 2,
 * CC BY 4.0), decision D3-10 = B: the member's answers pick one published chart cell and the printed whole-number
 * 10-year risk is the result. The 44,100 cells and the 195-country region map are the frozen data files in `data/`,
 * byte-identical to `fixtures/heart-risk/` (the test checks their SHA-256 against the approval record).
 *
 * Cell boundaries (sheet 1.2): age 40-44 … 70-74; SBP < 120, 120-139, 140-159, 160-179, >= 180 (whole mmHg, half-up);
 * total cholesterol < 4, 4-4.9, 5-5.9, 6-6.9, >= 7 mmol/L (mg/dL / 38.67 unrounded, then 1 dp half-up); BMI < 20,
 * 20-24, 25-29, 30-35, >= 35 (1 dp half-up; 35.0 is >= 35). Band on the printed number (D3-13). Cholesterol "don't
 * know" uses the non-laboratory chart (D3-15). What-if: the adjacent cell per factor, ranked by the fall, ties in the
 * order smoking, blood pressure, cholesterol, weight. Stops before any number: under 40, over 74, existing
 * cardiovascular disease, pregnancy or breastfeeding (yes or not sure), and "prefer not to say" sex (D3-11).
 *
 * Pure: no WordPress, no database, no member lookup. Frozen: any change to these rules or the wording needs a new
 * decision and a new version of the approval record (NFR3-14).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The engine. `compute()` takes the answers and returns the result the screens and the tests read.
 */
class LLD_Members_Heart_Risk {

	const VERSION = '1.0';

	/** mg/dL of total cholesterol per mmol/L (sheet section 2, row 7). */
	const MG_DL_PER_MMOL = 38.67;

	/** The chart axes, lowest first, as the data file names them. */
	const AGE_BANDS  = array( '40-44', '45-49', '50-54', '55-59', '60-64', '65-69', '70-74' );
	const SBP_BANDS  = array( '<120', '120-139', '140-159', '160-179', '>=180' );
	const TC_BANDS   = array( '<4', '4-4.9', '5-5.9', '6-6.9', '>=7' );
	const BMI_BANDS  = array( '<20', '20-24', '25-29', '30-35', '>=35' );
	const SBP_BOUNDS = array( 120, 140, 160, 180 );
	const TC_BOUNDS  = array( 4.0, 5.0, 6.0, 7.0 );
	const BMI_BOUNDS = array( 20.0, 25.0, 30.0, 35.0 );

	/** What-if factors in tie order (sheet section 3.2). */
	const FACTORS = array( 'smoking', 'blood-pressure', 'cholesterol', 'weight' );

	/** The frozen data files, beside this class (copies of fixtures/heart-risk/, byte for byte). */
	const DATA_CELLS   = 'data/who2019-chart-cells.json';
	const DATA_REGIONS = 'data/who2019-country-to-region.json';

	/**
	 * The signed wording (sheet sections 3 and 4). Keys and text are hashed in the approval record's copy_hashes; the
	 * test checks them. Placeholders are filled by the screen from the result's `fill`.
	 */
	const MESSAGES = array(
		'headline'                => 'Your estimated chance of a heart attack or stroke in the next 10 years is about {risk} %',
		'band.low'                => 'Low. Keeping active, not smoking and eating well keeps it that way.',
		'band.moderate'           => 'Moderate. Small changes now make a real difference over the years.',
		'band.high'               => "High. It's worth talking to your doctor about your risk and whether treatment would help.",
		'band.very-high'          => 'Very high. Please make an appointment with your doctor to discuss this. Treatment can lower it a lot.',
		'comparison'              => 'For comparison, {n} in 100 people like you would be expected to have a heart attack or stroke in the next 10 years',
		'whatif'                  => 'The change that would lower your risk most: {factor}. {Stopping smoking | Bringing your blood pressure under {band top} | Bringing your cholesterol under {band top} mmol/L | Bringing your BMI under {band top}} would take it to about {new_risk} %.',
		'whatif.diabetes'         => 'Keeping blood sugar well controlled also lowers risk.',
		'whatif.none'             => 'Your changeable risk factors are already in the lowest bands. Keeping them there is the goal.',
		'whatif.noFall'           => 'No single change would lower this estimate on the WHO chart right now. Not smoking, staying active and keeping your blood pressure and cholesterol in check still protect your heart over the years.', // CW-20 (Owner_Questions 95)
		'headline.under1'         => 'Your estimated chance of a heart attack or stroke in the next 10 years is less than 1 %', // CW-22 (Owner_Questions 100)
		'comparison.under1'       => 'For comparison, fewer than 1 in 100 people like you would be expected to have a heart attack or stroke in the next 10 years', // CW-22
		'always'                  => "This is an estimate for people without known heart disease, from the World Health Organization's 2019 risk charts for {region}. It doesn't replace an assessment by your doctor, who may use a method made for your country.",
		'model.lab'               => 'Based on a blood test (total cholesterol)',
		'model.nolab'             => 'Without a blood test (uses BMI instead). Ask your doctor for a cholesterol test for a closer estimate.',
		'other-methods'           => 'Your doctor may use a different method for your country (for example QRISK in the UK, PREVENT or the ACC/AHA calculator in the US, SCORE2 in Europe). Those are calibrated to your country and are the ones to use for treatment decisions.',
		'stops.under40'           => "This check isn't suitable under 40. The WHO charts estimate risk from age 40. Most of what lowers risk later, not smoking, moving more, eating well and knowing your blood pressure, works at any age.",
		'stops.over74'            => "This check isn't suitable over 74. Your doctor can assess your risk with methods made for older adults.",
		'stops.cvd'               => "This check is for people who haven't had a heart attack, stroke or heart surgery. Your doctor uses different methods for you, and treatment is usually already recommended.",
		'notes.diabetes'          => 'With diabetes, your doctor may use a diabetes-specific assessment and recommend treatment whatever the number.',
		'limits.other-conditions' => "The WHO charts don't include family history, kidney disease or some other conditions. If any of these apply, your real risk may be higher; your doctor can assess it.",
		'stops.pregnancy'         => "This check isn't suitable right now. Blood pressure and cholesterol change during pregnancy and breastfeeding, so the WHO charts can't give a reliable estimate at this time. Your midwife or doctor can check your blood pressure and advise you. You can come back to this check after pregnancy and breastfeeding.", // CW-01
		'stops.sex'               => "We can't work out this estimate without knowing your sex. The WHO charts are separate for men and women, because risk differs between them at the same age and blood pressure, and there's no chart in between. If you'd like an estimate, you can choose a sex above. Your doctor can also assess your heart risk with you.", // CW-02
	);

	/**
	 * The check asks for the systolic reading only (sheet section 2). The urgent level is decided by the safety layer
	 * (LLD_Members_Stage3_Safety::warning_level, decision D5: any blood-pressure input), which needs a diastolic too:
	 * this one is inside the possible range for every systolic the page accepts and can set no level on its own, so the
	 * systolic alone decides. The urgent key and its text are the safety layer's (`bp-check.urgent.alert`, or
	 * `bp-check.pregnancy.urgent` with the pregnancy thresholds), never retyped here.
	 */
	const NEUTRAL_DIA = 60;

	/**
	 * Other names and ISO 3166 two-letter codes for countries, folded as fold() folds them, => the appendix-1 name
	 * whose region applies. The site's own extension: a small state the appendix leaves out takes its neighbour's
	 * (Liechtenstein, Monaco, San Marino, Vatican City, Saint Kitts and Nevis, Nauru, Palau, Tuvalu, Kosovo), and a
	 * territory takes the region of the part of the world it is in, as the appendix places its own territories
	 * (Puerto Rico, Guam, Bermuda) by geography (Owner_Questions 99, decided 4 Nov 2026, as shown to the owner:
	 * "Puerto Rico in the Americas, Réunion in Africa"; written into sheet 1.2 the same day). Each territory points
	 * at an appendix country in that area: the Caribbean islands at a Caribbean country (so Aruba, Curaçao and
	 * Bonaire go to Trinidad and Tobago, not Venezuela on the mainland), the Indian Ocean islands off Africa at an
	 * African one (Réunion at Madagascar; Mauritius, nearer, sits in the WHO's Southeast Asia region), the Pacific
	 * islands at a Pacific one (Pitcairn at Tonga). Antarctica has none.
	 */
	const ALIASES = array(
		// Other names.
		'united states' => 'United States of America', 'usa' => 'United States of America', 'u s' => 'United States of America', 'u s a' => 'United States of America', 'america' => 'United States of America',
		'uk' => 'United Kingdom', 'u k' => 'United Kingdom', 'great britain' => 'United Kingdom', 'britain' => 'United Kingdom', 'england' => 'United Kingdom', 'scotland' => 'United Kingdom', 'wales' => 'United Kingdom', 'northern ireland' => 'United Kingdom',
		'uae' => 'United Arab Emirates', 'eswatini' => 'Swaziland', 'kingdom of eswatini' => 'Swaziland', 'north macedonia' => 'Macedonia (TFYR)', 'macedonia' => 'Macedonia (TFYR)',
		'czechia' => 'Czech Republic', 'turkiye' => 'Turkey', 'vietnam' => 'Viet Nam', 'ivory coast' => "Cote d'Ivoire", 'russia' => 'Russian Federation',
		'korea republic of' => 'South Korea', 'republic of korea' => 'South Korea', 'korea south' => 'South Korea', 'korea north' => 'North Korea', 'dprk' => 'North Korea', 'democratic peoples republic of korea' => 'North Korea',
		'laos' => 'Lao PDR', 'lao peoples democratic republic' => 'Lao PDR', 'syria' => 'Syrian Arab Republic', 'iran islamic republic of' => 'Iran',
		'bolivia plurinational state of' => 'Bolivia', 'venezuela bolivarian republic of' => 'Venezuela', 'tanzania united republic of' => 'Tanzania', 'united republic of tanzania' => 'Tanzania',
		'moldova republic of' => 'Moldova', 'republic of moldova' => 'Moldova', 'brunei' => 'Brunei Darussalam', 'cape verde' => 'Cabo Verde',
		'democratic republic of the congo' => 'DR Congo', 'congo democratic republic of the' => 'DR Congo', 'drc' => 'DR Congo', 'congo kinshasa' => 'DR Congo', 'republic of the congo' => 'Congo', 'congo brazzaville' => 'Congo',
		'palestine' => 'Occupied Palestinian Territory', 'state of palestine' => 'Occupied Palestinian Territory', 'micronesia' => 'Micronesia (Federated States of)', 'east timor' => 'Timor-Leste', 'burma' => 'Myanmar',
		'holland' => 'Netherlands', 'us virgin islands' => 'Virgin Islands, U.S.', 'virgin islands' => 'Virgin Islands, U.S.', 'bosnia' => 'Bosnia and Herzegovina', 'taiwan province of china' => 'Taiwan',
		// Small states the appendix leaves out: the neighbour's region.
		'liechtenstein' => 'Switzerland', 'monaco' => 'France', 'san marino' => 'Italy', 'vatican city' => 'Italy', 'holy see' => 'Italy', 'saint kitts and nevis' => 'Antigua and Barbuda',
		'nauru' => 'Kiribati', 'palau' => 'Micronesia (Federated States of)', 'tuvalu' => 'Fiji', 'kosovo' => 'Serbia',
		// Territories: the region of where they are (Owner_Questions 99), by their nearest appendix country.
		'hong kong' => 'China', 'hong kong sar' => 'China', 'macao' => 'China', 'macau' => 'China', 'macao sar' => 'China',
		'anguilla' => 'Antigua and Barbuda', 'british virgin islands' => 'Antigua and Barbuda', 'cayman islands' => 'Jamaica', 'montserrat' => 'Antigua and Barbuda', 'turks and caicos islands' => 'Bahamas', 'falkland islands' => 'Chile',
		'gibraltar' => 'Spain', 'jersey' => 'United Kingdom', 'guernsey' => 'United Kingdom', 'isle of man' => 'United Kingdom', 'saint helena' => 'Angola', 'pitcairn islands' => 'Tonga', 'british indian ocean territory' => 'Maldives', 'south georgia and the south sandwich islands' => 'Chile',
		'aruba' => 'Trinidad and Tobago', 'curacao' => 'Trinidad and Tobago', 'sint maarten' => 'Antigua and Barbuda', 'bonaire' => 'Trinidad and Tobago', 'caribbean netherlands' => 'Trinidad and Tobago',
		'guadeloupe' => 'Dominica', 'martinique' => 'Saint Lucia', 'french guiana' => 'Suriname', 'reunion' => 'Madagascar', 'mayotte' => 'Comoros', 'french polynesia' => 'Samoa', 'new caledonia' => 'Vanuatu', 'wallis and futuna' => 'Samoa', 'saint pierre and miquelon' => 'Canada', 'saint barthelemy' => 'Antigua and Barbuda', 'saint martin' => 'Antigua and Barbuda', 'french southern territories' => 'Madagascar',
		'aland islands' => 'Finland', 'aland' => 'Finland', 'faroe islands' => 'Denmark', 'svalbard' => 'Norway', 'svalbard and jan mayen' => 'Norway', 'western sahara' => 'Morocco',
		'cook islands' => 'Samoa', 'niue' => 'Tonga', 'tokelau' => 'Samoa', 'norfolk island' => 'Australia', 'christmas island' => 'Indonesia', 'cocos keeling islands' => 'Indonesia', 'cocos islands' => 'Indonesia',
		'united states minor outlying islands' => 'Marshall Islands',
		// ISO 3166 two-letter codes (the time-zone country a signed-in member's page is pre-filled from).
		'ad' => 'Andorra', 'ae' => 'United Arab Emirates', 'af' => 'Afghanistan', 'ag' => 'Antigua and Barbuda', 'ai' => 'Antigua and Barbuda', 'al' => 'Albania', 'am' => 'Armenia', 'ao' => 'Angola',
		'ar' => 'Argentina', 'as' => 'American Samoa', 'at' => 'Austria', 'au' => 'Australia', 'aw' => 'Trinidad and Tobago', 'ax' => 'Finland', 'az' => 'Azerbaijan', 'ba' => 'Bosnia and Herzegovina',
		'bb' => 'Barbados', 'bd' => 'Bangladesh', 'be' => 'Belgium', 'bf' => 'Burkina Faso', 'bg' => 'Bulgaria', 'bh' => 'Bahrain', 'bi' => 'Burundi', 'bj' => 'Benin', 'bl' => 'Antigua and Barbuda',
		'bm' => 'Bermuda', 'bn' => 'Brunei Darussalam', 'bo' => 'Bolivia', 'bq' => 'Trinidad and Tobago', 'br' => 'Brazil', 'bs' => 'Bahamas', 'bt' => 'Bhutan', 'bw' => 'Botswana', 'by' => 'Belarus',
		'bz' => 'Belize', 'ca' => 'Canada', 'cc' => 'Indonesia', 'cd' => 'DR Congo', 'cf' => 'Central African Republic', 'cg' => 'Congo', 'ch' => 'Switzerland', 'ci' => "Cote d'Ivoire",
		'ck' => 'Samoa', 'cl' => 'Chile', 'cm' => 'Cameroon', 'cn' => 'China', 'co' => 'Colombia', 'cr' => 'Costa Rica', 'cu' => 'Cuba', 'cv' => 'Cabo Verde', 'cw' => 'Trinidad and Tobago',
		'cx' => 'Indonesia', 'cy' => 'Cyprus', 'cz' => 'Czech Republic', 'de' => 'Germany', 'dj' => 'Djibouti', 'dk' => 'Denmark', 'dm' => 'Dominica', 'do' => 'Dominican Republic', 'dz' => 'Algeria',
		'ec' => 'Ecuador', 'ee' => 'Estonia', 'eg' => 'Egypt', 'eh' => 'Morocco', 'er' => 'Eritrea', 'es' => 'Spain', 'et' => 'Ethiopia', 'fi' => 'Finland', 'fj' => 'Fiji', 'fk' => 'Chile',
		'fm' => 'Micronesia (Federated States of)', 'fo' => 'Denmark', 'fr' => 'France', 'ga' => 'Gabon', 'gb' => 'United Kingdom', 'gd' => 'Grenada', 'ge' => 'Georgia', 'gf' => 'Suriname',
		'gg' => 'United Kingdom', 'gh' => 'Ghana', 'gi' => 'Spain', 'gl' => 'Greenland', 'gm' => 'Gambia', 'gn' => 'Guinea', 'gp' => 'Dominica', 'gq' => 'Equatorial Guinea', 'gr' => 'Greece',
		'gs' => 'Chile', 'gt' => 'Guatemala', 'gu' => 'Guam', 'gw' => 'Guinea Bissau', 'gy' => 'Guyana', 'hk' => 'China', 'hn' => 'Honduras', 'hr' => 'Croatia', 'ht' => 'Haiti',
		'hu' => 'Hungary', 'id' => 'Indonesia', 'ie' => 'Ireland', 'il' => 'Israel', 'im' => 'United Kingdom', 'in' => 'India', 'io' => 'Maldives', 'iq' => 'Iraq', 'ir' => 'Iran', 'is' => 'Iceland',
		'it' => 'Italy', 'je' => 'United Kingdom', 'jm' => 'Jamaica', 'jo' => 'Jordan', 'jp' => 'Japan', 'ke' => 'Kenya', 'kg' => 'Kyrgyzstan', 'kh' => 'Cambodia', 'ki' => 'Kiribati', 'km' => 'Comoros',
		'kn' => 'Antigua and Barbuda', 'kp' => 'North Korea', 'kr' => 'South Korea', 'kw' => 'Kuwait', 'ky' => 'Jamaica', 'kz' => 'Kazakhstan', 'la' => 'Lao PDR', 'lb' => 'Lebanon',
		'lc' => 'Saint Lucia', 'li' => 'Switzerland', 'lk' => 'Sri Lanka', 'lr' => 'Liberia', 'ls' => 'Lesotho', 'lt' => 'Lithuania', 'lu' => 'Luxembourg', 'lv' => 'Latvia', 'ly' => 'Libya',
		'ma' => 'Morocco', 'mc' => 'France', 'md' => 'Moldova', 'me' => 'Montenegro', 'mf' => 'Antigua and Barbuda', 'mg' => 'Madagascar', 'mh' => 'Marshall Islands', 'mk' => 'Macedonia (TFYR)', 'ml' => 'Mali',
		'mm' => 'Myanmar', 'mn' => 'Mongolia', 'mo' => 'China', 'mp' => 'Northern Mariana Islands', 'mq' => 'Saint Lucia', 'mr' => 'Mauritania', 'ms' => 'Antigua and Barbuda', 'mt' => 'Malta', 'mu' => 'Mauritius',
		'mv' => 'Maldives', 'mw' => 'Malawi', 'mx' => 'Mexico', 'my' => 'Malaysia', 'mz' => 'Mozambique', 'na' => 'Namibia', 'nc' => 'Vanuatu', 'ne' => 'Niger', 'nf' => 'Australia', 'ng' => 'Nigeria',
		'ni' => 'Nicaragua', 'nl' => 'Netherlands', 'no' => 'Norway', 'np' => 'Nepal', 'nr' => 'Kiribati', 'nu' => 'Tonga', 'nz' => 'New Zealand', 'om' => 'Oman', 'pa' => 'Panama', 'pe' => 'Peru',
		'pf' => 'Samoa', 'pg' => 'Papua New Guinea', 'ph' => 'Philippines', 'pk' => 'Pakistan', 'pl' => 'Poland', 'pm' => 'Canada', 'pn' => 'Tonga', 'pr' => 'Puerto Rico',
		'ps' => 'Occupied Palestinian Territory', 'pt' => 'Portugal', 'pw' => 'Micronesia (Federated States of)', 'py' => 'Paraguay', 'qa' => 'Qatar', 're' => 'Madagascar', 'ro' => 'Romania', 'rs' => 'Serbia',
		'ru' => 'Russian Federation', 'rw' => 'Rwanda', 'sa' => 'Saudi Arabia', 'sb' => 'Solomon Islands', 'sc' => 'Seychelles', 'sd' => 'Sudan', 'se' => 'Sweden', 'sg' => 'Singapore',
		'sh' => 'Angola', 'si' => 'Slovenia', 'sj' => 'Norway', 'sk' => 'Slovakia', 'sl' => 'Sierra Leone', 'sm' => 'Italy', 'sn' => 'Senegal', 'so' => 'Somalia', 'sr' => 'Suriname',
		'ss' => 'South Sudan', 'st' => 'Sao Tome and Principe', 'sv' => 'El Salvador', 'sx' => 'Antigua and Barbuda', 'sy' => 'Syrian Arab Republic', 'sz' => 'Swaziland', 'tc' => 'Bahamas', 'td' => 'Chad',
		'tf' => 'Madagascar', 'tg' => 'Togo', 'th' => 'Thailand', 'tj' => 'Tajikistan', 'tk' => 'Samoa', 'tl' => 'Timor-Leste', 'tm' => 'Turkmenistan', 'tn' => 'Tunisia', 'to' => 'Tonga',
		'tr' => 'Turkey', 'tt' => 'Trinidad and Tobago', 'tv' => 'Fiji', 'tw' => 'Taiwan', 'tz' => 'Tanzania', 'ua' => 'Ukraine', 'ug' => 'Uganda', 'um' => 'Marshall Islands',
		'us' => 'United States of America', 'uy' => 'Uruguay', 'uz' => 'Uzbekistan', 'va' => 'Italy', 'vc' => 'Saint Vincent and the Grenadines', 've' => 'Venezuela', 'vg' => 'Antigua and Barbuda',
		'vi' => 'Virgin Islands, U.S.', 'vn' => 'Viet Nam', 'vu' => 'Vanuatu', 'wf' => 'Samoa', 'ws' => 'Samoa', 'xk' => 'Serbia', 'ye' => 'Yemen', 'yt' => 'Comoros', 'za' => 'South Africa',
		'zm' => 'Zambia', 'zw' => 'Zimbabwe',
	);

	/** Accented letters folded to plain ones for name matching (no intl extension needed). */
	const PLAIN = array(
		'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Ã' => 'a', 'Å' => 'a',
		'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'i', 'Î' => 'i',
		'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Ö' => 'o', 'Ø' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ú' => 'u', 'Ü' => 'u',
		'ç' => 'c', 'Ç' => 'c', 'ñ' => 'n', 'Ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'ı' => 'i', 'İ' => 'i', 'æ' => 'ae', 'Æ' => 'ae', 'ß' => 'ss',
	);

	/** @var array|null The chart cells, decoded once. */
	private static $cells = null;

	/** @var array|null Country name (lower case) => GBD region. */
	private static $countries = null;

	/**
	 * Apply the member's health profile (sheet sections 2 and 4; safety matrix P3-03): known heart disease stops the
	 * check, pregnancy stops it, an eating-disorder flag hides the weight what-if, and diabetes is pre-filled when the
	 * page did not answer it. The page's answers are never made less safe.
	 *
	 * @param array    $in         The answers on the page.
	 * @param string[] $situations LLD_Members_Stage3_Safety::SITUATIONS keys in force.
	 * @return array The answers with the profile applied.
	 */
	public static function apply_profile( array $in, array $situations ) {
		$has = function ( $key ) use ( $situations ) {
			return in_array( $key, $situations, true ) || in_array( str_replace( '_', '-', $key ), $situations, true );
		};
		if ( $has( 'heart_disease' ) ) {
			$in['cvd'] = 'yes';
		}
		if ( $has( 'pregnancy' ) ) {
			$in['preg'] = 'yes';
		}
		if ( $has( 'eating_disorder' ) ) {
			$in['eat'] = 'yes';
		}
		if ( $has( 'diabetes' ) && ( ! isset( $in['diabetes'] ) || '' === $in['diabetes'] || null === $in['diabetes'] ) ) {
			$in['diabetes'] = 'yes';
		}
		return $in;
	}

	/**
	 * Work out the result.
	 *
	 * @param array $in age (18-99), sex (female|male|prefer not to say), country (a name in the region map) or region
	 *                  (one of the 21), smoke (yes|no), sbp (80-250 mmHg), diabetes (yes|no), tc (2.0-12.0 mmol/L or
	 *                  77-464 mg/dL, or null / "dont-know" for the non-laboratory chart), tcUnit (mmol/L|mg/dL), bmi
	 *                  (15-60) or heightCm and weightKg, cvd (yes|no), preg (yes|no|not-sure), eat (yes|no|pnts).
	 * @return array stop, model, region, cell, risk, band, urgent, sbp, tcMmolL, bmiShown, whatif[], first,
	 *               weightWhatifHidden, fill, messages (the wording keys to show, in order), version; or error.
	 */
	public static function compute( array $in ) {
		$out = array(
			'stop'               => null,
			'model'              => null,
			'region'             => null,
			'cell'               => null,
			'risk'               => null,
			'band'               => null,
			'urgent'             => false,
			'urgentKey'          => null,
			'sbp'                => null,
			'tcMmolL'            => null,
			'bmiShown'           => null,
			'whatif'             => array(),
			'first'              => null,
			'weightWhatifHidden' => false,
			'fill'               => array(),
			'messages'           => array(),
			'version'            => self::VERSION,
		);

		// Answers that decide the stops and the urgent level first (sheet sections 2 and 4), then the urgent level,
		// then the stops (each still shown after the urgent alert), then the measurements.
		$age = isset( $in['age'] ) && is_numeric( $in['age'] ) ? (float) $in['age'] : null;
		if ( null === $age || $age < 18 || $age > 99 || floor( $age ) !== $age ) {
			return self::invalid( 'age' );
		}
		$age = (int) $age;
		$sex = self::sex( isset( $in['sex'] ) ? $in['sex'] : '' );
		if ( '' === $sex ) {
			return self::invalid( 'sex' );
		}
		// The two safety answers must be given (a missing answer is never taken as "no"); eating defaults to "no".
		$cvd  = self::answer( isset( $in['cvd'] ) ? $in['cvd'] : null, array( 'yes', 'no' ) );
		$preg = self::answer( isset( $in['preg'] ) ? $in['preg'] : null, array( 'yes', 'no', 'notsure' ) );
		$eat  = self::answer( isset( $in['eat'] ) ? $in['eat'] : 'no', array( 'yes', 'no', 'pnts' ) );
		foreach ( array( 'cvd' => $cvd, 'preg' => $preg, 'eat' => $eat ) as $field => $value ) {
			if ( null === $value ) {
				return self::invalid( $field );
			}
		}
		if ( ! isset( $in['sbp'] ) || ! is_numeric( $in['sbp'] ) ) {
			return self::invalid( 'sbp' );
		}
		$sbp = (int) round( (float) $in['sbp'], 0, PHP_ROUND_HALF_UP );
		if ( $sbp < 80 || $sbp > 250 ) {
			return self::invalid( 'sbp' );
		}
		// The urgent level on any blood-pressure input (D5; safety matrix A1), before anything else, stops included;
		// "Not sure" counts as pregnant, so the pregnancy thresholds and wording apply.
		$out['sbp']       = $sbp;
		$out['urgentKey'] = self::urgent_key( $sbp, 'no' !== $preg );
		$out['urgent']    = null !== $out['urgentKey'] && 'bp-check.pregnancy.same-day' !== $out['urgentKey'];
		if ( $age < 40 ) {
			return self::stop( $out, 'under40' );
		}
		if ( $age > 74 ) {
			return self::stop( $out, 'over74' );
		}
		if ( 'yes' === $cvd ) {
			return self::stop( $out, 'cvd' );
		}
		if ( 'no' !== $preg ) {
			return self::stop( $out, 'pregnancy' );
		}
		if ( 'PNTS' === $sex ) {
			return self::stop( $out, 'sex' );
		}

		$region = self::region_of( $in );
		if ( null === $region ) {
			return self::invalid( isset( $in['region'] ) && '' !== $in['region'] && null !== $in['region'] ? 'region' : 'country' );
		}
		$smoke    = self::answer( isset( $in['smoke'] ) ? $in['smoke'] : null, array( 'yes', 'no' ) );
		$diabetes = self::answer( isset( $in['diabetes'] ) ? $in['diabetes'] : null, array( 'yes', 'no' ) );
		if ( null === $smoke ) {
			return self::invalid( 'smoke' );
		}
		if ( null === $diabetes ) {
			return self::invalid( 'diabetes' );
		}
		$tc = self::cholesterol( $in );
		if ( false === $tc ) {
			return self::invalid( 'tc' );
		}
		$bmi = self::bmi( $in );
		if ( false === $bmi || ( null === $tc && null === $bmi ) ) {
			return self::invalid( 'bmi' );
		}

		$model  = null === $tc ? 'nolab' : 'lab';
		$cell   = array(
			'sex'     => 'M' === $sex ? 'men' : 'women',
			'smoking' => 'yes' === $smoke ? 'smoker' : 'non-smoker',
			'age'     => self::AGE_BANDS[ intdiv( $age - 40, 5 ) ],
			'sbp'     => self::band_of( $sbp, self::SBP_BOUNDS, self::SBP_BANDS ),
		);
		if ( 'lab' === $model ) {
			$cell = array( 'diabetes' => $diabetes ) + $cell;
			$cell['tc'] = self::band_of( $tc, self::TC_BOUNDS, self::TC_BANDS );
		} else {
			$cell['bmi'] = self::band_of( $bmi, self::BMI_BOUNDS, self::BMI_BANDS );
		}
		$risk = self::lookup( $region, $model, $cell );
		if ( null === $risk ) {
			return self::invalid( 'region' );
		}

		$out['model']    = $model;
		$out['region']   = $region;
		$out['cell']     = $cell;
		$out['risk']     = $risk;
		$out['band']     = self::band( $risk );
		$out['tcMmolL']  = $tc;
		$out['bmiShown'] = $bmi;

		// What would lower it most (sheet section 3.2): one adjacent cell per factor, on its own. Only a factor whose
		// move lowers the printed figure is offered; $lowest says whether every factor is already in its lowest band.
		$hide_weight = 'no' !== $eat;
		$lowest      = true;
		$hidden      = null;
		$options     = array();
		if ( 'smoker' === $cell['smoking'] ) {
			$lowest    = false;
			$options[] = self::whatif( $region, $model, $cell, 'smoking', 'smoking', 'non-smoker', null, $risk );
		}
		$i = array_search( $cell['sbp'], self::SBP_BANDS, true );
		if ( $i > 0 ) {
			$lowest    = false;
			$options[] = self::whatif( $region, $model, $cell, 'blood-pressure', 'sbp', self::SBP_BANDS[ $i - 1 ], self::SBP_BOUNDS[ $i - 1 ], $risk );
		}
		if ( 'lab' === $model ) {
			$i = array_search( $cell['tc'], self::TC_BANDS, true );
			if ( $i > 0 ) {
				$lowest    = false;
				$options[] = self::whatif( $region, $model, $cell, 'cholesterol', 'tc', self::TC_BANDS[ $i - 1 ], self::TC_BOUNDS[ $i - 1 ], $risk );
			}
		} else {
			$i = array_search( $cell['bmi'], self::BMI_BANDS, true );
			if ( $i > 1 ) { // only above 20-24
				$lowest = false;
				$weight = self::whatif( $region, $model, $cell, 'weight', 'bmi', self::BMI_BANDS[ $i - 1 ], self::BMI_BOUNDS[ $i - 1 ], $risk );
				if ( $hide_weight ) {
					$out['weightWhatifHidden'] = true;
					$hidden                    = $weight;
				} else {
					$options[] = $weight;
				}
			}
		}
		$whatif = array_values(
			array_filter(
				$options,
				function ( $w ) {
					return $w['fall'] > 0;
				}
			)
		);
		// Rank by the fall; the build order above is the tie order (smoking, blood pressure, cholesterol, weight).
		$order = array_flip( self::FACTORS );
		usort(
			$whatif,
			function ( $a, $b ) use ( $order ) {
				return $b['fall'] !== $a['fall'] ? $b['fall'] - $a['fall'] : $order[ $a['factor'] ] - $order[ $b['factor'] ];
			}
		);
		$out['whatif'] = $whatif;
		$out['first']  = $whatif ? $whatif[0]['factor'] : null;

		$out['fill'] = array(
			'risk'   => 0 === $risk ? 'less than 1' : (string) $risk,
			'n'      => (string) $risk,
			'region' => $region,
		);
		if ( $whatif ) {
			$out['fill']['factor']   = $whatif[0]['factor'];
			$out['fill']['band top'] = null === $whatif[0]['under'] ? null : (string) $whatif[0]['under'];
			$out['fill']['new_risk'] = 0 === $whatif[0]['risk'] ? 'less than 1' : (string) $whatif[0]['risk'];
		}

		$keys = array();
		if ( $out['urgent'] ) {
			$keys[] = $out['urgentKey']; // before any result (sheet section 4); the result is still shown below it
		}
		// A 0 % chart cell reads "less than 1 %" and "fewer than 1 in 100" (CW-22, Owner_Questions 100).
		$keys[] = 0 === $out['risk'] ? 'headline.under1' : 'headline';
		$keys[] = 'band.' . $out['band'];
		$keys[] = 0 === $out['risk'] ? 'comparison.under1' : 'comparison';
		$keys[] = 'model.' . $model;
		if ( $whatif ) {
			$keys[] = 'whatif';
		} elseif ( $lowest ) {
			$keys[] = 'whatif.none';
		} elseif ( null === $hidden || $hidden['fall'] <= 0 ) {
			// A factor could move a band but no move lowers the printed figure: neither of the other lines is true, so
			// CW-20 (Owner_Questions 95). When only the hidden weight move would lower it, no line.
			$keys[] = 'whatif.noFall';
		}
		if ( 'yes' === $diabetes ) {
			$keys[] = 'whatif.diabetes';
			$keys[] = 'notes.diabetes';
		}
		$keys[] = 'always';
		$keys[] = 'other-methods';
		$keys[] = 'limits.other-conditions';
		$out['messages'] = $keys;
		return $out;
	}

	/**
	 * The signed text for each wording key. The urgent keys (`bp-check.urgent.alert`, `bp-check.pregnancy.urgent`) take
	 * the frozen text from the safety layer's signed blood-pressure lines (P3-58, FR3-46); a key with no signed text
	 * maps to null, so no unsigned text is ever shown. The pregnancy and "prefer not to say" stops carry CW-01 and
	 * CW-02; `whatif.noFall`, `headline.under1` and `comparison.under1` carry CW-20 and CW-22.
	 *
	 * @param string[] $keys   Wording keys from compute().
	 * @param array    $result The compute() result (unused; the screen fills the placeholders from its `fill`).
	 * @return array key => text or null.
	 */
	public static function wording( array $keys, array $result = array() ) {
		unset( $result );
		$out = array();
		foreach ( $keys as $key ) {
			if ( 0 === strpos( $key, 'bp-check.' ) ) {
				$bp          = constant( self::safety_class() . '::BP_MESSAGES' );
				$out[ $key ] = isset( $bp[ substr( $key, 9 ) ] ) ? $bp[ substr( $key, 9 ) ] : null;
			} elseif ( isset( self::MESSAGES[ $key ] ) ) {
				$out[ $key ] = self::MESSAGES[ $key ];
			} else {
				$out[ $key ] = null;
			}
		}
		return $out;
	}

	/**
	 * The GBD region for a country name (the appendix-1 list), or null.
	 *
	 * @param string $country Country name.
	 */
	public static function region_for_country( $country ) {
		if ( ! is_scalar( $country ) ) {
			return null;
		}
		$map = self::countries();
		$key = self::fold( (string) $country );
		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}
		if ( isset( self::ALIASES[ $key ] ) ) {
			$to = self::fold( self::ALIASES[ $key ] );
			return isset( $map[ $to ] ) ? $map[ $to ] : null;
		}
		return null;
	}

	/** The 21 region names, as the chart file names them. */
	public static function regions() {
		return array_keys( self::cells() );
	}

	/**
	 * The safety layer's urgent key for this systolic reading, or null below the urgent level.
	 *
	 * @param int  $sbp      Systolic, whole mmHg.
	 * @param bool $pregnant Pregnant or breastfeeding, "yes" or "not sure".
	 */
	private static function urgent_key( $sbp, $pregnant ) {
		$level = call_user_func( array( self::safety_class(), 'warning_level' ), 'blood_pressure', array( 'sys' => $sbp, 'dia' => self::NEUTRAL_DIA ), array( 'pregnancy' => $pregnant ) );
		if ( 'urgent' === $level['level'] ) {
			return $level['key'];
		}
		// Owner_Questions 98 (decided 4 Nov 2026): in pregnancy, 140 to 159 shows the safety matrix's same-day line
		// first, as the metabolic check does; it is not the urgent level, so `urgent` stays false.
		return 'bp-check.pregnancy.same-day' === $level['key'] ? $level['key'] : null;
	}

	/** The Stage 3 safety layer, loaded on first use when the engine runs on its own (tests, CLI). */
	private static function safety_class() {
		if ( ! class_exists( 'LLD_Members_Stage3_Safety' ) ) {
			require_once dirname( __DIR__ ) . '/class-safety-stage3.php';
		}
		return 'LLD_Members_Stage3_Safety';
	}

	/**
	 * One what-if: the cell with one factor moved one band down, and the fall.
	 */
	private static function whatif( $region, $model, array $cell, $factor, $axis, $to, $under, $risk ) {
		$moved          = $cell;
		$moved[ $axis ] = $to;
		$new            = self::lookup( $region, $model, $moved );
		return array(
			'factor' => $factor,
			'to'     => $to,
			'risk'   => $new,
			'fall'   => $risk - $new,
			'under'  => $under,
		);
	}

	/**
	 * The printed percentage in one cell, or null.
	 */
	private static function lookup( $region, $model, array $cell ) {
		$charts = self::cells();
		if ( ! isset( $charts[ $region ] ) ) {
			return null;
		}
		if ( 'lab' === $model ) {
			$path = array( 'laboratory', $cell['diabetes'], $cell['sex'], $cell['smoking'], $cell['age'], $cell['sbp'], $cell['tc'] );
		} else {
			$path = array( 'non-laboratory', 'n/a', $cell['sex'], $cell['smoking'], $cell['age'], $cell['sbp'], $cell['bmi'] );
		}
		$node = $charts[ $region ];
		foreach ( $path as $step ) {
			if ( ! is_array( $node ) || ! isset( $node[ $step ] ) ) {
				return null;
			}
			$node = $node[ $step ];
		}
		return is_int( $node ) ? $node : null;
	}

	/** Risk band on the printed whole number (D3-13). */
	private static function band( $risk ) {
		if ( $risk < 5 ) {
			return 'low';
		}
		if ( $risk < 10 ) {
			return 'moderate';
		}
		if ( $risk < 20 ) {
			return 'high';
		}
		return 'very-high';
	}

	/**
	 * The band label for a value: below the first bound is the first label, and so on.
	 */
	private static function band_of( $value, array $bounds, array $labels ) {
		$i = 0;
		foreach ( $bounds as $bound ) {
			if ( $value >= $bound - 1e-9 ) {
				++$i;
			}
		}
		return $labels[ $i ];
	}

	/**
	 * Total cholesterol in mmol/L to 1 dp (half-up), null for "don't know", false when out of range.
	 */
	private static function cholesterol( array $in ) {
		$v = isset( $in['tc'] ) ? $in['tc'] : null;
		if ( null === $v || '' === $v || ( is_string( $v ) && in_array( strtolower( str_replace( array( ' ', '-', '_', "'" ), '', $v ) ), array( 'dontknow', 'unknown', 'idontknow' ), true ) ) ) {
			return null;
		}
		if ( ! is_numeric( $v ) ) {
			return false;
		}
		$v    = (float) $v;
		$unit = isset( $in['tcUnit'] ) && is_scalar( $in['tcUnit'] ) ? strtolower( str_replace( ' ', '', (string) $in['tcUnit'] ) ) : 'mmol/l';
		if ( 'mg/dl' === $unit ) {
			if ( $v < 77 || $v > 464 ) {
				return false;
			}
			$v = $v / self::MG_DL_PER_MMOL; // unrounded, then 1 dp (sheet section 6)
		} elseif ( 'mmol/l' === $unit || '' === $unit ) {
			if ( $v < 2.0 || $v > 12.0 ) {
				return false;
			}
		} else {
			return false;
		}
		return round( $v, 1, PHP_ROUND_HALF_UP );
	}

	/**
	 * BMI to 1 dp (half-up) from bmi or heightCm and weightKg; null when none given, false when out of 15-60.
	 */
	private static function bmi( array $in ) {
		if ( isset( $in['bmi'] ) && null !== $in['bmi'] && '' !== $in['bmi'] ) {
			if ( ! is_numeric( $in['bmi'] ) ) {
				return false;
			}
			$b = (float) $in['bmi'];
		} elseif ( isset( $in['heightCm'], $in['weightKg'] ) && is_numeric( $in['heightCm'] ) && is_numeric( $in['weightKg'] ) && (float) $in['heightCm'] > 0 ) {
			$m = (float) $in['heightCm'] / 100;
			$b = (float) $in['weightKg'] / ( $m * $m );
		} else {
			return null;
		}
		$b = round( $b, 1, PHP_ROUND_HALF_UP );
		return $b < 15 || $b > 60 ? false : $b;
	}

	/**
	 * The region from `region` (one of the 21) or `country` (the appendix-1 list).
	 */
	private static function region_of( array $in ) {
		if ( isset( $in['region'] ) && is_scalar( $in['region'] ) && '' !== $in['region'] ) {
			foreach ( self::regions() as $r ) {
				if ( self::fold( $r ) === self::fold( (string) $in['region'] ) ) {
					return $r;
				}
			}
			return null;
		}
		return isset( $in['country'] ) ? self::region_for_country( $in['country'] ) : null;
	}

	/** @return array The decoded chart cells (region => chart => …). */
	private static function cells() {
		if ( null === self::$cells ) {
			$doc         = json_decode( (string) file_get_contents( __DIR__ . '/' . self::DATA_CELLS ), true );
			self::$cells = is_array( $doc ) && isset( $doc['charts'] ) ? $doc['charts'] : array();
		}
		return self::$cells;
	}

	/** @return array Folded country name => region. */
	private static function countries() {
		if ( null === self::$countries ) {
			$doc             = json_decode( (string) file_get_contents( __DIR__ . '/' . self::DATA_REGIONS ), true );
			self::$countries = array();
			if ( is_array( $doc ) && isset( $doc['countries'] ) ) {
				foreach ( $doc['countries'] as $name => $row ) {
					self::$countries[ self::fold( $name ) ] = $row['gbd_region'];
				}
			}
		}
		return self::$countries;
	}

	/**
	 * A name folded for matching: accents dropped, lower case, apostrophes (straight or curly) removed, "&" as "and",
	 * every other mark and run of spaces as one space, a leading "the" dropped and "St" as "Saint".
	 */
	private static function fold( $s ) {
		$s = strtr( (string) $s, self::PLAIN );
		$s = strtolower( str_replace( array( "'", "\u{2019}", "\u{2018}", '`', "\u{00B4}", "\u{02BC}" ), '', $s ) );
		$s = str_replace( '&', ' and ', $s );
		$s = trim( preg_replace( '/[^a-z0-9]+/', ' ', $s ) );
		$s = preg_replace( '/^the /', '', $s );
		return preg_replace( '/^st /', 'saint ', $s );
	}

	/**
	 * @param array  $out  Result so far.
	 * @param string $what under40, over74, cvd, pregnancy or sex.
	 */
	private static function stop( array $out, $what ) {
		$out['stop']     = $what;
		$out['messages'] = null === $out['urgentKey'] ? array( 'stops.' . $what ) : array( $out['urgentKey'], 'stops.' . $what );
		return $out;
	}

	/**
	 * @param string $field The field that failed.
	 */
	private static function invalid( $field ) {
		return array(
			'error'   => $field,
			'version' => self::VERSION,
		);
	}

	/**
	 * @param mixed $value Sex answer in any accepted spelling.
	 * @return string F, M, PNTS or ''.
	 */
	private static function sex( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$v = strtolower( trim( (string) $value ) );
		if ( in_array( $v, array( 'f', 'female', 'woman', 'women' ), true ) ) {
			return 'F';
		}
		if ( in_array( $v, array( 'm', 'male', 'man', 'men' ), true ) ) {
			return 'M';
		}
		if ( in_array( $v, array( 'pnts', 'prefer not to say', 'prefer_not', 'prefer-not-to-say', 'prefer_not_to_say' ), true ) ) {
			return 'PNTS';
		}
		return '';
	}

	/**
	 * @param mixed    $value   Answer.
	 * @param string[] $allowed Accepted answers.
	 */
	private static function answer( $value, array $allowed ) {
		if ( is_bool( $value ) ) {
			$value = $value ? 'yes' : 'no';
		}
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$v = strtolower( str_replace( array( ' ', '-', '_' ), '', trim( (string) $value ) ) );
		if ( 'prefernottosay' === $v || 'prefernot' === $v ) {
			$v = 'pnts';
		}
		$v = 'true' === $v ? 'yes' : ( 'false' === $v ? 'no' : $v ); // true / false, as booleans or as text
		return in_array( $v, $allowed, true ) ? $v : null;
	}
}
