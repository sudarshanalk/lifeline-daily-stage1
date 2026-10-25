<?php
/**
 * Encryption for free text (Data Model S3). The key is kept outside the database.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AES-256-GCM. A copied database cannot be read without the key file.
 */
class LLD_Members_Crypto {

	/**
	 * Encrypt a string. Returns a token that does not contain the original text.
	 *
	 * @param string $plain Text to protect.
	 */
	public static function encrypt( $plain, $aad = '' ) {
		$key = self::key();
		$iv  = random_bytes( 12 );
		$tag = '';
		$raw = openssl_encrypt( (string) $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, (string) $aad );
		if ( false === $raw || strlen( $tag ) !== 16 ) {
			throw new RuntimeException( 'Encryption failed.' );
		}
		return base64_encode( $iv . $tag . $raw );
	}

	/**
	 * Decrypt a token from encrypt().
	 *
	 * @param string $token Stored ciphertext.
	 */
	public static function decrypt( $token, $aad = '' ) {
		$bin = base64_decode( (string) $token, true );
		if ( false === $bin || strlen( $bin ) < 28 ) {
			throw new RuntimeException( 'Ciphertext is not valid.' );
		}
		$iv  = substr( $bin, 0, 12 );
		$tag = substr( $bin, 12, 16 );
		$raw = substr( $bin, 28 );
		$out = openssl_decrypt( $raw, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, (string) $aad );
		if ( false === $out ) {
			throw new RuntimeException( 'Decryption failed.' );
		}
		return $out;
	}

	/**
	 * 32-byte key. LLD_DATA_KEY in wp-config.php wins. Outside production, a key file is created if needed.
	 */
	public static function key() {
		if ( defined( 'LLD_DATA_KEY' ) && is_string( LLD_DATA_KEY ) && strlen( LLD_DATA_KEY ) >= 32 ) {
			return hash( 'sha256', LLD_DATA_KEY, true );
		}
		$path = lld_members_private_dir() . '/data.key';
		if ( is_readable( $path ) ) {
			$key = file_get_contents( $path );
			if ( is_string( $key ) && strlen( $key ) >= 32 ) {
				return substr( $key, 0, 32 );
			}
		}
		if ( lld_members_is_production() ) {
			throw new RuntimeException( 'LLD_DATA_KEY is not set. Refusing to invent an encryption key on production.' );
		}
		$dir = lld_members_private_dir();
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		$key = random_bytes( 32 );
		$handle = fopen( $path, 'x' );
		if ( false === $handle ) {
			$key = file_get_contents( $path );
			if ( ! is_string( $key ) || strlen( $key ) < 32 ) {
				throw new RuntimeException( 'The encryption key file could not be created.' );
			}
			return substr( $key, 0, 32 );
		}
		fwrite( $handle, $key );
		fclose( $handle );
		return $key;
	}
}
