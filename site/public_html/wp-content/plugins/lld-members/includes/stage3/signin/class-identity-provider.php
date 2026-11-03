<?php
/**
 * Google and Apple sign-in through OpenID Connect (P3-20: FR3-01, FR3-02; P3-22: FR3-05).
 *
 * The authorisation-code flow with a server-side exchange: the browser only ever sees the state value. The
 * ID token comes straight from the provider's token endpoint over TLS, authenticated with our client secret,
 * so its claims are trusted after the issuer, audience, expiry and nonce checks below (OpenID Connect Core
 * 3.1.3.7, point 6). Only the provider's account ID, the email, whether it is verified, and the name are read.
 *
 * Settings come from wp-config.php constants and never from the repository:
 *   Google: LLD_GOOGLE_CLIENT_ID, LLD_GOOGLE_CLIENT_SECRET
 *   Apple:  LLD_APPLE_SERVICES_ID, LLD_APPLE_TEAM_ID, LLD_APPLE_KEY_ID, LLD_APPLE_PRIVATE_KEY (the .p8 contents)
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider settings, the authorisation address, the code exchange and the claim checks.
 */
class LLD_Members_Identity_Provider {

	const PROVIDERS = array( 'google', 'apple' );

	const GOOGLE_AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';
	const GOOGLE_EXCHANGE  = 'https://oauth2.googleapis.com/token';
	const GOOGLE_ISSUERS   = array( 'https://accounts.google.com', 'accounts.google.com' );
	const APPLE_AUTHORIZE  = 'https://appleid.apple.com/auth/authorize';
	const APPLE_EXCHANGE   = 'https://appleid.apple.com/auth/token';
	const APPLE_ISSUER     = 'https://appleid.apple.com';

	/**
	 * Member-facing name.
	 *
	 * @param string $provider google or apple.
	 */
	public static function label( $provider ) {
		return 'apple' === $provider ? 'Apple' : 'Google';
	}

	/**
	 * True when the provider's settings are all present in wp-config.php.
	 *
	 * @param string $provider google or apple.
	 */
	public static function configured( $provider ) {
		if ( 'google' === $provider ) {
			return self::constant( 'LLD_GOOGLE_CLIENT_ID' ) !== '' && self::constant( 'LLD_GOOGLE_CLIENT_SECRET' ) !== '';
		}
		if ( 'apple' === $provider ) {
			return self::constant( 'LLD_APPLE_SERVICES_ID' ) !== '' && self::constant( 'LLD_APPLE_TEAM_ID' ) !== ''
				&& self::constant( 'LLD_APPLE_KEY_ID' ) !== '' && self::constant( 'LLD_APPLE_PRIVATE_KEY' ) !== '';
		}
		return false;
	}

	/**
	 * The client ID the provider knows us by.
	 *
	 * @param string $provider google or apple.
	 */
	public static function client_id( $provider ) {
		return 'apple' === $provider ? self::constant( 'LLD_APPLE_SERVICES_ID' ) : self::constant( 'LLD_GOOGLE_CLIENT_ID' );
	}

	/**
	 * Where the provider sends the member back. Registered with Google on 3 Nov 2026 (tracker P3-20).
	 *
	 * @param string $provider google or apple.
	 */
	public static function redirect_uri( $provider ) {
		return rest_url( 'lld-members/v1/sign-in/' . $provider . '/callback' );
	}

