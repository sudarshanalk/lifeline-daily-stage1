<?php
/**
 * Barcode lookup (P3-44 · FR3-37): the product behind a scanned or typed barcode.
 *
 * The phone's browser reads the barcode (assets/stage3.js); this class only finds the product. A barcode is cleaned to
 * digits, its GS1 check digit is checked, and every equivalent form is looked up: UPC-A is EAN-13 with a leading 0,
 * GTIN-14 adds more leading zeros and UPC-E is a shortened UPC-A. The member's own product with that barcode comes
 * first (they typed it from the pack), then the shared food table. Nothing found → the screen offers "Add this
 * product", which saves a custom food carrying the barcode, so the next scan finds it (TC-FR3-37-AS-02).
 *
 * Premium only (FR3-63, LLD_Members_Access_Tiers 'barcode'); the safety matrix hides the calories for members it
 * says must not see them ('barcode_calories', FR3-69). Looking up a product reads no health data, so it needs no health
 * consent; logging it does, through the food log.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Barcode cleaning and product lookup.
 */
class LLD_Members_Barcode {

	/** Nutrient keys sent for a product, per 100 g (shared foods) or per portion (the member's own). */
	const NUTRIENTS = array( 'kcal', 'protein_g', 'carb_g', 'fat_g', 'fibre_g' );

	/** Wording the scan screen shows. Plain and calm; nothing here is clinical. */
	const MESSAGES = array(
		'not_found'      => "We don't have this product yet. You can add it from the pack, and next time it will be found.",
		'add'            => 'Add this product',
		'invalid'        => "That doesn't look like a whole barcode. Check the numbers under the bars and try again.",
		'camera_refused' => "Scanning needs the camera, and camera access wasn't allowed. You can type the barcode number or search for the food instead.",
		'camera_missing' => "This browser can't use the camera for scanning. You can type the barcode number or search for the food instead.",
		'logged'         => 'Added to your food diary.',
	);

	/**
	 * Digits only, a barcode length (EAN-8, UPC-E, UPC-A, EAN-13, GTIN-14) and a correct check digit, or ''.
	 *
	 * @param mixed $raw Scanned or typed value.
	 */
	public static function clean( $raw ) {
		$digits = preg_replace( '/\D/', '', is_scalar( $raw ) ? (string) $raw : '' );
		if ( ! is_string( $digits ) || ! in_array( strlen( $digits ), array( 8, 12, 13, 14 ), true ) ) {
			return '';
		}
		if ( self::check_ok( $digits ) ) {
			return $digits;
		}
		// An 8-digit UPC-E has its check digit from the UPC-A it shortens.
		if ( 8 === strlen( $digits ) && '' !== self::upce_to_upca( $digits ) ) {
			return $digits;
		}
		return '';
	}

	/**
	 * GS1 check digit: weights 3 and 1 from the right, the last digit makes the sum a multiple of 10.
	 *
	 * @param string $digits Digits.
	 */
	public static function check_ok( $digits ) {
		$len = strlen( $digits );
		if ( $len < 8 || ! ctype_digit( $digits ) ) {
			return false;
		}
		$sum = 0;
		for ( $i = $len - 2, $w = 3; $i >= 0; $i--, $w = 4 - $w ) {
			$sum += (int) $digits[ $i ] * $w;
		}
		return ( ( 10 - $sum % 10 ) % 10 ) === (int) $digits[ $len - 1 ];
	}

	/**
	 * Expand a UPC-E (number system 0 or 1) into its 12-digit UPC-A, or '' when it is not one.
	 *
	 * @param string $e Eight digits.
	 */
	public static function upce_to_upca( $e ) {
		if ( 8 !== strlen( $e ) || ! ctype_digit( $e ) || ! in_array( $e[0], array( '0', '1' ), true ) ) {
			return '';
		}
		$d    = substr( $e, 1, 6 );
		$last = (int) $d[5];
		if ( $last <= 2 ) {
			$body = $d[0] . $d[1] . $d[5] . '0000' . $d[2] . $d[3] . $d[4];
		} elseif ( 3 === $last ) {
			$body = $d[0] . $d[1] . $d[2] . '00000' . $d[3] . $d[4];
		} elseif ( 4 === $last ) {
			$body = $d[0] . $d[1] . $d[2] . $d[3] . '00000' . $d[4];
		} else {
			$body = $d[0] . $d[1] . $d[2] . $d[3] . $d[4] . '0000' . $d[5];
		}
		$upca = $e[0] . $body . $e[7];
		return self::check_ok( $upca ) ? $upca : '';
	}

