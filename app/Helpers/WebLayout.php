<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Database\Database;
use App\Services\BrandTheme;
use App\Services\Csrf;
use App\Services\DevCredit;
use App\Services\IntegrityGuard;

final class WebLayout
{
    private const CSS = <<<'CSS'
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;scroll-padding-top:72px;-webkit-text-size-adjust:100%;text-size-adjust:100%}
:root{--mx-bg:#0b0e1a;--mx-purple:#8a63ff;--mx-cyan:#4cc9f0;--mx-grad:linear-gradient(135deg,#8a63ff,#4cc9f0);--mx-glass:rgba(255,255,255,.045);--mx-border:rgba(255,255,255,.1);--mx-text:#eef2ff;--mx-soft:#c5cce8;--mx-muted:#9aa8c7;--mx-radius:16px;--mx-glow:0 8px 28px rgba(138,99,255,.32);--mx-ease:cubic-bezier(.22,1,.36,1)}
body{font-family:var(--font);background:var(--mx-bg);color:var(--mx-text);min-height:100vh;display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-orient:vertical;-webkit-flex-direction:column;flex-direction:column;line-height:1.5;-webkit-font-smoothing:antialiased}
body.page-modern::before{content:'';position:fixed;inset:0;background:radial-gradient(700px 380px at 18% -5%,rgba(138,99,255,.22),transparent 65%),radial-gradient(560px 320px at 88% 8%,rgba(76,201,240,.16),transparent 60%),radial-gradient(2px 2px at 12% 22%,rgba(255,255,255,.35),transparent 100%),radial-gradient(1px 1px at 78% 64%,rgba(255,255,255,.25),transparent 100%),radial-gradient(1px 1px at 42% 88%,rgba(255,255,255,.2),transparent 100%);pointer-events:none;z-index:0}
body.page-modern::after{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(76,201,240,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(76,201,240,.05) 1px,transparent 1px);background-size:44px 44px;pointer-events:none;z-index:0;opacity:.55}
.topbar,.wrap,.foot{position:relative;z-index:1}
.topbar{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-align:center;-webkit-align-items:center;align-items:center;gap:6px;-webkit-flex-wrap:wrap;flex-wrap:wrap;padding:12px 20px;background:rgba(11,14,26,.82);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);border-bottom:1px solid var(--mx-border);position:-webkit-sticky;position:sticky;top:0;z-index:50}
.brand{font-size:17px;font-weight:900;color:#fff;text-decoration:none;margin-right:12px;margin-inline-end:12px;display:-webkit-inline-box;display:-webkit-inline-flex;display:inline-flex;-webkit-box-align:center;-webkit-align-items:center;align-items:center;gap:8px;white-space:nowrap}
.brand-logo{height:30px;width:auto;max-width:150px;object-fit:contain;display:block}
.brand span{background:var(--mx-grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.topbar a{color:var(--mx-soft);text-decoration:none;font-size:13px;padding:8px 12px;border-radius:12px;font-weight:600;-webkit-transition:background .15s,color .15s;transition:background .15s,color .15s}
.topbar a:hover{background:rgba(255,255,255,.06);color:#fff}
.topbar a.active{background:rgba(138,99,255,.22);color:#fff;font-weight:700;box-shadow:inset 0 0 0 1px rgba(138,99,255,.25)}
.topbar .spacer{-webkit-box-flex:1;-webkit-flex:1;flex:1;min-width:8px}
.tgbtn{background:var(--mx-grad)!important;color:#fff!important;font-weight:700;box-shadow:var(--mx-glow)}
.wrap{max-width:1140px;margin:0 auto;padding:24px 18px 36px;width:100%;-webkit-box-flex:1;-webkit-flex:1;flex:1}
.page-store .wrap{padding-top:16px}
.card{background:var(--mx-glass);border:1px solid var(--mx-border);border-radius:20px;padding:26px;margin-bottom:20px;-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);box-shadow:0 12px 40px rgba(0,0,0,.18)}
h1{font-size:24px;margin-bottom:6px;font-weight:800}
.mx-gradient-text{color:#f4f6ff;background:linear-gradient(135deg,#fff 0%,#d4c4ff 35%,var(--mx-purple) 62%,var(--mx-cyan) 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.sub{color:var(--mx-muted);font-size:14px;margin-bottom:14px;line-height:1.8}
a.btn,button.btn{display:inline-block;padding:12px 22px;border-radius:14px;border:0;background:var(--mx-grad);color:#fff;text-decoration:none;font-size:15px;font-weight:800;cursor:pointer;font-family:inherit;min-height:44px;line-height:1.2;text-align:center;vertical-align:middle;box-shadow:var(--mx-glow);-webkit-transition:-webkit-transform .15s var(--mx-ease),transform .15s var(--mx-ease),box-shadow .15s;transition:transform .15s var(--mx-ease),box-shadow .15s}
a.btn:hover,button.btn:hover{-webkit-transform:translateY(-2px);transform:translateY(-2px);box-shadow:0 12px 36px rgba(138,99,255,.42)}
.btn.is-loading{opacity:.72;pointer-events:none;cursor:wait;-webkit-transform:none;transform:none}
.checkout-field-err{display:none;margin-bottom:12px}
.checkout-field-err.show{display:block}
.checkout-card{position:relative}
.checkout-busy{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(11,14,26,.88);border-radius:20px;z-index:2;font-weight:800;color:#fff;text-align:center;padding:20px}
.checkout-busy[hidden]{display:none!important}
.btn.green{background:var(--mx-grad)}
.btn.red{background:linear-gradient(135deg,#e74c6f,#c0395a)}
.btn.block{display:block;width:100%;text-align:center}
.btn.gray{background:rgba(255,255,255,.08);border:1px solid var(--mx-border);box-shadow:none;color:var(--mx-soft)}
.btn.btn-outline{background:transparent;border:1px solid rgba(138,99,255,.35);color:var(--mx-soft);box-shadow:none}
.btn.btn-outline:hover{background:rgba(138,99,255,.1);border-color:rgba(138,99,255,.5)}
.acct-check{display:flex;align-items:flex-start;gap:10px;font-size:14px;line-height:1.6;color:#cfe0f5;margin-top:12px}
.acct-check input{margin-top:4px;flex-shrink:0;width:auto}
label{display:block;font-size:14px;margin:12px 0 5px;color:var(--mx-soft)}
input,select,textarea{width:100%;max-width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--mx-border);background:rgba(255,255,255,.04);color:#fff;font-size:16px;font-family:inherit;-webkit-appearance:none;appearance:none;-webkit-transition:border-color .15s,box-shadow .15s;transition:border-color .15s,box-shadow .15s}
input:focus,select:focus,textarea:focus{outline:none;border-color:rgba(138,99,255,.45);box-shadow:0 0 0 3px rgba(138,99,255,.15)}
select{background-color:#141a2e;color:#eef2ff;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%239fb3cc' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:36px;color-scheme:dark}
select option,select optgroup{background-color:#141a2e;color:#eef2ff}
select option:checked,select option:hover{background-color:#252d4a;color:#fff}
html[dir="rtl"] select{background-position:left 14px center;padding-right:14px;padding-left:36px}
input[type=checkbox]{width:auto;-webkit-appearance:auto;appearance:auto}
button,.btn,a.btn{-webkit-tap-highlight-color:transparent;touch-action:manipulation}
html{color-scheme:dark}
.err{background:#3a1620;border:1px solid #7a2a3a;color:#ffb9c6;padding:13px;border-radius:11px;margin-bottom:14px}
.ok{background:#12301f;border:1px solid #1f5c39;color:#a9f0c6;padding:13px;border-radius:11px;margin-bottom:14px}
.warn{background:#3a2f12;border:1px solid #6e5a1f;color:#ffd479;padding:13px;border-radius:11px;margin-bottom:14px}
.badge{display:inline-block;padding:5px 13px;border-radius:99px;font-size:12px;font-weight:700}
.b-ok{background:#12301f;color:#7de8a8}.b-exp{background:#3a1620;color:#ff9fb1}.b-warn{background:#3a2f12;color:#ffd479}
.kv{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--mx-border)}
.sub-results{padding:4px 18px 12px}
.sub-result-row{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:12px 0;border-bottom:1px solid var(--mx-border)}
.sub-result-row:last-child{border-bottom:0}
.sub-result-info{display:flex;flex-wrap:wrap;align-items:center;gap:10px 18px;flex:1}
.mono{direction:ltr;unicode-bidi:isolate;text-align:left;font-family:Consolas,"Courier New",monospace}
html[dir="rtl"] .mono{text-align:right}
.link{color:var(--mx-cyan);text-decoration:none;font-weight:600}
.link:hover{color:#9ee7ff;text-decoration:underline}
a.btn.btn-sm,button.btn.btn-sm{padding:8px 14px;font-size:13px;min-height:36px}

/* ——— Account panel ——— */
.acct-page{max-width:960px;margin:0 auto}
.acct-narrow{max-width:520px;margin-left:auto;margin-right:auto}
.acct-auth{max-width:440px;margin:0 auto;padding:8px 0 24px}
.acct-auth-card{text-align:center;padding:32px 24px;border:1px solid var(--mx-border);background:var(--mx-glass);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);box-shadow:0 16px 48px rgba(0,0,0,.22)}
.acct-auth-card h1{font-size:1.5rem}
.acct-auth-icon{font-size:44px;line-height:1;margin-bottom:10px;filter:drop-shadow(0 4px 12px rgba(138,99,255,.35))}
.acct-auth-foot{margin-top:16px;text-align:center}
.acct-hint{margin-top:8px}
.acct-form{margin-top:8px}
.acct-submit{margin-top:16px;min-height:48px}
.acct-code-input{letter-spacing:.25em;text-align:center;font-size:20px}
.acct-head-top{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-align:start;-webkit-align-items:flex-start;align-items:flex-start;-webkit-box-pack:justify;-webkit-justify-content:space-between;justify-content:space-between;gap:14px;-webkit-flex-wrap:wrap;flex-wrap:wrap;margin-bottom:16px}
.acct-head-top h1{margin-bottom:0}
.acct-products{display:grid;gap:14px;margin-top:16px}
.acct-product-card{display:block;padding:18px 20px;border-radius:var(--radius-lg);border:1px solid var(--border);background:var(--surface);text-decoration:none;color:inherit;transition:transform .2s var(--ease),border-color .2s,box-shadow .2s}
.acct-product-card:hover{transform:translateY(-3px);border-color:var(--border-strong);box-shadow:0 16px 40px rgba(124,92,255,.14)}
.acct-product-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
.acct-product-meta{display:flex;flex-wrap:wrap;gap:10px 16px;font-size:13px;color:var(--mx-soft)}
.acct-product-go{display:inline-block;margin-top:12px;font-weight:700;color:var(--primary)}
.acct-product-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
.acct-hero{padding:24px 22px;border:1px solid var(--mx-border);background:var(--mx-glass);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);box-shadow:0 14px 40px rgba(0,0,0,.2)}
.acct-hero-inner{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-align:center;-webkit-align-items:center;align-items:center;gap:16px;-webkit-flex-wrap:wrap;flex-wrap:wrap;margin-bottom:18px}
.acct-avatar{width:56px;height:56px;min-width:56px;border-radius:16px;background:var(--mx-grad);color:#fff;font-size:24px;font-weight:900;display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-align:center;-webkit-align-items:center;align-items:center;-webkit-box-pack:center;-webkit-justify-content:center;justify-content:center;-webkit-flex-shrink:0;flex-shrink:0;box-shadow:var(--mx-glow)}
.acct-hero-text{-webkit-box-flex:1;-webkit-flex:1 1 180px;flex:1 1 180px;min-width:0}
.acct-hero-text h1{font-size:22px;line-height:1.3;word-wrap:break-word;overflow-wrap:break-word}
.acct-hero-badges{margin-top:8px}
.acct-logout{-webkit-flex-shrink:0;flex-shrink:0;margin-left:auto}
html[dir="rtl"] .acct-logout{margin-left:0;margin-right:auto}
.acct-stats{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-flex-wrap:wrap;flex-wrap:wrap;margin:-6px}
.acct-stat{-webkit-box-flex:1;-webkit-flex:1 1 140px;flex:1 1 140px;min-width:130px;margin:6px;background:rgba(255,255,255,.04);border:1px solid var(--mx-border);border-radius:14px;padding:12px 14px;display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-align:start;-webkit-align-items:flex-start;align-items:flex-start;gap:10px;-webkit-transition:border-color .15s,-webkit-transform .15s;transition:border-color .15s,transform .15s}
.acct-stat:hover{border-color:rgba(138,99,255,.3);-webkit-transform:translateY(-2px);transform:translateY(-2px)}
.acct-stat-ico{font-size:18px;line-height:1.2;-webkit-flex-shrink:0;flex-shrink:0}
.acct-stat-body{min-width:0;-webkit-box-flex:1;-webkit-flex:1;flex:1}
.acct-stat-l{font-size:11px;color:var(--mx-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
.acct-stat-v{font-size:15px;font-weight:800;color:#fff;word-wrap:break-word;overflow-wrap:break-word}
.acct-layout{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-orient:vertical;-webkit-flex-direction:column;flex-direction:column;gap:16px}
.acct-main{-webkit-box-flex:1;-webkit-flex:1;flex:1;min-width:0}
.acct-aside{background:var(--mx-glass);border:1px solid var(--mx-border);border-radius:18px;padding:16px;-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
.acct-aside-title{font-size:12px;color:var(--mx-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;font-weight:700}
.acct-nav{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-orient:vertical;-webkit-flex-direction:column;flex-direction:column;gap:8px}
.acct-nav-item{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-align:center;-webkit-align-items:center;align-items:center;gap:12px;padding:12px 14px;border-radius:14px;border:1px solid var(--mx-border);background:rgba(255,255,255,.03);color:var(--mx-text);text-decoration:none;font-weight:700;font-size:14px;-webkit-transition:border-color .15s,background .15s,-webkit-transform .15s;transition:border-color .15s,background .15s,transform .15s}
.acct-nav-item:hover,.acct-nav-item:focus{border-color:rgba(138,99,255,.4);background:rgba(138,99,255,.08);outline:none;-webkit-transform:translateX(2px);transform:translateX(2px)}
html[dir="rtl"] .acct-nav-item:hover,html[dir="rtl"] .acct-nav-item:focus{-webkit-transform:translateX(-2px);transform:translateX(-2px)}
.acct-nav-warn{border-color:rgba(231,76,111,.35)}
.acct-nav-ico{font-size:20px;line-height:1;-webkit-flex-shrink:0;flex-shrink:0}
.acct-section-head{margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--mx-border)}
.acct-section-head h2{font-size:18px;font-weight:800;margin:0;color:#f2f6ff}
.acct-server-top{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-align:center;-webkit-align-items:center;align-items:center;-webkit-box-pack:justify;-webkit-justify-content:space-between;justify-content:space-between;gap:12px;-webkit-flex-wrap:wrap;flex-wrap:wrap;margin-bottom:16px}
.acct-server-name{font-size:20px;font-weight:800;word-wrap:break-word;overflow-wrap:break-word}
.acct-info-grid{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-flex-wrap:wrap;flex-wrap:wrap;margin:-5px;margin-bottom:12px}
.acct-info{-webkit-box-flex:1;-webkit-flex:1 1 200px;flex:1 1 200px;min-width:180px;margin:5px;background:rgba(255,255,255,.03);border:1px solid var(--mx-border);border-radius:12px;padding:10px 12px;display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-align:start;-webkit-align-items:flex-start;align-items:flex-start;gap:10px}
.acct-info-ico{font-size:16px;line-height:1.3;-webkit-flex-shrink:0;flex-shrink:0}
.acct-info-body{min-width:0;-webkit-box-flex:1;-webkit-flex:1;flex:1}
.acct-info-l{display:block;font-size:11px;color:var(--mx-muted);margin-bottom:3px}
.acct-info-v{display:block;font-size:14px;font-weight:700;word-wrap:break-word;overflow-wrap:break-word;word-break:break-word}
.acct-control-section{margin-top:16px;padding-top:14px;border-top:1px solid var(--mx-border)}
.acct-control-label{font-size:12px;color:var(--mx-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px;font-weight:700}
.acct-controls{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-flex-wrap:wrap;flex-wrap:wrap;margin:-5px}
.acct-controls-2 .acct-ctrl-form,.acct-controls-2 .acct-ctrl{-webkit-box-flex:1;-webkit-flex:1 1 160px;flex:1 1 160px}
.acct-ctrl-form{margin:5px;-webkit-box-flex:1;-webkit-flex:1 1 130px;flex:1 1 130px;min-width:120px}
.acct-ctrl-form .acct-ctrl{display:block;width:100%}
.acct-ctrl{margin:5px;-webkit-box-flex:1;-webkit-flex:1 1 130px;flex:1 1 130px;min-width:120px;min-height:44px;text-align:center;padding:12px 10px;font-size:13px;line-height:1.3}
.acct-pass-box{font-size:18px;padding:16px 18px;background:rgba(138,99,255,.08);border:1px solid rgba(138,99,255,.28);border-radius:14px;margin:14px 0 18px;word-wrap:break-word;overflow-wrap:break-word;word-break:break-all;-webkit-user-select:all;user-select:all;box-shadow:inset 0 0 20px rgba(138,99,255,.08)}
.acct-selected-os{background:rgba(255,255,255,.03);border:1px solid var(--mx-border);border-radius:12px;padding:12px 14px;margin-bottom:16px}
.acct-warn-banner{margin-bottom:16px}
.acct-empty,.acct-empty-msg{text-align:center;padding:8px 0}
.acct-inv-list{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-orient:vertical;-webkit-flex-direction:column;flex-direction:column;gap:12px}
.acct-inv-item{background:rgba(255,255,255,.03);border:1px solid var(--mx-border);border-radius:14px;padding:14px 16px}
.acct-inv-line{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-pack:justify;-webkit-justify-content:space-between;justify-content:space-between;-webkit-box-align:center;-webkit-align-items:center;align-items:center;gap:12px;padding:7px 0;border-bottom:1px solid var(--mx-border)}
.acct-inv-line:last-child{border-bottom:0}
.acct-inv-lbl{font-size:12px;color:var(--mx-muted);-webkit-flex-shrink:0;flex-shrink:0}
.acct-inv-val{font-size:14px;font-weight:700;text-align:end;word-wrap:break-word;overflow-wrap:break-word}
html[dir="rtl"] .acct-inv-val{text-align:start}
.acct-check{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-align:start;-webkit-align-items:flex-start;align-items:flex-start;gap:10px;font-size:14px;line-height:1.6;color:#cfe0f5;margin-top:12px;cursor:pointer}
.acct-check input{margin-top:4px;-webkit-flex-shrink:0;flex-shrink:0;width:18px;height:18px;min-width:18px}

@media (min-width:768px){
.acct-layout{-webkit-box-orient:horizontal;-webkit-flex-direction:row;flex-direction:row;-webkit-box-align:start;-webkit-align-items:flex-start;align-items:flex-start}
.acct-aside{width:220px;min-width:220px;-webkit-flex-shrink:0;flex-shrink:0}
}
@media (max-width:480px){
.acct-hero-inner{-webkit-box-orient:vertical;-webkit-flex-direction:column;flex-direction:column;-webkit-box-align:stretch;-webkit-align-items:stretch;align-items:stretch;text-align:center}
.acct-logout{margin-left:0;margin-right:0;width:100%;text-align:center}
.acct-avatar{margin:0 auto}
.acct-stat{min-width:100%;-webkit-box-flex:1 1 100%;flex:1 1 100%}
.acct-ctrl-form,.acct-ctrl{min-width:100%;-webkit-box-flex:1 1 100%;flex:1 1 100%}
.card{padding:18px 16px}
.wrap{padding:20px 14px}
}
@media (prefers-reduced-motion:reduce){*{transition:none!important}}
.foot{text-align:center;color:var(--mx-muted);font-size:13px;padding:22px;border-top:1px solid var(--mx-border)}
.foot-credit{margin-top:10px;font-size:12px;opacity:.88}
.foot-integrity-warn{margin-top:8px;font-size:11px;color:#f0a060;opacity:.95}
.foot a{color:var(--mx-purple)!important;text-decoration:none}
.foot a:hover{color:var(--mx-cyan)!important}

/* ——— Store home ——— */
@-webkit-keyframes stFadeUp{from{opacity:0;-webkit-transform:translateY(18px);transform:translateY(18px)}to{opacity:1;-webkit-transform:translateY(0);transform:translateY(0)}}
@keyframes stFadeUp{from{opacity:0;-webkit-transform:translateY(18px);transform:translateY(18px)}to{opacity:1;-webkit-transform:translateY(0);transform:translateY(0)}}
@-webkit-keyframes stOrb{0%,100%{-webkit-transform:translate(0,0) scale(1);transform:translate(0,0) scale(1)}50%{-webkit-transform:translate(12px,-8px) scale(1.06);transform:translate(12px,-8px) scale(1.06)}}
@keyframes stOrb{0%,100%{-webkit-transform:translate(0,0) scale(1);transform:translate(0,0) scale(1)}50%{-webkit-transform:translate(12px,-8px) scale(1.06);transform:translate(12px,-8px) scale(1.06)}}
@-webkit-keyframes stGlow{0%,100%{opacity:.45}50%{opacity:.85}}
@keyframes stGlow{0%,100%{opacity:.45}50%{opacity:.85}}
.page-store{--st-h1:clamp(1.75rem,4.5vw,2.5rem);--st-h2:1.2rem;--st-h3:1.05rem;--st-body:0.9375rem;--st-small:0.8125rem;--st-radius:20px;--st-line:var(--mx-border);--st-muted:var(--mx-muted);--st-text:var(--mx-text);--st-soft:var(--mx-soft);--st-accent:var(--mx-cyan);--st-ease:var(--mx-ease)}
.page-store .wrap{max-width:920px;padding-top:20px;padding-bottom:52px}
.store-home{display:block;color:var(--st-text);font-size:var(--st-body);line-height:1.6}
.st-hero-wrap{position:relative;margin-bottom:52px;padding:40px 24px 36px;border-radius:24px;border:1px solid var(--st-line);background:var(--mx-glass);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,.25)}
.st-hero-bg{position:absolute;inset:0;pointer-events:none;overflow:hidden}
.st-orb{position:absolute;border-radius:50%;filter:blur(56px);-webkit-filter:blur(56px);opacity:.55}
.st-orb-a{width:260px;height:260px;top:-70px;inset-inline-start:6%;background:rgba(138,99,255,.45);-webkit-animation:stOrb 9s ease-in-out infinite;animation:stOrb 9s ease-in-out infinite}
.st-orb-b{width:200px;height:200px;bottom:-60px;inset-inline-end:8%;background:rgba(76,201,240,.32);-webkit-animation:stOrb 11s ease-in-out infinite reverse;animation:stOrb 11s ease-in-out infinite reverse}
.st-mast{position:relative;text-align:center;max-width:640px;margin:0 auto}
.st-hero-logo{margin:0 auto 18px}
.st-hero-logo img{max-height:72px;max-width:min(280px,80vw);width:auto;height:auto;object-fit:contain;display:inline-block}
.st-kicker{display:-webkit-inline-box;display:-webkit-inline-flex;display:inline-flex;-webkit-box-align:center;-webkit-align-items:center;align-items:center;gap:8px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:6px 14px;border-radius:99px;color:#d4c4ff;background:rgba(138,99,255,.14);border:1px solid rgba(138,99,255,.28);margin-bottom:18px}
.st-kicker::before{content:'';width:7px;height:7px;border-radius:50%;background:#4ade80;box-shadow:0 0 8px rgba(74,222,128,.7)}
.st-mast h1{font-size:var(--st-h1);font-weight:800;line-height:1.2;margin:0 0 14px;letter-spacing:-.035em;color:#f4f6ff;background:linear-gradient(135deg,#fff 0%,#d4c4ff 35%,var(--mx-purple) 62%,var(--mx-cyan) 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.acct-hero-text h1,.acct-auth-card h1{color:#f4f6ff;background:linear-gradient(135deg,#fff 0%,#d4c4ff 35%,var(--mx-purple) 62%,var(--mx-cyan) 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.st-lead{font-size:var(--st-body);color:var(--st-soft);line-height:1.8;margin:0 0 16px}
.st-pills{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-flex-wrap:wrap;flex-wrap:wrap;-webkit-box-pack:center;-webkit-justify-content:center;justify-content:center;gap:8px;margin-bottom:22px}
.st-pill{font-size:var(--st-small);font-weight:600;padding:6px 12px;border-radius:99px;color:#c5d8ee;background:rgba(255,255,255,.04);border:1px solid var(--st-line)}
.st-hero-cta{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-flex-wrap:wrap;flex-wrap:wrap;-webkit-box-pack:center;-webkit-justify-content:center;justify-content:center;gap:10px;margin-bottom:22px}
.st-hero-cta .btn{min-width:150px}
.st-trust-row{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-flex-wrap:wrap;flex-wrap:wrap;-webkit-box-pack:center;-webkit-justify-content:center;justify-content:center;gap:8px}
.st-trust-pill{font-size:11px;font-weight:600;color:var(--st-muted);padding:5px 10px;border-radius:8px;background:rgba(0,0,0,.15)}
.st-anim{opacity:0;-webkit-animation:stFadeUp .75s var(--st-ease) forwards;animation:stFadeUp .75s var(--st-ease) forwards}
.st-anim-d1{-webkit-animation-delay:.05s;animation-delay:.05s}
.st-anim-d2{-webkit-animation-delay:.12s;animation-delay:.12s}
.st-anim-d3{-webkit-animation-delay:.2s;animation-delay:.2s}
.st-anim-d4{-webkit-animation-delay:.28s;animation-delay:.28s}
.st-reveal{opacity:0;-webkit-transform:translateY(22px);transform:translateY(22px);-webkit-transition:opacity .65s var(--st-ease),-webkit-transform .65s var(--st-ease);transition:opacity .65s var(--st-ease),transform .65s var(--st-ease);-webkit-transition-delay:calc(var(--st-i,0) * 70ms);transition-delay:calc(var(--st-i,0) * 70ms)}
.st-reveal.st-in{opacity:1;-webkit-transform:translateY(0);transform:translateY(0)}
.st-block{margin-bottom:52px;scroll-margin-top:80px}
.st-block-label{margin-bottom:22px}
.st-block-label h2{font-size:var(--st-h2);font-weight:800;margin:0 0 6px;letter-spacing:-.02em;color:#f2f7fb}
.st-block-label p{font-size:var(--st-small);color:var(--st-muted);margin:0;line-height:1.65}
.st-plans-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(272px,1fr));gap:18px}
.st-plan{position:relative;display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-orient:vertical;-webkit-flex-direction:column;flex-direction:column;padding:24px;border-radius:var(--st-radius);background:var(--mx-glass);border:1px solid var(--st-line);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);-webkit-transition:border-color .2s,-webkit-transform .2s var(--st-ease),transform .2s var(--st-ease),box-shadow .2s;transition:border-color .2s,transform .2s var(--st-ease),box-shadow .2s;will-change:transform}
.st-plan:hover{-webkit-transform:translateY(-4px);transform:translateY(-4px);border-color:rgba(138,99,255,.4);box-shadow:0 16px 40px rgba(138,99,255,.15)}
.st-plan.is-featured{border-color:rgba(138,99,255,.45);box-shadow:0 0 0 1px rgba(138,99,255,.15),var(--mx-glow)}
.st-plan.is-featured::after{content:'';position:absolute;inset:-1px;border-radius:inherit;border:1px solid rgba(138,99,255,.3);pointer-events:none;-webkit-animation:stGlow 3s ease-in-out infinite;animation:stGlow 3s ease-in-out infinite}
.st-plan-tag{position:absolute;top:18px;inset-inline-end:18px;font-size:10px;font-weight:700;letter-spacing:.04em;color:#f0c96a;text-transform:uppercase}
.st-plan-top{margin-bottom:18px;padding-inline-end:76px}
.st-plan-top h3{font-size:var(--st-h3);font-weight:800;margin:0 0 8px;line-height:1.35;color:#f4f8fc}
.st-plan-stock{display:inline-block;font-size:11px;font-weight:700;padding:4px 9px;border-radius:7px}
.st-plan-stock.ok{color:#8fd4ae;background:rgba(23,160,109,.14)}
.st-plan-stock.warn{color:#e8c56a;background:rgba(201,162,39,.12)}
.st-plan-price{margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--st-line)}
.st-plan-amount{display:block;font-size:2.125rem;font-weight:800;line-height:1;color:#fff;letter-spacing:-.03em}
.st-plan-cur{display:block;font-size:var(--st-small);color:var(--st-muted);margin-top:6px;font-weight:500}
.st-plan-sep{opacity:.4}
.st-plan-specs{list-style:none;margin:0 0 20px;padding:0;-webkit-box-flex:1;-webkit-flex:1;flex:1}
.st-plan-specs li{position:relative;font-size:var(--st-small);color:var(--st-soft);padding:8px 0 8px 16px;border-bottom:1px solid rgba(255,255,255,.04);line-height:1.5}
.st-plan-specs li::before{content:'';position:absolute;left:0;top:14px;width:5px;height:5px;border-radius:50%;background:var(--mx-grad)}
html[dir="rtl"] .st-plan-specs li{padding:8px 16px 8px 0}
html[dir="rtl"] .st-plan-specs li::before{left:auto;right:0}
.st-plan-specs li:last-child{border-bottom:0}
.st-plan-btn{margin-top:auto;min-height:46px;font-size:var(--st-body);position:relative;overflow:hidden}
.st-plan-btn span{position:relative;z-index:1}
.st-plan-btn.btn.gray{background:rgba(255,255,255,.06);border:1px solid var(--mx-border);color:var(--mx-soft);box-shadow:none}
.st-faq-list{display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-orient:vertical;-webkit-flex-direction:column;flex-direction:column;gap:10px}
.st-faq{border:1px solid var(--st-line);border-radius:16px;background:var(--mx-glass);overflow:hidden;-webkit-transition:border-color .15s;transition:border-color .15s}
.st-faq[open]{border-color:rgba(138,99,255,.35)}
.st-faq summary{cursor:pointer;padding:15px 16px;font-size:var(--st-body);font-weight:700;color:#edf3fa;line-height:1.5;list-style:none;display:-webkit-box;display:-webkit-flex;display:flex;-webkit-box-align:center;-webkit-align-items:center;align-items:center;gap:12px;-webkit-user-select:none;user-select:none}
.st-faq summary::-webkit-details-marker{display:none}
.st-faq-chevron{-webkit-flex-shrink:0;flex-shrink:0;width:22px;height:22px;border-radius:8px;background:rgba(138,99,255,.18);position:relative;-webkit-transition:-webkit-transform .25s var(--st-ease),transform .25s var(--st-ease);transition:transform .25s var(--st-ease)}
.st-faq-chevron::before,.st-faq-chevron::after{content:'';position:absolute;background:var(--mx-cyan);border-radius:1px}
.st-faq-chevron::before{width:10px;height:2px;top:10px;left:6px}
.st-faq-chevron::after{width:2px;height:10px;top:6px;left:10px;-webkit-transition:opacity .2s,-webkit-transform .2s;transition:opacity .2s,transform .2s}
.st-faq[open] .st-faq-chevron{-webkit-transform:rotate(180deg);transform:rotate(180deg)}
.st-faq[open] .st-faq-chevron::after{opacity:0;-webkit-transform:scale(0);transform:scale(0)}
.st-faq-q{-webkit-box-flex:1;-webkit-flex:1;flex:1;min-width:0}
.st-faq[open] summary{border-bottom:1px solid var(--st-line)}
.st-faq-body{padding:4px 16px 16px 50px;font-size:var(--st-small);color:var(--st-soft);line-height:1.75;-webkit-animation:stFadeUp .35s var(--st-ease);animation:stFadeUp .35s var(--st-ease)}
html[dir="rtl"] .st-faq-body{padding:4px 50px 16px 16px}
.st-foot{text-align:center;padding-top:12px;font-size:var(--st-small);color:var(--st-muted)}
.st-foot .link{margin-inline-start:6px;font-weight:700;color:var(--mx-cyan);-webkit-transition:color .15s;transition:color .15s}
.st-foot .link:hover{color:#9ee7ff}
.topbar .lang-sep{color:var(--mx-muted);margin:0 2px;user-select:none}
.page-account .wrap{max-width:960px}
@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){.topbar{background:#0b0e1a}}

/* Cross-browser + RTL */
html[dir="rtl"] .brand{margin-right:0;margin-left:12px}
html[dir="rtl"] .topbar span[style*="inline-flex"]{margin-left:0!important;margin-right:8px}
html[dir="rtl"] .acct-hero-inner{text-align:right}
html[dir="rtl"] .acct-auth-card{text-align:center}
html[dir="rtl"] .acct-inv-line{-webkit-box-orient:horizontal;-webkit-flex-direction:row;flex-direction:row}
@media (max-width:720px){
.page-store .wrap{padding-top:16px;padding-bottom:40px}
.st-hero-wrap{padding:28px 16px 24px;margin-bottom:40px}
.st-hero-cta .btn{min-width:100%;-webkit-box-flex:1 1 100%;flex:1 1 100%}
.st-plans-grid{grid-template-columns:1fr}
.st-plan-top{padding-inline-end:0}
.st-plan-tag{position:static;display:inline-block;margin-bottom:10px}
.st-faq-body{padding-inline-start:16px;padding-inline-end:16px}
.topbar{padding:10px 14px}
.wrap{padding:16px 14px 28px}
}
@media (prefers-reduced-motion:reduce){
html{scroll-behavior:auto}
.st-anim,.st-reveal{opacity:1;-webkit-transform:none;transform:none;-webkit-animation:none;animation:none;-webkit-transition:none;transition:none}
.st-orb,.st-plan.is-featured::after{-webkit-animation:none;animation:none}
.page-store a.btn:hover,button.btn:hover,.st-plan:hover{-webkit-transform:none;transform:none}
}
@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){.topbar{background:#0b0e1a}}

@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){.topbar{background:#0b0e1a}}

/* ——— Nebula redesign ——— */
body.page-modern::before,body.page-modern::after{display:none!important}
:root{--primary:var(--mx-purple,#7c5cff);--secondary:var(--mx-cyan,#22d3ee);--bg:var(--mx-bg,#06060f);--grad:var(--mx-grad);--surface:rgba(255,255,255,.04);--surface-2:rgba(255,255,255,.06);--border:rgba(255,255,255,.09);--border-strong:rgba(124,92,255,.4);--text:#f3f5ff;--soft:#c3cbe6;--muted:#8a93b4;--radius:18px;--radius-lg:24px;--glow:var(--mx-glow);--ok:#34d399;--warn:#fbbf24;--ease:var(--mx-ease)}
body{background:var(--bg)!important;color:var(--text)!important}
.aurora{position:fixed;inset:0;z-index:0;pointer-events:none;overflow:hidden}
.aurora b{position:absolute;border-radius:50%;filter:blur(80px);opacity:.5}
.aurora b:nth-child(1){width:520px;height:520px;top:-160px;inset-inline-start:-80px;background:var(--primary);animation:nb-float1 16s var(--ease) infinite}
.aurora b:nth-child(2){width:440px;height:440px;top:8%;inset-inline-end:-120px;background:var(--secondary);opacity:.32;animation:nb-float2 19s var(--ease) infinite}
.aurora b:nth-child(3){width:380px;height:380px;bottom:-120px;inset-inline-start:30%;background:var(--primary);opacity:.22;animation:nb-float1 22s var(--ease) infinite reverse}
.grid-bg{position:fixed;inset:0;z-index:0;pointer-events:none;background-image:linear-gradient(rgba(124,92,255,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(124,92,255,.05) 1px,transparent 1px);background-size:54px 54px;-webkit-mask-image:radial-gradient(ellipse 80% 60% at 50% 0%,#000 30%,transparent 75%);mask-image:radial-gradient(ellipse 80% 60% at 50% 0%,#000 30%,transparent 75%)}
@keyframes nb-float1{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(40px,30px) scale(1.12)}}
@keyframes nb-float2{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(-30px,40px) scale(1.08)}}
@keyframes nb-pulse{0%,100%{opacity:1}50%{opacity:.35}}
@keyframes nb-ring{0%{background-position:0% 50%}100%{background-position:200% 50%}}
.topbar{padding:14px 26px;background:rgba(6,6,15,.7)!important;backdrop-filter:blur(18px)!important;border-bottom-color:var(--border)!important}
.brand{font-family:var(--display);font-weight:700;font-size:19px;letter-spacing:-.02em}
.logo-mark{width:34px;height:34px;border-radius:11px;background:var(--grad);display:grid;place-items:center;box-shadow:var(--glow);font-size:18px;flex-shrink:0}
.brand em{font-style:normal;background:var(--grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.topbar a{font-size:14px;font-weight:500;padding:9px 14px;border-radius:11px}
.topbar a.active{background:rgba(124,92,255,.16)!important;box-shadow:inset 0 0 0 1px var(--border-strong)!important}
.page-store .wrap{max-width:1140px;padding-top:0}
.page-account .wrap{max-width:1080px;padding-top:24px}
a.btn,button.btn{display:inline-flex!important;align-items:center;justify-content:center;gap:9px;padding:15px 30px!important;border-radius:14px!important;font-size:16px!important;font-weight:700!important;min-height:52px!important;box-shadow:var(--glow)!important}
a.btn:hover,button.btn:hover{transform:translateY(-3px)!important;box-shadow:0 16px 48px rgba(124,92,255,.5)!important}
.btn.ghost,.btn.gray{background:var(--surface)!important;border:1px solid var(--border)!important;box-shadow:none!important;color:var(--soft)!important}
.btn.sm{padding:11px 16px!important;font-size:14px!important;min-height:44px!important}
.card,.panel{background:var(--surface)!important;border-color:var(--border)!important;border-radius:var(--radius)!important;backdrop-filter:blur(14px)}
.hero{text-align:center;padding:80px 0 56px}
.hero-logo{margin:0 auto 18px}.hero-logo img{max-height:72px;max-width:min(280px,80vw);object-fit:contain}
.tag{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:700;letter-spacing:.05em;padding:7px 15px;border-radius:99px;color:#d8ccff;background:rgba(124,92,255,.14);border:1px solid var(--border-strong);margin-bottom:8px}
.tag::before{content:'';width:7px;height:7px;border-radius:50%;background:var(--ok);box-shadow:0 0 10px var(--ok);animation:nb-pulse 2s infinite}
.hero h1{font-family:var(--display);font-size:clamp(2.4rem,6vw,4.2rem)!important;line-height:1.05;letter-spacing:-.04em;margin:22px 0 18px;background:linear-gradient(135deg,#fff 0%,#dcd0ff 40%,var(--primary) 70%,var(--secondary) 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.hero-desc{font-size:clamp(1rem,2.2vw,1.22rem);color:var(--soft);max-width:600px;margin:0 auto 28px;line-height:1.8}
.hero-cta{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:34px}
.trust{display:flex;gap:30px;justify-content:center;flex-wrap:wrap}
.trust b{font-family:var(--display);font-size:1.7rem;background:var(--grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.trust span{font-size:12px;color:var(--muted);display:block}
.shead{text-align:center;margin-bottom:40px}
.shead h2{font-family:var(--display);font-size:clamp(1.8rem,4vw,2.6rem);letter-spacing:-.03em;margin-bottom:10px}
.shead p{color:var(--muted);font-size:1.02rem}
.plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,360px));justify-content:center;gap:20px;margin-bottom:90px}
.plan{position:relative;display:flex;flex-direction:column;padding:30px 26px;border-radius:var(--radius-lg);background:var(--surface);border:1px solid var(--border);backdrop-filter:blur(14px);transition:transform .25s var(--ease),border-color .25s,box-shadow .25s}
.plan:hover{transform:translateY(-6px);border-color:var(--border-strong);box-shadow:0 24px 60px rgba(124,92,255,.18)}
.plan.feat{background:linear-gradient(180deg,rgba(124,92,255,.12),rgba(255,255,255,.03))}
.plan.feat::before{content:'';position:absolute;inset:-1px;border-radius:inherit;padding:1px;background:var(--grad);-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;animation:nb-ring 4s linear infinite;background-size:200% 200%;pointer-events:none}
.plan .btn{position:relative;z-index:2}
.ribbon{position:absolute;top:-12px;inset-inline-end:24px;background:var(--grad);color:#fff;font-size:11px;font-weight:800;padding:6px 14px;border-radius:99px;box-shadow:var(--glow)}
.plan-name{font-family:var(--display);font-size:1.3rem;font-weight:700;margin-bottom:6px}
.stockpill{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;padding:5px 11px;border-radius:99px;margin-bottom:20px}
.stockpill.ok{color:#8af0c2;background:rgba(52,211,153,.12)}.stockpill.warn{color:#fcd574;background:rgba(251,191,36,.12)}
.stockpill::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}
.price{display:flex;align-items:baseline;gap:8px;margin-bottom:22px;padding-bottom:22px;border-bottom:1px solid var(--border)}
.price b{font-family:var(--display);font-size:3rem;font-weight:700;line-height:1}
.price span{color:var(--muted);font-size:.9rem}
.specs-block{flex:1;margin-bottom:24px}
.specs-head{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:12px}
.specs{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px}
.specs li{display:flex;align-items:flex-start;gap:12px;font-size:.94rem;color:var(--soft);line-height:1.45;padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid var(--border)}
.plan .specs li{background:rgba(124,92,255,.06);border-color:rgba(124,92,255,.12)}
.spec-ico{width:28px;height:28px;border-radius:9px;background:rgba(124,92,255,.14);display:grid;place-items:center;flex-shrink:0;font-size:14px;line-height:1}
.spec-txt{flex:1;padding-top:4px}
.checkout-specs{margin:18px 0 8px;padding-top:18px;border-top:1px solid var(--border)}
.checkout-specs .specs-head{text-transform:none;letter-spacing:0;font-size:13px;color:var(--soft)}
.checkout-specs .specs li{padding:8px 10px;font-size:.9rem}
.faq-wrap{margin-bottom:60px}.faq{max-width:760px;margin:0 auto}
.qa{border:1px solid var(--border);border-radius:16px;background:var(--surface);margin-bottom:12px;overflow:hidden}
.qa[open]{border-color:var(--border-strong)}
.qa summary{cursor:pointer;list-style:none;padding:18px 20px;font-weight:700;display:flex;align-items:center;gap:14px}
.qa summary::-webkit-details-marker{display:none}
.qa .ic{width:26px;height:26px;border-radius:9px;background:rgba(124,92,255,.18);display:grid;place-items:center;flex-shrink:0;color:var(--secondary);font-size:18px;transition:transform .25s}
.qa[open] .ic{transform:rotate(45deg)}
.qa-body{padding:0 20px 20px 60px;color:var(--soft);line-height:1.8}
html[dir="rtl"] .qa-body{padding:0 60px 20px 20px}
.acct{display:grid;grid-template-columns:260px 1fr;gap:22px;align-items:start;padding-bottom:40px}
.aside{position:sticky;top:84px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;backdrop-filter:blur(14px)}
.who{display:flex;align-items:center;gap:13px;padding-bottom:18px;margin-bottom:18px;border-bottom:1px solid var(--border)}
.ava{width:50px;height:50px;border-radius:15px;background:var(--grad);display:grid;place-items:center;font-family:var(--display);font-weight:700;font-size:18px;box-shadow:var(--glow)}
.who b{display:block;font-size:15px}.who small{color:var(--muted);font-size:12px}
.menu{display:flex;flex-direction:column;gap:7px}
.menu a{display:flex;align-items:center;gap:13px;padding:13px 15px;border-radius:13px;color:var(--soft);text-decoration:none;font-weight:600;font-size:14px;border:1px solid transparent;transition:.15s}
.menu a .mi{font-size:19px;width:22px;text-align:center}
.menu a:hover{background:var(--surface-2);color:#fff}
.menu a.on{background:rgba(124,92,255,.16);color:#fff;border-color:var(--border-strong)}
.menu a.danger{color:#fda4b4}
.main{display:flex;flex-direction:column;gap:20px}
.panel-head{display:flex;align-items:center;gap:12px;margin-bottom:22px;flex-wrap:wrap}
.panel-head h3{font-family:var(--display);font-size:1.25rem;flex:1;margin:0}
.status{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:#8af0c2;background:rgba(52,211,153,.1);padding:7px 14px;border-radius:99px}
.status::before{content:'';width:8px;height:8px;border-radius:50%;background:var(--ok);box-shadow:0 0 10px var(--ok);animation:nb-pulse 2s infinite}
.status.off{color:#fcd574;background:rgba(251,191,36,.12)}.status.off::before{background:var(--warn)}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px}
.stat{background:var(--surface-2);border:1px solid var(--border);border-radius:15px;padding:16px 18px}
.stat .l{font-size:12px;color:var(--muted);margin-bottom:7px}
.stat .v{font-family:var(--display);font-size:1.5rem;font-weight:700}
.stat .v.ok{color:var(--ok)}
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:13px;margin-bottom:22px}
.info{background:var(--surface-2);border:1px solid var(--border);border-radius:13px;padding:14px 16px}
.info .l{font-size:11px;color:var(--muted);margin-bottom:5px}
.info .v{font-weight:700;font-size:14px;word-break:break-word}
.controls{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;padding-top:20px;border-top:1px solid var(--border)}
.ctrl-form{display:contents}
.inv{display:flex;align-items:center;gap:14px;padding:16px;border:1px solid var(--border);border-radius:14px;background:var(--surface-2);margin-bottom:11px}
.inv .ii{width:42px;height:42px;border-radius:12px;background:rgba(124,92,255,.14);display:grid;place-items:center;color:var(--secondary);font-size:20px;flex-shrink:0}
.inv .amt{margin-inline-start:auto;text-align:end}
.inv .amt b{font-family:var(--display)}
.paid{font-size:11px;font-weight:800;color:#8af0c2;background:rgba(52,211,153,.12);padding:4px 10px;border-radius:99px;display:inline-block;margin-top:4px}
.authwrap{max-width:440px;margin:40px auto 80px}
.auth{text-align:center;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:40px 30px;backdrop-filter:blur(14px)}
.auth-ic{width:64px;height:64px;border-radius:20px;background:var(--grad);display:grid;place-items:center;margin:0 auto 18px;font-size:30px;box-shadow:var(--glow)}
.auth h3{font-family:var(--display);font-size:1.5rem;margin-bottom:8px}
.auth p,.auth-hint{color:var(--muted);margin-bottom:22px;font-size:14px}
.field{text-align:start;margin-bottom:16px}
.mono{font-family:var(--display),Consolas,monospace}
.acct-inner{max-width:560px;margin:0 auto}
@media(max-width:820px){.acct{grid-template-columns:1fr}.aside{position:static}.hero{padding:56px 0 40px}.plans{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){.aurora b,.plan.feat::before,.tag::before,.status::before{animation:none!important}}
CSS;

    /** Checkout: fetch PayPal redirect URL as JSON (works in mobile/Telegram browsers). */
    public static function checkoutFormScript(string $locale): string
    {
        $msgName = Html::esc(I18n::t('store.checkout.fill_name', $locale));
        $msgPhone = Html::esc(I18n::t('store.checkout.fill_phone', $locale));
        $msgFail = Html::esc(I18n::t('store.checkout.pay_failed', $locale));
        return <<<JS
<script>
(function(){
  var f=document.getElementById('checkout-form');
  if(!f)return;
  var err=document.getElementById('checkout-field-err');
  var busy=document.getElementById('checkout-busy');
  var msgName='{$msgName}';
  var msgPhone='{$msgPhone}';
  var msgFail='{$msgFail}';
  var showErr=function(t){if(!err)return;err.textContent=t;err.classList.add('show');err.scrollIntoView({behavior:'smooth',block:'center'});if(busy)busy.hidden=true;};
  var clearErr=function(){if(err){err.classList.remove('show');err.textContent='';}};
  var pay=function(ev){
    if(ev)ev.preventDefault();
    clearErr();
    var name=f.querySelector('[name="name"]');
    var phone=f.querySelector('[name="phone"]');
    if(!name||!name.value.trim()){showErr(msgName);name&&name.focus();return;}
    if(!phone||phone.value.replace(/\D/g,'').length<8){showErr(msgPhone);phone&&phone.focus();return;}
    if(busy)busy.hidden=false;
    fetch(f.action,{method:'POST',body:new FormData(f),credentials:'same-origin',headers:{'X-Checkout-Mode':'json','Accept':'application/json'}})
      .then(function(r){return r.json().then(function(d){return{ok:r.ok,data:d};});})
      .then(function(r){
        if(r.data&&r.data.ok&&r.data.redirect){window.location.href=r.data.redirect;return;}
        showErr((r.data&&r.data.error)||msgFail);
      })
      .catch(function(){f.submit();});
  };
  f.addEventListener('submit',pay);
  document.querySelector('.checkout-card .err')?.scrollIntoView({behavior:'smooth',block:'center'});
})();
</script>
JS;
    }

    /** @param array{locale?: string, active?: string, returnPath?: string, csrf?: string} $opts */
    public static function layout(string $title, string $body, array $opts = []): string
    {
        $st = Database::getSettings();
        $store = Locale::storeSettings();
        $locale = $opts['locale'] ?? $store['store_locale'];
        $lang = I18n::htmlLang($locale);
        $dir = I18n::htmlDir($locale);
        $storeName = $st['store_name'] ?? ($locale === 'ar' ? 'متجر VPS' : 'VPS Store');
        $brandCss = BrandTheme::cssOverrides($st);
        $brandBg = BrandTheme::bg($st);
        $logoUrl = BrandTheme::logoUrl($st);
        $tg = $st['telegram_link'] ?? '';
        $active = $opts['active'] ?? '';
        $font = "'Tajawal','Segoe UI',Tahoma,Arial,sans-serif";
        $display = "'Space Grotesk','Tajawal',sans-serif";
        $fontLink = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">';
        $hasFaq = !empty($st['show_faq_home']) && trim((string) ($st['faq'] ?? '')) !== '';
        $nav = fn($href, $label, $key) => '<a href="' . Url::to($href) . '" class="' . ($active === $key ? 'active' : '') . '">' . $label . '</a>';
        $switcher = I18n::langSwitcher('store', $locale, $opts['returnPath'] ?? '/', $store['allow_locale_switch']);
        $year = date('Y');
        $e = [Html::class, 'esc'];
        $css = self::CSS;
        $tgLink = $tg
            ? '<a class="tgbtn" href="' . $e($tg) . '" target="_blank">💬 ' . I18n::t('nav.contact', $locale) . '</a>'
            : '';
        $bodyClass = 'page-modern';
        if ($active === 'store' && str_contains($body, 'store-home')) {
            $bodyClass .= ' page-store';
        }
        if ($active === 'account' || str_contains($body, 'acct') || str_contains($body, 'authwrap')) {
            $bodyClass .= ' page-account';
        }
        $csrfMeta = '';
        $csrfScript = '';
        $csrfTok = trim((string) ($opts['csrf'] ?? ''));
        if ($csrfTok !== '') {
            $csrfMeta = Csrf::metaTag($csrfTok);
            $csrfScript = Csrf::formInjectScript();
        }
        $brandInner = $logoUrl !== ''
            ? '<img class="brand-logo logo-mark" src="' . $e($logoUrl) . '" alt="' . $e($storeName) . '">'
            : '<span class="logo-mark" aria-hidden="true">🖥️</span>' . $e($storeName) . '<em>.</em>';
        return '<!doctype html><html lang="' . $e($lang) . '" dir="' . $e($dir) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<meta name="theme-color" content="' . $e($brandBg) . '">'
            . '<meta name="format-detection" content="telephone=no">'
            . $csrfMeta
            . '<title>' . $e($title) . ' — ' . $e($storeName) . '</title>'
            . $fontLink
            . '<style>:root{--font:' . $font . ';--display:' . $display . '}</style><style>' . $css . '</style><style>' . $brandCss . '</style></head><body class="' . trim($bodyClass) . '">'
            . '<div class="aurora" aria-hidden="true"><b></b><b></b><b></b></div>'
            . '<div class="grid-bg" aria-hidden="true"></div>'
            . '<nav class="topbar">'
            . '<a class="brand logo" href="' . Url::to('/') . '">' . $brandInner . '</a>'
            . $nav('/', I18n::t('nav.plans', $locale), 'store')
            . ($hasFaq ? '<a href="' . Url::to('/#faq') . '">' . $e(I18n::t('nav.faq', $locale)) . '</a>' : '')
            . $nav('/subscription', I18n::t('nav.subscription', $locale), 'sub')
            . $nav('/account', I18n::t('nav.account', $locale), 'account')
            . '<span class="spacer"></span>' . $switcher . $tgLink
            . '</nav>'
            . '<div class="wrap">' . $body . '</div>'
            . '<div class="foot">'
            . '<div>© ' . $year . ' ' . $e($storeName) . ' — ' . $e(I18n::t('foot.tagline', $locale)) . '</div>'
            . DevCredit::inlineHtml($locale)
            . IntegrityGuard::storeWarningHtml($locale)
            . '</div>'
            . PageGuard::noRightClickScript()
            . $csrfScript
            . '</body></html>';
    }
}
