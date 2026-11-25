<?php

defined( 'ABSPATH' ) || exit;

final class ECM_Flow {
	public const SCHEMA = '1';
	public const CRON = 'ecm_workflow_reminders';

	public static function hooks(): void {
		ECM_Flow_Public::hooks();
		ECM_Flow_Admin::hooks();
		add_action( self::CRON, array( __CLASS__, 'reminders' ) );
		add_action( ECM_Flow_Reminders::REPLAN, array( 'ECM_Flow_Reminders', 'replan' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'schedule' ), 24 );
	}

	public static function install(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collation = $wpdb->get_charset_collate();
		$tables = array(
			'workflow' => "registration_id bigint unsigned NOT NULL,
				event_id bigint unsigned NOT NULL,
				phone_hash char(64) DEFAULT NULL,
				state varchar(24) NOT NULL,
				source varchar(16) NOT NULL DEFAULT 'public',
				version int unsigned NOT NULL DEFAULT 1,
				revision int unsigned NOT NULL DEFAULT 1,
				snapshot longtext NOT NULL,
				details longtext NOT NULL,
				residence varchar(190) NOT NULL DEFAULT '',
				priority_city varchar(120) NOT NULL DEFAULT '',
				available_cities text NOT NULL,
				receipt_id bigint unsigned NOT NULL DEFAULT 0,
				review_note text NOT NULL,
				next_reminder datetime DEFAULT NULL,
				reminder_no tinyint unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (registration_id),
				UNIQUE KEY event_phone (event_id,phone_hash),
				KEY event_state (event_id,state,registration_id),
				KEY reminder (next_reminder,registration_id)",
			'workflow_receipts' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
				registration_id bigint unsigned NOT NULL,
				mime varchar(48) NOT NULL,
				byte_size int unsigned NOT NULL,
				checksum char(64) NOT NULL,
				reference varchar(120) NOT NULL,
				payment_date date DEFAULT NULL,
				status varchar(24) NOT NULL,
				note text NOT NULL,
				reviewer bigint unsigned NOT NULL DEFAULT 0,
				reviewed_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY registration_id (registration_id,id)",
			'workflow_receipt_chunks' => "receipt_id bigint unsigned NOT NULL,
				chunk_no smallint unsigned NOT NULL,
				content mediumtext NOT NULL,
				PRIMARY KEY  (receipt_id,chunk_no)",
		);
		foreach ( $tables as $name => $definition ) {
			$table = ECM_DB::table( $name );
			dbDelta( "CREATE TABLE {$table} (\n{$definition}\n) ENGINE=InnoDB {$collation};" );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}
		update_option( 'ecm_workflow_schema', self::SCHEMA, false );
		return true;
	}

	public static function ready(): bool {
		return self::SCHEMA === get_option( 'ecm_workflow_schema' );
	}

