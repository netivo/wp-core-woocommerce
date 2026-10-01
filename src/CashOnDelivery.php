<?php
/**
 * Created by Netivo for Netivo WooCommerce Package.
 * Date: 01.10.2026
 *
 */

namespace Netivo\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class CashOnDelivery
 *
 * Restricts the available payment gateways to cash on delivery (`cod`) when
 * the chosen shipping method is a cash on delivery one. Shipping methods are
 * matched by a label fragment configurable in WooCommerce > Settings >
 * Shipping > Shipping options. Always instantiated by {@see WooCommerce::__construct()}.
 */
class CashOnDelivery {

	/**
	 * Option name storing the shipping label fragment.
	 */
	const OPTION = 'netivo_cod_shipping_label';

	/**
	 * Default shipping label fragment.
	 */
	const DEFAULT_LABEL = 'pobranie';

	/**
	 * CashOnDelivery constructor.
	 *
	 * Registers the settings field and the payment gateway filter.
	 */
	public function __construct() {
		add_filter( 'woocommerce_shipping_settings', [ $this, 'add_settings' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'disable_gateways_based_on_shipping' ] );
	}

	/**
	 * Adds the cash on delivery label field to the shipping options settings
	 * section, just before its section end.
	 *
	 * Hooked to `woocommerce_shipping_settings`.
	 *
	 * @param array $settings Shipping options settings definition.
	 *
	 * @return array Modified settings definition.
	 */
	public function add_settings( $settings ): array {
		$field = [
			'title'    => __( 'Wysyłka za pobraniem', 'netivo' ),
			'desc'     => __( 'Fragment nazwy metody wysyłki oznaczający płatność za pobraniem. Gdy klient wybierze taką metodę, dostępna będzie tylko płatność za pobraniem. Pozostaw puste, aby wyłączyć.', 'netivo' ),
			'id'       => self::OPTION,
			'type'     => 'text',
			'default'  => self::DEFAULT_LABEL,
			'desc_tip' => true,
		];

		$position = count( $settings );
		foreach ( $settings as $i => $setting ) {
			if ( isset( $setting['type'], $setting['id'] ) && $setting['type'] === 'sectionend' && $setting['id'] === 'shipping_options' ) {
				$position = $i;
				break;
			}
		}

		array_splice( $settings, $position, 0, [ $field ] );

		return $settings;
	}

	/**
	 * Removes every payment gateway except cash on delivery when the chosen
	 * shipping method label of any package contains the configured fragment.
	 *
	 * Hooked to `woocommerce_available_payment_gateways`.
	 *
	 * @param array $gateways Available payment gateways keyed by gateway id.
	 *
	 * @return array Filtered payment gateways.
	 */
	public function disable_gateways_based_on_shipping( $gateways ) {
		if ( is_admin() || empty( WC()->session ) ) {
			return $gateways;
		}

		$needle = trim( (string) get_option( self::OPTION, self::DEFAULT_LABEL ) );
		if ( $needle === '' ) {
			return $gateways;
		}

		$chosen_shipping_rates = WC()->session->get( 'chosen_shipping_methods' );
		$packages              = WC()->shipping()->get_packages();

		foreach ( $packages as $i => $package ) {
			$chosen_method = $chosen_shipping_rates[ $i ] ?? '';

			if ( empty( $package['rates'][ $chosen_method ] ) || mb_stripos( $package['rates'][ $chosen_method ]->get_label(), $needle ) === false ) {
				continue;
			}

			foreach ( $gateways as $key => $gateway ) {
				if ( $key !== 'cod' ) {
					unset( $gateways[ $key ] );
				}
			}
			break;
		}

		return $gateways;
	}

}
