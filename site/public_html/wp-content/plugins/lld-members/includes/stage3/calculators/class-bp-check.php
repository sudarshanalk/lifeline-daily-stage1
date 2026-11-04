<?php
/**
 * Blood Pressure Reading Check engine (P3-58, FR3-50; warning levels FR3-38, FR3-46), written from the owner-signed
 * method sheet `04 Health Tools/Stage 3/P3-58-Blood-Pressure-Reading-Check-Method-Sheet.md` (D3-16 to D3-21;
 * approval record `approvals/stage3/bp-check.json`, fixtures `fixtures/bp-check/`, oracle `oracles/orc-09-bp-check/`).
 *
 * Pure rules: no WordPress, no database, no member lookup. The caller (the members' interface) supplies the answers,
 * and for the 7-day average the member's logged readings; the member's profile reaches it through apply_profile().
 *
 * One source for the warning levels: the urgent, pregnancy, low, pulse and impossible levels and their signed text are
 * LLD_Members_Stage3_Safety::warning_level() and ::BP_MESSAGES (P3-51), the same answer the logging screen gives
 * (FR3-46). This class adds only what the sheet gives the calculator alone: the ISH 2020 office categories (section 1),
 * the home-average categories and the 7-day average (sections 3 and 5), and the category, home, 80-plus, medicine and
 * guidelines-differ wording. Frozen: any change needs a new decision and a new version of the approval record
 * (NFR3-14, NFR3-17). Free for everyone; nothing here asks about Premium.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The engine. `compute()` takes the answers and returns the result the screens and the tests read.
 */
class LLD_Members_BP_Check {

	const VERSION = '1.0';

	/** ISH 2020 office categories (sheet section 1): the higher of the two numbers decides. */
	const OFFICE = array(
		'grade2'      => array( 'sys' => 160, 'dia' => 100 ),
		'grade1'      => array( 'sys' => 140, 'dia' => 90 ),
		'high-normal' => array( 'sys' => 130, 'dia' => 85 ),
	);

	/** Home-average categories (sheet section 3; D3-18 option A). */
	const HOME = array(
		'well-above' => array( 'sys' => 155, 'dia' => 95 ),
		'above'      => array( 'sys' => 135, 'dia' => 85 ),
	);

	/** The 7-day average (sheet section 5; D3-21 option A). */
	const WINDOW_DAYS = 7;
	const MIN_DAYS    = 3;

	/** Most readings one 7-day check accepts (a member logging 4 a day logs 28 a week); more is refused, not read. */
	const MAX_READINGS = 200;

	/** Age range and the 80-plus note (sheet sections 2 and 3). */
	const AGE_RANGE = array( 18, 99 );
	const AGE_NOTE  = 80;

