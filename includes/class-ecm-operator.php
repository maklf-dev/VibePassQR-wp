<?php

defined( 'ABSPATH' ) || exit;

/**
 * Fast, authenticated operator workflow for QR lookup and selective check-in.
 */
final class ECM_Operator {
	public static function hooks(): void {
		add_action( 'wp_ajax_ecm_operator_lookup', array( __CLASS__, 'lookup' ) );
		add_action( 'wp_ajax_ecm_operator_checkin', array( __CLASS__, 'checkin' ) );
	}

	public static function portal_markup(): string {
		wp_enqueue_script( 'ecm-operator' );
		$user   = wp_get_current_user();
		$events = array_values(
			array_filter(
				ECM_Events::all( 'published' ),
				static fn( object $event ): bool => ! empty( $event->enabled ) && ECM_Events::can_record_attendance( (int) $event->id, (int) $user->ID )
			)
		);
		$recent = array();
		$total  = 0;
		if ( current_user_can( 'view_own_ecm_attendance' ) ) {
			global $wpdb;
			$attendance   = ECM_DB::table( 'attendance' );
			$sessions     = ECM_DB::table( 'sessions' );
			$event_table  = ECM_DB::table( 'event_definitions' );
			$participants = ECM_DB::table( 'participants' );
			$people       = ECM_DB::table( 'people' );
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attendance} WHERE recorded_by_user_id=%d", (int) $user->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$recent = $wpdb->get_results( $wpdb->prepare( "SELECT a.created_at,s.title AS session_title,e.title AS event_title,p.participant_type,COALESCE(NULLIF(p.display_name,''),pe.full_name) full_name FROM {$attendance} a LEFT JOIN {$sessions} s ON s.id=a.session_id LEFT JOIN {$event_table} e ON e.id=a.event_id LEFT JOIN {$participants} p ON p.id=a.participant_id LEFT JOIN {$people} pe ON pe.id=p.person_id WHERE a.recorded_by_user_id=%d ORDER BY a.id DESC LIMIT 20", (int) $user->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		ob_start();
		?>
		<div class="ecm-operator-home" data-ecm-operator data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ecm_operator' ) ); ?>">
			<div class="ecm-hero"><div><span class="ecm-kicker">FAST CHECK-IN</span><h1>پنل مدیر ثبت حضور</h1><p><?php echo esc_html( $user->display_name ); ?> · کد U<?php echo esc_html( $user->ID ); ?></p></div><div class="ecm-operator-head-actions"><?php if ( current_user_can( 'view_ecm_reports' ) ) : ?><a class="ecm-button ecm-button--ghost" href="<?php echo esc_url( ECM_Portal::url( array( 'ecm_portal_view' => 'reports' ) ) ); ?>">گزارش‌ها</a><?php endif; ?><a class="ecm-button ecm-button--ghost" href="<?php echo esc_url( ECM_Portal::url() ); ?>">خانه پنل</a></div></div>
			<div class="ecm-content">
				<?php if ( ! $events ) : ?><div class="ecm-state ecm-state--warning"><h2>رویداد فعالی برای شما تعریف نشده است</h2><p>مدیر کل باید نقش یا حساب شما را در دسترسی رویداد انتخاب کند.</p></div><?php else : ?>
				<section class="ecm-scanner-panel">
					<div class="ecm-scanner-toolbar"><label>رویداد<select data-operator-event><?php foreach ( $events as $event ) : $window = ECM_DB::attendance_window( (int) $event->id ); ?><option value="<?php echo esc_attr( $event->id ); ?>"><?php echo esc_html( $event->title . ( $window['session'] ? ' — ' . $window['session']->title : ' — بدون نوبت فعال' ) ); ?></option><?php endforeach; ?></select></label><span class="ecm-scanner-clock" data-operator-clock></span></div>
					<div class="ecm-scanner-camera" data-camera-shell>
						<video data-operator-video playsinline muted hidden></video>
						<canvas data-camera-freeze hidden aria-hidden="true"></canvas>
						<div class="ecm-scanner-placeholder" data-camera-placeholder><span>⌁</span><strong>اسکن سریع QR</strong><p>دوربین فقط داخل مرورگر استفاده می‌شود و تصویری روی سرور ذخیره نمی‌شود.</p></div>
						<div class="ecm-scanner-frame" aria-hidden="true"></div>
						<button type="button" class="ecm-camera-rescan" data-camera-rescan hidden>اسکن مجدد</button>
					</div>
					<div class="ecm-scanner-actions"><button type="button" class="ecm-button ecm-button--primary" data-camera-toggle>بازکردن دوربین</button><label class="ecm-button ecm-button--ghost ecm-camera-file">خواندن از تصویر<input type="file" accept="image/*" capture="environment" data-camera-file></label></div>
					<div class="ecm-scanner-manual"><label for="ecm-operator-code">لینک، کد QR یا کد بلیت</label><div><input id="ecm-operator-code" type="text" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" data-operator-code placeholder="مثال: GJGPJB3A1K یا لینک کارت"><button type="button" class="ecm-button ecm-button--ghost" data-operator-lookup>بررسی</button></div></div>
					<p class="ecm-scanner-status" data-operator-status role="status">آماده اسکن</p>
				</section>
				<section class="ecm-operator-result" data-operator-result hidden aria-live="polite"></section>
				<?php endif; ?>

				<?php if ( current_user_can( 'view_own_ecm_attendance' ) ) : ?><section class="ecm-operator-history"><div><h2>آخرین ثبت‌های من</h2><span><?php echo esc_html( number_format_i18n( $total ) ); ?> ثبت در مجموع</span></div><?php if ( ! $recent ) : ?><p>هنوز حضوری با حساب شما ثبت نشده است.</p><?php endif; ?><div class="ecm-operator-history__list"><?php foreach ( $recent as $entry ) : ?><article><div><strong><?php echo esc_html( $entry->full_name ?: 'شرکت‌کننده' ); ?></strong><span><?php echo esc_html( 'guest' === $entry->participant_type ? 'مهمان' : 'معرف' ); ?></span></div><p><?php echo esc_html( ( $entry->event_title ?: 'رویداد' ) . ' · ' . ( $entry->session_title ?: 'نوبت' ) ); ?></p><time><?php echo esc_html( ECM_Utils::utc_to_local( $entry->created_at, 'Y/m/d H:i:s' ) ); ?></time></article><?php endforeach; ?></div></section><?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function lookup(): void {
		check_ajax_referer( 'ecm_operator', 'nonce' );
		$context = self::context_from_request();
		if ( is_wp_error( $context ) ) {
			ECM_Activity::record( 'operation', array( 'operation' => 'operator_lookup_failed', 'event_id' => absint( $_POST['event_id'] ?? 0 ), 'is_error' => true, 'error_code' => $context->get_error_code() ) );
			self::json_error( $context );
		}
		ECM_Activity::record( 'operation', array( 'operation' => 'operator_lookup_success', 'event_id' => (int) $context['event']->id, 'session_id' => (int) $context['session']->id ) );
		wp_send_json_success( self::payload( $context ) );
	}

	public static function checkin(): void {
		check_ajax_referer( 'ecm_operator', 'nonce' );
		$context = self::context_from_request();
		if ( is_wp_error( $context ) ) {
			ECM_Activity::record( 'operation', array( 'operation' => 'operator_checkin_rejected', 'event_id' => absint( $_POST['event_id'] ?? 0 ), 'is_error' => true, 'error_code' => $context->get_error_code() ) );
			self::json_error( $context );
		}
		$selected = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $_POST['participant_ids'] ?? array() ) ) ) ) );
		$participant_map = array();
		foreach ( $context['participants'] as $context_participant ) {
			$participant_map[ (int) $context_participant->id ] = $context_participant;
		}
		$allowed  = array_keys( $participant_map );
		$selected = array_values( array_intersect( $selected, $allowed ) );
		if ( ! $selected ) {
			ECM_Activity::record( 'operation', array( 'operation' => 'operator_checkin_rejected', 'event_id' => (int) $context['event']->id, 'session_id' => (int) $context['session']->id, 'is_error' => true, 'error_code' => 'empty_selection', 'route' => 'ajax_ecm_operator_checkin' ) );
			wp_send_json_error( array( 'code' => 'empty_selection', 'message' => 'حداقل یک فرد ثبت‌نشده را انتخاب کنید.' ), 400 );
		}

		global $wpdb;
		$table    = ECM_DB::table( 'attendance' );
		$recorder = ECM_Events::recorder_snapshot( (int) $context['event']->id );
		$device   = ECM_Utils::request_device();
		$request  = sanitize_text_field( wp_unslash( $_POST['request_id'] ?? '' ) );
		if ( ! preg_match( '/^[a-zA-Z0-9-]{12,80}$/', $request ) ) {
			$request = wp_generate_uuid4();
		}
		$attendance_counts = array();
		$id_list = implode( ',', array_map( 'absint', $selected ) );
		$count_rows = $wpdb->get_results( $wpdb->prepare( "SELECT participant_id,COUNT(*) AS attendance_count FROM {$table} WHERE session_id=%d AND participant_id IN ({$id_list}) GROUP BY participant_id", (int) $context['session']->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $count_rows as $count_row ) {
			$attendance_counts[ (int) $count_row->participant_id ] = (int) $count_row->attendance_count;
		}
		$results = array();
		foreach ( $selected as $participant_id ) {
			$participant = $participant_map[ $participant_id ] ?? null;
			$count       = $attendance_counts[ $participant_id ] ?? 0;
			if ( ! $participant || $count >= (int) $context['session']->max_entries ) {
				$results[] = array( 'participant_id' => $participant_id, 'status' => 'already' );
				continue;
			}
			$request_hash = hash( 'sha256', $participant_id . '|' . $context['session']->id . '|' . $request );
			$inserted = $wpdb->insert(
				$table,
				array(
					'registration_id'     => (int) $participant->registration_id,
					'event_id'            => (int) $context['event']->id,
					'participant_id'      => $participant_id,
					'session_id'          => (int) $context['session']->id,
					'entry_no'            => $count + 1,
					'status'              => 'success',
					'request_hash'        => $request_hash,
					'recorded_by_user_id' => (int) $recorder['user_id'],
					'recorder_name'       => (string) $recorder['name'],
					'recorder_code'       => (string) $recorder['code'],
					'method'              => 'operator_scanner',
					'device_type'         => (string) $device['type'],
					'device_label'        => (string) $device['label'],
					'user_agent'          => (string) $device['user_agent'],
					'created_at'          => ECM_Utils::now_utc(),
				),
				array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( ! $inserted ) {
				$status    = ECM_DB::participant_attendance_count( $participant_id, (int) $context['session']->id ) > $count ? 'already' : 'error';
				$results[] = array( 'participant_id' => $participant_id, 'status' => $status );
				if ( 'error' === $status ) {
					ECM_Activity::record( 'operation', array( 'operation' => 'operator_checkin_store_failed', 'event_id' => (int) $context['event']->id, 'session_id' => (int) $context['session']->id, 'registration_id' => (int) $participant->registration_id, 'participant_id' => $participant_id, 'is_error' => true, 'error_code' => 'attendance_database', 'route' => 'ajax_ecm_operator_checkin' ) );
				}
				continue;
			}
			$attendance_id = (int) $wpdb->insert_id;
			ECM_Utils::log(
				(int) $participant->registration_id,
				'attendance_success',
				array( 'session_id' => (int) $context['session']->id, 'attendance_id' => $attendance_id, 'event_id' => (int) $context['event']->id, 'participant_id' => $participant_id, 'participant_type' => (string) $participant->participant_type, 'recorded_by_user_id' => (int) $recorder['user_id'], 'method' => 'operator_scanner', 'device' => (string) $device['label'] )
			);
			$results[] = array( 'participant_id' => $participant_id, 'status' => 'stored', 'attendance_id' => $attendance_id );
		}

		$stored_count = count( array_filter( $results, static fn( array $result ): bool => 'stored' === ( $result['status'] ?? '' ) ) );
		$already_count = count( array_filter( $results, static fn( array $result ): bool => 'already' === ( $result['status'] ?? '' ) ) );
		ECM_Activity::record( 'operation', array( 'operation' => 'operator_checkin_completed', 'event_id' => (int) $context['event']->id, 'session_id' => (int) $context['session']->id, 'stored_count' => $stored_count, 'already_count' => $already_count, 'route' => 'ajax_ecm_operator_checkin' ) );
		wp_send_json_success( array( 'message' => 'نتیجه ثبت حضور به‌روز شد.', 'results' => $results, 'view' => self::payload( $context ) ) );
	}

	/** @return array<string,mixed>|WP_Error */
	private static function context_from_request() {
		if ( ! is_user_logged_in() || ! current_user_can( 'record_ecm_attendance' ) ) {
			return new WP_Error( 'authentication_required', 'نشست ورود شما معتبر نیست. دوباره وارد شوید.', array( 'status' => 401 ) );
		}
		$event_id = absint( $_POST['event_id'] ?? 0 );
		$event    = ECM_Events::event( $event_id );
		if ( ! ECM_Events::is_publicly_active( $event ) || ! ECM_Events::can_record_attendance( $event_id ) ) {
			return new WP_Error( 'forbidden_event', 'برای ثبت حضور این رویداد دسترسی ندارید.', array( 'status' => 403 ) );
		}
		$input    = sanitize_text_field( (string) wp_unslash( $_POST['token'] ?? '' ) );
		$token    = ECM_Utils::normalize_qr_token_input( $input );
		$resolved = '' !== trim( $input ) ? ECM_Utils::resolve_qr_token( $input ) : array( 'registration' => null, 'participant' => null );
		$registration = $resolved['registration'];
		$participant  = $resolved['participant'];
		if ( ! ECM_Utils::registration_is_complete( $registration ) || ! $participant || (int) $participant->event_id !== $event_id ) {
			return new WP_Error( 'invalid_qr', 'این QR معتبر نیست یا متعلق به رویداد انتخاب‌شده نیست.', array( 'status' => 400 ) );
		}
		$window = ECM_DB::attendance_window( $event_id );
		if ( 'active' !== $window['state'] || ! $window['session'] ) {
			$config = ECM_Events::config( $event );
			$state  = in_array( $window['state'], array( 'early', 'late' ), true ) ? $window['state'] : 'outside';
			return new WP_Error( 'outside_window', (string) $config[ $state . '_message' ], array( 'status' => 409 ) );
		}
		$participant = ECM_DB::participant_identity( (int) $participant->id );
		// A guest QR is private to that guest. A primary QR is the group entry
		// point and must always expose the primary participant plus active guests,
		// independent of older event-level group settings.
		$participants = 'primary' === (string) $participant->participant_type
			? ECM_DB::participants_for_registration( (int) $registration->id )
			: array( $participant );
		return array( 'token' => $token ?: strtoupper( trim( $input ) ), 'event' => $event, 'registration' => $registration, 'scanned' => $participant, 'session' => $window['session'], 'participants' => $participants );
	}

	/** @param array<string,mixed> $context */
	private static function payload( array $context ): array {
		global $wpdb;
		$attendance = ECM_DB::table( 'attendance' );
		$ids        = array_map( static fn( object $participant ): int => (int) $participant->id, $context['participants'] );
		$entries    = array();
		if ( $ids ) {
			$list = implode( ',', array_map( 'absint', $ids ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$attendance} WHERE session_id=%d AND participant_id IN ({$list}) ORDER BY id DESC", (int) $context['session']->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				if ( ! isset( $entries[ $row->participant_id ] ) ) {
					$entries[ $row->participant_id ] = $row;
				}
			}
		}
		$host = null;
		foreach ( $context['participants'] as $candidate ) {
			if ( 'primary' === (string) $candidate->participant_type ) {
				$host = $candidate;
				break;
			}
		}
		if ( ! $host && 'guest' === (string) $context['scanned']->participant_type && ! empty( $context['scanned']->invited_by_participant_id ) ) {
			$host = ECM_DB::participant_identity( (int) $context['scanned']->invited_by_participant_id );
		}
		$host_attended = $host ? ECM_DB::participant_attendance_count( (int) $host->id, (int) $context['session']->id ) > 0 : false;
		$people = array();
		foreach ( $context['participants'] as $participant ) {
			$entry = $entries[ $participant->id ] ?? null;
			$recorder_name = $entry ? trim( (string) $entry->recorder_name ) : '';
			if ( $entry && ! $recorder_name && $entry->recorded_by_user_id ) {
				$user = get_userdata( (int) $entry->recorded_by_user_id );
				$recorder_name = $user ? (string) $user->display_name : '';
			}
			$people[] = array(
				'id'              => (int) $participant->id,
				'name'            => (string) ( $participant->full_name ?: 'بدون نام' ),
				'phone'           => (string) $participant->normalized_phone,
				'type'            => (string) $participant->participant_type,
				'guest_order'     => (int) $participant->guest_order,
				'host_name'       => 'guest' === (string) $participant->participant_type && $host ? (string) $host->full_name : '',
				'host_attended'   => 'guest' === (string) $participant->participant_type && $host ? $host_attended : false,
				'attended'        => (bool) $entry,
				'attendance_time' => $entry ? ECM_Utils::utc_to_local( $entry->created_at, 'Y/m/d H:i:s' ) : '',
				'recorder_name'   => $recorder_name ?: ( $entry && 'self_service' === $entry->method ? 'خود کاربر' : '' ),
				'recorder_code'   => $entry ? (string) $entry->recorder_code : '',
			);
		}
		return array(
			'token'        => (string) $context['token'],
			'event_id'     => (int) $context['event']->id,
			'event_title'  => (string) $context['event']->title,
			'session_id'   => (int) $context['session']->id,
			'session_title'=> (string) $context['session']->title,
			'session_time' => ECM_Utils::utc_to_local( $context['session']->start_at_utc, 'Y/m/d H:i' ) . ' تا ' . ECM_Utils::utc_to_local( $context['session']->end_at_utc, 'H:i' ),
			'scanned_type' => (string) $context['scanned']->participant_type,
			'group_mode'   => 'primary' === (string) $context['scanned']->participant_type && count( $context['participants'] ) > 1,
			'participants' => $people,
		);
	}

	private static function json_error( WP_Error $error ): void {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 400;
		wp_send_json_error( array( 'code' => $error->get_error_code(), 'message' => $error->get_error_message(), 'login_url' => 401 === $status ? ECM_Portal::url() : '' ), $status );
	}
}
