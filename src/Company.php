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

class Company {

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

	public function change_address_format( $formats ) {
		$formats['PL'] = "{nip}\n{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}";

		return $formats;
	}

	public function change_address_format_replace( $replacement, $args ) {
		$replacement['{nip}'] = ( ! empty( $args['nip'] ) ) ? 'NIP: ' . $args['nip'] : '';

		return $replacement;
	}

	public function add_nip_to_order_address( $data, $order ) {
		if ( $order->get_meta( '_fv_vat' ) == '1' ) {
			$data['nip'] = $order->get_meta( '_nip' );
		}

		return $data;
	}

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

	public function add_invoice_field( $fields ): array {
		$fields['billing']['billing_first_name']['class']    = [ 'form-row form-row-first js-fvat-to-hide' ];
		$fields['billing']['billing_first_name']['required'] = false;
		$fields['billing']['billing_first_name']['label']    = $fields['billing']['billing_first_name']['label'] . '*';
		$fields['billing']['billing_last_name']['class']     = [ 'form-row form-row-last js-fvat-to-hide' ];
		$fields['billing']['billing_last_name']['required']  = false;
		$fields['billing']['billing_last_name']['label']     = $fields['billing']['billing_last_name']['label'] . '*';
		$fields['billing']['billing_company']['class']       = [ 'form-row form-row-last js-fvat-to-show' ];
		$fields['billing']['billing_company']['priority']    = 30;
		$fields['billing']['billing_company']['label']       = $fields['billing']['billing_company']['label'] . '*';
		$fields['billing']['billing_nip']['class']           = [ 'form-row form-row-first js-fvat-to-show' ];
		$fields['billing']['billing_nip']['required']        = false;
		$fields['billing']['billing_nip']['label']           = __( 'NIP *', 'netivo' );
		$fields['billing']['fv_vat']                         = array(
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

	public function validate_nip_field(): void {
		if ( empty( $_POST['fv_vat'] ) && empty( $_POST['billing_first_name'] ) ) {
			wc_add_notice( __( '<strong>Imię</strong> jest wymaganym polem.', 'netivo' ), 'error' );
		}
		if ( empty( $_POST['fv_vat'] ) && empty( $_POST['billing_last_name'] ) ) {
			wc_add_notice( __( '<strong>Nazwisko</strong> jest wymaganym polem.', 'netivo' ), 'error' );
		}
		if ( ! empty( $_POST['fv_vat'] ) && empty( $_POST['billing_company'] ) ) {
			wc_add_notice( __( '<strong>Nazwa firmy</strong> jest wymaganym polem.', 'netivo' ), 'error' );
		}
		if ( ! empty( $_POST['fv_vat'] ) && empty( $_POST['billing_nip'] ) ) {
			wc_add_notice( __( '<strong>NIP</strong> jest wymaganym polem.', 'netivo' ), 'error' );
		}
		if ( ! empty( $_POST['fv_vat'] ) && ! $this->validate_nip( $_POST['billing_nip'] ) ) {
			wc_add_notice( __( 'Proszę podać poprawny <strong>NIP</strong>', 'netivo' ), 'error' );
		}

		if ( ! empty( $_POST['fv_vat'] ) ) {
			unset( $_POST['billing_first_name'] );
			unset( $_POST['billing_last_name'] );
		}
	}

	/**
	 * @param $order \WC_Order
	 *
	 * @return void
	 */
	public function save_nip_field( \WC_Order $order ): void {
		if ( ! empty( $_POST['fv_vat'] ) ) {
			$order->update_meta_data( '_fv_vat', sanitize_text_field( $_POST['fv_vat'] ) );
			if ( ! empty( $_POST['billing_nip'] ) ) {
				$order->update_meta_data( '_nip', sanitize_text_field( $_POST['billing_nip'] ) );
				$customer_id = $order->get_customer_id();
				if ( ! empty( $customer_id ) ) {
					update_user_meta( $customer_id, 'billing_nip', sanitize_text_field( $_POST['billing_nip'] ) );
				}
			}
		}
	}

	public function add_nip_to_customer_address( $address, $customer_id, $address_type ) {
		if ( $address_type == 'billing' ) {
			$nip = get_user_meta( $customer_id, 'billing_nip', true );
			if ( ! empty( $nip ) ) {
				$address['nip'] = $nip;
			}
		}

		return $address;
	}


	public function validate_customer_nip( $user_id, $address_type, $address ) {
		if ( ! empty( $_POST['billing_nip'] ) ) {
			if ( ! $this->validate_nip( $_POST['billing_nip'] ) ) {
				wc_add_notice( __( 'Proszę podać poprawny <strong>NIP</strong>', 'netivo' ), 'error' );
			}
		}
	}

	protected function validate_nip( $nip ): bool {
		$nip = str_replace( [ '-', ' ' ], '', $nip );
		if ( strlen( $nip ) == 10 ) {
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

}