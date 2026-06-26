<?php
declare(strict_types=1);

namespace App\Views;

use App\Helpers\Html;
use App\Helpers\I18n;
use App\Services\BrandTheme;

final class InvoiceUi
{
    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed>|null $client
     * @param array<string, mixed> $settings
     */
    public static function printPage(
        array $invoice,
        ?array $client,
        array $settings,
        string $loc
    ): string {
        $dir = I18n::htmlDir($loc);
        $lang = I18n::htmlLang($loc);
        $font = $loc === 'ar'
            ? "'Tajawal','Segoe UI',Tahoma,Arial,sans-serif"
            : "'Segoe UI',system-ui,-apple-system,sans-serif";

        $store = (string) ($settings['store_name'] ?? ($loc === 'ar' ? 'متجر VPS' : 'VPS Store'));
        $logoUrl = BrandTheme::logoUrl($settings);
        $primary = BrandTheme::primary($settings);
        $secondary = BrandTheme::secondary($settings);
        $brandMark = $logoUrl !== ''
            ? '<img src="' . $e($logoUrl) . '" alt="" style="max-height:44px;max-width:120px;object-fit:contain">'
            : '🖥️';
        $cur = (string) ($settings['currency'] ?? 'USD');
        $number = (string) ($invoice['number'] ?? '');
        $paid = !empty($invoice['paid']);
        $amount = number_format((float) ($invoice['amount'] ?? 0), 2);
        $months = (int) ($invoice['months'] ?? 1);
        $desc = trim((string) ($invoice['desc'] ?? ''));
        if ($desc === '') {
            $desc = I18n::t('admin.invoices.default_desc', $loc);
        }
        $created = substr((string) ($invoice['created_at'] ?? ''), 0, 10);
        $paidAt = !empty($invoice['paid_at']) ? substr((string) $invoice['paid_at'], 0, 10) : '';
        $buyerName = (string) ($client['name'] ?? $invoice['buyer_name'] ?? '—');
        $buyerPhone = (string) ($client['phone'] ?? $invoice['phone'] ?? '—');
        $footer = trim((string) ($settings['invoice_footer'] ?? ''));
        $payInstr = trim((string) ($settings['manual_payment_instructions'] ?? ''));
        $year = date('Y');

        $statusCls = $paid ? 'inv-status-paid' : 'inv-status-unpaid';
        $statusLbl = I18n::t($paid ? 'admin.badge.paid' : 'admin.badge.unpaid', $loc);
        $paidStamp = $paid
            ? '<div class="inv-paid-stamp" aria-hidden="true">' . Html::esc(I18n::t('admin.invoices.print_paid_stamp', $loc)) . '</div>'
            : '';

        $monthsLbl = I18n::t('admin.invoices.print_months', $loc, ['n' => $months]);

        $e = Html::esc(...);

        $css = self::printCss();

        $payBlock = '';
        if ($paid && $paidAt !== '') {
            $payBlock .= '<div class="inv-note inv-note-ok">'
                . $e(I18n::t('admin.invoices.print_paid_at', $loc, ['date' => $paidAt]))
                . '</div>';
        } elseif (!$paid && $payInstr !== '') {
            $payBlock .= '<div class="inv-note inv-note-warn">'
                . '<strong>' . $e(I18n::t('admin.invoices.print_pay_method', $loc)) . '</strong>'
                . '<div class="inv-pay-text">' . nl2br($e($payInstr)) . '</div></div>';
        }

        $footerBlock = $footer !== ''
            ? '<footer class="inv-footer">' . nl2br($e($footer)) . '</footer>'
            : '';

        return '<!doctype html><html lang="' . $e($lang) . '" dir="' . $e($dir) . '"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $e($number) . ' — ' . $e($store) . '</title>'
            . '<style>:root{--font:' . $font . '}</style>'
            . '<style>' . $css . '</style></head>'
            . '<body class="' . ($paid ? 'inv-is-paid' : 'inv-is-unpaid') . '">'
            . '<div class="inv-sheet">'
            . $paidStamp
            . '<header class="inv-header" style="background:linear-gradient(135deg,' . $e($primary) . ' 0%,' . $e($secondary) . ' 100%)">'
            . '<div class="inv-brand">'
            . '<div class="inv-brand-mark" aria-hidden="true">' . $brandMark . '</div>'
            . '<div><div class="inv-store">' . $e($store) . '</div>'
            . '<div class="inv-store-sub">' . $e(I18n::t('admin.invoices.print_tagline', $loc)) . '</div></div>'
            . '</div>'
            . '<div class="inv-meta">'
            . '<div class="inv-doc-type">' . $e(I18n::t('admin.invoices.print_title', $loc)) . '</div>'
            . '<div class="inv-doc-no">' . $e($number) . '</div>'
            . '<div class="inv-doc-date">' . $e(I18n::t('admin.invoices.print_issued', $loc, ['date' => $created])) . '</div>'
            . '<span class="inv-status ' . $statusCls . '">' . $e($statusLbl) . '</span>'
            . '</div></header>'
            . '<section class="inv-bill">'
            . '<div class="inv-bill-label">' . $e(I18n::t('admin.invoices.print_bill_to', $loc)) . '</div>'
            . '<div class="inv-bill-name">' . $e($buyerName) . '</div>'
            . '<div class="inv-bill-phone mono">' . $e($buyerPhone) . '</div>'
            . '</section>'
            . '<table class="inv-table"><thead><tr>'
            . '<th>' . $e(I18n::t('admin.common.desc', $loc)) . '</th>'
            . '<th class="inv-col-qty">' . $e(I18n::t('admin.invoices.print_duration', $loc)) . '</th>'
            . '<th class="inv-col-amt">' . $e(I18n::t('admin.common.amount', $loc)) . '</th>'
            . '</tr></thead><tbody><tr>'
            . '<td>' . $e($desc) . '</td>'
            . '<td class="inv-col-qty">' . $e($monthsLbl) . '</td>'
            . '<td class="inv-col-amt mono">' . $e($amount . ' ' . $cur) . '</td>'
            . '</tr></tbody></table>'
            . '<div class="inv-total-row">'
            . '<span class="inv-total-label">' . $e(I18n::t('admin.invoices.print_total', $loc)) . '</span>'
            . '<span class="inv-total-amt mono">' . $e($amount . ' ' . $cur) . '</span>'
            . '</div>'
            . $payBlock
            . $footerBlock
            . '<div class="inv-print-bar no-print">'
            . '<button type="button" class="inv-print-btn" onclick="window.print()">'
            . $e(I18n::t('admin.common.print', $loc)) . '</button>'
            . '</div>'
            . '<div class="inv-copy no-print">© ' . $year . ' ' . $e($store) . '</div>'
            . '</div></body></html>';
    }

