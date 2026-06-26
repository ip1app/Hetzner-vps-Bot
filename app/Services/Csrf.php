<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Html;

final class Csrf
{
    public const FIELD = '_csrf';
    public const PUBLIC_COOKIE = 'q8csrf';
    private const TTL = 3600;

    public static function token(string $sessionTok): string
    {
        return self::signPayload(self::sessionSecret($sessionTok));
    }

    public static function verify(string $sessionTok, string $submitted): bool
    {
        return self::verifySigned($submitted, self::sessionSecret($sessionTok));
    }

    public static function field(string $sessionTok): string
    {
        $t = self::token($sessionTok);
        if ($t === '') {
            return '';
        }
        return '<input type="hidden" name="' . self::FIELD . '" value="' . Html::esc($t) . '">';
    }

    public static function loginToken(): string
    {
        return self::signPayload(self::loginSecret());
    }

    public static function verifyLoginRequest(string $cookie, string $body): bool
    {
        if ($cookie === '' || $body === '' || !hash_equals($cookie, $body)) {
            return false;
        }
        return self::verifySigned($body, self::loginSecret());
    }

    public static function publicToken(): string
    {
        return self::signPayload(self::publicSecret());
    }

    /** Reuse a valid public CSRF cookie so other tabs are not invalidated. */
    public static function publicTokenFromCookie(string $cookie): string
    {
        $cookie = trim($cookie);
        if ($cookie !== '' && self::verifySigned($cookie, self::publicSecret())) {
            return $cookie;
        }
        return self::publicToken();
    }

    public static function verifyPublicRequest(string $cookie, string $body): bool
    {
        if ($cookie === '' || $body === '' || !hash_equals($cookie, $body)) {
            return false;
        }
        return self::verifySigned($body, self::publicSecret());
    }

    public static function metaTag(string $token): string
    {
        if ($token === '') {
            return '';
        }
        return '<meta name="csrf-token" content="' . Html::esc($token) . '">';
    }

    public static function formInjectScript(): string
    {
        return <<<'JS'
<script>
(function(){
  var m=document.querySelector('meta[name="csrf-token"]');
  if(!m||!m.content)return;
  document.querySelectorAll('form[method="post"]').forEach(function(f){
    if(f.querySelector('input[name="_csrf"]'))return;
    var i=document.createElement('input');
    i.type='hidden';i.name='_csrf';i.value=m.content;
    f.prepend(i);
  });
})();
</script>
JS;
    }

    private static function signingMaterial(): string
    {
        $jwt = Config::get('JWT_SECRET');
        if ($jwt !== '') {
            return $jwt;
        }
        return Config::get('SECRET_KEY');
    }

    private static function sessionSecret(string $sessionTok): string
    {
        if ($sessionTok === '') {
            return '';
        }
        return hash('sha256', self::signingMaterial() . '|csrf|' . $sessionTok);
    }

    private static function loginSecret(): string
    {
        return hash('sha256', self::signingMaterial() . '|login-csrf');
    }

    private static function publicSecret(): string
    {
        return hash('sha256', self::signingMaterial() . '|public-csrf');
    }

    private static function signPayload(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        $exp = time() + self::TTL;
        $nonce = bin2hex(random_bytes(8));
        $payload = $exp . '.' . $nonce;
        $sig = substr(hash_hmac('sha256', $payload, $secret), 0, 32);
        return $payload . '.' . $sig;
    }

    private static function verifySigned(string $submitted, string $secret): bool
    {
        if ($secret === '' || $submitted === '') {
            return false;
        }
        $parts = explode('.', $submitted);
        if (count($parts) !== 3) {
            return false;
        }
        [$exp, $nonce, $sig] = $parts;
        if (time() > (int) $exp) {
            return false;
        }
        $payload = $exp . '.' . $nonce;
        $good = substr(hash_hmac('sha256', $payload, $secret), 0, 32);
        return hash_equals($good, $sig);
    }
}
