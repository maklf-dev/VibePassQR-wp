<?php

defined( 'ABSPATH' ) || exit;

final class ECM_Flow_Edit {
	public static function guests( int $id ): array {
		$guests = array();
		foreach ( ECM_DB::participants_for_registration( $id ) as $participant ) {
			if ( 'guest' === $participant->participant_type ) {
				$guests[] = array( 'id' => (int) $participant->id, 'name' => $participant->full_name, 'phone' => $participant->normalized_phone );
			}
		}
		return $guests;
	}

	public static function reconcile_guests( int $id, array $requested ) {
		global $wpdb;
		$existing = self::guests( $id );
		$participants = ECM_DB::table( 'participants' );
		$new = array();
		foreach ( $requested as $index => $guest ) {
			$match = null;
			foreach ( $existing as $i => $old ) {
				if ( $old['phone'] === $guest['phone'] ) { $match = $i; break; }
			}
			if ( null === $match ) {
				$new[] = $guest;
				continue;
			}
			ECM_Flow::write( $wpdb->update( $participants, array( 'display_name' => $guest['name'], 'guest_order' => $index + 1 ), array( 'id' => $existing[ $match ]['id'] ) ) );
			unset( $existing[ $match ] );
		}
		foreach ( $existing as $removed ) {
			ECM_Flow::write( $wpdb->update( $participants, array( 'status' => 'inactive', 'access_hash' => '' ), array( 'id' => $removed['id'] ) ) );
			ECM_Flow::write( $wpdb->update( ECM_DB::table( 'ticket_tokens' ), array( 'status' => 'revoked' ), array( 'participant_id' => $removed['id'] ) ) );
		}
		return ECM_Participants::create_guests( $id, $new, false );
	}

	public static function save( object $event, object $registration, array $settings, array $raw, array $raw_guests ) {
		$id = (int) $registration->id;
		$keys = (array) ( $settings['edit_keys'] ?? array() );
		if ( empty( $settings['edit_enabled'] ) || ! $keys || ! empty( $registration->is_test ) || ! ECM_Events::allows( $event, 'recovery' ) ) {
			return new WP_Error( 'edit', 'ویرایش ثبت‌نام در دسترس نیست.' );
		}
		if ( in_array( $registration->payment_status, array( 'paid', 'offline_confirmed' ), true ) && empty( $settings['edit_after_paid'] ) ) {
			return new WP_Error( 'edit_paid', 'ویرایش ثبت‌نام پرداخت‌شده برای این رویداد بسته است.' );
		}
		$config = ECM_Events::config( $event );
		$manage_guests = in_array( 'guests', $keys, true );
		$primary = ECM_DB::primary_participant( $id );
		$identity = $primary ? ECM_DB::participant_identity( (int) $primary->id ) : null;
		if ( ! $identity || ! $identity->normalized_phone ) {
			return new WP_Error( 'identity', 'هویت معرف یافت نشد.' );
		}
		$result = ECM_Flow::atomic( static function () use ( $id, $identity, $config, $raw, $raw_guests, $manage_guests, $event, $registration, $keys ) {
			global $wpdb;
			$table = ECM_DB::table( 'registrations' );
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d FOR UPDATE", $id ) );
			if ( ! $current || $current->form_data !== $registration->form_data || $current->payment_status !== $registration->payment_status ) {
				return new WP_Error( 'stale', 'پرونده تغییر کرده است. صفحه را تازه کنید.' );
			}
			$before = ECM_DB::decode_data( $current );
			$input = ECM_Flow_Public::validate( $config, $identity->normalized_phone, $raw, $manage_guests ? $raw_guests : array(), min( 20, (int) $event->guest_limit ), $before, $keys );
			if ( is_wp_error( $input ) ) {
				return $input;
			}
			$participants = ECM_DB::table( 'participants' );
			$notifications = array();
			if ( $manage_guests ) {
				$old = array_column( self::guests( $id ), null, 'id' );
				$new = array();
				foreach ( $input['guests'] as $guest ) {
					$guest_id = (int) $guest['id'];
					if ( $guest_id && ! isset( $old[ $guest_id ] ) ) {
						return new WP_Error( 'guest_id', 'فهرست مهمان‌ها تغییر کرده است. صفحه را تازه کنید.' );
					}
					if ( $guest_id && $old[ $guest_id ]['phone'] === $guest['phone'] ) {
						if ( $old[ $guest_id ]['name'] !== $guest['name'] ) {
							ECM_Flow::write( $wpdb->update( $participants, array( 'display_name' => $guest['name'], 'updated_at' => ECM_Utils::now_utc() ), array( 'id' => $guest_id ) ) );
							$notifications[] = array( 'guest_changed', $guest );
						}
						unset( $old[ $guest_id ] );
					} else {
						$new[] = $guest;
						$notifications[] = array( 'guest_added', $guest );
					}
				}
				foreach ( $old as $old_id => $removed ) {
					ECM_Flow::write( $wpdb->update( $participants, array( 'status' => 'inactive', 'access_hash' => '', 'updated_at' => ECM_Utils::now_utc() ), array( 'id' => $old_id ) ) );
					ECM_Flow::write( $wpdb->update( ECM_DB::table( 'ticket_tokens' ), array( 'status' => 'revoked' ), array( 'participant_id' => $old_id ) ) );
					$notifications[] = array( 'guest_removed', $removed );
				}
				$created = ECM_Participants::create_guests( $id, $new, false );
				if ( is_wp_error( $created ) ) {
					return $created;
				}
				foreach ( (array) $config['fields'] as $field ) {
					if ( 'guest' === ( $field['type'] ?? '' ) ) {
						$input['form'][ $field['key'] ] = array_map( static fn( $g ) => array( 'name' => $g['name'], 'phone' => $g['phone'] ), $input['guests'] );
					}
				}
			}
			$name = ECM_Utils::field_value( $input['form'], $config['name_field'] );
			ECM_Flow::write( $wpdb->update( $participants, array( 'display_name' => $name ), array( 'id' => (int) $identity->id ) ) );
			ECM_Flow::write( $wpdb->update( $table, array( 'form_data' => wp_json_encode( $input['form'], JSON_UNESCAPED_UNICODE ), 'updated_at' => ECM_Utils::now_utc() ), array( 'id' => $id ) ) );
			ECM_Flow::write( $wpdb->delete( ECM_DB::table( 'registration_meta' ), array( 'registration_id' => $id ) ) );
			( new ECM_Public() )->index_fields( $id, $input['form'], $config['fields'] );
			if ( $before !== $input['form'] || $notifications ) {
				$changed = array_keys( array_filter( $input['form'], static fn( $value, $key ) => ( $before[ $key ] ?? null ) !== $value, ARRAY_FILTER_USE_BOTH ) );
				ECM_Utils::log( $id, 'registration_self_edited', array( 'fields' => $changed, 'guest_actions' => array_column( $notifications, 0 ) ) );
				$notifications[] = array( 'edit', array() );
			}
			return $notifications;
		} );
		if ( ! is_wp_error( $result ) ) {
			$change_key = wp_generate_uuid4();
			foreach ( $result as $index => $notice ) {
				list( $kind, $target ) = $notice;
				self::edit_sms( $settings, $id, $kind, 'primary', $change_key . '-' . $index );
				if ( $target ) {
					self::edit_sms( $settings, $id, $kind, 'guest', $change_key . '-' . $index, $target );
				} elseif ( 'edit' === $kind ) {
					foreach ( self::guests( $id ) as $guest ) {
						self::edit_sms( $settings, $id, $kind, 'guest', $change_key . '-guest-' . $guest['id'], $guest );
					}
				}
			}
		}
		return $result;
	}

