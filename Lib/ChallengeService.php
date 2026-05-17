<?php

namespace Ne0Heretic\FirewallBundle\Lib;

use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;

class ChallengeService
{
    /** @var RedisTagAwareAdapter */
    protected $cache;
    /** @var array */
    private array $config;

    public function __construct(RedisTagAwareAdapter $cache, ConfigService $configService)
    {
        $this->cache = $cache;
        $this->config = $configService->getConfig();
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
                document.cookie = "challengeToken=" + {$vSafe} + "; path=/; max-age=1800; SameSite=Strict";
                document.cookie = "challengeId={$challengeId}; path=/; max-age=1800; SameSite=Strict";

                window.location.reload();
            }
        } catch (e) {}

    })();
JS;
    }

    /**
     * Verify: Decode token, match stored secret.
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

    private function getChallengeKey(string $id, string $ip): string
    {
        // Enforces cryptographic isolation per unique client visitor address
        return 'challenge_secret_' . md5($ip . '_' . $id);
    }

}