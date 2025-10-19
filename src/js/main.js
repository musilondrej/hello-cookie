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

    function deleteCookie(name) {
        const expires = 'Thu, 01 Jan 1970 00:00:00 GMT';
        const hostname = location.hostname;
        const parts = hostname.split('.');
        const baseDomain = parts.length > 2 ? `.${parts.slice(-2).join('.')}` : `.${hostname}`;
        const candidates = new Set([
            '',
            `.${hostname}`,
            baseDomain
        ]);
        candidates.forEach(domain => {
            try {
                document.cookie = `${name}=; expires=${expires}; path=/; ${domain ? `domain=${domain};` : ''} secure; SameSite=Lax`;
            } catch (e) { /* ignore */ }
        });
    }

    function buildCookieMatchers(patternsCsv) {
        const raw = (patternsCsv || '').split(',').map(s => s.trim()).filter(Boolean);
        const matchers = raw.map(p => {
            if (p.includes('*')) {
                const esc = p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\\\*/g, '.*');
                const re = new RegExp(`^${esc}$`);
                return name => re.test(name);
            }
            if (p.endsWith('_')) {
                const pref = p.slice(0, -1);
                return name => name.startsWith(pref);
            }
            if (p.startsWith('_') || p.startsWith('__')) {
                return name => name.startsWith(p);
            }
            return name => name === p;
        });
        return name => matchers.some(fn => fn(name));
    }

    function eraseConfiguredCookies() {
        const shouldErase = buildCookieMatchers(CONFIG.cookiesToErase || '');
        const cookies = (document.cookie || '').split(';').map(c => c.trim().split('=')[0]).filter(Boolean);
        cookies.forEach(name => {
            if (shouldErase(name)) deleteCookie(name);
        });
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
                // Pokud uživatel nastavil bez analytiky/marketingu, smažeme známé cookies
                if (!accepted('analytics') || !accepted('marketing')) {
                    eraseConfiguredCookies();
                }
            },
            onConsent: function (user_preferences) {
                updateConsentMode();
            },
            onChange: function (user_preferences, changed_categories) {
                updateConsentMode();
                logConsentToServer('change');
                // Pokud došlo k odvolání analytiky/marketingu, smažeme známé cookies
                try {
                    const changed = Array.isArray(changed_categories) ? changed_categories : [];
                    if ((changed.includes('analytics') && !accepted('analytics')) ||
                        (changed.includes('marketing') && !accepted('marketing'))) {
                        eraseConfiguredCookies();
                    }
                } catch(e) { /* ignore */ }
            }
        };

        CookieConsent.run(configWithCallbacks);
    }

    initializeCookieConsent();
});
