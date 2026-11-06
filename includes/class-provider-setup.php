<?php
namespace PortalWeaveSSO;

defined('ABSPATH') || exit;

/** Local, server-validated setup recipes. This class never requests provider metadata. */
final class ProviderSetup
{
    public static function catalog(): array
    {
        $credentials = [
            'authentik' => self::step(__('Copy the authentik provider credentials', 'portalweave-sso'), __('In the authentik administration interface, open Applications → Providers and select the OAuth2/OIDC provider attached to your application.', 'portalweave-sso'), [
                __('Open the provider configuration and copy its Client ID and Client Secret. Use this provider, not the application slug, as the source of the credentials.', 'portalweave-sso'),
                __('Keep the client type Confidential and the RSA signing key selected. Paste the credential values below, then save and test.', 'portalweave-sso'),
            ]),
            'entra' => self::step(__('Copy the Entra application credentials', 'portalweave-sso'), __('Open Entra ID → App registrations and select the application you registered.', 'portalweave-sso'), [
                __('On Overview, copy Application (client) ID into Client ID. Directory (tenant) ID belongs in the earlier tenant field.', 'portalweave-sso'),
                __('Open Certificates & secrets → Client secrets → New client secret, enter a description and expiry, and select Add.', 'portalweave-sso'),
                __('Copy the secret Value immediately into Client secret. The Value is shown only at creation; Secret ID is not the credential.', 'portalweave-sso'),
            ]),
            'office365' => self::step(__('Copy the Microsoft 365 Entra credentials', 'portalweave-sso'), __('Open the Microsoft Entra App registrations entry for your Microsoft 365 directory application.', 'portalweave-sso'), [
                __('Copy Application (client) ID from Overview. Microsoft 365 uses this Entra application rather than a separate Office 365 OAuth credential.', 'portalweave-sso'),
                __('Under Certificates & secrets → Client secrets, create a secret with an appropriate description and expiry.', 'portalweave-sso'),
                __('Copy its Value immediately into Client secret, not Secret ID. The secret Value is available only when created.', 'portalweave-sso'),
            ]),
            'b2c' => self::step(__('Copy the B2C application credentials', 'portalweave-sso'), __('Select the existing B2C directory, open Azure AD B2C → App registrations, and select this application.', 'portalweave-sso'), [
                __('Copy Application (client) ID from the application Overview. It is different from the tenant ID and policy name.', 'portalweave-sso'),
                __('Open Certificates & secrets → Client secrets → New client secret and add a secret for the registered web application.', 'portalweave-sso'),
                __('Copy the secret Value while it is shown and paste it below. Do not copy Secret ID; an old secret Value cannot be displayed again.', 'portalweave-sso'),
            ]),
            'cognito' => self::step(__('Copy the Cognito app client credentials', 'portalweave-sso'), __('Open Amazon Cognito → User pools → your pool → Applications → App clients, then select the traditional web application client.', 'portalweave-sso'), [
                __('Copy the Client ID and Client secret from the app client details.', 'portalweave-sso'),
                __('Use the app client belonging to the pool and managed-login domain entered earlier. The pool ID and AWS access keys are not OAuth client credentials.', 'portalweave-sso'),
            ]),
            'keycloak' => self::step(__('Copy the Keycloak client credentials', 'portalweave-sso'), __('In the selected realm, open Clients and select the client you created.', 'portalweave-sso'), [
                __('Copy the Client ID from the client Settings.', 'portalweave-sso'),
                __('Keep Client authentication enabled. On Credentials, select the Client ID and Secret authenticator and copy Client Secret.', 'portalweave-sso'),
                __('These are the realm client credentials, not a Keycloak administrator username or password.', 'portalweave-sso'),
            ]),
            'okta' => self::step(__('Copy the Okta application credentials', 'portalweave-sso'), __('Open Applications and Resources → Applications, select this OIDC web integration, and open General → Client Credentials.', 'portalweave-sso'), [
                __('Copy Client ID, select Client secret authentication, and generate or copy the client secret shown for this integration.', 'portalweave-sso'),
                __('Use HTTP Basic client authentication to match this recipe and retain S256 PKCE. In older consoles, applications are listed under Applications → Applications.', 'portalweave-sso'),
            ]),
            'salesforce' => self::step(__('Copy the Salesforce Consumer Key and Secret', 'portalweave-sso'), __('In Setup, open External Client App Manager and select this External Client App.', 'portalweave-sso'), [
                __('Open Settings → OAuth Settings → Consumer Key and Secret and complete the verification challenge when requested.', 'portalweave-sso'),
                __('Copy Consumer Key into the field below and Consumer Secret into the secret field. Do not use a Salesforce account password or security token.', 'portalweave-sso'),
            ]),
            'google' => self::step(__('Copy the Google web client credentials', 'portalweave-sso'), __('In the correct Google Cloud project, open Google Auth platform → Clients and select this Web application OAuth client.', 'portalweave-sso'), [
                __('Copy the Client ID from the web client details.', 'portalweave-sso'),
                __('Copy the client secret value when the client or secret is created. Google may show a new secret only once; use its current credential-management guidance if the value was not recorded.', 'portalweave-sso'),
                __('Use this OAuth web client secret, not a Google API key, service-account JSON key or account password.', 'portalweave-sso'),
            ]),
            'auth0' => self::step(__('Copy the Auth0 application credentials', 'portalweave-sso'), __('Open Applications → Applications, select the Regular Web Application, and open Settings.', 'portalweave-sso'), [
                __('Copy Client ID and Client Secret from this application. Some dashboard versions place secret controls on a Credentials tab.', 'portalweave-sso'),
                __('Choose Client Secret (Basic) in the application credential settings and use the tenant or custom domain entered earlier.', 'portalweave-sso'),
            ]),
            'discord' => self::step(__('Copy the Discord OAuth2 credentials', 'portalweave-sso'), __('In the Discord Developer Portal, select this application and open OAuth2 settings.', 'portalweave-sso'), [
                __('Copy Client ID and the OAuth2 Client Secret from the application settings.', 'portalweave-sso'),
                __('If the value is unavailable, use the portal client-secret reveal or reset control. Resetting a secret requires updating the application credential stored here.', 'portalweave-sso'),
                __('Do not copy a bot token from the Bot page. This connection uses the application OAuth2 client secret.', 'portalweave-sso'),
            ]),
            'classlink' => self::step(__('Copy the ClassLink partner-issued credentials', 'portalweave-sso'), __('Return to the OAuth2 application registered through your ClassLink Partner Portal account.', 'portalweave-sso'), [
                __('Use the client identifier and credential values issued for this partner connection, following the current portal instructions or ClassLink partner support guidance.', 'portalweave-sso'),
                __('Match the documented client authentication method. Confidential Basic or request-body clients need the issued secret; a documented public PKCE client uses None and no secret.', 'portalweave-sso'),
                __('Do not substitute a LaunchPad password, district rostering key or OneRoster credential for the OAuth2 application credentials.', 'portalweave-sso'),
            ]),
            'custom' => self::step(__('Copy the registered web client credentials', 'portalweave-sso'), __('Open the web application you registered in your provider administration console and follow its maintained credential documentation.', 'portalweave-sso'), [
                __('Copy that application client identifier and select the token-endpoint authentication method configured at the provider.', 'portalweave-sso'),
                __('For confidential Basic or request-body authentication, copy the actual client secret value. For a public client, select None, leave the secret empty and require S256 PKCE.', 'portalweave-sso'),
                __('Do not use an administrator password or unrelated API token. Record a newly issued secret securely if the provider shows it only once.', 'portalweave-sso'),
            ]),
        ];
        $client = self::field(__('Client ID', 'portalweave-sso'), 'text', __('Copy the application client identifier exactly.', 'portalweave-sso'), true, 'credentials');
        $secret = self::field(__('Client secret', 'portalweave-sso'), 'password', __('Copy the secret value. It is encrypted when saved and is never shown again by this wizard.', 'portalweave-sso'), true, 'credentials');
        $host = self::field(__('Provider URL', 'portalweave-sso'), 'url', __('Enter the full HTTPS address of your provider, without a query string or fragment.', 'portalweave-sso'), true, 'location', 'https://login.example.org');
        $host_step = self::step(__('Locate your provider', 'portalweave-sso'), __('Use the address of the instance your users already use.', 'portalweave-sso'), [__('Open the provider administration console and copy its HTTPS instance address.', 'portalweave-sso')]);
        $verify_notice = __('A protocol test does not prove account eligibility. Keep verified-email checks enabled; test the actual claims and account policies before registration, linking or administrator sign-in.', 'portalweave-sso');
        $catalog = [];
        $catalog['authentik'] = [
            'label' => __('authentik', 'portalweave-sso'), 'summary' => __('Connect an OAuth2/OIDC application on your authentik instance.', 'portalweave-sso'),
            'docs' => 'https://docs.goauthentik.io/add-secure-apps/providers/oauth2/create-oauth2-provider/',
            'steps' => ['location' => $host_step, 'registration' => self::step(__('Create the application and provider', 'portalweave-sso'), __('Open Applications → Applications → New Application.', 'portalweave-sso'), [
                __('Enter the application name and slug, continue, and choose OAuth2/OIDC.', 'portalweave-sso'),
                __('Choose a Confidential client, an authorization flow and an RSA signing key. Without a signing key, authentik can issue HS256 tokens instead of the required RS256.', 'portalweave-sso'),
                __('Register the callback shown here with strict matching, enable the openid, profile and email scopes, and create the application.', 'portalweave-sso'),
            ]), 'credentials' => $credentials['authentik']],
            'fields' => ['host' => array_replace($host, ['help' => __('Use the instance base URL before /if/admin, not the full administration page address.', 'portalweave-sso')]), 'application' => self::field(__('Application slug', 'portalweave-sso'), 'text', __('Copy the application slug, not its display name.', 'portalweave-sso'), true, 'registration'), 'client_id' => $client, 'client_secret' => $secret],
            'notices' => [__('The recipe uses a separate issuer for each application. Global issuer mode needs the advanced editor. Current authentik email scopes return email_verified=false unless you configure a genuine current-address verification flow and mapping.', 'portalweave-sso'), $verify_notice],
        ];
        $catalog['entra'] = [
            'label' => __('Microsoft Entra ID', 'portalweave-sso'), 'summary' => __('Register a single-tenant web application in Microsoft Entra ID.', 'portalweave-sso'),
            'docs' => 'https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app',
            'steps' => ['location' => self::step(__('Identify your workforce tenant', 'portalweave-sso'), __('Open the Microsoft Entra admin center and select your directory.', 'portalweave-sso'), [__('Copy the Directory (tenant) ID. Use the tenant UUID, not common, organizations or an email domain.', 'portalweave-sso')]), 'registration' => self::step(__('Register a single-tenant web application', 'portalweave-sso'), __('Open Entra ID → App registrations → New registration.', 'portalweave-sso'), [
                __('Choose accounts in this organizational directory only and register the application.', 'portalweave-sso'),
                __('Under Authentication, add a Web redirect URI and paste the callback shown here. Do not enable implicit or hybrid grants for this authorization-code flow.', 'portalweave-sso'),
                __('Under Certificates & secrets → Client secrets, create a secret and copy its Value immediately. Do not copy the Secret ID.', 'portalweave-sso'),
            ]), 'credentials' => $credentials['entra']],
            'fields' => ['tenant_id' => self::field(__('Directory (tenant) ID', 'portalweave-sso'), 'text', __('The tenant UUID shown in the directory or application overview.', 'portalweave-sso'), true, 'location'), 'client_id' => $client, 'client_secret' => $secret],
            'notices' => [__('This recipe uses the Microsoft global cloud. Sovereign clouds need advanced endpoints. Entra does not promise an email_verified claim; configure a trustworthy tenant-specific verification claim before allowing account creation, linking or administrator SSO. Email and UPN are not immutable subjects.', 'portalweave-sso'), $verify_notice],
        ];
        $catalog['office365'] = $catalog['entra'];
        $catalog['office365']['label'] = __('Microsoft 365 / Office 365', 'portalweave-sso');
        $catalog['office365']['summary'] = __('Use the Microsoft Entra registration belonging to your Microsoft 365 directory.', 'portalweave-sso');
        $catalog['office365']['steps']['credentials'] = $credentials['office365'];
        $catalog['b2c'] = [
            'label' => __('Azure AD B2C', 'portalweave-sso'), 'summary' => __('Connect an existing B2C tenant and its sign-up/sign-in policy.', 'portalweave-sso'),
            'docs' => 'https://learn.microsoft.com/en-us/azure/active-directory-b2c/active-directory-b2c-app-registration',
            'steps' => ['location' => self::step(__('Locate the existing B2C policy', 'portalweave-sso'), __('Select your existing B2C directory and sign-up/sign-in user flow or custom policy.', 'portalweave-sso'), [__('Copy the policy authority. The policy name is part of this address and cannot be inferred from a client ID.', 'portalweave-sso')]), 'registration' => self::step(__('Register the B2C web application', 'portalweave-sso'), __('Open Azure AD B2C → App registrations → New registration.', 'portalweave-sso'), [
                __('Choose the accounts used by your user flows, add a Web redirect URI with the callback shown here, and register the application.', 'portalweave-sso'),
                __('Select the application claims needed by the policy. The recipe maps email from emails.0; adjust the saved mapping if your policy emits a scalar email instead.', 'portalweave-sso'),
                __('The wizard requests openid and the client ID of this application as scopes. Microsoft documents the client ID scope for access to your own application service. This requests the Bearer access token needed alongside the ID token.', 'portalweave-sso'),
                __('Open your policy metadata at the authority followed by /v2.0/.well-known/openid-configuration and copy its exact issuer, including its trailing slash.', 'portalweave-sso'),
                __('Create a client secret under Certificates & secrets and copy its Value. Do not copy a tutorial jwt.ms callback or enable implicit grants.', 'portalweave-sso'),
            ]), 'credentials' => $credentials['b2c']],
            'fields' => ['authority' => self::field(__('Policy authority', 'portalweave-sso'), 'url', __('Use https://tenant.b2clogin.com/tenant.onmicrosoft.com/POLICY with your real tenant and policy.', 'portalweave-sso'), true, 'location'), 'issuer' => self::field(__('Exact metadata issuer', 'portalweave-sso'), 'url', __('Copy issuer from this policy metadata. It is different from the policy authority; preserve its trailing slash.', 'portalweave-sso'), true, 'registration'), 'client_id' => $client, 'client_secret' => $secret],
            'notices' => [__('B2C is for existing tenants; new customer purchases ended on 1 May 2025. Built-in user flows have no generic UserInfo endpoint. Email collection or OTP does not establish that every federated identity emits a trustworthy email_verified claim. Policies can also share an issuer.', 'portalweave-sso'), $verify_notice],
        ];
        $catalog['cognito'] = [
            'label' => __('Amazon Cognito', 'portalweave-sso'), 'summary' => __('Connect a user pool and its managed-login or custom domain.', 'portalweave-sso'),
            'docs' => 'https://docs.aws.amazon.com/cognito/latest/developerguide/user-pool-settings-client-apps.html',
            'steps' => ['location' => self::step(__('Identify the pool and login domain', 'portalweave-sso'), __('Open Amazon Cognito → User pools and select your pool.', 'portalweave-sso'), [
                __('Copy the pool issuer from its metadata or settings. It is not the managed-login domain. Both cognito-idp and the updated issuer-cognito-idp issuer forms are supported.', 'portalweave-sso'),
                __('Copy the managed-login or custom domain origin. Configure it under Branding → Domain if necessary.', 'portalweave-sso'),
            ]), 'registration' => self::step(__('Create a traditional web application client', 'portalweave-sso'), __('Open Applications → App clients and create an app client for a Traditional web application.', 'portalweave-sso'), [
                __('Set the Return URL to the callback shown here and create the client.', 'portalweave-sso'),
                __('Under Login pages → Managed login pages configuration, enable Authorization code grant, your intended identity providers, and the openid, email and profile scopes.', 'portalweave-sso'),
                __('Save the exact callback and copy the app client ID and secret. Do not request offline_access; Cognito issues refresh tokens through its authorization-code grant.', 'portalweave-sso'),
            ]), 'credentials' => $credentials['cognito']],
            'fields' => ['issuer' => self::field(__('User-pool issuer', 'portalweave-sso'), 'url', __('Copy the exact pool issuer URL, including the pool ID.', 'portalweave-sso'), true, 'location'), 'domain' => self::field(__('Managed-login domain', 'portalweave-sso'), 'url', __('The HTTPS origin of the managed-login or custom domain, without a path.', 'portalweave-sso'), true, 'location'), 'client_id' => $client, 'client_secret' => $secret],
            'notices' => [__('Cognito uses provider-specific logout parameters. This recipe leaves the logout endpoint empty; signing out locally does not promise to end a Cognito or upstream provider session.', 'portalweave-sso'), $verify_notice],
        ];
        $catalog['keycloak'] = [
            'label' => __('Keycloak', 'portalweave-sso'), 'summary' => __('Register a confidential OIDC client in the intended Keycloak realm.', 'portalweave-sso'),
            'docs' => 'https://www.keycloak.org/securing-apps/oidc-layers',
            'steps' => ['location' => $host_step, 'registration' => self::step(__('Create the realm client', 'portalweave-sso'), __('Select the realm, then open Clients → Create client → OpenID Connect.', 'portalweave-sso'), [
                __('Set the client ID and enable Client authentication and Standard Flow. Require PKCE method S256 and keep implicit, direct-access and service-account flows off.', 'portalweave-sso'),
                __('Add the exact callback as a Valid Redirect URI and configure the intended post-logout redirect URI.', 'portalweave-sso'),
                __('Attach the email and profile scopes. Use a real email verification process such as the Verify Email required action.', 'portalweave-sso'),
                __('Under Credentials, select Client ID and Secret and copy the secret.', 'portalweave-sso'),
            ]), 'credentials' => $credentials['keycloak']],
            'fields' => ['host' => array_replace($host, ['help' => __('Use the server base URL before /realms. Preserve a configured base path such as /auth; do not include the administration page path.', 'portalweave-sso')]), 'realm' => self::field(__('Realm', 'portalweave-sso'), 'text', __('Copy the realm identifier. Preserve an actual base path such as /auth in the provider URL; do not add it automatically.', 'portalweave-sso'), true, 'registration'), 'client_id' => $client, 'client_secret' => $secret],
            'notices' => [$verify_notice],
        ];
        $catalog['okta'] = [
            'label' => __('Okta', 'portalweave-sso'), 'summary' => __('Create a web OIDC integration using the org authorization server or an explicitly selected custom server.', 'portalweave-sso'),
            'docs' => 'https://help.okta.com/oie/en-us/content/topics/apps/apps_app_integration_wizard_oidc.htm',
            'steps' => ['location' => $host_step, 'registration' => self::step(__('Create the Okta web integration', 'portalweave-sso'), __('Open Applications and Resources → Applications → Create App Integration.', 'portalweave-sso'), [
                __('Choose the classic OIDC OpenID Connect integration, Web Application and Configure in Okta. Older consoles place this under Applications → Applications.', 'portalweave-sso'),
                __('Enable Authorization Code, register the exact Sign-in and Sign-out redirect URIs, and assign the intended users or groups.', 'portalweave-sso'),
                __('Under General → Client Credentials, select Client secret and copy it. Select Basic client authentication and retain PKCE S256.', 'portalweave-sso'),
            ]), 'credentials' => $credentials['okta']],
            'fields' => ['host' => array_replace($host, ['help' => __('Use the user-facing Okta or custom-domain HTTPS origin, without an administration page or /oauth2 path.', 'portalweave-sso')]), 'server_type' => self::select(__('Authorization server', 'portalweave-sso'), __('The org server is sufficient for ordinary SSO. A custom server requires its own access policy and may need an additional production entitlement.', 'portalweave-sso'), ['org' => __('Org authorization server', 'portalweave-sso'), 'custom' => __('Custom authorization server', 'portalweave-sso')], 'org', 'location'), 'server_id' => self::field(__('Authorization server ID', 'portalweave-sso'), 'text', __('Copy the custom server ID. Use default only if that server is actually configured.', 'portalweave-sso'), true, 'location') + ['when' => ['server_type' => 'custom']], 'client_id' => $client, 'client_secret' => $secret],
            'notices' => [__('An email_verified claim alone does not establish the assurance of the organization verification process. Keep linking and administrator SSO off until you have reviewed that policy and the actual claims.', 'portalweave-sso'), $verify_notice],
        ];
        $catalog['salesforce'] = [
            'label' => __('Salesforce', 'portalweave-sso'), 'summary' => __('Register an External Client App for a production, sandbox or custom Salesforce authority.', 'portalweave-sso'),
            'docs' => 'https://developer.salesforce.com/docs/platform/sfdx-dev/guide/sfdx-dev-auth-eca.html',
            'steps' => ['location' => self::step(__('Choose the Salesforce environment', 'portalweave-sso'), __('Use the environment where your users and application are registered.', 'portalweave-sso'), [__('For a My Domain or Experience Cloud authority, copy the authority and exact issuer from its official metadata rather than assuming that they are the same.', 'portalweave-sso')]), 'registration' => self::step(__('Create an External Client App', 'portalweave-sso'), __('In Setup, open External Client App Manager → New External Client App.', 'portalweave-sso'), [
                __('Enable OAuth, paste the callback shown here, and request the openid, profile and email identity scopes.', 'portalweave-sso'),
                __('Configure ID tokens with standard claims and the expected client-ID audience. Keep Require Secret for Web Server Flow and PKCE enabled.', 'portalweave-sso'),
                __('Save, then open the app Settings → OAuth Settings → Consumer Key and Secret. Complete any verification challenge and copy the key and secret.', 'portalweave-sso'),
                __('New Connected App creation is restricted; use an External Client App for a new integration. Full or API access is unnecessary for basic SSO.', 'portalweave-sso'),
            ]), 'credentials' => $credentials['salesforce']],
            'fields' => ['environment' => self::select(__('Environment', 'portalweave-sso'), __('Choose the authority used by this application.', 'portalweave-sso'), ['production' => __('Production', 'portalweave-sso'), 'sandbox' => __('Sandbox', 'portalweave-sso'), 'custom' => __('My Domain / Experience Cloud', 'portalweave-sso')], 'production', 'location'), 'host' => $host + ['when' => ['environment' => 'custom']], 'issuer' => self::field(__('Exact metadata issuer', 'portalweave-sso'), 'url', __('Copy the issuer from this custom authority metadata.', 'portalweave-sso'), true, 'location') + ['when' => ['environment' => 'custom']], 'client_id' => array_replace($client, ['label' => __('Consumer Key', 'portalweave-sso')]), 'client_secret' => array_replace($secret, ['label' => __('Consumer Secret', 'portalweave-sso')])],
            'notices' => [__('Verify the application ID-token audience and actual email_verified claim. This recipe does not weaken audience or signature checks and does not configure a generic Salesforce logout endpoint.', 'portalweave-sso'), $verify_notice],
        ];
        $catalog['google'] = [
            'label' => __('Google', 'portalweave-sso'), 'summary' => __('Create a Google Web application OAuth client. No tenant or domain input is needed.', 'portalweave-sso'),
            'docs' => 'https://developers.google.com/workspace/guides/create-credentials',
            'steps' => ['registration' => self::step(__('Register the Google web client', 'portalweave-sso'), __('In Google Cloud, choose a project and open Google Auth platform.', 'portalweave-sso'), [
                __('Complete Branding and Audience. Choose Internal only for an eligible Workspace organization; add test users when required for an External application.', 'portalweave-sso'),
                __('Open Clients → Create client → Web application. Add the exact callback shown here under Authorized redirect URIs.', 'portalweave-sso'),
                __('Request only openid, email and profile in Data Access. JavaScript origins are unnecessary for this server-side redirect flow.', 'portalweave-sso'),
                __('Create the client and copy its client ID and secret when they are shown.', 'portalweave-sso'),
            ]), 'credentials' => $credentials['google']],
            'fields' => ['client_id' => $client, 'client_secret' => $secret],
            'notices' => [__('Workspace authorization requires checking the trusted hd claim and organization policy, not merely an email suffix or request hint. Google has no generic OIDC end-session endpoint in this recipe.', 'portalweave-sso'), $verify_notice],
        ];
        $catalog['auth0'] = [
            'label' => __('Auth0', 'portalweave-sso'), 'summary' => __('Connect a Regular Web Application using its tenant or custom domain.', 'portalweave-sso'),
            'docs' => 'https://auth0.com/docs/get-started/auth0-overview/create-applications/regular-web-apps',
            'steps' => ['location' => $host_step, 'registration' => self::step(__('Create the Auth0 Regular Web Application', 'portalweave-sso'), __('Open Applications → Applications → Create Application → Regular Web Applications.', 'portalweave-sso'), [
                __('Use the same tenant or custom domain throughout. Add the callback shown here to Allowed Callback URLs and configure Allowed Logout URLs.', 'portalweave-sso'),
                __('Under Settings or Credentials, copy Client ID and Client Secret and choose Client Secret (Basic).', 'portalweave-sso'),
                __('Keep RS256, OIDC conformity, Authorization Code and PKCE S256. Enable the appropriate connection and save.', 'portalweave-sso'),
            ]), 'credentials' => $credentials['auth0']],
            'fields' => ['host' => array_replace($host, ['help' => __('Use https:// followed by the application Domain or custom domain. Include any regional hostname and omit dashboard paths.', 'portalweave-sso')]), 'client_id' => $client, 'client_secret' => $secret],
            'notices' => [__('The issuer includes its trailing slash. Email verification assurance depends on the enabled database, enterprise or social connection; review that connection before linking users.', 'portalweave-sso'), $verify_notice],
        ];
        $catalog['onelogin'] = [
            'label' => __('OneLogin', 'portalweave-sso'), 'summary' => __('Use the documented public-client None (PKCE) mode of a OneLogin OIDC application.', 'portalweave-sso'),
            'docs' => 'https://developers.onelogin.com/docs/openid-connect/guides/auth-flow-pkce/',
            'steps' => ['location' => $host_step, 'registration' => self::step(__('Create the OneLogin OIDC application', 'portalweave-sso'), __('Open Administration → Applications → Applications → Add App and select OpenId Connect (OIDC) by OneLogin.', 'portalweave-sso'), [
                __('Save the application. Under Configuration, register the exact callback and intended post-logout redirect URI.', 'portalweave-sso'),
                __('On the SSO tab, choose Token Endpoint Authentication Method: None (PKCE), use the v2 issuer and RS256, and assign the intended users or roles.', 'portalweave-sso'),
            ]), 'credentials' => self::step(__('Copy the OneLogin Client ID', 'portalweave-sso'), __('Open this OneLogin application SSO tab. The documented None (PKCE) mode does not use a client secret.', 'portalweave-sso'), [__('Copy Client ID from the SSO tab and keep Token Endpoint Authentication Method set to None (PKCE). Do not copy a client secret into this public-client recipe.', 'portalweave-sso')])],
            'fields' => ['host' => array_replace($host, ['help' => __('Use the tenant or custom-domain HTTPS origin only. Omit /oidc/2 from an issuer URL; the wizard adds it.', 'portalweave-sso')]), 'client_id' => $client],
            'notices' => [__('OneLogin does not natively verify email addresses or emit email_verified by default. Map that claim only from a genuine current-address verification process. Confidential client authentication remains available in the advanced editor.', 'portalweave-sso'), $verify_notice],
        ];
        $catalog['discord'] = [
            'label' => __('Discord', 'portalweave-sso'), 'summary' => __('Connect a Discord OAuth2 application using its verified profile and email claims.', 'portalweave-sso'),
            'docs' => 'https://docs.discord.com/developers/topics/oauth2',
            'steps' => ['registration' => self::step(__('Register the Discord redirect', 'portalweave-sso'), __('In the Discord Developer Portal, create or select an application and open OAuth2 settings.', 'portalweave-sso'), [
                __('Add the exact callback shown here to Redirects and save it.', 'portalweave-sso'),
                __('Use Authorization Code with S256 PKCE and the identify and email scopes. Copy the application Client ID and Client Secret from OAuth2 settings.', 'portalweave-sso'),
                __('Use the OAuth2 client secret, not a bot token. Missing or unverified email remains blocked.', 'portalweave-sso'),
            ]), 'credentials' => $credentials['discord']],
            'fields' => ['client_id' => $client, 'client_secret' => $secret], 'notices' => [$verify_notice],
        ];
        $manual_fields = [
            'authorize_url' => self::field(__('Authorization endpoint', 'portalweave-sso'), 'url', __('Copy the HTTPS authorization-code endpoint from the provider documentation.', 'portalweave-sso'), true, 'registration'),
            'token_url' => self::field(__('Token endpoint', 'portalweave-sso'), 'url', __('Copy the HTTPS token endpoint. It must return a Bearer access token.', 'portalweave-sso'), true, 'registration'),
            'scope' => self::field(__('Scopes', 'portalweave-sso'), 'text', __('Use the documented space-separated identity scopes. OIDC requires openid.', 'portalweave-sso'), true, 'registration'),
            'client_id' => $client,
            'credential_method' => self::select(__('Client authentication', 'portalweave-sso'), __('Match the application token-endpoint setting. Public clients must support S256 PKCE.', 'portalweave-sso'), ['header' => __('HTTP Basic', 'portalweave-sso'), 'body' => __('Client secret in request body', 'portalweave-sso'), 'none' => __('None (public client with PKCE)', 'portalweave-sso')], 'header', 'credentials'),
            'client_secret' => array_replace($secret, ['help' => __('Required for HTTP Basic or request-body client authentication. Leave blank only for a documented public client with PKCE.', 'portalweave-sso'), 'when' => ['credential_method' => ['header', 'body']]]),
        ];
        $catalog['classlink'] = [
            'label' => __('ClassLink', 'portalweave-sso'), 'summary' => __('Use current connection details supplied through the ClassLink Partner Portal.', 'portalweave-sso'),
            'docs' => 'https://help.classlink.com/s/article/pp-about-oc-rs',
            'steps' => ['registration' => self::step(__('Register the partner connection', 'portalweave-sso'), __('Obtain Partner Portal access and follow its current OAuth2 application integration instructions.', 'portalweave-sso'), [
                __('Register the callback shown here with the partner application. ClassLink has changed authentication endpoints; copy the current authorization, token and JSON profile endpoints from the portal.', 'portalweave-sso'),
                __('Confirm Authorization Code, S256 PKCE, Bearer profile access, the immutable subject, email and true mailbox-verification claims with ClassLink. Do not substitute guessed legacy endpoints.', 'portalweave-sso'),
                __('If the partner contract uses a different protocol or unsupported verification format, use the advanced editor after reviewing the integration requirements.', 'portalweave-sso'),
            ]), 'credentials' => $credentials['classlink']],
            'fields' => $manual_fields + [
                'userinfo_url' => self::field(__('JSON profile endpoint', 'portalweave-sso'), 'url', __('Must support HTTPS GET with Authorization: Bearer and return the documented profile object.', 'portalweave-sso'), true, 'registration'),
                'subject_attribute' => self::field(__('Immutable subject path', 'portalweave-sso'), 'text', __('Copy the stable user identifier path from the current partner contract.', 'portalweave-sso'), true, 'registration'),
                'email_attribute' => self::field(__('Email claim path', 'portalweave-sso'), 'text', __('Copy the scalar email path in the profile object.', 'portalweave-sso'), true, 'registration'),
                'verified_attribute' => self::field(__('Verified-email claim path', 'portalweave-sso'), 'text', __('The current-address verification claim must actually be true; never map a user identifier or email address as verification.', 'portalweave-sso'), true, 'registration'),
                'issuer' => self::field(__('Authorization response issuer', 'portalweave-sso'), 'url', __('Copy the exact issuer if the authorization response supplies iss; otherwise leave empty.', 'portalweave-sso'), false, 'registration'),
            ],
            'notices' => [__('Current partner endpoints, authentication and verification details are not publicly established by this plugin. This guided manual mode requires a documented contract; it does not assert that older presets still work.', 'portalweave-sso'), $verify_notice],
        ];
        $catalog['custom'] = [
            'label' => __('Custom OAuth / OpenID Connect', 'portalweave-sso'), 'summary' => __('Copy authoritative connection details for an OIDC or OAuth2 provider.', 'portalweave-sso'),
            'docs' => 'https://openid.net/specs/openid-connect-discovery-1_0.html',
            'steps' => ['registration' => self::step(__('Register your web application', 'portalweave-sso'), __('Use the application registration and metadata documentation maintained by your provider.', 'portalweave-sso'), [
                __('Register the callback shown here exactly and choose Authorization Code with S256 PKCE.', 'portalweave-sso'),
                __('For OIDC, copy the exact issuer and trusted JWKS URL, request openid, and configure RS256 ID tokens. For OAuth2, obtain a Bearer JSON profile endpoint and an immutable subject path.', 'portalweave-sso'),
                __('This wizard does not fetch metadata or trust key URLs supplied by a token. Nonstandard flows and advanced claim mappings remain available in the provider editor.', 'portalweave-sso'),
            ]), 'credentials' => $credentials['custom']],
            'fields' => ['protocol' => self::select(__('Protocol', 'portalweave-sso'), __('Use the protocol documented by your provider.', 'portalweave-sso'), ['oidc' => __('OpenID Connect', 'portalweave-sso'), 'oauth2' => __('OAuth 2.0', 'portalweave-sso')], 'oidc', 'registration')] + $manual_fields + [
                'issuer' => self::field(__('Exact issuer', 'portalweave-sso'), 'url', __('Required for OIDC. For OAuth2, provide the exact issuer when the authorization response supplies iss. Preserve any trailing slash.', 'portalweave-sso'), false, 'registration'),
                'jwks_url' => self::field(__('Trusted JWKS URL', 'portalweave-sso'), 'url', __('Copy jwks_uri from the trusted metadata. The wizard pins RS256.', 'portalweave-sso'), true, 'registration') + ['when' => ['protocol' => 'oidc']],
                'userinfo_url' => self::field(__('JSON profile endpoint', 'portalweave-sso'), 'url', __('Must support Bearer HTTPS GET and supply the immutable subject and email.', 'portalweave-sso'), true, 'registration') + ['when' => ['protocol' => 'oauth2']],
                'subject_attribute' => self::field(__('Immutable subject path', 'portalweave-sso'), 'text', __('Use a stable identifier from the OAuth2 profile, not email or a mutable username.', 'portalweave-sso'), true, 'registration') + ['when' => ['protocol' => 'oauth2']],
            ], 'notices' => [$verify_notice],
        ];
        $catalog['custom']['fields']['scope']['default'] = 'openid profile email';
        uasort($catalog, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        return $catalog;
    }

    /** Convert one creation form into an ordinary app. Authorization and persistence belong to the caller. */
    public static function prepare(array $input)
    {
        foreach ($input as $value) {
            if (!is_string($value)) {
                return self::error(__('Setup fields must be scalar strings.', 'portalweave-sso'));
            }
        }
        $catalog = self::catalog();
        $provider = $input['provider'] ?? '';
        if (!isset($catalog[$provider])) {
            return self::error(__('Choose a supported provider.', 'portalweave-sso'));
        }
        $recipe = $catalog[$provider];
        if (array_diff(array_keys($input), array_merge(['id', 'provider', 'name', 'callback_registered'], array_keys($recipe['fields'])))) {
            return self::error(__('The setup form contains unexpected fields.', 'portalweave-sso'));
        }
        $id = $input['id'] ?? '';
        if ($id === '0' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/D', $id)) {
            return self::error(__('Enter a unique app ID using lowercase letters, digits, underscores or dashes.', 'portalweave-sso'));
        }
        if (($input['callback_registered'] ?? '') !== '1') {
            return self::error(__('Confirm that the exact callback URL has been registered at the provider.', 'portalweave-sso'));
        }
        if (strlen($input['name'] ?? '') > 200) {
            return self::error(__('The display name is too long.', 'portalweave-sso'));
        }
        $values = [];
        foreach ($recipe['fields'] as $key => $field) {
            $values[$key] = $input[$key] ?? ($field['default'] ?? '');
        }
        foreach ($recipe['fields'] as $key => $field) {
            $active = true;
            foreach ($field['when'] ?? [] as $dependency => $expected) {
                $actual = $values[$dependency] ?? '';
                $active = $active && (is_array($expected) ? in_array($actual, $expected, true) : $actual === $expected);
            }
            if (!$active) {
                if ($key === 'client_secret' && $values[$key] !== '') {
                    return self::error(__('Public-client setup must not submit a client secret.', 'portalweave-sso'));
                }
                $values[$key] = '';
                continue;
            }
            $value = $values[$key];
            if (strlen($value) > ($field['type'] === 'password' ? 65536 : 2048) || ($field['required'] && $value === '')) {
                return self::error(__('Complete the required provider fields within their size limits.', 'portalweave-sso'));
            }
            if ($field['type'] === 'password') {
                continue; // Credentials are opaque bytes: do not trim or sanitize them.
            }
            if (preg_match('/[\x00-\x1f\x7f]|YOUR[_ -]|[<>{}]/i', $value)) {
                return self::error(__('Replace placeholders with the actual provider values and remove invalid characters.', 'portalweave-sso'));
            }
            if ($field['type'] === 'select' && !array_key_exists($value, $field['options'])) {
                return self::error(__('Choose one of the documented setup options.', 'portalweave-sso'));
            }
            if ($field['type'] === 'url' && $value !== '' && self::https($value) === '') {
                return self::error(__('Provider addresses must be absolute HTTPS URLs without credentials, query strings or fragments.', 'portalweave-sso'));
            }
        }
        $app = Config::app_defaults();
        $app['id'] = $id;
        $app['provider'] = $provider;
        $app['name'] = trim($input['name'] ?? '') !== '' ? sanitize_text_field($input['name']) : $recipe['label'];
        $app['client_id'] = $values['client_id'];
        $app['client_secret'] = $values['client_secret'] ?? '';
        if ($app['client_secret'] === '********') {
            return self::error(__('Enter the actual client secret rather than a stored-value placeholder.', 'portalweave-sso'));
        }
        $app['credential_method'] = $values['credential_method'] ?? 'header';
        if ($provider !== 'onelogin' && $app['credential_method'] !== 'none' && $app['client_secret'] === '') {
            return self::error(__('This client authentication method requires a client secret.', 'portalweave-sso'));
        }
        if ($app['credential_method'] === 'none' && $app['client_secret'] !== '') {
            return self::error(__('Public-client setup must not submit a client secret.', 'portalweave-sso'));
        }
        if (in_array($provider, ['entra', 'office365'], true)) {
            if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $values['tenant_id'])) {
                return self::error(__('Enter the Directory tenant UUID, not a shared tenant alias.', 'portalweave-sso'));
            }
            $base = 'https://login.microsoftonline.com/' . strtolower($values['tenant_id']);
            $app = array_replace($app, ['issuer' => $base . '/v2.0', 'authorize_url' => $base . '/oauth2/v2.0/authorize', 'token_url' => $base . '/oauth2/v2.0/token', 'jwks_url' => $base . '/discovery/v2.0/keys', 'userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo', 'logout_url' => $base . '/oauth2/v2.0/logout', 'credential_method' => 'body']);
        } elseif ($provider === 'b2c') {
            if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $values['client_id'])) {
                return self::error(__('Copy the B2C Application (client) ID UUID from the application Overview.', 'portalweave-sso'));
            }
            $authority = rtrim($values['authority'], '/');
            if (!preg_match('~^https://([a-z0-9-]+)\.b2clogin\.com/([a-z0-9-]+)\.onmicrosoft\.com/([a-zA-Z0-9_-]+)$~D', $authority, $parts) || $parts[1] !== $parts[2] || !Redirect::same_origin($authority, $values['issuer'])) {
                return self::error(__('Use the matching B2C tenant policy authority and its exact metadata issuer.', 'portalweave-sso'));
            }
            $app = array_replace($app, ['issuer' => $values['issuer'], 'authorize_url' => $authority . '/oauth2/v2.0/authorize', 'token_url' => $authority . '/oauth2/v2.0/token', 'jwks_url' => $authority . '/discovery/v2.0/keys', 'logout_url' => $authority . '/oauth2/v2.0/logout', 'scope' => 'openid ' . $values['client_id'], 'credential_method' => 'body']);
            $app['mapping']['email'] = 'emails.0';
        } elseif ($provider === 'cognito') {
            if (!preg_match('~^https://(?:issuer-)?cognito-idp\.[a-z0-9-]+\.amazonaws\.com(?:\.cn)?/[a-z0-9-]+_[a-zA-Z0-9]+$~D', $values['issuer']) || self::https($values['domain'], true) === '') {
                return self::error(__('Enter the exact Cognito pool issuer and the managed-login domain origin.', 'portalweave-sso'));
            }
            $base = rtrim($values['domain'], '/');
            $app = array_replace($app, ['issuer' => $values['issuer'], 'authorize_url' => $base . '/oauth2/authorize', 'token_url' => $base . '/oauth2/token', 'userinfo_url' => $base . '/oauth2/userInfo', 'jwks_url' => $values['issuer'] . '/.well-known/jwks.json']);
            $app['mapping']['username'] = 'cognito:username';
        } elseif ($provider === 'google') {
            $app = array_replace($app, ['issuer' => 'https://accounts.google.com', 'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_url' => 'https://oauth2.googleapis.com/token', 'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo', 'jwks_url' => 'https://www.googleapis.com/oauth2/v3/certs', 'credential_method' => 'body']);
        } elseif ($provider === 'discord') {
            $app = array_replace($app, ['protocol' => 'oauth2', 'issuer' => 'https://discord.com', 'scope' => 'identify email', 'authorize_url' => 'https://discord.com/oauth2/authorize', 'token_url' => 'https://discord.com/api/oauth2/token', 'userinfo_url' => 'https://discord.com/api/users/@me', 'subject_attribute' => 'id', 'verified_attribute' => 'verified']);
            $app['mapping'] = ['username' => 'username', 'email' => 'email', 'first_name' => '', 'last_name' => '', 'display_name' => 'global_name', 'groups' => ''];
        } elseif (in_array($provider, ['keycloak', 'authentik'], true)) {
            $base = rtrim($values['host'], '/');
            $component = self::component($values[$provider === 'keycloak' ? 'realm' : 'application']);
            if ($component === '') {
                return self::error(__('Enter a single valid realm or application slug, without URL separators or placeholders.', 'portalweave-sso'));
            }
            if ($provider === 'keycloak') {
                $issuer = $base . '/realms/' . $component;
                $prefix = $issuer . '/protocol/openid-connect/';
                $app = array_replace($app, ['issuer' => $issuer, 'authorize_url' => $prefix . 'auth', 'token_url' => $prefix . 'token', 'userinfo_url' => $prefix . 'userinfo', 'jwks_url' => $prefix . 'certs', 'logout_url' => $prefix . 'logout']);
            } else {
                $issuer = $base . '/application/o/' . $component . '/';
                $app = array_replace($app, ['issuer' => $issuer, 'authorize_url' => $base . '/application/o/authorize/', 'token_url' => $base . '/application/o/token/', 'userinfo_url' => $base . '/application/o/userinfo/', 'jwks_url' => $issuer . 'jwks/', 'logout_url' => $issuer . 'end-session/']);
            }
        } elseif (in_array($provider, ['auth0', 'okta', 'onelogin'], true)) {
            if (self::https($values['host'], true) === '') {
                return self::error(__('Use the provider HTTPS origin without an application path.', 'portalweave-sso'));
            }
            $base = rtrim($values['host'], '/');
            if ($provider === 'auth0') {
                $app = array_replace($app, ['issuer' => $base . '/', 'authorize_url' => $base . '/authorize', 'token_url' => $base . '/oauth/token', 'userinfo_url' => $base . '/userinfo', 'jwks_url' => $base . '/.well-known/jwks.json', 'logout_url' => $base . '/oidc/logout']);
            } elseif ($provider === 'onelogin') {
                $issuer = $base . '/oidc/2';
                $app = array_replace($app, ['issuer' => $issuer, 'authorize_url' => $issuer . '/auth', 'token_url' => $issuer . '/token', 'userinfo_url' => $issuer . '/me', 'jwks_url' => $issuer . '/certs', 'logout_url' => $issuer . '/logout', 'credential_method' => 'none']);
            } else {
                $issuer = $base;
                $prefix = $base . '/oauth2/v1/';
                if ($values['server_type'] === 'custom') {
                    $server = self::component($values['server_id']);
                    if ($server === '') {
                        return self::error(__('Enter a valid custom authorization server ID.', 'portalweave-sso'));
                    }
                    $issuer .= '/oauth2/' . $server;
                    $prefix = $issuer . '/v1/';
                }
                $app = array_replace($app, ['issuer' => $issuer, 'authorize_url' => $prefix . 'authorize', 'token_url' => $prefix . 'token', 'userinfo_url' => $prefix . 'userinfo', 'jwks_url' => $prefix . 'keys', 'logout_url' => $prefix . 'logout']);
            }
        } elseif ($provider === 'salesforce') {
            $base = $values['environment'] === 'sandbox' ? 'https://test.salesforce.com' : 'https://login.salesforce.com';
            $issuer = $base;
            if ($values['environment'] === 'custom') {
                if (self::https($values['host'], true) === '') {
                    return self::error(__('Use the custom Salesforce HTTPS authority origin.', 'portalweave-sso'));
                }
                $base = rtrim($values['host'], '/');
                $issuer = $values['issuer'];
            }
            $app = array_replace($app, ['issuer' => $issuer, 'authorize_url' => $base . '/services/oauth2/authorize', 'token_url' => $base . '/services/oauth2/token', 'userinfo_url' => $base . '/services/oauth2/userinfo', 'jwks_url' => $base . '/id/keys']);
        } else {
            $app['protocol'] = $provider === 'classlink' ? 'oauth2' : $values['protocol'];
            foreach (['authorize_url', 'token_url', 'scope', 'issuer', 'jwks_url', 'userinfo_url', 'subject_attribute'] as $key) {
                if (isset($values[$key]) && $values[$key] !== '') { $app[$key] = $values[$key]; }
            }
            if ($app['protocol'] === 'oidc' && ($app['issuer'] === '' || !in_array('openid', explode(' ', $app['scope']), true))) {
                return self::error(__('OpenID Connect setup requires the exact issuer and the openid scope.', 'portalweave-sso'));
            }
            if ($provider === 'classlink') {
                $app['mapping']['username'] = $values['subject_attribute'];
                $app['mapping']['email'] = $values['email_attribute'];
                $app['verified_attribute'] = $values['verified_attribute'];
            }
        }
        // Match Config::save() before a draft can be tested. Secrets remain opaque.
        foreach (['client_id', 'scope', 'subject_attribute', 'verified_attribute'] as $key) {
            $app[$key] = sanitize_text_field($app[$key]);
        }
        if ($app['client_id'] === '') {
            return self::error(__('Enter the provider client ID.', 'portalweave-sso'));
        }
        return $app;
    }

    private static function https(string $value, bool $origin = false): string
    {
        if ($value !== trim($value) || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) { return ''; }
        $parts = wp_parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) { return ''; }
        if (!filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && !filter_var($parts['host'], FILTER_VALIDATE_IP)) { return ''; }
        $path = $parts['path'] ?? '';
        if ($origin && $path !== '' && $path !== '/') { return ''; }
        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ($segment === '.' || $segment === '..' || preg_match('/[\x00-\x20\x7f\\\\]/', $segment)) { return ''; }
        }
        return $value;
    }

    private static function component(string $value): string
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}$/D', $value) && !in_array($value, ['.', '..'], true) ? rawurlencode($value) : '';
    }

    private static function field(string $label, string $type, string $help, bool $required, string $step, string $placeholder = ''): array
    {
        return ['label' => $label, 'type' => $type, 'help' => $help, 'required' => $required, 'step' => $step] + ($placeholder !== '' ? ['placeholder' => $placeholder] : []);
    }

    private static function select(string $label, string $help, array $options, string $default, string $step): array
    {
        return self::field($label, 'select', $help, true, $step) + ['options' => $options, 'default' => $default];
    }

    private static function step(string $title, string $description, array $instructions): array
    {
        return ['title' => $title, 'description' => $description, 'instructions' => $instructions];
    }

    private static function error(string $message): \WP_Error
    {
        return new \WP_Error('portalweave_sso_provider_setup', $message);
    }
}
