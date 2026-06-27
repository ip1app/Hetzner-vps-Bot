<?php
declare(strict_types=1);

namespace App\Views;

use App\Helpers\Html;
use App\Helpers\I18n;
use App\Request;
use App\Services\AdminPath;
use App\Services\Csrf;
use App\Services\InstallCleanup;
use App\Services\DevCredit;
use App\Services\IntegrityGuard;

final class AdminUi
{
    private const CSS = <<<'CSS'
*{box-sizing:border-box;margin:0;padding:0}
:root{--adm-bg:#0b0e1a;--adm-purple:#8a63ff;--adm-cyan:#4cc9f0;--adm-grad:linear-gradient(135deg,#8a63ff,#4cc9f0);--adm-glass:rgba(255,255,255,.045);--adm-border:rgba(255,255,255,.1);--adm-text:#eef2ff;--adm-soft:#c5cce8;--adm-muted:#9aa8c7;--adm-glow:0 8px 28px rgba(138,99,255,.32);--adm-input-bg:#141a2e}
html{color-scheme:dark}
body{font-family:var(--font);background:var(--adm-bg);color:var(--adm-text);min-height:100vh;-webkit-font-smoothing:antialiased}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(700px 380px at 18% -5%,rgba(138,99,255,.22),transparent 65%),radial-gradient(560px 320px at 88% 8%,rgba(76,201,240,.16),transparent 60%);pointer-events:none;z-index:0}
body::after{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(76,201,240,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(76,201,240,.04) 1px,transparent 1px);background-size:44px 44px;pointer-events:none;z-index:0;opacity:.5}
.shell{position:relative;z-index:1;display:flex;min-height:100vh;--sidebar-w:248px;--sidebar-w-mini:72px}
.sidebar{width:var(--sidebar-w);background:linear-gradient(180deg,rgba(11,14,26,.97),rgba(14,18,32,.95));border-inline-end:1px solid var(--adm-border);padding:14px 10px;flex-shrink:0;position:sticky;top:0;height:100vh;overflow-x:hidden;overflow-y:auto;display:flex;flex-direction:column;transition:width .2s ease,padding .2s ease;-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px)}
.sidebar-top{display:flex;align-items:center;gap:6px;margin-bottom:12px}
.sidebar .brand{display:flex;align-items:center;gap:10px;font-size:15px;font-weight:900;color:#fff;text-decoration:none;flex:1;min-width:0;padding:10px;border-radius:14px;background:rgba(138,99,255,.12);border:1px solid rgba(138,99,255,.28);overflow:hidden;box-shadow:inset 0 0 24px rgba(138,99,255,.08)}
.sidebar .brand-ico{flex-shrink:0;font-size:18px}
.sidebar .brand-logo{height:28px;width:auto;max-width:120px;object-fit:contain;display:block;flex-shrink:0}
.sidebar .brand-txt{line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sidebar-toggle,.mob-menu{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border:1px solid var(--adm-border);border-radius:12px;background:rgba(255,255,255,.04);color:var(--adm-soft);cursor:pointer;font-size:16px;font-family:inherit;flex-shrink:0}
.sidebar-toggle:hover,.mob-menu:hover{background:rgba(138,99,255,.15);color:#fff;border-color:rgba(138,99,255,.35)}
.sidebar-nav{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;padding:2px 0;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:#2c4a6e transparent}
.sidebar-nav::-webkit-scrollbar{width:5px}
.sidebar-nav::-webkit-scrollbar-thumb{background:#2c4a6e;border-radius:99px}
.sidebar-bottom{flex-shrink:0;margin-top:8px;padding-top:10px;border-top:1px solid var(--adm-border)}
.sidebar a{display:flex;align-items:center;gap:10px;padding:9px 12px;margin:2px 0;border-radius:12px;color:var(--adm-soft);text-decoration:none;font-size:13px;font-weight:600;transition:background .15s,color .15s,border-color .15s;white-space:nowrap;border:1px solid transparent}
.sidebar a .nav-ico{width:22px;text-align:center;font-size:15px;flex-shrink:0}
.sidebar a .nav-label{overflow:hidden;text-overflow:ellipsis}
.sidebar a:hover{background:rgba(138,99,255,.1);color:#fff;border-color:rgba(138,99,255,.2)}
.sidebar a.active{background:var(--adm-grad);color:#fff;font-weight:800;box-shadow:var(--adm-glow);border-color:transparent}
.sidebar .logout{margin-top:0;color:#ffb9c6;border-top:0;padding-top:0}
.sidebar .logout .nav-label{transition:opacity .15s}
.main-wrap{flex:1;min-width:0;display:flex;flex-direction:column}
.main-topbar{display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--adm-border);background:rgba(11,14,26,.82);position:sticky;top:0;z-index:40;-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px)}
.mob-menu{display:none}
.page-title{font-size:18px;font-weight:800;margin:0;letter-spacing:-.02em;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:linear-gradient(135deg,#fff 0%,#d4c4ff 40%,var(--adm-cyan) 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.main{flex:1;padding:20px 22px 40px;width:100%;max-width:1360px}
.main>.h1-fallback{font-size:24px;margin-bottom:14px;font-weight:800;letter-spacing:-.02em}
h1{font-size:24px;margin-bottom:14px;font-weight:800;letter-spacing:-.02em}
.sidebar-collapsed .sidebar{width:var(--sidebar-w-mini);padding-inline:8px}
.sidebar-collapsed .brand-txt,.sidebar-collapsed .nav-label,.sidebar-collapsed .sidebar-foot-txt,.sidebar-collapsed .logout .nav-label{opacity:0;width:0;overflow:hidden;display:none}
.sidebar-collapsed .brand{justify-content:center;padding:10px 6px}
.sidebar-collapsed .sidebar a{justify-content:center;padding:10px 8px}
.sidebar-collapsed .sidebar-toggle .tog-icon{transform:rotate(180deg)}
html[dir="rtl"] .sidebar-collapsed .sidebar-toggle .tog-icon{transform:rotate(-180deg)}
.sidebar-overlay{display:none}
h2{font-size:16px;margin:0 0 12px;font-weight:800}
.sub{color:var(--adm-muted);font-size:13px;margin-bottom:12px;line-height:1.65}
.card{background:var(--adm-glass);border:1px solid var(--adm-border);border-radius:18px;padding:18px 20px;margin-bottom:16px;-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);box-shadow:0 12px 40px rgba(0,0,0,.18)}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;flex-wrap:wrap}
.card-head h2{margin:0;color:#f2f6ff}
.card-link{font-size:12px;font-weight:700;color:var(--adm-cyan);text-decoration:none}
.card-link:hover{text-decoration:underline;color:#9ee7ff}
.dash-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px;padding:20px 22px;border-radius:20px;background:var(--adm-glass);border:1px solid rgba(138,99,255,.25);box-shadow:0 16px 48px rgba(0,0,0,.22);position:relative;overflow:hidden}
.dash-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(320px 180px at 0% 0%,rgba(138,99,255,.18),transparent 70%);pointer-events:none}
.dash-hero>div{position:relative;z-index:1}
.dash-hero h1{margin:0 0 6px;font-size:22px;background:linear-gradient(135deg,#fff 0%,#d4c4ff 35%,var(--adm-purple) 70%,var(--adm-cyan) 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.dash-hero .sub{margin:0}
.dash-pills{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.dash-pill{display:inline-flex;align-items:center;font-size:11px;font-weight:700;padding:6px 12px;border-radius:99px;background:rgba(255,255,255,.04);border:1px solid var(--adm-border);color:var(--adm-soft);line-height:1.3;white-space:nowrap;gap:6px}
.dash-pill::before{content:'';width:7px;height:7px;border-radius:50%;background:currentColor;opacity:.9;flex-shrink:0}
.dash-pill.ok{background:rgba(74,222,128,.1);border-color:rgba(74,222,128,.32);color:#7de8a8}
.dash-pill.warn{background:rgba(255,212,121,.1);border-color:rgba(255,212,121,.28);color:#ffd479}
.dash-pill.err{background:rgba(255,120,120,.1);border-color:rgba(255,120,120,.28);color:#ff9f9f}
.topbar-actions{display:flex;align-items:center;gap:8px;flex-shrink:0}
.tb-btn{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:50%;border:1px solid var(--adm-border);background:rgba(255,255,255,.04);color:var(--adm-soft);text-decoration:none;font-size:16px;position:relative;transition:background .15s,border-color .15s}
.tb-btn:hover{background:rgba(138,99,255,.14);border-color:rgba(138,99,255,.35);color:#fff}
.tb-lang{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;margin-inline-start:4px}
.tb-lang a{color:var(--adm-muted);text-decoration:none;padding:4px 6px;border-radius:8px}
.tb-lang a.active{color:var(--adm-cyan)}
.tb-lang .lang-sep{color:var(--adm-muted);opacity:.5}
.kpi-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.kpi-card{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:18px 16px;border-radius:18px;background:var(--adm-glass);border:1px solid var(--adm-border);transition:border-color .15s,transform .15s}
.kpi-card:hover{border-color:rgba(138,99,255,.32);transform:translateY(-2px)}
.kpi-body{min-width:0}
.kpi-card .kpi-l{font-size:12px;color:var(--adm-muted);margin-bottom:6px;font-weight:600}
.kpi-card .kpi-n{font-size:30px;font-weight:900;line-height:1;color:#fff;letter-spacing:-.02em}
.kpi-card .kpi-s{font-size:11px;color:var(--adm-muted);margin-top:6px;line-height:1.4}
.kpi-card .kpi-s.up{color:#7de8a8}.kpi-card .kpi-s.muted{color:var(--adm-muted)}
.kpi-ico{flex-shrink:0;width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;background:rgba(138,99,255,.14);border:1px solid rgba(138,99,255,.24)}
.kpi-card.t-cyan .kpi-n{color:var(--adm-cyan)}.kpi-card.t-cyan .kpi-ico{background:rgba(76,201,240,.12);border-color:rgba(76,201,240,.28)}
.kpi-card.t-gold .kpi-n{color:#f0c96a}.kpi-card.t-gold .kpi-ico{background:rgba(240,201,106,.12);border-color:rgba(240,201,106,.28)}
.kpi-card.t-warn .kpi-n{color:#ffd479}.kpi-card.t-warn .kpi-ico{background:rgba(255,212,121,.12);border-color:rgba(255,212,121,.28)}
.qa-strip{margin-bottom:18px}
.qa-title{font-size:15px;font-weight:800;color:#f2f6ff;margin-bottom:12px}
.qa-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}
.qa{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;min-height:108px;padding:16px 10px;border-radius:16px;text-decoration:none;color:var(--adm-text);background:var(--adm-glass);border:1px solid var(--adm-border);transition:transform .15s,border-color .15s,box-shadow .15s}
.qa:hover{transform:translateY(-3px);border-color:rgba(138,99,255,.45);box-shadow:0 10px 28px rgba(138,99,255,.18)}
.qa-ico-wrap{width:46px;height:46px;border-radius:14px;background:rgba(138,99,255,.16);border:1px solid rgba(138,99,255,.28);display:flex;align-items:center;justify-content:center}
.qa .ico{font-size:22px;line-height:1}
.qa .lbl{font-size:12px;font-weight:700;text-align:center;line-height:1.35}
.qa.t-blue:hover{border-color:var(--adm-cyan)}.qa.t-green:hover{border-color:#4ade80}.qa.t-gold:hover{border-color:#f0c96a}.qa.t-purple:hover{border-color:var(--adm-purple)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:12px;margin-bottom:16px}
.stat{background:rgba(255,255,255,.04);border:1px solid var(--adm-border);border-radius:14px;padding:14px 12px;text-align:center;transition:border-color .15s,transform .15s}
.stat:hover{border-color:rgba(138,99,255,.35);transform:translateY(-2px)}
.stat .n{font-size:26px;font-weight:900;color:var(--adm-cyan);line-height:1.1}
.stat .l{font-size:11px;color:var(--adm-muted);margin-top:5px;line-height:1.35}
.stat.t-ok .n{color:#5ddea8}.stat.t-warn .n{color:#ffd479}.stat.t-exp .n{color:#ff9fb1}.stat.t-gold .n{color:#f0c96a}
.dash-cols{display:grid;grid-template-columns:1.15fr .85fr;gap:16px;align-items:start}
.dash-cols .card{margin-bottom:0;overflow:hidden}
.dash-cols>.col{display:flex;flex-direction:column;gap:16px;min-width:0}
.tbl-wrap{overflow:auto;border-radius:12px;border:1px solid var(--adm-border)}
.tbl-wrap table{margin:0}
.tbl-compact th,.tbl-compact td{padding:8px 10px;font-size:13px}
.tbl-compact tr:hover td{background:rgba(138,99,255,.08)}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:10px 12px;border-bottom:1px solid var(--adm-border);text-align:start;vertical-align:top}
th{color:var(--adm-muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
label{display:block;font-size:13px;margin:10px 0 5px;color:var(--adm-soft)}
details.admin-panel>summary{cursor:pointer;font-weight:800;font-size:16px;list-style:none;display:flex;align-items:center;gap:8px;color:var(--adm-text);user-select:none}
details.admin-panel>summary::-webkit-details-marker{display:none}
details.admin-panel>summary::before{content:"▸";color:var(--adm-cyan);transition:transform .15s;font-size:14px}
details.admin-panel[open]>summary::before{transform:rotate(90deg)}
details.admin-panel[open]>summary{margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--adm-border)}
details.admin-panel .panel-body{padding-top:4px}
details.admin-panel .inv-count{color:var(--adm-muted);font-weight:600;font-size:13px}
input,textarea{width:100%;padding:10px 12px;border-radius:12px;border:1px solid var(--adm-border);background:var(--adm-input-bg);color:var(--adm-text);font-size:14px;font-family:inherit}
select{width:100%;padding:10px 36px 10px 12px;border-radius:12px;border:1px solid var(--adm-border);background-color:var(--adm-input-bg);color:var(--adm-text);font-size:14px;font-family:inherit;-webkit-appearance:none;appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%23c5cce8' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center}
html[dir="rtl"] select{padding:10px 12px 10px 36px;background-position:left 12px center}
select option,select optgroup{background-color:#141a2e;color:#eef2ff}
select option:checked{background-color:#252d4a;color:#fff}
input:focus,select:focus,textarea:focus{outline:none;border-color:rgba(138,99,255,.45);box-shadow:0 0 0 3px rgba(138,99,255,.12)}
input[type=date]{color-scheme:dark;cursor:pointer}
input[type=date]::-webkit-calendar-picker-indicator{cursor:pointer;filter:invert(.85)}
textarea{min-height:90px;resize:vertical}
.btn,.btn-sm{display:inline-block;padding:10px 16px;border:0;border-radius:12px;background:var(--adm-grad);color:#fff;font-weight:800;cursor:pointer;text-decoration:none;font-size:14px;font-family:inherit;box-shadow:var(--adm-glow);transition:transform .15s,box-shadow .15s}
.btn:hover,.btn-sm:hover{transform:translateY(-1px);box-shadow:0 12px 36px rgba(138,99,255,.38)}
.btn-sm{padding:6px 12px;font-size:12px;margin:2px;box-shadow:none}
.btn.green{background:var(--adm-grad)}.btn.red{background:linear-gradient(135deg,#e74c6f,#c0395a);box-shadow:0 8px 24px rgba(231,76,111,.28)}.btn.gray{background:rgba(255,255,255,.08);border:1px solid var(--adm-border);box-shadow:none;color:var(--adm-soft)}
.err,.ok,.warn,.danger{padding:12px 14px;border-radius:10px;margin-bottom:14px;font-size:14px}
.dev-announce-wrap{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.dev-announce-body{flex:1;min-width:0}
.dev-announce-dismiss{margin:0;padding:0;flex-shrink:0}
.dev-announce-dismiss-btn{background:transparent;border:none;color:inherit;font-size:22px;line-height:1;cursor:pointer;opacity:.75;padding:0 4px}
.dev-announce-dismiss-btn:hover{opacity:1}
.dev-announce-info{background:#122033;border:1px solid #1f4a6e;color:#9fd4ff}
.release-notes-body{white-space:pre-wrap;word-break:break-word;font-family:inherit;font-size:13px;line-height:1.55;margin:10px 0 0;padding:12px;background:rgba(0,0,0,.2);border-radius:8px;max-height:240px;overflow:auto}
.err{background:#3a1620;border:1px solid #7a2a3a;color:#ffb9c6}
.ok{background:#12301f;border:1px solid #1f5c39;color:#a9f0c6}
.warn{background:#3a2f12;border:1px solid #6e5a1f;color:#ffd479}
.danger{background:#4a1218;border:2px solid #ff6b6b;color:#ffd4d8;line-height:1.7}
.badge{display:inline-block;padding:4px 10px;border-radius:99px;font-size:11px;font-weight:700}
.b-ok{background:#12301f;color:#7de8a8}.b-exp{background:#3a1620;color:#ff9fb1}.b-warn{background:#3a2f12;color:#ffd479}.b-muted{background:rgba(255,255,255,.06);color:var(--adm-muted);border:1px solid var(--adm-border)}
.row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.bar-chart{display:flex;align-items:flex-end;justify-content:space-around;gap:8px;height:190px;margin:8px 0 4px;padding:0 4px 30px;overflow:hidden;position:relative;isolation:isolate}
.bar-wrap{flex:1 1 0;min-width:0;max-width:72px;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;gap:8px}
.bar-val{font-size:12px;font-weight:800;color:#fff;line-height:1;white-space:nowrap}
.bar{flex:0 0 auto;width:100%;max-width:48px;min-width:18px;align-self:stretch;background:linear-gradient(180deg,#a855f7 0%,#06b6d4 100%);border-radius:12px 12px 0 0;box-shadow:0 0 18px rgba(138,99,255,.22)}
.bar-lbl{font-size:10px;color:var(--adm-muted);white-space:nowrap;line-height:1.2}
.c-avatar{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;font-size:12px;font-weight:800;color:#fff;background:linear-gradient(135deg,#3b82f6,#22d3ee);flex-shrink:0}
.c-cell{display:flex;align-items:center;gap:10px;min-width:0}
.c-cell .c-meta{min-width:0}
.c-cell .c-name{font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.c-cell .c-phone{font-size:12px;color:var(--adm-muted);margin-top:2px;direction:ltr;text-align:inherit}
.clients-dash td{vertical-align:middle}
.stock-panel{background:linear-gradient(180deg,rgba(26,22,20,.92),rgba(18,16,14,.88));border-color:rgba(245,158,11,.22)}
.stock-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-radius:14px;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.18);margin-bottom:10px}
.stock-row:last-child{margin-bottom:0}
.stock-row-main{display:flex;align-items:center;gap:12px;min-width:0}
.stock-ico{width:40px;height:40px;border-radius:12px;background:rgba(245,158,11,.14);border:1px solid rgba(245,158,11,.28);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.stock-row .stock-title{font-weight:800;color:#fff}
.stock-row .stock-sub{font-size:12px;color:var(--adm-muted);margin-top:2px}
.stock-count{font-size:28px;font-weight:900;color:#f59e0b;line-height:1;flex-shrink:0}
.mono{direction:ltr;font-family:Consolas,monospace}
.finance-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.finance-kpi{padding:12px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid var(--adm-border)}
.finance-kpi .v{font-size:18px;font-weight:900;color:var(--adm-cyan)}
.finance-kpi .k{font-size:11px;color:var(--adm-muted);margin-top:4px}
@media(max-width:1100px){.dash-cols{grid-template-columns:1fr}.finance-kpis{grid-template-columns:1fr}.kpi-row{grid-template-columns:repeat(2,minmax(0,1fr))}.qa-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:900px){
.shell{--sidebar-w:260px}
.sidebar{width:var(--sidebar-w)!important;padding:14px 10px!important}
.sidebar-collapsed .brand-txt,.sidebar-collapsed .nav-label,.sidebar-collapsed .sidebar-foot-txt,.sidebar-collapsed .logout .nav-label{display:block!important;opacity:1!important;width:auto!important;overflow:visible!important}
.sidebar-collapsed .brand{justify-content:flex-start!important;padding:10px!important}
.sidebar-collapsed .sidebar a{justify-content:flex-start!important;padding:9px 12px!important}
.sidebar{position:fixed;inset-block:0;inset-inline-start:0;z-index:120;height:100vh;transform:translateX(-105%);transition:transform .22s ease}
html[dir="rtl"] .sidebar{transform:translateX(105%)}
html[dir="rtl"] .sidebar-open .sidebar{transform:translateX(0)}
.sidebar-open .sidebar{transform:translateX(0)}
.sidebar-open .sidebar-overlay{display:block;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:110}
.mob-menu{display:inline-flex}
.sidebar-toggle{display:none}
.main-topbar{padding:10px 14px}
.main{padding:16px 14px 32px}
.qa-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
.kpi-row{grid-template-columns:1fr}
}
@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){.main-topbar,.sidebar{background:#0b0e1a}}
body.login-page{position:relative}
body.login-page::before{content:'';position:fixed;inset:0;background:radial-gradient(700px 380px at 18% -5%,rgba(138,99,255,.22),transparent 65%),radial-gradient(560px 320px at 88% 8%,rgba(76,201,240,.16),transparent 60%);pointer-events:none;z-index:0}
body.login-page::after{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(76,201,240,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(76,201,240,.04) 1px,transparent 1px);background-size:44px 44px;pointer-events:none;z-index:0;opacity:.5}
.login-shell{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:28px 20px;position:relative;z-index:1}
.login-wrap{width:100%;max-width:420px}
.login-page-title{text-align:center;font-size:26px;font-weight:900;margin:0 0 22px;letter-spacing:-.02em;background:linear-gradient(135deg,#fff 0%,#d4c4ff 40%,var(--adm-cyan) 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.login-card{margin:0;padding:28px 26px 26px;border:1px solid rgba(138,99,255,.28);border-radius:20px;box-shadow:0 20px 56px rgba(0,0,0,.28),inset 0 1px 0 rgba(255,255,255,.04)}
.login-card-head{display:flex;align-items:center;gap:10px;margin-bottom:6px}
.login-icon{font-size:26px;line-height:1;filter:drop-shadow(0 4px 12px rgba(138,99,255,.35))}
.login-card h2{margin:0;font-size:17px;font-weight:800;color:#f2f6ff}
.login-card .sub{margin:0 0 22px;font-size:13px;line-height:1.7}
.login-card label:first-of-type{margin-top:0}
.login-card input{font-size:15px;padding:12px 14px}
.login-card .btn{display:block;width:100%;margin-top:18px;min-height:46px;font-size:15px;text-align:center}
.login-foot{text-align:center;margin-top:24px;padding-top:18px;border-top:1px solid var(--adm-border);font-size:11px;color:var(--adm-muted);line-height:1.75}
.login-foot a{color:var(--adm-cyan);text-decoration:none}
.sidebar-foot{margin-top:10px;padding:10px 8px 4px;border-top:1px solid var(--adm-border);font-size:11px;color:var(--adm-muted);line-height:1.65}
.sidebar-foot a{color:var(--adm-purple);text-decoration:none;font-weight:600}
.sidebar-foot a:hover{text-decoration:underline;color:var(--adm-cyan)}
.sidebar-ver{color:var(--adm-muted);font-size:10px;margin-bottom:4px}
.inv-plan-panel{margin-top:10px}
.client-filters{display:grid;grid-template-columns:1fr auto auto auto;gap:10px;margin-bottom:14px;align-items:end}
.client-filters label{font-size:12px;margin:0 0 4px}
.client-filters .cf-field{display:flex;flex-direction:column;min-width:0}
.client-filters .cf-actions{display:flex;gap:8px;flex-wrap:wrap}
.client-filters .cf-count{font-size:12px;color:var(--adm-muted);margin-bottom:6px;grid-column:1/-1}
.stock-alert-list{margin:10px 0 0;padding-inline-start:20px;line-height:1.75}
.stock-alert-list li{margin:4px 0}
.admin-pagination{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid var(--adm-border)}
.admin-pagination .pg-info{font-size:13px;color:var(--adm-muted)}
.admin-pagination .pg-btn[disabled]{opacity:.45;pointer-events:none}
@media(max-width:720px){.client-filters{grid-template-columns:1fr 1fr}.client-filters .cf-actions{grid-column:1/-1}}
.client-server-pick-wrap{min-width:0}
.client-server-pick-label{display:block;margin-bottom:6px;font-weight:700;color:var(--adm-cyan)}
.client-server-pick{width:100%;min-width:0;max-width:240px;font-weight:600}
.client-group-row td{vertical-align:middle}
CSS;

    public const PAGE_SIZE = 25;

    /** @param array{locale: string, title: string, body: string, flash?: string} $opts */
    public static function loginPage(array $opts): string
    {
        $loc = $opts['locale'];
        $dir = $loc === 'ar' ? 'rtl' : 'ltr';
        $font = $loc === 'ar' ? "'Tajawal','Segoe UI',Tahoma,sans-serif" : "'Segoe UI',system-ui,sans-serif";
        $e = Html::esc(...);
        $flash = $opts['flash'] ?? '';
        $css = self::CSS;
        $fontLink = $loc === 'ar'
            ? '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
                . '<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">'
            : '';
        return '<!doctype html><html lang="' . $e($loc) . '" dir="' . $e($dir) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b0e1a">'
            . '<title>' . $e($opts['title']) . ' — Admin</title>'
            . $fontLink
            . '<style>:root{--font:' . $font . '}</style><style>' . $css . '</style></head><body class="login-page">'
            . '<div class="login-shell"><div class="login-wrap"><h1 class="login-page-title">'
            . $e($opts['title']) . '</h1>' . IntegrityGuard::adminBannerHtml($loc) . $flash . $opts['body']
            . self::footer($loc)
            . '</div></div>'
            . \App\Helpers\PageGuard::hardenScript()
            . '</body></html>';
    }

    private static function footer(string $loc): string
    {
        return DevCredit::adminFooterHtml($loc);
    }

    /** @param array{locale: string, active?: string, title: string, body: string, flash?: string} $opts */
    public static function page(array $opts): string
    {
        $loc = $opts['locale'];
        $dir = $loc === 'ar' ? 'rtl' : 'ltr';
        $font = $loc === 'ar' ? "'Tajawal','Segoe UI',Tahoma,sans-serif" : "'Segoe UI',system-ui,sans-serif";
        $e = Html::esc(...);
        $nav = [
            ['/', 'admin.nav.home', 'home', '🏠'],
            ['/clients', 'admin.nav.clients', 'clients', '👥'],
            ['/products', 'admin.nav.products', 'products', '📦'],
            ['/invoices', 'admin.nav.invoices', 'invoices', '🧾'],
            ['/payments', 'admin.nav.payments', 'payments', '💳'],
            ['/telegram', 'admin.nav.telegram', 'telegram', '🤖'],
            ['/hetzner', 'admin.nav.hetzner', 'hetzner', '☁️'],
            ['/activity', 'admin.nav.activity', 'activity', '📜'],
            ['/branding', 'admin.nav.branding', 'branding', '🎨'],
            ['/updates', 'admin.nav.updates', 'updates', '🔄'],
            ['/settings', 'admin.nav.settings', 'settings', '⚙️'],
        ];
        $active = $opts['active'] ?? '';
        $links = '';
        foreach ($nav as [$href, $key, $id, $ico]) {
            $label = I18n::t($key, $loc);
            $cls = $active === $id ? ' class="active"' : '';
            $links .= '<a href="' . AdminPath::url($href) . '"' . $cls
                . ' title="' . $e($label) . '">'
                . '<span class="nav-ico">' . $ico . '</span><span class="nav-label">' . $e($label) . '</span></a>';
        }
        $flash = $opts['flash'] ?? '';
        $css = self::CSS;
        $pageClass = $opts['pageClass'] ?? '';
        $header = $opts['header'] ?? '';
        $pageTitle = $opts['title'];
        $storeName = trim((string) ($opts['storeName'] ?? ''));
        $brandCss = (string) ($opts['brandCss'] ?? '');
        $brandBg = (string) ($opts['brandBg'] ?? '#0b0e1a');
        $brandLogoUrl = trim((string) ($opts['brandLogoUrl'] ?? ''));
        $csrf = (string) ($opts['csrf'] ?? '');
        $brandSub = $storeName !== '' ? $storeName : 'VPS';
        $brandIco = $brandLogoUrl !== ''
            ? '<img class="brand-logo" src="' . $e($brandLogoUrl) . '" alt="' . $e($brandSub) . '">'
            : '<span class="brand-ico">🖥️</span>';
        $collapseLabel = I18n::t('admin.sidebar.toggle', $loc);
        $fontLink = $loc === 'ar'
            ? '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
                . '<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">'
            : '';
        $footer = self::footer($loc);
        $logoutLabel = I18n::t('admin.logout', $loc);
        $js = self::sidebarScript();
        $st = \App\Database\Database::getSettings();
        $allowSwitch = ($st['allow_locale_switch'] ?? true) !== false;
        $topbarRight = self::topbarActions($loc, $allowSwitch);
        return '<!doctype html><html lang="' . $e($loc) . '" dir="' . $e($dir) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="' . $e($brandBg) . '"><title>' . $e($pageTitle) . ' — Admin</title>'
            . Csrf::metaTag($csrf)
            . $fontLink
            . '<style>:root{--font:' . $font . '}</style><style>' . $css . '</style>'
            . ($brandCss !== '' ? '<style>' . $brandCss . '</style>' : '')
            . '</head><body>'
            . '<div class="shell" id="admin-shell">'
            . '<div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>'
            . '<aside class="sidebar" id="admin-sidebar">'
            . '<div class="sidebar-top">'
            . '<a class="brand" href="' . AdminPath::url() . '" title="' . $e($brandSub) . '">'
            . $brandIco . '<span class="brand-txt">' . $e($brandSub) . '</span></a>'
            . '<button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="' . $e($collapseLabel) . '" title="' . $e($collapseLabel) . '">'
            . '<span class="tog-icon">‹</span></button>'
            . '</div>'
            . '<nav class="sidebar-nav">' . $links . '</nav>'
            . '<div class="sidebar-bottom">'
            . '<a class="logout" href="' . AdminPath::url('/logout') . '" title="' . $e($logoutLabel) . '">'
            . '<span class="nav-ico">🚪</span><span class="nav-label">' . $e($logoutLabel) . '</span></a>'
            . $footer
            . '</div>'
            . '</aside>'
            . '<div class="main-wrap">'
            . '<header class="main-topbar">'
            . '<button type="button" class="mob-menu" id="mob-menu" aria-label="' . $e($collapseLabel) . '">☰</button>'
            . '<h1 class="page-title">' . $e($pageTitle) . '</h1>'
            . $topbarRight
            . '</header>'
            . '<main class="main' . ($pageClass !== '' ? ' ' . $e($pageClass) : '') . '">'
            . $header . $flash . $opts['body']
            . '</main></div></div>' . $js . Csrf::formInjectScript() . \App\Helpers\PageGuard::hardenScript() . '</body></html>';
    }

    private static function sidebarScript(): string
    {
        return <<<'JS'
<script>
(function(){
  var shell=document.getElementById('admin-shell');
  var toggle=document.getElementById('sidebar-toggle');
  var mob=document.getElementById('mob-menu');
  var overlay=document.getElementById('sidebar-overlay');
  var key='q8admin-sidebar-collapsed';
  if(!shell||!toggle)return;
  try{if(localStorage.getItem(key)==='1')shell.classList.add('sidebar-collapsed');}catch(e){}
  toggle.addEventListener('click',function(){
    shell.classList.toggle('sidebar-collapsed');
    try{localStorage.setItem(key,shell.classList.contains('sidebar-collapsed')?'1':'0');}catch(e){}
  });
  function closeMob(){shell.classList.remove('sidebar-open');}
  function openMob(){shell.classList.add('sidebar-open');}
  if(mob)mob.addEventListener('click',function(){shell.classList.contains('sidebar-open')?closeMob():openMob();});
  if(overlay)overlay.addEventListener('click',closeMob);
  window.addEventListener('resize',function(){if(window.innerWidth>900)closeMob();});
  function bindPanel(id,key,defOpen){
    var el=document.getElementById(id);
    if(!el)return;
    try{var v=localStorage.getItem(key);if(v==='1')el.setAttribute('open','');else if(v==='0')el.removeAttribute('open');else if(defOpen)el.setAttribute('open','');}catch(e){}
    el.addEventListener('toggle',function(){try{localStorage.setItem(key,el.open?'1':'0');}catch(e){}});
  }
  bindPanel('client-list-panel','q8admin-client-list','1');
  bindPanel('client-add-panel','q8admin-client-add','0');
  bindPanel('product-new-panel','q8admin-product-new','0');
})();
</script>
JS;
    }

    public static function flash(string $loc, ?string $okKey = null, ?string $err = null): string
    {
        if ($err) {
            return '<div class="err">' . Html::esc($err) . '</div>';
        }
        if ($okKey) {
            return '<div class="ok">' . Html::esc(I18n::t($okKey, $loc)) . '</div>';
        }
        return '';
    }

    public static function badge(string $text, string $cls = 'b-muted'): string
    {
        return '<span class="badge ' . Html::esc($cls) . '">' . Html::esc($text) . '</span>';
    }

    /** @param list<array{n: string|int, l: string, tone?: string}> $stats */
    public static function statsGrid(array $stats): string
    {
        $html = '<div class="grid">';
        foreach ($stats as $s) {
            $tone = $s['tone'] ?? '';
            $cls = $tone !== '' ? ' stat t-' . Html::esc($tone) : ' stat';
            $html .= '<div class="' . trim($cls) . '"><div class="n">' . Html::esc((string) $s['n']) . '</div><div class="l">' . Html::esc($s['l']) . '</div></div>';
        }
        return $html . '</div>';
    }

    /** @param list<array{href: string, icon: string, label: string, tone?: string}> $items */
    public static function quickActions(string $title, array $items): string
    {
        $html = '<div class="qa-strip"><div class="qa-title">' . Html::esc($title) . '</div><div class="qa-grid">';
        foreach ($items as $item) {
            $tone = $item['tone'] ?? 'blue';
            $html .= '<a class="qa t-' . Html::esc($tone) . '" href="' . Html::esc($item['href']) . '">'
                . '<span class="qa-ico-wrap"><span class="ico">' . $item['icon'] . '</span></span>'
                . '<span class="lbl">' . Html::esc($item['label']) . '</span></a>';
        }
        return $html . '</div></div>';
    }

    public static function dashHero(string $title, string $subtitle, string $pillsHtml = ''): string
    {
        return '<div class="dash-hero"><div><h1>' . Html::esc($title) . '</h1><p class="sub">' . $subtitle . '</p>'
            . ($pillsHtml !== '' ? '<div class="dash-pills">' . $pillsHtml . '</div>' : '')
            . '</div></div>';
    }

    public static function dashPill(string $text, string $tone = ''): string
    {
        $cls = 'dash-pill' . ($tone !== '' ? ' ' . Html::esc($tone) : '');
        return '<span class="' . $cls . '">' . Html::esc($text) . '</span>';
    }

    public static function topbarActions(string $loc, bool $allowSwitch = true): string
    {
        $storeUrl = \App\Helpers\Url::to('/');
        $switcher = I18n::langSwitcher('admin', $loc, AdminPath::to(), $allowSwitch);
        $lang = $switcher !== '' ? '<span class="tb-lang">' . $switcher . '</span>' : '';
        return '<div class="topbar-actions">'
            . '<a class="tb-btn" href="' . Html::esc($storeUrl) . '" target="_blank" rel="noopener" title="' . Html::esc(I18n::t('admin.topbar.store', $loc)) . '">🌐</a>'
            . $lang
            . '</div>';
    }

    /** @param list<array{n: string|int, l: string, icon?: string, sub?: string, tone?: string}> $cards */
    public static function kpiRow(array $cards): string
    {
        $html = '<div class="kpi-row">';
        foreach ($cards as $card) {
            $tone = $card['tone'] ?? '';
            $cls = 'kpi-card' . ($tone !== '' ? ' t-' . Html::esc($tone) : '');
            $sub = $card['sub'] ?? '';
            $subCls = str_starts_with($sub, '↑') || str_starts_with($sub, '+') ? 'kpi-s up' : 'kpi-s muted';
            $html .= '<div class="' . $cls . '"><div class="kpi-body">'
                . '<div class="kpi-l">' . Html::esc($card['l']) . '</div>'
                . '<div class="kpi-n">' . Html::esc((string) $card['n']) . '</div>'
                . ($sub !== '' ? '<div class="' . $subCls . '">' . Html::esc($sub) . '</div>' : '')
                . '</div>'
                . '<div class="kpi-ico">' . ($card['icon'] ?? '📊') . '</div>'
                . '</div>';
        }
        return $html . '</div>';
    }

    public static function clientAvatar(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ini = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $ini .= mb_strtoupper(mb_substr($p, 0, 1));
        }
        if ($ini === '') {
            $ini = '?';
        }
        return '<span class="c-avatar">' . Html::esc($ini) . '</span>';
    }

    /** @param list<array{client: array<string, mixed>, plan: string, ip: string}> $rows */
    public static function latestClientsTable(array $rows, string $loc): string
    {
        $body = '';
        foreach ($rows as $row) {
            $c = $row['client'];
            $name = (string) ($c['name'] ?? '');
            $phone = (string) ($c['phone'] ?? '');
            $ip = $row['ip'] !== '' ? $row['ip'] : '—';
            $body .= '<tr><td><div class="c-cell">' . self::clientAvatar($name)
                . '<div class="c-meta"><div class="c-name">' . Html::esc($name) . '</div>'
                . '<div class="c-phone">' . Html::esc($phone) . '</div></div></div></td>'
                . '<td>' . Html::esc($row['plan']) . '</td>'
                . '<td class="mono">' . Html::esc($ip) . '</td>'
                . '<td>' . self::clientStatusBadge($c, $loc) . '</td></tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="4" class="sub">' . Html::esc(I18n::t('admin.home.no_latest_clients', $loc)) . '</td></tr>';
        }
        return self::tableWrap('<table class="clients-dash"><tr><th>'
            . Html::esc(I18n::t('admin.common.client', $loc)) . '</th><th>'
            . Html::esc(I18n::t('admin.home.col_plan', $loc)) . '</th><th>'
            . Html::esc(I18n::t('admin.common.ip', $loc)) . '</th><th>'
            . Html::esc(I18n::t('admin.common.status', $loc)) . '</th></tr>' . $body . '</table>');
    }

    /** @param list<array{product: array<string, mixed>, available: int}> $items */
    public static function stockAlertsCard(array $items, string $loc, ?string $linkHref = null): string
    {
        if ($items === []) {
            return '';
        }
        $rows = '';
        foreach ($items as $row) {
            $name = (string) ($row['product']['name'] ?? '');
            $n = (int) ($row['available'] ?? 0);
            $rows .= '<div class="stock-row"><div class="stock-row-main">'
                . '<div class="stock-ico">⚠️</div><div><div class="stock-title">' . Html::esc($name) . '</div>'
                . '<div class="stock-sub">' . Html::esc(I18n::t('admin.home.stock_low_sub', $loc)) . '</div></div></div>'
                . '<div class="stock-count">' . Html::esc((string) $n) . '</div></div>';
        }
        $link = ($linkHref !== null)
            ? '<a class="card-link" href="' . Html::esc($linkHref) . '">' . Html::esc(I18n::t('admin.home.view_all', $loc)) . ' →</a>'
            : '';
        return '<div class="card stock-panel">'
            . '<div class="card-head"><h2>' . Html::esc(I18n::t('admin.home.stock_alerts', $loc)) . '</h2>' . $link . '</div>'
            . $rows . '</div>';
    }

    public static function chartMonthLabel(string $ym, string $loc): string
    {
        $m = (int) substr($ym, 5, 2);
        $keys = ['', 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
        $key = $keys[$m] ?? 'jan';
        return I18n::t('admin.month.' . $key, $loc);
    }

    public static function installSecurityBanner(string $loc): string
    {
        if (!InstallCleanup::hasRemnants()) {
            return '';
        }
        $files = implode(', ', InstallCleanup::remnants());
        return '<div class="danger"><strong style="font-size:16px;display:block;margin-bottom:8px">'
            . I18n::t('admin.security.install_title', $loc)
            . '</strong><p style="margin:0 0 12px">'
            . I18n::t('admin.security.install_body', $loc, ['files' => $files])
            . '</p><form method="post" action="' . Html::esc(AdminPath::url('/security/remove-install')) . '" class="row">'
            . '<button type="submit" class="btn red">'
            . Html::esc(I18n::t('admin.security.install_btn', $loc))
            . '</button></form></div>';
    }

    public static function cardHead(string $title, ?string $linkHref = null, ?string $linkLabel = null): string
    {
        $link = ($linkHref !== null && $linkLabel !== null)
            ? '<a class="card-link" href="' . Html::esc($linkHref) . '">' . Html::esc($linkLabel) . ' →</a>'
            : '';
        return '<div class="card-head"><h2>' . Html::esc($title) . '</h2>' . $link . '</div>';
    }

    public static function tableWrap(string $tableHtml, bool $compact = true): string
    {
        $cls = $compact ? 'tbl-wrap tbl-compact' : 'tbl-wrap';
        return '<div class="' . $cls . '">' . $tableHtml . '</div>';
    }

    /** @param list<array{v: string, k: string}> $items */
    public static function financeKpis(array $items): string
    {
        $html = '<div class="finance-kpis">';
        foreach ($items as $item) {
            $html .= '<div class="finance-kpi"><div class="v">' . Html::esc($item['v']) . '</div><div class="k">' . Html::esc($item['k']) . '</div></div>';
        }
        return $html . '</div>';
    }

    public static function dashCols(string $left, string $right): string
    {
        return '<div class="dash-cols"><div class="col">' . $left . '</div><div class="col">' . $right . '</div></div>';
    }

    /** @param list<array{key: string, label: string, sum: float}> $months */
    public static function financeChart(array $months, float $maxSum): string
    {
        $chartH = 120;
        $html = '<div class="bar-chart">';
        foreach ($months as $m) {
            $sum = (float) ($m['sum'] ?? 0);
            $h = $maxSum > 0 ? max(10, (int) round(($sum / $maxSum) * $chartH)) : 10;
            $val = $sum >= 1000 ? number_format($sum / 1000, 1) . 'k' : (string) (int) round($sum);
            $html .= '<div class="bar-wrap" title="' . Html::esc((string) $sum) . '">'
                . '<div class="bar-val">' . Html::esc($val) . '</div>'
                . '<div class="bar" style="height:' . $h . 'px"></div>'
                . '<div class="bar-lbl">' . Html::esc($m['label']) . '</div></div>';
        }
        return $html . '</div>';
    }

    public static function pageNum(Request $req): int
    {
        return max(1, (int) ($req->query['page'] ?? 1));
    }

    /** @param array<string, scalar|null> $query */
    public static function paginationBar(string $loc, int $page, int $perPage, int $total, string $basePath, array $query = []): string
    {
        if ($total <= $perPage) {
            return '';
        }
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $from = ($page - 1) * $perPage + 1;
        $to = min($total, $page * $perPage);
        $hrefFor = static function (int $p) use ($basePath, $query): string {
            $q = $query;
            if ($p > 1) {
                $q['page'] = $p;
            } else {
                unset($q['page']);
            }
            $qs = http_build_query(array_filter($q, static fn($v) => $v !== null && $v !== ''));
            return AdminPath::url($basePath) . ($qs !== '' ? '?' . $qs : '');
        };
        $prev = $page > 1
            ? '<a class="btn btn-sm gray pg-btn" href="' . Html::esc($hrefFor($page - 1)) . '">' . Html::esc(I18n::t('admin.pagination.prev', $loc)) . '</a>'
            : '<span class="btn btn-sm gray pg-btn" aria-disabled="true">' . Html::esc(I18n::t('admin.pagination.prev', $loc)) . '</span>';
        $next = $page < $pages
            ? '<a class="btn btn-sm gray pg-btn" href="' . Html::esc($hrefFor($page + 1)) . '">' . Html::esc(I18n::t('admin.pagination.next', $loc)) . '</a>'
            : '<span class="btn btn-sm gray pg-btn" aria-disabled="true">' . Html::esc(I18n::t('admin.pagination.next', $loc)) . '</span>';
        $info = I18n::t('admin.pagination.range', $loc, [
            'from' => (string) $from,
            'to' => (string) $to,
            'total' => (string) $total,
        ]) . ' · ' . I18n::t('admin.pagination.page_of', $loc, [
            'page' => (string) $page,
            'pages' => (string) $pages,
        ]);
        return '<nav class="admin-pagination" aria-label="Pagination">' . $prev
            . '<span class="pg-info">' . Html::esc($info) . '</span>' . $next . '</nav>';
    }

    /** @param array{q?: string, filter?: string, sort?: string, list?: string} $query */
    public static function clientsFilterBar(string $loc, array $query, int $shown, int $total): string
    {
        $q = (string) ($query['q'] ?? '');
        $filter = (string) ($query['filter'] ?? 'all');
        $sort = (string) ($query['sort'] ?? 'expires_asc');
        $listOpen = ($query['list'] ?? '') === '1';
        $base = AdminPath::url('/clients');
        $filters = [
            'all' => I18n::t('admin.clients.filter.all', $loc),
            'active' => I18n::t('admin.clients.filter.active', $loc),
            'expiring' => I18n::t('admin.clients.filter.expiring', $loc),
            'expired' => I18n::t('admin.clients.filter.expired', $loc),
            'unlinked' => I18n::t('admin.clients.filter.unlinked', $loc),
            'no_server' => I18n::t('admin.clients.filter.no_server', $loc),
            'suspended' => I18n::t('admin.clients.filter.suspended', $loc),
        ];
        $sorts = [
            'expires_asc' => I18n::t('admin.clients.sort.expires_asc', $loc),
            'expires_desc' => I18n::t('admin.clients.sort.expires_desc', $loc),
            'name_asc' => I18n::t('admin.clients.sort.name_asc', $loc),
        ];
        $filterOpts = '';
        foreach ($filters as $id => $label) {
            $sel = $filter === $id ? ' selected' : '';
            $filterOpts .= '<option value="' . Html::esc($id) . '"' . $sel . '>' . Html::esc($label) . '</option>';
        }
        $sortOpts = '';
        foreach ($sorts as $id => $label) {
            $sel = $sort === $id ? ' selected' : '';
            $sortOpts .= '<option value="' . Html::esc($id) . '"' . $sel . '>' . Html::esc($label) . '</option>';
        }
        $count = $shown === $total
            ? I18n::t('admin.clients.filter_count_all', $loc, ['n' => $total])
            : I18n::t('admin.clients.filter_count', $loc, ['shown' => $shown, 'total' => $total]);
        return '<form class="client-filters" method="get" action="' . Html::esc($base) . '">'
            . ($listOpen ? '<input type="hidden" name="list" value="1">' : '')
            . '<div class="cf-count">' . Html::esc($count) . '</div>'
            . '<div class="cf-field"><label for="cf-q">' . Html::esc(I18n::t('admin.clients.search', $loc)) . '</label>'
            . '<input id="cf-q" name="q" value="' . Html::esc($q) . '" placeholder="' . Html::esc(I18n::t('admin.clients.search_ph', $loc)) . '"></div>'
            . '<div class="cf-field"><label for="cf-filter">' . Html::esc(I18n::t('admin.clients.filter_label', $loc)) . '</label>'
            . '<select id="cf-filter" name="filter">' . $filterOpts . '</select></div>'
            . '<div class="cf-field"><label for="cf-sort">' . Html::esc(I18n::t('admin.clients.sort_label', $loc)) . '</label>'
            . '<select id="cf-sort" name="sort">' . $sortOpts . '</select></div>'
            . '<div class="cf-actions"><button type="submit" class="btn btn-sm">' . Html::esc(I18n::t('admin.clients.apply_filter', $loc)) . '</button>'
            . ($q !== '' || $filter !== 'all' || $sort !== 'expires_asc'
                ? '<a class="btn btn-sm gray" href="' . Html::esc($base) . '">' . Html::esc(I18n::t('admin.clients.clear_filter', $loc)) . '</a>'
                : '')
            . '</div></form>';
    }

    /** @param array{q?: string, filter?: string} $query */
    public static function invoicesFilterBar(string $loc, array $query, int $shown, int $total, int $allTotal): string
    {
        $q = (string) ($query['q'] ?? '');
        $filter = (string) ($query['filter'] ?? 'all');
        $base = AdminPath::url('/invoices');
        $filters = [
            'all' => I18n::t('admin.invoices.filter.all', $loc),
            'paid' => I18n::t('admin.invoices.filter.paid', $loc),
            'unpaid' => I18n::t('admin.invoices.filter.unpaid', $loc),
        ];
        $filterOpts = '';
        foreach ($filters as $id => $label) {
            $sel = $filter === $id ? ' selected' : '';
            $filterOpts .= '<option value="' . Html::esc($id) . '"' . $sel . '>' . Html::esc($label) . '</option>';
        }
        $count = ($q !== '' || $filter !== 'all')
            ? I18n::t('admin.invoices.filter_count', $loc, ['shown' => $shown, 'total' => $total])
            : ($shown === $allTotal
                ? I18n::t('admin.invoices.filter_count_all', $loc, ['n' => $allTotal])
                : I18n::t('admin.invoices.filter_count', $loc, ['shown' => $shown, 'total' => $total]));
        return '<form class="client-filters" method="get" action="' . Html::esc($base) . '">'
            . '<div class="cf-count">' . Html::esc($count) . '</div>'
            . '<div class="cf-field"><label for="inv-q">' . Html::esc(I18n::t('admin.invoices.search', $loc)) . '</label>'
            . '<input id="inv-q" name="q" value="' . Html::esc($q) . '" placeholder="' . Html::esc(I18n::t('admin.invoices.search_ph', $loc)) . '"></div>'
            . '<div class="cf-field"><label for="inv-filter">' . Html::esc(I18n::t('admin.invoices.filter_label', $loc)) . '</label>'
            . '<select id="inv-filter" name="filter">' . $filterOpts . '</select></div>'
            . '<div class="cf-actions"><button type="submit" class="btn btn-sm">' . Html::esc(I18n::t('admin.invoices.apply_filter', $loc)) . '</button>'
            . ($q !== '' || $filter !== 'all'
                ? '<a class="btn btn-sm gray" href="' . Html::esc($base) . '">' . Html::esc(I18n::t('admin.invoices.clear_filter', $loc)) . '</a>'
                : '')
            . '</div></form>';
    }

    public static function clientStatusBadge(array $client, string $loc): string
    {
        if (($client['active'] ?? true) === false) {
            return self::badge(I18n::t('admin.badge.suspended', $loc), 'b-exp');
        }
        if (\App\Database\Database::isExpired($client)) {
            return self::badge(I18n::t('admin.badge.expired', $loc), 'b-exp');
        }
        $days = \App\Database\Database::daysLeft($client);
        if ($days !== null && $days <= 7) {
            return self::badge(I18n::t('admin.badge.days_left', $loc, ['n' => max(0, $days)]), 'b-warn');
        }
        return self::badge(I18n::t('admin.badge.plan_active', $loc), 'b-ok');
    }
}
