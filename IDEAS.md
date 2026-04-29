# ایده‌ها و مسیر توسعه

> این فایل مشکلات فعلی، ایده‌های بهبود و جزئیات فنی پیاده‌سازی را به صورت اولویت‌بندی‌شده مستند می‌کند.

---

## فهرست مطالب

1. [لاگ ارسال پیامک‌ها](#1-لاگ-ارسال-پیامکها) ✅
2. [مکانیزم Retry](#2-مکانیزم-retry) ✅
3. [لغو پیامک هنگام لغو سفارش](#3-لغو-پیامک-هنگام-لغو-سفارش)
4. [جایگزینی WP-Cron با Action Scheduler](#4-جایگزینی-wp-cron-با-action-scheduler) ✅
5. [فرمت‌بندی خودکار شماره تلفن](#5-فرمتبندی-خودکار-شماره-تلفن) ✅
6. [قوانین شرطی پیشرفته](#6-قوانین-شرطی-پیشرفته)
7. [اطلاع‌رسانی به ادمین فروشگاه](#7-اطلاعرسانی-به-ادمین-فروشگاه)
8. [Meta Box در صفحه سفارش](#8-meta-box-در-صفحه-سفارش) ✅
9. [بررسی موجودی پنل](#9-بررسی-موجودی-پنل)
10. [Opt-Out مشتریان](#10-opt-out-مشتریان)
11. [پشتیبانی از چند ارائه‌دهنده SMS](#11-پشتیبانی-از-چند-ارائهدهنده-sms)
12. [ارسال انبوه برای سفارش‌های موجود](#12-ارسال-انبوه-برای-سفارشهای-موجود)
13. [یادداشت خودکار در سفارش](#13-یادداشت-خودکار-در-سفارش) ✅
14. [سازگاری با HPOS](#14-سازگاری-با-hpos)
15. [پشتیبان‌گیری و بازیابی تنظیمات](#15-پشتیبانگیری-و-بازیابی-تنظیمات)
16. [پاکسازی هنگام حذف افزونه](#16-پاکسازی-هنگام-حذف-افزونه)
17. [داشبورد آمار](#17-داشبورد-آمار) ✅
18. [REST API برای تریگر خارجی](#18-rest-api-برای-تریگر-خارجی)
19. [WP-CLI Support](#19-wp-cli-support)
20. [بین‌المللی‌سازی (i18n)](#20-بینالمللیسازی-i18n)
21. [تست‌های خودکار](#21-تستهای-خودکار)
22. [محیط توسعه Docker](#22-محیط-توسعه-docker)

---

## 1. لاگ ارسال پیامک‌ها

> ✅ پیاده‌سازی‌شده در v2.1.0

**مشکل فعلی:** هیچ سابقه‌ای از پیامک‌های ارسال‌شده یا ناموفق وجود ندارد. اگر cron اجرا شود و SMS ارسال نشود، هیچ‌جا ثبت نمی‌شود و ادمین راهی برای دیباگ ندارد.

**پیشنهاد:** یک جدول سفارشی در دیتابیس ایجاد شود و در `execute` نتیجه هر ارسال ثبت گردد.

**اسکیمای جدول:**
```sql
CREATE TABLE {prefix}payamito_sms_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id    BIGINT UNSIGNED NOT NULL,
    mobile      VARCHAR(20)     NOT NULL,
    pattern     VARCHAR(50)     NOT NULL,
    vars        TEXT            NOT NULL,
    status      ENUM('sent','failed','cancelled') NOT NULL DEFAULT 'failed',
    response    TEXT            NULL,
    attempt     TINYINT         NOT NULL DEFAULT 1,
    scheduled_at DATETIME       NOT NULL,
    sent_at     DATETIME        NULL,
    PRIMARY KEY (id),
    KEY order_id (order_id),
    KEY status (status),
    KEY sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**جزئیات پیاده‌سازی:**
- جدول در `register_activation_hook` با `dbDelta` ایجاد شود
- کلاس `Payamito_Logger` مجزا برای عملیات CRUD روی این جدول
- در پنل ادمین یک تب «تاریخچه» با `WP_List_Table` برای نمایش، فیلتر بر اساس وضعیت/تاریخ، و قابلیت جستجو
- یک دستور cron هفتگی برای حذف لاگ‌های قدیمی‌تر از X روز (قابل تنظیم توسط ادمین)

---

## 2. مکانیزم Retry

> ✅ پیاده‌سازی‌شده در v2.6.0

**مشکل فعلی:** اگر سرویس SOAP در لحظه اجرای cron در دسترس نباشد (timeout، قطعی موقت، خطای شبکه)، پیامک برای همیشه از دست می‌رود.

**پیشنهاد:** در صورت خطا، با تأخیر تصاعدی مجدداً زمان‌بندی شود.

**جدول زمانی retry:**
| تلاش | تأخیر |
|------|-------|
| ۱ (اول) | بلافاصله |
| ۲ | ۵ دقیقه |
| ۳ | ۳۰ دقیقه |
| ۴ (آخر) | ۲ ساعت |

**جزئیات پیاده‌سازی:**
```php
// در execute()، پس از دریافت null از API:
$attempt = (int) get_post_meta($order_id, '_payamito_attempt_' . $pattern_code, true);
$delays  = [0, 5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS];

if ($attempt < count($delays) - 1) {
    update_post_meta($order_id, '_payamito_attempt_' . $pattern_code, $attempt + 1);
    wp_schedule_single_event(
        time() + $delays[$attempt + 1],
        'payamito_execute_scheduled_sms',
        [$order_id, $pattern_code, $vars_str]
    );
} else {
    // همه تلاش‌ها تمام شد — لاگ نهایی با status=failed
    delete_post_meta($order_id, '_payamito_attempt_' . $pattern_code);
}
```
- تعداد و فاصله تلاش‌ها از تنظیمات ادمین قابل تغییر باشد
- پس از شکست نهایی، یک ایمیل هشدار به ادمین ارسال شود

---

## 3. لغو پیامک هنگام لغو سفارش

**مشکل فعلی:** اگر سفارشی trigger شود (مثلاً به «در حال پردازش» برود) و پیامک با تأخیر ۲ ساعته زمان‌بندی شده باشد، سپس ظرف آن ۲ ساعت سفارش لغو شود، پیامک همچنان ارسال می‌شود. این تجربه بدی برای مشتری است.

**پیشنهاد:**
```php
// در on_status_change()، اگر وضعیت جدید از نوع «پایان‌دهنده» است:
$terminal_statuses = ['cancelled', 'refunded', 'failed'];
if (in_array($to_status, $terminal_statuses, true)) {
    $this->cancel_pending_sms($order_id);
}

private function cancel_pending_sms(int $order_id): void {
    $rules = get_option('payamito_schedule_rules', []);
    foreach ($rules as $rule) {
        $args      = [$order_id, $rule['pattern'], $rule['vars']];
        $timestamp = wp_next_scheduled('payamito_execute_scheduled_sms', $args);
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'payamito_execute_scheduled_sms', $args);
            // لاگ با status=cancelled
        }
    }
}
```
- وضعیت‌های «پایان‌دهنده» از تنظیمات ادمین قابل تنظیم باشند

---

## 4. جایگزینی WP-Cron با Action Scheduler

> ✅ پیاده‌سازی‌شده در v2.7.0

**مشکل فعلی:** `wp_schedule_single_event` به ترافیک سایت وابسته است — اگر هیچ کاربری از سایت بازدید نکند، cron اجرا نمی‌شود. همچنین WP-Cron برای حجم بالا مناسب نیست و دیباگ آن سخت است.

**پیشنهاد:** از کتابخانه [Action Scheduler](https://actionscheduler.org) که توسط WooCommerce شیپ می‌شود استفاده شود.

**مزایا:**
- جدول مخصوص در دیتابیس با رابط گرافیکی در `WooCommerce > Status > Scheduled Actions`
- اجرا مستقل از ترافیک سایت
- پشتیبانی از صف‌های موازی (parallel queues)
- لاگ داخلی هر action
- قابلیت retry داخلی

**نمونه کد:**
```php
// به جای wp_schedule_single_event:
as_schedule_single_action(
    time() + $delay,
    'payamito_execute_scheduled_sms',
    ['order_id' => $order_id, 'pattern' => $rule['pattern'], 'vars' => $rule['vars']],
    'payamito-sms'  // group برای فیلتر کردن
);

// به جای wp_next_scheduled:
as_has_scheduled_action('payamito_execute_scheduled_sms', ['order_id' => $order_id]);

// به جای wp_unschedule_event:
as_unschedule_action('payamito_execute_scheduled_sms', ['order_id' => $order_id]);
```

**نکته:** چون WooCommerce پیش‌نیاز است، Action Scheduler همیشه در دسترس خواهد بود — نیازی به نصب جداگانه نیست.

---

## 5. فرمت‌بندی خودکار شماره تلفن

> ✅ پیاده‌سازی‌شده در v2.8.0

**مشکل فعلی:** مشتریان شماره را به فرمت‌های متفاوت وارد می‌کنند (`+989120000000`، `00989120000000`، `09120000000`، `9120000000`). سرویس پیامیتو ممکن است برخی فرمت‌ها را رد کند.

**پیشنهاد:**
```php
private function normalize_phone(string $phone): string {
    $phone = preg_replace('/\D/', '', $phone); // حذف غیر عدد

    // تبدیل ۰۰۹۸ یا ۹۸ به ۰
    if (str_starts_with($phone, '0098')) {
        $phone = '0' . substr($phone, 4);
    } elseif (str_starts_with($phone, '98') && strlen($phone) === 12) {
        $phone = '0' . substr($phone, 2);
    }

    // اعتبارسنجی: باید ۱۱ رقم و با ۰۹ شروع شود
    if (strlen($phone) !== 11 || !str_starts_with($phone, '09')) {
        throw new \InvalidArgumentException("Invalid Iranian mobile: {$phone}");
    }

    return $phone;
}
```
- در صورت شماره نامعتبر، لاگ با `status=failed` و دلیل `invalid_phone` ثبت شود

---

## 6. قوانین شرطی پیشرفته

**مشکل فعلی:** قوانین فقط بر اساس وضعیت سفارش فعال می‌شوند. هیچ تمایزی بین سفارش ۵۰ هزار تومانی و ۵۰ میلیون تومانی نیست.

**پیشنهاد:** به هر قانون یک بخش «شرط‌ها» اضافه شود. قانون فقط در صورتی اجرا شود که **همه** شرط‌ها برقرار باشند.

**ساختار داده پیشنهادی:**
```json
{
  "status": "wc-completed",
  "delay_val": 10,
  "delay_unit": "minutes",
  "pattern": "12345",
  "vars": "name:{billing_first_name}",
  "conditions": [
    { "type": "order_total",    "operator": ">=", "value": "500000" },
    { "type": "payment_method", "operator": "=",  "value": "zarinpal" },
    { "type": "product_cat",    "operator": "in", "value": "electronics,accessories" },
    { "type": "shipping_method","operator": "!=", "value": "free_shipping" }
  ]
}
```

**انواع شرط‌های پیشنهادی:**
| نوع | توضیح |
|-----|-------|
| `order_total` | مبلغ کل سفارش |
| `item_count` | تعداد آیتم‌ها |
| `payment_method` | روش پرداخت |
| `shipping_method` | روش ارسال |
| `product_id` | شامل محصول خاص |
| `product_cat` | شامل دسته‌بندی خاص |
| `customer_orders_count` | تعداد کل سفارش‌های مشتری (برای تشخیص مشتری جدید/قدیمی) |
| `billing_city` | شهر خریدار |
| `coupon_used` | استفاده از کوپن خاص |

**پیاده‌سازی:**
```php
private function evaluate_conditions(array $conditions, WC_Abstract_Order $order): bool {
    foreach ($conditions as $condition) {
        if (!$this->check_condition($condition, $order)) return false;
    }
    return true;
}
```

---

## 7. اطلاع‌رسانی به ادمین فروشگاه

**مشکل فعلی:** فقط خریدار پیامک دریافت می‌کند. ادمین فروشگاه هیچ اطلاعی از سفارش‌های جدید از طریق SMS دریافت نمی‌کند.

**پیشنهاد:** در تعریف هر قانون، یک بخش اختیاری «اطلاع‌رسانی به ادمین» اضافه شود:

```json
{
  "admin_notify": {
    "enabled": true,
    "mobile": "09120000000",
    "pattern": "99999",
    "vars": "order:{order_id};amount:{order_total}"
  }
}
```

- شماره ادمین می‌تواند از تنظیمات WooCommerce خوانده شود یا به صورت دستی وارد شود
- ارسال پیامک ادمین بلافاصله و بدون تأخیر انجام می‌شود (تأخیر فقط برای مشتری است)
- چند شماره ادمین با `,` جداشده پشتیبانی شود

---

## 8. Meta Box در صفحه سفارش

> ✅ پیاده‌سازی‌شده در v2.4.0

**مشکل فعلی:** از صفحه ویرایش سفارش در ادمین، راهی برای دیدن وضعیت پیامک‌های مرتبط با آن سفارش وجود ندارد.

**پیشنهاد:** یک Meta Box در صفحه ویرایش سفارش اضافه شود که نشان دهد:
- چه پیامک‌هایی برای این سفارش زمان‌بندی شده (در انتظار)
- چه پیامک‌هایی ارسال شده (با زمان و پاسخ سرویس)
- چه پیامک‌هایی ناموفق بوده
- دکمه «ارسال مجدد» برای پیامک‌های ناموفق
- دکمه «ارسال اکنون» برای پیامک‌های در صف

```php
add_action('add_meta_boxes', function() {
    add_meta_box(
        'payamito_sms_status',
        'وضعیت پیامک‌های پیامیتو',
        [Payamito_Admin::class, 'render_order_meta_box'],
        wc_get_page_screen_id('shop-order'),
        'side',
        'default'
    );
});
```

---

## 9. بررسی موجودی پنل

**مشکل فعلی:** اگر موجودی پنل تمام شود، هیچ اطلاع‌رسانی‌ای صورت نمی‌گیرد. پیامک‌ها ارسال نمی‌شوند اما ادمین متوجه نمی‌شود.

**پیشنهاد:**

```php
// در Payamito_Api:
public function get_credit(): int|false {
    try {
        $client = new SoapClient($this->endpoint, [...]);
        $result = $client->GetCredit([
            'username' => $this->username,
            'password' => $this->password,
        ]);
        return (int) ($result->GetCreditResult ?? false);
    } catch (Exception $e) {
        return false;
    }
}
```

- موجودی هر ۶ ساعت با یک cron خوانده و در یک transient ذخیره شود
- اگر موجودی کمتر از آستانه تعریف‌شده (مثلاً ۱۰۰۰ پیامک) بود، یک `admin_notice` با کلاس `notice-warning` در داشبورد نمایش داده شود
- ایمیل هشدار به ایمیل ادمین وردپرس ارسال شود
- موجودی فعلی در پنل افزونه با یک دکمه «بروزرسانی» نمایش داده شود

---

## 10. Opt-Out مشتریان

**مشکل فعلی:** مشتریان هیچ راهی برای لغو دریافت پیامک ندارند. این در برخی کشورها الزام قانونی دارد و در ایران نیز شیوه مناسبی است.

**پیشنهاد:**

**روش ۱ — از طریق حساب کاربری:**
- یک چک‌باکس «دریافت پیامک اطلاع‌رسانی» در صفحه «حساب کاربری» WooCommerce
- این تنظیم در user meta ذخیره شود: `payamito_sms_opt_out`
- در `execute()` پیش از ارسال بررسی شود

**روش ۲ — در checkout:**
- یک فیلد اختیاری در صفحه تسویه‌حساب
- در order meta ذخیره شود

```php
// در execute():
$user_id = $order->get_customer_id();
if ($user_id && get_user_meta($user_id, 'payamito_sms_opt_out', true)) {
    // لاگ با status=skipped, reason=opt_out
    return;
}
```

---

## 11. پشتیبانی از چند ارائه‌دهنده SMS

**مشکل فعلی:** افزونه کاملاً به پیامیتو وابسته است. اگر سرویس خاموش باشد، هیچ fallback وجود ندارد.

**پیشنهاد:** یک interface تعریف شود:

```php
interface SMS_Provider_Interface {
    public function send_pattern_sms(string $mobile, int|string $body_id, array $args): mixed;
    public function get_credit(): int|false;
    public function get_name(): string;
}

class Payamito_Api implements SMS_Provider_Interface { ... }
class Melipayamak_Api implements SMS_Provider_Interface { ... }
class Kavenegar_Api implements SMS_Provider_Interface { ... }
```

**کلاس Provider Factory:**
```php
class SMS_Provider_Factory {
    public static function make(string $provider, array $credentials): SMS_Provider_Interface {
        return match ($provider) {
            'payamito'    => new Payamito_Api($credentials['username'], $credentials['password']),
            'melipayamak' => new Melipayamak_Api($credentials['username'], $credentials['password']),
            'kavenegar'   => new Kavenegar_Api($credentials['api_key']),
            default       => throw new \InvalidArgumentException("Unknown SMS provider: {$provider}"),
        };
    }
}
```

- در تنظیمات ادمین، یک dropdown برای انتخاب ارائه‌دهنده
- پشتیبانی از fallback: اگر ارائه‌دهنده اصلی ناموفق بود، از ارائه‌دهنده دوم استفاده شود

---

## 12. ارسال انبوه برای سفارش‌های موجود

**مشکل فعلی:** افزونه فقط برای سفارش‌های جدید کار می‌کند. نمی‌توان برای سفارش‌های قدیمی پیامک ارسال کرد.

**پیشنهاد:** یک بخش «ارسال انبوه» در پنل ادمین:

```
فیلترها:
- بازه تاریخ (از: تا:)
- وضعیت سفارش
- حداقل مبلغ سفارش
- کد پترن و متغیرها

→ دکمه «پیش‌نمایش» — تعداد سفارش‌های واجد شرایط را نشان می‌دهد
→ دکمه «ارسال» — با یک confirm dialog
```

**پیاده‌سازی امن:**
- ارسال به صورت batch (مثلاً ۵۰ پیامک در هر cron) انجام شود تا سرویس SOAP overload نشود
- یک transient برای نگه‌داشتن وضعیت پیشرفت ارسال انبوه
- Progress bar در ادمین با polling از یک REST endpoint
- قابلیت لغو ارسال انبوه در حین اجرا

---

## 13. یادداشت خودکار در سفارش

> ✅ پیاده‌سازی‌شده در v2.2.0

**مشکل فعلی:** هیچ سابقه‌ای در خود سفارش ثبت نمی‌شود. اگر مشتری تماس بگیرد و بگوید پیامک دریافت نکرده، ادمین راهی برای بررسی ندارد.

**پیشنهاد:** پس از هر ارسال (موفق یا ناموفق)، یک یادداشت داخلی در سفارش ثبت شود:

```php
// پس از ارسال موفق:
$order->add_order_note(
    sprintf(
        '[پیامیتو] پیامک با پترن %s به شماره %s ارسال شد. (کد پاسخ: %s)',
        $pattern_code,
        $this->mask_phone($phone),   // نمایش ماسک‌شده: 091****4321
        $response_code
    ),
    false,  // internal note, not visible to customer
    false
);

// پس از شکست:
$order->add_order_note(
    sprintf('[پیامیتو] خطا در ارسال پیامک پترن %s — تلاش %d از %d', ...),
    false, false
);
```

- شماره تلفن به صورت ماسک‌شده نمایش داده شود

---

## 14. سازگاری با HPOS

**مشکل فعلی:** افزونه سازگاری رسمی با High-Performance Order Storage را اعلام نکرده. در WooCommerce 8.0+ که HPOS پیش‌فرض است، ممکن است warning نمایش داده شود.

**پیشنهاد:**

در `payamito-schedule.php`:
```php
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});
```

- از `$order->get_meta()` و `$order->update_meta_data()` به جای `get_post_meta` / `update_post_meta` استفاده شود
- کلاس `WC_Abstract_Order` در type hint به جای `WC_Order` برای پشتیبانی از refunds هم

---

## 15. پشتیبان‌گیری و بازیابی تنظیمات

**مشکل فعلی:** انتقال تنظیمات بین محیط‌های dev/staging/production نیازمند دسترسی مستقیم به دیتابیس است.

**پیشنهاد:** در پنل ادمین دو دکمه:

```php
// Export:
$export = [
    'version' => PAYAMITO_SCHEDULE_VERSION,
    'exported_at' => current_time('c'),
    'rules' => get_option('payamito_schedule_rules', []),
    // اعتبارنامه export نمی‌شود به دلایل امنیتی
];
header('Content-Disposition: attachment; filename="payamito-rules-' . date('Y-m-d') . '.json"');
echo wp_json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// Import:
// اعتبارسنجی JSON و ساختار قبل از ذخیره
// تأیید اینکه version با نسخه فعلی سازگار است
// گزینه merge (ادغام با قوانین موجود) یا replace (جایگزینی کامل)
```

---

## 16. پاکسازی هنگام حذف افزونه

**مشکل فعلی:** هیچ `uninstall.php` وجود ندارد. آپشن‌های `payamito_credentials`، `payamito_schedule_rules`، جدول لاگ و تمام cron event های در صف پس از حذف افزونه در دیتابیس باقی می‌مانند.

**پیشنهاد:** فایل `uninstall.php`:

```php
<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

// بررسی گزینه «حذف داده‌ها هنگام uninstall»
$settings = get_option('payamito_settings', []);
if (empty($settings['delete_data_on_uninstall'])) return;

// حذف آپشن‌ها
delete_option('payamito_credentials');
delete_option('payamito_schedule_rules');
delete_option('payamito_settings');

// لغو تمام cron های در صف
$crons = _get_cron_array();
foreach ($crons as $timestamp => $hooks) {
    if (isset($hooks['payamito_execute_scheduled_sms'])) {
        wp_unschedule_hook('payamito_execute_scheduled_sms');
    }
}

// حذف جدول لاگ
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}payamito_sms_log");
```

---

## 17. داشبورد آمار

> ✅ پیاده‌سازی‌شده در v2.3.0

**پیشنهاد:** یک صفحه «آمار» در پنل افزونه با نمودارهای ساده:

| شاخص | توضیح |
|-------|-------|
| پیامک‌های ارسال‌شده ۳۰ روز اخیر | سری زمانی روزانه |
| نرخ موفقیت | درصد موفق / ناموفق |
| پرکاربردترین پترن‌ها | رتبه‌بندی pattern_code |
| میانگین تأخیر اجرا | فاصله scheduled_at تا sent_at |
| موجودی پنل | آخرین بار بررسی‌شده |

- از کتابخانه Chart.js (که WordPress به صورت پیش‌فرض شیپ نمی‌کند) یا SVG ساده استفاده شود
- داده‌ها از جدول لاگ aggregate شوند
- داده‌های aggregate در یک transient با TTL یک ساعته کش شوند

---

## 18. REST API برای تریگر خارجی

**پیشنهاد:** یک endpoint برای ارسال پیامک از سیستم‌های خارجی (مثلاً CRM، ERP):

```
POST /wp-json/payamito/v1/send
Authorization: Bearer {application_password}

{
  "mobile": "09120000000",
  "pattern": "12345",
  "vars": { "name": "علی", "amount": "500000" }
}
```

```php
register_rest_route('payamito/v1', '/send', [
    'methods'             => 'POST',
    'callback'            => [Payamito_Api_Rest::class, 'handle_send'],
    'permission_callback' => function($request) {
        return current_user_can('manage_woocommerce');
    },
    'args' => [
        'mobile'  => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
        'pattern' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
        'vars'    => ['required' => true, 'type' => 'object'],
    ],
]);
```

- Rate limiting: حداکثر ۱۰ درخواست در دقیقه با استفاده از transients
- لاگ تمام درخواست‌های REST API

---

## 19. WP-CLI Support

**پیشنهاد:**

```bash
# تست ارسال
wp payamito send --mobile=09120000000 --pattern=12345 --vars='{"name":"علی"}'

# نمایش قوانین
wp payamito rules list
wp payamito rules export > rules-backup.json
wp payamito rules import rules-backup.json

# مشاهده لاگ
wp payamito log list --status=failed --count=20
wp payamito log retry --id=42
wp payamito log clear --before="30 days ago"

# اطلاعات پنل
wp payamito credit
```

```php
if (defined('WP_CLI') && WP_CLI) {
    require_once PAYAMITO_SCHEDULE_DIR . 'includes/class-cli.php';
    WP_CLI::add_command('payamito', 'Payamito_CLI');
}
```

---

## 20. بین‌المللی‌سازی (i18n)

**مشکل فعلی:** تمام رشته‌ها مستقیم فارسی هستند. افزونه برای فروشگاه‌های دوزبانه یا توزیع در مخزن WordPress.org مناسب نیست.

**پیشنهاد:**
```php
// قبل:
echo 'ذخیره اطلاعات';

// بعد:
echo __('ذخیره اطلاعات', 'payamito-schedule');
```

- یک فایل `languages/payamito-schedule.pot` با `wp i18n make-pot` تولید شود
- در هدر افزونه `Text Domain: payamito-schedule` و `Domain Path: /languages` اضافه شود
- `load_plugin_textdomain` در `plugins_loaded` hook

---

## 21. تست‌های خودکار

**مشکل فعلی:** هیچ تستی وجود ندارد. refactor کردن بدون ترس از regression امکان‌پذیر نیست.

**پیشنهاد:**

**ساختار:**
```
tests/
├── bootstrap.php
├── Unit/
│   ├── ApiTest.php          ← mock SoapClient
│   ├── SchedulerTest.php    ← mock wc_get_order
│   └── PhoneNormalizerTest.php
└── Integration/
    └── RuleEvaluationTest.php
```

**نمونه unit test:**
```php
class PhoneNormalizerTest extends WP_UnitTestCase {
    /**
     * @dataProvider phoneProvider
     */
    public function test_normalize(string $input, string $expected): void {
        $this->assertSame($expected, Payamito_Scheduler::normalize_phone($input));
    }

    public function phoneProvider(): array {
        return [
            'with +98'    => ['+989120000000', '09120000000'],
            'with 0098'   => ['00989120000000', '09120000000'],
            'already 09'  => ['09120000000', '09120000000'],
            'without zero'=> ['9120000000', '09120000000'],
        ];
    }
}
```

- `phpunit.xml` با تنظیمات test suite
- GitHub Actions workflow برای اجرای تست‌ها در هر PR

---

## 22. محیط توسعه Docker

**مشکل فعلی:** راه‌اندازی محیط توسعه محلی برای افزونه‌های WooCommerce دشوار و وقت‌گیر است.

**پیشنهاد:** یک `docker-compose.yml` در ریشه پروژه:

```yaml
services:
  wordpress:
    image: wordpress:6-php8.2
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_DEBUG: 1
    volumes:
      - .:/var/www/html/wp-content/plugins/payamito-schedule
    ports:
      - "8080:80"

  db:
    image: mariadb:11
    environment:
      MARIADB_DATABASE: wordpress
      MARIADB_ROOT_PASSWORD: root

  wpcli:
    image: wordpress:cli
    command: >
      sh -c "
        wp core install --url=localhost:8080 --title=Test --admin_user=admin --admin_password=admin --admin_email=test@test.com &&
        wp plugin install woocommerce --activate &&
        wp plugin activate payamito-schedule
      "
```