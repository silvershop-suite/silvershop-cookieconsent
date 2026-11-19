<%-- SilverShop Cookie Consent — default banner + in-banner preferences centre.

     Framework-neutral and token-driven. The markup, ids and data-consent hooks
     are reconciled with the silvershop-suite/storefront banner so a theme can host its
     own #consent region and this module just drives it; override this template in
     your theme (templates/SilverShop/CookieConsent/Includes/CookieConsent.ss) to
     restyle.

     JS contract: #consent / #consent-prefs and data-consent="accept|reject|manage|save|open|close".
--%>
<% if $SiteConfig.CookieConsentActive %>
<div class="consent" id="consent" role="region" aria-labelledby="consent-title"<% if not $PromptCookieConsent %> hidden<% end_if %>>
    <div class="consent__inner">
        <div class="consent__copy">
            <h2 class="consent__title" id="consent-title"><% if $SiteConfig.CookieConsentTitle %>$SiteConfig.CookieConsentTitle<% else %><%t SilverShop\CookieConsent\Extension\SiteConfigExtension.DefaultTitle 'Cookies, briefly' %><% end_if %></h2>
            <div class="consent__text">$SiteConfig.CookieConsentContent</div>
        </div>

        <form class="consent__prefs" id="consent-prefs" method="post" action="$SaveCookieConsentLink" hidden>
            <% loop $CookieConsentCategories %>
                <div class="consent__pref">
                    <div>
                        <strong>$Title</strong>
                        <span>$Content.Plain</span>
                    </div>
                    <label class="switch">
                        <input class="switch__input" type="checkbox" name="categories[]" value="$Code" data-consent-category="$Code"<% if $isRequired %> checked disabled<% else_if $isGranted %> checked<% end_if %> />
                        <span class="visually-hidden">$Title<% if $isRequired %> (<%t SilverShop\CookieConsent\ConsentState.AlwaysOn 'always on' %>)<% end_if %></span>
                    </label>
                </div>
            <% end_loop %>
        </form>

        <div class="consent__actions">
            <button class="consent__btn consent__btn--ghost" type="button" data-consent="manage" aria-expanded="false" aria-controls="consent-prefs">
                <% if $SiteConfig.EditCookieSettingsTitle %>$SiteConfig.EditCookieSettingsTitle<% else %><%t SilverShop\CookieConsent\ConsentState.ManageCookies 'Manage preferences' %><% end_if %>
            </button>
            <a class="consent__btn consent__btn--secondary" href="$AcceptNecessaryCookiesLink" rel="nofollow" data-consent="reject">
                <% if $SiteConfig.AcceptOnlyNecessaryCookiesTitle %>$SiteConfig.AcceptOnlyNecessaryCookiesTitle<% else %><%t SilverShop\CookieConsent\ConsentState.RejectAllCookies 'Reject non-essential' %><% end_if %>
            </a>
            <a class="consent__btn consent__btn--primary" href="$AcceptAllCookiesLink" rel="nofollow" data-consent="accept">
                <% if $SiteConfig.AcceptAllCookiesTitle %>$SiteConfig.AcceptAllCookiesTitle<% else %><%t SilverShop\CookieConsent\ConsentState.AcceptAllCookies 'Accept all' %><% end_if %>
            </a>
            <button class="consent__btn consent__btn--save" type="submit" form="consent-prefs" data-consent="save" hidden><%t SilverShop\CookieConsent\ConsentState.SavePreferences 'Save preferences' %></button>
        </div>

        <% if $CookiePolicyPage %>
            <noscript>
                <a class="consent__policy-link" href="$CookiePolicyPage.Link"><%t SilverShop\CookieConsent\ConsentState.ManageCookies 'Manage preferences' %></a>
            </noscript>
        <% end_if %>
    </div>
</div>
<% end_if %>
