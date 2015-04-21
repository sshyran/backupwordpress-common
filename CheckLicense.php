<?php namespace HM\BackUpWordPress;

use HM\BackUpWordPress\Notices;

/**
 * Class CheckLicense
 */
class CheckLicense {

	/**
	 * URL for the updater to ping for a new version.
	 */
	const EDD_STORE_URL = 'https://bwp.hmn.md';

	/**
	 * Required by EDD licensing plugin API.
	 */
	const EDD_PLUGIN_AUTHOR = 'Human Made Limited';

	protected $plugin_settings = '';

	protected $edd_download_file_name = '';

	protected $plugin;

	protected $prefix = '';

	protected $action_hook = '';

	protected $nonce_field = '';

	/**
	 * Instantiate a new object.
	 */
	public function __construct( $plugin_settings, $edd_download_file_name, Addon $plugin, PluginUpdater $updater, $prefix ) {

		add_action( 'backupwordpress_loaded', array( $this, 'init' ) );

		$this->plugin_settings = $plugin_settings;
		$this->edd_download_file_name = $edd_download_file_name;

		$this->plugin = $plugin;

		$this->updater = $updater;

		$this->prefix = $prefix;

		$this->action_hook = 'hmbkp_' . $this->prefix . '_license_key_submit';

		$this->nonce_field = 'hmbkp_' . $this->prefix . '_license_key_submit_nonce';
	}

	public function __get( $property ) {
		return $this->$property;
	}

	/**
	 * Checks the stored key on load and if it's not valid, present the license form.
	 */
	public function init() {

		$settings = $this->fetch_settings();

		if ( ( empty( $settings['license_key'] ) ) || false === $this->validate_key( $settings['license_key'] ) ) {

			add_action( 'admin_notices', array( $this, 'display_license_form' ) );

		}

		add_action( 'admin_post_' . $this->action_hook, array( $this, 'license_key_submit' ) );

	}

	/**
	 * Sets up the EDD licensing check.
	 */
	protected function plugin_updater() {

		// Retrieve our license key from the DB
		$settings = $this->fetch_settings();

		$license_key = $settings['license_key'];

		// Setup the updater
		$this->updater->init( self::EDD_STORE_URL, __FILE__, array(
				'version'   => $this->plugin->plugin_version, // current version number
				'license'   => $license_key, // license key (used get_option above to retrieve from DB)
				'item_name' => $this->edd_download_file_name, // name of this plugin
				'author'    => self::EDD_PLUGIN_AUTHOR, // author of this plugin
			)
		);

	}

	/**
	 * Check whether the provided license key is valid.
	 *
	 * @return bool
	 */
	protected function validate_key( $key ) {

		$license_data = $this->fetch_license_data( $key );

		$notices = array();

		if ( $this->is_license_invalid( $license_data['license_status'] ) ) {
			$notices[] = sprintf( __( 'Your %s license is invalid, please double check it now to continue to receive updates and support. Thanks!', 'backupwordpress' ), $this->edd_download_file_name );
		}  elseif ( $this->is_license_expired( $license_data['expiry_date'] ) ) {
			$notices[] = sprintf( __( 'Your %s license expired on %s, renew it now to continue to receive updates and support. Thanks!', 'backupwordpress' ), $this->edd_download_file_name, $license_data['expiry_date'] );
		}

		if ( ! empty( $notices ) ) {

			Notices::get_instance()->set_notices( 'license_check', $notices );

			return false;
		}

		return true;

	}

	/**
	 * Checks whether the license key has expired.
	 *
	 * @param $license_status
	 *
	 * @return bool True if 'expired'
	 */
	public function is_license_expired( $expiry_date ) {

		return ( strtotime( 'now' ) > strtotime( $expiry_date ) );
	}

	/**
	 * Checks whether the license key is valid.
	 *
	 * @param $license_status
	 *
	 * @return bool True if 'invalid'
	 */
	public function is_license_invalid( $license_status ) {

		return ( 'invalid' === $license_status );

	}

	/**
	 * Determines whether the key was activated for this domain.
	 *
	 * @param $license_status
	 *
	 * @return bool True if 'site_inactive'
	 */
	public function is_license_inactive( $license_status ) {

		return ( 'site_inactive' === $license_status || 'inactive' === $license_status );
	}

	public function is_license_valid( $license_status ) {
		return 'valid' === $license_status;
	}

