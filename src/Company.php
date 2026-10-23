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
 * account address book. Non-Polish billing countries get a generic
 * "VAT / Tax ID" label and country-specific tax number validation.
 * Always instantiated by {@see WooCommerce::__construct()}.
 */
class Company {

	/**
	 * Structural VAT number patterns for non-Polish EU/EEA/UK countries.
	 * Numbers are matched with an optional leading country-code prefix stripped first
	 * (e.g. "DE123456789" or "123456789" both validate for country DE).
	 */
	protected const EU_VAT_PATTERNS = [
		'AT' => '/^U\d{8}$/',
		'BE' => '/^0?\d{9}$/',
		'BG' => '/^\d{9,10}$/',
		'CY' => '/^\d{8}[A-Z]$/',
		'CZ' => '/^\d{8,10}$/',
		'DE' => '/^\d{9}$/',
		'DK' => '/^\d{8}$/',
		'EE' => '/^\d{9}$/',
		'EL' => '/^\d{9}$/',
		'GR' => '/^\d{9}$/',
		'ES' => '/^[A-Z0-9]\d{7}[A-Z0-9]$/',
		'FI' => '/^\d{8}$/',
		'FR' => '/^[A-Z0-9]{2}\d{9}$/',
		'HR' => '/^\d{11}$/',
		'HU' => '/^\d{8}$/',
		'IE' => '/^\d{7}[A-Z]{1,2}$|^\d[A-Z]\d{5}[A-Z]$/',
		'IT' => '/^\d{11}$/',
		'LT' => '/^\d{9}$|^\d{12}$/',
		'LU' => '/^\d{8}$/',
		'LV' => '/^\d{11}$/',
		'MT' => '/^\d{8}$/',
		'NL' => '/^\d{9}B\d{2}$/',
		'PT' => '/^\d{9}$/',
		'RO' => '/^\d{2,10}$/',
		'SE' => '/^\d{12}$/',
		'SI' => '/^\d{8}$/',
		'SK' => '/^\d{10}$/',
		'GB' => '/^\d{9}$|^\d{12}$|^(GD|HA)\d{3}$/',
		'CH' => '/^E\d{9}$|^\d{9}$/',
		'NO' => '/^\d{9}$/',
	];

	/**
	 * VAT number prefixes that differ from the WooCommerce (ISO) country code.
	 */
	protected const VAT_PREFIX_ALIASES = [
		'GR' => 'EL',
	];

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
			'label'       => $this->get_tax_number_label( $this->get_customer_country() ),
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
	 * Adds a `{nip}` placeholder to the address format of Poland and every
	 * country listed in {@see self::EU_VAT_PATTERNS}.
	 *
	 * Poland gets a fixed format. For the other countries the `{nip}` line is
	 * prepended to the country's own WooCommerce format (or the default one),
	 * so their local address layout is kept.
	 *
	 * Hooked to `woocommerce_localisation_address_formats`.
	 *
	 * @param array $formats Country code => address format string map.
	 *
	 * @return array Modified format map.
	 */
	public function change_address_format( $formats ) {
		$formats['PL'] = "{nip}\n{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}";

		foreach ( array_keys( self::EU_VAT_PATTERNS ) as $country ) {
			$format = $formats[ $country ] ?? ( $formats['default'] ?? '' );
			if ( '' !== $format && ! str_contains( $format, '{nip}' ) ) {
				$formats[ $country ] = "{nip}\n" . $format;
			}
		}

		return $formats;
	}

