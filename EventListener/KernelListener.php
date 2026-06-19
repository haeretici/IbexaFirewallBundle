<?php

namespace Haeretici\FirewallBundle\EventListener;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Haeretici\FirewallBundle\Lib\BotValidator;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\HttpFoundation\Request;
use Haeretici\FirewallBundle\Lib\ChallengeService;
use Haeretici\FirewallBundle\Lib\ConfigService;

class KernelListener
{
    /** @var BotValidator */
    protected $botValidator;
    /** @var RedisTagAwareAdapter */
    protected $cache;
    // Per request vars
    public static ?float $startTime = null;
    public static ?string $clientIp = null;
    public static float $firewallTime = 0.0;
    public static bool $checkRateLimit = false;
    public static bool $isBotAgent = false;
    public static bool $isBannedBot = false;
    public static bool $isChallenge = false;
    public static bool $isRateLimited = false;
    public static bool $exempt = false;
    /** @var ChallengeService */
    protected $challengeService;
    /** @var ConfigService */
    protected $configService;
    private string $hmacSecret;

    private const REQUEST_ATTR_PROMOTE_VERIFIED = 'haeretici_firewall.promote_verified_client';

    private const BOT_PATTERNS = [
        'google' => ['uas' => ['Googlebot/', 'Googlebot-'], 'method' => 'validateGooglebot', 'enabled_key' => 'google_enabled'],
        'twitter' => ['uas' => ['Twitterbot/'], 'method' => 'validateTwitterbot', 'enabled_key' => 'twitter_enabled'],
        'facebook' => ['uas' => ['facebookexternalhit/', 'Facebot/'], 'method' => 'validateFacebookbot', 'enabled_key' => 'facebook_enabled'],
        'bing' => ['uas' => ['bingbot/', 'BingPreview/', 'AdIdxBot/', 'MicrosoftPreview/'], 'method' => 'validateBingbot', 'enabled_key' => 'bing_enabled'],
        'linkedin' => ['uas' => ['LinkedInBot/'], 'method' => 'validateLinkedInBot', 'enabled_key' => 'linkedin_enabled'],
    ];

