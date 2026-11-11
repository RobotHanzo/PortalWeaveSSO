import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const read = file => readFileSync(path.join(root, file), 'utf8');

test('directory policy: runtime cannot accept or render retired custom button CSS', () => {
  for (const file of readdirSync(path.join(root, 'includes')).filter(file => file.endsWith('.php'))) {
    assert.doesNotMatch(read(`includes/${file}`), /custom_css|Custom button CSS/, file);
  }
});

test('directory policy: authentication salts stay inside the existing encryption boundary', () => {
  for (const file of readdirSync(path.join(root, 'includes')).filter(file => file.endsWith('.php'))) {
    const source = read(`includes/${file}`);
    // Existing credential encryption remains salt-bound for ciphertext compatibility.
    // No storage name, identifier, log or external request may derive from these values.
    const boundary = file === 'class-config.php' ? source.slice(source.indexOf('final class Config')) : source;
    assert.doesNotMatch(boundary, /\bwp_salt\s*\(|\b(?:AUTH|SECURE_AUTH|LOGGED_IN|NONCE)_(?:KEY|SALT)\b/, file);
  }
});

test('directory policy: every registered shortcode has the plugin prefix', () => {
  const tags = readdirSync(path.join(root, 'includes')).flatMap(file => file.endsWith('.php')
    ? [...read(`includes/${file}`).matchAll(/\badd_shortcode\s*\(\s*'([^']+)'/g)].map(match => match[1]) : []);
  assert.ok(tags.includes('portalweave_sso_login'));
  assert.ok(tags.every(tag => tag.startsWith('portalweave_sso_')), tags.join(', '));
});

test('directory policy: provider presets have listing disclosures and official policy references', () => {
  const readme = read('readme.txt').split('== External services ==')[1]?.split(/\n== /)[0];
  assert.ok(readme, 'The distributed listing needs its own external services section');
  const disclosures = {
    entra: ['Microsoft Entra', 'https://www.microsoft.com/en-us/privacy/privacystatement', 'https://www.microsoft.com/licensing/terms/'],
    b2c: ['Azure AD B2C', 'https://www.microsoft.com/en-us/privacy/privacystatement', 'https://www.microsoft.com/licensing/terms/'],
    office365: ['Office 365', 'https://www.microsoft.com/en-us/privacy/privacystatement', 'https://www.microsoft.com/licensing/terms/'],
    cognito: ['Amazon Cognito', 'https://aws.amazon.com/privacy/', 'https://aws.amazon.com/service-terms/'],
    google: ['Google', 'https://policies.google.com/privacy', 'https://developers.google.com/terms'],
    okta: ['Okta', 'https://www.okta.com/legal/privacy-policy/', 'https://www.okta.com/legal/'],
    auth0: ['Auth0', 'https://www.okta.com/legal/privacy-policy/', 'https://auth0.com/legal'],
    salesforce: ['Salesforce', 'https://www.salesforce.com/company/legal/privacy/', 'https://www.salesforce.com/company/legal/customer-agreements/'],
    onelogin: ['OneLogin', 'https://www.oneidentity.com/legal/privacy.aspx', 'https://www.oneidentity.com/legal/'],
    discord: ['Discord', 'https://discord.com/privacy', 'https://support-dev.discord.com/hc/en-us/articles/8562894815383-Discord-Developer-Terms-of-Service'],
    classlink: ['ClassLink', 'https://www.classlink.com/company/privacy', 'https://www.classlink.com/contact'],
    keycloak: ['Keycloak', 'operator terms/privacy policy'],
    authentik: ['authentik', 'operator terms/privacy policy'],
    custom: ['custom providers', 'operator terms/privacy policy'],
  };
  const presets = [...read('assets/admin.js').split('const presets = {')[1].split('\n  };')[0].matchAll(/^    (\w+):/gm)].map(match => match[1]);
  assert.ok(presets.length >= 12, 'Failed to discover provider presets');
  // The server catalog/logo map also covers providers added without a browser preset.
  const server = read('includes/class-provider-setup.php').split('$files = [')[1].split('];')[0];
  const providers = new Set([...presets, ...[...server.matchAll(/'([^']+)'\s*=>/g)].map(match => match[1])]);
  for (const provider of providers) {
    assert.ok(disclosures[provider], `Add and review the disclosure for new provider ${provider}`);
    for (const text of disclosures[provider]) assert.ok(readme.includes(text), `${provider}: missing ${text}`);
  }
  for (const text of ['authorization code', 'access token', 'refresh token', 'username/password', 'signing-key', 'logout', 'SCIM', 'MCP', 'image hosts', 'mail service']) {
    assert.ok(readme.includes(text), `Missing data flow: ${text}`);
  }
});

test('directory policy: public repository documentation links use main and resolve to shipped source', () => {
  for (const file of ['readme.txt', 'README.md', 'docs/external-services.md']) {
    const links = [...read(file).matchAll(/https:\/\/github\.com\/RobotHanzo\/PortalWeaveSSO\/blob\/([^\s)]+)/g)];
    for (const [, target] of links) {
      assert.ok(target.startsWith('main/'), `${file}: stale branch link ${target}`);
      assert.ok(existsSync(path.join(root, target.slice(5).split('#')[0])), `${file}: missing linked file ${target}`);
    }
    if (file === 'readme.txt') assert.ok(links.some(([, target]) => target === 'main/docs/external-services.md'));
  }
});
