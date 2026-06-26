<?php
declare(strict_types=1);

namespace App\Views;

use App\Helpers\Html;
use App\Helpers\I18n;
use App\Helpers\Url;
use App\Services\BrandTheme;

final class StoreUi
{
    /**
     * @param array<string, mixed> $st
     * @param list<array<string, mixed>> $products
     */
    public static function homePage(string $loc, array $st, array $products): string
    {
        $e = Html::esc(...);
        $cur = (string) ($st['currency'] ?? 'KWD');
        $title = trim((string) ($st['store_name'] ?? $st['store_title'] ?? ''));
        if ($title === '') {
            $title = I18n::t('store.hero.default_title', $loc);
        }
        $heroDesc = self::descForLocale($st, $loc);
        if ($heroDesc === '') {
            $heroDesc = I18n::t('store.hero.default_desc', $loc);
        }
        $logoUrl = BrandTheme::logoUrl($st);
        $logoBlock = $logoUrl !== ''
            ? '<div class="hero-logo st-anim st-anim-d1"><img src="' . $e($logoUrl) . '" alt="' . $e($title) . '"></div>'
            : '';

        $trust = '<div class="trust st-reveal" data-reveal>'
            . self::trustStat(I18n::t('store.trust.stat_uptime', $loc), I18n::t('store.trust.stat_uptime_lbl', $loc))
            . self::trustStat(I18n::t('store.trust.stat_speed', $loc), I18n::t('store.trust.stat_speed_lbl', $loc))
            . self::trustStat(I18n::t('store.trust.stat_support', $loc), I18n::t('store.trust.stat_support_lbl', $loc))
            . self::trustStat(I18n::t('store.trust.stat_nvme', $loc), I18n::t('store.trust.stat_nvme_lbl', $loc))
            . '</div>';

        return '<div class="store-home">'
            . '<section class="section hero">'
            . $logoBlock
            . '<span class="tag st-anim st-anim-d1">' . $e(I18n::t('store.hero.badge', $loc)) . '</span>'
            . '<h1 class="st-anim st-anim-d2">' . $e($title) . '</h1>'
            . '<p class="hero-desc st-anim st-anim-d3">' . $e($heroDesc) . '</p>'
            . '<div class="hero-cta st-anim st-anim-d4">'
            . '<a class="btn" href="#plans">' . $e(I18n::t('store.btn.browse', $loc)) . '</a>'
            . '<a class="btn ghost" href="' . $e(Url::to('/account')) . '">' . $e(I18n::t('store.btn.account_login', $loc)) . '</a>'
            . '</div>'
            . $trust
            . '</section>'
            . self::plansSection($loc, $products, $cur)
            . self::faqSection($st, $loc)
            . '<noscript><style>.st-reveal{opacity:1!important;transform:none!important}</style></noscript>'
            . self::motionScript()
            . '</div>';
    }

