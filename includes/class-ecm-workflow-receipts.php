<?php

defined( 'ABSPATH' ) || exit;

/** Never serve receipt images through WordPress's public media URLs. */
final class ECM_Workflow_Receipts {
	public static function private_root(): string|WP_Error {
		if ( ! defined( 'ECM_PRIVATE_RECEIPT_DIR' ) || ! is_string( ECM_PRIVATE_RECEIPT_DIR ) || '' === trim( ECM_PRIVATE_RECEIPT_DIR ) ) {
			return new WP_Error( 'ecm_flow_storage_not_configured', 'مسیر خصوصی رسیدها در wp-config.php تنظیم نشده است.' );
		}
		$root = rtrim( (string) ECM_PRIVATE_RECEIPT_DIR, '/\\' );
		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return new WP_Error( 'ecm_flow_storage_unavailable', 'ایجاد پوشه خصوصی رسیدها ممکن نشد.' );
		}
		$real = realpath( $root );
		if ( ! $real || ! is_writable( $real ) ) {
			return new WP_Error( 'ecm_flow_storage_unavailable', 'مسیر خصوصی رسیدها قابل نوشتن نیست.' );
		}
		foreach ( array( ABSPATH, WP_CONTENT_DIR, (string) ( $_SERVER['DOCUMENT_ROOT'] ?? '' ) ) as $public ) {
			$public_path = $public ? realpath( $public ) : false;
			if ( $public_path && ( $real === $public_path || str_starts_with( $real . DIRECTORY_SEPARATOR, rtrim( $public_path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) ) {
				return new WP_Error( 'ecm_flow_storage_public', 'مسیر رسیدها نباید داخل پوشه قابل دسترسی وب قرار داشته باشد.' );
			}
		}
		return $real;
	}

	public static function validate_upload( array $file ): array|WP_Error {
		if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'ecm_flow_file_missing', 'تصویر رسید دریافت نشد.' );
		}
		$tmp = (string) $file['tmp_name'];
		$size = (int) ( $file['size'] ?? 0 );
		if ( $size < 100 || $size > 8 * MB_IN_BYTES || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'ecm_flow_file_size', 'فایل معتبر نیست یا از سقف ۸ مگابایت بیشتر است.' );
		}
		$info = @getimagesize( $tmp );
		if ( ! $info || empty( $info[0] ) || empty( $info[1] ) || (int) $info[0] * (int) $info[1] > 40000000 ) {
			return new WP_Error( 'ecm_flow_file_image', 'تصویر نامعتبر یا بیش از حد بزرگ است.' );
		}
		if ( ! class_exists( 'finfo' ) ) {
			return new WP_Error( 'ecm_flow_fileinfo_missing', 'افزونه Fileinfo در PHP فعال نیست.' );
		}
		$finfo = new finfo( FILEINFO_MIME_TYPE );
		$mime = (string) $finfo->file( $tmp );
		$allowed = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );
		if ( ! isset( $allowed[ $mime ] ) || $mime !== (string) ( $info['mime'] ?? '' ) ) {
			return new WP_Error( 'ecm_flow_file_type', 'فقط تصویر JPEG، PNG و WebP پذیرفته می‌شود.' );
		}
		$hash = hash_file( 'sha256', $tmp );
		if ( ! $hash ) {
			return new WP_Error( 'ecm_flow_file_hash', 'محاسبه اثرانگشت فایل موفق نبود.' );
		}
		return array( 'tmp' => $tmp, 'mime' => $mime, 'size' => $size, 'ext' => $allowed[ $mime ], 'sha256' => $hash );
	}

	/** Run only after the caller has checked capability, nonce and DB state. */
	public static function move_private( array $validated ): array|WP_Error {
		$root = self::private_root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$name = bin2hex( random_bytes( 24 ) ) . '.' . $validated['ext'];
		$absolute = $root . DIRECTORY_SEPARATOR . $name;
		if ( ! move_uploaded_file( $validated['tmp'], $absolute ) ) {
			return new WP_Error( 'ecm_flow_file_move', 'ذخیره خصوصی تصویر موفق نبود.' );
		}
		if ( ! @chmod( $absolute, 0600 ) ) {
			@unlink( $absolute );
			return new WP_Error( 'ecm_flow_file_permissions', 'تنظیم سطح دسترسی خصوصی فایل ممکن نشد.' );
		}
		return array( 'reference' => $name, 'path' => $absolute );
	}
}