	/**
	 * The calculator's own signed wording (sheet sections 1.1 and 3). Keys and text are hashed in the approval record's
	 * copy_hashes; the other seven keys there are the warning-level lines in LLD_Members_Stage3_Safety::BP_MESSAGES.
	 */
	const MESSAGES = array(
		'category.normal.headline'      => 'Normal',
		'category.normal.body'          => 'A reading under 130/85 is in the normal range. One reading is a snapshot: blood pressure moves with activity, stress, caffeine and time of day.',
		'category.normal-low.headline'  => 'Normal (on the low side)',
		'category.high-normal.headline' => 'High-normal',
		'category.high-normal.body'     => 'Between 130/85 and 139/89. Not high blood pressure, but worth keeping an eye on. Less salt, more movement, a healthy weight and less alcohol all help. Recheck in a few months.',
		'category.grade1.headline'      => 'High (grade 1)',
		'category.grade1.body'          => "A reading of 140/90 or more. One reading doesn't confirm high blood pressure. Take readings on 7 days, morning and evening, and share the average with your doctor.",
		'category.grade2.headline'      => 'High (grade 2)',
		'category.grade2.body'          => "A reading of 160/100 or more. Please take a second reading after resting 5 minutes. If it's still 160/100 or more, make an appointment with your doctor soon, and take home readings for 7 days to bring along.",
		'home.within.headline'          => 'Within the home range',
		'home.within.body'              => 'Your 7-day home average is under 135/85, the home equivalent of a normal-to-high-normal clinic reading.',
		'home.above.headline'           => 'Above the home threshold',
		'home.above.body'               => 'A home average of 135/85 or more is the level at which doctors consider high blood pressure. Please share these readings with your doctor.',
		'home.well-above.headline'      => 'Well above the home threshold',
		'home.well-above.body'          => 'Please make an appointment with your doctor soon and take these readings with you.',
		'home.min-days'                 => 'Add readings on at least 3 days for a 7-day average.',
		'pregnancy.neutral'             => 'Blood pressure in pregnancy is checked by your midwife; please share your readings with them.',
		'age80.note'                    => 'At 80 and over, doctors often accept slightly higher readings (up to 150/90 in some guidelines). Your doctor will set the right target for you.',
		'medicine.note'                 => 'This is your treated reading. Keep taking your medicine as prescribed; never change it because of a home reading. Share your readings with your doctor.',
		'about.guidelines-differ'       => "Guidelines differ: in the US, 130/80 and above is called stage 1 high blood pressure; in Europe, the UK and most of the world, 140/90 is the line. We use the International Society of Hypertension's categories, written for people everywhere. Your own doctor's advice comes first.",
	);

	/** warning_level() keys (P3-51) and the calculator's warning label for each. */
	const SAFETY_LABELS = array(
		'bp-check.urgent.alert'             => 'urgent',
		'bp-check.pregnancy.urgent'         => 'pregnancy_urgent',
		'bp-check.pregnancy.same-day'       => 'pregnancy_same_day',
		'bp-check.low.dizzy'                => 'low_dizzy',
		'bp-check.category.normal-low.body' => 'low_note',
		'bp-check.pulse.note'               => 'pulse',
		'bp-check.refused.impossible'       => 'refused',
	);

	/**
	 * Apply the member's health profile (the safety matrix's calculators_new column): pregnancy in the profile turns
	 * on the pregnancy levels whatever the page answered ("bp-check:pregnancy_mode"). The 80-plus note follows the
	 * age answered on the page (the matrix's age_65_plus row cannot tell 80 and over).
	 *
	 * @param array    $in         The answers on the page.
	 * @param string[] $situations LLD_Members_Stage3_Safety::situations() keys.
	 */
	public static function apply_profile( array $in, array $situations ) {
		if ( in_array( 'pregnancy', $situations, true ) && 'notsure' !== self::pregnancy_answer( isset( $in['pregnant'] ) ? $in['pregnant'] : 'no' ) ) {
			$in['pregnant'] = 'yes';
		}
		return $in;
	}

	/**
	 * Whether a mode answer asks for the 7-day home average (the members' route then reads the member's own logged
	 * readings, sheet section 5).
	 *
	 * @param mixed $value Mode answer.
	 */
	public static function home_mode( $value ) {
		return '7-day' === self::mode( $value );
	}

