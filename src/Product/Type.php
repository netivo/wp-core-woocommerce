<?php
/**
 * Created by Netivo for wp-core
 * User: manveru
 * Date: 24.04.2025
 * Time: 13:51
 *
 */

namespace Netivo\WooCommerce\Product;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Type
 *
 * Base class for custom WooCommerce simple product types. Subclasses are
 * listed in the theme's `modules['product_type']` configuration and are
 * registered via {@see \Netivo\WooCommerce\WooCommerce::init_product_types()}.
 */
abstract class Type extends \WC_Product_Simple {
	/**
	 * Registers the product type with WooCommerce (e.g. via the
	 * `product_type_selector` and `woocommerce_product_class` filters).
	 *
	 * Called statically by {@see \Netivo\WooCommerce\WooCommerce::init_product_types()}.
	 */
	public abstract static function register(): void;

	/**
	 * Type constructor.
	 *
	 * @param int|\WC_Product|object $product Product ID, product object, or raw data.
	 */
	public function __construct( $product ) {
		parent::__construct( $product );
	}
}