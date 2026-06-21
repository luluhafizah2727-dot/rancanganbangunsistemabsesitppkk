#!/usr/bin/env bash
set -euo pipefail

if [[ "$EUID" -ne 0 ]]; then
  echo "Jalankan script ini sebagai root atau melalui sudo."
  exit 1
fi

. /etc/os-release
if [[ "$ID" != "ubuntu" ]]; then
  echo "Script bootstrap hanya mendukung Ubuntu."
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl git gnupg unzip zip software-properties-common cron apache2 apache2-utils mariadb-server mariadb-client redis-server

if ! apt-cache show php8.3-fpm >/dev/null 2>&1; then
  add-apt-repository -y ppa:ondrej/php
  apt-get update
fi

apt-get install -y \
  php8.3 php8.3-cli php8.3-fpm php8.3-common php8.3-bcmath php8.3-curl \
  php8.3-gd php8.3-intl php8.3-mbstring php8.3-mysql php8.3-opcache \
  php8.3-redis php8.3-soap php8.3-xml php8.3-zip

echo 'phpmyadmin phpmyadmin/dbconfig-install boolean false' | debconf-set-selections
echo 'phpmyadmin phpmyadmin/reconfigure-webserver multiselect' | debconf-set-selections
apt-get install -y phpmyadmin

update-alternatives --set php /usr/bin/php8.3

install -d -m 0755 /etc/apt/keyrings
curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_24.x nodistro main" > /etc/apt/sources.list.d/nodesource.list
apt-get update
apt-get install -y nodejs
npm install --global pnpm@11.8.0

curl -fsSL https://pkg.cloudflare.com/cloudflare-public-v2.gpg -o /usr/share/keyrings/cloudflare-public-v2.gpg
echo "deb [signed-by=/usr/share/keyrings/cloudflare-public-v2.gpg] https://pkg.cloudflare.com/cloudflared any main" > /etc/apt/sources.list.d/cloudflared.list
apt-get update
apt-get install -y cloudflared

if ! command -v composer >/dev/null 2>&1; then
  expected_signature="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  actual_signature="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  [[ "$expected_signature" == "$actual_signature" ]] || { rm -f /tmp/composer-setup.php; echo "Signature Composer tidak cocok."; exit 1; }
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

a2enmod rewrite headers proxy proxy_http proxy_fcgi proxy_wstunnel setenvif expires deflate
systemctl enable --now apache2 php8.3-fpm mariadb redis-server cron

echo "Requirement Ubuntu selesai dipasang."
