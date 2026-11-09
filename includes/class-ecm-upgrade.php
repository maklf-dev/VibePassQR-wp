<?php
defined( 'ABSPATH' ) || exit;

final class ECM_Upgrade {
 public static function defaults(): array {
  return array('hide_finance'=>0,'payment_required'=>1,'canvas'=>1,'archive_pdf'=>0,'storage_folder'=>'event-card-manager','diagnostics_enabled'=>0,
   'brand_navy'=>'#0B1F46','brand_blue'=>'#3A70A7','brand_gold'=>'#E3B336','surface_ice'=>'#EAF3F8','surface_white'=>'#FFFFFF',
   'text_color'=>'#0B1F46','muted_color'=>'#405775','line_color'=>'#CEDBE5','success_color'=>'#166534','danger_color'=>'#B91C1C','warning_color'=>'#92400E',
   'content_width'=>1040,'registration_width'=>960,'recovery_width'=>820,'card_page_width'=>820,'operator_width'=>1120,'reports_width'=>1800,
   'body_size'=>17,'line_height'=>190,'heading_scale'=>100,'radius'=>16,'control_radius'=>10,'button_radius'=>10,'button_height'=>48,'page_padding'=>36,'section_gap'=>20,
   'card_width'=>390,'qr_size'=>245,'font_family'=>'inherit','shadow_mode'=>'none','motion_enabled'=>1,
   'custom_css'=>'','custom_css_registration'=>'','custom_css_recovery'=>'','custom_css_card'=>'','custom_css_attendance'=>'','custom_css_portal'=>'','custom_css_operator'=>'','custom_css_reports'=>'','custom_css_admin'=>'',
   'name_field'=>'full_name','card_instruction'=>'این کد را هنگام ورود ارائه کنید.', 'registration_title'=>'','registration_button'=>'دریافت کارت','attendance_button'=>'ثبت حضور','attendance_instruction'=>'برای ثبت حضور دکمه را بزنید.',
   'sms_field'=>'mobile','sms_sender'=>'','sms_enabled'=>0,'sms_delivery'=>'background','sms_transport'=>'edge','sms_ip_mode'=>'auto','sms_transport_paused'=>1,'sms_worker_batch'=>25,'sms_max_attempts'=>4,'sms_retry_delays'=>'5,10,15','sms_registration'=>0,'sms_attendance'=>0,'sms_recovery'=>0,'sms_repeat'=>0,'sms_outside'=>0,'sms_early'=>0,'sms_late'=>0,
   'sms_registration_text'=>"ثبت‌نام شما در {event_name} موفق بود.\nکد پیگیری: {tracking_code}\nکارت: {card_link}",
   'sms_attendance_text'=>"{name}، حضور شما در {session_name} در {time} ثبت شد.",
   'sms_recovery_text'=>'لینک بازیابی کارت: {card_link} — کد: {tracking_code}',
   'sms_repeat_text'=>'حضور این کارت در {session_name} قبلاً ثبت شده است.',
   'sms_outside_text'=>'ثبت حضور خارج از بازه مجاز است.',
   'sms_early_text'=>'زمان ثبت حضور {event_name} هنوز آغاز نشده است. نزدیک‌ترین نوبت: {session_name} در {session_time}.',
   'sms_late_text'=>'بازه ثبت حضور {event_name} پایان یافته است. آخرین نوبت: {session_name}.',
	   'sms_mode'=>'webservice','sms_patterns'=>array(),'sms_params'=>array(),'sms_pattern_profiles'=>array(),'sms_pattern_profile'=>array(),
	   'sms_state_mode'=>array(),'sms_audience_mode'=>array(),'sms_guest_mode'=>array(),'sms_guest_text'=>array(),'sms_guest_patterns'=>array(),'sms_guest_params'=>array(),'sms_guest_pattern_profile'=>array(), 'external_role_control'=>1);
 }
 public static function install(): void {
  global $wpdb;
  require_once ABSPATH.'wp-admin/includes/upgrade.php';
  $cc=$wpdb->get_charset_collate();
  dbDelta('CREATE TABLE '.ECM_DB::table('sms')." (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   registration_id bigint(20) unsigned NOT NULL,
	 participant_id bigint(20) unsigned NOT NULL DEFAULT 0,
	 event_id bigint(20) unsigned NOT NULL DEFAULT 0,
	 session_id bigint(20) unsigned NOT NULL DEFAULT 0,
	 notification_type varchar(60) NOT NULL DEFAULT '',
	 transport varchar(30) NOT NULL DEFAULT 'edge',
   event_key varchar(100) NOT NULL,
   recipient varchar(30) NOT NULL,
   message longtext NOT NULL,
   payload longtext NOT NULL,
   status varchar(30) NOT NULL DEFAULT 'queued',
   provider_id varchar(190) DEFAULT NULL,
   error_code varchar(190) DEFAULT NULL,
   http_code smallint(5) unsigned DEFAULT NULL,
   attempts smallint(5) unsigned NOT NULL DEFAULT 0,
   next_attempt_at datetime DEFAULT NULL,
   response_summary text DEFAULT NULL,
	 accepted_at datetime DEFAULT NULL,
   delivered_at datetime DEFAULT NULL,
   created_at datetime NOT NULL,
   updated_at datetime NOT NULL,
   PRIMARY KEY  (id),
   UNIQUE KEY event_key (event_key),
   KEY registration_id (registration_id),
	 KEY registration_created (registration_id,created_at),
	 KEY participant_id (participant_id),
	 KEY event_session_created (event_id,session_id,created_at),
	 KEY transport_status (transport,status),
	 KEY status_next_attempt (status,next_attempt_at)
  ) {$cc};");
  dbDelta('CREATE TABLE '.ECM_DB::table('sms_attempts')." (
	 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	 sms_id bigint(20) unsigned NOT NULL,
	 attempt_no smallint(5) unsigned NOT NULL DEFAULT 1,
	 transport varchar(30) NOT NULL,
	 status varchar(30) NOT NULL DEFAULT 'sending',
	 http_code smallint(5) unsigned DEFAULT NULL,
	 provider_id varchar(190) DEFAULT NULL,
	 error_code varchar(190) DEFAULT NULL,
	 response_summary text DEFAULT NULL,
	 latency_ms int(10) unsigned NOT NULL DEFAULT 0,
	 started_at datetime NOT NULL,
	 finished_at datetime DEFAULT NULL,
	 PRIMARY KEY  (id),
	 KEY sms_id (sms_id),
	 KEY transport_status_started (transport,status,started_at)
  ) {$cc};");
  dbDelta('CREATE TABLE '.ECM_DB::table('files')." (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   registration_id bigint(20) unsigned NOT NULL,
	   participant_id bigint(20) unsigned NOT NULL DEFAULT 0,
   relative_path varchar(255) NOT NULL,
   filename varchar(190) NOT NULL,
   bytes bigint(20) unsigned NOT NULL,
   created_at datetime NOT NULL,
   PRIMARY KEY  (id),
	 KEY participant_id (participant_id),
	 KEY registration_created (registration_id,created_at),
   KEY created_at (created_at)
  ) {$cc};");
  dbDelta('CREATE TABLE '.ECM_DB::table('links')." (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   registration_id bigint(20) unsigned NOT NULL,
	 participant_id bigint(20) unsigned NOT NULL DEFAULT 0,
   token_hash char(64) NOT NULL,
   expires_at datetime NOT NULL,
   used_at datetime DEFAULT NULL,
   PRIMARY KEY  (id),
   UNIQUE KEY token_hash (token_hash),
	 KEY participant_id (participant_id),
	 KEY expires_at (expires_at)
  ) {$cc};");
  dbDelta('CREATE TABLE '.ECM_DB::table('otp')." (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   challenge_hash char(64) NOT NULL,
   event_id bigint(20) unsigned NOT NULL DEFAULT 0,
   registration_id bigint(20) unsigned NOT NULL DEFAULT 0,
   participant_id bigint(20) unsigned NOT NULL DEFAULT 0,
   phone_hash char(64) NOT NULL DEFAULT '',
   recipient varchar(30) NOT NULL DEFAULT '',
   otp_hash varchar(255) NOT NULL DEFAULT '',
   browser_hash char(64) NOT NULL DEFAULT '',
   status varchar(20) NOT NULL DEFAULT 'active',
   attempts smallint(5) unsigned NOT NULL DEFAULT 0,
   send_count smallint(5) unsigned NOT NULL DEFAULT 1,
   expires_at datetime NOT NULL,
   resend_at datetime NOT NULL,
   verified_at datetime DEFAULT NULL,
   created_at datetime NOT NULL,
   updated_at datetime NOT NULL,
   PRIMARY KEY  (id),
   UNIQUE KEY challenge_hash (challenge_hash),
   KEY event_phone_created (event_id,phone_hash,created_at),
   KEY participant_id (participant_id),
   KEY status_expires (status,expires_at)
  ) {$cc};");
  ECM_Capabilities::install();
  self::migrate_settings();
  self::release_cleanup();
  self::pages();
 }