	/**
	 * Fetches the plugin's license data either from the cache or from the EDD API.
	 *
	 * @return array|bool|mixed
	 */
	public function fetch_license_data( $key ) {

		$license_data = $this->fetch_settings();

		if ( 0 === strlen( trim( $license_data['license_key'] ) ) ) {

			$api_params = array(
				'edd_action' => 'check_license',
				'license'    => $key,
				'item_name'  => urlencode( $this->edd_download_file_name )
			);

			// Call the custom API.
			$response = wp_remote_get( $this->get_api_url( $api_params ), array( 'timeout' => 15, 'sslverify' => false ) );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$license_data = json_decode( wp_remote_retrieve_body( $response ) );

				$this->update_settings( array( 'license_key' => $key, 'license_status' => $license_data->license, 'license_expired' => $this->is_license_expired( $license_data->license ), 'expiry_date' => $license_data->expires ) );

		}

		return $this->fetch_settings();

	}

	/**
	 * Builds the API call URL.
	 *
	 * @param $args
	 *
	 * @return string
	 */
	public function get_api_url( $args ) {

		return add_query_arg( $args, self::EDD_STORE_URL );

	}

	/**
	 * Posts the activate action to the EDD API. Will then set the license_status to 'active'
	 *
	 * @return bool|void
	 */
	public function activate_license() {

		$settings = $this->fetch_settings();

		// Return early if we have a valid license
		if ( $this->is_license_valid( $settings['license_status'] ) ) {
			return;
		}

		// data to send in our API request
		$api_params = array(
			'edd_action' => 'activate_license',
			'license'    => $settings['license_key'],
			'item_name'  => urlencode( $this->edd_download_file_name ), // the name of our product in EDD
			'url'        => home_url()
		);

		// Call the custom API.
		$response = wp_remote_get( $this->get_api_url( $api_params ), array( 'timeout'   => 15, 'sslverify' => false ) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) ) {
			return false;
		}

		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		$settings['license_status'] = $license_data->license;

		if ( strtotime( 'now' ) < strtotime( $license_data->expires ) ) {
			$settings['license_expired'] = false;
		}

		return $this->update_settings( $settings );
	}

	/**
	 * Fetch the settings from the database.
	 *
	 * @return mixed|void
	 */
	public function fetch_settings() {
		return apply_filters( $this->plugin_settings, get_option( $this->plugin_settings, array( 'license_key' => '', 'license_status' => '', 'license_expired' => false, 'expiry_date' => '' ) ) );
	}

	/**
	 * Save the settings to the database.
	 *
	 * @param $data
	 *
	 * @return bool
	 */
	protected function update_settings( $data = array() ) {
		return update_option( $this->plugin_settings, $data );
	}

	protected function clear_settings() {
		return delete_option( $this->plugin_settings );
	}

	/**
	 * Display a form in the dashboard so the user can provide their license key.
	 *
	 */
	public function display_license_form() {

		$current_screen = get_current_screen();

		if ( is_null( $current_screen ) ) {
			return;
		}

		if ( ! defined( 'HMBKP_ADMIN_PAGE' ) ) {
			return;
		}

		if ( $current_screen->id !== HMBKP_ADMIN_PAGE ) {
			return;
		}

		?>

		<div class="updated">

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

				<p>
					<label style="vertical-align: baseline;" for="license_key"><?php printf( __( '%1$s%2$s is almost ready.%3$s Enter your license key to get updates and support.', 'backupwordpress' ), '<strong>', $this->edd_download_file_name, '</strong>' ); ?></label>
					<input id="license_key" class="code regular-text" name="license_key" type="text" value=""/>

				</p>

				<input type="hidden" name="action" value="<?php echo esc_attr( $this->action_hook ); ?>"/>

				<?php wp_nonce_field( $this->action_hook, $this->nonce_field ); ?>

				<?php submit_button( __( 'Save license key', 'backupwordpress' ) ); ?>

			</form>

		</div>

	<?php }

	/**
	 * Handles the license key form submission. Saves the license key.
	 */
	public function license_key_submit() {

		check_admin_referer( $this->action_hook, $this->nonce_field );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( wp_get_referer() );
			die;
		}

		if ( empty( $_POST['license_key'] ) ) {
			wp_safe_redirect( wp_get_referer() );
			die;
		}
		$key = sanitize_text_field( $_POST['license_key'] );

		// Clear any existing settings
		$this->clear_settings();

		Notices::get_instance()->clear_all_notices();

		if ( $this->validate_key( $key ) ) {
			$this->activate_license();
		} else {
			$this->clear_settings();
		}

		wp_safe_redirect( wp_get_referer() );
		die;
	}
}
