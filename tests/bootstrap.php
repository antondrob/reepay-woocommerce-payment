<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package ./reepay_Woocommerce_Payment
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );
function _manually_load_plugin() {
	$wordpres_plugins_path = ABSPATH . 'wp-content/plugins/';

	require $wordpres_plugins_path . 'woocommerce/woocommerce.php';

	require dirname( dirname( __FILE__ ) ) . '/./reepay-woocommerce-payment.php';
}

// install WC.
tests_add_filter( 'setup_theme', 'install_wc' );
function install_wc() {
	// Clean existing install first.
	define( 'WP_UNINSTALL_PLUGIN', true );
	define( 'WC_REMOVE_ALL_DATA', true );
	include (ABSPATH . 'wp-content/plugins/woocommerce/uninstall.php');
	echo esc_html( 'Installing WooCommerce...' . PHP_EOL );
	WC_Install::install();
	// Reload capabilities after install, see https://core.trac.wordpress.org/ticket/28374
	$GLOBALS['wp_roles'] = null;
	wp_roles();
}
// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";
