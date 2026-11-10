<?php

defined( 'ABSPATH' ) || exit;

/**
 * Read-only, bounded analytics. Queries aggregate each fact table independently
 * to avoid multiplying registrations by guests/attendance/SMS rows.
 * All date groups are UTC days/hours; filter boundaries use the WP timezone.
 */
final class ECM_Analytics {
	private const METRICS = array(
		'registrations' => 'ثبت‌نام‌های نهایی',
		'participants'  => 'شرکت‌کنندگان فعال',
		'attendance'    => 'حضورهای ثبت‌شده',
		'sms'           => 'درخواست‌های پیامک',
		'workflow'      => 'درخواست‌های Workflow',
	);
	private const GROUPS = array(
		'event'           => 'رویداد',
		'day'             => 'روز (UTC)',
		'hour'            => 'ساعت (UTC)',
		'payment_status'  => 'وضعیت پرداخت',
		'workflow_status' => 'وضعیت درخواست Workflow',
	);
	private const COLORS = array( '#2563eb', '#0f766e', '#a21caf', '#b45309', '#be123c' );

	public static function hooks(): void {
		add_action( 'admin_post_ecm_analytics_csv', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_ecm_analytics_svg', array( __CLASS__, 'export_svg' ) );
	}

	/** Strict allowlists; do not let input select raw SQL identifiers. */
	public static function filters(): array {
		$group = isset( $_GET['ecm_group'] ) ? sanitize_key( wp_unslash( $_GET['ecm_group'] ) ) : 'event';
		if ( ! isset( self::GROUPS[ $group ] ) ) { $group = 'event'; }
		$chart = isset( $_GET['ecm_chart'] ) ? sanitize_key( wp_unslash( $_GET['ecm_chart'] ) ) : 'bar';
		if ( ! in_array( $chart, array( 'bar', 'line', 'pie' ), true ) ) { $chart = 'bar'; }
		$raw = isset( $_GET['ecm_metric'] ) ? (array) wp_unslash( $_GET['ecm_metric'] ) : array( 'registrations', 'participants' );
		$metrics = array_values( array_unique( array_intersect( array_keys( self::METRICS ), array_map( 'sanitize_key', array_filter( $raw, 'is_string' ) ) ) ) );
		$metrics = array_slice( $metrics, 0, 5 );
		if ( 'payment_status' === $group ) { $metrics = array( 'registrations' ); }
		if ( 'workflow_status' === $group ) { $metrics = array( 'workflow' ); }
		if ( ! $metrics ) { $metrics = array( 'registrations' ); }
		$today = wp_date( 'Y-m-d' );
		$default_from = wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		$from = self::date( $_GET['ecm_from'] ?? '', $default_from );
		$to = self::date( $_GET['ecm_to'] ?? '', $today );
		// Bounded report window. Malformed/inverted/huge windows use safe defaults.
		if ( strtotime( $from ) > strtotime( $to ) || strtotime( $to ) - strtotime( $from ) > 366 * DAY_IN_SECONDS ) {
			$from = $default_from;
			$to = $today;
		}
		return array( 'group' => $group, 'chart' => $chart, 'metrics' => $metrics, 'from' => $from, 'to' => $to );
	}

	private static function date( $value, string $default ): string {
		if ( ! is_scalar( $value ) ) { return $default; }
		$value = ECM_Utils::normalize_digits( sanitize_text_field( wp_unslash( (string) $value ) ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) { return $default; }
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $parsed && $parsed->format( 'Y-m-d' ) === $value ? $value : $default;
	}

	public static function query_args( array $filters ): array {
		return array(
			'ecm_events' => ECM_Report_Scope::query_args()['ecm_events'],
			'ecm_view'   => 'analytics',
			'ecm_group'  => $filters['group'],
			'ecm_metric' => $filters['metrics'],
			'ecm_chart'  => $filters['chart'],
			'ecm_from'   => $filters['from'],
			'ecm_to'     => $filters['to'],
		);
	}

	/** @return array<int,array{key:string,label:string,values:array<string,int>}> */
	public static function dataset( array $filters ): array {
		global $wpdb;
		$registration = ECM_DB::table( 'registrations' );
		$metric_sources = array(
			'registrations' => array( ECM_DB::table( 'registrations' ), "t.is_test=0", '' ),
			'participants'  => array( ECM_DB::table( 'participants' ), "t.status='active'", "INNER JOIN {$registration} r ON r.id=t.registration_id AND r.is_test=0" ),
			'attendance'    => array( ECM_DB::table( 'attendance' ), "t.status='success'", "INNER JOIN {$registration} r ON r.id=t.registration_id AND r.is_test=0" ),
			'sms'           => array( ECM_DB::table( 'sms' ), '(t.registration_id=0 OR r.is_test=0)', "LEFT JOIN {$registration} r ON r.id=t.registration_id" ),
			'workflow'      => array( ECM_DB::table( 'flow_instances' ), '1=1', '' ),
		);
		$group = $filters['group'];
		$bucket = 't.event_id';
		if ( 'day' === $group ) { $bucket = 'DATE(t.created_at)'; }
		if ( 'hour' === $group ) { $bucket = 'HOUR(t.created_at)'; }
		if ( 'payment_status' === $group ) { $bucket = 't.payment_status'; }
		if ( 'workflow_status' === $group ) { $bucket = 't.state'; }
		$from = ECM_Utils::local_to_utc( $filters['from'] . ' 00:00:00' );
		$to = ECM_Utils::local_to_utc( $filters['to'] . ' 23:59:59' );
		if ( ! $from || ! $to ) { return array(); }
		$ids = ECM_Report_Scope::event_ids();
		$where_event = $ids ? ' AND t.event_id IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')' : '';
		$combined = array();
		foreach ( $filters['metrics'] as $metric ) {
			if ( ! isset( $metric_sources[ $metric ] ) ) { continue; }
			if ( 'workflow' === $metric && ! ECM_Workflow_Schema::installed() ) { continue; }
			list( $table, $condition, $joins ) = $metric_sources[ $metric ];
			$sql = "SELECT {$bucket} bucket,COUNT(*) total FROM {$table} t {$joins} WHERE {$condition} AND t.created_at >= %s AND t.created_at <= %s{$where_event} GROUP BY bucket ORDER BY bucket ASC LIMIT 370";
			$results = $wpdb->get_results( $wpdb->prepare( $sql, $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $results as $record ) {
				$key = (string) $record->bucket;
				if ( '' === $key ) { $key = '(unknown)'; }
				if ( ! isset( $combined[ $key ] ) ) { $combined[ $key ] = array( 'key' => $key, 'label' => $key, 'values' => array_fill_keys( $filters['metrics'], 0 ) ); }
				$combined[ $key ]['values'][ $metric ] = max( 0, (int) $record->total );
			}
		}
		$catalog = array();
		foreach ( ECM_Events::all() as $event ) { $catalog[ (string) $event->id ] = (string) $event->title; }
		foreach ( $combined as &$row ) {
			if ( 'event' === $group ) { $row['label'] = $catalog[ $row['key'] ] ?? ( 'شناسه ' . $row['key'] ); }
			if ( 'hour' === $group && ctype_digit( $row['key'] ) ) { $row['label'] = sprintf( '%02d:00 UTC', (int) $row['key'] ); }
			if ( 'payment_status' === $group ) {
				$row['label'] = array( 'paid' => 'پرداخت‌شده', 'not_required' => 'بدون پرداخت', 'offline_confirmed' => 'تأیید دستی', 'pending' => 'در انتظار پرداخت', 'failed' => 'ناموفق' )[ $row['key'] ] ?? $row['key'];
			}
			if ( 'workflow_status' === $group ) { $row['label'] = self::state_label( $row['key'] ); }
		}
		unset( $row );
		return array_values( $combined );
	}

	public static function render( bool $admin ): string {
		if ( ! current_user_can( 'view_ecm_reports' ) ) { return ''; }
		$f = self::filters();
		$rows = self::dataset( $f );
		$totals = array_fill_keys( $f['metrics'], 0 );
		foreach ( $rows as $row ) { foreach ( $f['metrics'] as $key ) { $totals[ $key ] += $row['values'][ $key ] ?? 0; } }
		$base = $admin ? admin_url( 'admin.php' ) : ECM_Reports::url( 'analytics', array(), false );
		$export = self::query_args( $f );
		unset( $export['ecm_view'] );
		$csv = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'ecm_analytics_csv' ), $export ), admin_url( 'admin-post.php' ) ), 'ecm_analytics_export' );
		$svg = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'ecm_analytics_svg' ), $export ), admin_url( 'admin-post.php' ) ), 'ecm_analytics_export' );
		ob_start();
		?>
		<section class="ecm-report-section ecm-analytics" dir="rtl">
			<div class="ecm-report-section__head"><div><h2>گزارش‌ساز تحلیلی</h2><p>گزارش تجمیعی و قابل مقایسه بدون دریافت ردیف‌های شخصی. بازه زمانی حداکثر ۳۶۶ روز است.</p></div></div>
			<form method="get" action="<?php echo esc_url( $base ); ?>" class="ecm-analytics__form">
				<?php if ( $admin ) : ?><input type="hidden" name="page" value="ecm-reports"><?php elseif ( isset( $_GET['ecm_portal_view'] ) ) : ?><input type="hidden" name="ecm_portal_view" value="reports"><?php endif; ?>
				<?php echo ECM_Report_Scope::hidden_fields(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="ecm_view" value="analytics">
				<label>گروه‌بندی <select name="ecm_group"><?php foreach ( self::GROUPS as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $f['group'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label>از تاریخ <input type="date" name="ecm_from" value="<?php echo esc_attr( $f['from'] ); ?>"></label>
				<label>تا تاریخ <input type="date" name="ecm_to" value="<?php echo esc_attr( $f['to'] ); ?>"></label>
				<label>نوع نمودار <select name="ecm_chart"><option value="bar" <?php selected( $f['chart'], 'bar' ); ?>>میله‌ای</option><option value="line" <?php selected( $f['chart'], 'line' ); ?>>خطی</option><option value="pie" <?php selected( $f['chart'], 'pie' ); ?>>دایره‌ای (یک شاخص)</option></select></label>
				<fieldset><legend>شاخص‌ها</legend><?php foreach ( self::METRICS as $key => $label ) : ?><label><input type="checkbox" name="ecm_metric[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $f['metrics'], true ) ); ?>><?php echo esc_html( $label ); ?></label><?php endforeach; ?></fieldset>
				<button type="submit" class="button button-primary">محاسبه گزارش</button>
			</form>
			<p class="description">فیلتر رویداد در بالای همین صفحه اعمال می‌شود. روز و ساعت بر اساس UTC گروه‌بندی می‌شوند؛ مرز تاریخ انتخابی مطابق منطقه زمانی وردپرس است. برای گروه‌بندی وضعیت، شاخص مرتبط به‌صورت خودکار انتخاب می‌شود.</p>
			<div class="ecm-analytics__totals"><?php foreach ( $totals as $key => $value ) : ?><div><small><?php echo esc_html( self::METRICS[ $key ] ); ?></small><strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong></div><?php endforeach; ?></div>
			<?php if ( $rows ) : ?>
			<div class="ecm-analytics__chart" role="group" aria-label="نمودار داده‌های انتخاب‌شده"><?php echo self::chart_svg( $rows, $f ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="ecm-analytics__actions"><a class="button" href="<?php echo esc_url( $csv ); ?>">دریافت CSV داده‌ها</a><a class="button" href="<?php echo esc_url( $svg ); ?>">دریافت SVG نمودار</a></div>
			<div class="ecm-analytics__table-wrap"><table class="widefat striped"><thead><tr><th>گروه</th><?php foreach ( $f['metrics'] as $metric ) : ?><th><?php echo esc_html( self::METRICS[ $metric ] ); ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ( $rows as $row ) : ?><tr><th><?php echo esc_html( $row['label'] ); ?></th><?php foreach ( $f['metrics'] as $metric ) : ?><td><?php echo esc_html( number_format_i18n( $row['values'][ $metric ] ?? 0 ) ); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
			<?php else : ?><p class="ecm-state">برای بازه و رویدادهای انتخاب‌شده داده‌ای ثبت نشده است.</p><?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function state_label( string $status ): string {
		return array( 'draft'=>'پیش‌نویس', 'awaiting_agreement'=>'در انتظار پذیرش', 'awaiting_receipt'=>'در انتظار رسید', 'under_review'=>'در انتظار بررسی', 'needs_correction'=>'نیازمند اصلاح', 'approved'=>'تأییدشده', 'rejected'=>'ردشده', 'cancelled'=>'لغوشده', 'expired'=>'منقضی' )[ $status ] ?? $status;
	}

	private static function xml( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' ); }
	private static function short( string $label ): string { return function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 29 ) : substr( $label, 0, 55 ); }

	/** SVG has no scripts/external assets and is safe for both inline display and download. */
	public static function chart_svg( array $rows, array $filters ): string {
		$metrics = $filters['metrics'];
		$rows = array_slice( $rows, 0, 24 );
		if ( 'pie' === $filters['chart'] && count( $metrics ) === 1 && count( $rows ) <= 12 ) { return self::pie_svg( $rows, $metrics[0] ); }
		$height = max( 280, 110 + count( $rows ) * 36 );
		$top = 76;
		$width = 950;
		$max = 1;
		foreach ( $rows as $row ) { foreach ( $metrics as $metric ) { $max = max( $max, (int) ( $row['values'][ $metric ] ?? 0 ) ); } }
		$s = '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="نمودار آماری" viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" style="max-height:960px;background:#fff;font-family:Tahoma,Arial,sans-serif">';
		$s .= '<rect width="950" height="' . $height . '" fill="#ffffff"/><text x="925" y="30" font-size="17" fill="#1f2937" text-anchor="end">گزارش آماری رویدادها</text>';
		foreach ( $metrics as $idx => $metric ) { $x = 230 + $idx * 195; $color = self::COLORS[ $idx % count( self::COLORS ) ]; $s .= '<rect x="' . $x . '" y="43" width="12" height="12" fill="' . $color . '"/><text x="' . ( $x + 17 ) . '" y="54" fill="#374151" font-size="11">' . self::xml( self::METRICS[ $metric ] ) . '</text>'; }
		if ( 'line' === $filters['chart'] ) {
			$max_points = max( 1, count( $rows ) - 1 );
			foreach ( $metrics as $idx => $metric ) {
				$points = array();
				foreach ( $rows as $i => $row ) { $x = 225 + 670 * $i / $max_points; $y = $height - 55 - ( $height - 150 ) * (int) ( $row['values'][ $metric ] ?? 0 ) / $max; $points[] = round( $x, 1 ) . ',' . round( $y, 1 ); }
				$s .= '<polyline points="' . implode( ' ', $points ) . '" fill="none" stroke="' . self::COLORS[ $idx % count( self::COLORS ) ] . '" stroke-width="3"/>';
			}
			foreach ( $rows as $i => $row ) { $x = 225 + 670 * $i / $max_points; $s .= '<text x="' . round( $x, 1 ) . '" y="' . ( $height - 24 ) . '" font-size="9" fill="#334155" text-anchor="middle">' . self::xml( self::short( $row['label'] ) ) . '</text>'; }
		} else {
			foreach ( $rows as $i => $row ) {
				$y = $top + $i * 36;
				$s .= '<text x="210" y="' . ( $y + 14 ) . '" fill="#1e293b" text-anchor="end" font-size="12">' . self::xml( self::short( $row['label'] ) ) . '</text>';
				foreach ( $metrics as $j => $metric ) {
					$value = (int) ( $row['values'][ $metric ] ?? 0 );
					$x = 224;
					$bar_y = $y + $j * max( 5, intdiv( 24, count( $metrics ) ) );
					$bar_h = max( 3, intdiv( 23, count( $metrics ) ) - 1 );
					$bar_w = (int) round( 615 * $value / $max );
					$s .= '<rect x="' . $x . '" y="' . $bar_y . '" width="' . $bar_w . '" height="' . $bar_h . '" rx="2" fill="' . self::COLORS[ $j % count( self::COLORS ) ] . '"/>';
					$s .= '<text x="' . ( $x + $bar_w + 8 ) . '" y="' . ( $bar_y + $bar_h ) . '" fill="#334155" font-size="11">' . $value . '</text>';
				}
			}
		}
		if ( count( $rows ) >= 24 ) { $s .= '<text x="240" y="' . ( $height - 7 ) . '" font-size="11" fill="#475569">نمودار حداکثر ۲۴ گروه اول را نشان می‌دهد؛ CSV شامل همه گروه‌هاست.</text>'; }
		return $s . '</svg>';
	}

	private static function pie_svg( array $rows, string $metric ): string {
		$total = array_sum( array_map( static fn( array $row ): int => (int) ( $row['values'][ $metric ] ?? 0 ), $rows ) );
		$s = '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="نمودار دایره‌ای" viewBox="0 0 950 430" width="100%" style="background:#fff;font-family:Tahoma,Arial,sans-serif"><rect width="950" height="430" fill="#fff"/><text x="920" y="34" font-size="18" fill="#1e293b" text-anchor="end">' . self::xml( self::METRICS[ $metric ] ) . '</text>';
		$angle = -M_PI / 2;
		foreach ( $rows as $i => $row ) {
			$value = (int) ( $row['values'][ $metric ] ?? 0 );
			if ( $value < 1 || $total < 1 ) { continue; }
			$delta = 2 * M_PI * $value / $total;
			$color = self::COLORS[ $i % count( self::COLORS ) ];
			if ( abs( $delta - 2 * M_PI ) < 0.0001 ) { $s .= '<circle cx="300" cy="215" r="145" fill="' . $color . '"/>'; }
			else {
				$x1 = 300 + 145 * cos( $angle ); $y1 = 215 + 145 * sin( $angle );
				$x2 = 300 + 145 * cos( $angle + $delta ); $y2 = 215 + 145 * sin( $angle + $delta );
				$s .= '<path d="M 300 215 L ' . round( $x1, 2 ) . ' ' . round( $y1, 2 ) . ' A 145 145 0 ' . ( $delta > M_PI ? '1' : '0' ) . ' 1 ' . round( $x2, 2 ) . ' ' . round( $y2, 2 ) . ' Z" fill="' . $color . '"/>';
			}
			$angle += $delta;
			$s .= '<rect x="500" y="' . ( 70 + $i * 27 ) . '" width="13" height="13" fill="' . $color . '"/><text x="522" y="' . ( 82 + $i * 27 ) . '" font-size="12" fill="#334155">' . self::xml( self::short( $row['label'] ) ) . ': ' . $value . '</text>';
		}
		return $s . '</svg>';
	}

	private static function authorized_export(): void {
		if ( ! current_user_can( 'view_ecm_reports' ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'ecm_analytics_export' );
	}
	public static function export_csv(): void {
		self::authorized_export();
		$f = self::filters(); $rows = self::dataset( $f );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="ecm-analytics-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array_merge( array( self::GROUPS[ $f['group'] ] ), array_map( static fn( string $key ): string => self::METRICS[ $key ], $f['metrics'] ) ) );
		foreach ( $rows as $row ) {
			$label = (string) $row['label'];
			if ( preg_match( '/^\s*[=+\-@]/u', $label ) ) { $label = "'" . $label; }
			fputcsv( $out, array_merge( array( $label ), array_map( static fn( string $key ): int => (int) ( $row['values'][ $key ] ?? 0 ), $f['metrics'] ) ) );
		}
		fclose( $out ); exit;
	}
	public static function export_svg(): void {
		self::authorized_export();
		$f = self::filters();
		nocache_headers();
		header( 'Content-Type: image/svg+xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="ecm-analytics-' . gmdate( 'Y-m-d-His' ) . '.svg"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo self::chart_svg( self::dataset( $f ), $f ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