	/**
	 * Work out the result.
	 *
	 * @param array $in mode (single | 7-day), pregnant (yes | no | notsure, or true / false), age (18-99, optional),
	 *                  medicine and dizzy (the "I feel dizzy or faint" tick), optional: yes / no, true / false,
	 *                  "true" / "false" or 1 / 0, anything else refused; single: systolic, diastolic, pulse (optional);
	 *                  7-day: readings [{date Y-m-d, systolic, diastolic}] (at most MAX_READINGS: the member's logged
	 *                  blood-pressure readings, not deleted, which the members' route reads itself) and today (Y-m-d
	 *                  in the member's time zone). Every reading and pulse is rounded to whole numbers, half-up, before
	 *                  any level or category (as the heart risk check does).
	 * @return array mode, refused, category, warning_level, urgent, messages (wording keys in order), average,
	 *               average_unrounded, average_shown, days, daily_means, used, skipped, pulse_direction, safety,
	 *               version; or error (the field to check).
	 */
	public static function compute( array $in ) {
		$mode = self::mode( isset( $in['mode'] ) ? $in['mode'] : 'single' );
		if ( null === $mode ) {
			return self::invalid( 'mode' );
		}
		$pregnant = self::pregnancy_answer( isset( $in['pregnant'] ) ? $in['pregnant'] : 'no' );
		if ( null === $pregnant ) {
			return self::invalid( 'pregnant' );
		}
		$dizzy    = self::yes_no( isset( $in['dizzy'] ) ? $in['dizzy'] : null );
		$medicine = self::yes_no( isset( $in['medicine'] ) ? $in['medicine'] : null );
		if ( null === $dizzy ) {
			return self::invalid( 'dizzy' );
		}
		if ( null === $medicine ) {
			return self::invalid( 'medicine' );
		}
		$age = null;
		if ( isset( $in['age'] ) && '' !== $in['age'] ) {
			if ( ! is_numeric( $in['age'] ) || (float) $in['age'] < self::AGE_RANGE[0] || (float) $in['age'] > self::AGE_RANGE[1] ) {
				return self::invalid( 'age' );
			}
			$age = (int) $in['age'];
		}
		$flags = array(
			'pregnancy' => 'no' !== $pregnant, // "Not sure" is treated as pregnant (sheet section 4.2)
			'dizzy'     => $dizzy,
		);
		$ctx   = array(
			'pregnant' => $flags['pregnancy'],
			'age80'    => null !== $age && $age >= self::AGE_NOTE,
			'medicine' => $medicine,
		);
		$out   = array(
			'mode'              => $mode,
			'refused'           => false,
			'category'          => null,
			'warning_level'     => 'none',
			'urgent'            => false,
			'messages'          => array(),
			'systolic'          => null,
			'diastolic'         => null,
			'pulse'             => null,
			'pulse_direction'   => null,
			'average'           => null,
			'average_unrounded' => null,
			'average_shown'     => false,
			'days'              => null,
			'daily_means'       => array(),
			'used'              => array(),
			'skipped'           => 0,
			'safety'            => null,
			'version'           => self::VERSION,
		);
		return 'single' === $mode ? self::single( $in, $flags, $ctx, $out ) : self::home( $in, $flags, $ctx, $out );
	}

	/**
	 * The signed text for wording keys: this class's own, or the warning-level lines of the safety matrix. The pulse
	 * note's {high|low} is filled from the result.
	 *
	 * @param string[] $keys   Wording keys (a compute() result's messages).
	 * @param array    $result The compute() result.
	 * @return array<string,string>
	 */
	public static function wording( array $keys, array $result = array() ) {
		$out = array();
		foreach ( $keys as $key ) {
			$text = self::text( $key );
			if ( null === $text ) {
				continue;
			}
			if ( 'pulse.note' === $key ) {
				$text = str_replace( '{high|low}', isset( $result['pulse_direction'] ) && 'low' === $result['pulse_direction'] ? 'low' : 'high', $text );
			}
			$out[ $key ] = $text;
		}
		return $out;
	}

	/**
	 * Every signed wording key the calculator shows, with its text as signed (placeholders unfilled): what the approval
	 * record hashes.
	 *
	 * @return array<string,string>
	 */
	public static function signed_text() {
		$out = self::MESSAGES;
		foreach ( self::safety_class()::BP_MESSAGES as $key => $text ) {
			$out[ $key ] = $text;
		}
		return $out;
	}

