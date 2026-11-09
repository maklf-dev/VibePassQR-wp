## 0.7.0-alpha.1 — 2026-11-09

- Additive workflow schema (versions, instances, files) and audit relationship.
- Default-off, per-event opt-in workflow domain layer with immutable version publishing.
- Admin-only internal draft persistence and optimistic-concurrency protected stage change.
- Deferred schema upgrade on privileged admin requests; no front-end DDL.
- Workflow readiness in diagnostics; no public workflow routes activated.
- Experimental only: no approval, receipt upload, OTP tracking or card-issuance integration yet.

# Changelog

## 0.6.7 — 2026-11-09

- افزودن فیلتر مشترک رویداد در گزارش‌ها با پیش‌فرض جدیدترین رویداد فعال، انتخاب چند رویداد و گزینه همه
- هماهنگی دامنه فیلتر و خروجی CSV برای گزارش‌های ثبت‌نام، حضور و پیامک؛ حفظ لینک‌های فیلتر قدیمی
- اصلاح صفحه‌بندی گزارش ثبت‌نام با اندازه صفحه 25/50/100/250 و حفظ فیلتر در لینک‌ها
- نمایش رابطه معرف و مهمان در گزارش با پیش‌واکشی دسته‌ای
- ثبت علت فنی رد دسترسی کارت و لینک کوتاه با Trace محدود و بدون اطلاعات محرمانه
- افزودن فواصل قابل تنظیم تلاش مجدد SMS و حفظ مقدار حداکثر تلاش قبلی در ارتقا
- شورت‌کد اختیاری `[ecm_event_list]` بدون تغییر مسیر ثبت‌نام فعلی
- بدون تغییر تخریبی در دیتابیس و بدون فعال‌سازی موتور ثبت‌نام چندمرحله‌ای (برنامه نسخه 0.7.0)

## 0.6.6 — 2026-11-06

- رفع صفحه سفید PDF ثابت پیش از کارت در سایت HTTPS با ارتقای امن لینک HTTP هم‌دامنه به HTTPS هنگام نمایش
- نرمال‌سازی همان لینک هنگام ذخیره تنظیمات رویداد، بدون نیاز به تغییر دستی تنظیم فعلی
- حفظ کامل ثبت‌نام‌ها، کارت‌ها، QRها، OTP، پیامک، حضور و تنظیمات نسخه 0.6.5 و بدون تغییر دیتابیس

## 0.6.5 — 2026-10-28

- بازسازی خودکار نقش‌های داخلی «مدیر رویداد» و «مدیر ثبت حضور» در صورت حذف‌شدن یا ناقص‌بودن قابلیت‌های ضروری
- افزودن انتخاب گروهی ثبت‌نام‌ها در گزارش اصلی، منحصراً برای مدیر کل واقعی وردپرس
- افزودن عملیات علامت‌گذاری تستی، بازگردانی به حالت عادی و حذف دائمی ثبت‌نام و داده‌های وابسته با nonce و تأیید صریح
- افزودن فیلتر فقط اصلی، فقط تستی یا همه ثبت‌نام‌ها به گزارش مدیر کل و خروجی فیلترشده همان صفحه
- حذف ثبت‌نام‌های تستی از داشبورد و گزارش‌های اشخاص، رویدادها، اپراتورها، حضور، پیامک و خروجی مدیران غیرکل
- استثناکردن شماره ثبت‌نام‌های تستی از محدودیت شماره تکراری در همان رویداد، بدون تغییر سیاست سایر شماره‌ها
- حفظ کامل تنظیمات، رویدادها، کارت‌ها، QRها، OTP، پیامک، حضور و گردش‌های نسخه 0.6.4

## 0.6.4 — 2026-10-27