    public function __construct(
        RedisTagAwareAdapter $cache,
        ConfigService $configService,
        string $hmacSecret
    ) {
        $this->hmacSecret = $hmacSecret;
        self::$startTime = microtime(true);
        $this->cache = $cache;
        $this->configService = $configService;
        $this->config = $this->configService->getConfig();
        $request = Request::createFromGlobals();
        $this->path = $request->getPathInfo();
        // Exempt paths
        $exemptPaths = $this->config['exemptions']['paths'] ?? [];
        self::$exempt = false;
        foreach ($exemptPaths as $pat) {
            if (fnmatch($pat, $this->path)) {
                self::$exempt = true;
                return;
            }
        }
        // Get the request IP (this uses Symfony's built-in logic, which respects trusted_proxies if configured)
        $ip = $request->getClientIp();

        // Get the forwarded IP (manual extraction if you need the raw X-Forwarded-For value or custom handling)
        $forwardedFor = $request->headers->get('X-Forwarded-For');
        $forwardedIp = null;
        if ($forwardedFor) {
            // X-Forwarded-For can contain multiple IPs (client, proxies); take the leftmost (original client)
            $ipList = explode(',', $forwardedFor);
            $forwardedIp = trim($ipList[0]);

            // Optional: Validate IP format (basic check)
            if (!filter_var($forwardedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
                $forwardedIp = null; // Fallback to $ip if invalid
            }
        }

        // Use forwarded IP if available and valid, else fallback to direct client IP
        self::$clientIp = $forwardedIp ?: $ip;
//        self::$clientIp = '1.1.1.1';
        self::$firewallTime += microtime(true) - self::$startTime;
    }

    /**
     * @param RequestEvent $event
     * @return void
     */
    public function onKernelRequest(RequestEvent $event)
    {
        if (self::$exempt || !$event->isMasterRequest()) {
            return;
        }
        $blockStartTime = microtime(true);
        $request = $event->getRequest();
        $userAgent = $request->headers->get('User-Agent') ?? '';
        $this->challengeService = new ChallengeService($this->cache, $this->configService, $this->hmacSecret);
        $this->botValidator = new BotValidator($this->cache, $this->configService);

        // Per-browser verified state (IP + User-Agent); does not whitelist other browsers on same IP.
        if ($this->challengeService->isClientVerified(
            self::$clientIp,
            $userAgent,
            $request->cookies->get(ChallengeService::COOKIE_VERIFIED)
        )) {
            self::$checkRateLimit = true;
            self::$firewallTime += microtime(true) - $blockStartTime;
            return;
        }

        // One-time challenge submission: headers or short-lived cookies from the solver JS.
        $token = $request->headers->get('X-Challenge-Token') ?: $request->cookies->get(ChallengeService::COOKIE_TOKEN);
        $challengeId = $request->headers->get('X-Challenge-Id') ?: $request->cookies->get(ChallengeService::COOKIE_ID);
        $isTrapped = false;
        if($this->config['honeypot']['enabled']) {
            $honeypotPaths = $this->config['honeypot']['paths'] ?? [];
            foreach ($honeypotPaths as $pat) {
                if (fnmatch($pat, $this->path)) {
                    $isTrapped = true;
                    break;
                }
            }
        }
        if (!$isTrapped && $token && $challengeId && $this->challengeService->verifyChallenge($challengeId, $token, self::$clientIp)) {
            $this->challengeService->invalidateChallengeSecret($challengeId, self::$clientIp);
            $this->challengeService->markClientVerified(self::$clientIp, $userAgent);
            $request->attributes->set(self::REQUEST_ATTR_PROMOTE_VERIFIED, true);
            self::$checkRateLimit = true;
            self::$firewallTime += microtime(true) - $blockStartTime;
            return;
        }
        // Global ban check
        else if ($this->botValidator->isBanned(self::$clientIp)) {
            self::$isBotAgent = true;
            self::$isBannedBot = true;
            error_log("Globally banned bot IP: " . self::$clientIp);
            $response = new Response('Unauthorized bot access', 403);
            $response->setPrivate();
            $response->setSharedMaxAge(0);
            $event->setResponse($response);
            self::$firewallTime += microtime(true) - $blockStartTime;
            return;
        }
        // Validate known bots
        $botMatched = false;
        foreach (self::BOT_PATTERNS as $botInfo) {
            // Check if the bot type is enabled FIRST.
            // If it's disabled, don't even look for its User Agent.
            if (empty($this->config['bots'][$botInfo['enabled_key']])) {
                continue;
            }

            $uaMatch = false;
            foreach ($botInfo['uas'] as $ua) {
                if (stripos($userAgent, $ua) !== false) {
                    $uaMatch = true;
                    break;
                }
            }
            if ($uaMatch) {
                self::$isBotAgent = true;
                $botMatched = true;
                if (!$this->botValidator->{$botInfo['method']}(self::$clientIp)) {
                    self::$isBannedBot = true;
                    error_log("Fake bot detected from IP: " . self::$clientIp . " with UA: $userAgent");
                    $this->botValidator->banIpGlobally(self::$clientIp);
                    $response = new Response('Unauthorized bot access', 403);
                    $response->setPrivate();
                    $response->setSharedMaxAge(0);
                    $event->setResponse($response);
                    self::$firewallTime += microtime(true) - $blockStartTime;
                    return;
                }
                break;
            }
        }

        if (!$botMatched) {
            if($isTrapped) {
                $this->botValidator->banIpGlobally(self::$clientIp, $this->config['honeypot']['ban_duration']);
                self::$isBotAgent = true;
                self::$isBannedBot = true;
                error_log("Globally banned bot IP: " . self::$clientIp);
                $response = new Response('Unauthorized bot access', 403);
                $response->setPrivate();
                $response->setSharedMaxAge(0);
                $event->setResponse($response);
                self::$firewallTime += microtime(true) - $blockStartTime;
                return;
            }
            self::$checkRateLimit = true;
            if ($this->config['challenge']['enabled_for_non_bots']) {
                self::$isChallenge = true;
                // Trigger challenge: Generate and short-circuit
                $challenge = $this->challengeService->generateChallenge(self::$clientIp);
                $js = $this->challengeService->getObfuscatedSolverJs($challenge['broken'], $challenge['id'], $challenge['dummy_char']);

                // Generate minimal challenge HTML
                $html = $this->getMinimalChallengeHtml($js, $request); // Inject broken as data-attr if needed

                $response = new Response($html, 200, [
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                ]);
                $response->setPrivate();
                $response->setSharedMaxAge(0);
                $event->setResponse($response);
                error_log("Short-circuit challenge response for IP: " . self::$clientIp);
            }
        }
        self::$firewallTime += microtime(true) - $blockStartTime;
    }

    /**
     * Helper to generate minimal HTML with JS injection.
     * Customize: Embed your site's head/body or redirect to challenge route.
     */
    private function getMinimalChallengeHtml(string $js, Request $request): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Verifying...</title>
<meta http-equiv="refresh" content="5"> <!-- Fallback if JS fails -->
</head>
<body>
<p>One moment while we verify your browser...</p>
<script>{$js}</script>
</body>
</html>
HTML;
    }