 public static function release_cleanup(): void {
  if(get_option('ecm_release_cleanup_030'))return;
  $settings=(array)get_option('ecm_settings',array());
  $settings['diagnostics_enabled']=0;
  $settings['sms_enabled']=0;
  $settings['sms_transport_paused']=1;
	$settings['payment_mode']='offline';
  update_option('ecm_settings',$settings,false);
	delete_option('ecm_sms_network_snapshot');
	ECM_Diagnostics::clear();

  global $wpdb;
  $table=ECM_DB::table('event_definitions');
  foreach($wpdb->get_results("SELECT id,config_json FROM {$table}") as $event){
   $config=json_decode((string)$event->config_json,true);
   if(!is_array($config)||'demo'!==($config['payment_mode']??''))continue;
   $config['payment_mode']='offline';
   $wpdb->update($table,array('config_json'=>wp_json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),array('id'=>(int)$event->id),array('%s'),array('%d'));
  }
	$sms_table=ECM_DB::table('sms');
	$wpdb->query("UPDATE {$sms_table} SET status='paused',error_code='transport_paused',next_attempt_at=NULL,updated_at=UTC_TIMESTAMP() WHERE status IN ('queued','retry_wait')"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
  update_option('ecm_release_cleanup_030',1,false);
 }

 public static function migrate_settings(): void {
  $stored=(array)get_option('ecm_settings',array());
  if(!array_key_exists('payment_required',$stored)){
   $stored['payment_required']=empty($stored['hide_finance'])?1:0;
  }
  $stored['hide_finance']=empty($stored['payment_required'])?1:0;
  if(!array_key_exists('diagnostics_enabled',$stored))$stored['diagnostics_enabled']=0;
  if(!array_key_exists('sms_delivery',$stored))$stored['sms_delivery']='background';
	if(!array_key_exists('sms_transport',$stored))$stored['sms_transport']='edge';
	if(!array_key_exists('sms_worker_batch',$stored))$stored['sms_worker_batch']=25;
	if(!array_key_exists('sms_max_attempts',$stored))$stored['sms_max_attempts']=4;
	if(!array_key_exists('sms_retry_delays',$stored))$stored['sms_retry_delays']='5,10,15';
  if(!array_key_exists('sms_ip_mode',$stored))$stored['sms_ip_mode']='auto';
  if(!array_key_exists('sms_transport_paused',$stored))$stored['sms_transport_paused']=1;
	if(!isset($stored['sms_pattern_profiles'])||!is_array($stored['sms_pattern_profiles']))$stored['sms_pattern_profiles']=array();
	$seed_payment_pattern=!get_option('ecm_sms_pattern_seed_040');
	if($seed_payment_pattern&&!isset($stored['sms_pattern_profiles']['registration_payment'])){
	 $stored['sms_pattern_profiles']['registration_payment']=array(
	  'label'=>'ثبت‌نام مالی — دوره فیزیک سلامت نسل Z',
	  'code'=>'is35i844i289qa9',
	  'params'=>array('name'=>'{name}','phone'=>'{phone}','pay'=>'{payment_reference}','date'=>'{payment_date}'),
	  'requires_payment'=>1,
	  'enabled'=>1,
	 );
	}
	if(!isset($stored['sms_pattern_profile'])||!is_array($stored['sms_pattern_profile']))$stored['sms_pattern_profile']=array();
		if(!isset($stored['sms_state_mode'])||!is_array($stored['sms_state_mode']))$stored['sms_state_mode']=array();
		if(!isset($stored['sms_audience_mode'])||!is_array($stored['sms_audience_mode']))$stored['sms_audience_mode']=array();
		if(!isset($stored['sms_guest_mode'])||!is_array($stored['sms_guest_mode']))$stored['sms_guest_mode']=array();
		if(!isset($stored['sms_guest_text'])||!is_array($stored['sms_guest_text']))$stored['sms_guest_text']=array();
		if(!isset($stored['sms_guest_patterns'])||!is_array($stored['sms_guest_patterns']))$stored['sms_guest_patterns']=array();
		if(!isset($stored['sms_guest_params'])||!is_array($stored['sms_guest_params']))$stored['sms_guest_params']=array();
		if(!isset($stored['sms_guest_pattern_profile'])||!is_array($stored['sms_guest_pattern_profile']))$stored['sms_guest_pattern_profile']=array();
		// 0.4.0 allowed choosing a pattern while the global mode stayed on text.
		// Preserve the administrator's intent by promoting those states to pattern mode.
		foreach((array)$stored['sms_pattern_profile'] as $state=>$profile_id){
		 if($profile_id && empty($stored['sms_state_mode'][$state]))$stored['sms_state_mode'][$state]='pattern';
		}
		foreach((array)($stored['sms_patterns']??array()) as $state=>$pattern_code){
		 if($pattern_code && empty($stored['sms_state_mode'][$state]))$stored['sms_state_mode'][$state]='pattern';
		}
	if(!get_option('ecm_dynamic_ticket_storage_050')){
	 $stored['archive_pdf']=0;
	 update_option('ecm_dynamic_ticket_storage_050',1,false);
	}
	$seed_otp_pattern=!get_option('ecm_sms_pattern_seed_060');
	if($seed_otp_pattern&&!isset($stored['sms_pattern_profiles']['recovery_otp'])){
	 $stored['sms_pattern_profiles']['recovery_otp']=array(
	  'label'=>'کد تأیید بازیابی کارت',
	  'code'=>'sqg5qg0uahtq43y',
	  'sender'=>'+983000505',
	  'purpose'=>'otp',
	  'params'=>array('code'=>'{code}'),
	  'param_types'=>array('code'=>'integer'),
	  'requires_payment'=>0,
	  'enabled'=>1,
	 );
	}
  update_option('ecm_settings',$stored,false);
	if($seed_payment_pattern)update_option('ecm_sms_pattern_seed_040',1,false);
	if($seed_otp_pattern)update_option('ecm_sms_pattern_seed_060',1,false);
 }
 public static function slugs(): array { return array('registration'=>'event-register','recovery'=>'event-recovery','card'=>'event-card','attendance'=>'event-attendance','manager'=>'event-reports','portal'=>'event-panel'); }
 public static function pages(): void {
  $pages=(array)get_option('ecm_pages',array()); $aliases=(array)get_option('ecm_old_paths',array());
  foreach(self::slugs() as $key=>$slug){
   $id=(int)($pages[$key]??0); $p=$id?get_post($id):null;
   if(!$p || 'page'!==$p->post_type) continue;
   $path=parse_url(get_permalink($id),PHP_URL_PATH);
   if($path) $aliases[$path]=$key;
   if(trim($p->post_content)==='[ecm_'.$key.']')update_post_meta($id,'_ecm_created',1);
   wp_update_post(array('ID'=>$id,'post_name'=>$slug));
  }
  // Covers legacy PDFs even after WordPress canonical URLs have changed.
  $aliases['/ثبت-حضور/']='attendance'; $aliases['/کارت-ورود/']='card';
  update_option('ecm_old_paths',$aliases,false);
 }
 public static function aliases(): void {
  $path=parse_url(wp_unslash($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
  if(!$path) return;
  $decoded=rawurldecode(rawurldecode($path));
  foreach((array)get_option('ecm_old_paths',array()) as $old=>$key){
   if(rtrim(rawurldecode(rawurldecode($old)),'/')!==rtrim($decoded,'/')) continue;
   $args=array();
   if(isset($_GET['token'])) $args['token']=sanitize_text_field(wp_unslash($_GET['token']));
   if(isset($_GET['id'])) $args['id']=absint($_GET['id']);
	 if(isset($_GET['participant'])) $args['participant']=sanitize_text_field(wp_unslash($_GET['participant']));
   $target=ECM_Utils::page_url($key,$args);
   if(rtrim(parse_url($target,PHP_URL_PATH),'/')!==rtrim($path,'/')){wp_safe_redirect($target,302);exit;}
  }
 }
 public static function encrypt(string $value): string {
  if(!function_exists('openssl_encrypt')) throw new RuntimeException('OpenSSL is required');
  $iv=random_bytes(12);$tag=''; $encrypted=openssl_encrypt($value,'aes-256-gcm',hash('sha256',wp_salt('secure_auth'),true),OPENSSL_RAW_DATA,$iv,$tag);
  if(false===$encrypted) throw new RuntimeException('Encryption failed');
  return base64_encode($iv.$tag.$encrypted);
 }
 public static function decrypt(string $value): string {
  $raw=base64_decode($value,true); if(!$raw||strlen($raw)<28||!function_exists('openssl_decrypt')) return '';
  $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',wp_salt('secure_auth'),true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
  return false===$plain?'':$plain;
 }
 public static function key(): string { return trim(defined('ECM_IPPANEL_API_KEY')?(string)ECM_IPPANEL_API_KEY:self::decrypt((string)get_option('ecm_sms_key',''))); }
 public static function key_source(): string {
  if(defined('ECM_IPPANEL_API_KEY') && ''!==(string)ECM_IPPANEL_API_KEY)return 'wp-config';
  return ''!==self::decrypt((string)get_option('ecm_sms_key',''))?'encrypted_database':'missing';
 }
	 public static function link(object $r, ?object $participant=null): string {
	  $target=$participant;
	  if(!$target)$target=ECM_DB::primary_participant((int)$r->id);
	  if($target && (int)$target->registration_id===(int)$r->id){
	   $short=ECM_Tickets::card_url($target);
	   if($short)return $short;
	  }
	  global $wpdb; $token=rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=');
  $participant_id=$participant && 'guest'===(string)$participant->participant_type && (int)$participant->registration_id===(int)$r->id?(int)$participant->id:0;
  $wpdb->insert(ECM_DB::table('links'),array('registration_id'=>$r->id,'participant_id'=>$participant_id,'token_hash'=>hash('sha256',$token),'expires_at'=>gmdate('Y-m-d H:i:s',time()+7*DAY_IN_SECONDS)),array('%d','%d','%s','%s'));
	  return add_query_arg('ecm_card',$token,home_url('/'));
 }
	 public static function short_link_redirect(): void {
	  if(empty($_GET['ecm_card']))return;
	  $token=sanitize_text_field(wp_unslash($_GET['ecm_card']));
	  if(!preg_match('/^[A-Za-z0-9_-]{22}$/',$token))return;
	  wp_safe_redirect(ECM_Utils::page_url('recovery',array('ticket'=>$token)),302);exit;
	 }
 public static function redeem_link(): void {
  if(empty($_GET['ticket']) || 'POST'!==($_SERVER['REQUEST_METHOD']??'')) return;
  $token=sanitize_text_field(wp_unslash($_GET['ticket']));
	  if(!preg_match('/^(?:[a-f0-9]{64}|[A-Za-z0-9_-]{22})$/',$token)) return;
  check_admin_referer('ecm_redeem','ecm_nonce');
  global $wpdb;$t=ECM_DB::table('links');$hash=hash('sha256',$token);
  $link=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE token_hash=%s AND used_at IS NULL AND expires_at>%s",$hash,ECM_Utils::now_utc()));
  if(!$link) return;
  $r=ECM_DB::registration((int)$link->registration_id); if(!ECM_Utils::registration_is_complete($r)) return;
  $updated=$wpdb->query($wpdb->prepare("UPDATE {$t} SET used_at=%s WHERE id=%d AND used_at IS NULL",ECM_Utils::now_utc(),$link->id));
  if(1!==$updated) return;
  $secret=wp_generate_password(40,false,false);$participant_id=absint($link->participant_id??0);
  if($participant_id){
   $participant=ECM_DB::participant($participant_id);
   if(!$participant || 'guest'!==(string)$participant->participant_type || (int)$participant->registration_id!==(int)$r->id){$wpdb->update($t,array('used_at'=>null),array('id'=>$link->id));return;}
   if(false===$wpdb->update(ECM_DB::table('participants'),array('access_hash'=>wp_hash_password($secret),'updated_at'=>ECM_Utils::now_utc()),array('id'=>$participant_id),array('%s','%s'),array('%d'))){$wpdb->update($t,array('used_at'=>null),array('id'=>$link->id));return;}
   ECM_Utils::set_participant_card_grant($participant_id,$secret);ECM_Utils::log((int)$r->id,'card_recovered',array('via'=>'sms_link','participant_id'=>$participant_id,'participant_type'=>'guest'));
   wp_safe_redirect(ECM_Utils::page_url('card',array('id'=>$r->id,'participant'=>$participant->public_id,'scope'=>'guest')));exit;
  }
  if(false===$wpdb->update(ECM_DB::table('registrations'),array('access_hash'=>wp_hash_password($secret)),array('id'=>$r->id),array('%s'),array('%d'))){$wpdb->update($t,array('used_at'=>null),array('id'=>$link->id));return;}
  ECM_Utils::set_card_grant((int)$r->id,$secret);ECM_Utils::log((int)$r->id,'card_recovered',array('via'=>'sms_link','participant_id'=>absint($r->primary_participant_id??0),'participant_type'=>'primary'));
  wp_safe_redirect(ECM_Utils::page_url('card',array('id'=>$r->id)));exit;
 }
 public static function canvas(string $template): string {
  $s=ECM_Utils::settings();$p=get_post();
  if(!$s['canvas'] || !$p || 'page'!==$p->post_type) return $template;
  foreach(array('registration','recovery','card','attendance','manager','portal') as $k) if(has_shortcode($p->post_content,'ecm_'.$k)) return ECM_PATH.'templates/canvas.php';
  return $template;
 }
 public static function style(): string {
  $s=ECM_Utils::settings();$css='';
  foreach(array('brand_navy'=>'navy','brand_blue'=>'blue','brand_gold'=>'gold','surface_ice'=>'ice','surface_white'=>'white','text_color'=>'text','muted_color'=>'muted','line_color'=>'line','success_color'=>'success','danger_color'=>'danger','warning_color'=>'warning') as $key=>$name) $css.='--ecm-'.$name.':'.sanitize_hex_color($s[$key]).';';
  foreach(array('content_width'=>'width','registration_width'=>'registration-width','recovery_width'=>'recovery-width','card_page_width'=>'card-page-width','operator_width'=>'operator-width','reports_width'=>'reports-width','body_size'=>'body','radius'=>'radius','control_radius'=>'control-radius','button_radius'=>'button-radius','button_height'=>'button-height','page_padding'=>'page-padding','section_gap'=>'section-gap','card_width'=>'card-width','qr_size'=>'qr-size') as $key=>$name) $css.='--ecm-'.$name.':'.absint($s[$key]).'px;';
  $fonts=array('inherit'=>'inherit','system'=>'-apple-system,BlinkMacSystemFont,"Segoe UI",Tahoma,Arial,sans-serif','tahoma'=>'Tahoma,Arial,sans-serif');
  $shadows=array('none'=>'none','soft'=>'0 12px 36px rgba(11,31,70,.09)','strong'=>'0 24px 70px rgba(11,31,70,.18)');
  $css.='--ecm-line-height:'.(min(220,max(150,absint($s['line_height'])))/100).';';
  $css.='--ecm-heading-scale:'.(min(130,max(80,absint($s['heading_scale'])))/100).';';
  $css.='--ecm-font:'.($fonts[$s['font_family']]??$fonts['inherit']).';';
  $css.='--ecm-shadow:'.($shadows[$s['shadow_mode']]??'none').';';
  $css.='--ecm-motion:'.(!empty($s['motion_enabled'])?'1':'0').';--ecm-transition:'.(!empty($s['motion_enabled'])?'.16s':'0s').';';
  return '.ecm-canvas,.ecm-shell,.ecm-admin,.ecm-report{'.$css.'}';
 }

 public static function custom_style(string $area='public'): string {
  $s=ECM_Utils::settings();
  if('admin'===$area)return (string)($s['custom_css_admin']??'');
  $keys=array('custom_css','custom_css_registration','custom_css_recovery','custom_css_card','custom_css_attendance','custom_css_portal','custom_css_operator','custom_css_reports');
  $css='';foreach($keys as $key)$css.="\n".(string)($s[$key]??'');
  return trim($css);
 }
}
