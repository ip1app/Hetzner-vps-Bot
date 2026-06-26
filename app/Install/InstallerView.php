<?php
declare(strict_types=1);

namespace App\Install;

use App\Helpers\Html;
use App\Helpers\Url;
use App\Services\AdminPath;
use App\Services\DevCredit;

final class InstallerView
{
    private const CSS = <<<'CSS'
body{font-family:'Segoe UI',Tahoma,sans-serif;background:#0b1626;color:#e8eef7;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{background:rgba(19,35,58,.92);border:1px solid #25405f;border-radius:20px;padding:32px;max-width:720px;width:100%}
h1{font-size:24px;margin-bottom:8px}
.sub{color:#9fb3cc;margin-bottom:20px}
.steps{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
.step-pill{padding:6px 12px;border-radius:999px;font-size:13px;background:#1a2d47;color:#9fb3cc}
.step-pill.active{background:#1f7ae0;color:#fff}
.step-pill.done{background:#12301f;color:#a9f0c6}
label{display:block;margin:12px 0 6px}
input{width:100%;padding:12px;border-radius:10px;border:1px solid #2c4a6e;background:#0d1a2c;color:#fff;box-sizing:border-box}
.btn{display:inline-block;padding:14px 20px;border:0;border-radius:12px;background:linear-gradient(90deg,#1f7ae0,#19a974);color:#fff;font-weight:800;cursor:pointer;margin-top:16px;text-decoration:none}
.btn-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
.btn-secondary{background:#2c4a6e}
.err{background:#3a1620;color:#ffb9c6;padding:12px;border-radius:10px;margin-bottom:12px;white-space:pre-wrap}
.alert-danger{background:#4a1218;border:2px solid #ff6b6b;color:#ffd4d8;padding:16px;border-radius:12px;margin:16px 0;line-height:1.7}
.warn{background:#3a2f12;color:#ffd479;padding:12px;border-radius:10px;margin-bottom:12px}
.ok{background:#12301f;color:#a9f0c6;padding:12px;border-radius:10px;margin-bottom:12px}
.lang-btns{display:flex;gap:16px;justify-content:center;flex-wrap:wrap;margin-top:24px}
.lang-btn{min-width:150px;text-align:center;font-size:18px}
.hint{color:#9fb3cc;font-size:14px;margin-top:12px}
.install-foot{margin-top:28px;padding-top:20px;border-top:1px solid #25405f;text-align:center}
.install-foot p{margin:6px 0;color:#9fb3cc;font-size:13px}
.install-foot a{color:#6eb5ff;text-decoration:none}
.install-foot a:hover{text-decoration:underline}
CSS;

    /** @param array<string, string> $vars */
    public static function t(string $key, string $loc, array $vars = []): string
    {
        $en = [
            'title' => 'Installation',
            'pick_lang' => 'Choose installation language',
            'pick_lang_sub' => 'You can change store and admin language later in settings.',
            'step_store' => 'Store',
            'step_db' => 'Database',
            'step_admin' => 'Admin',
            'step_done' => 'Done',
            's1_title' => 'Store setup',
            's1_sub' => 'Basic store information — you can change this later.',
            'store_name' => 'Store / business name',
            'store_ph' => 'My VPS Store',
            'currency' => 'Currency',
            's2_title' => 'MySQL database',
            's2_sub' => 'Enter MySQL credentials (create the database in cPanel first).',
            'db_host' => 'Host',
            'db_port' => 'Port',
            'db_name' => 'Database name',
            'db_user' => 'Username',
            'db_pass' => 'Password',
            'test_db' => 'Test connection',
            'test_ok' => 'Connection successful',
            'test_fail' => 'Connection failed: {msg}',
            's3_title' => 'Admin account',
            's3_sub' => 'Credentials for the admin panel. Bot and API tokens are added later.',
            'admin_user' => 'Admin username',
            'admin_pass' => 'Password (min 6 characters)',
            'admin_pass2' => 'Confirm password',
            'admin_hint' => 'Save these credentials — they are stored in .env on the server.',
            'next' => 'Next',
            'finish' => 'Finish installation',
            'done_title' => 'Installation complete',
            'done_ok' => 'Your platform is ready.',
            'done_cleanup_ok' => 'Install files were removed automatically.',
            'done_cleanup_partial' => 'Some install files could not be removed automatically ({files}). A red warning will appear in the admin panel until you delete them.',
            'delete_title' => '⚠️ Required: delete the install folder',
            'delete_body' => 'For security, delete the install/ folder from your server now (cPanel File Manager or FTP).',
            'open_admin' => 'Open admin panel',
            'open_store' => 'View store',
            'next_steps' => 'Next: log in to admin → connect Telegram bot → add Hetzner → configure PayPal.',
            'locked_title' => 'Already installed',
            'locked_sub' => 'Installation is locked. Use the admin panel to manage your platform.',
            'locked_hint' => 'To reinstall: delete data/installed.lock and .env, then open setup again.',
            'err_store' => 'Store name is required',
            'err_mysql' => 'Database name and username are required',
            'err_pdo_mysql' => 'PHP MySQL driver (pdo_mysql) is not enabled on this server.',
            'err_pass_short' => 'Password must be at least 6 characters',
            'err_pass_match' => 'Passwords do not match',
            'err_finish' => 'Installation failed. See details below.',
            'err_writable' => 'Cannot write files — set data/ and project root writable (755 or 775).',
            'err_post' => 'Form submission failed. Please try again.',
            'footer_credit' => 'Programming & development {author}',
        ];
        $ar = [
            'title' => 'التثبيت',
            'pick_lang' => 'اختر لغة التثبيت',
            'pick_lang_sub' => 'يمكنك تغيير لغة المتجر والأدمن لاحقاً من الإعدادات.',
            'step_store' => 'المتجر',
            'step_db' => 'قاعدة البيانات',
            'step_admin' => 'الأدمن',
            'step_done' => 'تم',
            's1_title' => 'إعداد المتجر',
            's1_sub' => 'معلومات أساسية — يمكن تغييرها لاحقاً.',
            'store_name' => 'اسم المتجر / النشاط',
            'store_ph' => 'متجر VPS',
            'currency' => 'العملة',
            's2_title' => 'قاعدة بيانات MySQL',
            's2_sub' => 'أدخل بيانات MySQL (أنشئ القاعدة من cPanel أولاً).',
            'db_host' => 'المضيف',
            'db_port' => 'المنفذ',
            'db_name' => 'اسم القاعدة',
            'db_user' => 'المستخدم',
            'db_pass' => 'كلمة المرور',
            'test_db' => 'اختبار الاتصال',
            'test_ok' => 'تم الاتصال بنجاح',
            'test_fail' => 'فشل الاتصال: {msg}',
            's3_title' => 'حساب الأدمن',
            's3_sub' => 'بيانات دخول لوحة التحكم — توكن البوت و Hetzner تُضاف لاحقاً.',
            'admin_user' => 'اسم المستخدم',
            'admin_pass' => 'كلمة المرور (6 أحرف على الأقل)',
            'admin_pass2' => 'تأكيد كلمة المرور',
            'admin_hint' => 'احفظ البيانات — تُخزَّن في .env على السيرفر.',
            'next' => 'التالي',
            'finish' => 'إنهاء التثبيت',
            'done_title' => 'اكتمل التثبيت',
            'done_ok' => 'منصتك جاهزة.',
            'done_cleanup_ok' => 'تم حذف ملفات التثبيت تلقائياً.',
            'done_cleanup_partial' => 'تعذّر حذف بعض ملفات التثبيت تلقائياً ({files}). سيظهر تنبيه أحمر في لوحة التحكم حتى تحذفها.',
            'delete_title' => '⚠️ مطلوب: احذف مجلد التثبيت',
            'delete_body' => 'للأمان، احذف مجلد install/ من السيرفر الآن (مدير الملفات في cPanel أو FTP).',
            'open_admin' => 'فتح لوحة التحكم',
            'open_store' => 'عرض المتجر',
            'next_steps' => 'التالي: الأدمن → ربط بوت تلجرام → Hetzner → PayPal.',
            'locked_title' => 'مثبّت مسبقاً',
            'locked_sub' => 'التثبيت مقفول. استخدم لوحة التحكم لإدارة المنصة.',
            'locked_hint' => 'لإعادة التثبيت: احذف data/installed.lock و .env ثم افتح setup.php.',
            'err_store' => 'اسم المتجر مطلوب',
            'err_mysql' => 'اسم القاعدة والمستخدم مطلوبان',
            'err_pdo_mysql' => 'تعريف MySQL في PHP (pdo_mysql) غير مفعّل على السيرفر.',
            'err_pass_short' => 'كلمة المرور يجب ألا تقل عن 6 أحرف',
            'err_pass_match' => 'كلمتا المرور غير متطابقتين',
            'err_finish' => 'فشل التثبيت — التفاصيل أدناه.',
            'err_writable' => 'تعذّر الكتابة — اضبط صلاحيات data/ وجذر المشروع (755 أو 775).',
            'err_post' => 'فشل إرسال النموذج. حاول مرة أخرى.',
            'footer_credit' => 'برمجة وتطوير {author}',
        ];
        $table = $loc === 'ar' ? $ar : $en;
        $text = $table[$key] ?? $key;
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', $v, $text);
        }
        return $text;
    }

    public static function language(): string
    {
        $e = Html::esc(...);
        $base = $e($_SERVER['SCRIPT_NAME'] ?? '/setup.php');
        $body = '<p class="sub" style="text-align:center">' . $e('Choose installation language / اختر لغة التثبيت') . '</p>'
            . '<p class="hint" style="text-align:center">' . $e('You can change language later / يمكنك تغيير اللغة لاحقاً') . '</p>'
            . '<div class="lang-btns">'
            . '<a class="btn lang-btn" href="' . $base . '?lang=en">English</a>'
            . '<a class="btn lang-btn" href="' . $base . '?lang=ar">العربية</a>'
            . '</div>';
        return self::page('en', 'Installation / التثبيت', '', $body, 0);
    }

    /** @param array<string, string> $vals */
    public static function store(string $loc, array $vals, string $action, string $err = ''): string
    {
        $e = Html::esc(...);
        $body = ($err ? '<div class="err">' . $e($err) . '</div>' : '')
            . '<p class="sub">' . $e(self::t('s1_sub', $loc)) . '</p>'
            . '<form method="post" action="' . $e($action) . '">'
            . '<input type="hidden" name="action" value="store">'
            . '<input type="hidden" name="locale" value="' . $e($loc) . '">'
            . '<label>' . $e(self::t('store_name', $loc)) . '</label>'
            . '<input name="store_name" value="' . $e($vals['store_name'] ?? '') . '" placeholder="' . $e(self::t('store_ph', $loc)) . '" required>'
            . '<label>' . $e(self::t('currency', $loc)) . '</label>'
            . '<input name="currency" value="' . $e($vals['currency'] ?? 'USD') . '">'
            . '<button type="submit" class="btn">' . $e(self::t('next', $loc)) . '</button></form>';
        return self::page($loc, self::t('s1_title', $loc), self::t('step_store', $loc) . ' (1/3)', $body, 1);
    }

    /** @param array<string, string> $vals */
    public static function database(string $loc, array $vals, string $action, string $err = '', string $ok = '', bool $mysqlReady = true): string
    {
        $e = Html::esc(...);
        $pdoWarn = !$mysqlReady ? '<div class="warn">' . $e(self::t('err_pdo_mysql', $loc)) . '</div>' : '';
        $body = ($err ? '<div class="err">' . $e($err) . '</div>' : '')
            . ($ok ? '<div class="ok">' . $e($ok) . '</div>' : '')
            . $pdoWarn
            . '<p class="sub">' . $e(self::t('s2_sub', $loc)) . '</p>'
            . '<form method="post" action="' . $e($action) . '">'
            . '<input type="hidden" name="locale" value="' . $e($loc) . '">'
            . '<label>' . $e(self::t('db_host', $loc)) . '</label><input name="db_host" value="' . $e($vals['db_host'] ?? 'localhost') . '" required>'
            . '<label>' . $e(self::t('db_port', $loc)) . '</label><input name="db_port" value="' . $e($vals['db_port'] ?? '3306') . '" required>'
            . '<label>' . $e(self::t('db_name', $loc)) . '</label><input name="db_name" value="' . $e($vals['db_name'] ?? '') . '" required>'
            . '<label>' . $e(self::t('db_user', $loc)) . '</label><input name="db_user" value="' . $e($vals['db_user'] ?? '') . '" required>'
            . '<label>' . $e(self::t('db_pass', $loc)) . '</label><input name="db_pass" type="password" value="' . $e($vals['db_pass'] ?? '') . '">'
            . '<div class="btn-row">'
            . '<button type="submit" name="action" value="test_db" class="btn btn-secondary"' . ($mysqlReady ? '' : ' disabled') . '>' . $e(self::t('test_db', $loc)) . '</button>'
            . '<button type="submit" name="action" value="db" class="btn"' . ($mysqlReady ? '' : ' disabled') . '>' . $e(self::t('next', $loc)) . '</button>'
            . '</div></form>';
        return self::page($loc, self::t('s2_title', $loc), self::t('step_db', $loc) . ' (2/3)', $body, 2);
    }

    /** @param array<string, string> $vals */
    public static function admin(string $loc, array $vals, string $action, string $err = ''): string
    {
        $e = Html::esc(...);
        $body = ($err ? '<div class="err">' . $e($err) . '</div>' : '')
            . '<p class="sub">' . $e(self::t('s3_sub', $loc)) . '</p>'
            . '<form method="post" action="' . $e($action) . '">'
            . '<input type="hidden" name="action" value="finish">'
            . '<input type="hidden" name="locale" value="' . $e($loc) . '">'
            . '<label>' . $e(self::t('admin_user', $loc)) . '</label><input name="admin_user" value="' . $e($vals['admin_user'] ?? 'admin') . '">'
            . '<label>' . $e(self::t('admin_pass', $loc)) . '</label><input name="admin_pass" type="password" required>'
            . '<label>' . $e(self::t('admin_pass2', $loc)) . '</label><input name="admin_pass2" type="password" required>'
            . '<p class="hint">' . $e(self::t('admin_hint', $loc)) . '</p>'
            . '<button type="submit" class="btn">' . $e(self::t('finish', $loc)) . '</button></form>';
        return self::page($loc, self::t('s3_title', $loc), self::t('step_admin', $loc) . ' (3/3)', $body, 3);
    }

    public static function done(string $loc, array $remnants = []): string
    {
        $e = Html::esc(...);
        $autoNote = $remnants !== []
            ? '<div class="warn" style="margin-top:12px"><p style="margin:0">'
                . $e(self::t('done_cleanup_partial', $loc, ['files' => implode(', ', $remnants)]))
                . '</p></div>'
            : '<div class="ok" style="margin-top:12px"><p style="margin:0">'
                . $e(self::t('done_cleanup_ok', $loc))
                . '</p></div>';
        $body = '<div class="ok">' . $e(self::t('done_ok', $loc)) . '</div>'
            . $autoNote
            . '<div class="alert-danger"><strong style="font-size:18px;display:block;margin-bottom:8px">'
            . $e(self::t('delete_title', $loc)) . '</strong><p style="margin:0">'
            . $e(self::t('delete_body', $loc)) . '</p></div>'
            . '<p class="hint">' . $e(self::t('next_steps', $loc)) . '</p>'
            . '<a class="btn" href="' . $e(AdminPath::url()) . '">' . $e(self::t('open_admin', $loc)) . '</a> '
            . '<a class="btn" href="' . $e(Url::to('/')) . '">' . $e(self::t('open_store', $loc)) . '</a>';
        return self::page($loc, self::t('done_title', $loc), self::t('step_done', $loc), $body, 4);
    }

    public static function locked(string $loc): string
    {
        $e = Html::esc(...);
        $body = '<p>' . $e(self::t('locked_sub', $loc)) . '</p>'
            . '<p class="hint">' . $e(self::t('locked_hint', $loc)) . '</p>'
            . '<a class="btn" href="' . $e(AdminPath::url()) . '">' . $e(self::t('open_admin', $loc)) . '</a>';
        return self::page($loc, self::t('locked_title', $loc), '', $body, 0);
    }

    private static function footer(string $loc): string
    {
        return DevCredit::installFooterHtml($loc, fn(string $key, array $vars = []) => self::t($key, $loc, $vars));
    }

    private static function page(string $loc, string $title, string $sub, string $body, int $activeStep): string
    {
        $e = Html::esc(...);
        $dir = $loc === 'ar' ? 'rtl' : 'ltr';
        $steps = '';
        if ($activeStep > 0) {
            foreach ([1 => 'step_store', 2 => 'step_db', 3 => 'step_admin'] as $n => $key) {
                $cls = 'step-pill';
                if ($n === $activeStep) {
                    $cls .= ' active';
                } elseif ($n < $activeStep) {
                    $cls .= ' done';
                }
                $steps .= '<span class="' . $cls . '">' . $e(self::t($key, $loc)) . '</span>';
            }
        }
        $subHtml = $sub !== '' ? '<p class="sub">' . $e($sub) . '</p>' : '';
        $stepsHtml = $steps !== '' ? '<div class="steps">' . $steps . '</div>' : '';
        return '<!doctype html><html lang="' . $e($loc) . '" dir="' . $e($dir) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e(self::t('title', $loc)) . '</title>'
            . '<style>' . self::CSS . '</style></head><body><div class="card"><h1>' . $e($title) . '</h1>'
            . $stepsHtml . $subHtml . $body . self::footer($loc) . '</div></body></html>';
    }
}
