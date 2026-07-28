<?php
/**
 * PSR-4-style autoloader for the WTG namespace.
 *
 * This is the one class in the plugin that cannot be autoloaded — it is the
 * mechanism that makes autoloading work. The bootstrap file requires it by
 * hand and then calls Autoloader::register().
 *
 * @package WTG
 */

namespace WTG;

// Block direct access: if WordPress has not booted, ABSPATH is undefined and we
// must not run.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps fully-qualified WTG\ class names to files under includes/.
 */
class Autoloader {

	/**
	 * The namespace prefix this autoloader is responsible for.
	 *
	 * Any class NOT starting with this prefix is ignored, so we play nicely
	 * with WordPress core and other plugins' autoloaders.
	 *
	 * The trailing backslash matters: it ensures we match the "WTG\" boundary
	 * and never a class like "WTGSomethingElse".
	 */
	const PREFIX = 'WTG\\';

	/**
	 * Register autoload() with the SPL autoload stack.
	 *
	 * spl_autoload_register lets multiple autoloaders coexist; PHP calls each
	 * one in turn until a class is found. Called once, from the bootstrap file.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Translate a class name into a file path and require it.
	 *
	 * PHP calls this automatically, passing the fully-qualified class name
	 * (e.g. "WTG\Google\OAuth_Client"), the first time that class is used.
	 *
	 * @param string $class Fully-qualified class name requested by PHP.
	 * @return void
	 */
	public static function autoload( $class ) {

		// Step 1: Is this class ours? If it does not begin with "WTG\", bail so
		// another registered autoloader can handle it. strncmp compares only the
		// first strlen(PREFIX) characters, which is faster than building a substring.
		if ( 0 !== strncmp( $class, self::PREFIX, strlen( self::PREFIX ) ) ) {
			return;
		}

		// Step 2: Drop the "WTG\" prefix, leaving the "relative" class path,
		// e.g. "Google\OAuth_Client" or just "Plugin".
		$relative = substr( $class, strlen( self::PREFIX ) );

		// Step 3: Split on the namespace separator. The LAST piece is the class
		// name; everything before it are sub-directories.
		// "Google\OAuth_Client" => [ 'Google', 'OAuth_Client' ]
		$parts = explode( '\\', $relative );

		// array_pop removes and returns the last element, so $parts is left
		// holding only the sub-directory segments (possibly empty).
		$class_name = array_pop( $parts );

		// Step 4: Convert the class name to WordPress's file-naming convention:
		//   OAuth_Client  ->  class-oauth-client.php
		// Lowercase everything, turn underscores into hyphens, wrap as class-*.php.
		$file_name = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';

		// Step 5: Rebuild any sub-directory path. Sub-folders keep their original
		// case (Google, WooCommerce, Queue, Admin), so we do NOT lowercase them.
		// implode with the OS-agnostic directory separator; add a trailing sep
		// only when there actually are sub-directories.
		$sub_path = empty( $parts ) ? '' : implode( DIRECTORY_SEPARATOR, $parts ) . DIRECTORY_SEPARATOR;

		// Step 6: Assemble the absolute path under includes/ and load it if present.
		// WTG_PLUGIN_DIR already ends in a slash (set in the bootstrap file).
		$path = WTG_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $sub_path . $file_name;

		// is_file guards against a missing file causing a fatal require error —
		// if the mapped file does not exist, we simply do nothing and let PHP
		// raise its normal "class not found" error, which is easier to debug.
		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
}
