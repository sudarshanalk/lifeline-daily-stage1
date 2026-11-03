<?php
/**
 * Daily Calorie Target engine (P3-55, FR3-47, FR3-28), written from the owner-signed method sheet
 * `04 Health Tools/Stage 3/P3-55-Daily-Calorie-Target-Method-Sheet.md` (approval record
 * `approvals/stage3/calorie-target.json`, fixtures `fixtures/calorie-target/`).
 *
 * Pure arithmetic and rules: no WordPress, no database, no member lookup. The caller (the members' interface, the
 * plan, the nightly run) supplies the answers and the member's profile flags and decides what to store.
 *
 * Mifflin-St Jeor (D3-01) × activity factor, minus 1,100 kcal a day per kg a week of the chosen rate, floored at the
 * safe minimum (D3-02: 1,200 kcal women, 1,500 kcal men; "prefer not to say" uses the female equation and floor,
 * D3-03), rounded to the nearest 10 kcal half-up. Rates follow the Goal Weight Timeline cap (P3-56):
 * min(1.0, 1 % of weight); 65+ : 0.25 and 0.5 only. Every stop wins over every note. Frozen: any change to these
 * rules or the wording needs a new decision and a new version of the approval record (NFR3-14).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The engine. `compute()` takes the answers and returns the result the screens, the plan and the tests read.
 */
class LLD_Members_Calorie_Target {

	const VERSION = '1.0';

	const KCAL_PER_KG_WEEK = 1100;
	const FLOOR_FEMALE     = 1200;
	const FLOOR_MALE       = 1500;
	const FIBRE_G          = 30;
	const RATES            = array( 0.25, 0.5, 0.75, 1.0 );
	const FACTORS          = array(
		'low'         => 1.2,
		'some'        => 1.375,
		'active'      => 1.55,
		'very active' => 1.725,
	);

	/**
	 * The signed wording (sheet section 7 and the notes in section 2). Keys and text are hashed in the approval
	 * record's copy_hashes; the test checks them.
	 */
	const MESSAGES = array(
		'headline'            => 'About **{target} kcal** a day',
		'headline_subline'    => 'to {lose about {rate} kg a week | keep your weight steady | gain about {rate} kg a week}',
		'why_this_number'     => 'Your body uses about {tdee} kcal a day at your activity level. {A deficit of about {deficit} kcal a day gives a loss of about {rate} kg a week.}',
		'floor_note'          => 'To stay above the safe minimum of {min} kcal a day, your weekly loss would be about {rate} kg. A slower, steadier plan works better anyway.',
		'no_loss_note'        => "A calorie-cutting target isn't suitable at your size. If you want to change your weight, your doctor or a dietitian can help.",
		'sex_not_said_note'   => "We've used the lower of the two formulas. Choose a sex above for a closer estimate.",
		'stop.under18'        => 'This calculator is for adults. Calorie needs for children and teenagers depend on growth, and your doctor, school nurse or a dietitian can advise.',
		'stop.pregnancy'      => "This calculator isn't suitable right now. Calorie needs change during pregnancy and breastfeeding, and cutting calories isn't advised. Your midwife or doctor can advise you.",
		'stop.eating'         => "Thank you for telling us. Calorie numbers aren't the most helpful thing to focus on, so we haven't shown any. Your doctor can help, and you can find free, confidential support near you at findahelpline.com.",
		'eating_not_said_note' => "We've shown the calories that keep your weight steady.",
		'diabetes_note'       => 'If you take insulin or medicine that can cause low blood sugar, talk to your doctor or diabetes team before cutting calories, because your medicine may need adjusting.',
		'over65_note'         => 'After 65, keeping muscle matters more than cutting calories. If you choose to lose weight, keep protein up and stay active. A slower rate (0.25 to 0.5 kg a week) suits most people.',
		'estimate_note'       => 'This is an estimate. Real needs vary by about ±10 %. Adjust from how your weight actually changes over a few weeks.',
		'hub_card'            => 'Daily Calorie Target · About 2 minutes · Age, sex, height and weight, activity, goal · Adults 18 to 99. Not for pregnancy or breastfeeding. Not a diagnosis.',
	);

	/**
	 * Safe minimum for a sex answer: 1,500 kcal for male, 1,200 kcal for female or "prefer not to say".
	 *
	 * @param string $sex F, M or PNTS (female, male, prefer not to say also accepted).
	 */
	public static function floor_for( $sex ) {
		return 'M' === self::sex( $sex ) ? self::FLOOR_MALE : self::FLOOR_FEMALE;
	}

