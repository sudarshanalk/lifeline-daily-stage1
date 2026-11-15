<?php
/**
 * Healthy Weight programme, "where you are now" (P3-35; FR3-97; rules R-HW-NUM-01 and R-HW-NUM-02 of the signed rule
 * catalogue v1.3, section 9; method sheet `04 Health Tools/Stage 3/P3-35-Where-You-Are-Now-Method-Sheet.md`).
 *
 * A PHP port of the Healthy Weight Check's frozen engine (`site/public_html/tools/healthy-weight-tool.html`, block
 * "(2) HW_ENGINE — FROZEN", FR2-39, AS-19): BMI to 1 decimal half-up, the standard and lower cut-off bands, the
 * waist-to-height bands (not shown at BMI 35 or more, a neutral line below 0.40) and the healthy range rounded
 * inwards. The Check itself is never edited; the tests replay every frozen fixture in `fixtures/healthy-weight/`
 * through this class and require the same result (TC-FR3-97-AS-03). `validate()` ports the Check's form checks and
 * unit conversion so the invalid-input and imperial fixtures replay too.
 *
 * Pure: no WordPress, no database.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Check's engine, ported line by line, plus the D3-54 background choice.
 */
class LLD_Members_HW_Where {

	/** The Check's lower cut-off backgrounds (its own question's values). */
	const ADJUSTED = array( 'Indian', 'Pakistani', 'Bangladeshi', 'OtherSouthAsian', 'Chinese', 'OtherAsian', 'MiddleEastern', 'BlackAfrican', 'BlackCaribbean' );

	/** Band edges: underweight below the first, then healthy, overweight, obese1, obese2, obese3. */
	const CUTS = array(
		'standard' => array( 18.5, 25, 30, 35, 40 ),
		'adjusted' => array( 18.5, 23, 27.5, 32.5, 37.5 ),
	);

	const NAMES = array( 'underweight', 'healthy', 'overweight', 'obese1', 'obese2', 'obese3' );

	const EPS = 1e-9;

	/** Stage 2 health-profile backgrounds (the diabetes tool's list) that set the lower cut-offs (D3-54 A). */
	const PROFILE_ADJUSTED = array( 'Indian', 'Pakistani', 'Bangladeshi', 'OtherAsian', 'BlackCaribbean', 'BlackAfrican', 'Chinese' );

	/** Unit factors, as the Check's state block. */
	const IN_CM = 2.54;
	const FT_CM = 30.48;
	const LB_KG = 0.45359237;
	const ST_KG = 6.35029318;

	/**
	 * Round half up to $dp decimals, exactly as the Check (floor(x · 10^dp + 0.5 + 1e-9) / 10^dp).
	 *
	 * @param float $x  Value.
	 * @param int   $dp Decimals.
	 * @return float
	 */
	public static function round_half_up( $x, $dp ) {
		$f = pow( 10, $dp );
		return floor( $x * $f + 0.5 + self::EPS ) / $f;
	}

	/**
	 * BMI before rounding: kg × 10000 ÷ cm².
	 *
	 * @param float $weight_kg Weight.
	 * @param float $height_cm Height.
	 * @return float
	 */
	public static function bmi_of( $weight_kg, $height_cm ) {
		return $weight_kg * 10000 / ( $height_cm * $height_cm );
	}

	/**
	 * Is the background one with the lower cut-offs (the Check's own question)?
	 *
	 * @param string|null $bg    Check background value (Indian, MiddleEastern, Mixed, NotSay, White, …).
	 * @param string|null $mixed Mixed follow-up: yes, no or notsure.
	 * @return bool
	 */
	public static function adjusted( $bg, $mixed = null ) {
		return in_array( $bg, self::ADJUSTED, true ) || ( 'Mixed' === $bg && 'yes' === $mixed );
	}

	/**
	 * The band for a BMI already rounded to 1 decimal.
	 *
	 * @param float $bmi1 BMI, 1 decimal.
	 * @param bool  $adj  Lower cut-offs.
	 * @return string underweight|healthy|overweight|obese1|obese2|obese3
	 */
	public static function category( $bmi1, $adj ) {
		$c = self::CUTS[ $adj ? 'adjusted' : 'standard' ];
		$i = 0;
		while ( $i < count( $c ) && $bmi1 >= $c[ $i ] ) {
			$i++;
		}
		return self::NAMES[ $i ];
	}