    private static function trustStat(string $value, string $label): string
    {
        return '<div><b>' . Html::esc($value) . '</b><span>' . Html::esc($label) . '</span></div>';
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private static function plansSection(string $loc, array $products, string $cur): string
    {
        $e = Html::esc(...);
        $cards = '';
        foreach ($products as $i => $p) {
            $cards .= self::serverCard($p, $loc, $cur, $i === 1 || ($i === 0 && count($products) === 1), $i);
        }
        return '<section class="section" id="plans">'
            . '<div class="shead">'
            . '<h2>' . $e(I18n::t('store.plans.pick', $loc)) . '</h2>'
            . '<p>' . $e(I18n::t('store.plans.pick_sub', $loc)) . '</p>'
            . '</div>'
            . ($cards !== ''
                ? '<div class="plans">' . $cards . '</div>'
                : '<div class="warn">' . $e(I18n::t('store.no_plans', $loc)) . '</div>')
            . '</section>';
    }

    private static function motionScript(): string
    {
        return <<<'JS'
<script>
(function(){
  var nodes=document.querySelectorAll('[data-reveal]');
  if(!nodes.length)return;
  function show(el){el.classList.add('st-in')}
  if(!('IntersectionObserver' in window)){
    for(var i=0;i<nodes.length;i++)show(nodes[i]);
    return;
  }
  var io=new IntersectionObserver(function(entries){
    for(var j=0;j<entries.length;j++){
      if(entries[j].isIntersecting){show(entries[j].target);io.unobserve(entries[j].target)}
    }
  },{root:null,rootMargin:'0px 0px -6% 0px',threshold:0.08});
  for(var k=0;k<nodes.length;k++)io.observe(nodes[k]);
})();
</script>
JS;
    }

    /** @return array{ar: string, en: string} */
    public static function descFieldsForAdmin(array $st): array
    {
        $ar = trim((string) ($st['store_desc_ar'] ?? ''));
        $en = trim((string) ($st['store_desc_en'] ?? ''));
        $legacy = trim((string) ($st['store_desc'] ?? ''));
        if ($ar === '' && $en === '' && $legacy !== '') {
            $ar = $legacy;
        }
        return ['ar' => $ar, 'en' => $en];
    }

    /** @param array<string, mixed> $st */
    public static function descForLocale(array $st, string $loc): string
    {
        $fields = self::descFieldsForAdmin($st);
        $loc = \App\Helpers\Locale::norm($loc);
        $raw = $loc === 'ar' ? $fields['ar'] : $fields['en'];
        if ($raw !== '') {
            return $raw;
        }
        return $loc === 'ar' ? $fields['en'] : $fields['ar'];
    }

    /** @return array{ar: string, en: string} */
    public static function faqFieldsForAdmin(array $st): array
    {
        $ar = trim((string) ($st['faq_ar'] ?? ''));
        $en = trim((string) ($st['faq_en'] ?? ''));
        $legacy = trim((string) ($st['faq'] ?? ''));
        if ($ar === '' && $en === '' && $legacy !== '') {
            if (($st['store_locale'] ?? 'en') === 'ar') {
                $ar = $legacy;
            } else {
                $en = $legacy;
            }
        }
        return ['ar' => $ar, 'en' => $en];
    }

    /** @param array<string, mixed> $st */
    public static function faqRawForLocale(array $st, string $loc): string
    {
        $loc = \App\Helpers\Locale::norm($loc);
        $key = $loc === 'ar' ? 'faq_ar' : 'faq_en';
        $raw = trim((string) ($st[$key] ?? ''));
        if ($raw !== '') {
            return $raw;
        }
        $legacy = trim((string) ($st['faq'] ?? ''));
        if ($legacy !== '') {
            return $legacy;
        }
        $other = $loc === 'ar' ? 'faq_en' : 'faq_ar';
        return trim((string) ($st[$other] ?? ''));
    }

    /** @param array<string, mixed> $st */
    public static function hasFaqForLocale(array $st, string $loc): bool
    {
        if (empty($st['show_faq_home'])) {
            return false;
        }
        return self::faqRawForLocale($st, $loc) !== '';
    }

    /** @return list<array{q: string, a: string}> */
    public static function parseFaq(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\n\s*\n/', $raw) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $lines = preg_split('/\r\n|\r|\n/', $block) ?: [];
            $q = trim((string) ($lines[0] ?? ''));
            if ($q === '') {
                continue;
            }
            $q = preg_replace('/^(Q:|س:|سؤال:)\s*/iu', '', $q) ?? $q;
            $a = trim(implode("\n", array_slice($lines, 1)));
            $a = preg_replace('/^(A:|ج:|جواب:)\s*/iu', '', $a) ?? $a;
            if ($a === '') {
                continue;
            }
            $out[] = ['q' => $q, 'a' => $a];
        }
        return $out;
    }