	/**
	 * Work out the result.
	 *
	 * @param array $in sex, age, heightCm or heightFtIn {ft,in}, weightKg or weightStLb {st,lb} or weightLb,
	 *                  activity (low|some|active|very active), goal (lose|maintain|gain), rateKgWeek,
	 *                  pregnant (yes|no|notsure), eatingDisorder (yes|no|pnts), type2Diabetes, kidneyDisease,
	 *                  memberTarget (a Premium member's own target to check against the floor).
	 * @return array stop, refused, rmr, tdee, raw, targetKcal, achievableRate, notes[], maintenanceOnly, offeredRate,
	 *               floor, bmi, goal, messages (the wording keys to show, in order), version; or error.
	 */
	public static function compute( array $in ) {
		$sex   = self::sex( isset( $in['sex'] ) ? $in['sex'] : '' );
		$floor = self::floor_for( $sex );
		$out   = array(
			'stop'            => null,
			'refused'         => false,
			'rmr'             => null,
			'tdee'            => null,
			'raw'             => null,
			'targetKcal'      => null,
			'achievableRate'  => null,
			'notes'           => array(),
			'maintenanceOnly' => false,
			'offeredRate'     => null,
			'floor'           => $floor,
			'bmi'             => null,
			'goal'            => null,
			'messages'        => array(),
			'version'         => self::VERSION,
		);
		// Answers first (sheet 1.4, order): age, sex, pregnancy, eating; then the stops; then the measurements.
		$age = isset( $in['age'] ) && is_numeric( $in['age'] ) ? (int) $in['age'] : null;
		if ( null === $age || $age < 0 || $age > 99 ) {
			return self::invalid( 'age' );
		}
		if ( '' === $sex ) {
			return self::invalid( 'sex' );
		}
		$pregnant = self::answer( isset( $in['pregnant'] ) ? $in['pregnant'] : 'no', array( 'yes', 'no', 'notsure' ) );
		$eating   = self::answer( isset( $in['eatingDisorder'] ) ? $in['eatingDisorder'] : 'no', array( 'yes', 'no', 'pnts' ) );
		if ( null === $pregnant ) {
			return self::invalid( 'pregnant' );
		}
		if ( null === $eating ) {
			return self::invalid( 'eatingDisorder' );
		}
		if ( $age < 18 ) {
			return self::stop( $out, 'under18' );
		}
		if ( 'no' !== $pregnant ) {
			return self::stop( $out, 'pregnancy' );
		}
		if ( 'yes' === $eating ) {
			return self::stop( $out, 'eating' );
		}
		$height = self::height_cm( $in );
		$weight = self::weight_kg( $in );
		if ( null === $height || $height < 120 || $height > 230 ) {
			return self::invalid( 'height' );
		}
		if ( null === $weight || $weight < 25 || $weight > 350 ) {
			return self::invalid( 'weight' );
		}
		$activity = isset( $in['activity'] ) ? str_replace( '_', ' ', strtolower( trim( (string) $in['activity'] ) ) ) : '';
		if ( ! isset( self::FACTORS[ $activity ] ) ) {
			return self::invalid( 'activity' );
		}
		$goal = isset( $in['goal'] ) ? strtolower( trim( (string) $in['goal'] ) ) : 'maintain';
		if ( ! in_array( $goal, array( 'lose', 'maintain', 'gain' ), true ) ) {
			return self::invalid( 'goal' );
		}
		$diabetes = ! empty( $in['type2Diabetes'] );
		$kidney   = ! empty( $in['kidneyDisease'] );

		// A Premium member's own target: only the floor is checked (FR3-28 AS-02).
		if ( isset( $in['memberTarget'] ) && null !== $in['memberTarget'] && '' !== $in['memberTarget'] ) {
			$own = (float) $in['memberTarget'];
			if ( $own < $floor ) {
				$out['refused']  = true;
				$out['notes'][]  = 'floor';
				$out['messages'] = array( 'floor_note' );
				return $out;
			}
			$out['targetKcal'] = (int) round( $own );
			$out['goal']       = 'member';
			return $out;
		}

		$rmr  = 10 * $weight + 6.25 * $height - 5 * $age + ( 'M' === $sex ? 5 : -161 );
		$tdee = $rmr * self::FACTORS[ $activity ];
		$bmi  = $weight / ( ( $height / 100 ) * ( $height / 100 ) );
		$out['rmr']  = self::r2( $rmr );
		$out['tdee'] = self::r2( $tdee );
		$out['bmi']  = self::r1( $bmi );

		$underweight      = $bmi < 18.5;
		$maintenance_only = 'pnts' === $eating || $underweight;
		$over65           = $age >= 65;

		if ( 'gain' === $goal && ( $bmi >= 25 || 'no' !== $eating ) ) {
			$goal             = 'maintain'; // gain is offered only below BMI 25 with no eating-disorder answer
			$maintenance_only = true;
		}
		$out['goal'] = $goal;

		if ( 'lose' === $goal ) {
			$requested = isset( $in['rateKgWeek'] ) && is_numeric( $in['rateKgWeek'] ) ? (float) $in['rateKgWeek'] : 0.5;
			$offered   = self::offered_rate( $requested, $weight, $over65 );
			$out['offeredRate'] = $offered;
			if ( $maintenance_only ) {
				$out['targetKcal']      = self::r10( $tdee );
				$out['maintenanceOnly'] = true;
				$out['notes'][]         = $underweight ? 'underweight' : 'eating_not_said';
			} elseif ( $tdee <= $floor ) {
				$out['raw']             = self::r2( $tdee - self::KCAL_PER_KG_WEEK * $offered );
				$out['targetKcal']      = self::r10( $tdee );
				$out['achievableRate']  = 0;
				$out['maintenanceOnly'] = true;
				$out['notes'][]         = 'no_loss';
			} else {
				$raw        = $tdee - self::KCAL_PER_KG_WEEK * $offered;
				$out['raw'] = self::r2( $raw );
				if ( $raw < $floor ) {
					$out['targetKcal']     = self::r10( $floor );
					$out['achievableRate'] = self::r2( ( $tdee - $floor ) / self::KCAL_PER_KG_WEEK );
					$out['notes'][]        = 'floor';
				} else {
					$out['targetKcal']     = self::r10( $raw );
					$out['achievableRate'] = $offered;
				}
			}
		} elseif ( 'gain' === $goal ) {
			$requested = isset( $in['rateKgWeek'] ) && is_numeric( $in['rateKgWeek'] ) ? (float) $in['rateKgWeek'] : 0.25;
			$rate      = $requested >= 0.5 ? 0.5 : 0.25;
			$raw       = $tdee + self::KCAL_PER_KG_WEEK * $rate;
			$out['offeredRate'] = $rate;
			$out['raw']         = self::r2( $raw );
			$out['targetKcal']  = self::r10( $raw );
		} else {
			$out['raw']        = self::r2( $tdee );
			$out['targetKcal'] = self::r10( $tdee );
			if ( $maintenance_only ) {
				$out['maintenanceOnly'] = true;
				if ( $underweight ) {
					$out['notes'][] = 'underweight';
				} elseif ( 'pnts' === $eating ) {
					$out['notes'][] = 'eating_not_said';
				}
			}
		}
		if ( $over65 ) {
			$out['notes'][] = 'over65';
		}
		if ( 'PNTS' === $sex ) {
			$out['notes'][] = 'sex_not_said';
		}
		if ( $diabetes ) {
			$out['notes'][] = 'diabetes';
		}
		if ( $kidney ) {
			$out['notes'][] = 'kidney';
		}
		$out['messages'] = self::messages_for( $out );
		return $out;
	}

