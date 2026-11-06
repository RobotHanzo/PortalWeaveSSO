/* Standalone OAuth fragment relay and same-origin popup completion. */
(function () {
    'use strict';

    const page = document.body;
    if (page.dataset.portalweaveSsoMode === 'relay') {
        const response = new URLSearchParams(window.location.hash.slice(1));
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
        const form = document.getElementById('portalweave-sso-relay');
        if (!response.has('state')) {
            document.getElementById('portalweave-sso-relay-status').textContent = page.dataset.portalweaveSsoNoResponse;
            return;
        }
        ['state', 'id_token', 'access_token', 'token_type', 'error', 'iss'].forEach(function (key) {
            if (response.has(key)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = response.get(key);
                form.appendChild(input);
            }
        });
        form.submit();
    } else if (page.dataset.portalweaveSsoMode === 'popup') {
        const redirect = page.dataset.portalweaveSsoRedirect;
        if (window.opener) {
            window.opener.postMessage({ type: 'portalweave-sso-complete', redirect: redirect }, page.dataset.portalweaveSsoOrigin);
            window.close();
        } else {
            window.location.replace(redirect);
        }
    }
})();
