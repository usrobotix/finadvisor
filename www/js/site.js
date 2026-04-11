/**
 * site.js — Yandex SmartCaptcha integration + form success/error modal.
 *
 * How it works:
 *   1. A capture-phase "submit" listener intercepts every Tilda form before
 *      Tilda's own handler can run.
 *   2. If the captcha token is not yet available it executes the invisible
 *      SmartCaptcha widget for that form, then stops the event.
 *   3. When the captcha callback fires with a token the form is re-submitted,
 *      the token is injected into a hidden <input name="captcha_token"> and
 *      Tilda's normal validation + AJAX pipeline proceeds.
 *   4. An XMLHttpRequest wrapper intercepts the /send.php response and shows
 *      the success/error modal instead of (or alongside) Tilda's built-in UI.
 *
 * Configuration:
 *   Replace SMARTCAPTCHA_SITEKEY below with the real Yandex SmartCaptcha
 *   sitekey for this site (starts with "ysc1_").
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /* Configuration                                                        */
    /* ------------------------------------------------------------------ */
    var SMARTCAPTCHA_SITEKEY = 'ysc1_REPLACE_WITH_YOUR_SMARTCAPTCHA_SITEKEY';

    /* Warn loudly in development if the placeholder has not been replaced */
    if (SMARTCAPTCHA_SITEKEY.indexOf('REPLACE_WITH') !== -1) {
        if (typeof console !== 'undefined' && console.warn) {
            console.warn('[site.js] SmartCaptcha sitekey is a placeholder. ' +
                'Replace SMARTCAPTCHA_SITEKEY in www/js/site.js with a real sitekey ' +
                'from https://console.yandex.cloud/');
        }
    }

    /* ------------------------------------------------------------------ */
    /* State                                                                */
    /* ------------------------------------------------------------------ */
    var captchaWidgets = {};  // formId -> widgetId
    var readyTokens    = {};  // formId -> token (consumed once)
    var pendingFormId  = null;

    /* ------------------------------------------------------------------ */
    /* Helpers                                                              */
    /* ------------------------------------------------------------------ */
    function getForm(id) {
        return document.getElementById(id);
    }

    function getTokenField(form) {
        return form ? form.querySelector('[name="captcha_token"]') : null;
    }

    /* ------------------------------------------------------------------ */
    /* Modal                                                                */
    /* ------------------------------------------------------------------ */
    function showModal(ok, message) {
        var modal = document.getElementById('site-modal');
        if (!modal) return;

        modal.className = 'site-modal ' + (ok ? 'site-modal--success' : 'site-modal--error');

        var iconEl = document.getElementById('site-modal-icon');
        var titleEl = document.getElementById('site-modal-title');
        var msgEl = document.getElementById('site-modal-message');

        if (iconEl)  iconEl.textContent  = ok ? '✓' : '✗';
        if (titleEl) titleEl.textContent = ok ? 'Заявка принята!' : 'Ошибка отправки';
        if (msgEl)   msgEl.textContent   = message || '';

        modal.style.display = 'flex';
    }

    /* Exposed globally so the close button can call it */
    window.siteCloseModal = function () {
        var modal = document.getElementById('site-modal');
        if (modal) modal.style.display = 'none';
    };

    /* ------------------------------------------------------------------ */
    /* SmartCaptcha callbacks                                               */
    /* ------------------------------------------------------------------ */
    window._scSuccess = function (token) {
        var formId = pendingFormId;
        pendingFormId = null;
        if (!formId) return;

        readyTokens[formId] = token;

        var form = getForm(formId);
        var field = getTokenField(form);
        if (field) field.value = token;

        /* Re-dispatch submit so Tilda's handler (validation + AJAX) runs */
        if (form) {
            form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
    };

    window._scError = function () {
        pendingFormId = null;
        showModal(false, 'Ошибка проверки капчи. Пожалуйста, попробуйте ещё раз.');
    };

    window._scExpired = function () {
        /* Clear any stale tokens; user must re-verify */
        readyTokens = {};
        pendingFormId = null;
    };

    /* ------------------------------------------------------------------ */
    /* SmartCaptcha widget initialisation                                   */
    /* ------------------------------------------------------------------ */
    function initCaptchaForForm(form) {
        var container = form.querySelector('.js-smartcaptcha');
        if (!container) return;
        if (!window.smartCaptcha) return;

        var widgetId = window.smartCaptcha.render(container, {
            sitekey:           SMARTCAPTCHA_SITEKEY,
            callback:          window._scSuccess,
            'error-callback':  window._scError,
            'expired-callback': window._scExpired,
            hl:                'ru',
            invisible:         true
        });
        captchaWidgets[form.id] = widgetId;
    }

    function initAllCaptchas() {
        var forms = document.querySelectorAll('.t-form.js-form-proccess');
        forms.forEach(function (form) {
            initCaptchaForForm(form);
        });
    }

    /* Poll until smartCaptcha is available (loaded async) */
    function waitForSmartCaptcha(retries) {
        if (window.smartCaptcha) {
            initAllCaptchas();
            return;
        }
        if (retries === undefined) retries = 0;
        if (retries > 100) return; /* give up after ~10 s */
        setTimeout(function () { waitForSmartCaptcha(retries + 1); }, 100);
    }

    /* ------------------------------------------------------------------ */
    /* Form submit interception (capture phase — runs before Tilda)         */
    /* ------------------------------------------------------------------ */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.classList.contains('js-form-proccess')) return;

        /* Token already obtained this cycle — let Tilda proceed */
        if (readyTokens[form.id]) {
            delete readyTokens[form.id];
            return;
        }

        /* Block event and trigger captcha challenge */
        e.stopImmediatePropagation();
        e.preventDefault();

        if (!window.smartCaptcha || captchaWidgets[form.id] === undefined) {
            showModal(false, 'Капча не инициализирована. Пожалуйста, перезагрузите страницу.');
            return;
        }

        pendingFormId = form.id;
        window.smartCaptcha.execute(captchaWidgets[form.id]);
    }, true /* capture */);

    /* ------------------------------------------------------------------ */
    /* XHR patching — intercept /send.php requests                         */
    /* ------------------------------------------------------------------ */
    (function () {
        var origOpen = XMLHttpRequest.prototype.open;
        var origSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function (method, url) {
            this._sendPhpUrl = (typeof url === 'string') && (url.indexOf('/send.php') !== -1 || url === 'send.php');
            return origOpen.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function (body) {
            if (this._sendPhpUrl) {
                /* Append captcha_token to form data.
                   The token is already placed in the hidden <input> so Tilda
                   serializes it normally.  We only handle FormData here as a
                   safety net for non-Tilda callers; string bodies are left
                   unchanged because the hidden field covers that case. */
                if (body instanceof FormData) {
                    /* Remove any accidental duplicate then set */
                    body.delete('captcha_token');
                    body.append('captcha_token', window._pendingXhrToken || '');
                }
                window._pendingXhrToken = null;

                /* Listen to the response to drive the modal */
                this.addEventListener('readystatechange', function () {
                    if (this.readyState !== 4) return;
                    try {
                        var data = JSON.parse(this.responseText);
                        if (data.ok === true) {
                            showModal(true, data.message || 'Ваша заявка принята! Мы свяжемся с вами в ближайшее время.');
                        } else {
                            showModal(false, data.message || 'Произошла ошибка. Пожалуйста, попробуйте ещё раз.');
                        }
                    } catch (err) {
                        /* Non-JSON response — leave Tilda's UI intact */
                    }
                });
            }
            return origSend.call(this, body);
        };
    })();

    /* ------------------------------------------------------------------ */
    /* Store token for the XHR patch right before re-dispatch              */
    /* ------------------------------------------------------------------ */
    var _origScSuccess = window._scSuccess;
    window._scSuccess = function (token) {
        window._pendingXhrToken = token;
        _origScSuccess(token);
    };

    /* ------------------------------------------------------------------ */
    /* Init                                                                 */
    /* ------------------------------------------------------------------ */
    if (document.readyState !== 'loading') {
        waitForSmartCaptcha();
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            waitForSmartCaptcha();
        });
    }
}());
