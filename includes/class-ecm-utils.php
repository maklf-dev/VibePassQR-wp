<?php

defined( 'ABSPATH' ) || exit;

final class ECM_Utils {
	public static function defaults(): array {
		return array(
			'event_name'               => 'رویداد من',
			'form_intro'               => '<p>برای دریافت کارت، فرم زیر را تکمیل کنید.</p>',
			'currency_label'           => 'ریال',
			'amount'                   => '0',
			'rial_rate'                => '1',
			'currency_decimals'        => 0,
			'payment_mode'             => 'offline',
			'payment_button'           => 'ثبت‌نام و دریافت کارت',
			'price_label'              => 'مبلغ قابل پرداخت',
			'recovery_link_text'       => 'بازیابی کارت',
			'recovery_title'           => 'بازیابی کارت',
			'success_text'             => '<p>ثبت‌نام شما با موفقیت انجام شد. کارت خود را نگهداری کنید.</p>',
			'card_ready_title'         => 'کارت شما آماده است',
			'home_link_text'           => 'صفحه اصلی',
			'print_button'             => 'چاپ کارت',
			'recovery_intro'           => '<p>اطلاعاتی را که هنگام ثبت‌نام وارد کرده‌اید بنویسید.</p>',
			'recovery_button'          => 'بازیابی کارت',
			'recovery_not_found'       => 'کارت منطبق با اطلاعات واردشده پیدا نشد.',
			'card_print_mode'          => 'page',
			'card_print_html'          => '<p>این کارت را هنگام ورود همراه داشته باشید.</p>',
			'outside_message'          => 'در حال حاضر امکان ثبت حضور وجود ندارد.',
			'early_message'            => 'زمان مجاز ثبت حضور هنوز آغاز نشده است.',
			'late_message'             => 'زمان مجاز ثبت حضور پایان یافته است.',
			'invalid_qr_message'       => 'کد QR معتبر نیست.',
			'outside_title'            => 'خارج از بازه ثبت حضور',
			'early_title'              => 'هنوز زود است',
			'late_title'               => 'زمان ثبت حضور گذشته است',
			'invalid_qr_title'         => 'کد نامعتبر',
			'report_columns'           => array( 'public_id', 'event_title', 'created_at', 'payment_status', 'amount_rial', 'attendance_count', 'attendance_recorders', 'attendance_devices' ),
			'recovery_fields'          => array( 'mobile' ),
			'manager_roles'            => array( 'administrator', 'ecm_manager' ),
			'delete_data_on_uninstall' => 0,
			'fields'                   => array(
				array(
					'key'         => 'full_name',
					'label'       => 'نام و نام خانوادگی',
					'type'        => 'text',
					'required'    => 1,
					'placeholder' => 'نام کامل',
					'options'     => array(),
				),
				array(
					'key'         => 'mobile',
					'label'       => 'شماره موبایل',
					'type'        => 'tel',
					'required'    => 1,
					'placeholder' => '09xxxxxxxxx',
					'options'     => array(),
				),
			),
		);
	}

	public static function settings(): array {
		return wp_parse_args( (array) get_option( 'ecm_settings', array() ), array_merge(self::defaults(), ECM_Upgrade::defaults()) );
	}

	public static function registration_is_complete( ?object $registration ): bool {
		return $registration && in_array( (string) $registration->payment_status, array( 'paid', 'not_required', 'offline_confirmed' ), true );
	}

	public static function now_utc(): string {
		return current_time( 'mysql', true );
	}

	public static function local_to_utc( string $value ): ?string {
		if ( !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?$/', $value) ) {
			return null;
		}
		try {
			$local = new DateTimeImmutable( $value, wp_timezone() );
            $warnings=DateTimeImmutable::getLastErrors();
            if($warnings && ($warnings['warning_count']||$warnings['error_count'])) return null;
			return $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $exception ) {
			return null;
		}
	}

	public static function utc_to_local( ?string $value, string $format = 'Y/m/d H:i' ): string {
		if ( ! $value ) {
			return '—';
		}
		try {
			$date = new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
            if('Y-m-d\TH:i'===$format) return $date->setTimezone(wp_timezone())->format($format);
			return wp_date( $format, $date->getTimestamp(), wp_timezone() );
		} catch ( Exception $exception ) {
			return '—';
		}
	}

