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

	/** SBP at or above which the frozen urgent-care wording comes first (sheet section 4, FR3-46). */
	const URGENT_SBP = 180;

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
		'always'                  => "This is an estimate for people without known heart disease, from the World Health Organization's 2019 risk charts for {region}. It doesn't replace an assessment by your doctor, who may use a method made for your country.",
		'model.lab'               => 'Based on a blood test (total cholesterol)',
		'model.nolab'             => 'Without a blood test (uses BMI instead). Ask your doctor for a cholesterol test for a closer estimate.',
		'other-methods'           => 'Your doctor may use a different method for your country (for example QRISK in the UK, PREVENT or the ACC/AHA calculator in the US, SCORE2 in Europe). Those are calibrated to your country and are the ones to use for treatment decisions.',
		'stops.under40'           => "This check isn't suitable under 40. The WHO charts estimate risk from age 40. Most of what lowers risk later, not smoking, moving more, eating well and knowing your blood pressure, works at any age.",
		'stops.over74'            => "This check isn't suitable over 74. Your doctor can assess your risk with methods made for older adults.",
		'stops.cvd'               => "This check is for people who haven't had a heart attack, stroke or heart surgery. Your doctor uses different methods for you, and treatment is usually already recommended.",
		'notes.diabetes'          => 'With diabetes, your doctor may use a diabetes-specific assessment and recommend treatment whatever the number.',
		'limits.other-conditions' => "The WHO charts don't include family history, kidney disease or some other conditions. If any of these apply, your real risk may be higher; your doctor can assess it.",
	);

	/**
	 * The key of the frozen global urgent-care wording (FR3-46), as the Stage 3 safety layer names it
	 * (LLD_Members_Stage3_Safety::warning_level). It is never retyped here: wording() takes the text from the Blood
	 * Pressure Reading Check's signed `urgent.alert` (P3-58 section 4.1) when that engine is loaded.
	 */
	const URGENT_KEY = 'global_urgent';

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

		// Answers that decide the stops first (sheet section 2 and 4), then the stops, then the measurements.
		$age = isset( $in['age'] ) && is_numeric( $in['age'] ) ? (float) $in['age'] : null;
		if ( null === $age || $age < 18 || $age > 99 || floor( $age ) !== $age ) {
			return self::invalid( 'age' );
		}
		$age = (int) $age;
		$sex = self::sex( isset( $in['sex'] ) ? $in['sex'] : '' );
		if ( '' === $sex ) {
			return self::invalid( 'sex' );
		}
		$cvd  = self::answer( isset( $in['cvd'] ) ? $in['cvd'] : 'no', array( 'yes', 'no' ) );
		$preg = self::answer( isset( $in['preg'] ) ? $in['preg'] : 'no', array( 'yes', 'no', 'notsure' ) );
		$eat  = self::answer( isset( $in['eat'] ) ? $in['eat'] : 'no', array( 'yes', 'no', 'pnts' ) );
		foreach ( array( 'cvd' => $cvd, 'preg' => $preg, 'eat' => $eat ) as $field => $value ) {
			if ( null === $value ) {
				return self::invalid( $field );
			}
		}
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
		if ( ! isset( $in['sbp'] ) || ! is_numeric( $in['sbp'] ) ) {
			return self::invalid( 'sbp' );
		}
		$sbp = (int) round( (float) $in['sbp'], 0, PHP_ROUND_HALF_UP );
		if ( $sbp < 80 || $sbp > 250 ) {
			return self::invalid( 'sbp' );
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
		$out['urgent']   = $sbp >= self::URGENT_SBP;
		$out['sbp']      = $sbp;
		$out['tcMmolL']  = $tc;
		$out['bmiShown'] = $bmi;

		// What would lower it most (sheet section 3.2): one adjacent cell per factor, on its own.
		$hide_weight = 'no' !== $eat;
		$movable     = false;
		$whatif      = array();
		if ( 'smoker' === $cell['smoking'] ) {
			$whatif[] = self::whatif( $region, $model, $cell, 'smoking', 'smoking', 'non-smoker', null, $risk );
		}
		$i = array_search( $cell['sbp'], self::SBP_BANDS, true );
		if ( $i > 0 ) {
			$whatif[] = self::whatif( $region, $model, $cell, 'blood-pressure', 'sbp', self::SBP_BANDS[ $i - 1 ], self::SBP_BOUNDS[ $i - 1 ], $risk );
		}
		if ( 'lab' === $model ) {
			$i = array_search( $cell['tc'], self::TC_BANDS, true );
			if ( $i > 0 ) {
				$whatif[] = self::whatif( $region, $model, $cell, 'cholesterol', 'tc', self::TC_BANDS[ $i - 1 ], self::TC_BOUNDS[ $i - 1 ], $risk );
			}
		} else {
			$i = array_search( $cell['bmi'], self::BMI_BANDS, true );
			if ( $i > 1 ) { // only above 20-24
				if ( $hide_weight ) {
					$out['weightWhatifHidden'] = true;
					$movable                   = true;
				} else {
					$whatif[] = self::whatif( $region, $model, $cell, 'weight', 'bmi', self::BMI_BANDS[ $i - 1 ], self::BMI_BOUNDS[ $i - 1 ], $risk );
				}
			}
		}
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
		$movable       = $movable || (bool) $whatif;

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
			$keys[] = self::URGENT_KEY; // before any result (sheet section 4)
		}
		$keys[] = 'headline';
		$keys[] = 'band.' . $out['band'];
		$keys[] = 'comparison';
		$keys[] = 'model.' . $model;
		if ( $whatif ) {
			$keys[] = 'whatif';
		} elseif ( ! $movable ) {
			$keys[] = 'whatif.none';
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
	 * The signed text for each wording key. The urgent-care key takes the frozen global text from the Blood Pressure
	 * Reading Check (P3-58) when it is loaded; a key with no signed text here (the pregnancy and "prefer not to say"
	 * stops, which the sheet describes but whose exact words are not in the approval record) maps to null, so no
	 * unsigned text is ever shown.
	 *
	 * @param string[] $keys   Wording keys from compute().
	 * @param array    $result The compute() result (unused; the screen fills the placeholders from its `fill`).
	 * @return array key => text or null.
	 */
	public static function wording( array $keys, array $result = array() ) {
		unset( $result );
		$out = array();
		foreach ( $keys as $key ) {
			if ( self::URGENT_KEY === $key ) {
				$out[ $key ] = self::urgent_text();
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
		return isset( $map[ $key ] ) ? $map[ $key ] : null;
	}

	/** The 21 region names, as the chart file names them. */
	public static function regions() {
		return array_keys( self::cells() );
	}

	/**
	 * The frozen urgent-care text, or null when the Blood Pressure Reading Check is not loaded.
	 */
	private static function urgent_text() {
		if ( class_exists( 'LLD_Members_BP_Check' ) ) {
			$m = LLD_Members_BP_Check::signed_text();
			if ( isset( $m['urgent.alert'] ) ) {
				return $m['urgent.alert'];
			}
		}
		return null;
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

	/** Lower case, with any dash spelling and spaces made the same, for name matching. */
	private static function fold( $s ) {
		return strtolower( preg_replace( '/[\s\x{2010}\x{2011}\x{2012}\x{2013}\x{2014}-]+/u', '-', trim( $s ) ) );
	}

	/**
	 * @param array  $out  Result so far.
	 * @param string $what under40, over74, cvd, pregnancy or sex.
	 */
	private static function stop( array $out, $what ) {
		$out['stop']     = $what;
		$out['messages'] = array( 'stops.' . $what );
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
		return in_array( $v, $allowed, true ) ? $v : null;
	}
}
