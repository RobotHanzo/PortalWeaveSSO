(() => {
  'use strict';
  const presets = {
    entra: { base: 'https://login.microsoftonline.com/YOUR_TENANT_ID/v2.0', authorize_url: 'https://login.microsoftonline.com/YOUR_TENANT_ID/oauth2/v2.0/authorize', token_url: 'https://login.microsoftonline.com/YOUR_TENANT_ID/oauth2/v2.0/token', userinfo_url: 'https://graph.microsoft.com/oidc/userinfo', jwks_url: 'https://login.microsoftonline.com/YOUR_TENANT_ID/discovery/v2.0/keys', logout_url: 'https://login.microsoftonline.com/YOUR_TENANT_ID/oauth2/v2.0/logout' },
    b2c: { base: 'https://YOUR_TENANT.b2clogin.com/YOUR_TENANT_ID/v2.0/', authorize_url: 'https://YOUR_TENANT.b2clogin.com/YOUR_TENANT.onmicrosoft.com/YOUR_POLICY/oauth2/v2.0/authorize', token_url: 'https://YOUR_TENANT.b2clogin.com/YOUR_TENANT.onmicrosoft.com/YOUR_POLICY/oauth2/v2.0/token', jwks_url: 'https://YOUR_TENANT.b2clogin.com/YOUR_TENANT.onmicrosoft.com/YOUR_POLICY/discovery/v2.0/keys' },
    cognito: { base: 'https://cognito-idp.YOUR_REGION.amazonaws.com/YOUR_POOL_ID', authorize_url: 'https://YOUR_DOMAIN.auth.YOUR_REGION.amazoncognito.com/oauth2/authorize', token_url: 'https://YOUR_DOMAIN.auth.YOUR_REGION.amazoncognito.com/oauth2/token', userinfo_url: 'https://YOUR_DOMAIN.auth.YOUR_REGION.amazoncognito.com/oauth2/userInfo', jwks_url: 'https://cognito-idp.YOUR_REGION.amazonaws.com/YOUR_POOL_ID/.well-known/jwks.json' },
    keycloak: { base: 'https://YOUR_HOST/realms/YOUR_REALM', authorize_url: 'https://YOUR_HOST/realms/YOUR_REALM/protocol/openid-connect/auth', token_url: 'https://YOUR_HOST/realms/YOUR_REALM/protocol/openid-connect/token', userinfo_url: 'https://YOUR_HOST/realms/YOUR_REALM/protocol/openid-connect/userinfo', jwks_url: 'https://YOUR_HOST/realms/YOUR_REALM/protocol/openid-connect/certs', logout_url: 'https://YOUR_HOST/realms/YOUR_REALM/protocol/openid-connect/logout' },
    okta: { base: 'https://YOUR_ORG.okta.com/oauth2/default', authorize_url: 'https://YOUR_ORG.okta.com/oauth2/default/v1/authorize', token_url: 'https://YOUR_ORG.okta.com/oauth2/default/v1/token', userinfo_url: 'https://YOUR_ORG.okta.com/oauth2/default/v1/userinfo', jwks_url: 'https://YOUR_ORG.okta.com/oauth2/default/v1/keys', logout_url: 'https://YOUR_ORG.okta.com/oauth2/default/v1/logout' },
    salesforce: { base: 'https://login.salesforce.com', authorize_url: 'https://login.salesforce.com/services/oauth2/authorize', token_url: 'https://login.salesforce.com/services/oauth2/token', userinfo_url: 'https://login.salesforce.com/services/oauth2/userinfo', jwks_url: 'https://login.salesforce.com/id/keys' },
    google: { base: 'https://accounts.google.com', authorize_url: 'https://accounts.google.com/o/oauth2/v2/auth', token_url: 'https://oauth2.googleapis.com/token', userinfo_url: 'https://openidconnect.googleapis.com/v1/userinfo', jwks_url: 'https://www.googleapis.com/oauth2/v3/certs' },
    auth0: { base: 'https://YOUR_TENANT.auth0.com/', authorize_url: 'https://YOUR_TENANT.auth0.com/authorize', token_url: 'https://YOUR_TENANT.auth0.com/oauth/token', userinfo_url: 'https://YOUR_TENANT.auth0.com/userinfo', jwks_url: 'https://YOUR_TENANT.auth0.com/.well-known/jwks.json', logout_url: 'https://YOUR_TENANT.auth0.com/oidc/logout' },
    onelogin: { base: 'https://YOUR_SUBDOMAIN.onelogin.com/oidc/2', authorize_url: 'https://YOUR_SUBDOMAIN.onelogin.com/oidc/2/auth', token_url: 'https://YOUR_SUBDOMAIN.onelogin.com/oidc/2/token', userinfo_url: 'https://YOUR_SUBDOMAIN.onelogin.com/oidc/2/me', jwks_url: 'https://YOUR_SUBDOMAIN.onelogin.com/oidc/2/certs' },
    discord: { protocol: 'oauth2', scope: 'identify email', authorize_url: 'https://discord.com/oauth2/authorize', token_url: 'https://discord.com/api/oauth2/token', userinfo_url: 'https://discord.com/api/users/@me', subject_attribute: 'id', mapping: { username: 'username', email: 'email', first_name: '', last_name: '', display_name: 'global_name', groups: 'groups' }, verified_attribute: 'verified' },
    classlink: { protocol: 'oauth2', scope: 'profile', authorize_url: 'https://launchpad.classlink.com/oauth2/v2/auth', token_url: 'https://launchpad.classlink.com/oauth2/v2/token', userinfo_url: 'https://nodeapi.classlink.com/v2/my/info', subject_attribute: 'SourcedId' },
    authentik: { base: 'https://YOUR_HOST/application/o/YOUR_APPLICATION/', authorize_url: 'https://YOUR_HOST/application/o/authorize/', token_url: 'https://YOUR_HOST/application/o/token/', userinfo_url: 'https://YOUR_HOST/application/o/userinfo/', jwks_url: 'https://YOUR_HOST/application/o/YOUR_APPLICATION/jwks/', logout_url: 'https://YOUR_HOST/application/o/YOUR_APPLICATION/end-session/' }
  };
  presets.office365 = presets.entra;
  document.querySelectorAll('.portalweave-sso-preset').forEach(select => {
    select.addEventListener('change', () => {
      const preset = presets[select.value];
      if (!preset) return;
      const provider = select.closest('.portalweave-sso-provider');
      if (!provider) return;
      const defaults = { protocol: 'oidc', scope: 'openid profile email', issuer: preset.base || '', ...preset };
      Object.entries(defaults).forEach(([key, value]) => {
        if (key === 'base') return;
        const field = provider.querySelector(`[name$="[${key}]"]`);
        if (!field) return;
        // Changing providers is explicit; credentials and existing endpoint values are preserved.
        if (!field.value || key === 'protocol' || key === 'scope' || key === 'subject_attribute' || key === 'verified_attribute' || key === 'mapping') {
          field.value = typeof value === 'object' ? JSON.stringify(value, null, 2) : value;
        }
      });
    });
  });
})();
