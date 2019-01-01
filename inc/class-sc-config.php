<?php
/**
 * Handle plugin config
 *
 * @package  simple-cache
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class wrapping config functionality
 */
class SC_Config {

	/**
	 * Setup object
	 *
	 * @since 1.0.1
	 * @var   array
	 */
	public $defaults = array();


	/**
	 * Set config defaults
	 *
	 * @since 1.0
	 */
	public function __construct() {

		$this->defaults = array(
			'enable_page_caching'             => array(
				'default'   => false,
				'sanitizer' => array( $this, 'boolval' ),
			),
			'advanced_mode'                   => array(
				'default'   => false,
				'sanitizer' => array( $this, 'boolval' ),
			),
			'enable_in_memory_object_caching' => array(
				'default'   => false,
				'sanitizer' => array( $this, 'boolval' ),
			),
			'enable_gzip_compression'         => array(
				'default'   => false,
				'sanitizer' => array( $this, 'boolval' ),
			),
			'in_memory_cache'                 => array(
				'default'   => 'memcached',
				'sanitizer' => 'sanitize_text_field',
			),
			'page_cache_length'               => array(
				'default'   => 24,
				'sanitizer' => 'floatval',
			),
			'page_cache_length_unit'          => array(
				'default'   => 'hours',
				'sanitizer' => array( $this, 'sanitize_length_unit' ),
			),
			'cache_exception_urls'            => array(
				'default'   => '',
				'sanitizer' => 'wp_kses_post',
			),
			'enable_url_exemption_regex'      => array(
				'default'   => false,
				'sanitizer' => array( $this, 'boolval' ),
			),
		);
	}

	/**
	 * Make sure we support old PHP with boolval
	 *
	 * @param  string $value Value to check.
	 * @since  1.0
	 * @return boolean
	 */
	public function boolval( $value ) {
		return (bool) $value;
	}

	/**
	 * Make sure the length unit has an expected value
	 *
	 * @param  string $value Value to sanitize.
	 * @return string
	 */
	public function sanitize_length_unit( $value ) {
		$accepted_values = array( 'minutes', 'hours', 'days', 'weeks' );

		if ( in_array( $value, $accepted_values ) ) {
			return $value;
		}

		return 'minutes';
	}

	/**
	 * Return defaults
	 *
	 * @since  1.0
	 * @return array
	 */
	public function get_defaults() {

		$defaults = array();

		foreach ( $this->defaults as $key => $default ) {
			$defaults[ $key ] = $default['default'];
		}

		return $defaults;
	}

	/**
	 * Get config file name
	 *
	 * @since  1.7
	 * @return string
	 */
	private function get_config_file_name() {
		$home_url_parts = parse_url( home_url() );

		return 'config-' . $home_url_parts['host'] . '.php';
	}

	/**
	 * Write config to file
	 *
	 * @since  1.0
	 * @param  array $config Configuration array.
	 * @return bool
	 */
	public function write( $config ) {

		global $wp_filesystem;

		$config_dir = sc_get_config_dir();

		$this->config = wp_parse_args( $config, $this->get_defaults() );

		$wp_filesystem->mkdir( $config_dir );

		$config_file_string = '<?php ' . "\n\r" . "defined( 'ABSPATH' ) || exit;" . "\n\r" . 'return ' . var_export( $this->config, true ) . '; ' . "\n\r";

		if ( ! $wp_filesystem->put_contents( $config_dir . '/' . $this->get_config_file_name(), $config_file_string, FS_CHMOD_FILE ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get config from file or cache
	 *
	 * @since  1.0
	 * @return array
	 */
	public function get() {

		$config = get_option( 'sc_simple_cache', $this->get_defaults() );

		return wp_parse_args( $config, $this->get_defaults() );
	}

	/**
	 * Check if a directory is writable and we can create files as the same user as the current file
	 *
	 * @param  string $dir Directory path.
	 * @since  1.2.3
	 * @return boolean
	 */
	private function _is_dir_writable( $dir ) {
		$temp_file_name = untrailingslashit( $dir ) . '/temp-write-test-' . time();
		$temp_handle    = fopen( $temp_file_name, 'w' );

		if ( $temp_handle ) {

			// Attempt to determine the file owner of the WordPress files, and that of newly created files.
			$wp_file_owner   = false;
			$temp_file_owner = false;

			if ( function_exists( 'fileowner' ) ) {
				$wp_file_owner = @fileowner( __FILE__ );
				// Pass in the temporary handle to determine the file owner.
				$temp_file_owner = @fileowner( $temp_file_name );

				// Close and remove the temporary file.
				@fclose( $temp_handle );
				@unlink( $temp_file_name );

				// Return if we cannot determine the file owner, or if the owner IDs do not match.
				if ( false === $wp_file_owner || $wp_file_owner !== $temp_file_owner ) {
					return false;
				}
			} else {
				if ( ! @is_writable( $dir ) ) {
					return false;
				}
			}
		} else {
			return false;
		}

		return true;
	}

	/**
	 * Verify we can write to the file system
	 *
	 * @since  1.0
	 * @return boolean
	 */
	public function verify_file_access() {
		if ( function_exists( 'clearstatcache' ) ) {
			@clearstatcache();
		}

		// First check wp-config.php.
		if ( ! @is_writable( ABSPATH . 'wp-config.php' ) && ! @is_writable( ABSPATH . '../wp-config.php' ) ) {
			return false;
		}

		// Now check wp-content. We need to be able to create files of the same user as this file.
		if ( ! $this->_is_dir_writable( untrailingslashit( WP_CONTENT_DIR ) ) ) {
			return false;
		}

		// Make sure cache parent directory is writeable as well as cache directory
		if ( @file_exists( sc_get_cache_dir() . '/../' ) ) {
			if ( ! $this->_is_dir_writable( sc_get_cache_dir() . '/../' ) ) {
				return false;
			}
		}

		if ( @file_exists( sc_get_cache_dir() ) ) {
			if ( ! $this->_is_dir_writable( sc_get_cache_dir() ) ) {
				return false;
			}
		}

		// Check config parent directory is writeable
		if ( @file_exists( sc_get_config_dir() . '/../' ) ) {
			if ( ! $this->_is_dir_writable( sc_get_config_dir() . '/../' ) ) {
				return false;
			}
		}

		// Check the config directory is writeable
		if ( @file_exists( sc_get_config_dir() ) ) {
			if ( ! $this->_is_dir_writable( sc_get_config_dir() ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete files and option for clean up
	 *
	 * @since  1.2.2
	 * @return bool
	 */
	public function clean_up() {

		global $wp_filesystem;

		$config_dir = sc_get_config_dir();

		$path = $config_dir . '/' . $this->get_config_file_name();

		delete_option( 'sc_simple_cache' );

		if ( ! $wp_filesystem->delete( $path, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Return an instance of the current class, create one if it doesn't exist
	 *
	 * @since  1.0
	 * @return SC_Config
	 */
	public static function factory() {

		static $instance;

		if ( ! $instance ) {
			$instance = new self();
		}

		return $instance;
	}
}
