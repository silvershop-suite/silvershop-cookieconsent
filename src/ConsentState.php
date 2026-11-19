<?php

declare(strict_types=1);

namespace SilverShop\CookieConsent;

use SilverShop\CookieConsent\Model\ConsentRecord;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Cookie;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use Throwable;

/**
 * ConsentState — the single source of truth for consent in this module.
 *
 * It speaks the standard taxonomy (necessary / preferences / statistics /
 * marketing) natively: the granted categories, the policy version, a timestamp,
 * the decision method and a pseudonymous id all live in ONE server-readable,
 * JS-readable cookie ({@see self::$cookie_name}), shaped:
 *
 *   {"v":1,"t":1697040000,"m":"all","i":"<32hex>","c":["necessary","statistics"]}
 *
 * Because the cookie is server-readable, PHP can gate SSR output and emit the
 * Consent Mode v2 default before any tag, and because it is JS-readable the
 * client runtime enforces consent (script/tag blocking) without a round-trip.
 *
 * Other code reads this through:
 *   - PHP     ConsentState::has('statistics')
 *   - template $HasConsent('statistics')         {@see View\ConsentTemplateProvider}
 *   - JS      window.SilverShopConsent.has('statistics')
 *
 * All decision logic (needsPrompt, deriveMethod, decode) is extracted into pure
 * static methods so it is unit-testable without touching cookies.
 */
class ConsentState
{
    use Extensible;
    use Injectable;
    use Configurable;

    public const NECESSARY = 'necessary';
    public const PREFERENCES = 'preferences';
    public const STATISTICS = 'statistics';
    public const MARKETING = 'marketing';

    public const METHOD_ALL = 'all';
    public const METHOD_REJECT = 'reject';
    public const METHOD_CUSTOM = 'custom';

    /**
     * Name of the single consent cookie.
     *
     * @var string
     */
    private static $cookie_name = 'SilverShopConsent';

    /**
     * Optional explicit cookie domain (null = current host).
     *
     * @var string|null
     */
    private static $cookie_domain = null;

    /**
     * SameSite attribute for the consent cookie.
     *
     * @var string
     */
    private static $same_site = Cookie::SAMESITE_LAX;

    /**
     * The current consent / declaration version. Bump this (config or the CMS
     * action) whenever the categories or cookie declaration materially change, to
     * force a site-wide re-prompt.
     *
     * @var int
     */
    private static $consent_version = 1;

    /**
     * How long a given consent stays valid before we re-prompt, in days. Defaults
     * to ~6 months (strictest common EU reading, Dutch AP). Configurable up to the
     * French 13-month maximum.
     *
     * @var int
     */
    private static $consent_lifetime_days = 183;

    /**
     * After an explicit reject we suppress re-prompting for this many days to
     * avoid cookie-fatigue (EDPB). Defaults to ~12 months.
     *
     * @var int
     */
    private static $reject_suppress_days = 365;

    /**
     * Whether to write immutable ConsentRecord proof rows on each decision.
     *
     * @var bool
     */
    private static $log_consent = true;

    /**
     * Retention horizon for the consent log, in days. 0 (default) keeps records
     * indefinitely; set a positive value and run the prune task to age them out.
     *
     * @var int
     */
    private static $log_retention_days = 0;

    /**
     * Whether the module injects its default stylesheet.
     *
     * @var bool
     */
    private static $include_css = true;

    /**
     * Whether the module injects its client runtime.
     *
     * @var bool
     */
    private static $include_javascript = true;

    /**
     * Whether the client runtime may inject a default banner when the theme does
     * not host its own #consent markup. Zero-config convenience; set false if the
     * theme always provides the banner.
     *
     * @var bool
     */
    private static $auto_inject = true;

    /**
     * Whether dev/build creates the default Cookie Policy page. Off by default —
     * most sites already have one.
     *
     * @var bool
     */
    private static $create_default_pages = false;

    /**
     * Soft bridge: map a category to a session key sibling modules gate on.
     * Defaults to the silvershop-suite/analytics contract (statistics ->
     * analytics_consent). Harmless when nothing reads the key.
     *
     * @var array<string,string>
     */
    private static $session_keys = [
        self::STATISTICS => 'analytics_consent',
    ];

    /**
     * The category taxonomy. Keyed by category; each value holds `active`,
     * `required`, the Consent Mode v2 `consent_modes` signal list, and the
     * declared `cookies`. Seeded in config.yml.
     *
     * @var array<string,array>
     */
    private static $categories = [];