- افزودن اعتبارسنجی قطعی شماره موبایل ایران در مرورگر و سرور برای معرف و مهمان، پیش از هرگونه درج دیتابیس
- جلوگیری از ثبت مقدارهای ۱۱ کاراکتری غیرعددی و جلوگیری از ایجاد پیامک با گیرنده نامعتبر
- نرمال‌سازی ارقام فارسی و عربی به قالب استاندارد `09xxxxxxxxx`
- ارتقای گزارش روزانه به نسخه دوم با مسیر دقیق درخواست، شناسه هم‌بستگی، HTTP status، زمان پاسخ، حافظه و تعداد کوئری
- افزودن شمارنده‌ها و نتیجه‌های تفکیکی ثبت‌نام، OTP، بازیابی، پیامک و حضور، همراه با خطاهای اخیر و درخواست‌های کند محدود
- افزودن snapshot کم‌حجم از PHP، دیتابیس، OPcache، object cache، فضای دیسک، صف پیامک و کیفیت داده‌ها
- کاهش فشار گزارش‌گیری با تجمیع رخدادهای هر درخواست و یک بار نوشتن قفل‌شده در پایان درخواست
- رفع یک مرز خطای ثبت حضور که ممکن بود با پایان بازه نوبت، پس از ذخیره موفق حضور پاسخ 500 ایجاد کند
- حفظ کامل تنظیمات، داده‌ها، صفحات، کارت‌ها، QRها، OTP و گردش‌های فعلی نسخه 0.6.3

## 0.6.3 — 2026-10-26

- نمایش خطای مشخص در بازیابی OTP وقتی شماره در رویداد انتخاب‌شده ثبت نشده است
- افزودن دکمه بازگشت به صفحه ثبت‌نام همان رویداد، بدون تغییر در سایر مراحل OTP و پیامک

## 0.6.2 — 2026-10-26

- پذیرش مستقیم کد ده‌کاراکتری چاپ‌شده روی بلیت در پنل اپراتور، بدون حساسیت به حروف کوچک و بزرگ
- حفظ پشتیبانی ورودی‌های قبلی شامل QR، توکن و لینک کامل حضور
- افزودن گزارش روزانه و تجمیعی فعالیت‌های اختصاصی افزونه شامل صفحات، عملیات، ترافیک، دستگاه، عملکرد و فعالیت مدیران
- افزودن دانلود JSON و حذف مستقل گزارش روز جاری در صفحه نگهداری و عیب‌یابی
- محدودسازی اندازه، قفل فایل برای درخواست‌های هم‌زمان، نگهداری هفت‌روزه و حذف داده‌های حساس از گزارش فنی
- حفظ کامل تنظیمات، رویدادها، ثبت‌نام‌ها، کارت‌ها، OTP، پیامک و گردش حضور نسخه 0.6.1

## 0.6.1 — 2026-10-25

- رفع ارسال‌نشدن OTP بازیابی برای کاربران مهمان در درخواست عمومی `admin-ajax.php`
- بارگذاری محدود و امن وابستگی‌های ارسال افزونه IPPanel SMS Pro GV فقط در صورت توقف bootstrap عمومی آن افزونه
- حفظ تنظیمات و اطلاعات اتصال در افزونه واسط، بدون کپی نام کاربری یا رمز در Event Card Manager
- افزودن وضعیت Transport انتخاب‌شده و جزئیات بی‌خطر آن به فایل عیب‌یابی
- حفظ کامل تنظیمات OTP، پترن‌ها، کارت‌ها، ثبت‌نام‌ها و سازگاری ارتقا از 0.6.0

## 0.6.0 — 2026-10-25