	public static function schedule(): void {
		if ( self::ready() && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'ecm_minute', self::CRON );
		}
	}

	public static function settings( int $event_id ): array {
		return (array) get_option( 'ecm_workflow_event_' . $event_id, array() );
	}

	public static function enabled( ?object $event ): bool {
		return $event && ! empty( self::settings( (int) $event->id )['enabled'] );
	}

	public static function decode( string $value ): array {
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	public static function row( int $id, bool $lock = false ): ?object {
		if ( ! self::ready() || ! $id ) {
			return null;
		}
		global $wpdb;
		$table = ECM_DB::table( 'workflow' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE registration_id=%d" . ( $lock ? ' FOR UPDATE' : '' ), $id ) ) ?: null;
	}

	public static function ticket_revoked( int $registration_id ): bool {
		$row = self::row( $registration_id );
		return $row && ! empty( self::decode( $row->details )['ticket_revoked'] );
	}

	public static function by_phone( int $event_id, string $phone ): ?object {
		global $wpdb;
		$table = ECM_DB::table( 'workflow' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id=%d AND phone_hash=%s", $event_id, self::phone_hash( $phone ) ) ) ?: null;
	}

	public static function phone_hash( string $phone ): string {
		return hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) );
	}

	public static function states(): array {
		return array(
			'details' => 'مشخصات ناقص', 'extra' => 'مرحله تکمیلی', 'covenant' => 'در انتظار پذیرش میثاق‌نامه',
			'decision' => 'در انتظار تعیین وضعیت', 'followup' => 'نیازمند پیگیری',
			'payment' => 'در انتظار ارسال رسید', 'review' => 'رسید ارسال شده / در انتظار بررسی',
			'more_info' => 'درخواست اطلاعات تکمیلی', 'rejected' => 'رسید رد شده',
			'confirmed' => 'تأیید نهایی / رسید تأیید شده', 'cancelled' => 'انصراف داده',
		);
	}

	public static function transition( string $state, string $command ): ?string {
		$map = array(
			'payment' => array( 'submit_receipt' => 'review' ),
			'review' => array( 'approve' => 'confirmed', 'reject' => 'rejected', 'more_info' => 'more_info' ),
			'rejected' => array( 'submit_receipt' => 'review', 'cancel' => 'cancelled' ),
			'more_info' => array( 'submit_receipt' => 'review', 'cancel' => 'cancelled' ),
			'decision' => array( 'choose_payment' => 'payment', 'choose_followup' => 'followup' ),
			'followup' => array( 'choose_payment' => 'payment', 'choose_followup' => 'followup', 'cancel' => 'cancelled' ),
		);
		return $map[ $state ][ $command ] ?? null;
	}

	public static function atomic( callable $work ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'database', 'شروع عملیات ذخیره ممکن نیست.' );
		}
		try {
			$result = $work();
			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $result;
			}
			self::write( $wpdb->query( 'COMMIT' ) );
			return $result;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			ECM_Diagnostics::record( 'workflow', 'Transaction failed', array( 'error_class' => get_class( $error ) ) );
			return new WP_Error( 'database', 'ذخیره انجام نشد. دوباره تلاش کنید.' );
		}
	}

	public static function write( $result ): void {
		if ( false === $result ) {
			throw new RuntimeException( 'Workflow write failed.' );
		}
	}

	/** Called only by the existing administrator-confirmed deletion paths. */
	public static function delete_for_registrations( array $ids ): void {
		if ( ! self::ready() || ! $ids ) {
			return;
		}
		global $wpdb;
		$workflow = ECM_DB::table( 'workflow' );
		$receipts = ECM_DB::table( 'workflow_receipts' );
		$chunks = ECM_DB::table( 'workflow_receipt_chunks' );
		foreach ( array_chunk( array_map( 'absint', $ids ), 250 ) as $group ) {
			$list = implode( ',', $group );
			self::write( $wpdb->query( "DELETE FROM {$chunks} WHERE receipt_id IN (SELECT id FROM {$receipts} WHERE registration_id IN ({$list}))" ) );
			self::write( $wpdb->query( "DELETE FROM {$receipts} WHERE registration_id IN ({$list})" ) );
			self::write( $wpdb->query( "DELETE FROM {$workflow} WHERE registration_id IN ({$list})" ) );
		}
	}

	public static function create( object $event, string $phone, string $source = 'public', bool $test = false, ?array $initial = null ): object {
		global $wpdb;
		$event_id = (int) $event->id;
		$config = ECM_Events::config( $event );
		$settings = self::settings( $event_id );
		$settings['form'] = $config;
		$settings['guest_limit'] = min( 20, max( 0, (int) $event->guest_limit ) );
		$settings['edit_keys'] = array_values( (array) ( $settings['edit_keys'] ?? array() ) );
		$data = $initial['form'] ?? array( 'mobile' => $phone, ( $config['sms_field'] ?: 'mobile' ) => $phone );
		$state = $initial ? ( ! empty( $settings['extra'] ) ? 'extra' : self::after_extra( $settings ) ) : 'details';
		$amount = ECM_Utils::decimal( (string) $config['amount'] );
		$rate = ECM_Utils::decimal( (string) $config['rial_rate'] );
		$now = ECM_Utils::now_utc();
		$result = self::atomic(
			static function () use ( $wpdb, $event_id, $phone, $source, $test, $settings, $data, $amount, $rate, $config, $now, $initial, $state ) {
				self::write( $wpdb->insert( ECM_DB::table( 'registrations' ), array(
					'public_id' => ECM_Utils::public_id(), 'event_id' => $event_id, 'is_test' => (int) $test,
					'form_data' => wp_json_encode( self::report_projection( $data ) ), 'payment_status' => 'workflow_pending',
					'amount_original' => $amount, 'exchange_rate' => $rate, 'currency_label' => $config['currency_label'],
					'amount_rial' => ECM_Utils::rial_amount( $amount, $rate ), 'access_hash' => wp_hash_password( wp_generate_password( 40 ) ),
					'created_at' => $now, 'updated_at' => $now,
				) ) );
				$id = (int) $wpdb->insert_id;
				self::write( $wpdb->insert( ECM_DB::table( 'workflow' ), array(
					'registration_id' => $id, 'event_id' => $event_id, 'phone_hash' => $test ? null : self::phone_hash( $phone ),
					'state' => $state, 'source' => $source, 'version' => (int) ( $settings['version'] ?? 1 ),
					'snapshot' => wp_json_encode( $settings ), 'details' => wp_json_encode( array( 'form' => $data, 'guests' => $initial['guests'] ?? array(), 'extra_index' => 0 ) ),
					'residence' => (string) ( $data['residence_city'] ?? '' ), 'priority_city' => (string) ( $data['priority_city'] ?? '' ),
					'available_cities' => wp_json_encode( array_values( (array) ( $data['available_cities'] ?? array() ) ), JSON_UNESCAPED_UNICODE ),
					'next_reminder' => ! $test ? ECM_Flow_Reminders::next( $settings, $state, $now, array() ) : null,
					'review_note' => '', 'updated_at' => $now,
				) ) );
				return self::row( $id );
			}
		);
		if ( ! is_wp_error( $result ) ) {
			ECM_Utils::log( (int) $result->registration_id, 'workflow_started', array( 'source' => $source ) );
		}
		if ( is_wp_error( $result ) && ! $test && 'public' === $source ) {
			return self::by_phone( $event_id, $phone ) ?: $result;
		}
		return $result;
	}

	public static function change( int $id, int $revision, string $command, array $input = array() ) {
		global $wpdb;
		$result = self::atomic(
			static function () use ( $wpdb, $id, $revision, $command, $input ) {
				$row = self::row( $id, true );
				$registration = ECM_DB::registration( $id );
				if ( ! $row || ! $registration || (int) $row->revision !== $revision ) {
					return new WP_Error( 'stale', 'این پرونده تغییر کرده است. صفحه را تازه‌سازی کنید.' );
				}
				$config = self::decode( $row->snapshot );
				$details = self::decode( $row->details );
				$state = (string) $row->state;
				$next = self::transition( $state, $command );
				$now = ECM_Utils::now_utc();
				$updates = array();
				$registration_updates = array();
				if ( ( 'save_details' === $command && 'details' === $state ) || 'edit_workflow' === $command ) {
					$form = $input['form'];
					$old_form = (array) ( $details['form'] ?? ECM_DB::decode_data( $registration ) );
					$phone_key = $config['form']['sms_field'] ?: 'mobile';
					$form['mobile'] = $form[ $phone_key ] = (string) ( $old_form['mobile'] ?? $old_form[ $phone_key ] ?? '' );
					$changed = array_keys( array_filter( $form, static fn( $value, $key ) => ( $old_form[ $key ] ?? null ) !== $value, ARRAY_FILTER_USE_BOTH ) );
					$old_guests = (array) ( $details['guests'] ?? array() );
					$guests_changed = $old_guests !== $input['guests'];
					$replacement = 'cancelled' !== $state ? (array) ( $input['replacement_receipt'] ?? array() ) : array();
					$followup = isset( $details['followup'], $input['followup'] ) ? array_merge( $details['followup'], $input['followup'] ) : array();
					$followup_changed = $followup && $followup !== $details['followup'];
					if ( 'edit_workflow' === $command && ! $changed && ! $guests_changed && ! $replacement && ! $followup_changed ) {
						$row->edit_unchanged = true;
						return $row;
					}
					$details['form'] = $form;
					$registration_updates['form_data'] = wp_json_encode( self::report_projection( $form ), JSON_UNESCAPED_UNICODE );
					$details['guests'] = $input['guests'];
					$updates['residence'] = (string) ( $form['residence_city'] ?? '' );
					$updates['priority_city'] = (string) ( $form['priority_city'] ?? '' );
					$updates['available_cities'] = wp_json_encode( array_values( (array) ( $form['available_cities'] ?? array() ) ), JSON_UNESCAPED_UNICODE );
					$next = ! empty( $config['extra'] ) ? 'extra' : self::after_extra( $config );
					if ( 'save_details' === $command ) { $details['extra_index'] = 0; }
					if ( 'edit_workflow' === $command ) {
						if ( $followup_changed ) {
							$details['followup'] = $followup;
							$details['followup']['edited_at'] = $now;
							$changed[] = 'followup';
						}
						$next = $state;
						$requires_review = $replacement || 'confirmed' === $state;
						if ( $requires_review ) {
							$next = 'review';
							$registration_updates['payment_status'] = 'workflow_pending';
							$updates['review_note'] = '';
							if ( $replacement ) {
								$updates['receipt_id'] = ECM_Flow_Receipts::insert( $id, $replacement );
							} elseif ( (int) $row->receipt_id ) {
								ECM_Flow_Receipts::review( (int) $row->receipt_id, 'review', '', $now );
							}
						}
						if ( 'cancelled' !== $state && isset( $details['consent'] ) && in_array( $config['form']['name_field'], $changed, true ) ) {
							$details['previous_consents'][] = $details['consent'];
							unset( $details['consent'] );
							$details['after_consent'] = $next;
							$next = 'covenant';
						}
						$details['last_edit'] = array( 'at' => $now, 'fields' => $changed, 'guests_changed' => $guests_changed, 'receipt_changed' => (bool) $replacement );
					}
				} elseif ( 'save_extra' === $command && 'extra' === $state ) {
					$index = (int) ( $details['extra_index'] ?? 0 );
					if ( ! isset( $config['extra'][ $index ] ) ) {
						return new WP_Error( 'step', 'مرحله تکمیلی یافت نشد.' );
					}
					$details['form'] = $input['form'];
					$registration_updates['form_data'] = wp_json_encode( self::report_projection( $input['form'] ), JSON_UNESCAPED_UNICODE );
					$updates['residence'] = (string) ( $input['form']['residence_city'] ?? '' );
					$details['extra_index'] = $index + 1;
					$next = isset( $config['extra'][ $index + 1 ] ) ? 'extra' : self::after_extra( $config );
				} elseif ( 'accept' === $command && 'covenant' === $state && ! empty( $input['accept'] ) ) {
					$details['consent'] = array( 'at' => $now, 'version' => (int) $row->version, 'name' => ECM_Utils::field_value( ECM_DB::decode_data( $registration ), $config['form']['name_field'] ) );
					$next = (string) ( $details['after_consent'] ?? ( ! empty( $config['decision'] ) ? 'decision' : 'payment' ) );
					unset( $details['after_consent'] );
				} elseif ( 'back' === $command && in_array( $state, array( 'payment', 'extra', 'covenant', 'decision' ), true ) && ! (int) $row->receipt_id ) {
					$next = match ( $state ) {
						'payment' => ! empty( $config['decision'] ) ? 'decision' : ( ! empty( $config['covenant'] ) ? 'covenant' : ( ! empty( $config['extra'] ) ? 'extra' : 'details' ) ),
						'decision' => ! empty( $config['covenant'] ) ? 'covenant' : ( ! empty( $config['extra'] ) ? 'extra' : 'details' ),
						'covenant' => ! empty( $config['extra'] ) ? 'extra' : 'details',
						default => (int) ( $details['extra_index'] ?? 0 ) > 0 ? 'extra' : 'details',
					};
					if ( 'extra' === $next ) {
						$details['extra_index'] = 'extra' === $state ? (int) $details['extra_index'] - 1 : count( $config['extra'] ) - 1;
					}
					if ( 'details' === $next ) {
						unset( $details['consent'] );
					}
				} elseif ( 'choose_followup' === $command && $next ) {
					$details['followup'] = array( 'reason' => $input['reason'], 'notes' => $input['notes'], 'at' => $now );
				} elseif ( 'contact' === $command && 'followup' === $state ) {
					$details['contacts'][] = array( 'at' => $now, 'actor' => get_current_user_id(), 'note' => $input['note'] );
					$next = $state;
				} elseif ( 'submit_receipt' === $command && $next ) {
					$updates['receipt_id'] = ECM_Flow_Receipts::insert( $id, $input );
					$updates['review_note'] = '';
					if ( ! empty( $input['reply'] ) ) {
						$details['replies'][] = array( 'receipt_id' => $updates['receipt_id'], 'at' => $now, 'text' => $input['reply'] );
					}
				} elseif ( in_array( $command, array( 'approve', 'reject', 'more_info' ), true ) && $next ) {
					if ( ! (int) $row->receipt_id ) {
						return new WP_Error( 'receipt', 'رسید موجود نیست.' );
					}
					if ( 'approve' === $command ) {
						$event = ECM_Events::event( (int) $row->event_id );
						if ( ! ECM_Events::allows( $event, 'card' ) || count( (array) ( $details['guests'] ?? array() ) ) > (int) $event->guest_limit ) {
							return new WP_Error( 'event_config', 'صدور کارت یا سقف مهمان رویداد را در تنظیمات بررسی کنید.' );
						}
						$full_form = (array) ( $details['form'] ?? ECM_DB::decode_data( $registration ) );
						$primary = ECM_Events::ensure_registration_entities( $id, $full_form, true );
						if ( ! $primary ) {
							throw new RuntimeException( 'Primary participant missing.' );
						}
						self::write( $wpdb->update( ECM_DB::table( 'participants' ), array( 'display_name' => ECM_Utils::field_value( $full_form, $config['form']['name_field'] ) ), array( 'id' => (int) $primary->id ) ) );
						$guests = ECM_Flow_Edit::reconcile_guests( $id, (array) ( $details['guests'] ?? array() ) );
						if ( is_wp_error( $guests ) ) {
							return $guests;
						}
						self::write( $wpdb->delete( ECM_DB::table( 'registration_meta' ), array( 'registration_id' => $id ) ) );
						( new ECM_Public() )->index_fields( $id, $full_form, $config['form']['fields'] );
						$registration_updates = array( 'payment_status' => 'paid', 'payment_reference' => ECM_Flow_Receipts::row( (int) $row->receipt_id )->reference, 'paid_at' => $now );
						foreach ( ECM_DB::participants_for_registration( $id ) as $participant ) {
							if ( ! ECM_Tickets::card_url( $participant ) || ! ECM_Tickets::attendance_url( $participant ) ) {
								throw new RuntimeException( 'Ticket issuance failed.' );
							}
						}
					}
					$updates['review_note'] = (string) ( $input['note'] ?? '' );
					ECM_Flow_Receipts::review( (int) $row->receipt_id, $next, $updates['review_note'], $now );
				} elseif ( 'cancel' === $command && $next ) {
					$registration_updates['payment_status'] = 'cancelled';
					$details['cancelled_at'] = $now;
				}
				if ( ! $next ) {
					return new WP_Error( 'transition', 'این عملیات در وضعیت فعلی مجاز نیست.' );
				}
				if ( $registration_updates ) {
					self::write( $wpdb->update( ECM_DB::table( 'registrations' ), $registration_updates + array( 'updated_at' => $now ), array( 'id' => $id ) ) );
				}
				$policy = self::settings( (int) $row->event_id );
				$updates += array(
					'state' => $next, 'revision' => $revision + 1, 'details' => wp_json_encode( $details, JSON_UNESCAPED_UNICODE ),
					'updated_at' => $now, 'reminder_no' => 0,
					'next_reminder' => empty( $registration->is_test ) ? ECM_Flow_Reminders::next( $policy, $next, $now, $details ) : null,
				);
				self::write( $wpdb->update( ECM_DB::table( 'workflow' ), $updates, array( 'registration_id' => $id ) ) );
				ECM_Utils::log( $id, 'workflow_' . $command, array( 'from' => $state, 'to' => $next, 'revision' => $revision + 1, 'receipt_id' => (int) ( $updates['receipt_id'] ?? $row->receipt_id ) ) + ( 'edit_workflow' === $command ? $details['last_edit'] : array() ) );
				return self::row( $id );
			}
		);
		if ( ! is_wp_error( $result ) && empty( $result->edit_unchanged ) ) {
			$notification = array( 'save_details' => 'initial', 'choose_followup' => 'followup', 'submit_receipt' => 'submitted', 'approve' => 'confirmed', 'reject' => 'rejected', 'more_info' => 'more_info', 'cancel' => 'cancelled', 'edit_workflow' => 'edit' )[ $command ] ?? '';
			if ( $notification ) {
				self::notify( $result, $notification, 'initial' === $notification ? 'once' : (string) $result->revision );
			}
		}
		return $result;
	}

	private static function after_extra( array $config ): string {
		return ! empty( $config['covenant'] ) ? 'covenant' : ( ! empty( $config['decision'] ) ? 'decision' : 'payment' );
	}

	private static function report_projection( array $data ): array {
		unset( $data['national_id'], $data['national_code'], $data['birth_date'] );
		return $data;
	}

	public static function notify( object $row, string $state, string $key, bool $explicit_manual_test = false, ?array $custom_rules = null, array $context = array() ): void {
		$id = (int) $row->registration_id;
		$settings = self::settings( (int) $row->event_id );
		$details = self::decode( $row->details );
		$rules = $custom_rules ?? (array) ( $settings['sms'][ $state ] ?? array() );
		foreach ( array( 'primary', 'guest' ) as $audience ) {
			$rule = (array) ( $rules[ $audience ] ?? array() );
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			if ( 'primary' === $audience ) {
				$targets = array( array() );
			} elseif ( 'confirmed' === $state ) {
				$targets = array();
				foreach ( ECM_DB::participants_for_registration( $id ) as $participant ) {
					if ( 'guest' === $participant->participant_type ) {
						$targets[] = array( 'participant_id' => (int) $participant->id );
					}
				}
			} else {
				$targets = array_map( static fn( $guest ) => array( 'recipient' => $guest['phone'], 'participant_name' => $guest['name'] ), (array) ( $details['guests'] ?? array() ) );
			}
			foreach ( $targets as $index => $target ) {
				ECM_SMS::queue( 'flow_' . $state, $id, "workflow-{$id}-{$state}-{$key}-{$audience}-{$index}", $target + $context + array(
					'workflow_rule' => $rule, 'participant_type' => $audience, 'workflow_note' => $row->review_note, 'explicit_manual_test' => $explicit_manual_test,
				) );
			}
		}
	}

	public static function reminders(): void {
		if ( ! self::ready() ) {
			return;
		}
		global $wpdb;
		$table = ECM_DB::table( 'workflow' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE next_reminder<=%s ORDER BY next_reminder LIMIT 100", ECM_Utils::now_utc() ) );
		foreach ( $rows as $row ) {
			self::atomic( static function () use ( $wpdb, $table, $row ) {
				$fresh = self::row( (int) $row->registration_id, true );
				if ( ! $fresh || $fresh->next_reminder !== $row->next_reminder || (int) $fresh->revision !== (int) $row->revision ) {
					return false;
				}
				$event = ECM_Events::event( (int) $row->event_id );
				$registration = ECM_DB::registration( (int) $row->registration_id );
				$updates = $event && $registration ? ECM_Flow_Reminders::process( $fresh, self::settings( (int) $row->event_id ), $event, $registration ) : array( 'next_reminder' => null );
				self::write( $wpdb->update( $table, $updates, array( 'registration_id' => (int) $row->registration_id ) ) );
				return true;
			} );
		}
	}
}