	/**
	 * The ISH 2020 office category of one reading (sheet section 1).
	 *
	 * @param float $sys Systolic.
	 * @param float $dia Diastolic.
	 * @return string normal, high-normal, grade1 or grade2.
	 */
	public static function office_category( $sys, $dia ) {
		foreach ( self::OFFICE as $category => $t ) {
			if ( $sys >= $t['sys'] || $dia >= $t['dia'] ) {
				return $category;
			}
		}
		return 'normal';
	}

	/**
	 * The home category of a rounded 7-day average (sheet section 3).
	 *
	 * @param int $sys Systolic average.
	 * @param int $dia Diastolic average.
	 * @return string within, above or well-above.
	 */
	public static function home_category( $sys, $dia ) {
		foreach ( self::HOME as $category => $t ) {
			if ( $sys >= $t['sys'] || $dia >= $t['dia'] ) {
				return $category;
			}
		}
		return 'within';
	}

	// ---- Modes ---------------------------------------------------------------------------------------------------

	/**
	 * One reading (sheet sections 3 and 4).
	 */
	private static function single( array $in, array $flags, array $ctx, array $out ) {
		foreach ( array( 'systolic', 'diastolic' ) as $field ) {
			if ( ! isset( $in[ $field ] ) || ! is_numeric( $in[ $field ] ) ) {
				return self::invalid( $field );
			}
		}
		$values = array(
			'sys' => self::whole( $in['systolic'] ),
			'dia' => self::whole( $in['diastolic'] ),
		);
		if ( isset( $in['pulse'] ) && '' !== $in['pulse'] && null !== $in['pulse'] ) {
			if ( ! is_numeric( $in['pulse'] ) ) {
				return self::invalid( 'pulse' );
			}
			$values['pulse'] = self::whole( $in['pulse'] );
		}
		$out['systolic']  = $values['sys'] + 0;
		$out['diastolic'] = $values['dia'] + 0;
		$out['pulse']     = isset( $values['pulse'] ) ? $values['pulse'] + 0 : null;

		$safety        = self::safety_class()::warning_level( 'blood_pressure', $values, $flags );
		$out['safety'] = $safety;
		if ( 'impossible' === $safety['level'] ) {
			return self::refuse( $out );
		}
		$out['urgent'] = 'urgent' === $safety['level'];
		$label         = self::label( $safety );
		$low           = in_array( $label, array( 'low_note', 'low_dizzy' ), true );
		$keys          = array();
		if ( 'urgent' === $label ) {
			$keys[] = 'urgent.alert'; // shown before anything else (sheet 4.1)
		}
		if ( $ctx['pregnant'] ) {
			$keys[] = self::pregnancy_key( $label ); // no categories in pregnancy (D3-19)
		} else {
			$out['category'] = self::office_category( $values['sys'], $values['dia'] );
			if ( 'normal' === $out['category'] && $low ) {
				$keys[] = 'category.normal-low.headline';
				$keys[] = 'category.normal-low.body';
			} else {
				$keys[] = 'category.' . $out['category'] . '.headline';
				$keys[] = 'category.' . $out['category'] . '.body';
				if ( $low ) {
					$keys[] = 'category.normal-low.body'; // the low note (sheet 4.3) under a higher category
				}
			}
		}
		if ( 'low_dizzy' === $label ) {
			$keys[] = 'low.dizzy';
		}
		// The pulse note whenever the pulse is outside the resting range, even when a more serious level is the one
		// warning_level() returns (sheet 4.3: pulse never changes the category).
		$pulse_range = self::safety_class()::LEVELS['blood_pressure']['pulse'];
		if ( null !== $out['pulse'] && ( $out['pulse'] < $pulse_range[0] || $out['pulse'] > $pulse_range[1] ) ) {
			$out['pulse_direction'] = $out['pulse'] > $pulse_range[1] ? 'high' : 'low';
			$keys[]                 = 'pulse.note';
		}
		if ( 'pulse' === $label ) {
			$label = 'pulse_' . $out['pulse_direction'];
		}
		$out['warning_level'] = self::with_age_label( $label, $ctx );
		$out['messages']      = self::closing_notes( $keys, $ctx );
		return $out;
	}