- افزودن بازیابی امن کارت با OTP پیامکی اختیاری و مستقل برای هر رویداد
- افزودن پترن آماده `sqg5qg0uahtq43y` با پارامتر عددی `code` و خط `+983000505`
- اعتبار پنج‌دقیقه‌ای، ارسال مجدد پس از دو دقیقه، محدودیت درخواست/تلاش و مصرف یک‌باره کد
- اتصال مستقیم OTP به Transport عملیاتی IPPanel GV بدون وابستگی به Digits و بدون تأخیر صف
- عدم ذخیره کد خام در صف، گزارش، لاگ تشخیصی یا پاسخ مدیریتی
- تکمیل کتابخانه پترن با کاربرد، خط اختصاصی و نوع پارامترها و افزودن آزمون مستقل OTP
- جلوگیری قطعی از سقوط پترن انتخاب‌شده به متن آزاد و اصلاح تعیین فیلد گیرنده صف
- افزودن OTP به گزارش جامع پیامک، فیلتر اعلان و CSV با پارامترهای محرمانهٔ پوشانده‌شده
- بازطراحی نهایی رابط عمومی تیره، فرم بازیابی دومرحله‌ای، کنترل‌های موبایل و Safari و تراکم بهتر پنل مدیریت
- حفظ کامل کارت HTML، لینک‌های کوتاه، مهمان‌ها، حضور اپراتوری و داده‌های نسخه‌های قبلی

## 0.5.1-alpha.2 — 2026-10-25

- حذف عبارت اضافی از عنوان کد پیگیری در فرم بازیابی
- افزودن URL فایل PDF ثابت به تنظیمات مستقل هر رویداد
- نمایش PDF ثابت قبل از کارت در مقصد مشترک ثبت‌نام موفق و بازیابی
- افزودن دکمه دانلود و fallback برای مرورگرهایی که PDF داخلی را نمایش نمی‌دهند
- حفظ کارت HTML پیش‌فرض و غیرفعال‌ماندن موتور تولید PDF اختصاصی

## 0.5.1-alpha.1 — 2026-10-25

- غیرفعال‌سازی کامل موتور PDF تمپلیت، حذف کتابخانه pdf-lib و بازگشت کارت‌ها به خروجی HTML پیش‌فرض
- حفظ لینک‌های کوتاه `/t/` و `/a/` و تمام توکن‌های صادرشده مستقل از حذف موتور PDF
- افزودن سیاست رویدادمحور «فقط کارت شخصی» یا «کارت معرف و مهمان‌ها» با پیش‌فرض امن کارت شخصی
- اعمال سیاست دسترسی روی نمایش کارت، بازیابی، لینک کوتاه و سند چاپ برای جلوگیری از دورزدن با URL
- افزودن کد پیگیری اختیاری در بازیابی شماره‌های مشترک برای جلوگیری از نمایش کارت شخص دیگر
- حذف دکمه‌ها، تنظیمات، اسکریپت‌ها و Endpoint آرشیو PDF کارت بدون حذف مخرب داده‌های تاریخی

## 0.5.0 — 2026-10-24

- افزودن قالب PDF ثابت و نسخه‌بندی‌شده برای هر رویداد با فایل رسمی تأییدشده به‌عنوان قالب پیش‌فرض
- افزودن نگاشت مختصات QR، نام، موبایل، شناسه، نوع بلیت و تمام فیلدهای فرم بدون بازطراحی PDF پایه
- تولید و دانلود بلیت تکی و چندصفحه‌ای در مرورگر بدون ذخیره PDF هر شرکت‌کننده روی هاست
- حفظ annotationهای قابل‌کلیک قالب در خروجی PDF پویا
- افزودن لینک‌های کوتاه مستقل کارت و حضور با توکن تصادفی ۱۲۸ بیتی، ذخیره هش و نسخه رمز‌شده قابل بازیابی
- حفظ قرارداد QRهای قبلی، لینک‌های بازیابی قدیمی، کارت‌های فعلی و دسترسی تفکیکی معرف/مهمان
- غیرفعال‌شدن آرشیو خودکار PDF در مهاجرت این نسخه بدون حذف فایل‌های آرشیوی قبلی
- نمایش فضای نسخه‌های قالب در عیب‌یابی و افزودن قالب‌های بارگذاری‌شده به ZIP پشتیبان
- افزودن fallback سازگار با Safari برای خواندن خروجی Canvas هنگام ساخت PDF

## 0.4.2 — 2026-10-24

