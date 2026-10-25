<?php
/**
 * Filesystem layout for protected media.
 *
 * All media lives under `wp-content/uploads/ces-private/`, which
 * `CES_Activator::create_protected_storage()` creates and denies to direct
 * HTTP requests. Nothing in this tree is ever web-reachable; playback goes
 * exclusively through the signed stream endpoint.
 *
 * Every path that originates from a request is resolved and then confirmed to
 * sit inside the expected directory, so a crafted `..` sequence cannot reach
 * wp-config.php or anything else on disk.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Private storage path resolver.
 */
class CES_Lms_Paths {

	/**
	 * Root of the private media tree, trailing-slashed. Empty on failure.
	 *
	 * @return string
	 */
	public static function base() {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] ) . 'ces-private/';
	}

	/**
	 * Directory holding uploaded master files.
	 *
	 * @return string
	 */
	public static function source_dir() {
		$base = self::base();

		return $base ? $base . 'source/' : '';
	}

	/**
	 * Root of the HLS output tree.
	 *
	 * @return string
	 */
	public static function hls_dir() {
		$base = self::base();

		return $base ? $base . 'hls/' : '';
	}

	/**
	 * Scratch directory for chunked uploads in progress.
	 *
	 * @return string
	 */
	public static function tmp_dir() {
		$base = self::base();

		return $base ? $base . 'tmp/' : '';
	}

	/**
	 * HLS output directory for one lesson.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return string
	 */
	public static function lesson_hls_dir( $lesson_id ) {
		$dir = self::hls_dir();

		return $dir ? $dir . (int) $lesson_id . '/' : '';
	}

	/**
	 * Absolute path to a lesson's uploaded source file, or '' when missing.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return string
	 */
	public static function source_path( $lesson_id ) {
		$name = (string) get_post_meta( (int) $lesson_id, CES_Lms_Meta::SOURCE, true );

		if ( '' === $name ) {
			return '';
		}

		$dir = self::source_dir();

		if ( ! $dir ) {
			return '';
		}

		$path = $dir . basename( $name );

		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Create the private tree if anything is missing.
	 *
	 * Called before every write so a host that wipes uploads, or an install
	 * that was never formally activated, still works.
	 *
	 * @return bool True when the tree exists and is writable.
	 */
	public static function ensure() {
		$base = self::base();

		if ( ! $base ) {
			return false;
		}

		if ( ! file_exists( $base ) && class_exists( 'CES_Activator' ) ) {
			CES_Activator::create_protected_storage();
		}

		foreach ( array( $base, self::source_dir(), self::hls_dir(), self::tmp_dir() ) as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}

		return is_dir( $base ) && wp_is_writable( $base );
	}

	/**
	 * Validate an untrusted media filename.
	 *
	 * Deliberately strict: a single path segment of safe characters with one
	 * extension drawn from a fixed allow-list. No directory separators, no
	 * dot-segments, no leading dot.
	 *
	 * @param string $name Candidate filename from a request.
	 * @return string The accepted filename, or '' when rejected.
	 */
	public static function sanitize_media_name( $name ) {
		$name = (string) $name;

		if ( '' === $name || strlen( $name ) > 180 ) {
			return '';
		}

		if ( false !== strpos( $name, '/' ) || false !== strpos( $name, '\\' ) || false !== strpos( $name, "\0" ) ) {
			return '';
		}

		if ( false !== strpos( $name, '..' ) || 0 === strpos( $name, '.' ) ) {
			return '';
		}

		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(m3u8|ts|m4s|mp4|m4v|key|vtt|webm|mov|mkv)$/', $name ) ) {
			return '';
		}

		return $name;
	}

	/**
	 * Resolve a media filename inside a directory, refusing escapes.
	 *
	 * @param string $dir  Directory the file must live in.
	 * @param string $name Already-sanitised filename.
	 * @return string Absolute real path, or '' when it is missing or outside $dir.
	 */
	public static function resolve_within( $dir, $name ) {
		if ( '' === $dir || '' === $name ) {
			return '';
		}

		$real_dir = realpath( $dir );

		if ( false === $real_dir ) {
			return '';
		}

		$candidate = realpath( trailingslashit( $real_dir ) . $name );

		if ( false === $candidate || ! is_file( $candidate ) ) {
			return '';
		}

		$real_dir = trailingslashit( str_replace( '\\', '/', $real_dir ) );
		$normal   = str_replace( '\\', '/', $candidate );

		if ( 0 !== strpos( $normal, $real_dir ) ) {
			return '';
		}

		return $candidate;
	}

	/**
	 * Recursively delete a directory inside the private tree.
	 *
	 * Refuses to touch anything that is not under `ces-private/`, so a bad
	 * caller cannot turn this into an arbitrary-delete primitive.
	 *
	 * @param string $dir Absolute directory path.
	 * @return bool
	 */
	public static function delete_dir( $dir ) {
		$base = self::base();

		if ( ! $base || ! is_dir( $dir ) ) {
			return false;
		}

		$real_base = realpath( $base );
		$real_dir  = realpath( $dir );

		if ( false === $real_base || false === $real_dir ) {
			return false;
		}

		$real_base = trailingslashit( str_replace( '\\', '/', $real_base ) );
		$normal    = trailingslashit( str_replace( '\\', '/', $real_dir ) );

		if ( $normal === $real_base || 0 !== strpos( $normal, $real_base ) ) {
			return false;
		}

		$items = scandir( $real_dir );

		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $real_dir . DIRECTORY_SEPARATOR . $item;

			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::delete_dir( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return @rmdir( $real_dir );
	}

	/**
	 * Free disk space on the uploads volume, in bytes. 0 when unknown.
	 *
	 * @return float
	 */
	public static function free_space() {
		$base = self::base();

		if ( ! $base || ! function_exists( 'disk_free_space' ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$free = @disk_free_space( $base );

		return is_numeric( $free ) ? (float) $free : 0;
	}

	/**
	 * Total bytes currently used by the private media tree.
	 *
	 * Walks the tree, so the admin diagnostics screen caches the result.
	 *
	 * @return float
	 */
	public static function used_space() {
		$base = self::base();

		if ( ! $base || ! is_dir( $base ) ) {
			return 0;
		}

		$total = 0.0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$total += (float) $file->getSize();
				}
			}
		} catch ( Exception $e ) {
			CES_Helpers::log( 'LMS: used_space walk failed', $e->getMessage() );
		}

		return $total;
	}

	/**
	 * Whether the private tree is genuinely unreachable over HTTP.
	 *
	 * Checks the guard files exist. A real fetch test lives on the
	 * diagnostics screen, which can make a loopback request.
	 *
	 * @return array {
	 *     @type bool $htaccess Whether the deny rules file is present.
	 *     @type bool $index    Whether the index.php stub is present.
	 * }
	 */
	public static function protection_status() {
		$base = self::base();

		return array(
			'htaccess' => (bool) ( $base && file_exists( $base . '.htaccess' ) ),
			'index'    => (bool) ( $base && file_exists( $base . 'index.php' ) ),
		);
	}

	/**
	 * Public URL of the private directory, used only to probe that it is denied.
	 *
	 * @param string $relative Optional file inside the tree.
	 * @return string
	 */
	public static function probe_url( $relative = 'index.php' ) {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}

		return trailingslashit( $uploads['baseurl'] ) . 'ces-private/' . ltrim( $relative, '/' );
	}
}
