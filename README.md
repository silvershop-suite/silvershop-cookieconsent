# silvershop-suite/cookieconsent

A first-class, **self-hosted consent-management platform (CMP)** for SilverStripe. It turns a GDPR cookie bar into a
consent *framework*: a configurable category taxonomy, a CMS-managed cookie declaration, a server + JS **consent-state
API** other code reads (`$HasConsent('statistics')`), **true script/tag blocking** until consent, **Google Consent Mode
v2** signalling, an **immutable consent log** for accountability, re-consent/versioning, and an accessible banner +
in-banner preferences centre.

- **Works on any SilverStripe 6 site — shop or not.** There is **no `silvershop/core` dependency** and no shop logic;
  cookie consent is site-wide by nature.
- **The theme hosts and styles the banner; the module owns the content and behaviour.** The default banner template is
  framework-neutral, token-driven (`--cc-*` custom properties, `em` units) and fully overridable. It is reconciled with
  the `silvershop-suite/storefront` banner markup (`#consent` / `.consent__*` / `data-consent="…"`), so a theme can host its own
  region and this module simply drives it.
- **No IAB TCF.** This is a first-party, self-hosted CMP, not an ad-tech consent-string vendor.

## Requirements

- PHP 8.3+
- `silverstripe/framework` ^6, `silverstripe/cms` ^6, `silverstripe/admin` ^3, `silverstripe/siteconfig` ^6

Optional (feature-detected, never required):

- `tractorcow/silverstripe-fluent` — localises the banner copy and the cookie declaration.
- `symbiote/silverstripe-gridfieldextensions` — drag-and-drop ordering of the cookie declaration.
- `symbiote/silverstripe-queuedjobs` — schedule the consent-log retention prune.
- `silvershop-suite/analytics` — the statistics-consent session bridge lights up automatically.

## Install

```bash
composer require silvershop-suite/cookieconsent
```

Run `dev/build?flush=1`, then switch consent on under **CMS → Settings → Cookie consent** and manage the declaration +
log under **CMS → Cookie consent**.

## The categories (standard taxonomy)

The industry-standard four, native from the start, configured on `ConsentState`:

| Category | Required | Consent Mode v2 signals (default) |
|---|---|---|
| `necessary` | yes (always on) | `security_storage` |
| `preferences` | no | `functionality_storage`, `personalization_storage` |
| `statistics` | no | `analytics_storage` |
| `marketing` | no | `ad_storage`, `ad_user_data`, `ad_personalization` |

```yaml
SilverShop\CookieConsent\ConsentState:
  consent_version: 1          # bump to force a site-wide re-prompt
  consent_lifetime_days: 183  # ~6 months (Dutch AP); up to 13mo (FR max)
  reject_suppress_days: 365   # don't re-ask for ~a year after a reject (EDPB)
  log_consent: true
  log_retention_days: 0       # 0 = keep forever; set a horizon + run the prune task
  categories:
    statistics:
      active: true
      consent_modes: [analytics_storage]
      cookies:
        local: [_ga, _gat, _gid]
```

## Theme integration contract

The module exposes the consent state in **three** places — all speaking the same taxonomy:

| Where | How |
|---|---|
| PHP | `ConsentState::has('statistics')`, `::granted()`, `::needsPrompt()`, `::version()`, `::consentId()` |
| Template | `$HasConsent('statistics')`, `$CookieConsentNeedsPrompt`, `$CookieConsentVersion` (work in any template, header/footer shells included) |
| JS | `window.SilverShopConsent.has('statistics')`, `.grantAll()`, `.rejectAll()`, `.save([...])`, `.open()`, `.close()` + a `document` `consentchange` event |

Render the banner by including the default template in your layout (or let the zero-config JS inject it):

```html
<% include SilverShop\CookieConsent\CookieConsent %>
```

Override it by copying `templates/SilverShop/CookieConsent/Includes/CookieConsent.ss` into your theme. The JS contract is
the element `#consent` (banner) with `#consent-prefs` (the preferences form) and controls carrying
`data-consent="accept|reject|manage|save|open|close"`; a footer “Cookies” link with `data-consent="open"` reopens it.

### Script / tag blocking

Emit third-party tags inert and let the runtime activate them on consent:

```html
<script type="text/plain" data-consent-category="statistics">/* GA init */</script>
```

or from PHP:

```php
use SilverShop\CookieConsent\View\ConsentScript;
$tag = ConsentScript::src('https://www.googletagmanager.com/gtag/js?id=G-XXX', 'statistics', ['async' => true]);
```

Embeds (YouTube, Maps, …) get a click-to-load placeholder via a shortcode:

```
[consent_embed src="https://www.youtube.com/embed/xyz" category="marketing" title="Launch video"]
```

and the cookie declaration table via:

```
[cookie_declaration]                        all active categories
[cookie_declaration category="statistics"]  one category
```

### Google Consent Mode v2 / GTM

When `GTM_CODE` or `GA_CODE` is set in the environment, the module emits `gtag('consent','default',{…denied})` in the
`<head>` **before** any tag, from the category → signal map, and the JS emits `gtag('consent','update',{…})` + dataLayer
events (`cookieconsent_statistics`, …) once a category is granted. Basic mode (tags blocked until consent) is the
default; advanced mode is a config/GTM concern on your side.

## Accountability, re-consent and withdrawal

- **Immutable log.** Every accept/reject/save writes a pseudonymous `ConsentRecord` (granted categories, version,
  method, locale, UA, a random id — **never an IP or PII**) with `canEdit/canDelete/canCreate = false`. A
  `SilverShop-CookieConsent-PruneConsentRecords` BuildTask ages rows out past `log_retention_days`.
- **Re-consent.** The banner re-prompts when there is no decision, the `consent_version` has moved on, or the consent
  has lapsed (`consent_lifetime_days`) — but not within the post-reject suppression window.
- **Withdrawal.** Reopen the banner any time (`data-consent="open"`); rejecting / un-ticking a category force-expires its
  first-party cookies.

## Accessibility & anti-dark-pattern

Reject is kept as prominent as accept (the legacy “hide button” knobs are gone), nothing is pre-ticked beyond the locked
necessary category, the banner is keyboard-operable (Esc closes the preferences), focus moves into the preferences on
open and returns on close, and animation honours `prefers-reduced-motion`.

## The storefront bridge

`silvershop-suite/analytics` already gates tracking on an `analytics_consent` session flag. This module writes exactly that
flag when `statistics` consent changes (`ConsentState.session_keys`), so analytics reacts with **zero** code changes.
Map other categories to other modules’ session keys the same way.

## Roadmap

- **Geo-gating + CCPA/CPRA + Global Privacy Control (GPC)** — EU/EEA-only banner mode, a US “Do Not Sell/Share” opt-out
  model and `Sec-GPC` honouring (slice 4, deferred).

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
