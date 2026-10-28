<?php
/**
 * Protected photo store (Data Model S2). Files sit outside the web root.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resizes photos, strips location data by re-encoding, and serves them only after an access check.
 */
class LLD_Members_Photos {

	const MAX_BYTES  = 10485760;
	const MAX_EDGE   = 1600;
	const MAX_SOURCE = 8000;
	const MAX_PIXELS = 20000000;

	/**
	 * Store a profile or diary photo. JPG, PNG or HEIC, up to 10 MB.
	 *
	 * @param int    $member_id Member number.
	 * @param string $kind      profile or diary.
	 * @param string $tmp_path  Uploaded file.
	 * @param string $filename  Original name, used only to detect the type.
	 * @return string|WP_Error Relative path stored on the member record.
	 */
	public static function store( $member_id, $kind, $tmp_path, $filename ) {
		if ( ! in_array( $kind, array( 'profile', 'diary' ), true ) ) {
			return new WP_Error( 'lld_photo', 'Unknown photo kind.' );
		}
		if ( ! is_readable( $tmp_path ) || filesize( $tmp_path ) > self::MAX_BYTES ) {
			return new WP_Error( 'lld_photo', 'Photos must be 10 MB or smaller.' );
		}
		$ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'heic' ), true ) ) {
			return new WP_Error( 'lld_photo', 'Use a JPG, PNG or HEIC photo.' );
		}
		// The file's own first bytes decide the decoder, never the name it was sent with.
		$type = self::sniff( $tmp_path );
		if ( '' === $type ) {
			return new WP_Error( 'lld_photo', 'Use a JPG, PNG or HEIC photo.' );
		}
		$guard = self::guard_size( $tmp_path, $type );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$image = self::load( $tmp_path, $type );
		if ( is_wp_error( $image ) ) {
			return $image;
		}
		$image = self::orient( $image, self::orientation( $tmp_path, $type ) );
		$image = self::fit( $image );
		$dir   = self::member_dir( $member_id );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) {
			imagedestroy( $image );
			return new WP_Error( 'lld_photo', 'The photo store is not available.' );
		}
		$name = 'profile' === $kind ? 'profile.jpg' : 'diary-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false ) . '.jpg';
		$dest = $dir . '/' . $name;
		$ok   = imagejpeg( $image, $dest, 85 );
		imagedestroy( $image );
		if ( ! $ok ) {
			return new WP_Error( 'lld_photo', 'The photo could not be saved.' );
		}
		return $member_id . '/' . $name;
	}

	/**
	 * Send a stored photo to the signed-in member who owns it.
	 *
	 * @param int    $member_id Member number.
	 * @param string $relative  Path from store().
	 */
	public static function send( $member_id, $relative ) {
		$path = self::resolve( $relative );
		if ( ! $path ) {
			status_header( 404 );
			exit;
		}
		header( 'Content-Type: image/jpeg' );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path );
		exit;
	}

	/**
	 * Delete one member's photos. Health-consent withdrawal removes diary photos only.
	 *
	 * @param int    $member_id Member number.
	 * @param string $kind      all, profile or diary.
	 */
	public static function delete_member( $member_id, $kind = 'all' ) {
		$dir = self::member_dir( $member_id );
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}
			$diary = 0 === strpos( $file, 'diary-' );
			if ( 'diary' === $kind && ! $diary ) {
				continue;
			}
			if ( 'profile' === $kind && $diary ) {
				continue;
			}
			unlink( $dir . '/' . $file );
		}
		$left = array_diff( scandir( $dir ), array( '.', '..' ) );
		if ( ! $left ) {
			rmdir( $dir );
		}
	}

	/**
	 * Every stored photo of one member, profile and diary, for the data download.
	 *
	 * @param int $member_id Member number.
	 * @return string[] Absolute paths.
	 */
	public static function member_files( $member_id ) {
		$dir = self::member_dir( $member_id );
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files = array();
		foreach ( (array) scandir( $dir ) as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}
			$path = self::resolve( (int) $member_id . '/' . $file );
			if ( $path ) {
				$files[] = $path;
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * @param string $relative Stored relative path.
	 * @return string|false Absolute path inside the photo store.
	 */
	public static function resolve( $relative ) {
		$base = realpath( self::root() );
		if ( ! $base ) {
			return false;
		}
		$path = realpath( $base . '/' . ltrim( (string) $relative, '/\\' ) );
		if ( ! $path || 0 !== strpos( $path, $base . DIRECTORY_SEPARATOR ) ) {
			return false;
		}
		return is_file( $path ) ? $path : false;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function member_dir( $member_id ) {
		return self::root() . '/' . (int) $member_id;
	}

	private static function root() {
		$dir = lld_members_private_dir() . '/photos';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		return $dir;
	}

	/**
	 * The photo's real format from its first bytes: jpeg, png, heic, or empty for anything else.
	 *
	 * @param string $path File path.
	 */
	private static function sniff( $path ) {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return '';
		}
		$head = (string) fread( $handle, 64 );
		fclose( $handle );
		if ( 0 === strncmp( $head, "\xFF\xD8\xFF", 3 ) ) {
			return 'jpeg';
		}
		if ( 0 === strncmp( $head, "\x89PNG\r\n\x1A\n", 8 ) ) {
			return 'png';
		}
		if ( strlen( $head ) >= 16 && 'ftyp' === substr( $head, 4, 4 ) ) {
			$size   = min( strlen( $head ), max( 16, (int) unpack( 'N', substr( $head, 0, 4 ) )[1] ) );
			$brands = array( substr( $head, 8, 4 ) );
			for ( $at = 16; $at + 4 <= $size; $at += 4 ) {
				$brands[] = substr( $head, $at, 4 );
			}
			if ( array_intersect( $brands, array( 'heic', 'heix', 'heim', 'heis', 'hevc', 'hevx' ) ) ) {
				return 'heic';
			}
		}
		return '';
	}

	/**
	 * Refuse a decompression bomb before the bitmap is decoded. A photo whose size cannot be read
	 * is refused too, HEIC included, so nothing reaches the decoder without passing the limits.
	 *
	 * @param string $path File path.
	 * @param string $type jpeg, png or heic, from sniff().
	 * @return true|WP_Error
	 */
	private static function guard_size( $path, $type ) {
		$w = 0;
		$h = 0;
		if ( 'heic' === $type ) {
			if ( ! class_exists( 'Imagick' ) ) {
				return new WP_Error( 'lld_photo', 'HEIC photos need Imagick on this server.' );
			}
			try {
				$im = new Imagick();
				$im->pingImage( 'heic:' . $path );
				$w = $im->getImageWidth();
				$h = $im->getImageHeight();
				$im->clear();
			} catch ( Exception $e ) {
				return new WP_Error( 'lld_photo', 'That HEIC photo could not be read.' );
			}
		} else {
			$info = @getimagesize( $path );
			if ( is_array( $info ) ) {
				$w = (int) $info[0];
				$h = (int) $info[1];
			}
		}
		return self::within_limits( $w, $h, $type );
	}

	/**
	 * @param int    $w    Width in pixels.
	 * @param int    $h    Height in pixels.
	 * @param string $type Photo type.
	 * @return true|WP_Error
	 */
	private static function within_limits( $w, $h, $type ) {
		if ( $w < 1 || $h < 1 ) {
			return new WP_Error( 'lld_photo', 'heic' === $type ? 'That HEIC photo could not be read.' : 'That photo could not be read. Use a JPG, PNG or HEIC file.' );
		}
		if ( $w > self::MAX_SOURCE || $h > self::MAX_SOURCE || ( $w * $h ) > self::MAX_PIXELS ) {
			return new WP_Error( 'lld_photo', 'That photo is too large to open.' );
		}
		return true;
	}

	/**
	 * @param string $path File path.
	 * @param string $type Photo type.
	 */
	private static function orientation( $path, $type ) {
		if ( 'jpeg' !== $type || ! function_exists( 'exif_read_data' ) ) {
			return 1;
		}
		$exif = @exif_read_data( $path );
		return ( is_array( $exif ) && isset( $exif['Orientation'] ) ) ? (int) $exif['Orientation'] : 1;
	}

	/**
	 * Apply the EXIF orientation (1 to 8), then the caller re-encodes without location data.
	 * GD turns positive angles anticlockwise.
	 *
	 * @param GdImage|resource $image       Image.
	 * @param int              $orientation EXIF orientation, 1 to 8.
	 * @return GdImage|resource
	 */
	private static function orient( $image, $orientation ) {
		$steps = array(
			2 => array( 0, IMG_FLIP_HORIZONTAL ),
			3 => array( 180, 0 ),
			4 => array( 0, IMG_FLIP_VERTICAL ),
			5 => array( -90, IMG_FLIP_HORIZONTAL ),
			6 => array( -90, 0 ),
			7 => array( 90, IMG_FLIP_HORIZONTAL ),
			8 => array( 90, 0 ),
		);
		$orientation = (int) $orientation;
		if ( ! isset( $steps[ $orientation ] ) ) {
			return $image;
		}
		list( $angle, $flip ) = $steps[ $orientation ];
		if ( 0 !== $angle ) {
			$turned = imagerotate( $image, $angle, 0 );
			if ( $turned ) {
				imagedestroy( $image );
				$image = $turned;
			}
		}
		if ( 0 !== $flip ) {
			imageflip( $image, $flip );
		}
		return $image;
	}

	/**
	 * @param string $path File path.
	 * @param string $type jpeg, png or heic, from sniff().
	 * @return resource|GdImage|WP_Error
	 */
	private static function load( $path, $type ) {
		if ( 'heic' === $type ) {
			if ( ! class_exists( 'Imagick' ) ) {
				return new WP_Error( 'lld_photo', 'HEIC photos need Imagick on this server.' );
			}
			try {
				$im = new Imagick();
				$im->readImage( 'heic:' . $path );
				$limits = self::within_limits( $im->getImageWidth(), $im->getImageHeight(), 'heic' );
				if ( is_wp_error( $limits ) ) {
					$im->clear();
					return $limits;
				}
				if ( method_exists( $im, 'autoOrient' ) ) {
					$im->autoOrient();
				}
				$im->stripImage();
				$im->setImageFormat( 'jpeg' );
				$blob = $im->getImageBlob();
				$im->clear();
				$image = imagecreatefromstring( $blob );
			} catch ( Exception $e ) {
				return new WP_Error( 'lld_photo', 'That HEIC photo could not be read.' );
			}
			return $image ? $image : new WP_Error( 'lld_photo', 'That HEIC photo could not be read.' );
		}
		$image = 'png' === $type ? @imagecreatefrompng( $path ) : @imagecreatefromjpeg( $path );
		if ( ! $image ) {
			return new WP_Error( 'lld_photo', 'That photo could not be read. Use a JPG, PNG or HEIC file.' );
		}
		return $image;
	}

	/**
	 * Scale so the longest edge is at most 1,600 pixels. Re-encoding drops location data.
	 *
	 * @param GdImage|resource $image Image.
	 * @return GdImage|resource
	 */
	private static function fit( $image ) {
		$w = imagesx( $image );
		$h = imagesy( $image );
		if ( $w <= self::MAX_EDGE && $h <= self::MAX_EDGE ) {
			return $image;
		}
		$scale = self::MAX_EDGE / max( $w, $h );
		$nw    = max( 1, (int) round( $w * $scale ) );
		$nh    = max( 1, (int) round( $h * $scale ) );
		$out   = imagecreatetruecolor( $nw, $nh );
		imagecopyresampled( $out, $image, 0, 0, 0, 0, $nw, $nh, $w, $h );
		imagedestroy( $image );
		return $out;
	}
}