	private static function edit_sms( array $settings, int $id, string $kind, string $audience, string $key, array $target = array() ): void {
		$rule = $settings['sms'][ $kind ][ $audience ] ?? array();
		if ( ! empty( $rule['enabled'] ) ) {
			$meta = array( 'workflow_rule' => $rule, 'participant_type' => $audience );
			if ( $target ) {
				$meta += array( 'recipient' => $target['phone'], 'participant_name' => $target['name'] );
			}
			ECM_SMS::queue( 'flow_' . $kind, $id, 'edit-' . $key . '-' . $audience, $meta );
		}
	}

	public static function manual_markup( object $event ): void {
		$config = ECM_Events::config( $event );
		echo '<h2>ثبت‌نام دستی</h2><p>ثبت‌نام واقعی یا تستی بدون فرم عمومی. پرونده مرحله‌ای از همین پنل ایجاد می‌شود و برای صدور کارت به بررسی رسید نیاز دارد.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ecm_flow_manual"><input type="hidden" name="event_id" value="' . (int) $event->id . '">';
		wp_nonce_field( 'ecm_flow_manual_' . $event->id );
		echo '<p><label>موبایل معرف <input name="phone" type="tel" required></label> <label>نوع ثبت <select name="kind"><option value="test">تستی</option><option value="live">اصلی</option></select></label></p>';
		$renderer = new ECM_Public();
		foreach ( (array) $config['fields'] as $field ) {
			if ( ! in_array( $field['key'], array( 'mobile', $config['sms_field'] ), true ) && 'guest' !== $field['type'] ) {
				echo $renderer->render_field( $field, '', $event );
			}
		}
		if ( (int) $event->guest_limit > 0 ) {
			echo ECM_Flow_Public::guest_fields( array(), min( 20, (int) $event->guest_limit ) );
		}
		echo '<p><label><input type="checkbox" name="sms" value="1"> ارسال پیامک اولیه مطابق تنظیمات رویداد (با انتخاب تستی نیز برای همین ثبت ارسال می‌شود)</label></p><button class="button button-primary">ساخت ثبت‌نام دستی</button></form>';
	}

