<?php
/**
 * ============================================================
 * MANDAI DESIGN — پردازش فرم تماس + خبرنامه
 * ------------------------------------------------------------
 * ▶ تنها کاری که باید بکنید: خط $RECIPIENT_EMAIL را با ایمیل
 *   خودتان عوض کنید. (همین یک خط!)
 *
 * این فایل دو کار می‌کند:
 *   ۱) ذخیره پیام‌ها در files/submissions.csv (همیشه اجرا می‌شود)
 *   ۲) ارسال ایمیل با تابع mail() هاست (اگر فعال باشد)
 *
 * در وردپرس: این فایل را حذف کنید و از Contact Form 7 /
 * WPForms استفاده کنید — فرم‌ها با data-contact-form و
 * data-newsletter-form در index.html مشخص شده‌اند.
 * ============================================================
 */

// ✏️ ویرایش: ایمیل گیرنده پیام‌ها (خودتان)
$RECIPIENT_EMAIL = 'info@example.com';

// ✏️ ویرایش: نام سایت در موضوع ایمیل
$SITE_NAME = 'Mandai Design';

// ────────────────────────────────────────────────────────────
// از اینجا به بعد نیازی به تغییر نیست
// ────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(bool $ok, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'error' => $ok ? '' : $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function clean(string $value): string
{
    return trim(strip_tags($value));
}

// فقط POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Method not allowed.');
}

$formType = clean($_POST['form_type'] ?? 'contact');

// هانی‌پات ضد اسپم: فیلد مخفی «company» باید خالی بماند
if (!empty($_POST['company'])) {
    // ربات است — موفقیت جعلی برمی‌گردانیم
    respond(true);
}

$email = clean($_POST['email'] ?? '');
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.');
}

/* ── پوشه ذخیره‌سازی (محافظت‌شده با htaccess) ─────────────── */
$dataDir = __DIR__ . '/files';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

/* ═══════════════ خبرنامه ═══════════════ */
if ($formType === 'newsletter') {
    $file = $dataDir . '/subscribers.csv';
    $isNew = !file_exists($file);

    // جلوگیری از ثبت تکراری
    if (!$isNew && ($h = @fopen($file, 'r')) !== false) {
        while (($row = fgetcsv($h)) !== false) {
            if (strtolower($row[1] ?? '') === strtolower($email)) {
                fclose($h);
                respond(true, '', ['duplicate' => true]);
            }
        }
        fclose($h);
    }

    $h = @fopen($file, 'a');
    if ($h === false) {
        respond(false, 'Could not save subscription.');
    }
    if ($isNew) {
        fputcsv($h, ['date', 'email', 'ip']);
    }
    fputcsv($h, [date('Y-m-d H:i:s'), $email, $_SERVER['REMOTE_ADDR'] ?? '']);
    fclose($h);

    respond(true, '', ['saved' => true]);
}

/* ═══════════════ فرم تماس ═══════════════ */
$name    = clean($_POST['name'] ?? '');
$phone   = clean($_POST['phone'] ?? '');
$message = clean($_POST['message'] ?? '');
$source  = clean($_POST['source'] ?? '');

if ($name === '' || $message === '') {
    respond(false, 'Please fill in all required fields (name and message).');
}
if (mb_strlen($message) > 5000) {
    respond(false, 'Message is too long.');
}

/* ۱) ذخیره در CSV (پشتیبان مطمئن — حتی اگر ایمیل کار نکند) */
$saved = false;
$file  = $dataDir . '/submissions.csv';
$h = @fopen($file, 'a');
if ($h !== false) {
    if (!file_exists($file) || filesize($file) === 0) {
        fputcsv($h, ['date', 'name', 'email', 'phone', 'source', 'message', 'ip']);
    }
    fputcsv($h, [date('Y-m-d H:i:s'), $name, $email, $phone, $source, $message, $_SERVER['REMOTE_ADDR'] ?? '']);
    fclose($h);
    $saved = true;
}

/* ۲) ارسال ایمیل با mail() هاست */
$mailed = false;
$subject = "[$SITE_NAME] New contact message from $name";
$body    = "Name: $name\n"
         . "Email: $email\n"
         . "Phone: $phone\n"
         . "Source: $source\n"
         . "IP: " . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n\n"
         . "Message:\n$message\n";
$headers = [
    'From: ' . $SITE_NAME . ' <no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>',
    'Reply-To: ' . $name . ' <' . $email . '>',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . phpversion(),
];
if (function_exists('mail')) {
    $mailed = @mail($RECIPIENT_EMAIL, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}

if ($saved || $mailed) {
    respond(true, '', ['saved' => $saved, 'mailed' => $mailed]);
}

respond(false, 'Could not save your message. Please call us directly.');