	/**
	 * The 7-day home average from logged readings (sheet sections 3 and 5).
	 */
	private static function home( array $in, array $flags, array $ctx, array $out ) {
		$today = self::date( isset( $in['today'] ) ? $in['today'] : gmdate( 'Y-m-d' ) );
		if ( null === $today ) {
			return self::invalid( 'today' );
		}
		if ( ! isset( $in['readings'] ) || ! is_array( $in['readings'] ) || count( $in['readings'] ) > self::MAX_READINGS ) {
			return self::invalid( 'readings' );
		}
		$from = $today->modify( '-' . ( self::WINDOW_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );
		$to   = $today->format( 'Y-m-d' );

		// Every reading in the window counts (sheet 5); one warning_level() can't call possible is left out, as the
		// logging screen refuses it. The most serious level of any single reading is kept (sheet 3: the urgent
		// wording is shown regardless of the average).
		$rank  = array( 'none' => 0, 'caution' => 1, 'urgent' => 2 );
		$worst = null;
		$days  = array();
		foreach ( $in['readings'] as $r ) {
			if ( ! is_array( $r ) ) {
				return self::invalid( 'readings' );
			}
			$date = self::date( isset( $r['date'] ) ? $r['date'] : null );
			$sys  = self::num( $r, array( 'systolic', 'sys', 'value_1' ) );
			$dia  = self::num( $r, array( 'diastolic', 'dia', 'value_2' ) );
			if ( null === $date || null === $sys || null === $dia ) {
				return self::invalid( 'readings' );
			}
			$sys = self::whole( $sys ); // whole mmHg before any level, as a single reading
			$dia = self::whole( $dia );
			$d   = $date->format( 'Y-m-d' );
			if ( $d < $from || $d > $to ) {
				continue;
			}
			$s = self::safety_class()::warning_level( 'blood_pressure', array( 'sys' => $sys, 'dia' => $dia ), array( 'pregnancy' => $flags['pregnancy'] ) );
			if ( 'impossible' === $s['level'] ) {
				++$out['skipped'];
				continue;
			}
			$serious = in_array( $s['key'], array( 'bp-check.urgent.alert', 'bp-check.pregnancy.urgent', 'bp-check.pregnancy.same-day' ), true );
			if ( $serious && ( null === $worst || $rank[ $s['level'] ] > $rank[ $worst['level'] ] ) ) {
				$worst = $s;
			}
			$days[ $d ][]  = array( $sys, $dia );
			$out['used'][] = array(
				'date'      => $d,
				'systolic'  => $sys + 0,
				'diastolic' => $dia + 0,
			);
		}
		ksort( $days );
		$out['days'] = count( $days );

		// Daily means first, unrounded; then the mean of the days, rounded half-up; the category on the rounded numbers.
		$sum = array( 0.0, 0.0 );
		foreach ( $days as $d => $list ) {
			$s = 0.0;
			$t = 0.0;
			foreach ( $list as $pair ) {
				$s += $pair[0];
				$t += $pair[1];
			}
			$mean                 = array( $s / count( $list ), $t / count( $list ) );
			$sum[0]              += $mean[0];
			$sum[1]              += $mean[1];
			$out['daily_means'][] = array(
				'date'      => $d,
				'systolic'  => round( $mean[0], 3 ),
				'diastolic' => round( $mean[1], 3 ),
				'readings'  => count( $list ),
			);
		}
		$safety = $worst;
		if ( count( $days ) >= self::MIN_DAYS ) {
			$raw                      = array( $sum[0] / count( $days ), $sum[1] / count( $days ) );
			$avg                      = array( self::half_up( $raw[0] ), self::half_up( $raw[1] ) );
			$out['average']           = array( 'systolic' => $avg[0], 'diastolic' => $avg[1] );
			$out['average_unrounded'] = array( 'systolic' => round( $raw[0], 3 ), 'diastolic' => round( $raw[1], 3 ) );
			$out['average_shown']     = true;
			if ( null === $safety ) {
				$safety = self::safety_class()::warning_level( 'blood_pressure', array( 'sys' => $avg[0], 'dia' => $avg[1] ), $flags );
			}
		}
		$out['safety'] = $safety;
		$label         = null === $safety ? 'none' : self::label( $safety );
		$out['urgent'] = null !== $safety && 'urgent' === $safety['level'];
		$keys          = array();
		if ( 'urgent' === $label ) {
			$keys[] = 'urgent.alert';
		}
		if ( $ctx['pregnant'] ) {
			$keys[] = self::pregnancy_key( $label );
		} elseif ( null !== $out['average'] && 'urgent' !== $label ) {
			// With a reading at the urgent level the alert is shown and the average with it, without a home category
			// (fixture FX-BP-URGENT-05, sheet BP-32).
			$out['category'] = self::home_category( $out['average']['systolic'], $out['average']['diastolic'] );
			$keys[]          = 'home.' . $out['category'] . '.headline';
			$keys[]          = 'home.' . $out['category'] . '.body';
			if ( in_array( $label, array( 'low_note', 'low_dizzy' ), true ) ) {
				$keys[] = 'category.normal-low.body'; // the "on the low side" sub-note (sheet 3)
			}
		}
		if ( 'low_dizzy' === $label ) {
			$keys[] = 'low.dizzy';
		}
		if ( null === $out['average'] ) {
			$keys[] = 'home.min-days';
		}
		$out['warning_level'] = self::with_age_label( $label, $ctx );
		$out['messages']      = self::closing_notes( $keys, $ctx );
		return $out;
	}

	// ---- Helpers -------------------------------------------------------------------------------------------------

	/**
	 * The safety matrix class, loaded on first use when the engine runs on its own (PHP CLI tests); in WordPress the
	 * Stage 3 loader has already loaded it.
	 */
	private static function safety_class() {
		if ( ! class_exists( 'LLD_Members_Stage3_Safety' ) ) {
			require_once dirname( __DIR__ ) . '/class-safety-stage3.php';
		}
		return 'LLD_Members_Stage3_Safety';
	}

	/** A warning_level() result as the calculator's warning label. */
	private static function label( array $safety ) {
		if ( 'none' === $safety['level'] || null === $safety['key'] ) {
			return 'none';
		}
		return isset( self::SAFETY_LABELS[ $safety['key'] ] ) ? self::SAFETY_LABELS[ $safety['key'] ] : 'none';
	}

	/** The pregnancy line for a warning label (sheet 4.2). */
	private static function pregnancy_key( $label ) {
		if ( 'pregnancy_urgent' === $label ) {
			return 'pregnancy.urgent';
		}
		return 'pregnancy_same_day' === $label ? 'pregnancy.same-day' : 'pregnancy.neutral';
	}

	/**
	 * With no warning, the result's note: in pregnancy the neutral line (fixture FX-BP-PREG-04), otherwise the 80-plus
	 * note when it applies (outside pregnancy, where no category is shown).
	 */
	private static function with_age_label( $label, array $ctx ) {
		if ( 'none' !== $label ) {
			return $label;
		}
		if ( $ctx['pregnant'] ) {
			return 'pregnancy_neutral';
		}
		return $ctx['age80'] ? 'age80_note' : 'none';
	}

	/** The 80-plus note (outside pregnancy) and the treated-reading note close the result (sheet 3). */
	private static function closing_notes( array $keys, array $ctx ) {
		if ( $ctx['age80'] && ! $ctx['pregnant'] ) {
			$keys[] = 'age80.note';
		}
		if ( $ctx['medicine'] ) {
			$keys[] = 'medicine.note';
		}
		return $keys;
	}

	/** A reading that isn't possible: refused, no category (sheet 4.4, FR3-38 AS-04). */
	private static function refuse( array $out ) {
		$out['refused']       = true;
		$out['category']      = null;
		$out['warning_level'] = 'refused';
		$out['urgent']        = false;
		$out['messages']      = array( 'refused.impossible' );
		return $out;
	}

	/** Signed text for one key, or null. */
	private static function text( $key ) {
		if ( isset( self::MESSAGES[ $key ] ) ) {
			return self::MESSAGES[ $key ];
		}
		$bp = self::safety_class()::BP_MESSAGES;
		return isset( $bp[ $key ] ) ? $bp[ $key ] : null;
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

	/** Whole mmHg, half-up (the numbers are positive); the guard absorbs floating-point error in the daily means. */
	private static function half_up( $x ) {
		return (int) round( round( $x, 6 ), 0, PHP_ROUND_HALF_UP );
	}

	/**
	 * @param mixed $value Mode answer.
	 * @return string|null single or 7-day.
	 */
	private static function mode( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$v = strtolower( str_replace( array( ' ', '-', '_' ), '', trim( (string) $value ) ) );
		if ( 'single' === $v || 'singlereading' === $v ) {
			return 'single';
		}
		return in_array( $v, array( '7day', '7dayaverage', 'sevenday', 'home', 'average' ), true ) ? '7-day' : null;
	}

	/** A reading or pulse in whole numbers, half-up (they are positive). */
	private static function whole( $x ) {
		return (int) round( (float) $x, 0, PHP_ROUND_HALF_UP );
	}

	/**
	 * The pregnancy answer: yes, no or notsure. true / "true" / 1 is yes, false / "false" / 0 is no; anything else is
	 * refused (null), so "no" can never count as yes.
	 *
	 * @param mixed $value Answer.
	 * @return string|null
	 */
	private static function pregnancy_answer( $value ) {
		if ( 'notsure' === self::token( $value ) ) {
			return 'notsure';
		}
		$yes = self::yes_no( $value, false );
		return null === $yes ? null : ( $yes ? 'yes' : 'no' );
	}

	/**
	 * A yes/no answer or tick as a boolean: yes / true / "true" / 1 / "1" / "on" is true; no / false / "false" / 0 /
	 * "0" / "off" is false; not given (null or "") is $missing; anything else is refused (null).
	 *
	 * @param mixed     $value   Answer.
	 * @param bool|null $missing What an answer not given means.
	 * @return bool|null
	 */
	private static function yes_no( $value, $missing = false ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( null === $value || '' === $value ) {
			return $missing;
		}
		$v = self::token( $value );
		if ( in_array( $v, array( 'yes', 'true', '1', 'on' ), true ) ) {
			return true;
		}
		return in_array( $v, array( 'no', 'false', '0', 'off' ), true ) ? false : null;
	}

	/** An answer lower-cased, without spaces, hyphens or underscores; '' for a non-scalar. */
	private static function token( $value ) {
		return is_scalar( $value ) && ! is_bool( $value ) ? strtolower( str_replace( array( ' ', '-', '_' ), '', trim( (string) $value ) ) ) : '';
	}

	/**
	 * @param mixed $value A Y-m-d date.
	 * @return DateTimeImmutable|null
	 */
	private static function date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $value ) ) {
			return null;
		}
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $value, 0, 10 ), new DateTimeZone( 'UTC' ) );
		return $d && $d->format( 'Y-m-d' ) === substr( $value, 0, 10 ) ? $d : null;
	}

	private static function num( array $values, array $names ) {
		foreach ( $names as $n ) {
			if ( isset( $values[ $n ] ) && is_numeric( $values[ $n ] ) ) {
				return (float) $values[ $n ];
			}
		}
		return null;
	}
}