    /** @param array<string, mixed> $p */
    private static function serverCard(array $p, string $loc, string $cur, bool $popular, int $index): string
    {
        $e = Html::esc(...);
        $stock = count(\App\Database\Database::availableStockForProduct((string) $p['id']));
        $months = (int) ($p['months'] ?? 1);
        $period = $months === 1 ? I18n::t('store.per_month', $loc) : I18n::t('store.per_months', $loc, ['n' => $months]);
        $inStock = $stock > 0;
        $stockClass = $inStock ? 'ok' : 'warn';
        $stockLine = $inStock
            ? I18n::t('store.card.stock_ok', $loc, ['n' => $stock])
            : I18n::t('store.card.stock_empty', $loc);
        $btnBlock = $inStock
            ? '<a class="btn block" href="' . $e(Url::to('/checkout/' . $p['id'])) . '">' . $e(I18n::t('store.btn.subscribe', $loc)) . '</a>'
            : '<span class="btn block ghost" aria-disabled="true">' . $e(I18n::t('store.btn.out_of_stock', $loc)) . '</span>'
                . '<p class="sub" style="margin-top:10px;text-align:center">'
                . I18n::t('store.card.renew_hint', $loc, ['url' => $e(Url::to('/account'))]) . '</p>';

        $specsBlock = self::productSpecsHtml((string) ($p['desc'] ?? ''), $loc);

        $ribbon = $popular
            ? '<span class="ribbon">' . $e(I18n::t('store.ribbon', $loc)) . '</span>'
            : '';
        $delay = min($index + 1, 6);
        $cls = 'plan st-reveal' . ($popular ? ' feat' : '');

        return '<article class="' . $cls . '" data-reveal style="--st-i:' . $delay . '">'
            . $ribbon
            . '<div class="plan-name">' . $e((string) $p['name']) . '</div>'
            . '<span class="stockpill ' . $e($stockClass) . '">' . $e($stockLine) . '</span>'
            . '<div class="price"><b>' . $e((string) $p['price']) . '</b><span>' . $e($cur) . ' / ' . $e($period) . '</span></div>'
            . $specsBlock
            . $btnBlock
            . '</article>';
    }

    public static function productSpecsHtml(string $desc, string $loc, bool $withTitle = true, string $wrapClass = ''): string
    {
        $e = Html::esc(...);
        $lines = self::descToList($desc);
        if ($lines === []) {
            $lines = [I18n::t('store.plan.default_feat', $loc)];
        }
        $items = '';
        foreach ($lines as $line) {
            $ico = self::specIconForLine($line);
            $items .= '<li><span class="spec-ico" aria-hidden="true">' . $ico . '</span><span class="spec-txt">' . $e($line) . '</span></li>';
        }
        $head = $withTitle
            ? '<div class="specs-head">' . $e(I18n::t('store.plan.specs_title', $loc)) . '</div>'
            : '';
        $cls = 'specs-block' . ($wrapClass !== '' ? ' ' . $e($wrapClass) : '');
        return '<div class="' . $cls . '">' . $head . '<ul class="specs">' . $items . '</ul></div>';
    }

    private static function specIconForLine(string $line): string
    {
        if (preg_match('/\b(cpu|core|cores|vcpu|v\s*cpu|نواة|نوى|معالج)\b/ui', $line)) {
            return '🖥️';
        }
        if (preg_match('/\b(ram|memory|ذاكرة|رام)\b/ui', $line) || preg_match('/\d+\s*(gb|g)\s*ram/ui', $line)) {
            return '💾';
        }
        if (preg_match('/\b(ssd|nvme|disk|storage|قرص|تخزين)\b/ui', $line) || preg_match('/\d+\s*(gb|g|tb|t)\b/ui', $line)) {
            return '💾';
        }
        if (preg_match('/\b(ip|ipv4|ipv6|private|عنوان)\b/ui', $line)) {
            return '🌐';
        }
        if (preg_match('/\b(traffic|bandwidth|ترافيك|نقل)\b/ui', $line)) {
            return '🌐';
        }
        return '✓';
    }

    /** @return list<string> */
    public static function descToList(string $desc): array
    {
        $desc = trim($desc);
        if ($desc === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n|•|·/', $desc) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim(ltrim(trim($line), '-–—'));
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out !== [] ? $out : [$desc];
    }

    /** @param array<string, mixed> $st */
    private static function faqSection(array $st, string $loc): string
    {
        if (empty($st['show_faq_home'])) {
            return '';
        }
        $raw = self::faqRawForLocale($st, $loc);
        $items = self::parseFaq($raw);
        if ($items === []) {
            return '';
        }
        $html = '';
        $e = Html::esc(...);
        foreach ($items as $item) {
            $html .= '<details class="qa">'
                . '<summary><span class="ic" aria-hidden="true">+</span>' . $e($item['q']) . '</summary>'
                . '<div class="qa-body">' . nl2br(Html::esc($item['a'])) . '</div>'
                . '</details>';
        }
        return '<section class="section faq-wrap" id="faq">'
            . '<div class="shead">'
            . '<h2>' . Html::esc(I18n::t('faq.title', $loc)) . '</h2>'
            . '<p>' . Html::esc(I18n::t('store.faq.sub', $loc)) . '</p>'
            . '</div>'
            . '<div class="faq">' . $html . '</div>'
            . '</section>';
    }
}