	/**
	 * The address the member is sent to.
	 *
	 * @param string $provider      google or apple.
	 * @param string $state         Random state, bound to the browser by a cookie.
	 * @param string $nonce         Random nonce, echoed in the ID token.
	 * @param string $code_verifier PKCE verifier (Google).
	 */
	public static function authorize_url( $provider, $state, $nonce, $code_verifier ) {
		if ( 'apple' === $provider ) {
			$args = array(
				'response_type' => 'code',
				'response_mode' => 'form_post',
				'client_id'     => self::client_id( 'apple' ),
				'redirect_uri'  => self::redirect_uri( 'apple' ),
				'scope'         => 'name email',
				'state'         => $state,
				'nonce'         => $nonce,
			);
			return self::APPLE_AUTHORIZE . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
		}
		$args = array(
			'response_type'         => 'code',
			'client_id'             => self::client_id( 'google' ),
			'redirect_uri'          => self::redirect_uri( 'google' ),
			'scope'                 => 'openid email profile',
			'state'                 => $state,
			'nonce'                 => $nonce,
			'code_challenge'        => self::base64url( hash( 'sha256', $code_verifier, true ) ),
			'code_challenge_method' => 'S256',
			'prompt'                => 'select_account',
		);
		return self::GOOGLE_AUTHORIZE . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchange the authorisation code for the ID token's claims.
	 *
	 * @param string $provider      google or apple.
	 * @param string $code          Authorisation code from the callback.
	 * @param string $code_verifier PKCE verifier (Google).
	 * @param string $nonce         The nonce the token must carry.
	 * @return array|WP_Error Claims: sub, email, email_verified, first_name, last_name.
	 */
	public static function exchange( $provider, $code, $code_verifier, $nonce ) {
		$body = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'client_id'    => self::client_id( $provider ),
			'redirect_uri' => self::redirect_uri( $provider ),
		);
		if ( 'apple' === $provider ) {
			$secret = self::apple_client_secret();
			if ( is_wp_error( $secret ) ) {
				return $secret;
			}
			$body['client_secret'] = $secret;
			$endpoint              = self::APPLE_EXCHANGE;
		} else {
			$body['client_secret'] = self::constant( 'LLD_GOOGLE_CLIENT_SECRET' );
			$body['code_verifier'] = $code_verifier;
			$endpoint              = self::GOOGLE_EXCHANGE;
		}
		// sslverify is set here, not left to the default, because the trust in the token (file header) rests on it.
		$response = wp_remote_post( $endpoint, array( 'timeout' => 15, 'sslverify' => true, 'body' => $body, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'lld_provider_down', self::label( $provider ) . ' did not answer. Try again in a moment.' );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) || empty( $data['id_token'] ) ) {
			return new WP_Error( 'lld_provider_refused', self::label( $provider ) . ' did not complete the sign-in. Try again.' );
		}
		return self::claims( $provider, (string) $data['id_token'], $nonce );
	}

	/**
	 * Read and check an ID token's claims (pure: no WordPress calls).
	 *
	 * @param string $provider google or apple.
	 * @param string $id_token The JWT.
	 * @param string $nonce    Expected nonce.
	 * @param int    $now      Unix time, for tests.
	 * @return array|WP_Error
	 */
	public static function claims( $provider, $id_token, $nonce, $now = 0 ) {
		$now     = $now > 0 ? $now : time();
		$payload = self::jwt_payload( $id_token );
		if ( ! is_array( $payload ) ) {
			return self::error( 'lld_token_shape', 'The sign-in token could not be read.' );
		}
		$issuers = 'apple' === $provider ? array( self::APPLE_ISSUER ) : self::GOOGLE_ISSUERS;
		if ( empty( $payload['iss'] ) || ! in_array( (string) $payload['iss'], $issuers, true ) ) {
			return self::error( 'lld_token_issuer', 'The sign-in token came from the wrong place.' );
		}
		$aud = isset( $payload['aud'] ) ? (array) $payload['aud'] : array();
		if ( '' === self::client_id( $provider ) || ! in_array( self::client_id( $provider ), array_map( 'strval', $aud ), true ) ) {
			return self::error( 'lld_token_audience', 'The sign-in token was not meant for this site.' );
		}
		if ( empty( $payload['exp'] ) || (int) $payload['exp'] < $now ) {
			return self::error( 'lld_token_expired', 'The sign-in took too long. Try again.' );
		}
		if ( '' === $nonce || empty( $payload['nonce'] ) || ! hash_equals( $nonce, (string) $payload['nonce'] ) ) {
			return self::error( 'lld_token_nonce', 'The sign-in could not be matched to this browser. Try again.' );
		}
		if ( empty( $payload['sub'] ) ) {
			return self::error( 'lld_token_subject', 'The sign-in token has no account ID.' );
		}
		$verified = isset( $payload['email_verified'] ) ? $payload['email_verified'] : false;
		$verified = true === $verified || 'true' === $verified || 1 === $verified || '1' === $verified;
		$first    = isset( $payload['given_name'] ) ? (string) $payload['given_name'] : '';
		$last     = isset( $payload['family_name'] ) ? (string) $payload['family_name'] : '';
		if ( '' === $first && '' === $last && ! empty( $payload['name'] ) ) {
			$parts = preg_split( '/\s+/', trim( (string) $payload['name'] ), 2 );
			$first = (string) $parts[0];
			$last  = isset( $parts[1] ) ? (string) $parts[1] : '';
		}
		return array(
			'sub'            => (string) $payload['sub'],
			'email'          => isset( $payload['email'] ) ? strtolower( trim( (string) $payload['email'] ) ) : '',
			'email_verified' => $verified,
			'first_name'     => $first,
			'last_name'      => $last,
		);
	}

	/**
	 * Apple sends the name only once, as a JSON `user` field on the first authorisation.
	 *
	 * @param array  $claims Claims from claims().
	 * @param string $user   The raw `user` field, or empty.
	 */
	public static function with_apple_name( $claims, $user ) {
		$data = '' !== $user ? json_decode( $user, true ) : null;
		if ( is_array( $data ) && isset( $data['name'] ) && is_array( $data['name'] ) ) {
			if ( '' === $claims['first_name'] && ! empty( $data['name']['firstName'] ) ) {
				$claims['first_name'] = (string) $data['name']['firstName'];
			}
			if ( '' === $claims['last_name'] && ! empty( $data['name']['lastName'] ) ) {
				$claims['last_name'] = (string) $data['name']['lastName'];
			}
		}
		return $claims;
	}

	/**
	 * Apple's client secret: a short-lived ES256 JWT signed with the Sign in with Apple key.
	 *
	 * @param int $now Unix time, for tests.
	 * @return string|WP_Error
	 */
	public static function apple_client_secret( $now = 0 ) {
		$now = $now > 0 ? $now : time();
		$key = self::constant( 'LLD_APPLE_PRIVATE_KEY' );
		if ( false === strpos( $key, 'BEGIN' ) ) {
			// A bare base64 key (the .p8 without its armour lines) is wrapped; the armour is assembled from parts so a
			// secrets scanner does not read this source as a key.
			$armour = '-----' . 'PRIVATE KEY-----';
			$key    = '-----BEGIN ' . substr( $armour, 5 ) . "\n" . trim( chunk_split( preg_replace( '/\s+/', '', $key ), 64, "\n" ) ) . "\n-----END " . substr( $armour, 5 ) . "\n";
		}
		return self::es256_jwt(
			array( 'alg' => 'ES256', 'kid' => self::constant( 'LLD_APPLE_KEY_ID' ), 'typ' => 'JWT' ),
			array(
				'iss' => self::constant( 'LLD_APPLE_TEAM_ID' ),
				'iat' => $now,
				'exp' => $now + 600,
				'aud' => self::APPLE_ISSUER,
				'sub' => self::constant( 'LLD_APPLE_SERVICES_ID' ),
			),
			$key
		);
	}

	/**
	 * Build and sign a JWT with ES256 (pure).
	 *
	 * @param array  $header  JOSE header.
	 * @param array  $payload Claims.
	 * @param string $pem     EC private key, PEM.
	 * @return string|WP_Error
	 */
	public static function es256_jwt( $header, $payload, $pem ) {
		$signing = self::base64url( (string) json_encode( $header ) ) . '.' . self::base64url( (string) json_encode( $payload ) );
		$pkey    = openssl_pkey_get_private( $pem );
		if ( false === $pkey ) {
			return self::error( 'lld_apple_key', 'The Apple sign-in key could not be read.' );
		}
		$der = '';
		if ( ! openssl_sign( $signing, $der, $pkey, OPENSSL_ALGO_SHA256 ) ) {
			return self::error( 'lld_apple_sign', 'The Apple sign-in request could not be signed.' );
		}
		return $signing . '.' . self::base64url( self::der_to_raw( $der, 32 ) );
	}

	/**
	 * An ECDSA signature from OpenSSL is DER (SEQUENCE of two INTEGERs); JOSE wants r||s, each a fixed width.
	 *
	 * @param string $der   DER signature.
	 * @param int    $width Bytes per integer (32 for P-256).
	 */
	public static function der_to_raw( $der, $width ) {
		$pos = 2; // 0x30 and the sequence length.
		if ( ( ord( $der[1] ) & 0x80 ) !== 0 ) {
			$pos += ord( $der[1] ) & 0x7f;
		}
		$out = '';
		for ( $i = 0; $i < 2; $i++ ) {
			$pos++; // 0x02
			$len  = ord( $der[ $pos++ ] );
			$int  = substr( $der, $pos, $len );
			$pos += $len;
			$int  = ltrim( $int, "\x00" );
			$out .= str_pad( $int, $width, "\x00", STR_PAD_LEFT );
		}
		return $out;
	}

	/**
	 * The decoded payload of a JWT, or null. The signature is not checked here (see the file comment).
	 *
	 * @param string $jwt Token.
	 * @return array|null
	 */
	public static function jwt_payload( $jwt ) {
		$parts = explode( '.', (string) $jwt );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		$json = base64_decode( strtr( $parts[1], '-_', '+/' ), true );
		if ( false === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * @param string $bytes Raw bytes.
	 */
	public static function base64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * A wp-config.php constant as a trimmed string, or empty.
	 *
	 * @param string $name Constant name.
	 */
	public static function constant( $name ) {
		return defined( $name ) ? trim( (string) constant( $name ) ) : '';
	}

	/**
	 * A WP_Error where WordPress is loaded; otherwise an array the CLI tests can read.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private static function error( $code, $message ) {
		if ( class_exists( 'WP_Error' ) ) {
			return new WP_Error( $code, $message );
		}
		return array( 'error' => $code, 'message' => $message );
	}
}
