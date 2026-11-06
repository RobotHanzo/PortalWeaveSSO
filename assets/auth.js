/* Standalone OAuth fragment relay and same-origin popup completion. */
(function () {
    'use strict';

    const page = document.body;
    if (page.dataset.wpssoMode === 'relay') {
        const response = new URLSearchParams(window.location.hash.slice(1));
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
        const form = document.getElementById('wpsso-relay');
        if (!response.has('state')) {
            document.getElementById('wpsso-relay-status').textContent = page.dataset.wpssoNoResponse;
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
    } else if (page.dataset.wpssoMode === 'popup') {
        const redirect = page.dataset.wpssoRedirect;
        if (window.opener) {
            window.opener.postMessage({ type: 'wpsso-complete', redirect: redirect }, page.dataset.wpssoOrigin);
            window.close();
        } else {
            window.location.replace(redirect);
        }
    }
})();