	/**
	 * Nutrient targets for a calorie target (FR3-28, D3-06): protein from the Stage 1 Daily Protein Target, never
	 * recomputed here; fat 30 % of calories ÷ 9; carbohydrate the rest ÷ 4; fibre 30 g. Whole grams, half-up.
	 *
	 * @param int      $kcal      Daily calorie target.
	 * @param int|null $protein_g The member's protein target, or null when none may be shown (kidney disease).
	 * @return array fatG, carbG (null without protein), fibreG, proteinG.
	 */
	public static function macros( $kcal, $protein_g ) {
		$kcal = (int) $kcal;
		$fat  = (int) round( $kcal * 0.3 / 9, 0, PHP_ROUND_HALF_UP );
		$carb = null;
		if ( null !== $protein_g && '' !== $protein_g ) {
			$carb = (int) round( ( $kcal - 4 * (float) $protein_g - 9 * $fat ) / 4, 0, PHP_ROUND_HALF_UP );
			$carb = max( 0, $carb );
		}
		return array(
			'proteinG' => null === $protein_g || '' === $protein_g ? null : (int) round( (float) $protein_g, 0, PHP_ROUND_HALF_UP ),
			'fatG'     => $fat,
			'carbG'    => $carb,
			'fibreG'   => self::FIBRE_G,
		);
	}