	/**
	 * Every stored form the same product can have: as read, without leading zeros padded to 8, 12, 13 and 14 digits,
	 * and a UPC-E's UPC-A. A cleaned barcode only.
	 *
	 * @param string $code Cleaned barcode.
	 * @return string[]
	 */
	public static function forms( $code ) {
		if ( '' === $code ) {
			return array();
		}
		$codes = array( $code );
		if ( 8 === strlen( $code ) ) {
			$upca = self::upce_to_upca( $code );
			if ( '' !== $upca ) {
				$codes[] = $upca;
			}
		}
		$out = array();
		foreach ( $codes as $c ) {
			$core = ltrim( $c, '0' );
			foreach ( array( 8, 12, 13, 14 ) as $len ) {
				if ( strlen( $core ) <= $len ) {
					$out[] = str_pad( $core, $len, '0', STR_PAD_LEFT );
				}
			}
			$out[] = $c;
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/**
	 * The lookup itself, without WordPress, for tests: the member's own product first, then the shared foods.
	 *
	 * @param array<int,array<string,mixed>> $foods     Shared food rows (food table shape: kcal_100g …, portions).
	 * @param array<int,array<string,mixed>> $custom    Custom food rows of any member.
	 * @param int                            $member_id The member scanning.
	 * @param mixed                          $raw       Scanned or typed barcode.
	 * @return array<string,mixed>
	 */
	public static function lookup( array $foods, array $custom, $member_id, $raw ) {
		$code = self::clean( $raw );
		if ( '' === $code ) {
			return array(
				'error'  => self::MESSAGES['invalid'],
				'status' => 400,
			);
		}
		$forms = self::forms( $code );
		$own   = null;
		foreach ( $custom as $row ) {
			if ( ! is_array( $row ) || (int) ( $row['member_id'] ?? 0 ) !== (int) $member_id || ! empty( $row['deleted_at'] ) ) {
				continue;
			}
			if ( in_array( preg_replace( '/\D/', '', (string) ( $row['barcode'] ?? '' ) ), $forms, true ) && ( null === $own || (int) $row['id'] > (int) $own['id'] ) ) {
				$own = $row; // the newest one, if a member added the same product twice
			}
		}
		if ( null !== $own ) {
			return self::found( $code, self::custom_product( $own ) );
		}
		$best = null;
		foreach ( $foods as $row ) {
			if ( ! is_array( $row ) || ! in_array( preg_replace( '/\D/', '', (string) ( $row['barcode'] ?? '' ) ), $forms, true ) ) {
				continue;
			}
			if ( null === $best || self::better( $row, $best ) ) {
				$best = $row;
			}
		}
		if ( null !== $best ) {
			return self::found( $code, self::shared_product( $best ) );
		}
		return array(
			'found'   => false,
			'barcode' => $code,
			'message' => self::MESSAGES['not_found'],
			'offer'   => array(
				'label'  => self::MESSAGES['add'],
				'type'   => 'custom-foods',
				'fields' => array( 'barcode' => $code ),
			),
		);
	}

	/**
	 * The computed view the members' API serves at GET /me/food-barcode?code=…
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $query     code.
	 * @return array<string,mixed>
	 */
	public static function view( $member_id, array $query ) {
		$code = self::clean( $query['code'] ?? '' );
		if ( '' === $code ) {
			return self::lookup( array(), array(), $member_id, '' );
		}
		return self::lookup( self::shared_rows( self::forms( $code ) ), self::custom_rows( (int) $member_id, self::forms( $code ) ), $member_id, $code );
	}

	/**
	 * @param string              $code    Cleaned barcode.
	 * @param array<string,mixed> $product Product.
	 */
	private static function found( $code, array $product ) {
		return array(
			'found'   => true,
			'barcode' => $code,
			'food'    => $product,
		);
	}

	/**
	 * Open Food Facts first (packaged foods), then the row with more nutrition filled in, then the lower id.
	 *
	 * @param array<string,mixed> $a Candidate.
	 * @param array<string,mixed> $b Current best.
	 */
	private static function better( array $a, array $b ) {
		$off = ( 'off' === ( $a['source'] ?? '' ) ) <=> ( 'off' === ( $b['source'] ?? '' ) );
		if ( 0 !== $off ) {
			return $off > 0;
		}
		$filled = static function ( $row ) {
			$n = 0;
			foreach ( array( 'kcal_100g', 'protein_100g', 'carb_100g', 'fat_100g', 'fibre_100g' ) as $k ) {
				$n += isset( $row[ $k ] ) && is_numeric( $row[ $k ] ) ? 1 : 0;
			}
			return $n;
		};
		$more = $filled( $a ) <=> $filled( $b );
		if ( 0 !== $more ) {
			return $more > 0;
		}
		return (int) ( $a['id'] ?? 0 ) < (int) ( $b['id'] ?? 0 );
	}

	/**
	 * A shared food as the screen needs it: nutrition per 100 g and the portions in grams.
	 *
	 * @param array<string,mixed> $row Food table row.
	 */
	private static function shared_product( array $row ) {
		$portions = $row['portions'] ?? array();
		if ( is_string( $portions ) ) {
			$decoded  = json_decode( $portions, true );
			$portions = is_array( $decoded ) ? $decoded : array();
		}
		$list = array();
		foreach ( is_array( $portions ) ? $portions : array() as $p ) {
			$grams = is_array( $p ) && isset( $p['grams'] ) && is_numeric( $p['grams'] ) ? (float) $p['grams'] : 0.0;
			$name  = is_array( $p ) ? trim( (string) ( $p['name'] ?? '' ) ) : '';
			if ( $grams > 0 && '' !== $name ) {
				$list[] = array( 'name' => $name, 'grams' => $grams );
			}
		}
		$has_100 = false;
		foreach ( $list as $p ) {
			$has_100 = $has_100 || 100.0 === (float) $p['grams'];
		}
		if ( ! $has_100 ) {
			$list[] = array( 'name' => '100 g', 'grams' => 100.0 );
		}
		$per = array();
		foreach ( array( 'kcal' => 'kcal_100g', 'protein_g' => 'protein_100g', 'carb_g' => 'carb_100g', 'fat_g' => 'fat_100g', 'fibre_g' => 'fibre_100g' ) as $key => $column ) {
			$per[ $key ] = isset( $row[ $column ] ) && is_numeric( $row[ $column ] ) ? round( (float) $row[ $column ], 'kcal' === $key ? 1 : 2 ) : null;
		}
		$source = (string) ( $row['source'] ?? '' );
		return array(
			'food_id'       => (int) ( $row['id'] ?? 0 ),
			'food_kind'     => 'food',
			'name'          => (string) ( $row['name'] ?? '' ),
			'brand'         => (string) ( $row['brand'] ?? '' ),
			'basis'         => 'per_100g',
			'basis_grams'   => 100.0,
			'per'           => $per,
			'portions'      => $list,
			'default_grams' => (float) $list[0]['grams'],
			'source_name'   => class_exists( 'LLD_Members_Food_Licence' ) ? LLD_Members_Food_Licence::name_for( $source ) : $source,
			'attribution'   => (string) ( $row['attribution'] ?? '' ),
		);
	}

	/**
	 * The member's own product: nutrition per portion, as they entered it from the pack.
	 *
	 * @param array<string,mixed> $row Custom food row.
	 */
	private static function custom_product( array $row ) {
		$grams = isset( $row['portion_g'] ) && is_numeric( $row['portion_g'] ) && (float) $row['portion_g'] > 0 ? (float) $row['portion_g'] : 100.0;
		$per   = array();
		foreach ( self::NUTRIENTS as $key ) {
			$per[ $key ] = isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ? round( (float) $row[ $key ], 'kcal' === $key ? 1 : 2 ) : null;
		}
		$label = ( floor( $grams ) === $grams ? (string) (int) $grams : (string) $grams ) . ' g';
		return array(
			'food_id'       => (int) ( $row['id'] ?? 0 ),
			'food_kind'     => 'custom',
			'name'          => (string) ( $row['name'] ?? '' ),
			'brand'         => '',
			'basis'         => 'per_portion',
			'basis_grams'   => $grams,
			'per'           => $per,
			'portions'      => array( array( 'name' => 'Your portion, ' . $label, 'grams' => $grams ) ),
			'default_grams' => $grams,
			'source_name'   => 'Added by you',
			'attribution'   => '',
		);
	}

	/**
	 * Shared food rows with one of these barcodes.
	 *
	 * @param string[] $forms Barcode forms.
	 * @return array<int,array<string,mixed>>
	 */
	private static function shared_rows( array $forms ) {
		global $wpdb;
		if ( ! $forms || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! class_exists( 'LLD_Members_Stage3_Schema' ) ) {
			return array();
		}
		$table = LLD_Members_Stage3_Schema::table( 'foods' );
		$marks = implode( ', ', array_fill( 0, count( $forms ), '%s' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, source, name, brand, barcode, portions, kcal_100g, protein_100g, carb_100g, fat_100g, fibre_100g, attribution FROM {$table} WHERE barcode IN ({$marks}) ORDER BY id ASC LIMIT 20", $forms ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- internal table name; one placeholder per form.
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The member's own products with one of these barcodes.
	 *
	 * @param int      $member_id Member number.
	 * @param string[] $forms     Barcode forms.
	 * @return array<int,array<string,mixed>>
	 */
	private static function custom_rows( $member_id, array $forms ) {
		global $wpdb;
		if ( ! $forms || $member_id < 1 || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! class_exists( 'LLD_Members_Stage3_Schema' ) ) {
			return array();
		}
		$table = LLD_Members_Stage3_Schema::table( 'custom_foods' );
		$marks = implode( ', ', array_fill( 0, count( $forms ), '%s' ) );
		$args  = array_merge( array( $member_id ), $forms );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, member_id, name, barcode, portion_g, kcal, protein_g, carb_g, fat_g, fibre_g, deleted_at FROM {$table} WHERE member_id = %d AND deleted_at IS NULL AND barcode IN ({$marks}) ORDER BY id DESC LIMIT 5", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- internal table name; one placeholder per form.
		return is_array( $rows ) ? $rows : array();
	}
}
