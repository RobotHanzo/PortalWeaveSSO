#!/usr/bin/env bash
# Disposable compose.test.yml environment only.
set -euo pipefail
php -r 'require "/var/www/html/wp-load.php"; if (get_option("blogname") !== "PortalWeave SSO Integration") { fwrite(STDERR, "Refusing TLS fixture outside the integration site.\n"); exit(2); }'
if ! grep -q "PORTALWEAVE_SSO_TEST_IDP" /var/www/html/wp-config.php; then
  sed -i "/^require_once ABSPATH.*wp-settings\.php/i define('PORTALWEAVE_SSO_TEST_IDP', true);" /var/www/html/wp-config.php
fi
mkdir -p /etc/apache2/portalweave-sso-test-tls /var/www/html/wp-content/mu-plugins
if [ ! -f /etc/apache2/portalweave-sso-test-tls/localhost.key ]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 3 \
    -keyout /etc/apache2/portalweave-sso-test-tls/localhost.key \
    -out /etc/apache2/portalweave-sso-test-tls/localhost.crt \
    -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' >/dev/null 2>&1
  chmod 600 /etc/apache2/portalweave-sso-test-tls/localhost.key
fi
cat > /etc/apache2/sites-available/portalweave-sso-test-tls.conf <<'APACHE'
<VirtualHost *:443>
    ServerName localhost
    DocumentRoot /var/www/html
    SSLEngine on
    SSLCertificateFile /etc/apache2/portalweave-sso-test-tls/localhost.crt
    SSLCertificateKeyFile /etc/apache2/portalweave-sso-test-tls/localhost.key
    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
APACHE
cp /var/www/html/wp-content/plugins/portalweave-sso/tests/local-idp.php /var/www/html/wp-content/mu-plugins/portalweave-sso-test-idp.php
a2enmod ssl >/dev/null
a2ensite portalweave-sso-test-tls >/dev/null
apache2ctl configtest
apache2ctl graceful
printf '%s\n' 'Local-only test TLS ready. Run tests/setup-e2e.php, then node tests/e2e.mjs.'
