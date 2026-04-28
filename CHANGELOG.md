# Changelog

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

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
