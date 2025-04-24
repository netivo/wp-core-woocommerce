<?php
/**
 * Created by Netivo for wp-core
 * User: manveru
 * Date: 24.04.2025
 * Time: 13:51
 *
 */

namespace Netivo\Woocommerce\Product;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

abstract class Type extends \WC_Product_Simple {
	public abstract static function register(): void;

	public function __construct( $product ) {
		parent::__construct( $product );
	}
}