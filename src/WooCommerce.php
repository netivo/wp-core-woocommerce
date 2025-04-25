<?php
/**
 * Created by Netivo for Netivo Core Package.
 * User: michal
 * Date: 16.11.18
 * Time: 14:45
 *
 * @package Netivo\Core
 */

namespace Netivo\WooCommerce;

use Netivo\Core\Theme;

/**
 * Class Woocommerce
 *
 * Abstract Class to initialize WooCommerce elements in WordPress.
 */
abstract class WooCommerce {
	/**
	 * Include path for plugin, defined in child classes
	 *
	 * @var string
	 */
	protected string $include_path = '';

	/**
	 * Main class of the theme
	 *
	 * @var null | Theme
	 */
	protected ?Theme $main_class = null;

	/**
	 * Modules to initialize
	 *
	 * @var array
	 */
	protected array $modules = [];

	/**
	 * Woocommerce constructor.
	 *
	 * @param string $include_path Include path for plugin.
	 *
	 * @throws \ReflectionException When error.
	 */
	public function __construct( $main_class ) {
		$this->main_class = $main_class;

		if ( ! empty( $this->main_class->get_configuration()['modules']['woocommerce'] ) ) {
			$this->modules = $this->main_class->get_configuration()['modules']['woocommerce'];
		}

		new Company();

		$this->init_vars();
		$this->init_product_types();
		$this->init_child();
		if ( is_admin() ) {
			$this->init_product_tabs();
			$this->init_child_admin();
		}

	}

	/**
	 * Initialize new tab in woocommerce product data metabox.
	 *
	 * @throws \ReflectionException When error.
	 */
	protected function init_product_tabs(): void {
		if ( ! empty( $this->modules['product_tabs'] ) ) {
			foreach ( $this->modules['product_tabs'] as $tab ) {
				if ( class_exists( $tab ) ) {
					new $tab( $this->main_class->get_view_path() );
				}
			}
		}
	}

	/**
	 * Initialize new product type
	 *
	 * @throws \ReflectionException When error.
	 */
	protected function init_product_types(): void {
		if ( ! empty( $this->modules['product_types'] ) ) {
			foreach ( $this->modules['product_types'] as $type ) {
				if ( class_exists( $type ) ) {
					$type::register();
				}
			}
		}
	}

	/**
	 * Init custom data in woocommerce panel for child use.
	 */
	abstract protected function init_child(): void;

	/**
	 * Init custom data in woocommerce admin panel for child use.
	 */
	abstract protected function init_child_admin(): void;

	abstract protected function init_vars(): void;

}