<?php

namespace Haeretici\FirewallBundle\Lib;

use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\HttpFoundation\Cookie;

class ChallengeService
{
    public const COOKIE_VERIFIED = 'challengeVerified';
    public const COOKIE_TOKEN = 'challengeToken';
    public const COOKIE_ID = 'challengeId';

    /** @var RedisTagAwareAdapter */
    protected $cache;
    /** @var array */
    private array $config;
    private string $hmacSecret;

    public function __construct(RedisTagAwareAdapter $cache, ConfigService $configService, string $hmacSecret)
    {
        $this->cache = $cache;
        $this->config = $configService->getConfig();
        $this->hmacSecret = $hmacSecret;
    }

    /**
     * Generate broken Base64 challenge.
     */
    public function generateChallenge(string $ip): array
    {
        $secret = random_bytes($this->config['challenge']['secret_length']);
        $encoded = base64_encode($secret);
        $dummyChar = $this->config['challenge']['dummy_char'] ?? '!';
        $broken = $this->breakString($encoded, $dummyChar);

        $challengeId = bin2hex(random_bytes(16));
        $cacheKey = $this->getChallengeKey($challengeId, $ip);
        $item = $this->cache->getItem($cacheKey);
        $item->set([
            'secret' => $secret,
            'encoded' => $encoded
        ]);
        $item->expiresAfter($this->config['challenge']['ttl']);
        $this->cache->save($item);

        return [
            'broken' => $broken,
            'dummy_char' => $dummyChar,
            'id' => $challengeId
        ];
    }

    /**
     * Break Base64: Reverse + insert dummies (e.g., '=').
     */
    private function breakString(string $encoded, string $dummyChar): string
    {
        $reversed = strrev($encoded);
        $len = strlen($reversed);
        $dummies = (int) ($len * $this->config['challenge']['dummy_ratio']);
        $broken = $reversed;

        for ($i = 0; $i < $dummies; $i++) {
            $pos = random_int(0, strlen($broken));
            $broken = substr($broken, 0, $pos) . $dummyChar . substr($broken, $pos);
        }
        return $broken;
    }

