<?php

declare(strict_types=1);

namespace SilverShop\CookieConsent\Extension;

use SilverShop\CookieConsent\ConsentState;
use SilverShop\CookieConsent\Control\CookiePolicyPageController;
use SilverShop\CookieConsent\Model\CookieCategory;
use SilverShop\CookieConsent\Model\CookiePolicyPage;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Security;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\Requirements;

/**
 * Applies to every ContentController: requires the client assets, emits the
 * Google Consent Mode v2 default-denied signal before any tag, injects the
 * client consent config, exposes the banner/preferences template data, and
 * handles the server-side consent actions (the no-JS fallback + the XHR target).
 *
 * @extends Extension<ContentController>
 */
class ContentControllerExtension extends Extension
{
    private static $allowed_actions = [
        'acceptAllCookies',
        'acceptNecessaryCookies',
        'saveCookieConsent',
    ];

    public function onAfterInit(): void
    {
        if (!$this->consentActive()) {
            return;
        }

        // Consent Mode v2: default every referenced signal to 'denied' before any
        // tag. Env-gated (only when a tag manager is actually configured).
        if (Environment::getEnv('GTM_CODE') || Environment::getEnv('GA_CODE')) {
            $defaults = [];
            foreach (ConsentState::allConsentModeSignals() as $signal) {
                $defaults[$signal] = 'denied';
            }
            if (!empty($defaults)) {
                Requirements::insertHeadTags(
                    '<script>'
                    . 'window.dataLayer = window.dataLayer || [];'
                    . 'function gtag(){dataLayer.push(arguments);}'
                    . "gtag('consent', 'default', " . json_encode($defaults) . ');'
                    . '</script>',
                    'SilverShopConsentDefault'
                );
            }
        }

        // Hand the client its config so the JS drives itself from config/CMS.
        Requirements::insertHeadTags(
            '<script>window.SilverShopConsentConfig = ' . json_encode($this->getClientConfig()) . ';</script>',
            'SilverShopConsentConfig'
        );

        $module = ModuleLoader::getModule('silvershop-suite/cookieconsent');
        if (!$module) {
            return;
        }
        if (Config::inst()->get(ConsentState::class, 'include_css')) {
            Requirements::css($module->getResource('client/dist/styles/cookieconsent.css')->getRelativePath());
        }
        if (Config::inst()->get(ConsentState::class, 'include_javascript')) {
            Requirements::javascript($module->getResource('client/dist/js/cookieconsent.js')->getRelativePath());
        }
    }

    /**
     * The config object injected as window.SilverShopConsentConfig.
     *
     * @return array
     */
    public function getClientConfig(): array
    {
        $config = ConsentState::clientConfig([
            'acceptAll' => $this->getAcceptAllCookiesLink(),
            'acceptNecessary' => $this->getAcceptNecessaryCookiesLink(),
            'save' => $this->getSaveCookieConsentLink(),
        ]);

        // Display data for the zero-config JS-injected banner (used only when the
        // theme does not host its own #consent markup).
        $siteConfig = SiteConfig::current_site_config();
        $categories = [];
        foreach ($this->CookieConsentCategories() as $category) {
            $categories[] = [
                'code' => $category->Code,
                'title' => $category->Title,
                'desc' => trim(strip_tags((string) $category->Content)),
                'required' => $category->isRequired(),
                'granted' => $category->isGranted(),
            ];
        }
        $config['autoInject'] = (bool) Config::inst()->get(ConsentState::class, 'auto_inject');
        $config['banner'] = [
            'title' => (string) $siteConfig->CookieConsentTitle
                ?: _t(SiteConfigExtension::class . '.DefaultTitle', 'Cookies, briefly'),
            'content' => (string) $siteConfig->CookieConsentContent
                ?: _t(
                    SiteConfigExtension::class . '.DefaultContent',
                    '<p>Necessary cookies run the site. With your consent we also measure what works.</p>'
                ),
            'labels' => [
                'accept' => $this->label('AcceptAllCookiesTitle', 'AcceptAllCookies', 'Accept all'),
                'reject' => $this->label('AcceptOnlyNecessaryCookiesTitle', 'RejectAllCookies', 'Reject non-essential'),
                'manage' => $this->label('EditCookieSettingsTitle', 'ManageCookies', 'Manage preferences'),
                'save' => _t(ConsentState::class . '.SavePreferences', 'Save preferences'),
                'close' => _t(ConsentState::class . '.Close', 'Close'),
            ],
            'categories' => $categories,
        ];

        return $config;
    }

