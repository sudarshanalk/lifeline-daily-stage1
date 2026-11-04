<?php
/**
 * Saved calculator results over time (P3-5B, FR3-55).
 *
 * From the owner-signed sheet (approval `approvals/stage3/saved-results.json`, fixtures
 * `fixtures/saved-results/`). `compute()` is pure: it decides what a Free or Premium member is shown, the
 * change since the previous result, and whether a 91-day re-check reminder exists. It does not read the
 * database and it does not send mail.
 *
 * Stage 2 already stores the headline, unit, band and calculator version (data model C4) and never the
 * answers (D51). Stage 3 calculators also keep the answers, sealed, so a re-check can be pre-filled (D3-33).
 * History, the comparison and the re-check reminder are Premium. A Free member sees the latest result and
 * an invitation to upgrade. Reminder mail is the generic line only: no number and no calculator name (D3-35).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comparison, reminder and storage rules for one member's saved calculator results.
 */
class LLD_Members_Saved_Results {

	const VERSION = '1.0';

	/** Days from the last saved result to the re-check (three months, D3-35). */
	const RECHECK_DAYS = 91;

	/**
	 * Fragments whose text is in the repository and matches the approval copy_hashes. The other six
	 * fragments are on the signed sheet, which is not in this repository; the sentences the fixtures
	 * freeze are built below from those fixtures, not invented here.
	 */
	const MESSAGES = array(
		'change.lower'             => 'Lower than last time',
		'healthyWeight.comparison' => 'Moving towards the healthy range',
		'recheck.email'            => 'a calculator re-check is due',
	);

	/** Calculators that get a re-check reminder. Calorie target and goal timeline never do. */
	const RECHECK = array( 'heart_risk', 'bp_check', 'metabolic', 'fitness_age', 'healthy_weight' );

	/** Stage 1 tools keep Stage 2 behaviour: the answers are never stored (D51, D3-33). */
	const STAGE1 = array( 'protein', 'diabetes', 'diabetes_risk', 'healthy-weight', 'healthy_weight' );

	/** Saved calorie-target and goal-timeline results are hidden once an eating-disorder flag is set (FR3-69). */
	const HIDDEN_WHEN_EATING_DISORDER = array( 'calorie_target', 'goal_timeline' );

	/**
	 * What the member is shown for one fixture-shaped case.
	 *
	 * @param array<string,mixed> $in calculator, plan, results, health_consent, eating-disorder flag.
	 * @return array<string,mixed>
	 */
	public static function compute( array $in ) {
		$calculator = self::key( isset( $in['calculator'] ) ? $in['calculator'] : '' );
		$premium    = isset( $in['plan'] ) && 'premium' === strtolower( (string) $in['plan'] );
		$consent    = ! array_key_exists( 'health_consent', $in ) || false !== $in['health_consent'];
		$results    = isset( $in['results'] ) && is_array( $in['results'] ) ? array_values( $in['results'] ) : array();
		$hidden     = ! empty( $in['eating_disorder_flag_added_after_save'] ) && self::hidden_for_eating_disorder( $calculator );

		if ( ! $consent ) {
			return array( 'save_offered' => false );
		}
		if ( $hidden ) {
			return array(
				'save_offered'      => false,
				'hidden_everywhere' => true,
				'api_refuses'       => true,
			);
		}

		$out = array( 'save_offered' => true );
		$out = array_merge( $out, self::recheck( $calculator, $premium, $results ) );

		if ( ! $premium && $results ) {
			$latest                 = $results[ count( $results ) - 1 ];
			$out['shown']           = self::shown_raw( $latest );
			$out['latest_only']     = true;
			$out['history']         = false;
			$out['comparison']      = false;
			$out['upgrade_line']    = true;
			return $out;
		}

		if ( $premium && count( $results ) >= 2 ) {
			$out = array_merge( $out, self::compare( $calculator, $results[ count( $results ) - 2 ], $results[ count( $results ) - 1 ] ) );
			$out['history']      = true;
			$out['comparison']   = true;
			$out['latest_only']  = false;
			$out['upgrade_line'] = false;
		}
		return $out;
	}

