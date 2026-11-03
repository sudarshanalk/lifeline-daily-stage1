<?php
/**
 * The Monday report after the move to Google Analytics (P3-72, FR3-70).
 *
 * The Stage 2 report (LLD_Members_Growth, FR2-17) still builds and sends the email; this class only adds to it, so
 * every Stage 2 line and test stays as it was:
 * - before the Stage 2 report runs, the week's own figures are read (the Stage 2 report then starts a new week);
 * - while that one email is sent, a "Totals for the week" part is added: sign-ups, upgrades, renewals, pauses,
 *   cancellations, payments and refunds (from the orders Stripe confirmed, and Stripe's paid invoices when the
 *   real Stripe is connected) and member numbers;
 * - when Plausible is not connected, visits and calculator page views come from Google Analytics totals (Data
 *   API, read-only service account) instead of the "Plausible is not connected" line.
 * The report holds counts and money only: no names, emails, member numbers or health values (TC-FR3-70-AS-02).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Additions to the Monday report.
 */
class LLD_Members_Stage3_Report {

	const SUBJECT = 'Weekly report';

	const NOT_CONNECTED = 'Visits and calculator use: Plausible is not connected';

	const GA_OAUTH_URL = 'https://oauth2.googleapis.com/token';

	const GA_DATA = 'https://analyticsdata.googleapis.com/v1beta/properties/';

	/**
	 * Pages counted for each track, as in the Stage 2 report.
	 */
	const TRACK_PAGES = array(
		'healthy-weight' => array( '/programmes/healthy-weight/', '/coming-soon/healthy-weight/' ),
		'blood-sugar'    => array( '/programmes/blood-sugar/', '/coming-soon/blood-sugar/' ),
		'fitness'        => array( '/programmes/fitness/', '/coming-soon/fitness/' ),
		'sleep-stress'   => array( '/programmes/sleep-stress/', '/coming-soon/sleep-stress/' ),
	);

	/**
	 * Each calculator page and the track its landing page links to (the Stage 2 calculator_track map).
	 */
	const CALCULATOR_PAGES = array(
		'/health-tools/protein/'              => 'fitness',
		'/health-tools/diabetes-risk-check/'  => 'blood-sugar',
		'/health-tools/healthy-weight-check/' => 'healthy-weight',
	);

	/**
	 * The week's own figures, read just before the Stage 2 report resets them.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $week = null;

	public static function boot() {
		add_action( LLD_Members_Growth::REPORT_HOOK, array( __CLASS__, 'before_report' ), 5 );
		add_action( LLD_Members_Growth::REPORT_HOOK, array( __CLASS__, 'after_report' ), 20 );
		add_filter( 'wp_mail', array( __CLASS__, 'extend_mail' ) );
	}

	public static function before_report() {
		$figures    = LLD_Members_Growth::figures();
		self::$week = array(
			'range'   => self::report_week(),
			'figures' => $figures,
		);
	}

	public static function after_report() {
		self::$week = null;
	}

	/**
	 * Adds the Stage 3 part to the one report email sent during the Monday run, and keeps the full text.
	 *
	 * @param array<string,mixed> $mail wp_mail arguments.
	 * @return array<string,mixed>
	 */
	public static function extend_mail( $mail ) {
		if ( null === self::$week || ! is_array( $mail ) || self::SUBJECT !== ( $mail['subject'] ?? '' ) ) {
			return $mail;
		}
		$range   = self::$week['range'];
		$message = (string) ( $mail['message'] ?? '' );
		if ( 0 !== strpos( $message, 'Weekly report for ' . $range[0] . ' to ' . $range[1] ) ) {
			return $mail;
		}
		$message         = self::extend_text( $message, self::$week['figures'], $range, self::ga_figures( $range[0], $range[1] ) );
		$mail['message'] = $message;
		global $wpdb;
		$wpdb->update( LLD_Members_Schema::table( 'weekly_report' ), array( 'totals' => $message ), array( 'week_starting' => $range[0] ), array( '%s' ), array( '%s' ) );
		self::$week = null;
		return $mail;
	}

