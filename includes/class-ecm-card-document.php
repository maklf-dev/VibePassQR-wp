<?php

defined( 'ABSPATH' ) || exit;

/** Standalone, pagination-safe card print document. */
final class ECM_Card_Document {
	public static function hooks(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), -4 );
		add_action( 'admin_post_nopriv_ecm_print_cards', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_ecm_print_cards', array( __CLASS__, 'render' ) );
	}

	public static function maybe_render(): void {
		if ( isset( $_GET['ecm_document'] ) && 'cards' === sanitize_key( wp_unslash( $_GET['ecm_document'] ) ) ) {
			self::render();
		}
	}

	public static function url( int $registration_id, int $participant_id = 0 ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'ecm_document'    => 'cards',
					'registration_id' => $registration_id,
					'participant_id'  => $participant_id,
				),
				ECM_Utils::page_url( 'card' )
			),
			'ecm_print_cards_' . $registration_id
		);
	}

	public static function render(): void {
		$id = absint( $_GET['registration_id'] ?? 0 );
		$participant_id = absint( $_GET['participant_id'] ?? 0 );
		check_admin_referer( 'ecm_print_cards_' . $id );
		$registration = ECM_DB::registration( $id );
		if ( ! ECM_Utils::registration_is_complete( $registration ) ) wp_die( 'کارت معتبر نیست.', 403 );
		$host_grant = ECM_Utils::has_card_grant( $registration );
		$requested = $participant_id ? ECM_DB::participant_identity( $participant_id ) : null;
		$guest_grant = $requested && (int) $requested->registration_id === $id && ECM_Utils::has_participant_card_grant( $requested );
		if ( ! $host_grant && ! $guest_grant ) wp_die( 'دسترسی به کارت منقضی شده است. کارت را دوباره بازیابی کنید.', 403 );
		$event = ECM_Events::registration_event( $registration );
		if ( ! ECM_Events::is_publicly_active( $event ) ) wp_die( 'این رویداد فعال نیست.', 403 );
		$config = ECM_Events::config( $event );
		$group_cards = 'group_all' === ( $config['card_access_mode'] ?? 'own_only' );
		if ( $participant_id ) {
			if ( ! $requested || (int) $requested->registration_id !== $id ) wp_die( 'کارت پیدا نشد.', 404 );
			if ( $host_grant && ! $guest_grant && 'guest' === (string) $requested->participant_type && ! $group_cards ) wp_die( 'معرف فقط اجازه مشاهده و چاپ کارت خودش را دارد.', 403 );
			$participants = array( $requested );
		} else {
			if ( ! $host_grant ) wp_die( 'فقط ثبت‌نام‌کننده اصلی می‌تواند همه کارت‌ها را چاپ کند.', 403 );
			$primary = ECM_DB::primary_participant( $id );
			$participants = $group_cards ? ECM_DB::participants_for_registration( $id ) : array_filter( array( $primary ? ECM_DB::participant_identity( (int) $primary->id ) : null ) );
		}
		$mode = in_array( $config['card_print_mode'], array( 'page', 'custom', 'qr' ), true ) ? $config['card_print_mode'] : 'page';
		$return_args = array( 'id' => $id );
		if ( ! $host_grant && $requested ) {
			$return_args['participant'] = (string) $requested->public_id;
			$return_args['scope']       = 'guest';
		}
		ECM_Utils::log( $id, $participant_id ? 'card_print' : 'card_print_all', array( 'participant_id' => $participant_id, 'document' => 'standalone' ) );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		?><!doctype html>
		<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( 'چاپ کارت‌های ' . $event->title ); ?></title>
		<style>
		*{box-sizing:border-box}html,body{margin:0;background:#e7ebf0;color:#111318;font-family:Tahoma,Arial,sans-serif}.toolbar{position:sticky;top:0;z-index:10;display:flex;align-items:center;justify-content:center;gap:10px;padding:12px;background:#10131a;color:#fff}.toolbar button,.toolbar a{min-height:42px;padding:8px 18px;border:1px solid #3a4352;border-radius:12px;background:#222836;color:#fff;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.toolbar button{background:#3a70a7}.print-document{width:210mm;margin:12px auto}.print-sheet{width:210mm;height:297mm;display:grid;place-items:center;padding:12mm;background:#fff;break-after:page;page-break-after:always;overflow:hidden}.print-sheet:last-child{break-after:auto;page-break-after:auto}.sheet-content{width:100%;max-width:120mm;text-align:center}.page-copy{margin:0 auto 8mm;line-height:1.9}.pass{position:relative;width:92mm;min-height:132mm;margin:auto;padding:8mm;overflow:hidden;border:1px solid #d5dce5;border-radius:8mm;background:linear-gradient(145deg,#fff,#e9edf4);box-shadow:0 8mm 18mm rgba(0,0,0,.12)}.pass:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 15% 0,rgba(58,112,167,.2),transparent 32%),radial-gradient(circle at 100% 100%,rgba(227,179,54,.15),transparent 35%)}.pass>*{position:relative}.pass-top{display:flex;justify-content:space-between;gap:6mm;margin-bottom:7mm;font-weight:900;text-align:right}.pass-top small{max-width:34%;font-size:9px;color:#69717d}.qr{width:64mm;margin:0 auto 5mm;padding:3mm;background:#fff;border-radius:5mm}.qr svg{display:block;width:100%;height:auto}.pass h2{margin:4mm 0 2mm;font-size:20px}.pass code{direction:ltr;letter-spacing:.12em;color:#5b6370}.instruction,.custom{margin-top:5mm;color:#4f5661;font-size:12px;line-height:1.8}.mode-custom .page-copy,.mode-custom .instruction{display:none}.mode-custom .custom{display:block}.mode-page .custom{display:none}.mode-qr .page-copy,.mode-qr .pass-top,.mode-qr .pass h2,.mode-qr .pass code,.mode-qr .instruction,.mode-qr .custom{display:none}.mode-qr .pass{width:96mm;min-height:96mm;display:grid;place-items:center;padding:10mm}.mode-qr .qr{width:76mm;margin:0}.page-number{position:absolute;bottom:7mm;left:9mm;color:#7a828e;font-size:9px;direction:ltr}@media(max-width:850px){.print-document{width:100%;margin:0}.print-sheet{width:100%;height:auto;min-height:100vh;padding:20px}.pass{max-width:100%}}@media print{html,body{background:#fff}.toolbar{display:none!important}.print-document{width:auto;margin:0}.print-sheet{margin:0;box-shadow:none}@page{size:A4 portrait;margin:0}}
		.print-sheet{position:relative}
		</style></head><body><div class="toolbar"><button type="button" onclick="window.print()">چاپ کارت</button><a href="<?php echo esc_url( ECM_Utils::page_url( 'card', $return_args ) ); ?>">بازگشت به کارت</a></div><main class="print-document mode-<?php echo esc_attr( $mode ); ?>">
		<?php foreach ( array_values( $participants ) as $index => $participant ) : $is_primary = 'primary' === (string) $participant->participant_type; $token = $is_primary ? ECM_Utils::qr_token( $registration ) : ECM_Utils::participant_qr_token( $participant ); $qr_url = ECM_Tickets::attendance_url( $participant ) ?: ECM_Utils::page_url( 'attendance', array( 'token' => rawurldecode( $token ) ) ); ?>
		<section class="print-sheet"><div class="sheet-content"><?php if ( 'page' === $mode ) : ?><div class="page-copy"><?php echo wp_kses_post( $config['success_text'] ); ?></div><?php endif; ?><article class="pass"><div class="pass-top"><span><?php echo esc_html( $event->title ); ?></span><small><?php echo esc_html( $is_primary ? 'معرف / ثبت‌نام‌کننده' : 'مهمان ' . (int) $participant->guest_order ); ?></small></div><div class="qr" data-ecm-qr="<?php echo esc_attr( $qr_url ); ?>"></div><h2><?php echo esc_html( $participant->full_name ?: 'شرکت‌کننده' ); ?></h2><code><?php echo esc_html( $participant->public_id ); ?></code><p class="instruction"><?php echo esc_html( $config['card_instruction'] ); ?></p><div class="custom"><?php echo wp_kses_post( $config['card_print_html'] ); ?></div></article></div><span class="page-number"><?php echo esc_html( ( $index + 1 ) . ' / ' . count( $participants ) ); ?></span></section>
		<?php endforeach; ?></main><script src="<?php echo esc_url( ECM_URL . 'assets/js/qr.js?ver=' . rawurlencode( ECM_VERSION ) ); ?>"></script><script>document.querySelectorAll('[data-ecm-qr]').forEach(function(n){try{n.innerHTML=window.ECMQRCodeSVG(n.dataset.ecmQr)}catch(e){n.textContent='QR ERROR'}});</script></body></html><?php
		exit;
	}
}
