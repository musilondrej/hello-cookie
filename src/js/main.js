import "vanilla-cookieconsent/dist/cookieconsent.css";
import * as CookieConsent from "vanilla-cookieconsent";

window.CookieConsent = CookieConsent;

(function ensureGtmStubs() {
    if (typeof window !== 'undefined') {
        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };

        try { window.gtag('js', new Date()); } catch (e) { }
    }
})();

document.addEventListener('DOMContentLoaded', function () {
    const CONFIG = window.CCM_CONFIG;

    if (!CONFIG) {
        console.error('CCM Cookie Consent: Configuration not found');
        return;
    }

    function generateConsentId() {
        return Array.from(crypto.getRandomValues(new Uint8Array(32)), b =>
            b.toString(16).padStart(2, '0')).join('');
    }

    function getOrCreateConsentId() {
        let consentId = getCookie('ccm_consent_id');
        if (!consentId) {
            consentId = generateConsentId();
            setCookie('ccm_consent_id', consentId, 365);
        }
        return consentId;
    }

    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }

    function setCookie(name, value, days) {
        const expires = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie = `${name}=${value}; expires=${expires}; path=/; secure; SameSite=Lax`;
    }

    const accepted = (cat) => CookieConsent.acceptedCategory(cat);
    const grantIf = (flag) => flag ? 'granted' : 'denied';
    const hasGtag = () => typeof gtag !== 'undefined';
    const hasDL = () => typeof dataLayer !== 'undefined';
    const hasFbq = () => typeof fbq !== 'undefined';

    function getAcceptedCategoriesArray() {
        const all = ['necessary', 'analytics', 'marketing', 'functionality'];
        return all.filter(cat => cat === 'necessary' || accepted(cat));
    }

    function logConsentToServer(source) {
        setTimeout(() => {
            const categories = getAcceptedCategoriesArray();
            const consentId = getOrCreateConsentId();
            fetch(CONFIG.restUrl + 'ccm/v1/consent', {
                method: 'POST',
                body: JSON.stringify({
                    consent_id: consentId,
                    categories: categories,
                    version_hash: CONFIG.revision || CONFIG.version || '1.0',
                    source: source || 'accept'
                }),
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': CONFIG.nonce
                }
            })
                .then(response => response.json())
                .catch(error => {
                    console.error('Error logging consent:', error);
                });
        }, 100);
    }

    function buildConsentUpdate() {
        return {
            analytics_storage: grantIf(accepted('analytics')),
            ad_storage: grantIf(accepted('marketing')),
            ad_user_data: grantIf(accepted('marketing')),
            ad_personalization: grantIf(accepted('marketing')),
            functionality_storage: grantIf(accepted('functionality')),
            security_storage: 'granted'
        };
    }

    function pushDataLayer(eventName, payload) {
        if (!hasDL()) return;
        dataLayer.push({ event: eventName, ...payload });
    }

    function setFbqFromCategories() {
        if (!hasFbq()) return;
        fbq('consent', accepted('marketing') ? 'grant' : 'revoke');
    }

    function updateConsentMode() {
        const consentUpdate = buildConsentUpdate();

        if (hasGtag()) gtag('consent', 'update', consentUpdate);

        pushDataLayer('cookie_consent_update', { ...consentUpdate, timestamp: Date.now() });
        setFbqFromCategories();
    }

    function initializeCookieConsent() {

        const configWithCallbacks = {
            ...CONFIG,
            onFirstConsent: function (user_preferences) {
                updateConsentMode();
                logConsentToServer('accept');
            },
            onConsent: function (user_preferences) {
                updateConsentMode();
            },
            onChange: function (user_preferences, changed_categories) {
                updateConsentMode();
                logConsentToServer('change');
            }
        };

        CookieConsent.run(configWithCallbacks);
    }

    initializeCookieConsent();
});
