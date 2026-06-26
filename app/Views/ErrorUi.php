<?php
declare(strict_types=1);

namespace App\Views;

use App\Helpers\Html;
use App\Helpers\I18n;
use App\Helpers\Url;
use App\Services\AdminPath;
use App\Services\DevCredit;

final class ErrorUi
{
    private const CSS = <<<'CSS'
.err404-wrap{display:flex;align-items:center;justify-content:center;min-height:min(72vh,640px);padding:12px 0 28px}
.err404{position:relative;text-align:center;max-width:520px;width:100%;margin:0 auto;padding:36px 28px 32px;border-radius:24px;background:var(--mx-glass,rgba(255,255,255,.045));border:1px solid var(--mx-border,rgba(255,255,255,.1));box-shadow:0 20px 56px rgba(0,0,0,.28);overflow:hidden}
.err404::before{content:'';position:absolute;inset:0;background:radial-gradient(280px 160px at 50% 0%,rgba(138,99,255,.2),transparent 70%);pointer-events:none}
.err404>*{position:relative;z-index:1}
.err404-orbs{position:absolute;inset:0;pointer-events:none;overflow:hidden;z-index:0}
.err404-orb{position:absolute;border-radius:50%;filter:blur(40px);opacity:.45;animation:err404-float 8s ease-in-out infinite}
.err404-orb-a{width:140px;height:140px;background:rgba(138,99,255,.35);top:-30px;left:-20px}
.err404-orb-b{width:100px;height:100px;background:rgba(76,201,240,.28);bottom:-20px;right:-10px;animation-delay:-3s}
@keyframes err404-float{0%,100%{transform:translate(0,0)}50%{transform:translate(8px,-10px)}}
.err404-code{font-size:clamp(72px,18vw,108px);font-weight:900;line-height:1;letter-spacing:-.04em;margin-bottom:8px;background:linear-gradient(135deg,#fff 0%,#d4c4ff 28%,var(--mx-purple,#8a63ff) 55%,var(--mx-cyan,#4cc9f0) 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;text-shadow:none}
.err404-ico{font-size:42px;line-height:1;margin-bottom:10px;filter:drop-shadow(0 6px 18px rgba(138,99,255,.35))}
.err404 h1{font-size:clamp(20px,4.5vw,26px);margin-bottom:10px;font-weight:800;color:#f4f6ff}
.err404 .sub{margin-bottom:18px;max-width:400px;margin-left:auto;margin-right:auto}
.err404-path{display:inline-block;max-width:100%;padding:10px 16px;border-radius:12px;background:rgba(0,0,0,.22);border:1px solid var(--mx-border,rgba(255,255,255,.1));font-size:13px;color:var(--mx-muted,#9aa8c7);margin-bottom:22px;word-break:break-all}
.err404-path b{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;color:var(--mx-soft,#c5cce8);font-weight:700}
.err404-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;align-items:center}
.err404-actions .btn{min-width:148px}
.err404-hint{margin-top:18px;font-size:12px;color:var(--mx-muted,#9aa8c7)}
.err404-hint a{color:var(--mx-cyan,#4cc9f0);text-decoration:none;font-weight:600}
.err404-hint a:hover{text-decoration:underline;color:#9ee7ff}
.err404-credit{margin-top:22px;padding-top:16px;border-top:1px solid var(--mx-border,rgba(255,255,255,.1));font-size:12px;color:var(--mx-muted,#9aa8c7)}
.err404-credit a{color:var(--mx-purple,#8a63ff)!important;text-decoration:none;font-weight:600}
.err404-credit a:hover{color:var(--mx-cyan,#4cc9f0)!important}
CSS;

    public static function wrap(string $inner): string
    {
        return '<style>' . self::CSS . '</style>' . $inner;
    }

    public static function block(string $loc, string $path, string $context = 'store'): string
    {
        $e = Html::esc(...);
        $homeHref = $context === 'admin' ? AdminPath::url() : Url::to('/');
        $homeLabel = $context === 'admin'
            ? I18n::t('err404.btn_admin', $loc)
            : I18n::t('err404.btn_home', $loc);
        $displayPath = $path !== '' ? $path : '/';
        $accountLink = $context === 'store'
            ? '<div class="err404-hint"><a href="' . $e(Url::to('/account')) . '">' . $e(I18n::t('err404.link_account', $loc)) . '</a></div>'
            : '<div class="err404-hint"><a href="' . $e(Url::to('/')) . '">' . $e(I18n::t('err404.link_store', $loc)) . '</a></div>';

        return '<div class="err404-wrap">'
            . '<section class="err404" role="alert" aria-live="polite">'
            . '<div class="err404-orbs" aria-hidden="true"><span class="err404-orb err404-orb-a"></span><span class="err404-orb err404-orb-b"></span></div>'
            . '<div class="err404-ico" aria-hidden="true">🔍</div>'
            . '<div class="err404-code" aria-hidden="true">404</div>'
            . '<h1>' . $e(I18n::t('err404.heading', $loc)) . '</h1>'
            . '<p class="sub">' . $e(I18n::t('err404.desc', $loc)) . '</p>'
            . '<div class="err404-path mono"><b>' . $e(I18n::t('err404.path_label', $loc)) . '</b>' . $e($displayPath) . '</div>'
            . '<div class="err404-actions">'
            . '<a class="btn" href="' . $e($homeHref) . '">' . $e($homeLabel) . '</a>'
            . '<button type="button" class="btn gray" onclick="history.length>1?history.back():location.href=\'' . $e($homeHref) . '\'">'
            . $e(I18n::t('err404.btn_back', $loc)) . '</button>'
            . '</div>'
            . $accountLink
            . DevCredit::inlineHtml($loc, 'err404-credit')
            . '</section></div>';
    }
}
