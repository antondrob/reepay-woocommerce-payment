<?php
/**
 * @package Reepay\Checkout\Frontend
 */

namespace Reepay\Checkout\Frontend;

defined( 'ABSPATH' ) || exit();

/**
 * Class Main
 *
 * @package Reepay\Checkout\Frontend
 */
class Main {
	public function __construct() {
		new Assets();
	}
}
