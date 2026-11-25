<?php

defined( 'ABSPATH' ) || exit;

final class ECM_Flow_Receipts {
	public static function upload( array $file, string $reference, string $date, array $settings ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$maximum = min( 8, max( 1, (int) ( $settings['receipt_mb'] ?? 5 ) ) ) * MB_IN_BYTES;
		$path = (string) ( $file['tmp_name'] ?? '' );
		if ( UPLOAD_ERR_OK !== ( $file['error'] ?? -1 ) || ! is_uploaded_file( $path ) || filesize( $path ) > $maximum ) {
			return new WP_Error( 'file', 'فایل رسید معتبر و در محدوده حجم مجاز بارگذاری کنید.' );
		}
		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $path ) : wp_get_image_mime( $path );
		$supported = array( 'image/jpeg', 'image/png', 'image/webp' );
		if ( ! in_array( $mime, $supported, true ) || ! ( $size = @getimagesize( $path ) ) || ( $size[0] * $size[1] ) > 25000000 ) {
			return new WP_Error( 'type', 'رسید باید تصویر خوانای JPG، PNG یا WebP باشد.' );
		}
		$valid = self::validate_metadata( $reference, $date, $settings );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$bytes = file_get_contents( $path );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'file', 'خواندن فایل رسید ممکن نیست.' );
		}
		$editor = wp_get_image_editor( $path );
		if ( ! is_wp_error( $editor ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$temporary = wp_tempnam( 'ecm-receipt' );
			if ( $temporary ) {
				$editor->set_quality( 92 );
				$saved = $editor->save( $temporary . '.webp', 'image/webp' );
				if ( ! is_wp_error( $saved ) ) {
					$optimized = file_get_contents( $saved['path'] );
					if ( $optimized && strlen( $optimized ) < strlen( $bytes ) ) {
						$bytes = $optimized;
						$mime = 'image/webp';
					}
					if ( $saved['path'] !== $temporary ) {
						@unlink( $saved['path'] );
					}
				}
				@unlink( $temporary );
				if ( is_file( $temporary . '.webp' ) ) { @unlink( $temporary . '.webp' ); }
			}
		}
		return array( 'bytes' => $bytes, 'mime' => $mime, 'reference' => $reference, 'date' => $date ?: null );
	}

	private static function validate_metadata( string $reference, string $date, array $settings ) {
		if ( ! empty( $settings['reference_required'] ) && '' === $reference ) {
			return new WP_Error( 'reference', 'شماره پیگیری تراکنش لازم است.' );
		}
		if ( ! empty( $settings['date_required'] ) && '' === $date ) {
			return new WP_Error( 'date', 'تاریخ پرداخت را وارد کنید.' );
		}
		if ( '' !== $date ) {
			$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
			if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date || $date > wp_date( 'Y-m-d' ) ) {
				return new WP_Error( 'date', 'تاریخ پرداخت معتبر نیست.' );
			}
		}
		return true;
	}

	public static function replacement( int $receipt_id, array $settings ) {
		$old = self::row( $receipt_id );
		if ( ! $old ) { return new WP_Error( 'receipt', 'رسید قبلی یافت نشد.' ); }
		$reference = isset( $_POST['reference'] ) ? wp_html_excerpt( ECM_Flow_Public::param( 'reference' ), 100, '' ) : $old->reference;
		$date = isset( $_POST['payment_date'] ) ? ECM_Flow_Public::param( 'payment_date' ) : (string) $old->payment_date;
		$file = (array) ( $_FILES['receipt'] ?? array() );
		if ( UPLOAD_ERR_NO_FILE !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return self::upload( $file, $reference, $date, $settings );
		}
		if ( $reference === $old->reference && $date === (string) $old->payment_date ) { return null; }
		$valid = self::validate_metadata( $reference, $date, $settings );
		if ( is_wp_error( $valid ) ) { return $valid; }
		global $wpdb;
		$table = ECM_DB::table( 'workflow_receipt_chunks' );
		$chunks = $wpdb->get_col( $wpdb->prepare( "SELECT content FROM {$table} WHERE receipt_id=%d ORDER BY chunk_no", $receipt_id ) );
		$bytes = implode( '', array_map( 'base64_decode', $chunks ) );
		if ( strlen( $bytes ) !== (int) $old->byte_size || ! hash_equals( $old->checksum, hash( 'sha256', $bytes ) ) ) {
			return new WP_Error( 'file', 'تصویر رسید قبلی خوانده نشد. دوباره تصویر را بارگذاری کنید.' );
		}
		return array( 'bytes' => $bytes, 'mime' => $old->mime, 'reference' => $reference, 'date' => $date ?: null );
	}

	public static function insert( int $registration_id, array $input ): int {
		global $wpdb;
		$bytes = (string) $input['bytes'];
		ECM_Flow::write( $wpdb->insert( ECM_DB::table( 'workflow_receipts' ), array(
			'registration_id' => $registration_id, 'mime' => $input['mime'], 'byte_size' => strlen( $bytes ),
			'checksum' => hash( 'sha256', $bytes ), 'reference' => $input['reference'], 'payment_date' => $input['date'],
			'status' => 'review', 'note' => '', 'created_at' => ECM_Utils::now_utc(),
		) ) );
		$id = (int) $wpdb->insert_id;
		foreach ( str_split( $bytes, 196608 ) as $index => $chunk ) {
			ECM_Flow::write( $wpdb->insert( ECM_DB::table( 'workflow_receipt_chunks' ), array(
				'receipt_id' => $id, 'chunk_no' => $index, 'content' => base64_encode( $chunk ),
			) ) );
		}
		return $id;
	}

	public static function review( int $id, string $status, string $note, string $now ): void {
		global $wpdb;
		ECM_Flow::write( $wpdb->update( ECM_DB::table( 'workflow_receipts' ), array(
			'status' => $status, 'note' => $note, 'reviewer' => get_current_user_id(), 'reviewed_at' => $now,
		), array( 'id' => $id ) ) );
	}

	public static function row( int $id ): ?object {
		global $wpdb;
		$table = ECM_DB::table( 'workflow_receipts' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ) ) ?: null;
	}

	public static function history( int $registration_id ): array {
		global $wpdb;
		$table = ECM_DB::table( 'workflow_receipts' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE registration_id=%d ORDER BY id DESC LIMIT 50", $registration_id ) );
	}

	public static function url( int $id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'ecm_flow_receipt', 'id' => $id ), admin_url( 'admin-post.php' ) ), 'ecm_flow_receipt_' . $id );
	}

	public static function serve(): void {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'ecm_flow_receipt_' . $id );
		$file = ECM_Flow::ready() ? self::row( $id ) : null;
		$flow = $file ? ECM_Flow::row( (int) $file->registration_id ) : null;
		if ( ! $flow || ( ! ECM_Flow_Admin::can_review( (int) $flow->event_id ) && ! ECM_Flow_Public::owns( (int) $flow->event_id, (int) $flow->registration_id ) ) ) {
			wp_die( 'رسید در دسترس نیست.', '', array( 'response' => 404 ) );
		}
		global $wpdb;
		$table = ECM_DB::table( 'workflow_receipt_chunks' );
		$chunks = $wpdb->get_col( $wpdb->prepare( "SELECT content FROM {$table} WHERE receipt_id=%d ORDER BY chunk_no", $id ) );
		$bytes = implode( '', array_map( 'base64_decode', $chunks ) );
		if ( strlen( $bytes ) !== (int) $file->byte_size || ! hash_equals( $file->checksum, hash( 'sha256', $bytes ) ) ) {
			wp_die( 'خواندن رسید ممکن نیست.', '', array( 'response' => 500 ) );
		}
		nocache_headers();
		header( 'Content-Type: ' . $file->mime );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: inline' );
		echo $bytes;
		exit;
	}
}
