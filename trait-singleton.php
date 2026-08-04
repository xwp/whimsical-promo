<?php
/**
 * Trait for a class to be used as a singleton instance.
 *
 * @since 1.0.0
 * @package WhimsicalPromo
 */

namespace WhimsicalPromo\Singleton;

/**
 * Trait Singleton
 *
 * @codeCoverageIgnore
 * @since 1.0.0
 * @package WhimsicalPromo
 */
trait Singleton {

	/**
	 * An array of instances keyed by class name to support inheritance.
	 *
	 * @since 1.0.0
	 * @var   array<string,static> Map of class name to instance.
	 */
	protected static $instances = [];

	/**
	 * Object constructor. Intentionally empty and public.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
	}

	/**
	 * Prevent the object from being cloned.
	 *
	 * @since 1.0.0
	 */
	final protected function __clone() {
	}

	/**
	 * Return the instance of the object.
	 *
	 * @since  1.0.0
	 * @return static
	 */
	final public static function get_instance(): static {
		$static_class = static::class;

		if ( ! isset( self::$instances[ $static_class ] ) ) {
			// @phpstan-ignore-next-line -- Singleton instantiation is safe here.
			self::$instances[ $static_class ] = new static();
			// @phpstan-ignore-next-line -- This trait is shared, some classes may not implement init().
			if ( method_exists( self::$instances[ $static_class ], 'init' ) ) {
				self::$instances[ $static_class ]->init();
			}
		}

		return self::$instances[ $static_class ];
	}

	/**
	 * Allows deletion of singletons for unit testing.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $target_class Optional class name to delete. If null, deletes the calling class's instance.
	 */
	public static function _delete( ?string $target_class = null ): void {
		if ( null === $target_class ) {
			$target_class = static::class;
		}
		unset( self::$instances[ $target_class ] );
	}
}