	/**
	 * Waist-to-height band (R-HW-NUM-01): not shown at BMI 35 or more; below 0.40 a neutral line; under 0.50 healthy;
	 * under 0.60 increased; otherwise high.
	 *
	 * @param float $ratio2 Ratio, 2 decimals.
	 * @param float $bmi1   BMI, 1 decimal.
	 * @return string notShown|below|healthy|increased|high
	 */
	public static function waist_band( $ratio2, $bmi1 ) {
		if ( $bmi1 >= 35 ) {
			return 'notShown';
		}
		if ( $ratio2 < 0.40 ) {
			return 'below';
		}
		if ( $ratio2 < 0.50 ) {
			return 'healthy';
		}
		if ( $ratio2 < 0.60 ) {
			return 'increased';
		}
		return 'high';
	}

	/**
	 * Healthy weight range for height (R-HW-NUM-02): every weight whose BMI, as shown, is healthy, from 18.45 up to
	 * (not including) 24.95, or 22.95 with the lower cut-offs; ends rounded inwards to 0.1 kg or whole pounds.
	 *
	 * @param float $height_cm Height.
	 * @param bool  $adj       Lower cut-offs.
	 * @return array{lowKg:float,highKg:float,lowLb:int,highLb:int}
	 */
	public static function range( $height_cm, $adj ) {
		$m2 = pow( $height_cm / 100, 2 );
		$lo = 18.45 * $m2;
		$hi = ( $adj ? 22.95 : 24.95 ) * $m2;
		return array(
			'lowKg'  => ceil( $lo * 10 - self::EPS ) / 10,
			'highKg' => ( ceil( $hi * 10 - self::EPS ) - 1 ) / 10,
			'lowLb'  => (int) ceil( $lo / self::LB_KG - self::EPS ),
			'highLb' => (int) ceil( $hi / self::LB_KG - self::EPS ) - 1,
		);
	}

	/**
	 * The Check's assess(), unchanged in behaviour.
	 *
	 * @param array $i age, preg (yes|no|notsure), eat (yes|no), heightCm, weightKg, waistCm|null, bg, mixed, loss (yes|no).
	 * @return array Either {stop: under18|pregnancy|eating}, or {stop: null, bmi, category, adjusted, range, waist
	 *               ({ratio, band}|null), weightLoss, notes[], onward}.
	 */
	public static function assess( array $i ) {
		$age = isset( $i['age'] ) ? $i['age'] : null;
		if ( $age < 18 ) {
			return array( 'stop' => 'under18' );
		}
		$preg = isset( $i['preg'] ) ? $i['preg'] : null;
		if ( 'yes' === $preg || 'notsure' === $preg ) {
			return array( 'stop' => 'pregnancy' );
		}
		if ( isset( $i['eat'] ) && 'yes' === $i['eat'] ) {
			return array( 'stop' => 'eating' );
		}
		$adj  = self::adjusted( isset( $i['bg'] ) ? $i['bg'] : null, isset( $i['mixed'] ) ? $i['mixed'] : null );
		$bmi1 = self::round_half_up( self::bmi_of( (float) $i['weightKg'], (float) $i['heightCm'] ), 1 );
		$cat  = self::category( $bmi1, $adj );
		$out  = array(
			'stop'       => null,
			'bmi'        => $bmi1,
			'category'   => $cat,
			'adjusted'   => $adj,
			'range'      => self::range( (float) $i['heightCm'], $adj ),
			'waist'      => null,
			'weightLoss' => isset( $i['loss'] ) && 'yes' === $i['loss'],
			'notes'      => array(),
		);
		if ( isset( $i['waistCm'] ) && null !== $i['waistCm'] ) {
			$r2           = self::round_half_up( (float) $i['waistCm'] / (float) $i['heightCm'], 2 );
			$out['waist'] = array(
				'ratio' => $r2,
				'band'  => self::waist_band( $r2, $bmi1 ),
			);
		}
		if ( 'underweight' === $cat ) {
			$out['notes'][] = 'underweight';
		}
		if ( 'obese3' === $cat ) {
			$out['notes'][] = 'class3';
		}
		if ( $age >= 65 ) {
			$out['notes'][] = 'older';
		}
		$out['onward'] = ! $out['weightLoss'] && 'underweight' !== $cat;
		return $out;
	}

