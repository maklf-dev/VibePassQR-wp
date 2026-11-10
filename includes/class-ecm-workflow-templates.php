<?php

defined( 'ABSPATH' ) || exit;

/** Admin-edited presentation only: no PHP, script, arbitrary WordPress shortcodes or unscoped CSS. */
final class ECM_Workflow_Templates {
	public static function defaults(): array {
		return array(
			'form' => '<h2>اطلاعات ثبت‌نام</h2><p>لطفاً مشخصات را تکمیل کنید.</p><div>[ecm_fields]</div>',
			'agreement' => '<h2>میثاق‌نامه</h2><p>شرایط شرکت در رویداد را مطالعه کردم.</p><div>[ecm_agreement]</div>',
			'receipt' => '<h2>واریز و ارسال رسید</h2><div>[ecm_payment_details]</div><p>تصویر رسید پرداخت را بارگذاری کنید.</p><div>[ecm_receipt_upload]</div>',
			'result' => '<h2>وضعیت درخواست</h2><p>[ecm_status]</p><p>[ecm_tracking_code]</p>',
		);
	}

	public static function sanitize_html( string $html ): string|WP_Error {
		if ( strlen( $html ) > 16000 ) {
			return new WP_Error( 'ecm_flow_html_size', 'حداکثر حجم HTML هر مرحله ۱۶ کیلوبایت است.' );
		}
		// Shortcodes are resolved only from explicitly allowed control tokens below.
		return wp_kses_post( $html );
	}

	/** Reject anything outside a deliberately narrow, prefixable CSS subset. */
	public static function sanitize_css( string $css ): string|WP_Error {
		$css = trim( $css );
		if ( strlen( $css ) > 4096 || preg_match( '/@|\\\\|<|>|\/\*|\*\/|url\s*\(|expression\s*\(|var\s*\(|javascript|!important/i', $css ) ) {
			return new WP_Error( 'ecm_flow_css_forbidden', 'CSS نامعتبر یا دارای دستور غیرمجاز است.' );
		}
		if ( '' === $css ) {
			return '';
		}
		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER );
		if ( ! $matches || '' !== trim( preg_replace( '/([^{}]+)\{([^{}]*)\}/', '', $css ) ) ) {
			return new WP_Error( 'ecm_flow_css_format', 'فقط قوانین ساده CSS مجاز هستند.' );
		}
		$allowed = array( 'color', 'background', 'background-color', 'font-size', 'font-weight', 'font-family', 'line-height', 'text-align', 'border', 'border-color', 'border-width', 'border-style', 'border-radius', 'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right', 'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right', 'max-width', 'min-width', 'width', 'gap', 'display', 'grid-template-columns', 'align-items', 'justify-content', 'box-shadow', 'opacity' );
		$rules = array();
		foreach ( $matches as $match ) {
			$selectors = explode( ',', trim( $match[1] ) );
			$scoped = array();
			foreach ( $selectors as $selector ) {
				$selector = trim( $selector );
				if ( '' === $selector || ! preg_match( '/^[a-zA-Z0-9.#:_\-\s>+~]+$/D', $selector ) ) {
					return new WP_Error( 'ecm_flow_css_selector', 'انتخابگر CSS مجاز نیست.' );
				}
				$scoped[] = $selector;
			}
			$declarations = array();
			foreach ( explode( ';', trim( $match[2] ) ) as $declaration ) {
				$declaration = trim( $declaration );
				if ( '' === $declaration ) {
					continue;
				}
				$parts = explode( ':', $declaration, 2 );
				$property = strtolower( trim( $parts[0] ?? '' ) );
				$value = trim( $parts[1] ?? '' );
				if ( ! in_array( $property, $allowed, true ) || '' === $value || ! preg_match( '/^[a-zA-Z0-9#%\s.,()\-\/]+$/D', $value ) ) {
					return new WP_Error( 'ecm_flow_css_property', 'ویژگی یا مقدار CSS غیرمجاز است.' );
				}
				$declarations[] = $property . ':' . $value;
			}
			if ( $declarations ) {
				$rules[] = implode( ',', $scoped ) . '{' . implode( ';', $declarations ) . '}';
			}
		}
		return implode( "\n", $rules );
	}

	/** Resolve only standalone text-node control tokens, never HTML attribute content. */
	public static function render( array $step, array $components ): string {
		$default = self::defaults()[ $step['type'] ?? '' ] ?? '';
		$source = isset( $step['html'] ) && '' !== trim( (string) $step['html'] ) ? (string) $step['html'] : $default;
		$html = wp_kses_post( $source );
		$html = preg_replace_callback(
			'/(?:(?<=>)|\A)(\s*)\[(ecm_[a-z_]+)(?:\s+key="([a-z][a-z0-9_]{0,79})")?\](\s*)(?=<|\z)/u',
			static function ( array $m ) use ( $components ): string {
				$name = (string) $m[2];
				$key = (string) ( $m[3] ?? '' );
				if ( '' !== $key && ! in_array( $name, array( 'ecm_field', 'ecm_value' ), true ) ) { return $m[0]; }
				if ( '' === $key && in_array( $name, array( 'ecm_field', 'ecm_value' ), true ) ) { return $m[0]; }
				if ( ! array_key_exists( $name, $components ) ) { return $m[0]; }
				$component = $components[$name];
				if ( is_callable( $component ) ) {
					$component = $component( $key );
				} elseif ( '' !== $key ) { return $m[0]; }
				return is_string( $component ) ? $m[1] . $component . $m[4] : $m[0];
			},
			$html
		);

		$css = self::sanitize_css( (string) ( $step['css'] ?? '' ) );
		$style = '';
		if ( ! is_wp_error( $css ) && $css ) {
			$scoped = preg_replace_callback( '/([^{}]+)\{([^{}]*)\}/', static function ( array $m ): string {
				$selectors = array_map( static fn( string $sel ): string => '.ecm-flow-preview ' . trim( $sel ), explode( ',', trim( $m[1] ) ) );
				return implode( ',', $selectors ) . '{' . $m[2] . '}';
			}, $css );
			$style = '<style>' . $scoped . '</style>';
		}
		return $style . '<section class="ecm-flow-preview" dir="rtl">' . $html . '</section>';
	}
}
