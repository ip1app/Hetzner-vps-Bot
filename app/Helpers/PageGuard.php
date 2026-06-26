<?php
declare(strict_types=1);

namespace App\Helpers;

final class PageGuard
{
    /** Disable context menu and common devtools / view-source shortcuts. */
    public static function hardenScript(): string
    {
        return <<<'JS'
<script>
(function(){
  document.addEventListener('contextmenu',function(e){e.preventDefault();},{capture:true});
  document.addEventListener('keydown',function(e){
    var k=(e.key||'').toUpperCase();
    if(k==='F12'){e.preventDefault();return;}
    if(e.ctrlKey&&e.shiftKey&&(k==='I'||k==='J'||k==='C'||k==='K')){e.preventDefault();return;}
    if(e.ctrlKey&&!e.shiftKey&&k==='U'){e.preventDefault();return;}
    if(e.metaKey&&e.altKey&&k==='I'){e.preventDefault();return;}
    if(e.metaKey&&e.altKey&&k==='C'){e.preventDefault();return;}
  },{capture:true});
})();
</script>
JS;
    }

    /** @deprecated Use hardenScript() */
    public static function noRightClickScript(): string
    {
        return self::hardenScript();
    }
}
