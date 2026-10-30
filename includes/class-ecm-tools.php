<?php
defined('ABSPATH') || exit;
final class ECM_Tools {
 public static function hooks(): void {
  // The parent menu is registered at the default priority. Registering this
  // submenu later avoids an orphaned admin page on stricter installations.
  add_action('admin_menu',static function(){if(ECM_Capabilities::can_use_tools())add_submenu_page('ecm-dashboard','نگهداری و عیب‌یابی','نگهداری و عیب‌یابی','access_ecm','ecm-tools',array(__CLASS__,'page'));},30);
  add_action('template_redirect',array(__CLASS__,'redirect_legacy_path'),-20);
  add_action('admin_post_ecm_maintenance',array(__CLASS__,'maintenance'));
  add_action('admin_post_ecm_report_document',array(__CLASS__,'report_document'));
 }
 public static function redirect_legacy_path(): void {
  $request_path=(string)wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
  $legacy_path=(string)wp_parse_url(admin_url('ecm-tools'),PHP_URL_PATH);
  if(rtrim(rawurldecode($request_path),'/')!==rtrim(rawurldecode($legacy_path),'/'))return;
  if(!is_user_logged_in()){auth_redirect();exit;}
  if(!ECM_Capabilities::can_use_tools())wp_die('شما اجازه دسترسی به ابزارهای افزونه را ندارید.',403);
  wp_safe_redirect(admin_url('admin.php?page=ecm-tools'));exit;
 }
 public static function validate_sessions(array $rows): void {
  $windows=array();
  foreach($rows as $row){
   $event_id=absint($row['event_id']??ECM_Events::default_event_id());$start=ECM_Utils::local_to_utc(sanitize_text_field($row['start']??''));$end=ECM_Utils::local_to_utc(sanitize_text_field($row['end']??''));
   if(!ECM_Events::event($event_id)||empty($row['title'])||!$start||!$end||$start>=$end)wp_die('نوبت حضور معتبر نیست. رویداد، عنوان و شروع/پایان را بررسی کنید؛ تاریخ ورودی باید میلادی باشد. هیچ نوبتی حذف نشده است.');
   if(!empty($row['enabled'])) $windows[$event_id][]=array($start,$end);
  }
  foreach($windows as $event_windows){usort($event_windows,static fn($a,$b)=>strcmp($a[0],$b[0]));
   for($i=1;$i<count($event_windows);$i++) if($event_windows[$i][0]<=$event_windows[$i-1][1])wp_die('نوبت‌های فعال یک رویداد هم‌پوشانی دارند. برای جلوگیری از ثبت در جلسه اشتباه، بازه‌ها را از هم جدا کنید.');
  }
 }
 public static function settings_markup(array $s): void {
  echo '<section class="ecm-panel ecm-upgrade-settings" data-panel="appearance"><div class="ecm-panel__title"><div><h2>ظاهر و نمایش مشترک</h2><p>رنگ، اندازه، فاصله و عرض هر فضای کاری بدون دست‌کاری فایل‌های قالب قابل تنظیم است.</p></div></div>';
  echo '<div class="ecm-appearance-layout"><div><div class="ecm-subpanel"><div class="ecm-panel__title"><div><h3>پالت و وضعیت‌ها</h3><p>پریست فقط مقدار رنگ‌ها را در فرم قرار می‌دهد؛ برای ثبت باید دکمه ذخیره را بزنید.</p></div><div class="ecm-preset-actions"><button type="button" class="button" data-appearance-preset="light">روشن سرمه‌ای</button><button type="button" class="button" data-appearance-preset="dark">تیره تلگرام</button></div></div><div class="ecm-admin-grid ecm-admin-grid--4">';
  foreach(array('brand_navy'=>'سرمه‌ای برند','brand_blue'=>'آبی تأکیدی','brand_gold'=>'طلایی دکمه','surface_ice'=>'زمینه صفحه','surface_white'=>'سطح کارت','text_color'=>'متن اصلی','muted_color'=>'متن کم‌رنگ','line_color'=>'خط و کادر','success_color'=>'موفقیت','danger_color'=>'خطا','warning_color'=>'هشدار') as $key=>$label) self::input($key,$label,$s[$key],'color');
  echo '</div></div>';
  echo '<div class="ecm-subpanel"><h3>تایپوگرافی و اجزای رابط</h3><div class="ecm-admin-grid ecm-admin-grid--4">';
  echo '<label class="ecm-setting"><span>فونت رابط</span><select name="settings[font_family]"><option value="inherit" '.selected($s['font_family'],'inherit',false).'>فونت قالب / ارث‌بری</option><option value="system" '.selected($s['font_family'],'system',false).'>فونت سیستمی</option><option value="tahoma" '.selected($s['font_family'],'tahoma',false).'>Tahoma</option></select></label>';
  echo '<label class="ecm-setting"><span>سایه کارت‌ها</span><select name="settings[shadow_mode]"><option value="none" '.selected($s['shadow_mode'],'none',false).'>بدون سایه</option><option value="soft" '.selected($s['shadow_mode'],'soft',false).'>ملایم</option><option value="strong" '.selected($s['shadow_mode'],'strong',false).'>پررنگ</option></select></label>';
  foreach(array('body_size'=>'اندازه متن (px)','line_height'=>'ارتفاع خط (٪)','heading_scale'=>'مقیاس تیتر (٪)','radius'=>'گردی کارت‌ها (px)','control_radius'=>'گردی ورودی‌ها (px)','button_radius'=>'گردی دکمه‌ها (px)','button_height'=>'ارتفاع دکمه‌ها (px)','page_padding'=>'فاصله داخلی صفحه (px)','section_gap'=>'فاصله بخش‌ها (px)','card_width'=>'عرض کارت (px)','qr_size'=>'اندازه QR (px)') as $key=>$label) self::input($key,$label,$s[$key],'number');
  echo '</div><div class="ecm-check-grid">';self::check('motion_enabled','حرکت‌های ظریف رابط فعال باشد',$s['motion_enabled']);echo '</div></div>';
  echo '<div class="ecm-subpanel"><h3>عرض مستقل صفحات</h3><div class="ecm-admin-grid ecm-admin-grid--4">';
  foreach(array('content_width'=>'عرض عمومی','registration_width'=>'ثبت‌نام','recovery_width'=>'بازیابی','card_page_width'=>'نمایش کارت','operator_width'=>'پنل ثبت حضور','reports_width'=>'گزارش‌ها') as $key=>$label) self::input($key,$label,$s[$key],'number');
  echo '</div></div>';
	$shared_options=array('canvas'=>'صفحات تمام‌صفحه بدون هدر و فوتر','external_role_control'=>'مدیریت capabilityها توسط User Role Editor');
	if(current_user_can('manage_ecm_diagnostics'))$shared_options['diagnostics_enabled']='حالت پشتیبانی و ثبت لاگ کم‌حجم';
  echo '<div class="ecm-subpanel"><h3>رفتار مشترک و فایل‌ها</h3><div class="ecm-check-grid">';foreach($shared_options as $key=>$label) self::check($key,$label,$s[$key]);echo '</div><div class="ecm-admin-grid ecm-admin-grid--2">';
	  self::input('storage_folder','پوشه آرشیو زیر uploads',$s['storage_folder']);
  echo '</div></div></div>';
  echo '<aside class="ecm-appearance-preview" data-appearance-preview><span>پیش‌نمایش زنده</span><article><small>EVENT CARD</small><h3>نمونه رابط رویداد</h3><p>رنگ‌ها، فاصله‌ها، ورودی و دکمه را پیش از ذخیره بررسی کنید.</p><label>شماره موبایل<input type="text" value="09120000000" readonly></label><button type="button">دریافت کارت</button></article></aside></div>';
  echo '<div class="ecm-subpanel"><h3>CSS پیشرفته و اختصاصی</h3><p>این بخش برای تنظیمات تکمیلی توسعه‌دهنده است. برای هر قسمت از selector همان بخش استفاده کنید.</p>';
  $css_areas=array('custom_css'=>'عمومی صفحات افزونه','custom_css_registration'=>'فقط ثبت‌نام (.ecm-registration)','custom_css_recovery'=>'فقط بازیابی (.ecm-recovery)','custom_css_card'=>'فقط کارت (.ecm-card-shell)','custom_css_attendance'=>'فقط حضور عمومی (.ecm-attendance)','custom_css_portal'=>'فقط پنل نقش‌ها (.ecm-portal-shell)','custom_css_operator'=>'فقط اسکنر اپراتور (.ecm-operator-shell)','custom_css_reports'=>'فقط گزارش‌ها (.ecm-reports-v2)','custom_css_admin'=>'پیشخوان افزونه (.ecm-admin)');
  foreach($css_areas as $key=>$label)echo '<details class="ecm-css-area"><summary>'.esc_html($label).'</summary><label class="ecm-setting"><textarea rows="5" name="settings['.esc_attr($key).']" dir="ltr" spellcheck="false">'.esc_textarea($s[$key]??'').'</textarea></label></details>';
  echo '</div></section>';
 }
 private static function input(string $key,string $label,$value,string $type='text'): void {echo '<label class="ecm-setting"><span>'.esc_html($label).'</span><input type="'.esc_attr($type).'" name="settings['.esc_attr($key).']" value="'.esc_attr($value).'"></label>';}
 private static function check(string $key,string $label,$value): void {echo '<label><input type="checkbox" name="settings['.esc_attr($key).']" value="1" '.checked(!empty($value),true,false).'> '.esc_html($label).'</label>';}
 public static function sanitize_settings(array $new,array $raw): array {
  foreach(array('canvas','external_role_control','motion_enabled') as $key)$new[$key]=empty($raw[$key])?0:1;
	$new['archive_pdf']=0;
  if(current_user_can('manage_ecm_diagnostics'))$new['diagnostics_enabled']=empty($raw['diagnostics_enabled'])?0:1;
  foreach(array('brand_navy','brand_blue','brand_gold','surface_ice','surface_white','text_color','muted_color','line_color','success_color','danger_color','warning_color') as $key)$new[$key]=sanitize_hex_color($raw[$key]??'')?:ECM_Upgrade::defaults()[$key];
  $ranges=array('content_width'=>array(360,1600),'registration_width'=>array(360,1400),'recovery_width'=>array(360,1200),'card_page_width'=>array(360,1200),'operator_width'=>array(360,1600),'reports_width'=>array(900,2400),'body_size'=>array(15,24),'line_height'=>array(150,220),'heading_scale'=>array(80,130),'radius'=>array(0,36),'control_radius'=>array(0,28),'button_radius'=>array(0,32),'button_height'=>array(40,64),'page_padding'=>array(12,64),'section_gap'=>array(8,48),'card_width'=>array(280,600),'qr_size'=>array(180,420));
  foreach($ranges as $key=>$range)$new[$key]=min($range[1],max($range[0],absint($raw[$key]??$new[$key])));
  $new['font_family']=in_array(($raw['font_family']??''),array('inherit','system','tahoma'),true)?$raw['font_family']:'inherit';
  $new['shadow_mode']=in_array(($raw['shadow_mode']??''),array('none','soft','strong'),true)?$raw['shadow_mode']:'none';
  foreach(array('name_field','card_instruction','registration_title','registration_button','attendance_button','attendance_instruction') as $key)$new[$key]=sanitize_text_field($raw[$key]??$new[$key]);
  $folder=sanitize_key($raw['storage_folder']??$new['storage_folder']);$new['storage_folder']=$folder?:'event-card-manager';
  foreach(array('custom_css','custom_css_registration','custom_css_recovery','custom_css_card','custom_css_attendance','custom_css_portal','custom_css_operator','custom_css_reports','custom_css_admin') as $key)$new[$key]=self::sanitize_css((string)($raw[$key]??''));
  return $new;
 }
 private static function sanitize_css(string $css): string {
  $css=wp_strip_all_tags($css);$css=preg_replace('/@(?:import|charset|namespace)\b[^;]*;?/i','',$css);
  $css=preg_replace('/url\s*\([^)]*\)/i','',$css);$css=str_replace(array('</','<!--','-->'),'',(string)$css);
  return function_exists('mb_substr')?mb_substr($css,0,30000):substr($css,0,30000);
 }
 public static function storage(string $folder=''): string {
  $u=wp_upload_dir();$folder=sanitize_key($folder?:ECM_Utils::settings()['storage_folder']);
  $root=trailingslashit($u['basedir']).($folder?:'event-card-manager');
  if(!wp_mkdir_p($root))throw new RuntimeException('Storage not writable');
  if(!file_exists($root.'/index.php'))file_put_contents($root.'/index.php',"<?php http_response_code(403); exit;");
  if(!file_exists($root.'/.htaccess'))file_put_contents($root.'/.htaccess',"Require all denied\n");
  // PDFs remain encrypted even if an nginx host ignores .htaccess.
  return $root;
 }
 private static function file_path(object $row): string {
  $rel=(string)$row->relative_path;if(!preg_match('/^[a-z0-9_-]+\/[a-f0-9]{32}\.ecm$/',$rel))return '';
  $base=wp_upload_dir()['basedir'];$path=$base.'/'.$rel;$real=realpath($path);$root=realpath($base);
  return $real&&$root&&str_starts_with($real,trailingslashit($root))?$real:'';
 }
 private static function maintenance_form(string $op,string $label,string $cap,string $extra=''): void {
  if(!current_user_can($cap))return;echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="ecm-subpanel ecm-maintenance-form"><input type="hidden" name="action" value="ecm_maintenance"><input type="hidden" name="operation" value="'.esc_attr($op).'">';wp_nonce_field('ecm_maintenance');echo $extra.'<button class="button button-primary">'.esc_html($label).'</button></form>';
 }
 private static function disk_info(): array {
  global $wpdb;$t=ECM_DB::table('files');$folders=$wpdb->get_col("SELECT DISTINCT SUBSTRING_INDEX(relative_path,'/',1) FROM {$t}");$folders[]=ECM_Utils::settings()['storage_folder'];$base=wp_upload_dir()['basedir'];$bytes=0;$count=0;
  foreach(array_unique($folders) as $folder){if(!preg_match('/^[a-z0-9_-]+$/',$folder))continue;$dir=$base.'/'.$folder;if(!is_dir($dir))continue;
   foreach(scandir($dir)?:array() as $name){if(preg_match('/^[a-f0-9]{32}\.ecm$/',$name)&&is_file($dir.'/'.$name)){$bytes+=filesize($dir.'/'.$name);$count++;}}
  }return array($count,$bytes);
 }
	 public static function page(): void {
  if(!ECM_Capabilities::can_use_tools())wp_die('Forbidden',403);
	  ECM_Activity::flush_page();
  global $wpdb;$t=ECM_DB::table('files');$rows=$wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC LIMIT 100");$totals=$wpdb->get_row("SELECT COUNT(*) AS total,COALESCE(SUM(bytes),0) AS bytes FROM {$t}");$network=ECM_SMS::diagnostic_snapshot();$sms_settings=ECM_Utils::settings();$migration=ECM_Events::migration_health();
	  $activity=ECM_Activity::status();$disk=self::disk_info();$sms_transport=ECM_SMS_Transports::get((string)($sms_settings['sms_transport']??'edge'));echo '<div class="wrap ecm-admin" dir="rtl"><div class="ecm-admin__header"><div><span>MAINTENANCE & DIAGNOSTICS</span><h1>نگهداری و عیب‌یابی</h1><p>سلامت سیستم، برگه‌ها، گزارش پشتیبانی و آرشیو فایل‌ها.</p></div></div>';
	  if(isset($_GET['done']))echo '<div class="notice notice-success"><p>عملیات انجام شد.</p></div>';
	  $reset_result=get_transient('ecm_reset_result_'.get_current_user_id());
	  if(is_array($reset_result)){delete_transient('ecm_reset_result_'.get_current_user_id());echo '<div class="ecm-reset-summary"><div><span>ثبت‌نام حذف‌شده</span><strong>'.esc_html(number_format_i18n((int)($reset_result['registrations']??0))).'</strong></div><div><span>شرکت‌کننده حذف‌شده</span><strong>'.esc_html(number_format_i18n((int)($reset_result['participants']??0))).'</strong></div><div><span>حضور حذف‌شده</span><strong>'.esc_html(number_format_i18n((int)($reset_result['attendance']??0))).'</strong></div><div><span>پیامک حذف‌شده</span><strong>'.esc_html(number_format_i18n((int)($reset_result['sms']??0))).'</strong></div><div><span>فایل حذف‌شده</span><strong>'.esc_html(number_format_i18n((int)($reset_result['files']??0))).'</strong></div></div>';}
	  echo '<div class="ecm-health-grid"><div><span>نسخه</span><strong>'.esc_html(ECM_VERSION).'</strong></div><div><span>منطقه زمانی</span><strong>'.esc_html(wp_timezone_string()).'</strong></div><div><span>HTTPS</span><strong>'.(is_ssl()?'فعال':'غیرفعال').'</strong></div><div><span>OpenSSL</span><strong>'.(function_exists('openssl_encrypt')?'فعال':'غیرفعال').'</strong></div><div><span>WP-Cron</span><strong>'.(defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON?'خاموش':'فعال').'</strong></div><div><span>درگاه پیامک</span><strong>'.esc_html($sms_transport?$sms_transport->label():'نامعتبر').'</strong></div><div><span>ارسال پیامک</span><strong>'.(!empty($sms_settings['sms_transport_paused'])?'متوقف‌شده':'در حال اجرا').'</strong></div><div><span>Worker پیامک</span><strong>هر دقیقه / '.esc_html(absint($sms_settings['sms_worker_batch']??25)).' رکورد</strong></div><div><span>فایل‌های کارت قدیمی</span><strong>'.esc_html($disk[0]).' / '.esc_html(size_format($disk[1])).'</strong></div><div><span>لاگ تشخیصی</span><strong>'.esc_html(size_format(ECM_Diagnostics::size())).'</strong></div><div><span>گزارش فعالیت امروز</span><strong>'.esc_html(size_format((int)$activity['size'])).'</strong></div><div><span>رویداد پیش‌فرض</span><strong>#'.esc_html($migration['default_event_id']).'</strong></div><div><span>رویدادها / غیرفعال</span><strong>'.esc_html($migration['events']).' / '.esc_html($migration['inactive_events']).'</strong></div><div><span>تنظیمات رویدادها</span><strong>'.($migration['events_without_config']?'نیازمند مهاجرت: '.esc_html($migration['events_without_config']):'کامل').'</strong></div><div><span>مهاجرت شرکت‌کنندگان</span><strong>'.($migration['pending_registrations']?'در انتظار: '.esc_html($migration['pending_registrations']):'کامل').'</strong></div><div><span>اشخاص / شرکت‌کنندگان</span><strong>'.esc_html($migration['people']).' / '.esc_html($migration['participants']).'</strong></div><div><span>مهمانان</span><strong>'.esc_html($migration['guests']??0).'</strong></div></div>';
  if($network)echo '<div class="ecm-callout '.(!empty($network['success'])?'ecm-callout--success':'ecm-callout--error').'"><strong>آخرین نتیجه شبکه پیامک</strong><p>'.esc_html($network['verdict']??'نتیجه‌ای ثبت نشده است.').' — '.esc_html(ECM_Utils::utc_to_local($network['generated_at_utc']??ECM_Utils::now_utc())).'</p><a class="button" href="'.esc_url(admin_url('admin.php?page=ecm-sms')).'">مشاهده جزئیات و گزارش پشتیبانی</a></div>';
  echo '<section class="ecm-panel is-active"><div class="ecm-panel__title"><div><h2>وضعیت برگه‌ها</h2><p>حذف و بازسازی دو عملیات کاملاً مستقل هستند. هیچ رکورد ثبت‌نام یا حضور با این دو دکمه حذف نمی‌شود.</p></div></div><div class="ecm-page-status">';
  $page_ids=(array)get_option('ecm_pages',array());foreach(ECM_Upgrade::slugs() as $key=>$slug){$id=(int)($page_ids[$key]??0);echo '<div><code>'.esc_html($slug).'</code><span>'.esc_html($id?(get_post_status($id)?:'missing'):'not-created').'</span><small>ID: '.esc_html($id?:'—').'</small></div>';}echo '</div><div class="ecm-admin-grid ecm-admin-grid--2">';
  self::maintenance_form('rebuild_pages','بازسازی برگه‌های مفقود','repair_ecm_pages','<p>فقط برگه‌های مفقود را با URL انگلیسی و شورت‌کد صحیح می‌سازد.</p>');
  self::maintenance_form('delete_pages','حذف کامل برگه‌های ساخته‌شده توسط افزونه','repair_ecm_pages','<p><strong>برگه‌های متعلق به افزونه برای همیشه حذف می‌شوند.</strong> برگه‌هایی که خودتان انتخاب کرده‌اید حذف نمی‌شوند.</p><label class="ecm-setting"><span>عبارت تأیید</span><input name="confirm" placeholder="حذف کامل برگه‌های افزونه" required></label>');
  echo '</div></section>';
	  if(current_user_can('manage_ecm_diagnostics')){echo '<section class="ecm-panel is-active"><div class="ecm-panel__title"><div><h2>گزارش پشتیبانی</h2><p>ثبت لاگ پیش‌فرض خاموش است. در صورت نیاز پشتیبانی، آن را موقتاً از تنظیمات ظاهر و نمایش فعال کنید. کلید API، متن پیامک، شماره کامل و اطلاعات فرم ثبت نمی‌شوند.</p></div><a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=ecm_download_diagnostics'),'ecm_download_diagnostics')).'">دانلود گزارش JSON</a></div>';
	  self::maintenance_form('clear_diagnostics','پاک‌کردن لاگ پشتیبانی','manage_ecm_diagnostics','<p>پس از پایان بررسی می‌توانید همه فایل‌های لاگ را پاک کنید.</p><label class="ecm-setting"><span>عبارت تأیید</span><input name="confirm" placeholder="پاک کردن لاگ" required></label>');echo '</section>';}
	  if(current_user_can('manage_ecm_diagnostics')){echo '<section class="ecm-panel is-active"><div class="ecm-panel__title"><div><h2>گزارش روزانه فعالیت افزونه</h2><p>صفحات، درخواست‌های فرم و AJAX، عملیات، سرعت، حافظه، تعداد کوئری و خطاهای Event Card Manager به‌صورت تجمیعی ثبت می‌شوند. IP، موبایل، QR، کد بلیت، متن پیامک و اطلاعات فرم در فایل قرار نمی‌گیرد.</p></div>'.(!empty($activity['exists'])?'<a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=ecm_download_activity'),'ecm_download_activity')).'">دانلود گزارش امروز</a>':'<span class="button disabled" aria-disabled="true">هنوز گزارشی ساخته نشده</span>').'</div><div class="ecm-health-grid"><div><span>تاریخ گزارش</span><strong>'.esc_html($activity['date']).'</strong></div><div><span>کل فعالیت</span><strong>'.esc_html(number_format_i18n((int)$activity['activities'])).'</strong></div><div><span>بازدید صفحات افزونه</span><strong>'.esc_html(number_format_i18n((int)$activity['page_views'])).'</strong></div><div><span>درخواست فرم</span><strong>'.esc_html(number_format_i18n((int)($activity['form_requests']??0))).'</strong></div><div><span>درخواست AJAX</span><strong>'.esc_html(number_format_i18n((int)($activity['ajax_requests']??0))).'</strong></div><div><span>عملیات ثبت‌شده</span><strong>'.esc_html(number_format_i18n((int)$activity['operations'])).'</strong></div><div><span>نشانه‌های خطا</span><strong>'.esc_html(number_format_i18n((int)($activity['errors']??0))).'</strong></div><div><span>حجم فایل</span><strong>'.esc_html(size_format((int)$activity['size'])).'</strong></div><div><span>آخرین فعالیت</span><strong>'.esc_html($activity['last_activity']?ECM_Utils::utc_to_local($activity['last_activity'],'Y/m/d H:i:s'):'—').'</strong></div></div>';
	  self::maintenance_form('clear_activity_report','حذف گزارش امروز','manage_ecm_diagnostics','<p>فقط فایل تجمیعی امروز حذف می‌شود؛ ثبت‌نام، حضور، گزارش اصلی و تنظیمات تغییری نمی‌کنند. با نخستین فعالیت بعدی افزونه، فایل تازه ساخته می‌شود.</p><label class="ecm-setting"><span>عبارت تأیید</span><input name="confirm" placeholder="حذف گزارش روزانه" required></label>');echo '</section>';}
  if(current_user_can('manage_ecm_files')){echo '<section class="ecm-panel is-active"><div class="ecm-panel__title"><div><h2>فایل‌ها و تاریخچه</h2><p>فایل‌ها در دیتابیس: '.esc_html($totals->total).' — فضای ثبت‌شده: '.esc_html(size_format((int)$totals->bytes)).' — مسیر: <code>'.esc_html(trailingslashit(wp_upload_dir()['basedir']).ECM_Utils::settings()['storage_folder']).'</code></p></div></div>';
  self::maintenance_form('migrate_files','انتقال آرشیوهای قبلی به پوشه فعلی','manage_ecm_files','<p>پس از تغییر پوشه در تنظیمات، فایل‌های ثبت‌شده قبلی با این دکمه منتقل می‌شوند.</p>');
  $dates='<div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>از تاریخ</span><input type="date" name="from" required></label><label class="ecm-setting"><span>تا تاریخ</span><input type="date" name="to" required></label></div>';
  self::maintenance_form('backup','دانلود ZIP تاریخچه و PDFها','manage_ecm_files','<p>کل سوابق داده، رویدادها و پیامک‌ها و PDFها؛ شامل اطلاعات شخصی است، محرمانه نگهداری کنید. API Key صادر نمی‌شود.</p>');
  echo '<div class="ecm-admin-grid ecm-admin-grid--2">';
  self::maintenance_form('delete_history_range','حذف سابقه در بازه انتخابی','manage_ecm_files',$dates.'<p><strong>ثبت‌نام‌ها، کارت‌ها، حضورها، پیامک‌ها و لاگ‌های عملیاتی این بازه حذف می‌شوند.</strong> تعریف رویداد و نوبت‌ها باقی می‌ماند. ابتدا ZIP پشتیبان بگیرید.</p><label class="ecm-setting"><span>عبارت تأیید</span><input name="confirm" placeholder="حذف سابقه بازه" required></label>');
  self::maintenance_form('reset_history','ریست کامل سابقه افزونه','manage_ecm_files','<p><strong>تمام داده‌های عملیاتی همه رویدادها حذف می‌شوند:</strong> ثبت‌نام، شرکت‌کننده، کارت آرشیوی، حضور، پیامک و لاگ رویداد. تعریف رویدادها، نوبت‌ها، تنظیمات، برگه‌ها و نقش‌ها حفظ می‌شوند.</p><label class="ecm-setting"><span>عبارت تأیید دقیق</span><input name="confirm" placeholder="ریست کامل سابقه افزونه" required></label>');
  echo '</div>';
  echo '<h3>آخرین فایل‌ها</h3><div class="ecm-table-scroll"><table class="widefat ecm-admin-table"><tr><th>فایل</th><th>اندازه</th><th>زمان</th></tr>';foreach($rows as $row)echo '<tr><td>'.esc_html($row->filename).'</td><td>'.esc_html(size_format((int)$row->bytes)).'</td><td>'.esc_html(ECM_Utils::utc_to_local($row->created_at)).'</td></tr>';echo '</table></div></section>';}
  echo '<section class="ecm-panel is-active"><h2>User Role Editor</h2><p>برای هر نقش فقط دسترسی‌های لازم را فعال کنید. دسترسی <code>access_ecm</code> به‌صورت خودکار از سایر دسترسی‌های افزونه نتیجه می‌شود.</p><div class="ecm-capability-list">';foreach(ECM_Capabilities::all() as $cap=>$label)echo '<div><code>'.esc_html($cap).'</code><span>'.esc_html($label).'</span><b>'.(current_user_can($cap)?'✓':'—').'</b></div>';echo '</div></section></div>';
 }
 public static function maintenance(): void {
  check_admin_referer('ecm_maintenance');$op=sanitize_key($_POST['operation']??'');
	  if(in_array($op,array('rebuild_pages','delete_pages'),true))$cap='repair_ecm_pages';elseif(in_array($op,array('clear_diagnostics','clear_activity_report'),true))$cap='manage_ecm_diagnostics';else $cap='manage_ecm_files';if(!current_user_can($cap))wp_die('Forbidden',403);
  global $wpdb;
  if('rebuild_pages'===$op){ECM_Activator::create_pages();ECM_Upgrade::pages();flush_rewrite_rules();ECM_Diagnostics::record('pages','Plugin pages rebuilt',array('count'=>count((array)get_option('ecm_pages',array()))));}
  elseif('delete_pages'===$op){
   if('حذف کامل برگه‌های افزونه'!==sanitize_text_field(wp_unslash($_POST['confirm']??'')))wp_die('متن تأیید صحیح نیست.');
   $pages=(array)get_option('ecm_pages',array());$deleted=0;foreach($pages as $key=>$id){if(get_post_meta($id,'_ecm_created',true)){if(wp_delete_post((int)$id,true))$deleted++;unset($pages[$key]);}}update_option('ecm_pages',$pages,false);delete_option('ecm_old_paths');flush_rewrite_rules();ECM_Diagnostics::record('pages','Plugin-owned pages permanently deleted',array('count'=>$deleted));
  }elseif('clear_diagnostics'===$op){
   if('پاک کردن لاگ'!==sanitize_text_field(wp_unslash($_POST['confirm']??'')))wp_die('متن تأیید صحیح نیست.');ECM_Diagnostics::clear();
	  }elseif('clear_activity_report'===$op){
	   if('حذف گزارش روزانه'!==sanitize_text_field(wp_unslash($_POST['confirm']??'')))wp_die('متن تأیید صحیح نیست.');if(!ECM_Activity::clear_current())wp_die('حذف فایل گزارش روزانه انجام نشد. دسترسی نوشتن پوشه uploads را بررسی کنید.');wp_safe_redirect(admin_url('admin.php?page=ecm-tools&done=1&activity_cleared=1'));exit;
	  }elseif('migrate_files'===$op){
   $t=ECM_DB::table('files');$folder=ECM_Utils::settings()['storage_folder'];try{$root=self::storage();}catch(RuntimeException $e){wp_die('پوشه مقصد قابل نوشتن نیست.');}$failed=0;$moved=0;
   foreach($wpdb->get_results("SELECT * FROM {$t}") as $row){$old=self::file_path($row);if(!$old){$failed++;continue;}if(dirname($old)===$root)continue;$new=$root.'/'.basename($old);if(file_exists($new)||!rename($old,$new)){$failed++;continue;}
    if(false===$wpdb->update($t,array('relative_path'=>$folder.'/'.basename($old)),array('id'=>$row->id))){rename($new,$old);$failed++;}else $moved++;
   }ECM_Utils::log(0,'files_migrated',array('moved'=>$moved,'failed'=>$failed));if($failed)wp_die(esc_html('انتقال کامل نشد. منتقل‌شده: '.$moved.'، نیازمند بررسی: '.$failed.'. مسیر فایل‌های ناموفق حفظ شده است.'));
  }elseif('backup'===$op){self::backup();exit;}
  elseif('delete_history_range'===$op){
   if('حذف سابقه بازه'!==sanitize_text_field(wp_unslash($_POST['confirm']??'')))wp_die('متن تأیید صحیح نیست.');
   $from=ECM_Utils::local_to_utc(sanitize_text_field($_POST['from']??'').' 00:00:00');$to=ECM_Utils::local_to_utc(sanitize_text_field($_POST['to']??'').' 23:59:59');if(!$from||!$to||$from>$to)wp_die('بازه معتبر نیست.');
   $result=self::delete_operational_history($from,$to);set_transient('ecm_reset_result_'.get_current_user_id(),$result,5*MINUTE_IN_SECONDS);ECM_Diagnostics::record('maintenance','Operational history range deleted',array('from'=>$from,'to'=>$to,'counts'=>$result));
  }elseif('reset_history'===$op){
   if('ریست کامل سابقه افزونه'!==sanitize_text_field(wp_unslash($_POST['confirm']??'')))wp_die('متن تأیید صحیح نیست.');
   $result=self::delete_operational_history();set_transient('ecm_reset_result_'.get_current_user_id(),$result,5*MINUTE_IN_SECONDS);ECM_Diagnostics::record('maintenance','All operational history reset',array('counts'=>$result));
  }else wp_die('عملیات نامعتبر.');
	  wp_safe_redirect(admin_url('admin.php?page=ecm-tools&done=1'));exit;
	 }
 private static function delete_operational_history(?string $from=null,?string $to=null): array {
  global $wpdb;
  $tables=array();foreach(array('registrations','registration_meta','participants','people','attendance','events','sms','sms_attempts','files','ticket_tokens','otp') as $suffix)$tables[$suffix]=ECM_DB::table($suffix);
  $range=$from&&$to;$registration_ids=$range?$wpdb->get_col($wpdb->prepare("SELECT id FROM {$tables['registrations']} WHERE created_at BETWEEN %s AND %s",$from,$to)):$wpdb->get_col("SELECT id FROM {$tables['registrations']}");
  $registration_ids=array_values(array_unique(array_filter(array_map('absint',$registration_ids))));$id_sql=$registration_ids?implode(',',$registration_ids):'0';
  $file_where=$range?$wpdb->prepare("registration_id IN ({$id_sql}) OR created_at BETWEEN %s AND %s",$from,$to):'1=1';$files=$wpdb->get_results("SELECT * FROM {$tables['files']} WHERE {$file_where}");$file_count=0;
  foreach($files as $row){$path=self::file_path($row);if($path&&is_file($path)&&!unlink($path))continue;if(false!==$wpdb->delete($tables['files'],array('id'=>(int)$row->id),array('%d')))$file_count++;}
  $sms_where=$range?$wpdb->prepare("registration_id IN ({$id_sql}) OR created_at BETWEEN %s AND %s",$from,$to):'1=1';$sms_ids=array_values(array_filter(array_map('absint',$wpdb->get_col("SELECT id FROM {$tables['sms']} WHERE {$sms_where}"))));$sms_id_sql=$sms_ids?implode(',',$sms_ids):'0';
  $counts=array('registrations'=>count($registration_ids),'attendance'=>0,'sms'=>count($sms_ids),'files'=>$file_count,'participants'=>0);
  $wpdb->query('START TRANSACTION');
  try{
	$otp_where=$range?$wpdb->prepare("registration_id IN ({$id_sql}) OR created_at BETWEEN %s AND %s",$from,$to):'1=1';$wpdb->query("DELETE FROM {$tables['otp']} WHERE {$otp_where}");
   if($sms_ids)$wpdb->query("DELETE FROM {$tables['sms_attempts']} WHERE sms_id IN ({$sms_id_sql})");
   if($sms_ids)$wpdb->query("DELETE FROM {$tables['sms']} WHERE id IN ({$sms_id_sql})");
   $attendance_where=$range?$wpdb->prepare("registration_id IN ({$id_sql}) OR created_at BETWEEN %s AND %s",$from,$to):'1=1';$counts['attendance']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$tables['attendance']} WHERE {$attendance_where}");$wpdb->query("DELETE FROM {$tables['attendance']} WHERE {$attendance_where}");
   $event_where=$range?$wpdb->prepare("registration_id IN ({$id_sql}) OR created_at BETWEEN %s AND %s",$from,$to):'1=1';$wpdb->query("DELETE FROM {$tables['events']} WHERE {$event_where}");
   if($registration_ids){$counts['participants']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$tables['participants']} WHERE registration_id IN ({$id_sql})");$wpdb->query("DELETE FROM {$tables['ticket_tokens']} WHERE registration_id IN ({$id_sql})");$wpdb->query("DELETE FROM {$tables['registration_meta']} WHERE registration_id IN ({$id_sql})");$wpdb->query("DELETE FROM {$tables['participants']} WHERE registration_id IN ({$id_sql})");$wpdb->query("DELETE FROM {$tables['registrations']} WHERE id IN ({$id_sql})");}
   $wpdb->query("DELETE pe FROM {$tables['people']} pe LEFT JOIN {$tables['participants']} p ON p.person_id=pe.id WHERE p.id IS NULL");
   if(false===$wpdb->query('COMMIT'))throw new RuntimeException('commit_failed');
  }catch(Throwable $error){$wpdb->query('ROLLBACK');wp_die('ریست سابقه کامل نشد. دیتابیس به وضعیت قبل بازگردانده شد. جزئیات: '.esc_html($error->getMessage()));}
  return $counts;
 }
 private static function backup(): void {
  if(!class_exists('ZipArchive'))wp_die('ZipArchive روی هاست فعال نیست.');
  global $wpdb;$tmp=wp_tempnam('ecm-backup');$zip=new ZipArchive();if(true!==$zip->open($tmp,ZipArchive::OVERWRITE))wp_die('ساخت آرشیو ممکن نیست.');
	  foreach(array('event_definitions','event_operator_acl','ticket_templates','people','participants','registrations','registration_meta','sessions','attendance','events','sms','sms_attempts','files') as $suffix){$t=ECM_DB::table($suffix);$rows=$wpdb->get_results("SELECT * FROM {$t}",ARRAY_A);foreach($rows as &$row)unset($row['access_hash'],$row['normalized_phone'],$row['phone_hash']);unset($row);$zip->addFromString('data/'.$suffix.'.json',wp_json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));}
  $t=ECM_DB::table('files');foreach($wpdb->get_results("SELECT * FROM {$t}") as $row){$path=self::file_path($row);if($path){$plain=ECM_Upgrade::decrypt(file_get_contents($path));if($plain)$zip->addFromString('cards/'.$row->id.'-'.$row->filename,$plain);}}
	  $template_table=ECM_DB::table('ticket_templates');foreach($wpdb->get_results("SELECT * FROM {$template_table} WHERE source_type='upload'") as $template){$path=ECM_Tickets::template_path($template);if($path&&is_file($path))$zip->addFile($path,'ticket-templates/'.(int)$template->id.'-'.basename($path));}
  $zip->close();nocache_headers();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="event-history-'.gmdate('Ymd-His').'.zip"');header('Content-Length: '.filesize($tmp));readfile($tmp);unlink($tmp);
 }
 public static function report_document(): void {
  if(!current_user_can('view_ecm_reports'))wp_die('Forbidden',403);check_admin_referer('ecm_report_document');
  $all=ECM_Admin::report_columns();$cols=array_intersect_key($all,array_flip(ECM_Admin::selected_columns()));if(!$cols)wp_die('ابتدا حداقل یک ستون را در گزارش انتخاب و اعمال کنید.');
  $filters=ECM_Admin::report_filters();$report_event=!empty($filters['event'])?ECM_Events::event((int)$filters['event']):null;$report_title=$report_event?$report_event->title:ECM_Utils::settings()['event_name'];if(!empty($filters['session'])){global $wpdb;$session_table=ECM_DB::table('sessions');$session_title=$wpdb->get_var($wpdb->prepare("SELECT title FROM {$session_table} WHERE id=%d",(int)$filters['session']));if($session_title)$report_title.=' — '.$session_title;}$w=max(1120,count($cols)*180);nocache_headers();header('Content-Type: text/html; charset=UTF-8');
  echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>گزارش رویداد</title><link rel="stylesheet" href="'.esc_url(ECM_URL.'assets/css/design.css').'"><style>body{background:white;color:#0B1F46;font:16px/1.8 Tahoma,sans-serif;margin:24px}table{width:100%;table-layout:fixed;border-collapse:collapse}th,td{border:1px solid #ccd8e2;padding:8px;overflow-wrap:anywhere;font-size:12px}thead{display:table-header-group}tr{break-inside:avoid}.ecm-export-scroll{overflow:auto}table{min-width:'.(int)$w.'px}button{padding:12px;margin:8px}@media print{.ecm-export-controls{display:none}body{margin:0}.ecm-export-scroll{overflow:visible}table{min-width:0;width:100%}th,td{font-size:9px} @page{size:'.max(297,ceil($w/4)).'mm 297mm;margin:10mm}}</style></head><body><div class="ecm-export-controls"><button data-export-pdf>دانلود PDF همه رکوردها</button><button onclick="window.print()">چاپ گزارش مستقل</button><p>برای ستون‌های بسیار زیاد PDF صفحه عریض ساخته می‌شود؛ جدول در عرض تقسیم نمی‌شود.</p></div><h1>گزارش '.esc_html($report_title).'</h1><p>'.esc_html(ECM_Utils::utc_to_local(ECM_Utils::now_utc())).' — خروجی کامل فیلتر جاری</p><div class="ecm-export-scroll"><table data-export-table><thead><tr>';
  foreach($cols as $label)echo '<th>'.esc_html($label).'</th>';echo '</tr></thead><tbody>';
  $offset=0;do{$rows=ECM_Admin::report_rows(500,$offset,$filters);ECM_Admin::prime_report_cache($rows,$filters);$events=ECM_Admin::events_for_rows($rows);foreach($rows as $r){echo '<tr>';foreach(array_keys($cols) as $col)echo '<td>'.wp_kses_post(ECM_Admin::column_value($col,$r,ECM_DB::decode_data($r),$events[$r->id]??array())).'</td>';echo '</tr>';}$offset+=500;}while(count($rows)===500);
  echo '</tbody></table></div><script src="'.esc_url(ECM_URL.'assets/js/pdf.js').'"></script></body></html>';exit;
 }
}