	public static function normalize_digits( string $value ): string {
		return strtr(
			$value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	/**
	 * Validate and normalize an Iranian mobile number without silently removing
	 * arbitrary characters. Registration input must never turn letters or other
	 * unexpected characters into a valid recipient after sanitization.
	 *
	 * Accepted canonical inputs are 09xxxxxxxxx, 9xxxxxxxxx, 989xxxxxxxxx,
	 * +989xxxxxxxxx and 00989xxxxxxxxx (Persian/Arabic digits are supported).
	 * The stored value is always the local 11-digit form.
	 */
	public static function normalize_mobile( string $value ): string {
		$value = trim( self::normalize_digits( $value ) );
		if ( ! preg_match( '/^(?:09\d{9}|9\d{9}|989\d{9}|\+989\d{9}|00989\d{9})$/D', $value ) ) {
			return '';
		}
		if ( str_starts_with( $value, '0098' ) ) {
			return '0' . substr( $value, 4 );
		}
		if ( str_starts_with( $value, '+98' ) ) {
			return '0' . substr( $value, 3 );
		}
		if ( str_starts_with( $value, '98' ) ) {
			return '0' . substr( $value, 2 );
		}
		return 10 === strlen( $value ) ? '0' . $value : $value;
	}

	public static function is_valid_mobile( string $value ): bool {
		return '' !== self::normalize_mobile( $value );
	}

	public static function decimal( string $value ): string {
		$value = str_replace( array( ',', '٬', ' ' ), '', self::normalize_digits( $value ) );
		return preg_match( '/^-?\d+(?:\.\d+)?$/', $value ) ? $value : '0';
	}

	public static function rial_amount( string $amount, string $rate ): string {
        // Exact decimal multiplication and half-up rounding; no binary floating-point money arithmetic.
        $a=self::decimal($amount);$b=self::decimal($rate);if(str_starts_with($a,'-')||str_starts_with($b,'-'))return '0';
        $scale=0;foreach(array($a,$b) as $v){$pos=strpos($v,'.');if(false!==$pos)$scale+=strlen($v)-$pos-1;}
        $a=str_replace('.','',$a);$b=str_replace('.','',$b);$digits=array_fill(0,strlen($a)+strlen($b),0);
        for($i=strlen($a)-1;$i>=0;$i--)for($j=strlen($b)-1;$j>=0;$j--){$n=$digits[$i+$j+1]+(int)$a[$i]*(int)$b[$j];$digits[$i+$j+1]=$n%10;$digits[$i+$j]+=intdiv($n,10);}
        $product=ltrim(implode('',$digits),'0')?:'0';
        if(!$scale)return $product;
        $product=str_pad($product,$scale+1,'0',STR_PAD_LEFT);$whole=substr($product,0,-$scale);$round=(int)substr($product,-$scale,1)>=5;
        if($round){$carry=1;for($i=strlen($whole)-1;$i>=0&&$carry;$i--){$n=(int)$whole[$i]+$carry;$whole[$i]=(string)($n%10);$carry=intdiv($n,10);}if($carry)$whole='1'.$whole;}
        return ltrim($whole,'0')?:'0';
	}

	public static function public_id(): string {
		return strtoupper( wp_generate_password( 10, false, false ) );
	}

	public static function grant_cookie_name( int $registration_id ): string {
		return 'ecm_card_' . $registration_id;
	}

	public static function set_card_grant( int $registration_id, string $secret ): void {
		setcookie(
			self::grant_cookie_name( $registration_id ),
			$secret,
			array(
				'expires'  => time() + MONTH_IN_SECONDS,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::grant_cookie_name( $registration_id ) ] = $secret;
	}

	public static function has_card_grant( object $registration ): bool {
		$name   = self::grant_cookie_name( (int) $registration->id );
		$secret = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
		return $secret && wp_check_password( $secret, (string) $registration->access_hash );
	}

	public static function participant_grant_cookie_name( int $participant_id ): string {
		return 'ecm_guest_card_' . $participant_id;
	}

	public static function set_participant_card_grant( int $participant_id, string $secret ): void {
		setcookie(
			self::participant_grant_cookie_name( $participant_id ),
			$secret,
			array(
				'expires'  => time() + MONTH_IN_SECONDS,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::participant_grant_cookie_name( $participant_id ) ] = $secret;
	}

	public static function has_participant_card_grant( object $participant ): bool {
		if ( 'primary' === (string) $participant->participant_type ) {
			$registration = ECM_DB::registration( (int) $participant->registration_id );
			return $registration ? self::has_card_grant( $registration ) : false;
		}
		$name   = self::participant_grant_cookie_name( (int) $participant->id );
		$secret = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
		return $secret && ! empty( $participant->access_hash ) && wp_check_password( $secret, (string) $participant->access_hash );
	}

	public static function qr_token( object $registration ): string {
		$payload = (string) $registration->public_id;
		$sig     = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		return rawurlencode( $payload . '.' . $sig );
	}

	public static function verify_qr_token( string $token ): ?object {
		$token = rawurldecode( $token );
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! preg_match( '/^[A-Z0-9]{10}$/', $parts[0] ) ) {
			return null;
		}
		$expected = hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return null;
		}
		return ECM_DB::registration_by_public_id( $parts[0] );
	}

	public static function participant_qr_token( object $participant ): string {
		$payload = 'P:' . (string) $participant->public_id;
		$sig     = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		return rawurlencode( $payload . '.' . $sig );
	}

	/**
	 * Normalize a scanner payload without changing the signed QR contract.
	 *
	 * QR readers may return the raw token, a full attendance URL, or a URL that
	 * WordPress/the browser encoded more than once. Guest tokens contain `P:`
	 * and are therefore the most likely to be double encoded. Decode only a
	 * small, bounded number of times and return a strict token shape; the HMAC
	 * verification below remains the final source of truth.
	 */
	public static function normalize_qr_token_input( string $value ): string {
		$value = trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $value || strlen( $value ) > 4096 ) {
			return '';
		}

		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			$query = (string) wp_parse_url( $value, PHP_URL_QUERY );
			if ( '' !== $query ) {
				parse_str( html_entity_decode( $query, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $args );
				if ( isset( $args['token'] ) && is_string( $args['token'] ) ) {
					$value = $args['token'];
				}
			} elseif ( preg_match( '/(?:^|[?&])token=([^&#]+)/i', $value, $match ) ) {
				$value = $match[1];
			}

			$decoded = rawurldecode( $value );
			if ( $decoded === $value ) {
				break;
			}
			$value = $decoded;
		}

		$value = trim( wp_strip_all_tags( $value ) );
		if ( preg_match( '/^[A-Za-z0-9_-]{22}$/', $value ) ) {
			return $value;
		}
		$path = (string) wp_parse_url( $value, PHP_URL_PATH );
		if ( preg_match( '#(?:^|/)a/([A-Za-z0-9_-]{22})(?:/|$)#', $path, $short_match ) ) {
			return $short_match[1];
		}
		if ( ! preg_match( '/(?:^|[^A-Z0-9])((?:P:)?[A-Z0-9]{10}\.[A-F0-9]{64})(?:$|[^A-Z0-9])/i', $value, $match ) ) {
			return '';
		}

		$parts   = explode( '.', $match[1], 2 );
		$payload = strtoupper( $parts[0] );
		return $payload . '.' . strtolower( $parts[1] );
	}

	public static function verify_participant_qr_token( string $token ): ?object {
		$token = rawurldecode( $token );
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! preg_match( '/^P:([A-Z0-9]{10})$/', $parts[0], $matches ) ) {
			return null;
		}
		$expected = hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return null;
		}
		return ECM_DB::participant_by_public_id( $matches[1] );
	}

	/**
	 * Resolve both the unchanged legacy registration token and participant tokens.
	 *
	 * @return array{registration:?object,participant:?object}
	 */
	public static function resolve_qr_token( string $token ): array {
		if ( class_exists( 'ECM_Tickets' ) ) {
			$short_participant = ECM_Tickets::resolve_attendance_input( $token );
			if ( $short_participant ) {
				return array( 'registration' => ECM_DB::registration( (int) $short_participant->registration_id ), 'participant' => $short_participant );
			}
		}
		$token = self::normalize_qr_token_input( $token );
		if ( '' === $token ) {
			return array( 'registration' => null, 'participant' => null );
		}
		$registration = self::verify_qr_token( $token );
		if ( $registration ) {
			return array( 'registration' => $registration, 'participant' => ECM_DB::primary_participant( (int) $registration->id ) );
		}
		$participant = self::verify_participant_qr_token( $token );
		if ( ! $participant ) {
			return array( 'registration' => null, 'participant' => null );
		}
		return array( 'registration' => ECM_DB::registration( (int) $participant->registration_id ), 'participant' => $participant );
	}

	public static function request_device(): array {
		$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ua    = function_exists( 'mb_substr' ) ? mb_substr( $ua, 0, 500 ) : substr( $ua, 0, 500 );
		return self::device_from_user_agent( $ua );
	}

	public static function device_from_user_agent( string $ua ): array {
		$ua = function_exists( 'mb_substr' ) ? mb_substr( sanitize_text_field( $ua ), 0, 500 ) : substr( sanitize_text_field( $ua ), 0, 500 );
		if ( '' === $ua ) {
			return array( 'type' => '', 'label' => '', 'user_agent' => '' );
		}
		$is_ipad_desktop = str_contains( $ua, 'Macintosh' ) && str_contains( $ua, 'Mobile/' );
		$type  = preg_match( '/iPad|Tablet|PlayBook|Silk/i', $ua ) || $is_ipad_desktop ? 'tablet' : ( preg_match( '/Mobile|iPhone|iPod/i', $ua ) || ( preg_match( '/Android/i', $ua ) && ! preg_match( '/Tablet/i', $ua ) ) ? 'mobile' : 'desktop' );
		$os    = preg_match( '/iPhone|iPad|iPod/i', $ua ) || $is_ipad_desktop ? 'iOS/iPadOS' : ( preg_match( '/Android/i', $ua ) ? 'Android' : ( preg_match( '/Windows/i', $ua ) ? 'Windows' : ( preg_match( '/Macintosh|Mac OS X/i', $ua ) ? 'macOS' : ( preg_match( '/Linux/i', $ua ) ? 'Linux' : 'سیستم نامشخص' ) ) ) );
		$browser = preg_match( '/EdgA|EdgiOS|Edg\//i', $ua ) ? 'Edge' : ( preg_match( '/SamsungBrowser/i', $ua ) ? 'Samsung Internet' : ( preg_match( '/OPR\/|Opera/i', $ua ) ? 'Opera' : ( preg_match( '/CriOS|Chrome/i', $ua ) ? 'Chrome' : ( preg_match( '/FxiOS|Firefox/i', $ua ) ? 'Firefox' : ( preg_match( '/Version\/[\d.]+.*Safari/i', $ua ) ? 'Safari' : 'مرورگر نامشخص' ) ) ) ) );
		$labels = array( 'mobile' => 'موبایل', 'tablet' => 'تبلت', 'desktop' => 'رایانه' );
		return array( 'type' => $type, 'label' => $labels[ $type ] . ' · ' . $os . ' · ' . $browser, 'user_agent' => $ua );
	}

	public static function page_url( string $key, array $args = array() ): string {
		$pages = (array) get_option( 'ecm_pages', array() );
		$url   = ! empty( $pages[ $key ] ) ? get_permalink( (int) $pages[ $key ] ) : home_url( '/' );
		return add_query_arg( $args, $url );
	}

	public static function field_value( array $data, string $key ): string {
		$value = $data[ $key ] ?? '';
		if ( ! is_array( $value ) ) {
			return (string) $value;
		}
		if ( $value && is_array( reset( $value ) ) ) {
			$names = array();
			foreach ( $value as $row ) {
				if ( is_array( $row ) && ! empty( $row['name'] ) ) {
					$names[] = sanitize_text_field( $row['name'] );
				}
			}
			return implode( '، ', $names );
		}
		return implode( '، ', array_map( 'sanitize_text_field', $value ) );
	}

	public static function log( int $registration_id, string $type, array $meta = array() ): void {
		global $wpdb;
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$registration = $registration_id ? ECM_DB::registration( $registration_id ) : null;
		$event_id     = $registration ? absint( $registration->event_id ?? 0 ) : absint( $meta['event_id'] ?? 0 );
		$participant_id = ! empty( $meta['participant_id'] ) ? absint( $meta['participant_id'] ) : ( $registration ? absint( $registration->primary_participant_id ?? 0 ) : 0 );
		$correlation_id = class_exists( 'ECM_Activity' ) ? ECM_Activity::request_id() : '';
		$wpdb->insert(
			ECM_DB::table( 'events' ),
			array(
				'registration_id' => $registration_id,
				'domain_event_id' => $event_id,
				'participant_id'  => $participant_id,
				'actor_user_id'   => get_current_user_id(),
				'correlation_id'   => $correlation_id ?: null,
				'event_type'      => sanitize_key( $type ),
				'meta'            => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ),
				'ip_hash'         => $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ) : null,
				'user_agent'      => function_exists( 'mb_substr' ) ? mb_substr( $ua, 0, 500 ) : substr( $ua, 0, 500 ),
				'created_at'      => self::now_utc(),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		do_action( 'ecm_event_logged', $registration_id, $type, $meta );
	}
}