- افزودن سیاست مستقل و رویدادمحور برای اجازه یا جلوگیری از شماره تکراری مهمان
- نگهداری نام مستقل هر کارت مهمان حتی هنگام استفاده چند مهمان از یک شماره مشترک
- حذف سقف مهمان از تک‌تک ردیف‌های فرم‌ساز و یکپارچه‌شدن سقف در بخش مهمان رویداد
- تثبیت کنترل شماره تکراری معرف و مهمان در محدوده همان رویداد
- جداسازی حذف بازه‌ای سوابق از ریست کامل داده‌های عملیاتی
- حفظ رویدادها، نوبت‌ها، تنظیمات، نقش‌ها و برگه‌ها هنگام ریست سابقه

## 0.4.1 — 2026-10-19

- Rebuilt the SMS administration screen around compact health information and separate connection, notification and pattern tabs.
- Added an independent delivery mode for every notification and fixed pattern selections being overridden by the global free-text mode.
- Added shared or separate host/guest templates, patterns and parameter maps at global, event and attendance-session levels.
- Made every built-in and registration-form variable discoverable and copyable, including the secure card-link variable.
- Reorganized the pattern library into compact expandable records and made free-text/pattern editors conditional.
- Replaced 64-character card tickets in SMS messages with 128-bit URL-safe short links while retaining old-link compatibility.
- Added optional per-event duplicate-primary-phone prevention, race-safe database enforcement and a customizable public error.
- Added a comprehensive SMS report with event/session/type/status/transport/audience/mode/date/search filters and filtered UTF-8 CSV export.
- Added SMS summaries to the reporting overview, person profiles and event profiles, and recorded manual send/link tests in SMS history.

## 0.4.0 — 2026-10-19

- Promoted the approved 0.4 line to an in-place compatible release without changing registration, participant, QR, card, attendance or report identifiers.
- Added a global pattern library and seeded the verified `is35i844i289qa9` payment-registration pattern with `name`, `phone`, `pay` and `date` mappings.
- Added pattern-profile selection at global, event and attendance-session levels while keeping manual pattern code/parameter overrides compatible.
- Added strict pattern validation, 40-character parameter limits and a payment-success guard for financial patterns.
- Added an independent test center for connection, free text, pattern and real card-link messages without changing the operational transport.
- Added a test-only direct Legacy IPPanel adapter based on the installed Gravity bridge configuration; the verified GV bridge remains the recommended operational route.
- Consolidated provider diagnostics, removed duplicate connection/balance messages and kept the formatted balance in the primary status box.
- Added a real registration/participant card-link test that preserves host-all-cards and guest-own-card access scopes.
- Added phone, payment reference/date/status variables and participant-aware data to queued pattern rendering.
- Preserved the background queue, bounded retries, idempotency, per-attempt history and all 0.4.0-alpha.2 event/session inheritance behavior.

## 0.4.0-alpha.2 — 2026-10-18

- Added an asynchronous connection check on SMS settings entry, retry-on-failure control and formatted credit balance.
- Added inherited or independent SMS policies for every event and attendance/repeat overrides for every session.
- Queued a distinct registration message for the primary participant and each active guest.
- Scoped guest SMS card links to that guest while preserving all-card access for the primary participant.
- Added participant card tracking codes and the `{host_name}` template variable.
- Prevented chained recovery notifications when a card is opened through an SMS recovery ticket.
- Replaced raw IDs in recent SMS activity with participant, role, event and session labels and added queue-state counters.
- Added additive `sms_config` and link `participant_id` schema migrations.

## 0.4.0-alpha.1 — 2026-10-18