	/**
	 * The Stage 2 columns for the latest result, plus the answers when this calculator may keep them.
	 * Nothing here is written. `inputs` is null for a Stage 1 tool.
	 *
	 * @param array<string,mixed> $in Same shape as compute().
	 * @return array<string,mixed>
	 */
	public static function stored_fields( array $in ) {
		$view = self::compute( $in );
		if ( empty( $view['save_offered'] ) ) {
			return array(
				'saved'  => false,
				'inputs' => null,
			);
		}
		$results = isset( $in['results'] ) && is_array( $in['results'] ) ? array_values( $in['results'] ) : array();
		$last    = $results ? $results[ count( $results ) - 1 ] : array();
		$calc    = self::key( isset( $in['calculator'] ) ? $in['calculator'] : '' );
		$clear   = self::clear_columns( $calc, $last );
		return array(
			'saved'              => '' !== $clear['headline'],
			'headline'           => $clear['headline'],
			'unit'               => $clear['unit'],
			'band'               => $clear['band'],
			'calculator_version' => $clear['calculator_version'],
			'inputs'             => self::is_stage1( $calc ) ? null : $last,
		);
	}

	/**
	 * Save one engine result for the signed-in member. The answers of a Stage 3 calculator are sealed
	 * before they are written. Returns the new row id, or an error array. Never logs a value.
	 *
	 * @param int                 $member_id Member number.
	 * @param string              $tool      Tool slug (heart-risk) or fixture key (heart_risk).
	 * @param array<string,mixed> $engine    The engine's compute() result.
	 * @param array<string,mixed> $answers   The page answers, for a Stage 3 re-check pre-fill.
	 * @return array<string,mixed>
	 */
	public static function store( $member_id, $tool, array $engine, array $answers ) {
		global $wpdb;
		$member_id = (int) $member_id;
		if ( $member_id < 1 || ! isset( $wpdb ) ) {
			return array( 'error' => 'Not available.', 'status' => 400 );
		}
		$calc = self::key( $tool );
		if ( self::blocked_for_member( $member_id, $calc ) ) {
			return array( 'error' => 'Not available.', 'status' => 403 );
		}
		if ( class_exists( 'LLD_Members_Access' ) && ! LLD_Members_Access::has_health_consent( $member_id ) ) {
			return array( 'error' => 'Health consent is needed first.', 'status' => 403 );
		}
		$snapshot = self::snapshot_from_engine( $calc, $engine, $answers );
		$clear    = self::clear_columns( $calc, $snapshot );
		if ( '' === $clear['headline'] || '' === $clear['band'] ) {
			return array( 'error' => 'Not available.', 'status' => 422 );
		}
		$row = array(
			'member_id'          => $member_id,
			'calculator'         => $calc,
			'saved_at'           => function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
			'headline'           => $clear['headline'],
			'unit'               => '' !== $clear['unit'] ? $clear['unit'] : 'result',
			'band'               => $clear['band'],
			'calculator_version' => $clear['calculator_version'],
		);
		if ( ! self::is_stage1( $calc ) && class_exists( 'LLD_Members_Stage3_Data' ) ) {
			$sealed = LLD_Members_Stage3_Data::seal(
				$member_id,
				wp_json_encode(
					array(
						'answers'  => self::answers_only( $answers ),
						'snapshot' => $snapshot,
					)
				)
			);
			if ( is_string( $sealed ) && '' !== $sealed && self::has_inputs_column() ) {
				$row['inputs_ct'] = $sealed;
			}
		}
		$table = LLD_Members_Schema::table( 'calculator_result' );
		$ok    = $wpdb->insert( $table, $row );
		if ( ! $ok ) {
			return array( 'error' => 'Not available.', 'status' => 400 );
		}
		return array( 'id' => (int) $wpdb->insert_id );
	}

	/**
	 * Rows the member may see. Free: the latest result of each calculator, plus upgrade_line when an
	 * older one is withheld. Premium: every result, and the change on the latest of each calculator.
	 * A hidden calculator is left out (FR3-69).
	 *
	 * @param int $member_id Member number.
	 * @return array{rows:array<int,array<string,mixed>>,upgrade_line:bool}
	 */
	public static function api_list( $member_id ) {
		$rows    = self::load_rows( (int) $member_id );
		$premium = self::premium( $member_id );
		$grouped = array();
		foreach ( $rows as $row ) {
			if ( self::blocked_for_member( $member_id, $row['calculator'] ) ) {
				continue;
			}
			$grouped[ $row['calculator'] ][] = $row;
		}
		$visible = array();
		$upgrade = false;
		foreach ( $grouped as $calculator => $list ) {
			usort(
				$list,
				static function ( $a, $b ) {
					return (int) $a['id'] <=> (int) $b['id'];
				}
			);
			if ( ! $premium ) {
				$upgrade   = $upgrade || count( $list ) > 1;
				$visible[] = self::public_row( $list[ count( $list ) - 1 ], false );
				continue;
			}
			$change = count( $list ) >= 2 ? self::compare( $calculator, self::as_result( $list[ count( $list ) - 2 ] ), self::as_result( $list[ count( $list ) - 1 ] ) ) : array();
			foreach ( $list as $i => $row ) {
				$public = self::public_row( $row, false );
				if ( $i === count( $list ) - 1 && $change ) {
					foreach ( array( 'wording', 'shown_from', 'shown_to', 'band_from', 'band_to', 'comparable', 'earlier_version_note', 'weight_loss_praise' ) as $key ) {
						if ( array_key_exists( $key, $change ) && null !== $change[ $key ] ) {
							$public[ $key ] = $change[ $key ];
						}
					}
				}
				if ( $i === count( $list ) - 1 && in_array( $calculator, self::RECHECK, true ) && '' !== (string) $row['saved_at'] ) {
					$saved = strtotime( $row['saved_at'] . ' UTC' );
					if ( false !== $saved ) {
						$public['recheck_due'] = gmdate( 'Y-m-d', $saved + self::RECHECK_DAYS * 86400 );
					}
				}
				$visible[] = $public;
			}
		}
		usort(
			$visible,
			static function ( $a, $b ) {
				return (int) $b['id'] <=> (int) $a['id'];
			}
		);
		return array(
			'rows'         => $visible,
			'upgrade_line' => $upgrade,
		);
	}