    /**
     * @param ResponseEvent $event
     * @return void
     */
    public function onKernelResponse(ResponseEvent $event)
    {
        if(self::$exempt || !$event->isMasterRequest()) {
            return;
        }
        $blockStartTime = microtime(true);
        $request = $event->getRequest();

        if ($request->attributes->get(self::REQUEST_ATTR_PROMOTE_VERIFIED)) {
            if ($this->challengeService === null) {
                $this->challengeService = new ChallengeService($this->cache, $this->configService, $this->hmacSecret);
            }
            $userAgent = $request->headers->get('User-Agent') ?? '';
            $secure = $request->isSecure();
            $response = $event->getResponse();
            $response->headers->setCookie($this->challengeService->createVerifiedCookie(self::$clientIp, $userAgent, $secure));
            $response->headers->setCookie($this->challengeService->createClearCookie(ChallengeService::COOKIE_TOKEN, $secure));
            $response->headers->setCookie($this->challengeService->createClearCookie(ChallengeService::COOKIE_ID, $secure));
        }

        $responseTime = microtime(true) - self::$startTime - self::$firewallTime;
        // Rate limiting check: Block if exceeded
        // Counting only requests that takes at least $this->config['rate_limiting']['min_response_time']
        // Or requests that are not 2xx
        $responseCode = (int)($event->getResponse()->getStatusCode()/100);
        if ($event->isMasterRequest() && $this->config['enable_rate_limiting'] && self::$checkRateLimit && ($responseCode !== 2 || $responseTime > $this->config['rate_limiting']['min_response_time'] || self::$isChallenge) && !$this->botValidator->checkRateLimit(self::$clientIp)) {
            self::$isRateLimited = true;
            error_log("Rate limit exceeded for IP: " . self::$clientIp);
            $response = new Response('Too Many Requests', 429);
            $response->setPrivate();
            $response->setSharedMaxAge(0);
            $event->setResponse($response);
        }
        $userAgent = $request->headers->get('User-Agent') ?? '';
        $query = $request->getQueryString();
        $requestKey = 'request_time_' . microtime() . '-' . md5($this->path . $query . self::$clientIp);
        self::$firewallTime += microtime(true) - $blockStartTime;
        $data = [
            'ip' => self::$clientIp,
            'path' => $this->path,
            'query' => $query,
            'agent' => $userAgent,
            'firewallTime' => self::$firewallTime,
            'responseTime' => $responseTime,
            'isBotAgent' => self::$isBotAgent,
            'isBannedBot' => self::$isBannedBot,
            'isChallenge' => self::$isChallenge,
            'isRateLimited'=> self::$isRateLimited,
        ];
        $requestItem = $this->cache->getItem($requestKey);
        $requestItem->set(json_encode($data));
        // We temporary cache for 2 minutes
        // There will be a cron to store in the db every minute
        $requestItem->expiresAfter(120);
        $this->cache->save($requestItem);
    }
}