	/**
	 * Resolves the `{nip}` placeholder used in {@see self::change_address_format()},
	 * prefixing the number with the country-dependent tax number label.
	 *
	 * Hooked to `woocommerce_formatted_address_replacements`.
	 *
	 * @param array $replacement Placeholder => replacement value map.
	 * @param array $args        Address arguments, including `nip` when present.
	 *
	 * @return array Modified replacement map.
	 */
	public function change_address_format_replace( $replacement, $args ) {
		if ( empty( $args['nip'] ) ) {
			$replacement['{nip}'] = '';
		} else {
			$replacement['{nip}'] = $this->get_tax_number_label( $args['country'] ?? '' ) . ': ' . $args['nip'];
		}

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
	 * The NIP field label follows the customer's billing country at page load
	 * (see {@see self::get_customer_country()}); it is not updated when the
	 * country is changed on the checkout form.
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
		$fields['billing']['billing_nip']['label']          = $this->get_tax_number_label( $this->get_customer_country() ) . ' *';
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
	 * or company name and a valid tax number for a VAT invoice (validated
	 * against the billing country, see {@see self::validate_nip()}). Adds a
	 * WooCommerce notice for each failed rule.
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
		$country    = isset( $_POST['billing_country'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$tax_label = $this->get_tax_number_label( $country );

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
			/* translators: %s: tax number label (NIP or VAT / Tax ID) */
			wc_add_notice( sprintf( __( '<strong>%s</strong> jest wymaganym polem.', 'netivo' ), $tax_label ), 'error' );
		}
		if ( ! empty( $fv_vat ) && ! empty( $nip ) && ! $this->validate_nip( $nip, $country ) ) {
			/* translators: %s: tax number label (NIP or VAT / Tax ID) */
			wc_add_notice( sprintf( __( 'Proszę podać poprawny numer <strong>%s</strong>', 'netivo' ), $tax_label ), 'error' );
		}

		if ( ! empty( $fv_vat ) && apply_filters( 'netivo/woocommerce/fv/hide-name', true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the `woocommerce-process_checkout` nonce before this action fires.
			unset( $_POST['billing_first_name'], $_POST['billing_last_name'] );
		}
	}

	/**
	 * Returns the label to use for the tax number field depending on the billing country.
	 * Polish customers see "NIP"; everyone else sees a generic "VAT / Tax ID" label.
	 *
	 * @param string $country Billing country code. Falls back to the store base country when empty.
	 *
	 * @return string Tax number label.
	 */
	protected function get_tax_number_label( string $country ): string {
		if ( '' === trim( $country ) ) {
			$country = $this->get_base_country();
		}

		if ( strtoupper( trim( $country ) ) === 'PL' ) {
			return __( 'NIP', 'netivo' );
		}

		return __( 'VAT / Tax ID', 'netivo' );
	}

	/**
	 * Validates a tax/VAT number against rules appropriate for the given billing country.
	 * - PL: strict Polish NIP checksum.
	 * - Other EU/EEA/UK/CH/NO countries: structural VAT number format check.
	 * - Everywhere else: generic sanity check only, since no universal tax id standard exists.
	 *
	 * @param string $nip     Tax number to validate.
	 * @param string $country Billing country code. Falls back to the submitted `billing_country`,
	 *                        then to the store base country, when empty.
	 *
	 * @return bool True when the tax number is valid for the country.
	 */
	protected function validate_nip( $nip, string $country = '' ): bool {
		$nip     = trim( (string) $nip );
		$country = strtoupper( trim( $country ) );

		if ( '' === $nip ) {
			return false;
		}

		if ( '' === $country ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only called from WooCommerce checkout/address hooks, which verify their nonces before firing.
			$country = isset( $_POST['billing_country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) ) : '';
		}

		if ( '' === $country ) {
			$country = strtoupper( $this->get_base_country() );
		}

		if ( 'PL' === $country ) {
			return $this->validate_pl_nip( $nip );
		}

		if ( isset( self::EU_VAT_PATTERNS[ $country ] ) ) {
			return $this->validate_eu_vat( $nip, $country );
		}

		return $this->validate_generic_tax_id( $nip );
	}

	/**
	 * Validates a Polish NIP (tax id) using the standard 10-digit weighted
	 * mod-11 checksum algorithm.
	 *
	 * @param string $nip NIP to validate, with or without dashes/spaces.
	 *
	 * @return bool True when the NIP has a valid checksum.
	 */
	protected function validate_pl_nip( string $nip ): bool {
		$nip = str_replace( [ '-', ' ' ], '', $nip );
		if ( strlen( $nip ) === 10 && ctype_digit( $nip ) ) {
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
	 * Validates a VAT number against the structural pattern of the given country
	 * from {@see self::EU_VAT_PATTERNS}. An optional leading country-code prefix (or its VAT
	 * prefix alias from {@see self::VAT_PREFIX_ALIASES}, e.g. "EL" for Greece) is stripped first.
	 *
	 * @param string $number  VAT number to validate.
	 * @param string $country Country code present in {@see self::EU_VAT_PATTERNS}.
	 *
	 * @return bool True when the number matches the country pattern.
	 */
	protected function validate_eu_vat( string $number, string $country ): bool {
		$number = strtoupper( str_replace( [ '-', ' ', '.' ], '', $number ) );
		foreach ( [ $country, self::VAT_PREFIX_ALIASES[ $country ] ?? '' ] as $prefix ) {
			if ( '' !== $prefix && str_starts_with( $number, $prefix ) ) {
				$number = substr( $number, strlen( $prefix ) );
				break;
			}
		}

		return (bool) preg_match( self::EU_VAT_PATTERNS[ $country ], $number );
	}

	/**
	 * Generic sanity check for tax ids of countries without a known format.
	 *
	 * @param string $number Tax id to validate.
	 *
	 * @return bool True when the tax id looks plausible.
	 */
	protected function validate_generic_tax_id( string $number ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9\-. \/]{3,20}$/', trim( $number ) );
	}

	/**
	 * Returns the current customer's billing country: the WooCommerce customer
	 * (session or saved account), then the user meta, then the store base country.
	 *
	 * @return string Country code, empty when none can be determined.
	 */
	protected function get_customer_country(): string {
		$country = '';
		if ( function_exists( 'WC' ) && WC()->customer instanceof \WC_Customer ) {
			$country = (string) WC()->customer->get_billing_country();
		}
		if ( '' === $country ) {
			$country = (string) get_user_meta( get_current_user_id(), 'billing_country', true );
		}

		return '' !== $country ? $country : $this->get_base_country();
	}

	/**
	 * Returns the WooCommerce store base country.
	 *
	 * @return string Country code, empty when WooCommerce is not loaded.
	 */
	protected function get_base_country(): string {
		if ( function_exists( 'WC' ) && WC()->countries instanceof \WC_Countries ) {
			return (string) WC()->countries->get_base_country();
		}

		return '';
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
	 * Validates the tax number submitted when a customer saves their billing
	 * address in their account, adding a WooCommerce notice if it is invalid
	 * for the billing country.
	 *
	 * Hooked to `woocommerce_after_save_address_validation`.
	 *
	 * @param int    $_user_id     Customer user ID. Unused, required by the hook signature.
	 * @param string $address_type Address type being saved (e.g. `billing`).
	 * @param array  $address      Submitted address data.
	 */
	// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Parameters are required by the `woocommerce_after_save_address_validation` hook signature.
	public function validate_customer_nip( $_user_id, $address_type, $address ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the `woocommerce-edit_address` nonce before this action fires.
		$nip     = isset( $_POST['billing_nip'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_nip'] ) ) : '';
		$country = $address['country'] ?? ( isset( $_POST['billing_country'] ) ? wp_unslash( $_POST['billing_country'] ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'billing' !== $address_type || empty( $nip ) ) {
			return;
		}

		$country = sanitize_text_field( $country );

		if ( ! $this->validate_nip( $nip, $country ) ) {
			/* translators: %s: tax number label (NIP or VAT / Tax ID) */
			wc_add_notice( sprintf( __( 'Proszę podać poprawny numer <strong>%s</strong>', 'netivo' ), $this->get_tax_number_label( $country ) ), 'error' );
		}
	}

}