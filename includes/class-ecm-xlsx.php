<?php

defined( 'ABSPATH' ) || exit;

/** A single-sheet workbook. User text is always stored as text, never as formulas. */
final class ECM_XLSX {
	public static function download( string $filename, iterable $rows ): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( 'ساخت XLSX روی این هاست فعال نیست. از خروجی CSV استفاده کنید.', '', array( 'response' => 503 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$target = wp_tempnam( 'ecm-workbook' );
		$sheet = wp_tempnam( 'ecm-sheet' );
		try {
			if ( ! $target || ! $sheet || ! ( $stream = fopen( $sheet, 'wb' ) ) ) {
				throw new RuntimeException( 'Temporary workbook unavailable.' );
			}
			$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
			$main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
			$rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
			$package = 'http://schemas.openxmlformats.org/package/2006/relationships';
			fwrite( $stream, $xml . '<worksheet xmlns="' . $main . '"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews><sheetData>' );
			$index = 0;
			foreach ( $rows as $row ) {
				if ( ++$index > 1048576 ) {
					throw new RuntimeException( 'Excel row limit exceeded.' );
				}
				fwrite( $stream, '<row r="' . $index . '">' );
				foreach ( array_values( $row ) as $offset => $value ) {
					$column = '';
					for ( $n = $offset + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
						$column = chr( 65 + ( $n - 1 ) % 26 ) . $column;
					}
					$value = preg_replace( '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value ) ?? '';
					$text = htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
					fwrite( $stream, '<c r="' . $column . $index . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>' );
				}
				fwrite( $stream, '</row>' );
			}
			fwrite( $stream, '</sheetData></worksheet>' );
			fclose( $stream );
			$zip = new ZipArchive();
			if ( true !== $zip->open( $target, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				throw new RuntimeException( 'Workbook ZIP unavailable.' );
			}
			$zip->addFromString( '[Content_Types].xml', $xml . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>' );
			$zip->addFromString( '_rels/.rels', $xml . '<Relationships xmlns="' . $package . '"><Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
			$zip->addFromString( 'xl/workbook.xml', $xml . '<workbook xmlns="' . $main . '" xmlns:r="' . $rel . '"><sheets><sheet name="ثبت‌نام" sheetId="1" r:id="rId1"/></sheets></workbook>' );
			$zip->addFromString( 'xl/_rels/workbook.xml.rels', $xml . '<Relationships xmlns="' . $package . '"><Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/></Relationships>' );
			$zip->addFile( $sheet, 'xl/worksheets/sheet1.xml' );
			if ( ! $zip->close() ) {
				throw new RuntimeException( 'Workbook ZIP could not be saved.' );
			}
			nocache_headers();
			header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '.xlsx"' );
			readfile( $target );
		} catch ( Throwable $error ) {
			status_header( 500 );
			echo 'ساخت فایل Excel انجام نشد.';
		} finally {
			if ( $sheet ) { @unlink( $sheet ); }
			if ( $target ) { @unlink( $target ); }
		}
		exit;
	}
}