	/**
	 * One of the member's own results. Free members only receive their latest for that calculator.
	 * A hidden calculator is refused. Stage 3 answers are opened for the owner, for a re-check pre-fill.
	 *
	 * @param int $member_id Member number.
	 * @param int $id        Row id.
	 * @return array<string,mixed>|null
	 */
	public static function api_one( $member_id, $id ) {
		$member_id = (int) $member_id;
		$id        = (int) $id;
		$rows      = self::load_rows( $member_id );
		$found     = null;
		foreach ( $rows as $row ) {
			if ( (int) $row['id'] === $id ) {
				$found = $row;
				break;
			}
		}
		if ( ! $found ) {
			return null;
		}
		if ( self::blocked_for_member( $member_id, $found['calculator'] ) ) {
			return array( 'error' => 'Not available.', 'status' => 403 );
		}
		if ( ! self::premium( $member_id ) ) {
			$latest = 0;
			foreach ( $rows as $row ) {
				if ( $row['calculator'] === $found['calculator'] ) {
					$latest = max( $latest, (int) $row['id'] );
				}
			}
			if ( (int) $found['id'] !== $latest ) {
				return null;
			}
		}
		$public = self::public_row( $found, true );
		return $public;
	}

	/**
	 * @param string              $calculator Fixture key.
	 * @param array<string,mixed> $result     One saved result.
	 * @return array{headline:string,unit:string,band:string,calculator_version:string}
	 */
	private static function clear_columns( $calculator, array $result ) {
		$headline = '';
		$unit     = isset( $result['unit'] ) ? substr( (string) $result['unit'], 0, 64 ) : '';
		if ( isset( $result['result'] ) && is_numeric( $result['result'] ) ) {
			$headline = self::num( $result['result'] );
		} elseif ( isset( $result['systolic'], $result['diastolic'] ) ) {
			$headline = self::num( $result['systolic'] ) . '/' . self::num( $result['diastolic'] );
			if ( '' === $unit ) {
				$unit = 'mmHg';
			}
		} elseif ( isset( $result['vo2'] ) ) {
			$headline = self::num( $result['vo2'] );
		}
		$band = '';
		if ( isset( $result['band'] ) && is_string( $result['band'] ) && '' !== $result['band'] ) {
			$band = $result['band'];
		} elseif ( 'heart_risk' === $calculator && isset( $result['result'] ) ) {
			$band = self::heart_band( $result['result'] );
		} elseif ( 'bp_check' === $calculator && isset( $result['systolic'], $result['diastolic'] ) ) {
			$band = self::bp_label( self::bp_category( $result['systolic'], $result['diastolic'] ) );
		} elseif ( isset( $result['outcome'] ) && is_string( $result['outcome'] ) ) {
			$band = (string) $result['outcome'];
		}
		if ( '' === $band ) {
			$band = 'result';
		}
		$version = isset( $result['engine_version'] ) && '' !== (string) $result['engine_version'] ? (string) $result['engine_version'] : self::VERSION;
		return array(
			'headline'           => substr( $headline, 0, 64 ),
			'unit'               => $unit,
			'band'               => substr( $band, 0, 64 ),
			'calculator_version' => substr( $version, 0, 64 ),
		);
	}