	/**
	 * @param string                                                       $text    Stage 2 report.
	 * @param array<string,mixed>                                          $figures LLD_Members_Growth::figures() for the week.
	 * @param array{0:string,1:string}                                     $range   First and last day.
	 * @param array{tracks:array<string,array<string,int>>,total:int}|null $ga      Google Analytics totals, or null.
	 */
	public static function extend_text( $text, $figures, $range, $ga ) {
		$lines = explode( "\n", (string) $text );
		$at    = array_search( self::NOT_CONNECTED, $lines, true );
		if ( false !== $at ) {
			$visits = array( null === $ga ? 'Visits and calculator use: Google Analytics is not connected' : 'Visits and calculator page views from Google Analytics' );
			if ( null !== $ga ) {
				$visits[] = 'All page views ' . (int) $ga['total'];
				foreach ( LLD_Members_Growth::TRACKS as $key => $label ) {
					$visits[] = $label . ' visits ' . (int) $ga['tracks'][ $key ]['visits'];
					$visits[] = $label . ' calculator page views ' . (int) $ga['tracks'][ $key ]['calculator'];
				}
			}
			array_splice( $lines, $at, 1, $visits );
		}
		$tracks = isset( $figures['tracks'] ) && is_array( $figures['tracks'] ) ? $figures['tracks'] : array();
		$sum    = static function ( $field ) use ( $tracks ) {
			$n = 0;
			foreach ( $tracks as $row ) {
				$n += is_array( $row ) ? (int) ( $row[ $field ] ?? 0 ) : 0;
			}
			return $n;
		};
		$pauses  = self::pause_figures( $range );
		$money   = self::money_figures( $range );
		$members = self::member_figures();
		$lines[] = '';
		$lines[] = 'Totals for the week';
		$lines[] = 'Sign-ups ' . (int) ( $figures['registrations'] ?? 0 );
		$lines[] = 'Upgrades ' . $sum( 'upgrades' );
		$lines[] = 'Renewals ' . $sum( 'renewals' );
		$lines[] = 'Pauses started ' . $pauses['started'];
		$lines[] = 'Pauses requested ' . $pauses['requested'];
		$lines[] = 'Cancellations ' . $sum( 'cancellations' );
		foreach ( $money['lines'] as $line ) {
			$lines[] = $line;
		}
		$lines[] = 'Members ' . $members['all'];
		$lines[] = 'Free members ' . $members['free'];
		$lines[] = 'Premium members ' . $members['premium'];
		$lines[] = 'Members on pause ' . $pauses['now'];
		return implode( "\n", $lines );
	}

	/**
	 * Monday to Sunday before this week, in the site's time zone (as the Stage 2 report).
	 *
	 * @return array{0:string,1:string}
	 */
	public static function report_week() {
		$monday = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( 'monday this week' );
		return array( $monday->modify( '-7 days' )->format( 'Y-m-d' ), $monday->modify( '-1 day' )->format( 'Y-m-d' ) );
	}

