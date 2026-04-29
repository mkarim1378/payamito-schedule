# Changelog

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [2.5.0] - 2026-04-29

### Changed
- تب‌های پنل ادمین (تنظیمات، تاریخچه ارسال، آمار) به صفحات مجزا با زیرمنوی sidebar وردپرس تبدیل شدند
- آدرس صفحه تاریخچه: `admin.php?page=payamito-scheduler-log`
- آدرس صفحه آمار: `admin.php?page=payamito-scheduler-stats`
- کد tab switching از `admin.js` حذف شد

---

## [2.4.0] - 2026-04-29

### Added
- Meta Box «پیامک‌های پیامیتو» در صفحه ویرایش سفارش (ستون کناری)
- نمایش تمام پیامک‌های ارسال‌شده/ناموفق/لغوشده برای آن سفارش با شماره ماسک‌شده و زمان
- دکمه «ارسال مجدد» برای پیامک‌های ناموفق با بازپردازش vars از داده‌های جاری سفارش
- ثبت یادداشت داخلی سفارش پس از هر ارسال مجدد
- متدهای `Payamito_Logger::get_by_order()`, `get_by_id()`, `update_status()`
- سازگاری با HPOS: callback meta box هم `WP_Post` هم `WC_Abstract_Order` را می‌پذیرد

---

## [2.3.0] - 2026-04-29

### Added
- تب **آمار** در پنل ادمین با داده‌های aggregate از جدول لاگ
- کارت‌های خلاصه: کل ارسال‌ها، موفق، ناموفق، نرخ موفقیت (با رنگ‌بندی وضعیت)
- نمودار میله‌ای CSS-only برای ارسال‌های ۳۰ روز اخیر (سبز=موفق، قرمز=ناموفق)
- جدول پرکاربردترین ۵ پترن با نرخ موفقیت هر کدام
- متد `Payamito_Logger::get_stats()` با کش transient یک ساعته
- invalidate خودکار کش آمار پس از هر insert جدید

---

## [2.2.0] - 2026-04-29

### Added
- یادداشت داخلی خودکار در سفارش پس از هر ارسال پیامک (موفق یا ناموفق)
- شماره تلفن در یادداشت به صورت ماسک‌شده نمایش داده می‌شود (مثال: `0912****321`)
- متد `mask_phone()` در `Payamito_Scheduler`

---

## [2.1.0] - 2026-04-29

### Added
- کلاس `Payamito_Logger` با جدول `{prefix}payamito_sms_log` در دیتابیس برای ثبت تاریخچه ارسال‌ها
- تب **تاریخچه ارسال** در پنل ادمین با `WP_List_Table`، فیلتر بر اساس وضعیت (ارسال‌شده / ناموفق / لغوشده) و صفحه‌بندی
- ستون‌های لاگ: سفارش، شماره (ماسک‌شده)، پترن، وضعیت، تعداد تلاش، زمان‌بندی، زمان ارسال
- `scheduled_at` به صورت دقیق در آرگومان‌های WP-Cron پاس داده می‌شود
- Cron هفتگی برای پاکسازی خودکار لاگ‌های قدیمی
- فیلد «نگهداری لاگ (روز)» در تنظیمات پنل (پیش‌فرض: ۹۰ روز)
- تب‌بندی پنل ادمین به دو بخش «تنظیمات» و «تاریخچه ارسال»
- `register_activation_hook` برای ساخت خودکار جدول لاگ هنگام فعال‌سازی
- `register_deactivation_hook` برای لغو Cron هفتگی هنگام غیرفعال‌سازی
- فیلتر `cron_schedules` برای تعریف بازه زمانی `weekly`

### Changed
- متد `execute` در `Payamito_Scheduler` اکنون نتیجه هر ارسال را لاگ می‌کند

---

## [2.0.1] - 2026-04-28

### Security
- اتصال SOAP از HTTP به HTTPS تغییر یافت تا اطلاعات احراز هویت رمزنگاری‌شده منتقل شوند
- فیلد رمز عبور در پنل ادمین از `type="text"` به `type="password"` تغییر یافت
- بررسی `current_user_can('manage_options')` به ابتدای `handle_submission` منتقل شد تا تمام هندلرها را پوشش دهد