- Added a provider-neutral SMS transport contract while preserving all registration, card, QR, guest, attendance, portal and report behavior from 0.3.0.
- Added the primary `IPPanel SMS Pro GV` bridge using the installed plugin's public send/credit methods without copying or logging its username or password.
- Kept IPPanel Edge diagnostics available and added an optional WP‑SMS adapter as a secondary compatibility route.
- Enforced HTTPS for the GV endpoint and blocked bridge delivery when the legacy plugin is configured with an insecure HTTP domain.
- Moved all automatic notifications to a one-minute background queue with atomic job claiming, bounded batches, exponential retry and configurable attempt limits.
- Added per-attempt history, transport/event/session/participant metadata, accepted timestamps and a persistent redacted provider-health snapshot.
- Added provider discovery, readiness cards, credit/connection test, test send, queue counters and transport-aware logs to the SMS admin page.
- Preserved existing SMS enable/pause settings during upgrade; no queued message is sent merely because the plugin was updated.
- Added an explicit opt-in before releasing historical paused messages, preventing an accidental bulk send after switching transports.
- Included a staged Persian test plan and release notes for safe validation before enabling automatic messages.

## 0.3.0 — 2026-10-14

- Promoted the approved beta line to a stable, in-place upgrade without changing existing registration, participant, card, QR or attendance identifiers.
- Replaced simulated payment success with the explicit `offline_confirmed` state; a gateway integration can continue to use the `ecm_payment_result` adapter boundary.
- Disabled diagnostics by default, removed the temporary test-data reset and paused IPPanel delivery without deleting queued SMS records.
- Added additive compound indexes for event, session, participant, attendance, audit, SMS and file-report workloads.
- Replaced report N+1 detail queries with bounded batch preloading and changed dashboard/event aggregates to pre-aggregated joins for large datasets.
- Preserved the live operator scanner, primary/guest QR scope, group check-in, duplicate protection, standalone role portal and three-level reports.
- Reduced the distributed documentation to installation, acceptance testing, operations/security, stable release notes and the final QA report.

## 0.3.0-beta.2 — 2026-10-13

- Fixed guest QR recognition for raw, full-URL and repeatedly URL-encoded scanner payloads while preserving the existing HMAC token format.
- Made guest QR lookup return only that guest and primary QR lookup always return the primary participant plus all active guests.
- Added a deterministic scanner lifecycle: live scan, frozen camera frame, in-camera rescan control and automatic camera restart after a successful check-in.
- Bundled a local jsQR fallback for Safari and browsers without a reliable native BarcodeDetector; camera frames stay on-device and no CDN is used.
- Prevented immediate duplicate reads after automatic restart by requiring the previous QR to leave the camera frame before the next scan is armed.
- Stopped double encoding newly rendered participant QR URLs while retaining compatibility with previously issued cards.
- Expanded appearance controls with color/status tokens, typography, spacing, control/button geometry, independent page widths, motion, shadows and a live preview.
- Added light/dark palette helpers and section-specific advanced CSS inputs for registration, recovery, cards, attendance, portal, operator, reports and admin surfaces.
- Kept all beta.1 data, role, portal, reporting, card and legacy registration contracts upgrade-compatible.

## 0.3.0-beta.1 — 2026-10-13

- Added a standalone `/event-panel/` login and role-aware workspace that signs users in without using the theme/member-panel redirect path.
- Routed attendance operators directly to the live scanner and report managers directly to reporting; mixed roles receive a compact workspace chooser.
- Replaced in-page bulk printing with an authenticated standalone A4 document that places every card on its own page.
- Added generation-two reports: KPI overview, registration and attendance lists, people, operators and events.
- Added connected person, operator and event profiles with participation, guest, attendance, recorder and session details.
- Added filtered attendance CSV and linked event/session report buttons to their new report views.
- Preserved legacy registration/card/QR contracts and all additive event/participant data from `0.3.0-alpha.2.2`.
- Kept SMS transport paused and real payment gateways out of scope until their infrastructure requirements are resolved.

## 0.3.0-alpha.2.2 — 2026-10-11

