<?php
/**
 * Stage 3 rows in 'Download my data' and confirmation that shares die with the account (P3-86, FR3-60, FR3-61).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extends the Stage 2 download list with Stage 3 food, health, goals and related tables.
 */
class LLD_Members_Stage3_Export {

	/**
	 * Stage 3 suffixes that belong in the member download (FR3-60), whatever the plan.
	 * Older-than-14-days rows are included — Free history limits are display-only.
	 *
	 * @return string[]
	 */
	public static function download_suffixes() {
		return array(
			'goals',
			'goal_baselines',
			'plans',
			'plan_adjustments',
			'today_steps',
			'insights',
			'custom_foods',
			'food_log',
			'food_favourites',
			'nutrition_targets',
			'recipes',
			'food_preferences',
			'readings',
			'reminders',
			'shares',
		);
	}

	public static function boot() {
		add_filter( 'lld_members_download_tables', array( __CLASS__, 'download_tables' ) );
		add_filter( 'lld_members_download_rows', array( __CLASS__, 'open_sealed' ), 10, 3 );
	}

	/**
	 * @param string[] $tables Stage 2 download list.
	 * @return string[]
	 */
	public static function download_tables( $tables ) {
		$tables = is_array( $tables ) ? $tables : array();
		// Behind its feature like every Stage 3 change, so the Stage 2 download is unchanged while it is off (NFR3-01).
		if ( class_exists( 'LLD_Members_Features' ) && ! LLD_Members_Features::enabled( 'consent_new' ) ) {
			return $tables;
		}
		return array_values( array_unique( array_merge( $tables, self::download_suffixes() ) ) );
	}

	/**
	 * Open Stage 3 sealed columns in download rows so numbers match the screen (FR3-60 AS-02).
	 *
	 * @param array<string,array<int,array<string,mixed>>> $tables    Rows by suffix.
	 * @param int                                          $member_id Member number.
	 * @param string                                       $email     Unused.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public static function open_sealed( $tables, $member_id, $email = '' ) {
		unset( $email );
		if ( ! is_array( $tables ) ) {
			return array();
		}
		$member_id = (int) $member_id;
		$sealed_map = array(
			'goals'              => array( 'start_value', 'target_value', 'start_value_2', 'target_value_2' ),
			'readings'           => array( 'value_1', 'value_2', 'value_3', 'detail' ),
			'food_log'           => array(),
			'nutrition_targets'  => array(),
			'custom_foods'       => array(),
			'recipes'            => array(),
			'calculator_result'  => array( 'inputs', 'result' ),
		);
		// Prefer Stage3_Data open when the table uses _ct columns.
		foreach ( self::download_suffixes() as $suffix ) {
			if ( empty( $tables[ $suffix ] ) || ! is_array( $tables[ $suffix ] ) ) {
				continue;
			}
			$sealed = isset( $sealed_map[ $suffix ] ) ? $sealed_map[ $suffix ] : array();
			if ( ! $sealed && class_exists( 'LLD_Members_Stage3_API' ) ) {
				$types = LLD_Members_Stage3_API::types();
				foreach ( $types as $def ) {
					if ( isset( $def['table'] ) && $def['table'] === $suffix && ! empty( $def['sealed'] ) ) {
						$sealed = $def['sealed'];
						break;
					}
				}
			}
			if ( ! $sealed ) {
				continue;
			}
			foreach ( $tables[ $suffix ] as $i => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				foreach ( $sealed as $field ) {
					$ct = $field . '_ct';
					if ( ! empty( $row[ $ct ] ) && class_exists( 'LLD_Members_Stage3_Data' ) ) {
						$opened = LLD_Members_Stage3_Data::open( $member_id, $row[ $ct ] );
						if ( null !== $opened && '' !== $opened ) {
							$row[ $field ] = $opened;
						}
						unset( $row[ $ct ] );
					}
				}
				$tables[ $suffix ][ $i ] = $row;
			}
		}
		return $tables;
	}

	/**
	 * Whether any share token for this member still resolves (should be false after account erase).
	 *
	 * @param string $token Share token.
	 */
	public static function share_alive( $token ) {
		return null !== LLD_Members_Shares::resolve( $token );
	}
}