	/**
	 * The comparison between the previous result and the latest one.
	 *
	 * @param string              $calculator Fixture key.
	 * @param array<string,mixed> $previous   Earlier result.
	 * @param array<string,mixed> $latest     Later result.
	 * @return array<string,mixed>
	 */
	public static function compare( $calculator, array $previous, array $latest ) {
		$calculator = self::key( $calculator );
		if ( 'heart_risk' === $calculator && isset( $previous['model'], $latest['model'] ) && (string) $previous['model'] !== (string) $latest['model'] ) {
			return array(
				'wording'             => 'Not directly comparable',
				'comparable'          => false,
				'better_worse_label'  => null,
			);
		}
		$note = null;
		if ( isset( $previous['engine_version'], $latest['engine_version'] ) && (string) $previous['engine_version'] !== (string) $latest['engine_version'] ) {
			$note = 'compared with an earlier version';
		}
		$out = array( 'comparable' => true );
		if ( null !== $note ) {
			$out['earlier_version_note'] = $note;
		}

		if ( 'heart_risk' === $calculator ) {
			return array_merge( $out, self::compare_heart( $previous, $latest ) );
		}
		if ( 'bp_check' === $calculator ) {
			return array_merge( $out, self::compare_bp( $previous, $latest ) );
		}
		if ( 'fitness_age' === $calculator ) {
			return array_merge( $out, self::compare_fitness( $previous, $latest ) );
		}
		if ( 'metabolic' === $calculator ) {
			return array_merge( $out, self::compare_metabolic( $previous, $latest ) );
		}
		if ( 'healthy_weight' === $calculator ) {
			return array_merge( $out, self::compare_bmi( $previous, $latest ) );
		}
		if ( 'diabetes_risk' === $calculator || 'diabetes' === $calculator ) {
			return array_merge( $out, self::compare_points( $previous, $latest, 1.0, false ) );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $previous Earlier result.
	 * @param array<string,mixed> $latest   Later result.
	 * @return array<string,mixed>
	 */
	private static function compare_heart( array $previous, array $latest ) {
		$from    = (int) round( (float) $previous['result'] );
		$to      = (int) round( (float) $latest['result'] );
		$band_a  = self::heart_band( $previous['result'] );
		$band_b  = self::heart_band( $latest['result'] );
		$delta   = $to - $from;
		$meaning = abs( $delta ) > 1 || $band_a !== $band_b;
		$out     = array(
			'shown_from'  => $from . ' %',
			'shown_to'    => $to . ' %',
			'band_from'   => $band_a,
			'band_to'     => $band_b,
			'band_change' => $band_a !== $band_b,
		);
		if ( ! $meaning ) {
			$out['wording'] = 'About the same (' . self::signed_points( $delta, true ) . ')';
			return $out;
		}
		if ( $delta < 0 ) {
			$since          = isset( $previous['date'] ) && '' !== (string) $previous['date'] ? ' since ' . $previous['date'] : '';
			$out['wording'] = 'Better: ' . self::points_word( abs( $delta ), true ) . ' lower' . $since;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $previous Earlier reading.
	 * @param array<string,mixed> $latest   Later reading.
	 * @return array<string,mixed>
	 */
	private static function compare_bp( array $previous, array $latest ) {
		$sys = (float) $latest['systolic'] - (float) $previous['systolic'];
		$dia = (float) $latest['diastolic'] - (float) $previous['diastolic'];
		$out = array(
			'category_from' => self::bp_label( self::bp_category( $previous['systolic'], $previous['diastolic'] ) ),
			'category_to'   => self::bp_label( self::bp_category( $latest['systolic'], $latest['diastolic'] ) ),
		);
		$meaning = abs( $sys ) >= 5 || abs( $dia ) >= 3;
		if ( ! $meaning ) {
			$out['wording'] = 'About the same (' . self::signed_plain( $sys ) . '/' . self::signed_plain( $dia ) . ')';
			return $out;
		}
		if ( $sys <= 0 && $dia <= 0 ) {
			$out['wording'] = 'Better: ' . self::num( abs( $sys ) ) . '/' . self::num( abs( $dia ) ) . ' lower';
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $previous Earlier result.
	 * @param array<string,mixed> $latest   Later result.
	 * @return array<string,mixed>
	 */
	private static function compare_fitness( array $previous, array $latest ) {
		$vo2_from = (float) $previous['vo2'];
		$vo2_to   = (float) $latest['vo2'];
		$age_from = (int) $previous['fitness_age'];
		$age_to   = (int) $latest['fitness_age'];
		$d_vo2    = round( $vo2_to - $vo2_from, 1 );
		$d_age    = $age_to - $age_from;
		$meaning  = abs( $d_vo2 ) >= 1.5 || abs( $d_age ) >= 2;
		if ( $meaning && $d_vo2 > 0 && $d_age < 0 ) {
			return array(
				'wording' => 'Better: ' . self::signed_plain( $d_vo2 ) . ' points; fitness age ' . $age_from . ' → ' . $age_to,
			);
		}
		if ( ! $meaning ) {
			return array( 'wording' => 'About the same (' . self::signed_plain( $d_vo2 ) . ')' );
		}
		// A fall in fitness uses the signed fragment. A mixed change has no frozen sentence.
		if ( $d_vo2 < 0 || $d_age > 0 ) {
			return array( 'wording' => self::MESSAGES['change.lower'] );
		}
		return array();
	}

	/**
	 * @param array<string,mixed> $previous Earlier result.
	 * @param array<string,mixed> $latest   Later result.
	 * @return array<string,mixed>
	 */
	private static function compare_metabolic( array $previous, array $latest ) {
		$from = (int) $previous['criteria_met'];
		$to   = (int) $latest['criteria_met'];
		if ( abs( $to - $from ) < 1 ) {
			return array( 'wording' => 'About the same' );
		}
		if ( $to > $from ) {
			return array();
		}
		$text = 'Better: ' . $from . ' → ' . $to . ' of 4';
		if ( isset( $previous['outcome'], $latest['outcome'] ) && 'met' === $previous['outcome'] && 'not met' === $latest['outcome'] ) {
			$text .= '; no longer meets the definition';
		}
		return array( 'wording' => $text );
	}

	/**
	 * BMI is never praised as weight lost (FR3-16 AS-04). The sentence is the signed fragment.
	 *
	 * @param array<string,mixed> $previous Earlier result.
	 * @param array<string,mixed> $latest   Later result.
	 * @return array<string,mixed>
	 */
	private static function compare_bmi( array $previous, array $latest ) {
		$from = round( (float) $previous['result'], 1 );
		$to   = round( (float) $latest['result'], 1 );
		$out  = array( 'weight_loss_praise' => false );
		if ( abs( $to - $from ) <= 0.5 ) {
			$out['wording'] = 'About the same (' . self::signed_plain( round( $to - $from, 1 ) ) . ')';
			return $out;
		}
		$distance = static function ( $bmi ) {
			if ( $bmi < 18.5 ) {
				return 18.5 - $bmi;
			}
			if ( $bmi > 25 ) {
				return $bmi - 25;
			}
			return 0.0;
		};
		if ( $distance( $to ) < $distance( $from ) ) {
			$out['wording'] = 'BMI ' . self::num( $from ) . ' → ' . self::num( $to ) . '. ' . self::MESSAGES['healthyWeight.comparison'] . '.';
		}
		return $out;
	}

	/**
	 * A one-number result such as diabetes risk. Whole points use the word "point"; a decimal in the
	 * "about the same" line does not (FX-SR-10, FX-SR-11).
	 *
	 * @param array<string,mixed> $previous Earlier result.
	 * @param array<string,mixed> $latest   Later result.
	 * @param float               $threshold Meaningful change, strictly greater for heart-style points.
	 * @param bool                $whole     Round the change to a whole point.
	 * @return array<string,mixed>
	 */
	private static function compare_points( array $previous, array $latest, $threshold, $whole ) {
		$delta = (float) $latest['result'] - (float) $previous['result'];
		if ( $whole ) {
			$delta = (float) ( (int) round( (float) $latest['result'] ) - (int) round( (float) $previous['result'] ) );
		}
		$meaning = abs( $delta ) > $threshold;
		if ( ! $meaning ) {
			$integer = abs( $delta - round( $delta ) ) < 0.001;
			return array( 'wording' => 'About the same (' . self::signed_points( $delta, $integer ) . ')' );
		}
		if ( $delta < 0 ) {
			return array( 'wording' => 'Better: ' . self::points_word( abs( $delta ), abs( $delta - round( $delta ) ) < 0.001 ) . ' lower' );
		}
		return array();
	}

	/**
	 * @param string                    $calculator Fixture key.
	 * @param bool                      $premium    Premium access.
	 * @param array<int,array<string,mixed>> $results Saved results, oldest first.
	 * @return array<string,mixed>
	 */
	private static function recheck( $calculator, $premium, array $results ) {
		$created = $premium && in_array( $calculator, self::RECHECK, true ) && $results;
		if ( ! $created ) {
			return array( 'reminder_created' => false );
		}
		$last  = $results[ count( $results ) - 1 ];
		$email = self::MESSAGES['recheck.email'];
		$out   = array(
			'reminder_created'        => true,
			'reminder_type'           => 'check_in',
			'days_after_last_saved'   => self::RECHECK_DAYS,
			'email_contains_number'   => 1 === preg_match( '/\d/', $email ),
			'email_contains_result'   => self::email_contains_result( $email, $last ),
			'email_names_calculator'  => self::email_names_calculator( $email ),
		);
		if ( isset( $last['date'] ) ) {
			$due = self::due_label( (string) $last['date'] );
			if ( null !== $due ) {
				$out['due_date'] = $due;
			}
		}
		return $out;
	}

	/**
	 * "1 Feb" with no year is read in a non-leap year, so 91 days later is "3 May" (FX-SR-12).
	 *
	 * @param string $label Day and month, for example "1 Feb".
	 * @return string|null
	 */
	private static function due_label( $label ) {
		$label = trim( $label );
		$when  = DateTimeImmutable::createFromFormat( '!j M Y', $label . ' 2026' );
		if ( ! $when ) {
			$when = DateTimeImmutable::createFromFormat( '!Y-m-d', $label );
		}
		if ( ! $when ) {
			return null;
		}
		return $when->modify( '+' . self::RECHECK_DAYS . ' days' )->format( 'j M' );
	}

	/**
	 * @param string              $email Mail text.
	 * @param array<string,mixed> $last  Latest result.
	 */
	private static function email_contains_result( $email, array $last ) {
		foreach ( array( 'result', 'systolic', 'vo2', 'criteria_met' ) as $key ) {
			if ( isset( $last[ $key ] ) && is_numeric( $last[ $key ] ) && false !== strpos( $email, self::num( $last[ $key ] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The generic line may say "calculator". It must not name one (heart, calorie, weight, and the rest).
	 *
	 * @param string $email Mail text.
	 */
	private static function email_names_calculator( $email ) {
		foreach ( array( 'heart', 'blood', 'calorie', 'metabolic', 'fitness', 'weight', 'diabetes', 'protein', 'sleep', 'stress', 'bmi' ) as $word ) {
			if ( false !== stripos( $email, $word ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Band on the value before it is rounded for display, so 9.8 is Moderate and 12.3 is High (D3-13 cuts, FX-SR-01).
	 *
	 * @param mixed $risk Raw percentage.
	 */
	private static function heart_band( $risk ) {
		$risk = (float) $risk;
		if ( $risk < 5 ) {
			return 'Low';
		}
		if ( $risk < 10 ) {
			return 'Moderate';
		}
		if ( $risk < 20 ) {
			return 'High';
		}
		if ( $risk < 30 ) {
			return 'Very high';
		}
		return '30 or more';
	}

	/**
	 * Office category cuts from the blood-pressure sheet (higher number decides). Duplicated so this class
	 * stays usable without loading that engine.
	 *
	 * @param mixed $sys Systolic.
	 * @param mixed $dia Diastolic.
	 * @return string grade2, grade1, high-normal or normal.
	 */
	private static function bp_category( $sys, $dia ) {
		$sys = (float) $sys;
		$dia = (float) $dia;
		foreach ( array( 'grade2' => array( 160, 100 ), 'grade1' => array( 140, 90 ), 'high-normal' => array( 130, 85 ) ) as $name => $cut ) {
			if ( $sys >= $cut[0] || $dia >= $cut[1] ) {
				return $name;
			}
		}
		return 'normal';
	}

	/**
	 * @param string $category grade1, grade2, high-normal or normal.
	 */
	private static function bp_label( $category ) {
		if ( 'grade1' === $category ) {
			return 'Grade 1';
		}
		if ( 'grade2' === $category ) {
			return 'Grade 2';
		}
		if ( 'high-normal' === $category ) {
			return 'High-normal';
		}
		return 'Normal';
	}

	/**
	 * @param array<string,mixed> $result One result.
	 */
	private static function shown_raw( array $result ) {
		if ( isset( $result['systolic'], $result['diastolic'] ) ) {
			$unit = isset( $result['unit'] ) ? (string) $result['unit'] : 'mmHg';
			return self::num( $result['systolic'] ) . '/' . self::num( $result['diastolic'] ) . ' ' . $unit;
		}
		if ( isset( $result['vo2'] ) ) {
			return self::num( $result['vo2'] ) . ' mL/kg/min';
		}
		if ( ! isset( $result['result'] ) ) {
			return '';
		}
		$unit = isset( $result['unit'] ) ? (string) $result['unit'] : '';
		return '' === $unit ? self::num( $result['result'] ) : self::num( $result['result'] ) . ' ' . $unit;
	}

	/**
	 * @param mixed $n     A number.
	 * @param bool  $whole Whole points take the word "point".
	 */
	private static function signed_points( $n, $whole ) {
		$n    = (float) $n;
		$sign = $n > 0 ? '+' : '';
		if ( ! $whole ) {
			return $sign . self::num( $n );
		}
		return $sign . self::points_word( abs( $n ), true );
	}

	/**
	 * @param mixed $n A number.
	 */
	private static function signed_plain( $n ) {
		$n = (float) $n;
		return ( $n > 0 ? '+' : '' ) . self::num( $n );
	}

	/**
	 * @param mixed $n     Absolute or raw amount.
	 * @param bool  $whole True when it is a whole number of points.
	 */
	private static function points_word( $n, $whole ) {
		$n = abs( (float) $n );
		if ( ! $whole ) {
			return self::num( $n ) . ' points';
		}
		$word = abs( $n - 1 ) < 0.001 ? 'point' : 'points';
		return self::num( $n ) . ' ' . $word;
	}

	/**
	 * @param mixed $n A number.
	 */
	private static function num( $n ) {
		$n = (float) $n;
		if ( abs( $n - round( $n ) ) < 0.001 ) {
			return (string) (int) round( $n );
		}
		$text = number_format( $n, 2, '.', '' );
		return rtrim( rtrim( $text, '0' ), '.' );
	}

	/**
	 * @param string $name Tool slug or fixture key.
	 */
	public static function key( $name ) {
		$name = strtolower( trim( (string) $name ) );
		$map  = array(
			'heart-risk'     => 'heart_risk',
			'bp-check'       => 'bp_check',
			'calorie-target' => 'calorie_target',
			'goal-timeline'  => 'goal_timeline',
			'fitness-age'    => 'fitness_age',
			'hr-zones'       => 'hr_zones',
			'sleep-stress'   => 'sleep_stress',
			'healthy-weight' => 'healthy_weight',
			'diabetes-risk'  => 'diabetes_risk',
		);
		return isset( $map[ $name ] ) ? $map[ $name ] : str_replace( '-', '_', $name );
	}

	/**
	 * @param string $calculator Fixture key.
	 */
	private static function is_stage1( $calculator ) {
		return in_array( self::key( $calculator ), self::STAGE1, true );
	}

	/**
	 * @param string $calculator Fixture key.
	 */
	private static function hidden_for_eating_disorder( $calculator ) {
		return in_array( self::key( $calculator ), self::HIDDEN_WHEN_EATING_DISORDER, true );
	}

	/**
	 * @param int    $member_id  Member number.
	 * @param string $calculator Fixture key.
	 */
	private static function blocked_for_member( $member_id, $calculator ) {
		if ( ! class_exists( 'LLD_Members_Stage3_Safety' ) ) {
			return false;
		}
		$slug = str_replace( '_', '-', self::key( $calculator ) );
		if ( 'diabetes_risk' === self::key( $calculator ) ) {
			$slug = 'diabetes-risk';
		}
		$situations = LLD_Members_Stage3_Safety::situations( (int) $member_id );
		return LLD_Members_Stage3_Safety::forbids( $situations, 'reminders', 'saved:' . $slug );
	}

	/**
	 * @param string              $calculator Fixture key.
	 * @param array<string,mixed> $engine     Engine result.
	 * @param array<string,mixed> $answers    Page answers.
	 * @return array<string,mixed>
	 */
	private static function snapshot_from_engine( $calculator, array $engine, array $answers ) {
		$snap = array( 'engine_version' => isset( $engine['version'] ) ? (string) $engine['version'] : self::VERSION );
		if ( 'heart_risk' === $calculator && isset( $engine['risk'] ) && is_numeric( $engine['risk'] ) ) {
			$snap['result'] = (float) $engine['risk'];
			$snap['unit']   = '%';
			$snap['model']  = isset( $answers['model'] ) ? (string) $answers['model'] : ( isset( $engine['model'] ) ? (string) $engine['model'] : 'lab' );
		} elseif ( 'bp_check' === $calculator ) {
			$sys = isset( $engine['average']['systolic'] ) ? $engine['average']['systolic'] : ( isset( $engine['systolic'] ) ? $engine['systolic'] : null );
			$dia = isset( $engine['average']['diastolic'] ) ? $engine['average']['diastolic'] : ( isset( $engine['diastolic'] ) ? $engine['diastolic'] : null );
			if ( null !== $sys && null !== $dia ) {
				$snap['systolic']  = (float) $sys;
				$snap['diastolic'] = (float) $dia;
				$snap['unit']      = 'mmHg';
			}
		} elseif ( 'fitness_age' === $calculator && isset( $engine['vo2'], $engine['fitnessAge'] ) ) {
			$snap['vo2']         = (float) $engine['vo2'];
			$snap['fitness_age'] = (int) $engine['fitnessAge'];
			$snap['result']      = (float) $engine['vo2'];
			$snap['unit']        = 'mL/kg/min';
		} elseif ( 'metabolic' === $calculator && isset( $engine['count'] ) ) {
			$snap['criteria_met'] = (int) $engine['count'];
			$snap['outcome']      = isset( $engine['result'] ) && is_string( $engine['result'] ) ? $engine['result'] : '';
			$snap['result']       = (int) $engine['count'];
			$snap['unit']         = 'of 4';
		} elseif ( 'calorie_target' === $calculator && isset( $engine['targetKcal'] ) && is_numeric( $engine['targetKcal'] ) ) {
			$snap['result'] = (float) $engine['targetKcal'];
			$snap['unit']   = 'kcal';
		} elseif ( isset( $engine['headline'] ) && is_numeric( $engine['headline'] ) ) {
			$snap['result'] = (float) $engine['headline'];
			$snap['unit']   = isset( $engine['unit'] ) ? (string) $engine['unit'] : '';
		}
		if ( isset( $answers['band'] ) && is_string( $answers['band'] ) ) {
			$snap['band'] = $answers['band'];
		}
		return $snap;
	}

	/**
	 * Drop anything that is not a page answer. Wording and notes are not inputs.
	 *
	 * @param array<string,mixed> $answers Page answers.
	 * @return array<string,mixed>
	 */
	private static function answers_only( array $answers ) {
		unset( $answers['save'], $answers['wording'], $answers['messages'], $answers['notes'] );
		return $answers;
	}

	/**
	 * @param int $member_id Member number.
	 * @return array<int,array<string,mixed>>
	 */
	private static function load_rows( $member_id ) {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$table = LLD_Members_Schema::table( 'calculator_result' );
		$cols  = 'id, member_id, calculator, saved_at, headline, unit, band, calculator_version';
		if ( self::has_inputs_column() ) {
			$cols .= ', inputs_ct';
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE member_id = %d ORDER BY id ASC", $member_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal, columns are fixed.
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string,mixed> $row    Database row.
	 * @param bool                $prefill Include opened answers for the owner.
	 * @return array<string,mixed>
	 */
	private static function public_row( array $row, $prefill ) {
		$public = array(
			'id'                 => (int) $row['id'],
			'calculator'         => (string) $row['calculator'],
			'saved_at'           => (string) $row['saved_at'],
			'headline'           => (string) $row['headline'],
			'unit'               => (string) $row['unit'],
			'band'               => (string) $row['band'],
			'calculator_version' => (string) $row['calculator_version'],
		);
		if ( $prefill && ! empty( $row['inputs_ct'] ) && class_exists( 'LLD_Members_Stage3_Data' ) ) {
			$opened = LLD_Members_Stage3_Data::open( (int) $row['member_id'], $row['inputs_ct'] );
			$parsed = is_string( $opened ) ? json_decode( $opened, true ) : null;
			if ( is_array( $parsed ) && isset( $parsed['answers'] ) && is_array( $parsed['answers'] ) ) {
				$public['answers'] = $parsed['answers'];
			}
		}
		return $public;
	}

	/**
	 * Rebuild the fixture result shape from a stored row, preferring the sealed snapshot.
	 *
	 * @param array<string,mixed> $row Database row, which may include member_id.
	 * @return array<string,mixed>
	 */
	private static function as_result( array $row ) {
		$snap = array();
		if ( ! empty( $row['inputs_ct'] ) && class_exists( 'LLD_Members_Stage3_Data' ) && isset( $row['member_id'] ) ) {
			$opened = LLD_Members_Stage3_Data::open( (int) $row['member_id'], $row['inputs_ct'] );
			$parsed = is_string( $opened ) ? json_decode( $opened, true ) : null;
			if ( is_array( $parsed ) && isset( $parsed['snapshot'] ) && is_array( $parsed['snapshot'] ) ) {
				$snap = $parsed['snapshot'];
			}
		}
		if ( ! isset( $snap['result'] ) && is_numeric( $row['headline'] ) ) {
			$snap['result'] = (float) $row['headline'];
		}
		if ( ! isset( $snap['unit'] ) ) {
			$snap['unit'] = (string) $row['unit'];
		}
		if ( ! isset( $snap['engine_version'] ) ) {
			$snap['engine_version'] = (string) $row['calculator_version'];
		}
		if ( ! isset( $snap['band'] ) ) {
			$snap['band'] = (string) $row['band'];
		}
		return $snap;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function premium( $member_id ) {
		if ( ! class_exists( 'LLD_Members_Food_Log' ) ) {
			return false;
		}
		return LLD_Members_Food_Log::premium( (int) $member_id );
	}

	/**
	 * The sealed-answers column is added by the Stage 3 schema update. Older databases still save the headline.
	 */
	private static function has_inputs_column() {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$table = LLD_Members_Schema::table( 'calculator_result' );
		$names = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return is_array( $names ) && in_array( 'inputs_ct', $names, true );
	}
}
