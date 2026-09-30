<?php
/**
 * Created by Netivo for wp-core
 * User: manveru
 * Date: 9.08.2024
 * Time: 15:00
 *
 */

namespace Netivo\WooCommerce\Admin\Product;

use ReflectionClass;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Tab
 *
 * Base class for custom tabs in the WooCommerce product data metabox.
 * Subclasses are listed in the theme's `modules['product_tabs']` configuration
 * and are instantiated by {@see \Netivo\WooCommerce\WooCommerce::init_product_tabs()}.
 */
abstract class Tab {
	/**
	 * Tab id.
	 *
	 * @var string
	 */
	protected string $id;
	/**
	 * Tab title.
	 *
	 * @var string
	 */
	protected string $title;

	/**
	 * Tab priority in menu
	 *
	 * @var int
	 */
	protected int $priority = 15;

	/**
	 * Path to Admin folder on server.
	 *
	 * @var string
	 */
	protected string $path = '';

	/**
	 * Tab constructor.
	 *
	 * @param string $path Path to Admin folder.
	 */
	public function __construct( string $path ) {
		$this->path = $path;

		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'display' ] );
		add_action( 'save_post', [ $this, 'do_save' ] );
	}

	/**
	 * Adds tab to product data metabox.
	 *
	 * @param array $tabs Current tabs in product data metabox.
	 *
	 * @return array
	 */
	public function add_tab( array $tabs ): array {
		$tabs[ $this->id ] = array(
			'label'    => $this->title,
			'target'   => 'nt_' . $this->id . '_product_data',
			'class'    => array( '' ),
			'priority' => $this->priority,
		);

		return $tabs;
	}

	/**
	 * Displays the tab content.
	 *
	 * Resolves the view file by looking for a `Netivo\Attributes\View` PHP
	 * attribute on the subclass; if absent, derives the view name from the
	 * subclass's lowercased filename. The view is included from
	 * `{$this->path}/woocommerce/product/tabs/{$name}.phtml`.
	 *
	 * Hooked to `woocommerce_product_data_panels`.
	 *
	 * @throws \Exception When no matching view file exists.
	 */
	public function display(): void {
		global $post, $thepostid, $product_object;

		$obj  = new ReflectionClass( $this );
		$data = $obj->getAttributes();
		foreach ( $data as $attribute ) {
			if ( $attribute->getName() === 'Netivo\Attributes\View' ) {
				$name = $attribute->getArguments()[0];
			}
		}
		if ( empty( $name ) ) {
			$filename = $obj->getFileName();
			$filename = str_replace( '.php', '', $filename );

			$name = basename( $filename );

			$name = strtolower( $name );
		}

		$filename = $this->path . '/woocommerce/product/tabs/' . $name . '.phtml';

		if ( file_exists( $filename ) ) {
			echo '<div id="nt_' . esc_attr( $this->id ) . '_product_data" class="panel woocommerce_options_panel">';
			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- $filename is built from the theme's own admin path plus a class-derived name, not user input.
			include $filename;
			echo '</div>';
		} else {
			throw new \Exception( "There is no view file for this admin action" );
		}

	}

	/**
	 * Start saving process of the metabox.
	 *
	 * Guards {@see self::save()} so it only runs for `product` posts; returns
	 * the post ID unchanged for any other post type.
	 *
	 * Hooked to `save_post`.
	 *
	 * @param string $post_id Id of the saved post.
	 *
	 * @return mixed The post ID (if not a product) or {@see self::save()}'s return value.
	 */
	public function do_save( string $post_id ): mixed {
		if ( get_post_type( $post_id ) !== 'product' ) {
			return $post_id;
		}

		return $this->save( $post_id );
	}

	/**
	 * Method where the saving process is done. Use it in metabox to save the data.
	 *
	 * @param int $post_id Id of the saved post.
	 *
	 * @return mixed
	 */
	abstract public function save( int $post_id ): mixed;
}