- انتقال فرم‌ساز، حالت رایگان/پرداخت، متن‌های کارت و بازیابی و پیام‌های حضور به تنظیمات مستقل هر رویداد و حذف تعریف تکراری از تنظیمات عمومی.
- اضافه‌شدن فعال/غیرفعال‌کردن کل رویداد همراه عنوان و پیام قابل تنظیم برای همه لینک‌های عمومی.
- یکپارچه‌شدن بازیابی با شماره موبایل و بستن نشت دسترسی: معرف همه کارت‌ها و مهمان فقط کارت خودش را دریافت می‌کند.
- اضافه‌شدن چاپ همه کارت‌ها و PDF چندصفحه‌ای برای معرف، با دانلود خودکار فایل.
- اضافه‌شدن پنل سریع اپراتور با دوربین داخل مرورگر، انتخاب تصویر، ورود دستی، AJAX و ثبت‌های پشت‌سرهم بدون refresh.
- نمایش معرف و مهمان‌ها پس از اسکن QR معرف، انتخاب فقط معرف/همه/گزینشی، و محدودماندن QR مهمان به همان مهمان.
- نمایش حضور قبلی، ساعت، نام و کد ثبت‌کننده و وضعیت حضور معرف برای مهمانان.
- افزودن کنترل server-side دسترسی رویداد، بازه نوبت، participantهای مجاز، request ID و ایندکس یکتا برای جلوگیری از ثبت تکراری.
- اصلاح ستون دستگاه با استفاده از snapshot ذخیره‌شده و استخراج خوانای User-Agent برای داده‌های قبلی؛ نبود داده دیگر به‌عنوان دستگاه ساختگی نمایش داده نمی‌شود.
- حفظ قراردادهای قدیمی کارت، cookie و امضای QR برای ارتقای مستقیم از `0.3.0-alpha.2.1`.

## 0.3.0-alpha.2.1 — 2026-10-10

- انتقال کامل مدیریت نوبت‌های حضور از تنظیمات عمومی به صفحه هر رویداد، بدون حذف نوبت‌های قبلی.
- اضافه‌شدن لینک گزارش مستقیم برای هر رویداد و هر نوبت و فیلتر نوبت در جدول، CSV و سند چاپ/PDF.
- کنترل مستقل نمایش دکمه ورود اپراتور در هر رویداد؛ پیش‌فرض خاموش.
- اضافه‌شدن نوع فیلد «مهمان» با نام، موبایل، افزودن/حذف پویا و سقف ۲۰ نفر.
- هویت، کارت، QR، کد پیگیری و حضور مستقل برای معرف و هر مهمان.
- بازیابی همه کارت‌ها توسط معرف و بازیابی فقط کارت شخصی توسط مهمان.
- تفکیک شمارش حضور در سطح شرکت‌کننده و حفظ قرارداد QR قدیمی ثبت‌نام‌کننده اصلی.
- ثبت نوع دستگاه، سیستم‌عامل و مرورگر در حضور و ستون گزارش خوانا.
- آماده‌سازی مدل داده برای انتخاب گروهی معرف/مهمان در اسکنر مرحله `alpha.2.2`.

## 0.3.0-alpha.2 — 2026-10-10

- Added additive multi-event tables, event assignment for registrations/sessions/attendance, and a resumable legacy participant migration.
- Preserved legacy registration IDs, card grants and QR HMAC payloads through a default-event migration.
- Added `ecm_attendance_operator`, `record_ecm_attendance` and `view_own_ecm_attendance` without overwriting custom roles.
- Added per-event attendance policies (`self_service`, `operator_only`, `hybrid`) plus per-session overrides.
- Added per-event role/user ACLs, customizable access messages and server-side authorization on both GET and POST.
- Stored operator user ID, display-name/code snapshot and attendance method on every operator-recorded attendance.
- Added the operator portal, recent self-recorded attendance, event-aware registration/recovery and event filters in report/CSV/print.
- Added migration health to diagnostics and included new event/participant tables in backup/reset/uninstall handling.
- Kept IPPanel transport state and all existing SMS network diagnostics unchanged; SMS remains independent from this stage.

## 0.3.0-alpha.1.2.1 — 2026-10-06

