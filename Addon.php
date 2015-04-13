<?php namespace HM\BackUpWordPress;

/**
 * Class Addon
 */
class Addon {

	/**
	 * The plugin version number.
	 */
	protected $plugin_version = '';

	/**
	 * Minimum version of BackUpWordPress compatibility.
	 */
	protected $min_bwp_version = '';

	protected $requirements;

	protected $notice;

	protected $service_class;
	protected $bwp;

	/**
	 * Instantiates a new Plugin object
	 *
	 * @param $plugin_version
	 * @param $min_bwp_version
	 * @param $license_status
	 */
	public function __construct( $plugin_version, $min_bwp_version, $service_class, $bwp ) {

		add_action( 'admin_init', array( $this, 'maybe_self_deactivate' ) );

		$this->plugin_version = $plugin_version;
		$this->min_bwp_version = $min_bwp_version;
		$this->service_class = $service_class;
		$this->bwp = $bwp;
	}

	public function register( $path ) {
		require_once $path . $this->service_class . '.php';
		Services::register( __FILE__, __NAMESPACE__ . '\\' . $this->service_class );

	}

	public function __get( $property ) {
		return $this->$property;
	}

	/**
	 * Self deactivate ourself if incompatibility found.
	 */
	public function maybe_self_deactivate() {

		if ( $this->meets_requirements() ) {
			return;
		}

		deactivate_plugins( plugin_basename( __FILE__ ) );
		add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );

		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}

	}

	/**
	 * Displays a user friendly message in the WordPress admin.
	 */
	public function display_admin_notices() {

		echo '<div class="error"><p>' . esc_html( self::get_notice_message() ) . '</p></div>';

	}

	/**
	 * Returns a localized user friendly error message.
	 *
	 * @return string
	 */
	public function get_notice_message() {

		return sprintf(
			$this->notice,
			$this->edd_download_file_name,
			$this->min_bwp_version
		);
	}

	/**
	 * Check if current WordPress install meets necessary requirements.
	 *
	 * @return bool True is passes checks, false otherwise.
	 */
	public function meets_requirements() {

		if ( ! class_exists( 'HM\BackUpWordPress\Plugin' ) ) {
			$this->notice = __( '%1$s requires BackUpWordPress version %2$s. Please install or update it first.', 'backupwordpress' );
			return false;
		}

		$bwp = Plugin::get_instance();

		if ( version_compare( Plugin::PLUGIN_VERSION, $this->min_bwp_version, '<' ) ) {
			$this->notice = __( '%1$s requires BackUpWordPress version %2$s. Please install or update it first.', 'backupwordpress' );
			return false;
		}

		return true;
	}


}