	/**
	 * The highest rate the member may be offered: ≤ the one asked for, ≤ min(1.0, 1 % of weight) (P3-56), and ≤ 0.5
	 * from 65 (sheet section 2); never below 0.25.
	 *
	 * @param float $requested Rate asked for, kg a week.
	 * @param float $weight_kg Current weight.
	 * @param bool  $over65    Age 65 or over.
	 */
	public static function offered_rate( $requested, $weight_kg, $over65 ) {
		$cap = min( 1.0, self::r2( 0.01 * $weight_kg ) );
		if ( $over65 ) {
			$cap = min( $cap, 0.5 );
		}
		$limit   = min( (float) $requested, $cap );
		$offered = 0.25;
		foreach ( self::RATES as $rate ) {
			if ( $rate <= $limit + 1e-9 ) {
				$offered = $rate;
			}
		}
		return $offered;
	}

	/**
	 * The wording keys a result shows, in order: the stop alone, or the notes' messages then the estimate line.
	 *
	 * @param array $out A compute() result.
	 * @return string[]
	 */
	private static function messages_for( array $out ) {
		if ( null !== $out['stop'] ) {
			return array( 'stop.' . $out['stop'] );
		}
		$map  = array(
			'floor'           => 'floor_note',
			'no_loss'         => 'no_loss_note',
			'eating_not_said' => 'eating_not_said_note',
			'sex_not_said'    => 'sex_not_said_note',
			'over65'          => 'over65_note',
			'diabetes'        => 'diabetes_note',
		);
		$keys = array( 'headline', 'headline_subline', 'why_this_number' );
		foreach ( $out['notes'] as $note ) {
			if ( isset( $map[ $note ] ) ) {
				$keys[] = $map[ $note ];
			}
		}
		$keys[] = 'estimate_note';
		return $keys;
	}

	/**
	 * @param array  $out  Result so far.
	 * @param string $what under18, pregnancy or eating.
	 */
	private static function stop( array $out, $what ) {
		$out['stop']     = $what;
		$out['messages'] = array( 'stop.' . $what );
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
		$v = strtolower( trim( (string) $value ) );
		if ( in_array( $v, array( 'f', 'female', 'woman' ), true ) ) {
			return 'F';
		}
		if ( in_array( $v, array( 'm', 'male', 'man' ), true ) ) {
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
		$v = strtolower( str_replace( array( ' ', '-', '_' ), '', trim( (string) $value ) ) );
		if ( 'prefernottosay' === $v || 'prefernot' === $v ) {
			$v = 'pnts';
		}
		return in_array( $v, $allowed, true ) ? $v : null;
	}

	/**
	 * Height in cm from cm or ft/in (1 in = 2.54 cm), unrounded.
	 *
	 * @param array $in Answers.
	 */
	private static function height_cm( array $in ) {
		if ( isset( $in['heightCm'] ) && is_numeric( $in['heightCm'] ) ) {
			return (float) $in['heightCm'];
		}
		if ( isset( $in['heightFtIn'] ) && is_array( $in['heightFtIn'] ) ) {
			$ft = isset( $in['heightFtIn']['ft'] ) ? (float) $in['heightFtIn']['ft'] : 0;
			$in_ = isset( $in['heightFtIn']['in'] ) ? (float) $in['heightFtIn']['in'] : 0;
			return ( $ft * 12 + $in_ ) * 2.54;
		}
		return null;
	}

	/**
	 * Weight in kg from kg, st/lb or lb (1 lb = 0.45359237 kg), unrounded.
	 *
	 * @param array $in Answers.
	 */
	private static function weight_kg( array $in ) {
		if ( isset( $in['weightKg'] ) && is_numeric( $in['weightKg'] ) ) {
			return (float) $in['weightKg'];
		}
		if ( isset( $in['weightStLb'] ) && is_array( $in['weightStLb'] ) ) {
			$st = isset( $in['weightStLb']['st'] ) ? (float) $in['weightStLb']['st'] : 0;
			$lb = isset( $in['weightStLb']['lb'] ) ? (float) $in['weightStLb']['lb'] : 0;
			return ( $st * 14 + $lb ) * 0.45359237;
		}
		if ( isset( $in['weightLb'] ) && is_numeric( $in['weightLb'] ) ) {
			return (float) $in['weightLb'] * 0.45359237;
		}
		return null;
	}

	/** Nearest 10 kcal, half-up. */
	private static function r10( $x ) {
		return (int) ( round( $x / 10, 0, PHP_ROUND_HALF_UP ) * 10 );
	}

	/** Two decimals, half-up (shown intermediates). */
	private static function r2( $x ) {
		return round( $x, 2, PHP_ROUND_HALF_UP );
	}

	/** One decimal, half-up. */
	private static function r1( $x ) {
		return round( $x, 1, PHP_ROUND_HALF_UP );
	}
}
