<?php

defined( 'ABSPATH' ) || exit;

final class ECM_Report_Scope {
	public static function ids( ?array $events = null, int $hint = 0 ): array {
		$events = $events ?? ECM_Events::all();
		$allowed = array_values( array_map( 'intval', array_column( $events, 'id' ) ) );
		if ( ! $allowed ) { return array(); }
		if ( isset( $_GET['ecm_event_selection'] ) || isset( $_GET['ecm_events'] ) ) {
			$requested = array_map( 'absint', array_slice( (array) ( $_GET['ecm_events'] ?? array() ), 0, 100 ) );
			return $requested ? array_values( array_intersect( $allowed, $requested ) ) : $allowed;
		}
		$legacy = absint( $_GET['ecm_event'] ?? $hint );
		return array( $legacy && in_array( $legacy, $allowed, true ) ? $legacy : max( $allowed ) );
	}

	public static function condition( string $column, array $ids ): string {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		return $ids ? $column . ' IN (' . implode( ',', $ids ) . ')' : '1=0';
	}

	public static function args( array $ids ): array {
		return array( 'ecm_event_selection' => 1, 'ecm_events' => $ids ?: array( 0 ) );
	}

	public static function hidden( array $ids ): string {
		return '<input type="hidden" name="ecm_event_selection" value="1">' . implode( '', array_map(
			static fn( $id ) => '<input type="hidden" name="ecm_events[]" value="' . (int) $id . '">', $ids ?: array( 0 )
		) );
	}

	public static function checklist( array $events, array $ids ): string {
		$html = '<details class="ecm-scope-picker"><summary>دوره‌های گزارش · ' . count( $ids ) . ' انتخاب</summary><input type="hidden" name="ecm_event_selection" value="1"><div class="ecm-scope-grid">';
		foreach ( $events as $event ) {
			$id = (int) $event->id;
			$html .= '<label><input type="checkbox" name="ecm_events[]" value="' . $id . '" ' . checked( in_array( $id, $ids, true ), true, false ) . '> ' . esc_html( $event->title ) . '</label>';
		}
		return $html . '</div><small>با خالی گذاشتن انتخاب، همه دوره‌های قابل دسترس نشان داده می‌شوند.</small></details>';
	}

	public static function fields( array $ids ): array {
		$fields = array();
		foreach ( $ids as $id ) {
			$event = ECM_Events::event( $id );
			if ( ! $event ) { continue; }
			foreach ( (array) ECM_Events::config( $event )['fields'] as $field ) {
				if ( empty( $field['key'] ) || in_array( $field['type'], array( 'html', 'guest', 'telemetry' ), true ) ) { continue; }
				$key = sanitize_key( $field['key'] );
				$fields[ $key ]['label'] = $fields[ $key ]['label'] ?? $field['label'];
				$fields[ $key ]['options'] = array_values( array_unique( array_merge( $fields[ $key ]['options'] ?? array(), (array) ( $field['options'] ?? array() ) ) ) );
			}
		}
		return $fields;
	}

	public static function form_filters( array $ids ): array {
		$raw = (array) ( $_GET['ecm_field_filter'] ?? array() );
		$valid = array();
		foreach ( self::fields( $ids ) as $key => $field ) {
			$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( wp_unslash( $raw[ $key ] ) ) : '';
			if ( $field['options'] && '' !== $value && in_array( $value, $field['options'], true ) ) { $valid[ $key ] = $value; }
		}
		return $valid;
	}

	public static function field_inputs( array $ids ): string {
		$html = '';
		$filters = self::form_filters( $ids );
		$catalog = array_filter( self::fields( $ids ), static fn( $field ) => ! empty( $field['options'] ) );
		foreach ( $catalog as $key => $field ) {
			$html .= '<label class="ecm-report-field">' . esc_html( $field['label'] ) . '<select name="ecm_field_filter[' . esc_attr( $key ) . ']"><option value="">همه مقادیر</option>';
			foreach ( $field['options'] as $option ) {
				$html .= '<option value="' . esc_attr( $option ) . '" ' . selected( $filters[ $key ] ?? '', $option, false ) . '>' . esc_html( $option ) . '</option>';
			}
			$html .= '</select></label>';
		}
		return $html ? '<div class="ecm-scope-grid ecm-report-filter__fields">' . $html . '</div>' : '';
	}

	public static function field_sql( array $filters, string $column, array &$where, array &$args ): void {
		foreach ( $filters as $key => $value ) {
			$path = '$."' . sanitize_key( $key ) . '"';
			$document = "CASE WHEN JSON_VALID({$column}) THEN {$column} ELSE '{}' END";
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
			if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
				$extracted = "JSON_EXTRACT({$document},%s)";
				$where[] = "(CASE WHEN JSON_TYPE({$document},%s)='array' THEN (INSTR({$extracted},%s)>0 OR INSTR({$extracted},%s)>0) ELSE {$extracted}=%s END)";
				array_push( $args, $path, $path, $encoded, $path, wp_json_encode( $value ), $path, $value );
				continue;
			}
			$where[] = "JSON_CONTAINS({$document}, %s, %s)=1";
			array_push( $args, $encoded, $path );
		}
	}

	public static function metrics( array $ids ): string {
		if ( ! $ids || ! ECM_Flow::ready() ) { return ''; }
		global $wpdb;
		$flow = ECM_DB::table( 'workflow' );
		$receipts = ECM_DB::table( 'workflow_receipts' );
		$audit = ECM_DB::table( 'events' );
		$registrations = ECM_DB::table( 'registrations' );
		$scope = self::condition( 'w.event_id', $ids );
		$storage = $wpdb->get_results( "SELECT w.event_id,COUNT(p.id) uploads,COALESCE(SUM(p.byte_size),0) bytes FROM {$flow} w INNER JOIN {$receipts} p ON p.registration_id=w.registration_id WHERE {$scope} GROUP BY w.event_id", OBJECT_K );
		$edit_scope = self::condition( 'r.event_id', $ids );
		$edits = $wpdb->get_results( "SELECT r.event_id,COUNT(*) edits,COUNT(DISTINCT r.id) edited FROM {$audit} a INNER JOIN {$registrations} r ON r.id=a.registration_id WHERE {$edit_scope} AND r.is_test=0 AND a.event_type IN ('workflow_edit_workflow','registration_self_edited') GROUP BY r.event_id", OBJECT_K );
		$html = '<section class="ecm-report-storage" aria-label="حجم رسیدها و ویرایش‌ها">';
		foreach ( $ids as $id ) {
			$event = ECM_Events::event( $id );
			if ( ! $event ) { continue; }
			$html .= '<span><strong>' . esc_html( $event->title ) . '</strong> · ' . esc_html( size_format( (int) ( $storage[ $id ]->bytes ?? 0 ), 1 ) ?: '۰ بایت' ) . ' در ' . (int) ( $storage[ $id ]->uploads ?? 0 ) . ' نسخهٔ رسید · ' . (int) ( $edits[ $id ]->edits ?? 0 ) . ' ویرایش در ' . (int) ( $edits[ $id ]->edited ?? 0 ) . ' پرونده</span>';
		}
		return $html . '</section>';
	}
}