    /**
     * A SiteConfig-overridable, i18n-defaulted label.
     */
    protected function label(string $field, string $key, string $default): string
    {
        $siteConfig = SiteConfig::current_site_config();
        $value = (string) $siteConfig->$field;
        return $value !== '' ? $value : _t(ConsentState::class . ".{$key}", $default);
    }

    /**
     * Whether consent management is switched on.
     */
    protected function consentActive(): bool
    {
        return (bool) SiteConfig::current_site_config()->CookieConsentActive;
    }

    /**
     * Should the banner be prompted now? Not on Security or the Cookie policy page,
     * management active, and the versioned state says a (re-)decision is due.
     */
    public function PromptCookieConsent(): bool
    {
        if (!$this->consentActive()) {
            return false;
        }
        $owner = $this->getOwner();
        if ($owner instanceof Security || $owner instanceof CookiePolicyPageController) {
            return false;
        }
        $prompt = ConsentState::needsPrompt();
        $owner->extend('updatePromptCookieConsent', $prompt);
        return $prompt;
    }

    /**
     * The active categories, for the banner / preferences template.
     *
     * @return DataList&iterable<CookieCategory>
     */
    public function CookieConsentCategories(): DataList
    {
        return CookieCategory::get()->filter(['Active' => 1]);
    }

    /**
     * The cookie policy page instance (may be null), for the no-JS fallback link.
     */
    public function getCookiePolicyPage(): ?CookiePolicyPage
    {
        return CookiePolicyPage::instance();
    }

    /* ----------------------------------------------------------- actions */

    public function acceptAllCookies()
    {
        ConsentState::grantAll($this->consentSource());
        ConsentState::log($this->consentSource());
        return $this->consentResponse();
    }

    public function acceptNecessaryCookies()
    {
        ConsentState::rejectAll($this->consentSource());
        ConsentState::log($this->consentSource());
        return $this->consentResponse();
    }

    /**
     * Save a custom set of categories from the preferences centre. Accepts either
     * a `categories[]` form field or a comma-separated `categories` query var
     * (category keys are not personal data).
     */
    public function saveCookieConsent()
    {
        $request = $this->getOwner()->getRequest();
        $raw = $request->postVar('categories') ?? $request->getVar('categories');
        if (is_array($raw)) {
            $requested = $raw;
        } else {
            $requested = array_filter(array_map('trim', explode(',', (string) $raw)));
        }

        $source = Director::is_ajax() ? 'xhr' : 'preferences';
        ConsentState::save($requested, $source);
        ConsentState::log($source);
        return $this->consentResponse();
    }

    public function getAcceptAllCookiesLink(): string
    {
        return Controller::join_links($this->getOwner()->Link(), 'acceptAllCookies');
    }

    public function getAcceptNecessaryCookiesLink(): string
    {
        return Controller::join_links($this->getOwner()->Link(), 'acceptNecessaryCookies');
    }

    public function getSaveCookieConsentLink(): string
    {
        return Controller::join_links($this->getOwner()->Link(), 'saveCookieConsent');
    }

    /**
     * Where did this decision come from, for the log.
     */
    protected function consentSource(): string
    {
        return Director::is_ajax() ? 'xhr' : 'banner';
    }

    /**
     * Shared response for the consent actions: "ok" for ajax, otherwise a
     * cache-busted redirect back.
     *
     * @return \SilverStripe\Control\HTTPResponse|string
     */
    protected function consentResponse()
    {
        if (Director::is_ajax()) {
            return 'ok';
        }

        $owner = $this->getOwner();
        $url = $owner->getBackURL()
            ?: $owner->getReturnReferer()
            ?: Director::baseURL();

        $cachebust = uniqid();
        $separator = parse_url($url, PHP_URL_QUERY) ? '&' : '?';
        return $owner->redirect(Director::absoluteURL($url . $separator . 'consent=' . $cachebust));
    }
}
