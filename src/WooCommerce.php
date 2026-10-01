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
	 * Reads the `modules.woocommerce` configuration from the theme's main class,
	 * always initializes the {@see Company} and {@see CashOnDelivery} integrations, then runs variable,
	 * product type, and (when in wp-admin) product tab initialization, delegating
	 * to the abstract hooks implemented by the child class.
	 *
	 * @param Theme $main_class Main class instance of the theme.
	 */
	public function __construct( $main_class ) {
		$this->main_class = $main_class;

		if ( ! empty( $this->main_class->get_configuration()['modules']['woocommerce'] ) ) {
			$this->modules = $this->main_class->get_configuration()['modules']['woocommerce'];
		}

		new Company();
		new CashOnDelivery();

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
	 * Instantiates every class listed in `modules['product_tabs']` (each must
	 * extend {@see \Netivo\WooCommerce\Admin\Product\Tab}), passing the theme's
	 * view path so the tab can locate its `.phtml` template.
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
	 * Initialize new product type.
	 *
	 * Calls the static `register()` method on every class listed in
	 * `modules['product_type']` (each must extend
	 * {@see \Netivo\WooCommerce\Product\Type}) to register it as a custom
	 * WooCommerce product type.
	 */
	protected function init_product_types(): void {
		if ( ! empty( $this->modules['product_type'] ) ) {
			foreach ( $this->modules['product_type'] as $type ) {
				if ( class_exists( $type ) ) {
					$type::register();
				}
			}
		}
	}

	/**
	 * Init custom data in woocommerce panel for child use.
	 *
	 * Called on every request, after {@see self::init_product_types()}.
	 * Implemented by the theme's concrete subclass.
	 */
	abstract protected function init_child(): void;

	/**
	 * Init custom data in woocommerce admin panel for child use.
	 *
	 * Called only when {@see is_admin()}, after {@see self::init_product_tabs()}.
	 * Implemented by the theme's concrete subclass.
	 */
	abstract protected function init_child_admin(): void;

	/**
	 * Initialize class variables/properties before the rest of the bootstrap runs.
	 *
	 * Called first in the constructor. Implemented by the theme's concrete subclass.
	 */
	abstract protected function init_vars(): void;

}