### Performance
- گزینه‌های `connection_timeout` (۱۰ ثانیه) و `cache_wsdl` به `SoapClient` اضافه شدند تا از هنگ و درخواست‌های تکراری WSDL جلوگیری شود
- برای جلوگیری از ثبت رویداد cron تکراری، پیش از `wp_schedule_single_event` بررسی `wp_next_scheduled` انجام می‌شود

### Changed
- نوع‌بندی صریح (type hints) به تمام متدهای کلاس‌ها اضافه شد (PHP 8.0)
- سازنده `Payamito_Api` به constructor property promotion بازنویسی شد
- `switch/case` در `to_seconds` با `match` expression جایگزین شد
- بررسی `instanceof WC_Abstract_Order` در `execute` جایگزین بررسی falsy ساده شد
- مقدار `delay_val` با `max(0, ...)` در `sanitize_rule` اعتبارسنجی می‌شود تا اعداد منفی ذخیره نشوند
- در `handle_test_sms` بررسی `is_array` پس از `json_decode` اضافه شد
- ویژگی `min="0"` به فیلد `delay_val` در فرم HTML اضافه شد

---

## [2.0.0] - 2026-04-28

### Changed
- معماری کامل پلاگین از یک فایل تکی به ساختار ماژولار تغییر یافت
- فایل اصلی `payamito-schedule.php` به bootstrap خلاصه شد
- کلاس `Payamito_Custom_Scheduler` به سه کلاس مجزا تقسیم شد:
  - `Payamito_Api` — ارتباط با وب‌سرویس SOAP
  - `Payamito_Scheduler` — هوک‌های WooCommerce و اجرای cron
  - `Payamito_Admin` — پنل ادمین و پردازش فرم‌ها
- جاوااسکریپت پنل ادمین از فایل PHP خارج و به `assets/js/admin.js` منتقل شد
- داده‌های وضعیت سفارش‌ها از طریق `wp_localize_script` به JS پاس داده می‌شوند (حذف PHP داخل JS)

### Added
- بخش **تنظیمات پنل پیامک** در ادمین برای ذخیره نام کاربری و رمز عبور در دیتابیس
- ثابت‌های `PAYAMITO_SCHEDULE_VERSION`، `PAYAMITO_SCHEDULE_DIR`، `PAYAMITO_SCHEDULE_URL`
- تابع `enqueue_scripts` برای لود صحیح JS فقط در صفحه افزونه

### Removed
- اطلاعات ورود (username/password) از hardcode داخل سورس کد حذف شدند

### Fixed
- عدم استفاده از null coalescing روی `$_POST['test_args']` که باعث PHP notice می‌شد
- چک `is_soap_fault()` بی‌اثر بود؛ چون exception داخل `Payamito_Api` گرفته می‌شد و error string به اشتباه success نمایش داده می‌شد
- `Payamito_Api::send_pattern_sms` اکنون در صورت خطا `null` برمی‌گرداند به جای string تا تشخیص خطا در لایه بالاتر صحیح باشد
- ایندکس ردیف‌های جدید در JS بر اساس `Date.now()` تولید می‌شود تا پس از حذف ردیف‌ها، تداخل نام فیلدها رخ ندهد

### Security
- اطلاعات احراز هویت پنل پیامک از سورس کد حذف و به آپشن‌های دیتابیس وردپرس منتقل شدند

---

## [1.0.0] - نسخه اولیه

### Added
- ارسال پیامک پترن از طریق وب‌سرویس SOAP پیامیتو
- زمان‌بندی ارسال پیامک پس از تغییر وضعیت سفارش WooCommerce
- پنل ادمین برای مدیریت قوانین زمان‌بندی
- بخش تست ارسال پیامک با کد پترن و متغیرهای JSON
- پشتیبانی از شورت‌کدهای `{billing_first_name}`, `{billing_last_name}`, `{order_id}`, `{order_total}`, `{billing_phone}`
- تأخیر قابل تنظیم بر حسب دقیقه، ساعت یا روز