    private static function printCss(): string
    {
        return <<<'CSS'
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:#e8edf3;color:#1a2332;line-height:1.5;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.mono{direction:ltr;unicode-bidi:isolate;font-family:Consolas,"Courier New",monospace}
.inv-sheet{max-width:760px;margin:24px auto;background:#fff;border:1px solid #d5dde8;border-radius:4px;box-shadow:0 8px 32px rgba(15,30,50,.08);padding:0;position:relative;overflow:hidden}
.inv-header{display:-webkit-box;display:flex;-webkit-box-pack:justify;justify-content:space-between;-webkit-box-align:start;align-items:flex-start;gap:20px;padding:28px 32px 22px;background:linear-gradient(135deg,#0f2d52 0%,#1a4a8a 100%);color:#fff}
.inv-brand{display:-webkit-box;display:flex;-webkit-box-align:center;align-items:center;gap:14px}
.inv-brand-mark{width:48px;height:48px;border-radius:10px;background:rgba(255,255,255,.15);display:-webkit-box;display:flex;-webkit-box-align:center;align-items:center;-webkit-box-pack:center;justify-content:center;font-size:24px;-webkit-flex-shrink:0;flex-shrink:0}
.inv-store{font-size:22px;font-weight:800;letter-spacing:-.02em}
.inv-store-sub{font-size:12px;opacity:.85;margin-top:2px}
.inv-meta{text-align:end;-webkit-flex-shrink:0;flex-shrink:0}
html[dir="rtl"] .inv-meta{text-align:start}
.inv-doc-type{font-size:11px;text-transform:uppercase;letter-spacing:.12em;opacity:.8}
.inv-doc-no{font-size:26px;font-weight:900;margin:4px 0 6px;letter-spacing:.02em}
.inv-doc-date{font-size:13px;opacity:.9;margin-bottom:10px}
.inv-status{display:inline-block;padding:5px 14px;border-radius:99px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.inv-status-paid{background:#d4f5e4;color:#0d5c36}
.inv-status-unpaid{background:#ffe8cc;color:#8a4b00}
.inv-bill{padding:22px 32px 8px;border-bottom:1px solid #e8edf3}
.inv-bill-label{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:#6b7c93;font-weight:700;margin-bottom:6px}
.inv-bill-name{font-size:18px;font-weight:800;color:#1a2332}
.inv-bill-phone{font-size:14px;color:#4a5d78;margin-top:4px}
.inv-table{width:100%;border-collapse:collapse;margin:0}
.inv-table thead th{padding:12px 32px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;font-weight:700;text-align:start;border-bottom:2px solid #e8edf3;background:#f7f9fc}
.inv-table tbody td{padding:16px 32px;font-size:15px;border-bottom:1px solid #e8edf3;vertical-align:top}
.inv-col-qty{width:130px;text-align:center}
.inv-col-amt{width:150px;text-align:end;font-weight:800;color:#1a4a8a}
html[dir="rtl"] .inv-col-amt{text-align:start}
.inv-total-row{display:-webkit-box;display:flex;-webkit-box-pack:justify;justify-content:space-between;-webkit-box-align:center;align-items:center;padding:18px 32px;background:#f0f5fb;border-top:2px solid #1a4a8a}
.inv-total-label{font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#1a4a8a}
.inv-total-amt{font-size:22px;font-weight:900;color:#0f2d52}
.inv-note{margin:20px 32px 0;padding:14px 16px;border-radius:8px;font-size:14px;line-height:1.65}
.inv-note-ok{background:#edf9f2;border:1px solid #b8e6cc;color:#0d5c36}
.inv-note-warn{background:#fff8ed;border:1px solid #f0d9a8;color:#6b4a12}
.inv-pay-text{margin-top:8px}
.inv-footer{margin:20px 32px 24px;padding-top:16px;border-top:1px solid #e8edf3;font-size:12px;color:#6b7c93;line-height:1.7;text-align:center}
.inv-paid-stamp{position:absolute;top:120px;right:40px;left:auto;transform:rotate(-18deg);border:4px solid rgba(13,92,54,.35);color:rgba(13,92,54,.4);font-size:42px;font-weight:900;padding:8px 28px;border-radius:8px;letter-spacing:.08em;pointer-events:none;z-index:2;text-transform:uppercase}
html[dir="rtl"] .inv-paid-stamp{right:auto;left:40px;transform:rotate(18deg)}
.inv-print-bar{padding:16px 32px 20px;text-align:center;background:#f7f9fc;border-top:1px solid #e8edf3}
.inv-print-btn{padding:12px 32px;border:0;border-radius:8px;background:#1a4a8a;color:#fff;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;min-height:44px}
.inv-print-btn:hover{background:#0f2d52}
.inv-copy{text-align:center;font-size:11px;color:#9aa8bc;padding-bottom:16px}
.no-print{}
@media print{
body{background:#fff}
.inv-sheet{max-width:100%;margin:0;border:0;box-shadow:none;border-radius:0}
.no-print{display:none!important}
.inv-print-bar{display:none!important}
.inv-copy{display:none!important}
@page{size:A4;margin:12mm}
}
@media(max-width:600px){
.inv-header{-webkit-box-orient:vertical;-webkit-flex-direction:column;flex-direction:column}
.inv-meta{text-align:start;width:100%}
.inv-table thead th,.inv-table tbody td{padding-left:16px;padding-right:16px}
.inv-bill,.inv-total-row,.inv-note,.inv-footer{padding-left:16px;padding-right:16px}
.inv-paid-stamp{font-size:28px;top:100px;right:16px}
}
CSS;
    }
}
