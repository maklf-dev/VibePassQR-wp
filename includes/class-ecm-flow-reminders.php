<?php

defined( 'ABSPATH' ) || exit;

final class ECM_Flow_Reminders {
	public const LIMIT = 30;
	public const REPLAN = 'ecm_workflow_replan_reminders';
	public const STATES = array( 'extra', 'covenant', 'decision', 'payment' );

	public static function deadline( array $settings ): int {
		return empty( $settings['reminder_deadline'] ) ? 0 : (int) strtotime( $settings['reminder_deadline'] . ' UTC' );
	}

	public static function next( array $settings, string $state, string $updated, array $details, ?int $now = null ): ?string {
		$now = $now ?? time();
		if ( empty( $settings['reminders'] ) || ! in_array( $state, self::STATES, true ) ) { return null; }
		if ( ! isset( $settings['reminder_rules'] ) ) {
			return gmdate( 'Y-m-d H:i:s', max( $now, strtotime( $updated . ' UTC' ) + 2 * HOUR_IN_SECONDS ) );
		}
		$slots = self::slots( $settings, $updated, $details, $now, $now );
		return $slots ? gmdate( 'Y-m-d H:i:s', reset( $slots ) ) : null;
	}

	private static function slots( array $settings, string $updated, array $details, int $floor, int $now ): array {
		$deadline = self::deadline( $settings );
		if ( $deadline && $now >= $deadline ) { return array(); }
		$updated_at = (int) strtotime( $updated . ' UTC' );
		$floor = max( $floor, (int) ( $settings['reminder_rules_since'] ?? 0 ) );
		$slots = array();
		foreach ( $settings['reminder_rules'] as $id => $rule ) {
			if ( isset( $details['reminder_sent'][ $id ] ) || ( 'deadline' === $rule['anchor'] && ! $deadline ) ) { continue; }
			$due = 'deadline' === $rule['anchor'] ? $deadline - (int) $rule['offset'] : $updated_at + (int) $rule['offset'];
			if ( $due >= $floor && ( ! $deadline || $due < $deadline ) ) {
				$slots[ $id ] = $due;
			}
		}
		asort( $slots, SORT_NUMERIC );
		return $slots;
	}

	/** Run with the workflow row locked. */
	public static function process( object $row, array $settings, object $event, object $registration ): array {
		$count = (int) $row->reminder_no;
		$updates = array( 'next_reminder' => null, 'reminder_no' => $count );
		if ( empty( $settings['reminders'] ) || ! ECM_Events::allows( $event, 'registration' ) || ! empty( $registration->is_test ) || ! in_array( $row->state, self::STATES, true ) ) {
			return $updates;
		}
		if ( ! isset( $settings['reminder_rules'] ) ) {
			$count = min( 3, $count + 1 );
			ECM_Flow::notify( $row, 'reminder_' . $row->state, $row->revision . '-' . $count );
			$deadline = self::deadline( $settings );
			$next = 1 === $count ? strtotime( $row->updated_at . ' UTC' ) + DAY_IN_SECONDS :
				( 2 === $count && $deadline - HOUR_IN_SECONDS > time() ? $deadline - HOUR_IN_SECONDS : 0 );
			return array( 'next_reminder' => $next ? gmdate( 'Y-m-d H:i:s', $next ) : null, 'reminder_no' => $count );
		}
		$details = ECM_Flow::decode( $row->details );
		$now = time();
		$slots = self::slots( $settings, $row->updated_at, $details, (int) strtotime( $row->next_reminder . ' UTC' ), $now );
		foreach ( $slots as $id => $due ) {
			if ( $due > $now ) {
				$updates['next_reminder'] = gmdate( 'Y-m-d H:i:s', $due );
				break;
			}
			$rule = $settings['reminder_rules'][ $id ];
			ECM_Flow::notify( $row, 'reminder_custom', 'rule-' . $id, false, $rule['sms'], array(
				'reminder_rule' => $id, 'reminder_due' => $due, 'reminder_deadline' => self::deadline( $settings ),
			) );
			$details['reminder_sent'][ $id ] = $due;
		}
		$updates['details'] = wp_json_encode( $details, JSON_UNESCAPED_UNICODE );
		return $updates;
	}

	public static function valid_job( object $registration, array $context ): bool {
		$row = ECM_Flow::row( (int) $registration->id );
		$settings = ECM_Flow::settings( (int) $registration->event_id );
		$event = ECM_Events::event( (int) $registration->event_id );
		$id = (string) ( $context['rule'] ?? '' );
		$rule = $settings['reminder_rules'][ $id ] ?? null;
		$deadline = self::deadline( $settings );
		if ( ! $row || ! empty( $registration->is_test ) || ! in_array( $row->state, self::STATES, true ) || empty( $settings['reminders'] ) || ! $rule ||
			! ECM_Events::allows( $event, 'registration' ) || $deadline !== (int) ( $context['deadline'] ?? 0 ) || ( $deadline && time() >= $deadline ) ) {
			return false;
		}
		$expected = 'deadline' === $rule['anchor'] ? $deadline - (int) $rule['offset'] : strtotime( $row->updated_at . ' UTC' ) + (int) $rule['offset'];
		return $expected === (int) ( $context['due'] ?? 0 ) &&
			! empty( $rule['sms'][ $context['audience'] ?? 'primary' ]['enabled'] ) &&
			hash( 'sha256', wp_json_encode( $rule['sms'][ $context['audience'] ?? 'primary' ] ) ) === ( $context['rule_hash'] ?? '' );
	}

	public static function queue_replan( int $event_id, int $version, int $cursor = 0 ): void {
		wp_schedule_single_event( time() + 1, self::REPLAN, array( $event_id, $version, $cursor ) );
	}

	public static function replan( int $event_id, int $version, int $cursor ): void {
		global $wpdb;
		$settings = ECM_Flow::settings( $event_id );
		$event = ECM_Events::event( $event_id );
		if ( ! $event || ! ECM_Flow::ready() || (int) ( $settings['reminder_plan_version'] ?? 0 ) !== $version ) { return; }
		$table = ECM_DB::table( 'workflow' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT registration_id FROM {$table} WHERE event_id=%d AND registration_id>%d AND (state IN ('extra','covenant','decision','payment') OR next_reminder IS NOT NULL) ORDER BY registration_id LIMIT 100", $event_id, $cursor ) );
		foreach ( $ids as $id ) {
			$result = ECM_Flow::atomic( static function () use ( $wpdb, $table, $id, $event, $settings, $version ) {
				$row = ECM_Flow::row( (int) $id, true );
				$registration = ECM_DB::registration( (int) $id );
				if ( ! $row || ! $registration || (int) ( ECM_Flow::settings( (int) $event->id )['reminder_plan_version'] ?? 0 ) !== $version ) { return false; }
				$details = ECM_Flow::decode( $row->details );
				$next = empty( $registration->is_test ) && ECM_Events::allows( $event, 'registration' ) ? self::next( $settings, $row->state, $row->updated_at, $details, (int) ( $settings['reminder_rules_since'] ?? time() ) ) : null;
				ECM_Flow::write( $wpdb->update( $table, array( 'next_reminder' => $next, 'reminder_no' => 0 ), array( 'registration_id' => (int) $id ) ) );
				return true;
			} );
			if ( is_wp_error( $result ) ) {
				self::queue_replan( $event_id, $version, $cursor );
				return;
			}
			$cursor = (int) $id;
		}
		if ( 100 === count( $ids ) ) {
			self::queue_replan( $event_id, $version, $cursor );
		}
	}
}