	/**
	 * The Check's form checks and unit conversion (its collect()), so a raw answer set can be replayed: returns
	 * {errors: [keys in the Check's order]} or the assess() result.
	 *
	 * @param array $in age, preg, eat, bg, mixed, loss, and heightCm or heightFt/heightIn, weightKg or weightSt/weightLb,
	 *                  waistCm or waistIn (each optional, as the form).
	 * @return array
	 */
	public static function validate( array $in ) {
		$errors = array();
		$age    = null;
		$raw    = array_key_exists( 'age', $in ) && null !== $in['age'] && '' !== $in['age'] ? $in['age'] : null;
		if ( null === $raw || ! is_numeric( $raw ) || $raw < 1 || floor( (float) $raw ) != (float) $raw ) { // phpcs:ignore Universal.Operators.StrictComparisons
			$errors[] = 'age';
		} elseif ( $raw > 99 ) {
			$errors[] = 'age';
		} else {
			$age = 0 + $raw;
		}
		$preg = isset( $in['preg'] ) ? $in['preg'] : null;
		$eat  = isset( $in['eat'] ) ? $in['eat'] : null;
		if ( ! $preg ) {
			$errors[] = 'preg';
		}
		if ( ! $eat ) {
			$errors[] = 'eat';
		}
		if ( null !== $age && $preg && $eat && ( $age < 18 || 'no' !== $preg || 'yes' === $eat ) ) {
			return self::assess(
				array(
					'age'  => $age,
					'preg' => $preg,
					'eat'  => $eat,
				)
			);
		}
		$ht    = self::length( $in, 'heightCm', 'heightFt', 'heightIn' );
		$wt    = self::mass( $in );
		$waist = self::length( $in, 'waistCm', null, 'waistIn' );
		if ( ! ( null !== $ht && $ht >= 120 && $ht <= 230 ) ) {
			$errors[] = 'ht';
		}
		if ( ! ( null !== $wt && $wt >= 25 && $wt <= 300 ) ) {
			$errors[] = 'wt';
		}
		if ( null !== $waist && ! ( $waist >= 50 && $waist <= 200 ) ) {
			$errors[] = 'waist';
		}
		$bg = isset( $in['bg'] ) ? $in['bg'] : null;
		if ( ! $bg ) {
			$errors[] = 'bg';
		}
		if ( 'Mixed' === $bg && empty( $in['mixed'] ) ) {
			$errors[] = 'mixed';
		}
		if ( empty( $in['loss'] ) ) {
			$errors[] = 'loss';
		}
		if ( $errors ) {
			return array( 'errors' => $errors );
		}
		return self::assess(
			array(
				'age'      => $age,
				'preg'     => $preg,
				'eat'      => $eat,
				'heightCm' => $ht,
				'weightKg' => $wt,
				'waistCm'  => $waist,
				'bg'       => $bg,
				'mixed'    => 'Mixed' === $bg ? $in['mixed'] : null,
				'loss'     => $in['loss'],
			)
		);
	}

	/**
	 * Which BMI bands a member gets (D3-54 A, method sheet section 3).
	 *
	 * @param string|null       $ethnicity The Stage 2 health profile's family background (WhiteNA, Indian, Pakistani,
	 *                                     Bangladeshi, OtherAsian, BlackCaribbean, BlackAfrican, Chinese, Other, or empty).
	 * @param array|string|null $answer    The programme answer `bmi_background` from the Check's own question: a value
	 *                                     (Indian, MiddleEastern, NotSay, …) or {bg, mixed}; null when not asked yet.
	 * @return string adjusted (lower cut-offs) | standard | ask (ask the Check's background question once).
	 */
	public static function background_group( $ethnicity, $answer = null ) {
		if ( in_array( (string) $ethnicity, self::PROFILE_ADJUSTED, true ) ) {
			return 'adjusted'; // the profile names a lower cut-off background: no extra question
		}
		$bg    = is_array( $answer ) ? ( isset( $answer['bg'] ) ? $answer['bg'] : null ) : $answer;
		$mixed = is_array( $answer ) && isset( $answer['mixed'] ) ? $answer['mixed'] : null;
		if ( null === $bg || '' === $bg ) {
			return 'ask';
		}
		if ( 'Mixed' === $bg && null === $mixed ) {
			return 'ask'; // the mixed follow-up is part of the question
		}
		return self::adjusted( $bg, $mixed ) ? 'adjusted' : 'standard';
	}