	/**
	 * @param array{0:string,1:string} $range Week.
	 * @return array{started:int,requested:int,now:int}
	 */
	private static function pause_figures( $range ) {
		global $wpdb;
		$table = LLD_Members_Stage3_Schema::table( 'pauses' );
		$live  = "status NOT IN ('cancelled','withdrawn')";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and fixed status list.
		$started   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$live} AND starts_at BETWEEN %s AND %s", $range[0], $range[1] ) );
		$requested = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at BETWEEN %s AND %s", $range[0] . ' 00:00:00', $range[1] . ' 23:59:59' ) );
		$today     = wp_date( 'Y-m-d' );
		$now       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$live} AND starts_at <= %s AND ( ends_at IS NULL OR ends_at > %s )", $today, $today ) );
		// phpcs:enable
		return array(
			'started'   => $started,
			'requested' => $requested,
			'now'       => $now,
		);
	}

	/**
	 * Payments and refunds of the week from the orders Stripe confirmed, per currency; and Stripe's own paid
	 * invoices when the real Stripe is connected, so the two can be compared.
	 *
	 * @param array{0:string,1:string} $range Week.
	 * @return array{lines:string[]}
	 */
	private static function money_figures( $range ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'order' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT kind, charged_currency, COUNT(*) AS n, SUM(ABS(charged_amount)) AS total FROM {$table} WHERE kind IN ('payment','refund') AND status IN ('succeeded','refunded') AND ordered_at BETWEEN %s AND %s GROUP BY kind, charged_currency ORDER BY kind, charged_currency", $range[0] . ' 00:00:00', $range[1] . ' 23:59:59' ) );
		$lines = array();
		foreach ( array( 'payment' => 'Payments', 'refund' => 'Refunds' ) as $kind => $label ) {
			$found = false;
			foreach ( (array) $rows as $row ) {
				if ( (string) $row->kind !== $kind ) {
					continue;
				}
				$found   = true;
				$lines[] = $label . ' ' . strtoupper( (string) $row->charged_currency ) . ' ' . self::money( (int) $row->total ) . ' (' . (int) $row->n . ')';
			}
			if ( ! $found ) {
				$lines[] = $label . ' none';
			}
		}
		$stripe = self::stripe_invoices( $range );
		if ( null === $stripe ) {
			$lines[] = 'Stripe paid invoices: Stripe is not connected';
		} elseif ( ! $stripe ) {
			$lines[] = 'Stripe paid invoices none';
		} else {
			foreach ( $stripe as $currency => $sum ) {
				$lines[] = 'Stripe paid invoices ' . strtoupper( $currency ) . ' ' . self::money( $sum['total'] ) . ' (' . $sum['n'] . ')';
			}
		}
		return array( 'lines' => $lines );
	}

	/**
	 * @param array{0:string,1:string} $range Week.
	 * @return array<string,array{total:int,n:int}>|null Null when the real Stripe is not connected or does not answer.
	 */
	private static function stripe_invoices( $range ) {
		if ( ! class_exists( 'LLD_Members_Stripe_API' ) || ! LLD_Members_Stripe_API::on( 0 ) ) {
			return null;
		}
		$zone  = wp_timezone();
		$from  = ( new DateTimeImmutable( $range[0] . ' 00:00:00', $zone ) )->getTimestamp();
		$to    = ( new DateTimeImmutable( $range[1] . ' 00:00:00', $zone ) )->modify( '+1 day' )->getTimestamp();
		$sums  = array();
		$after = '';
		for ( $page = 0; $page < 20; $page++ ) {
			$params = array(
				'status'  => 'paid',
				'created' => array(
					'gte' => $from,
					'lt'  => $to,
				),
				'limit'   => 100,
			);
			if ( '' !== $after ) {
				$params['starting_after'] = $after;
			}
			$list = LLD_Members_Stripe_API::request( 'GET', 'invoices', $params );
			if ( is_wp_error( $list ) || ! isset( $list['data'] ) || ! is_array( $list['data'] ) ) {
				return null;
			}
			foreach ( $list['data'] as $invoice ) {
				$currency = strtolower( (string) ( $invoice['currency'] ?? '' ) );
				$paid     = (int) ( $invoice['amount_paid'] ?? 0 );
				if ( '' === $currency || $paid <= 0 ) {
					continue;
				}
				$sums[ $currency ] = array(
					'total' => ( $sums[ $currency ]['total'] ?? 0 ) + $paid,
					'n'     => ( $sums[ $currency ]['n'] ?? 0 ) + 1,
				);
				$after = (string) ( $invoice['id'] ?? '' );
			}
			if ( empty( $list['has_more'] ) || '' === $after ) {
				break;
			}
		}
		ksort( $sums );
		return $sums;
	}

	/**
	 * @return array{all:int,free:int,premium:int}
	 */
	private static function member_figures() {
		global $wpdb;
		$counts  = count_users();
		$all     = (int) ( $counts['avail_roles']['member'] ?? 0 );
		$premium = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'subscription' ) . " WHERE access_state = 'Premium'" );
		return array(
			'all'     => $all,
			'free'    => max( 0, $all - $premium ),
			'premium' => $premium,
		);
	}

	/**
	 * @param int $cents Amount in the currency's smallest unit.
	 */
	private static function money( $cents ) {
		return number_format( $cents / 100, 2, '.', '' );
	}

	/**
	 * Google Analytics page views for the week: every page, each track's pages and each calculator page. Needs
	 * LLD_GA4_PROPERTY (the property number) and LLD_GA4_KEY_FILE (a read-only service account's JSON key, kept
	 * outside the web root).
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return array{tracks:array<string,array{visits:int,calculator:int}>,total:int}|null
	 */
	public static function ga_figures( $from, $to ) {
		$property = defined( 'LLD_GA4_PROPERTY' ) ? preg_replace( '/\D/', '', (string) LLD_GA4_PROPERTY ) : '';
		$token    = '' === $property ? '' : self::ga_token();
		if ( '' === $token ) {
			return null;
		}
		$res = wp_remote_post(
			self::GA_DATA . $property . ':runReport',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'dateRanges' => array(
							array(
								'startDate' => $from,
								'endDate'   => $to,
							),
						),
						'dimensions' => array( array( 'name' => 'pagePath' ) ),
						'metrics'    => array( array( 'name' => 'screenPageViews' ) ),
						'limit'      => 10000,
					)
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$out   = array();
		$total = 0;
		foreach ( array_keys( LLD_Members_Growth::TRACKS ) as $track ) {
			$out[ $track ] = array(
				'visits'     => 0,
				'calculator' => 0,
			);
		}
		foreach ( isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array() as $row ) {
			$path  = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
			$views = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			$total += $views;
			$path  = '/' . trim( $path, '/' ) . '/';
			foreach ( self::TRACK_PAGES as $track => $pages ) {
				if ( in_array( $path, $pages, true ) ) {
					$out[ $track ]['visits'] += $views;
				}
			}
			if ( isset( self::CALCULATOR_PAGES[ $path ] ) ) {
				$out[ self::CALCULATOR_PAGES[ $path ] ]['visits']     += $views;
				$out[ self::CALCULATOR_PAGES[ $path ] ]['calculator'] += $views;
			}
		}
		return array(
			'tracks' => $out,
			'total'  => $total,
		);
	}

	/**
	 * A one-hour access token for the service account (OAuth 2.0 JWT bearer grant, RS256).
	 */
	private static function ga_token() {
		$file = defined( 'LLD_GA4_KEY_FILE' ) ? (string) LLD_GA4_KEY_FILE : '';
		if ( '' === $file || ! is_readable( $file ) || ! function_exists( 'openssl_sign' ) ) {
			return '';
		}
		$key = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local key file.
		if ( ! is_array( $key ) || empty( $key['client_email'] ) || empty( $key['private_key'] ) ) {
			return '';
		}
		$b64    = static function ( $s ) {
			return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding.
		};
		$now    = time();
		$head   = $b64( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = $b64(
			wp_json_encode(
				array(
					'iss'   => (string) $key['client_email'],
					'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
					'aud'   => self::GA_OAUTH_URL,
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
		$sig    = '';
		if ( ! openssl_sign( $head . '.' . $claims, $sig, (string) $key['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return '';
		}
		$res = wp_remote_post(
			self::GA_OAUTH_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $head . '.' . $claims . '.' . $b64( $sig ),
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return '';
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $body ) && ! empty( $body['access_token'] ) ? (string) $body['access_token'] : '';
	}
}
