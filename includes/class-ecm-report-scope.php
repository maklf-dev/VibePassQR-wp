<?php

defined( 'ABSPATH' ) || exit;

/**
 * One validated event scope for ECM reporting. Empty IDs means explicitly all events.
 * Historical `ecm_event` deep links remain supported; no query string selects
 * the newest published and enabled event until the user chooses otherwise.
 */
final class ECM_Report_Scope {
	private static ?array $event_catalog = null;

	/** One events query per request, not once per report link or filter field. */
	private static function catalog(): array {
		if ( null === self::$event_catalog ) {
			self::$event_catalog = ECM_Events::all();
		}
		return self::$event_catalog;
	}

	private static function event( int $id ): ?object {
		foreach ( self::catalog() as $event ) {
			if ( (int) $event->id === $id ) return $event;
		}
		return null;
	}
	public static function event_ids(): array {
		if ( isset( $_GET['ecm_events'] ) ) {
			$raw = wp_unslash( $_GET['ecm_events'] );
			$raw = is_array( $raw ) ? $raw : array( $raw );
			$raw = array_values( array_filter( $raw, 'is_scalar' ) );
			if ( in_array( '0', array_map( 'strval', $raw ), true ) ) {
				return array();
			}
			$ids = array_values( array_unique( array_filter( array_map( 'absint', array_slice( $raw, 0, 150 ) ) ) ) );
			return $ids ? ( self::existing_ids( $ids ) ?: array( PHP_INT_MAX ) ) : array( PHP_INT_MAX );
		}
		if ( isset( $_GET['ecm_event'] ) ) {
			$raw_id = wp_unslash( $_GET['ecm_event'] );
			$id = is_scalar( $raw_id ) ? absint( $raw_id ) : PHP_INT_MAX;
			return $id ? ( self::event( $id ) ? array( $id ) : array( PHP_INT_MAX ) ) : array();
		}
		foreach ( self::catalog() as $event ) {
			if ( ECM_Events::is_publicly_active( $event ) ) {
				return array( (int) $event->id );
			}
		}
		return array();
	}

	private static function existing_ids( array $ids ): array {
		if ( ! $ids ) {
			return array();
		}
		// IDs are validated against the event table, never used as raw SQL.
		$known = array_flip( array_map( 'intval', wp_list_pluck( self::catalog(), 'id' ) ) );
		return array_values( array_filter( $ids, static fn( int $id ): bool => isset( $known[ $id ] ) ) );
	}

	public static function query_args(): array {
		return array( 'ecm_events' => self::event_ids() ?: array( 0 ) );
	}

	public static function hidden_fields(): string {
		$html = '';
		foreach ( self::query_args()['ecm_events'] as $id ) {
			$html .= '<input type="hidden" name="ecm_events[]" value="' . esc_attr( $id ) . '">';
		}
		return $html;
	}

	/** A common selector used by the reports header, keeping the list compact. */
	public static function selector( string $view, bool $admin ): string {
		$ids = self::event_ids();
		$selected = array_flip( $ids );
		$events = self::catalog();
		$base = $admin ? admin_url( 'admin.php?page=ecm-reports' ) : ECM_Reports::url( $view, array(), false );
		$label = ! $ids ? 'همه رویدادها' : implode( '، ', array_map( static function ( int $id ): string {
			$event = self::event( $id );
			return $event ? (string) $event->title : 'رویداد نامعتبر';
		}, $ids ) );
		ob_start();
		?>
		<form class="ecm-report-scope" method="get" action="<?php echo esc_url( $base ); ?>">
			<?php if ( $admin ) : ?><input type="hidden" name="page" value="ecm-reports"><?php elseif ( isset( $_GET['ecm_portal_view'] ) ) : ?><input type="hidden" name="ecm_portal_view" value="reports"><?php endif; ?>
			<input type="hidden" name="ecm_view" value="<?php echo esc_attr( $view ); ?>">
			<details><summary>رویدادهای گزارش: <strong><?php echo esc_html( $label ); ?></strong></summary>
			<div class="ecm-report-scope__choices"><label><input type="checkbox" name="ecm_events[]" value="0" <?php checked( ! $ids ); ?>> همه رویدادها</label>
			<?php foreach ( $events as $event ) : ?><label><input type="checkbox" name="ecm_events[]" value="<?php echo esc_attr( $event->id ); ?>" <?php checked( isset( $selected[ (int) $event->id ] ) ); ?>> <?php echo esc_html( $event->title ); ?></label><?php endforeach; ?></div>
			<button class="ecm-button ecm-button--primary" type="submit">اعمال رویدادها</button></details>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	public static function sql_in( string $column ): string {
		$ids = self::event_ids();
		if ( ! $ids ) {
			return '';
		}
		return ' AND ' . $column . ' IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')';
	}
}