	/**
	 * "Where you are now" for a member (R-HW-NUM-01, -02; FR3-97): the Check's engine on the member's saved values.
	 *
	 * @param array $in weight_kg, height_cm, waist_cm (null when skipped), age, pregnant (yes|no|notsure|breastfeeding),
	 *                  eating_disorder (yes|no|pnts), ethnicity (profile), bmi_background (programme answer).
	 * @return array {rule: R-HW-NUM-01, stop: null|under18|pregnancy|eating, ask_background: bool, group, bmi,
	 *               category, adjusted, range {lowKg, highKg, lowLb, highLb}, waist {ratio, band}|null, notes[]}.
	 *               With a stop, no BMI, band or range is returned.
	 */
	public static function where_now( array $in ) {
		$group = self::background_group( isset( $in['ethnicity'] ) ? $in['ethnicity'] : null, isset( $in['bmi_background'] ) ? $in['bmi_background'] : null );
		$preg  = isset( $in['pregnant'] ) ? (string) $in['pregnant'] : 'no';
		$check = array(
			'age'      => isset( $in['age'] ) ? (int) $in['age'] : null,
			'preg'     => in_array( $preg, array( 'yes', 'breastfeeding' ), true ) ? 'yes' : ( 'notsure' === $preg ? 'notsure' : 'no' ),
			'eat'      => isset( $in['eating_disorder'] ) && 'yes' === $in['eating_disorder'] ? 'yes' : 'no',
			'heightCm' => isset( $in['height_cm'] ) ? (float) $in['height_cm'] : null,
			'weightKg' => isset( $in['weight_kg'] ) ? (float) $in['weight_kg'] : null,
			'waistCm'  => isset( $in['waist_cm'] ) && '' !== $in['waist_cm'] ? (float) $in['waist_cm'] : null,
			'bg'       => 'adjusted' === $group ? 'Indian' : 'White', // only the band set matters here
			'loss'     => 'no',
		);
		$r = self::assess( $check );
		if ( null !== $r['stop'] ) {
			return array(
				'rule'           => 'R-HW-NUM-01',
				'stop'           => $r['stop'],
				'ask_background' => false,
				'group'          => $group,
			);
		}
		return array(
			'rule'           => 'R-HW-NUM-01',
			'stop'           => null,
			'ask_background' => 'ask' === $group,
			'group'          => 'ask' === $group ? 'standard' : $group, // until answered, the standard bands
			'bmi'            => $r['bmi'],
			'category'       => $r['category'],
			'adjusted'       => $r['adjusted'],
			'range'          => $r['range'],
			'waist'          => $r['waist'],
			'notes'          => $r['notes'],
		);
	}

	/**
	 * A length in cm from cm, ft + in, or in, as the Check's readLen(); null when nothing was entered.
	 *
	 * @param array       $in  Answers.
	 * @param string      $cm  Centimetre key.
	 * @param string|null $ft  Feet key (with $inch as inches), or null.
	 * @param string      $inch Inches key.
	 * @return float|null
	 */
	private static function length( array $in, $cm, $ft, $inch ) {
		if ( null !== $ft && isset( $in[ $ft ] ) ) {
			return (float) $in[ $ft ] * self::FT_CM + ( isset( $in[ $inch ] ) ? (float) $in[ $inch ] : 0 ) * self::IN_CM;
		}
		if ( null === $ft && isset( $in[ $inch ] ) ) {
			return (float) $in[ $inch ] * self::IN_CM;
		}
		if ( isset( $in[ $cm ] ) && is_numeric( $in[ $cm ] ) ) {
			return (float) $in[ $cm ];
		}
		return null;
	}

	/**
	 * A weight in kg from kg or st + lb, as the Check's readKg(); null when nothing was entered.
	 *
	 * @param array $in Answers.
	 * @return float|null
	 */
	private static function mass( array $in ) {
		if ( isset( $in['weightSt'] ) || isset( $in['weightLb'] ) ) {
			return ( isset( $in['weightSt'] ) ? (float) $in['weightSt'] : 0 ) * self::ST_KG + ( isset( $in['weightLb'] ) ? (float) $in['weightLb'] : 0 ) * self::LB_KG;
		}
		if ( isset( $in['weightKg'] ) && is_numeric( $in['weightKg'] ) ) {
			return (float) $in['weightKg'];
		}
		return null;
	}
}