    /* ------------------------------------------------------------ taxonomy */

    /**
     * The raw category taxonomy config.
     *
     * @return array<string,array>
     */
    public static function categories(): array
    {
        return static::config()->get('categories') ?: [];
    }

    /**
     * Every configured category key, in declaration order.
     *
     * @return array<int,string>
     */
    public static function configuredCategories(): array
    {
        return array_keys(static::categories());
    }

    /**
     * Active category keys.
     *
     * @return array<int,string>
     */
    public static function activeCategories(): array
    {
        $keys = [];
        foreach (static::categories() as $key => $settings) {
            if (!empty($settings['active'])) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Required (always-on) category keys.
     *
     * @return array<int,string>
     */
    public static function requiredCategories(): array
    {
        $keys = [];
        foreach (static::categories() as $key => $settings) {
            if (!empty($settings['required'])) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Is this category required (always-on)?
     */
    public static function isRequired(string $category): bool
    {
        return in_array($category, static::requiredCategories(), true);
    }

    /**
     * Is this category configured and active?
     */
    public static function isActive(string $category): bool
    {
        return in_array($category, static::activeCategories(), true);
    }

    /* ----------------------------------------------------------- cookie read */

    /**
     * The decoded consent cookie, or null when absent / invalid.
     *
     * @return array|null
     */
    public static function current(): ?array
    {
        return static::decode(Cookie::get(static::config()->get('cookie_name')));
    }

    /**
     * Decode a raw consent cookie payload. Pure / testable.
     *
     * @return array|null
     */
    public static function decode(?string $raw): ?array
    {
        if (!$raw) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Encode a payload for the cookie. Pure / testable.
     */
    public static function encode(array $payload): string
    {
        return (string) json_encode($payload);
    }

    /**
     * Has the visitor made any explicit decision yet?
     */
    public static function hasDecision(): bool
    {
        return static::current() !== null;
    }

    /**
     * The granted category keys, union the required ones, intersected with the
     * configured taxonomy. Before any decision, only the required categories are
     * granted.
     *
     * @return array<int,string>
     */
    public static function grantedCategories(): array
    {
        $required = static::requiredCategories();
        $current = static::current();
        if ($current === null || !isset($current['c']) || !is_array($current['c'])) {
            return array_values($required);
        }
        $stored = array_values(array_intersect(static::configuredCategories(), $current['c']));
        return array_values(array_unique(array_merge($required, $stored)));
    }

    /**
     * Alias of {@see self::grantedCategories()} — the public "what is granted" API.
     *
     * @return array<int,string>
     */
    public static function granted(): array
    {
        return static::grantedCategories();
    }

    /**
     * Does the visitor consent to this category? Required categories are always
     * granted; unknown categories return false (non-throwing).
     */
    public static function has(string $category = self::NECESSARY): bool
    {
        if (static::isRequired($category)) {
            return true;
        }
        return in_array($category, static::grantedCategories(), true);
    }

    /**
     * The version the visitor last consented under (0 when unknown).
     */
    public static function version(): int
    {
        $current = static::current();
        return (int) ($current['v'] ?? 0);
    }

    /**
     * The pseudonymous consent id from the cookie (no PII), or null.
     */
    public static function consentId(): ?string
    {
        $current = static::current();
        return $current['i'] ?? null;
    }

    /**
     * The decision method last stored (all / reject / custom), or null.
     */
    public static function method(): ?string
    {
        $current = static::current();
        return $current['m'] ?? null;
    }

    /* ------------------------------------------------------------- re-consent */

    /**
     * Should the banner be (re-)shown? True when there is no decision, the stored
     * version is behind the current one, or the consent has lapsed — except within
     * the post-reject suppression window.
     */
    public static function needsPrompt(): bool
    {
        return static::evaluateNeedsPrompt(
            static::current(),
            (int) static::config()->get('consent_version'),
            (int) static::config()->get('consent_lifetime_days'),
            (int) static::config()->get('reject_suppress_days'),
            static::hasDecision(),
            time()
        );
    }

    /**
     * Pure decision logic for needsPrompt(), extracted so it is unit-testable
     * without touching cookies. All inputs are explicit.
     *
     * @param array|null $state decoded cookie payload {v,t,m,i,c} or null
     */
    public static function evaluateNeedsPrompt(
        ?array $state,
        int $currentVersion,
        int $lifetimeDays,
        int $rejectSuppressDays,
        bool $hasDecision,
        int $now
    ): bool {
        // No decision at all -> prompt.
        if (!$hasDecision) {
            return true;
        }

        // A decision exists but the payload is unreadable / has no timestamp:
        // re-prompt so we can record a clean versioned decision.
        if (!is_array($state) || !isset($state['t'])) {
            return true;
        }

        // Policy / declaration version moved on -> re-consent.
        if ((int) ($state['v'] ?? 0) < $currentVersion) {
            return true;
        }

        $age = $now - (int) $state['t'];
        $method = $state['m'] ?? self::METHOD_CUSTOM;

        // After a reject, suppress for the longer fatigue window.
        if ($method === self::METHOD_REJECT) {
            return $age > ($rejectSuppressDays * 86400);
        }

        return $age > ($lifetimeDays * 86400);
    }

    /**
     * Derive the decision method from the granted set. Pure / testable.
     *
     * @param array<int,string> $granted  granted category keys
     * @param array<int,string> $all      all active category keys
     * @param array<int,string> $required required category keys
     */
    public static function deriveMethod(array $granted, array $all, array $required): string
    {
        $norm = static function (array $a): array {
            $a = array_values(array_unique(array_filter($a)));
            sort($a);
            return $a;
        };
        $granted = $norm($granted);
        $all = $norm($all);
        $required = $norm($required);

        if ($granted === $all) {
            return self::METHOD_ALL;
        }
        if ($granted === $required) {
            return self::METHOD_REJECT;
        }
        return self::METHOD_CUSTOM;
    }

    /* ------------------------------------------------------------ cookie write */

    /**
     * Persist a set of granted categories. Required categories are always folded
     * in. Writes the cookie, clears now-withdrawn local cookies and syncs the soft
     * session bridges. Returns the payload written. Never logs (the controller /
     * form logs exactly one ConsentRecord per user action).
     *
     * @param array<int,string> $categories requested category keys
     * @return array the payload written
     */
    public static function save(array $categories, string $source = 'banner', ?string $method = null): array
    {
        $active = static::activeCategories();
        $required = static::requiredCategories();

        $granted = array_values(array_unique(array_merge(
            $required,
            array_intersect($active, $categories)
        )));

        $existing = static::current();
        $payload = [
            'v' => (int) static::config()->get('consent_version'),
            't' => time(),
            'm' => $method ?: static::deriveMethod($granted, $active, $required),
            'i' => $existing['i'] ?? static::generateConsentId(),
            'c' => $granted,
        ];

        static::writeCookie($payload);

        // Best-effort side effects — never break a consent write.
        try {
            static::clearWithdrawnCookies($granted);
            static::syncSession($granted);
        } catch (Throwable $e) {
            // noop
        }

        return $payload;
    }

    /**
     * Grant every active category.
     *
     * @return array
     */
    public static function grantAll(string $source = 'banner'): array
    {
        return static::save(static::activeCategories(), $source, self::METHOD_ALL);
    }

    /**
     * Grant only the required categories (reject non-essential).
     *
     * @return array
     */
    public static function rejectAll(string $source = 'banner'): array
    {
        return static::save(static::requiredCategories(), $source, self::METHOD_REJECT);
    }

    /**
     * Whether the consent cookie must carry the Secure attribute.
     *
     * Keyed to the ACTUAL request protocol, not the environment mode: Secure is
     * set only when the request is served over HTTPS. The previous behaviour
     * (`!Director::isDev()`) marked the cookie Secure on every non-dev
     * environment, so a site served over plain HTTP — a common staging / test /
     * internal setup — had the browser silently drop the cookie, and consent
     * never persisted (the banner kept reappearing and $HasConsent never
     * reflected the choice). Keyed to the real protocol it is correct on both
     * http (dev / staging) and https (production), and pairs with the http-safe
     * SameSite=Lax default (never SameSite=None, which would force Secure).
     */
    public static function cookieIsSecure(): bool
    {
        return Director::is_https();
    }

    /**
     * Write the consent cookie. Secure only over HTTPS (see {@see self::cookieIsSecure()}),
     * JS-readable (httpOnly=false) and 365-day so a returning visitor keeps their
     * decision until the lifetime / version logic re-prompts.
     */
    protected static function writeCookie(array $payload): void
    {
        $domain = static::config()->get('cookie_domain') ?: null;
        $secure = static::cookieIsSecure();
        $sameSite = static::config()->get('same_site') ?: Cookie::SAMESITE_LAX;

        Cookie::set(
            static::config()->get('cookie_name'),
            static::encode($payload),
            365,
            '/',
            $domain,
            $secure,
            false,
            $sameSite
        );
    }

    /**
     * Best-effort: force-expire the local cookies declared under any category that
     * is no longer granted, so a withdrawal actually clears first-party cookies.
     *
     * @param array<int,string> $granted granted category keys
     */
    protected static function clearWithdrawnCookies(array $granted): void
    {
        if (Director::is_cli()) {
            return;
        }
        $host = Director::host();
        foreach (static::categories() as $key => $settings) {
            if (in_array($key, $granted, true)) {
                continue;
            }
            $cookies = $settings['cookies']['local'] ?? [];
            foreach ($cookies as $name) {
                // Never clear our own state cookie.
                if ($name === static::config()->get('cookie_name')) {
                    continue;
                }
                Cookie::force_expiry($name, '/', $host);
            }
        }
    }

    /**
     * Soft analytics / marketing bridge: mirror each mapped category's granted
     * state onto its session key, so sibling modules that gate on the session
     * (e.g. silvershop-suite/analytics' `analytics_consent`) react with zero coupling.
     *
     * @param array<int,string> $granted granted category keys
     */
    public static function syncSession(array $granted): void
    {
        if (Director::is_cli()) {
            return;
        }
        $map = static::config()->get('session_keys') ?: [];
        if (empty($map)) {
            return;
        }
        $controller = Controller::curr();
        if (!$controller) {
            return;
        }
        $session = $controller->getRequest()->getSession();
        foreach ($map as $category => $sessionKey) {
            $session->set($sessionKey, in_array($category, $granted, true));
        }
    }

    /* ----------------------------------------------------------------- logging */

    /**
     * Write an immutable ConsentRecord for the current state. Guarded +
     * best-effort; called once per user action by the controller / form.
     *
     * @return ConsentRecord|null
     */
    public static function log(string $source = 'banner'): ?ConsentRecord
    {
        if (!static::config()->get('log_consent') || Director::is_cli()) {
            return null;
        }
        try {
            return ConsentRecord::record($source);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * A random, non-identifying consent id (no IP, no PII).
     */
    public static function generateConsentId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            return substr(md5(uniqid('', true)), 0, 32);
        }
    }

    /* ------------------------------------------------------- consent mode v2 */

    /**
     * The category -> Consent Mode v2 signal map, derived from the taxonomy.
     *
     * @return array<string,array<int,string>> e.g. ['statistics' => ['analytics_storage'], ...]
     */
    public static function consentModeMap(): array
    {
        $map = [];
        foreach (static::categories() as $key => $settings) {
            $signals = $settings['consent_modes'] ?? [];
            if (!empty($signals)) {
                $map[$key] = array_values($signals);
            }
        }
        return $map;
    }

    /**
     * Every Consent Mode signal referenced by any category (the set that must be
     * defaulted to 'denied' before any tag fires).
     *
     * @return array<int,string>
     */
    public static function allConsentModeSignals(): array
    {
        $signals = [];
        foreach (static::consentModeMap() as $categorySignals) {
            foreach ($categorySignals as $signal) {
                $signals[$signal] = true;
            }
        }
        return array_keys($signals);
    }

    /* ------------------------------------------------------------ client config */

    /**
     * The configuration object handed to the client runtime (window.SilverShopConsentConfig).
     *
     * @param array<string,string> $links action links keyed acceptAll/acceptNecessary/save
     * @return array
     */
    public static function clientConfig(array $links = []): array
    {
        return [
            'cookieName' => static::config()->get('cookie_name'),
            'categories' => static::activeCategories(),
            'required' => array_values(static::requiredCategories()),
            'consentModes' => static::consentModeMap(),
            'version' => (int) static::config()->get('consent_version'),
            'needsPrompt' => static::needsPrompt(),
            'acceptAllLink' => $links['acceptAll'] ?? '',
            'acceptNecessaryLink' => $links['acceptNecessary'] ?? '',
            'saveLink' => $links['save'] ?? '',
        ];
    }
}