- Expanded the redacted IPPanel support report with the tested endpoint, local/UTC times, plugin version, response size and safe response headers.
- Added a guarded operational-test reset that removes registrations, form values, attendance, events, SMS queue, temporary links and encrypted PDF archives while preserving configuration, pages, roles, API key and attendance sessions.
- Refined the report header, filters, date controls, statistics and table styling.
- Added a responsive mobile card layout for report rows and corrected no-finance filter spacing.
- Kept the SMS transport pause state unchanged during test-data reset.

## 0.3.0-alpha.1.2 — 2026-10-06

- Multi-layer IPPanel diagnosis: DNS A/AAAA, direct TCP 443, automatic HTTPS, forced IPv4 and conditional IPv6.
- Scoped IPv4-only transport option applied exclusively to `edge.ippanel.com` requests.
- Controlled SMS transport pause; new events remain recoverable with `paused` status instead of being discarded.
- Automatic support ownership verdict for hosting, IPPanel or local configuration.
- Copyable and downloadable redacted support report with environment and timing evidence.
- Latest network snapshot is included in the authenticated diagnostic JSON without secrets or message content.
- Improved maintenance form layout, destructive-action inputs and responsive public-page spacing.

## 0.3.0-alpha.1.1 — 2026-10-06

- Runtime administrator capability safety net and corrected tools submenu registration order.
- Canonical redirect from the accidental `/wp-admin/ecm-tools` path to the real WordPress admin page.
- High-specificity, theme-resistant styling for all public buttons.
- Read-only start/end date and time on the attendance confirmation screen.
- IPPanel test responses now distinguish authentication, validation, rate limiting, network errors and provider 5xx outages.
- API keys loaded from `wp-config.php` are trimmed without ever being exposed.
- Dedicated hotfix acceptance guide and release notes.

## 0.3.0-alpha.1 — 2026-10-06

- Central capability registry, administrator capability repair and User Role Editor compatibility.
- Real no-payment registration state (`not_required`) that bypasses payment processing.
- Dedicated IPPanel SMS settings, connection test, free-text/pattern test, immediate/background delivery, controlled retry and logs.
- Separate permanent deletion and rebuild actions for plugin-owned pages.
- Privacy-conscious rotating diagnostic file with authenticated download and clear actions.
- Early/late/outside attendance state separation and corresponding SMS states.
- Unified light admin design; removed conflicting dual admin stylesheets.
- Master staged roadmap, release notes and site acceptance test plan.

## 0.2.0 — 2026-10-04

- Additive upgrade preserving registrations, QR HMAC and existing grant behavior.
- English page slugs and legacy double-encoded path redirects.
- Attendance confirmation POST and unique request hashes prevent replay consumption.
- Scoped light/navy/gold UI, canvas template, appearance and messaging settings tabs.
- IPPanel Edge SMS queue: free text/pattern, state toggles, field mapping, variables, encrypted key, status reporting.
- Offline image-based PDF download and encrypted optional card archive.
- Selected-column CSV with UTF-8 BOM and formula-injection protection; select all/none.
- Independent report document, wide PDFs, repeated headers and vertical pagination.
- Archive sizes, folder migration, confidential ZIP history export and date-range cleanup.
- Separate capabilities compatible with User Role Editor; owned-page repair/trash.
- Exact decimal rial conversion, date validation and non-overlapping active-session validation.
- See docs/UPGRADE-FA.md for test scope, installation and known limitations.

## 0.1.0

Initial registration, card, recovery, attendance and reporting plugin.

## 0.7.0-alpha.2 (Workflow phase 3 — Staging only)
- Admin-only step editor with versioned safe HTML/scoped CSS and four-step preview.
- Admin test progression: form -> immutable agreement acceptance -> private receipt -> under review.
- Additive audit metadata; no registration, ticket, QR, attendance or SMS bridge activated.
- Workflow remains off by default; public registration stays classic even on custom workflow events.
- See `docs/RELEASE-NOTES-0.7.0-alpha.2-FA.md` and QA report.