	public static function manual(): void {
		$id = absint( ECM_Flow_Public::param( 'event_id' ) );
		check_admin_referer( 'ecm_flow_manual_' . $id );
		$event = ECM_Events::event( $id );
		if ( ! current_user_can( 'manage_options' ) || ! ECM_Flow::ready() || ! $event ) {
			wp_die( 'دسترسی ندارید.', '', array( 'response' => 403 ) );
		}
		$phone = ECM_Utils::normalize_mobile( ECM_Flow_Public::param( 'phone' ) );
		if ( ! $phone ) {
			wp_die( 'موبایل نامعتبر است.', '', array( 'response' => 400, 'back_link' => true ) );
		}
		$raw = isset( $_POST['ecm_field'] ) && is_array( $_POST['ecm_field'] ) ? wp_unslash( $_POST['ecm_field'] ) : array();
		$guests = isset( $_POST['guests'] ) && is_array( $_POST['guests'] ) ? wp_unslash( $_POST['guests'] ) : array();
		$config = ECM_Events::config( $event );
		$input = ECM_Flow_Public::validate( $config, $phone, $raw, $guests, min( 20, (int) $event->guest_limit ) );
		$test = 'live' !== ECM_Flow_Public::param( 'kind' );
		$sms = '1' === ECM_Flow_Public::param( 'sms' );
		if ( is_wp_error( $input ) ) {
			wp_die( esc_html( $input->get_error_message() ), '', array( 'response' => 400, 'back_link' => true ) );
		}
		if ( ECM_Flow::enabled( $event ) ) {
			$row = ECM_Flow::create( $event, $phone, 'manual', $test, $input );
			if ( is_wp_error( $row ) ) {
				wp_die( esc_html( $row->get_error_message() ), '', array( 'response' => 409, 'back_link' => true ) );
			}
			if ( $sms ) {
				ECM_Flow::notify( $row, 'initial', 'once', $test );
			}
			wp_safe_redirect( ECM_Reports::url( 'workflow', array( 'ecm_event' => $id, 'flow_id' => $row->registration_id ), true ), 303 );
			exit;
		}
		$result = self::manual_standard( $event, $config, $input, $phone, $test );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409, 'back_link' => true ) );
		}
		if ( $sms ) {
			foreach ( ECM_DB::participants_for_registration( $result ) as $participant ) {
				ECM_SMS::queue( 'registration', $result, 'manual-' . $result . '-' . $participant->id, array( 'participant_id' => (int) $participant->id, 'explicit_manual_test' => $test ) );
			}
		}
		wp_safe_redirect( add_query_arg( array( 'event_id' => $id, 'manual_created' => $result ), admin_url( 'admin.php?page=ecm-test-registration' ) ), 303 );
		exit;
	}

	private static function manual_standard( object $event, array $config, array $input, string $phone, bool $test ) {
		global $wpdb;
		return ECM_Flow::atomic( static function () use ( $wpdb, $event, $config, $input, $phone, $test ) {
			$table = ECM_DB::table( 'registrations' );
			$hash = ECM_Flow::phone_hash( $phone );
			$now = ECM_Utils::now_utc();
			$amount = ! empty( $config['payment_required'] ) && ! $test ? ECM_Utils::decimal( (string) $config['amount'] ) : '0';
			if ( ( ! $test && ! empty( $config['prevent_duplicate_phone'] ) ) && ECM_Participants::recover_by_phone( $phone, (int) $event->id ) ) {
				return new WP_Error( 'duplicate', 'برای این شماره قبلاً پرونده فعال وجود دارد.' );
			}
			ECM_Flow::write( $wpdb->insert( $table, array(
				'public_id' => ECM_Utils::public_id(), 'event_id' => (int) $event->id, 'is_test' => (int) $test,
				'form_data' => wp_json_encode( $input['form'], JSON_UNESCAPED_UNICODE ),
				'dedupe_phone_hash' => ! $test && ! empty( $config['prevent_duplicate_phone'] ) ? $hash : null,
				'amount_original' => $amount, 'currency_label' => $config['currency_label'], 'exchange_rate' => $config['rial_rate'],
				'amount_rial' => ECM_Utils::rial_amount( $amount, (string) $config['rial_rate'] ),
				'payment_status' => '0' === $amount ? 'not_required' : 'offline_confirmed',
				'access_hash' => wp_hash_password( wp_generate_password( 40 ) ), 'created_at' => $now, 'updated_at' => $now,
			) ) );
			$id = (int) $wpdb->insert_id;
			if ( ! ECM_Events::ensure_registration_entities( $id, $input['form'] ) ) {
				throw new RuntimeException( 'Primary participant missing.' );
			}
			$guests = ECM_Participants::create_guests( $id, $input['guests'], false );
			if ( is_wp_error( $guests ) ) {
				return $guests;
			}
			( new ECM_Public() )->index_fields( $id, $input['form'], $config['fields'] );
			ECM_Utils::log( $id, $test ? 'admin_test_created' : 'admin_manual_created', array( 'is_test' => $test, 'source' => 'manual' ) );
			return $id;
		} );
	}
}