    /**
     * Get obfuscated JS solver script with anti-debug.
     */
    public function getObfuscatedSolverJs(string $broken, string $challengeId, string $dummyChar): string
    {
        $secretLength = $this->config['challenge']['secret_length'];
        $challengeTtl = (int) $this->config['challenge']['ttl'];
        // Shuffling identifiers to defeat simple regex string extractors
        $vBroken = 'b_' . bin2hex(random_bytes(3));
        $vFixed = 'f_' . bin2hex(random_bytes(3));
        $vRaw = 'r_' . bin2hex(random_bytes(3));
        $vSafe = 's_' . bin2hex(random_bytes(3));

        return <<<JS
    (function() {
        // Tight, high-precision timing check to detect simple browser emulators
        const t0 = performance.now();
        for(let i=0; i<1000; i++) { Math.sqrt(i); }
        if (performance.now() - t0 > 5) { return; }
        // TODO: language, and webgl checks should fall back to captcha verification
        if (navigator.plugins.length < 5) { return; }
        //if (!navigator.languages || navigator.languages.length <= 1) { return; } // Language check
        if (navigator.webdriver) { return; } // New: Webdriver flag

        // Canvas/WebGL renderer check
        const canvas = document.createElement('canvas');
        const gl = canvas.getContext('webgl');
        if (gl) {
            const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
            if (debugInfo) {
                const renderer = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL);
                if (renderer && (renderer.includes('SwiftShader') || renderer.includes('Mesa') || renderer.includes('OffScreen'))) {
                    return;
                }
            }
            const bits = gl.getParameter(gl.BLUE_BITS);
            if (bits < 8) {
                return; // Low color bit depth indicates headless/virtualized buffer frame
            }
        }

        navigator.permissions.query({ name: 'notifications' }).then(function(permissionStatus) {
            if (Notification.permission === 'denied' && permissionStatus.state === 'prompt') {
                // Highly indicative of an automated or headless proxy engine
                return;
            }
        });

        const {$vBroken} = "{$broken}";
        // Safely strip dynamic dummy characters regardless of string contents
        const {$vFixed} = {$vBroken}.split("").reverse().join("").split("{$dummyChar}").join("");

        try {
            const {$vRaw} = atob({$vFixed});
            if ({$vRaw}.length === {$secretLength}) {
                const {$vSafe} = btoa({$vRaw});
                document.cookie = "challengeToken=" + {$vSafe} + "; path=/; max-age={$challengeTtl}; SameSite=Strict";
                document.cookie = "challengeId={$challengeId}; path=/; max-age={$challengeTtl}; SameSite=Strict";

                window.location.reload();
            }
        } catch (e) {}

    })();
JS;
    }

    /**
     * Verify one-time challenge submission (bound to IP + challenge id).
     */
    public function verifyChallenge(string $challengeId, string $token, string $ip): bool
    {
        $cacheKey = $this->getChallengeKey($challengeId, $ip);
        $item = $this->cache->getItem($cacheKey);
        if (!$item->isHit()) {
            return false;
        }

        $data = $item->get();
        $expectedSecret = $data['secret'];

        // Decode submitted (base64 to raw)
        $submittedSecret = base64_decode($token, true); // Strict mode
        if ($submittedSecret === false) {
            return false;
        }

        if (!hash_equals($expectedSecret, $submittedSecret)) {
            return false;
        }

        return true;
    }

    /**
     * Fast path: HMAC cookie binds expiry to IP + User-Agent (per browser, not per IP alone).
     */
    public function isClientVerified(string $ip, string $userAgent, ?string $verifiedCookie): bool
    {
        if ($verifiedCookie === null || $verifiedCookie === '') {
            return false;
        }

        $parts = explode('.', $verifiedCookie, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$expiryRaw, $signature] = $parts;
        $expiry = (int) $expiryRaw;
        if ($expiry < time()) {
            return false;
        }

        $clientKey = $this->getClientKey($ip, $userAgent);
        $expectedSignature = $this->signVerifiedPayload($expiry, $clientKey);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Promote a browser to verified after a successful one-time challenge solve.
     */
    public function markClientVerified(string $ip, string $userAgent): void
    {
        $clientKey = $this->getClientKey($ip, $userAgent);
        $item = $this->cache->getItem($this->getVerifiedClientKey($clientKey));
        $item->set((string) time());
        $item->expiresAfter((int) $this->config['challenge']['verified_ttl']);
        $this->cache->save($item);
    }

    public function createVerifiedCookie(string $ip, string $userAgent, bool $secure): Cookie
    {
        $ttl = (int) $this->config['challenge']['verified_ttl'];
        $expiry = time() + $ttl;
        $clientKey = $this->getClientKey($ip, $userAgent);
        $signature = $this->signVerifiedPayload($expiry, $clientKey);
        $value = $expiry . '.' . $signature;

        return Cookie::create(self::COOKIE_VERIFIED)
            ->withValue($value)
            ->withExpires($expiry)
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    public function createClearCookie(string $name, bool $secure): Cookie
    {
        return Cookie::create($name)
            ->withValue('')
            ->withExpires(1)
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    public function invalidateChallengeSecret(string $challengeId, string $ip): void
    {
        $this->cache->deleteItem($this->getChallengeKey($challengeId, $ip));
    }

    private function getClientKey(string $ip, string $userAgent): string
    {
        return hash('sha256', $ip . '|' . $userAgent);
    }

    private function getVerifiedClientKey(string $clientKey): string
    {
        return 'verified_client_' . $clientKey;
    }

    private function signVerifiedPayload(int $expiry, string $clientKey): string
    {
        return hash_hmac('sha256', $expiry . '.' . $clientKey, $this->hmacSecret);
    }

    private function getChallengeKey(string $id, string $ip): string
    {
        // Cryptographic isolation per visitor address for the one-time challenge exchange
        return 'challenge_secret_' . md5($ip . '_' . $id);
    }

}