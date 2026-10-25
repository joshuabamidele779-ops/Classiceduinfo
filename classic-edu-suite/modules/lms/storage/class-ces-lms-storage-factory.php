<?php
/**
 * Resolves the active storage driver from settings.
 *
 * `video_storage_driver` picks the driver for *new* uploads. Existing lessons
 * remember which driver owns their bytes in the `_ces_video_driver` meta, so
 * flipping the setting does not orphan or break anything already uploaded:
 * old lessons keep playing from where they are, new ones go to the new place.
 * That is what makes the migration path real rather than theoretical.
 *
 * @package ClassicEduSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storage driver factory.
 */
class CES_Lms_Storage_Factory {

	/**
	 * Instantiated drivers, keyed by driver key.
	 *
	 * @var CES_Lms_Storage[]
	 */
	private static $cache = array();

	/**
	 * Every driver key mapped to its class.
	 *
	 * @return array
	 */
	public static function map() {
		/**
		 * Filter the available storage drivers.
		 *
		 * Lets a future driver be added without touching this file.
		 *
		 * @param array $map Driver key => class name.
		 */
		return (array) apply_filters(
			'ces_lms_storage_drivers',
			array(
				'local'      => 'CES_Lms_Storage_Local',
				'bunny'      => 'CES_Lms_Storage_Bunny',
				'cloudflare' => 'CES_Lms_Storage_Cloudflare',
			)
		);
	}

	/**
	 * Driver keys and labels, for the settings dropdown.
	 *
	 * @return array
	 */
	public static function choices() {
		$choices = array();

		foreach ( array_keys( self::map() ) as $key ) {
			$driver = self::get( $key );

			if ( $driver ) {
				$choices[ $key ] = $driver->get_label();
			}
		}

		return $choices;
	}

	/**
	 * The driver key configured for new uploads.
	 *
	 * @return string
	 */
	public static function active_key() {
		$key = sanitize_key( (string) CES_Helpers::setting( 'video_storage_driver', 'local' ) );
		$map = self::map();

		return isset( $map[ $key ] ) ? $key : 'local';
	}

	/**
	 * The driver configured for new uploads.
	 *
	 * @return CES_Lms_Storage|null
	 */
	public static function active() {
		return self::get( self::active_key() );
	}

	/**
	 * Get a driver by key.
	 *
	 * @param string $key Driver key.
	 * @return CES_Lms_Storage|null
	 */
	public static function get( $key ) {
		$key = sanitize_key( (string) $key );
		$map = self::map();

		if ( ! isset( $map[ $key ] ) ) {
			return null;
		}

		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$class = $map[ $key ];

		if ( ! class_exists( $class ) ) {
			return null;
		}

		$driver = new $class();

		if ( ! $driver instanceof CES_Lms_Storage ) {
			return null;
		}

		self::$cache[ $key ] = $driver;

		return $driver;
	}

	/**
	 * The driver that owns a particular lesson's media.
	 *
	 * Falls back to the active driver for lessons that have no media yet.
	 *
	 * @param int $lesson_id Lesson post id.
	 * @return CES_Lms_Storage|null
	 */
	public static function for_lesson( $lesson_id ) {
		$key = sanitize_key( (string) get_post_meta( (int) $lesson_id, CES_Lms_Meta::DRIVER, true ) );

		if ( '' !== $key ) {
			$driver = self::get( $key );

			if ( $driver ) {
				return $driver;
			}
		}

		return self::active();
	}

	/**
	 * Reset the instance cache. Used by tests and by settings saves.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = array();
	}
}
