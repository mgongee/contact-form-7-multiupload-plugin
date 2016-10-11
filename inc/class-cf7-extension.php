<?php


/**
 * Basic class that contains common functions,
 * such as:
 * - meta & options management,
 * - etc
 */
class Cf7_Extension {

	/**
	 * this plugin needs to be initialized AFTER the Contact Form 7 plugin.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'initialize'), 10);
	}

	public function initialize() {
		global $pagenow;
		if ( !function_exists( 'wpcf7_add_shortcode' ) ) {
			if ( $pagenow != 'plugins.php' ) { return; }
			add_action( 'admin_notices', array( $this, 'error_plugin_missing' ) );                 // show error
			add_action( 'admin_enqueue_scripts', array( $this, 'error_plugin_missing_scripts' ) ); // load thickbox
		}
	}

	/**
	 * Show error message
	 */
	public function error_plugin_missing() {
		$out = '<div class="error" id="messages"><p>';
		if ( file_exists( WP_PLUGIN_DIR . '/contact-form-7/wp-contact-form-7.php' ) ) {
			$out .= 'The Contact Form 7 plugin is installed, but <strong>you must activate Contact Form 7</strong> below for the Multiupload Field plugin to work.';
		} else {
			$out .= 'The Contact Form 7 plugin must be installed for the Multiupload plugin to work. <a href="'.admin_url('plugin-install.php?tab=plugin-information&plugin=contact-form-7&from=plugins&TB_iframe=true&width=600&height=550').'" class="thickbox" title="Contact Form 7">Install Now.</a>';
		}
		$out .= '</p></div>';

		echo $out;
	}

	/**
	 * Load JS thickbox scripts for error message.
	 * Will only be loaded when Contact Form 7 plugin is not installed and only in Admin
	 */
	public function error_plugin_missing_scripts() {
		wp_enqueue_script('thickbox');
	}

	public static function log($data) {
		
		$filename = pathinfo(__FILE__, PATHINFO_DIRNAME) . DIRECTORY_SEPARATOR .'log.txt';
		if (isset($_REQUEST['cf7ext_log_to_screen']) && $_REQUEST['cf7ext_log_to_screen'] == 1) {
			echo('log::<pre>' . print_r($data, 1) . '</pre>');
		}
		else {
			// file_put_contents($filename, date("Y-m-d H:i:s") . " | " . print_r($data,1) . "\r\n\r\n", FILE_APPEND);
		}
	}
}