<?php

defined( 'ABSPATH' ) || exit;

final class ECM_Payment {
	/**
	 * Payment adapter boundary. A gateway add-on can filter the result and redirect URL later.
	 *
	 * @return array{success:bool,status:string,reference:string,redirect_url:string,message:string}
	 */
	public static function process( object $registration ): array {
		$settings = ECM_Events::config_for_registration( $registration );
		$result   = array(
			'success'      => true,
			'status'       => 'offline_confirmed',
			'reference'    => 'OFFLINE-' . $registration->public_id,
			'redirect_url' => '',
			'message'      => '',
		);

		/**
		 * Integrations may return success=false with a redirect_url for a real gateway.
		 */
		return (array) apply_filters( 'ecm_payment_result', $result, $registration, $settings );
	}
}

