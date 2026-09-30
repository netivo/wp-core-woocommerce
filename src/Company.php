<?php
/**
 * Created by Netivo for Elazienki Theme v2
 * User: manveru
 * Date: 11.03.2025
 * Time: 16:37
 *
 */

namespace Netivo\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Company
 *
 * Adds Polish invoicing support (NIP tax id, VAT invoice vs. receipt choice)
 * to WooCommerce billing fields, checkout, order storage, and the customer
 * account address book. Always instantiated by {@see WooCommerce::__construct()}.
 */
class Company {

	/**
	 * Company constructor.
	 *
	 * Registers all WordPress/WooCommerce filters and actions used to add,
	 * validate, and persist the NIP/invoice fields.
	 */
	public function __construct() {
		/**
		 * Fields
		 */
		add_filter( 'woocommerce_billing_fields', [ $this, 'add_nip_field_to_address' ], 10, 1 );
		add_filter( 'woocommerce_localisation_address_formats', [ $this, 'change_address_format' ] );
		add_filter( 'woocommerce_formatted_address_replacements', [ $this, 'change_address_format_replace' ], 10, 2 );

		add_action( 'wp_enqueue_scripts', [ $this, 'add_javascript_to_page' ] );

		/**
		 * Order
		 */
		add_filter( 'woocommerce_order_formatted_billing_address', [ $this, 'add_nip_to_order_address' ], 10, 2 );
		add_action( 'woocommerce_checkout_fields', [ $this, 'add_invoice_field' ] );
		add_action( 'woocommerce_checkout_process', [ $this, 'validate_nip_field' ] );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'save_nip_field' ] );

		/**
		 * Customer
		 */
		add_filter( 'woocommerce_my_account_my_address_formatted_address', [
			$this,
			'add_nip_to_customer_address'
		], 10, 3 );
		add_action( 'woocommerce_after_save_address_validation', [ $this, 'validate_customer_nip' ], 10, 3 );
	}


	/**
	 * Adds a `billing_nip` field to the WooCommerce billing fields and
	 * reorders/repositions the company field next to it.
	 *
	 * Hooked to `woocommerce_billing_fields`.
	 *
	 * @param array $address Billing fields definition.
	 *
	 * @return array Modified billing fields definition.
	 */
	public function add_nip_field_to_address( $address ) {
		$address['billing_nip']                    = array(
			'required'    => false,
			'class'       => array( 'form-row form-row-first' ),
			'input_class' => array( 'text-input' ),
			'label'       => __( 'NIP', 'netivo' ),
			'priority'    => 25,
			'value'       => get_user_meta( get_current_user_id(), 'billing_nip', true )
		);
		$address['billing_company']['class']       = [ 'form-row form-row-last' ];
		$address['billing_company']['priority']    = 30;
		$address['billing_first_name']['required'] = false;
		$address['billing_last_name']['required']  = false;

		return $address;
	}

	/**
	 * Adds a `{nip}` placeholder to the Polish (PL) address format.
	 *
	 * Hooked to `woocommerce_localisation_address_formats`.
	 *
	 * @param array $formats Country code => address format string map.
	 *
	 * @return array Modified format map.
	 */
	public function change_address_format( $formats ) {
		$formats['PL'] = "{nip}\n{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}";

		return $formats;
	}

	/**
	 * Resolves the `{nip}` placeholder used in {@see self::change_address_format()}.
	 *
	 * Hooked to `woocommerce_formatted_address_replacements`.
	 *
	 * @param array $replacement Placeholder => replacement value map.
	 * @param array $args        Address arguments, including `nip` when present.
	 *
	 * @return array Modified replacement map.
	 */
	public function change_address_format_replace( $replacement, $args ) {
		$replacement['{nip}'] = ( ! empty( $args['nip'] ) ) ? 'NIP: ' . $args['nip'] : '';

		return $replacement;
	}

	/**
	 * Adds the stored NIP to an order's formatted billing address when the
	 * order was placed as a VAT invoice.
	 *
	 * Hooked to `woocommerce_order_formatted_billing_address`.
	 *
	 * @param array     $data  Formatted billing address data.
	 * @param \WC_Order $order Order the address belongs to.
	 *
	 * @return array Modified formatted billing address data.
	 */
	public function add_nip_to_order_address( $data, $order ) {
		if ( $order->get_meta( '_fv_vat' ) === '1' ) {
			$data['nip'] = $order->get_meta( '_nip' );
		}

		return $data;
	}

	/**
	 * Enqueues the checkout invoice/receipt toggle script on the checkout page.
	 *
	 * The script is expected at `dist/netivo-woocommerce-checkout.js` relative
	 * to this package, but is resolved and served from the active theme's
	 * directory (the package is assumed to be deployed under the theme).
	 * Silently does nothing if the built file is missing.
	 *
	 * Hooked to `wp_enqueue_scripts`.
	 */
	public function add_javascript_to_page() {
		$file = realpath( __DIR__ . '/../dist/netivo-woocommerce-checkout.js' );

		if ( is_checkout() && file_exists( $file ) ) {
			$td    = get_template_directory();
			$turl  = get_template_directory_uri();
			$nfile = str_replace( $td, $turl, $file );
			wp_enqueue_script( 'nt-wooocommerce-checkout', $nfile, array(), false, array(
				'in_footer' => true,
				'defer'     => true
			) );
		}
	}

	/**
	 * Adds the `fv_vat` receipt/invoice choice field and styles the name,
	 * company, and NIP checkout fields so the front-end script
	 * (`checkout.js`) can toggle them based on that choice.
	 *
	 * Name field visibility/requirement is controlled by the
	 * `netivo/woocommerce/fv/hide-name` and `netivo/woocommerce/fv/name-required`
	 * filters, which the consuming theme may override.
	 *
	 * Hooked to `woocommerce_checkout_fields`.
	 *
	 * @param array $fields Checkout fields definition.
	 *
	 * @return array Modified checkout fields definition.
	 */
	public function add_invoice_field( $fields ): array {
		if ( apply_filters( 'netivo/woocommerce/fv/hide-name', true ) ) {
			$fields['billing']['billing_first_name']['class'] = [ 'form-row form-row-first js-fvat-to-hide' ];
		} else {
			$fields['billing']['billing_first_name']['class'] = [ 'form-row form-row-first' ];
		}
		$fields['billing']['billing_first_name']['required'] = apply_filters( 'netivo/woocommerce/fv/name-required', false );
		$fields['billing']['billing_first_name']['label']    = $fields['billing']['billing_first_name']['label'] . '*';
		if ( apply_filters( 'netivo/woocommerce/fv/hide-name', true ) ) {
			$fields['billing']['billing_last_name']['class'] = [ 'form-row form-row-last js-fvat-to-hide' ];
		} else {
			$fields['billing']['billing_last_name']['class'] = [ 'form-row form-row-last' ];
		}
		$fields['billing']['billing_last_name']['required'] = apply_filters( 'netivo/woocommerce/fv/name-required', false );
		$fields['billing']['billing_last_name']['label']    = $fields['billing']['billing_last_name']['label'] . '*';
		$fields['billing']['billing_company']['class']      = [ 'form-row form-row-last js-fvat-to-show' ];
		$fields['billing']['billing_company']['priority']   = 30;
		$fields['billing']['billing_company']['label']      = $fields['billing']['billing_company']['label'] . '*';
		$fields['billing']['billing_nip']['class']          = [ 'form-row form-row-first js-fvat-to-show' ];
		$fields['billing']['billing_nip']['required']       = false;
		$fields['billing']['billing_nip']['label']          = __( 'NIP *', 'netivo' );
		$fields['billing']['fv_vat']                        = array(
			'type'        => 'radio',
			'options'     => [
				0 => __( 'Paragon / Faktura imienna', 'netivo' ),
				1 => __( 'Faktura VAT', 'netivo' )
			],
			'default'     => 0,
			'required'    => true,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 5,
			'input_class' => array( 'js-fv-checkbox' )
		);


		return $fields;
	}

	/**
	 * Validates checkout submission: requires first/last name for a receipt,
	 * or company name and a valid NIP for a VAT invoice. Adds a WooCommerce
	 * notice for each failed rule.
	 *
	 * When a VAT invoice is requested and `netivo/woocommerce/fv/hide-name` is
	 * true, also strips the submitted name fields from `$_POST` so they are
	 * not persisted.
	 *
	 * Hooked to `woocommerce_checkout_process`.
	 */
	public function validate_nip_field(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the `woocommerce-process_checkout` nonce before this action fires.
		$fv_vat     = isset( $_POST['fv_vat'] ) ? sanitize_text_field( wp_unslash( $_POST['fv_vat'] ) ) : '';
		$first_name = isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '';
		$last_name  = isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '';
		$company    = isset( $_POST['billing_company'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_company'] ) ) : '';
		$nip        = isset( $_POST['billing_nip'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_nip'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $fv_vat ) && empty( $first_name ) ) {
			wc_add_notice( __( '<strong>Imię</strong> jest wymaganym polem.', 'netivo' ), 'error' );
		}
		if ( empty( $fv_vat ) && empty( $last_name ) ) {
			wc_add_notice( __( '<strong>Nazwisko</strong> jest wymaganym polem.', 'netivo' ), 'error' );
		}
		if ( ! empty( $fv_vat ) && empty( $company ) ) {
			wc_add_notice( __( '<strong>Nazwa firmy</strong> jest wymaganym polem.', 'netivo' ), 'error' );
		}
		if ( ! empty( $fv_vat ) && empty( $nip ) ) {
			wc_add_notice( __( '<strong>NIP</strong> jest wymaganym polem.', 'netivo' ), 'error' );
		}
		if ( ! empty( $fv_vat ) && ! $this->validate_nip( $nip ) ) {
			wc_add_notice( __( 'Proszę podać poprawny <strong>NIP</strong>', 'netivo' ), 'error' );
		}

		if ( ! empty( $fv_vat ) && apply_filters( 'netivo/woocommerce/fv/hide-name', true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the `woocommerce-process_checkout` nonce before this action fires.
			unset( $_POST['billing_first_name'], $_POST['billing_last_name'] );
		}
	}

	/**
	 * Validates a Polish NIP (tax id) using the standard 10-digit weighted
	 * mod-11 checksum algorithm.
	 *
	 * @param string $nip NIP to validate, with or without dashes/spaces.
	 *
	 * @return bool True when the NIP has a valid checksum.
	 */
	protected function validate_nip( $nip ): bool {
		$nip = str_replace( [ '-', ' ' ], '', $nip );
		if ( strlen( $nip ) === 10 ) {
			$digits   = str_split( $nip, 1 );
			$weights  = [ '6', '5', '7', '2', '3', '4', '5', '6', '7' ];
			$checksum = intval( $digits[9] );
			unset( $digits[9] );
			$digits = array_map( function ( $x, $y ) {
				return intval( $x ) * $y;
			}, $digits, $weights );
			$sum    = array_sum( $digits );
			if ( $sum % 11 === $checksum ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Persists the invoice choice to order meta and, when a NIP was submitted,
	 * saves it to order meta and to the customer's `billing_nip` user meta.
	 *
	 * Hooked to `woocommerce_checkout_create_order`.
	 *
	 * @param \WC_Order $order Order being created.
	 */
	public function save_nip_field( \WC_Order $order ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the `woocommerce-process_checkout` nonce before this action fires.
		$fv_vat = isset( $_POST['fv_vat'] ) ? sanitize_text_field( wp_unslash( $_POST['fv_vat'] ) ) : '';
		$nip    = isset( $_POST['billing_nip'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_nip'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! empty( $fv_vat ) ) {
			$order->update_meta_data( '_fv_vat', $fv_vat );
			if ( ! empty( $nip ) ) {
				$order->update_meta_data( '_nip', $nip );
				$customer_id = $order->get_customer_id();
				if ( ! empty( $customer_id ) ) {
					update_user_meta( $customer_id, 'billing_nip', $nip );
				}
			}
		}
	}

	/**
	 * Adds the stored NIP to a customer's formatted billing address.
	 *
	 * Hooked to `woocommerce_my_account_my_address_formatted_address`.
	 *
	 * @param array  $address      Formatted address data.
	 * @param int    $customer_id  Customer user ID.
	 * @param string $address_type Address type (e.g. `billing`, `shipping`).
	 *
	 * @return array Modified formatted address data.
	 */
	public function add_nip_to_customer_address( $address, $customer_id, $address_type ) {
		if ( $address_type === 'billing' ) {
			$nip = get_user_meta( $customer_id, 'billing_nip', true );
			if ( ! empty( $nip ) ) {
				$address['nip'] = $nip;
			}
		}

		return $address;
	}

	/**
	 * Validates the NIP submitted when a customer saves an address in their
	 * account, adding a WooCommerce notice if it fails the checksum.
	 *
	 * Hooked to `woocommerce_after_save_address_validation`.
	 *
	 * @param int    $_user_id      Customer user ID. Unused, required by the hook signature.
	 * @param string $_address_type Address type being saved (e.g. `billing`). Unused, required by the hook signature.
	 * @param array  $_address      Submitted address data. Unused, required by the hook signature.
	 */
	// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Parameters are required by the `woocommerce_after_save_address_validation` hook signature.
	public function validate_customer_nip( $_user_id, $_address_type, $_address ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the `woocommerce-edit_address` nonce before this action fires.
		$nip = isset( $_POST['billing_nip'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_nip'] ) ) : '';

		if ( ! empty( $nip ) && ! $this->validate_nip( $nip ) ) {
			wc_add_notice( __( 'Proszę podać poprawny <strong>NIP</strong>', 'netivo' ), 'error' );
		}
	}

}