# Deployment Guide

## Prerequisites

- PHP 8.1+ with `pdo_pgsql` extension enabled
- Apache or Nginx with PHP-FPM
- Access to the FusionPBX PostgreSQL database (local or network)
- A FusionPBX server already running (PHP runtime is guaranteed present)

---

## 1. Create a read-only database user

Run this on the FusionPBX server as the PostgreSQL superuser (usually `postgres`):

```bash
sudo -u postgres psql fusionpbx
```

```sql
CREATE USER phoneus_bridge WITH PASSWORD 'strong_random_password';
GRANT CONNECT ON DATABASE fusionpbx TO phoneus_bridge;
GRANT USAGE ON SCHEMA public TO phoneus_bridge;
GRANT SELECT ON v_xml_cdr TO phoneus_bridge;
```

Use a strong random password — store it for the next step.

---

## 2. Deploy the application

```bash
git clone <repo-url> /var/www/fusionpbx-bridge
```

No `composer install`. No build step. That's it.

---

## 3. Configure the application

```bash
cd /var/www/fusionpbx-bridge
cp config.example.php config.php
```

Generate an API key:

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Edit `config.php` and fill in the values:

```php
<?php
return [
    'api_key' => '<output from above>',
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 5432,
        'name'     => 'fusionpbx',
        'user'     => 'phoneus_bridge',
        'password' => '<password from step 1>',
    ],
];
```

`config.php` is gitignored and never committed. Keep a copy in your secrets manager.

---

## 4. Set file permissions

```bash
chown -R www-data:www-data /var/www/fusionpbx-bridge
chmod 640 /var/www/fusionpbx-bridge/config.php
```

---

## 5. Configure the web server

### Apache

Point the virtual host document root at the repo root and place a `.htaccess` there:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ public/index.php [QSA,L]
```

Or point document root directly at `public/` and use:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

### Nginx

```nginx
server {
    listen 443 ssl;
    server_name pbx1.example.com;

    root /var/www/fusionpbx-bridge/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

Adjust the PHP-FPM socket path to match your PHP version (`php8.1-fpm.sock`, `php8.2-fpm.sock`, etc.).

---

## 6. PHP configuration

Ensure the following in `php.ini` (or a drop-in under `/etc/php/X.Y/fpm/conf.d/`):

```ini
display_errors = Off
log_errors = On
```

Errors are written via `error_log()` — check your PHP error log for diagnostics (`/var/log/php8.x-fpm.log` or the virtualhost error log).

---

## 7. Verify the deployment

```bash
curl -s -H "Authorization: Bearer <api_key>" https://pbx1.example.com/api/health
```

Expected response:

```json
{
  "status": "ok",
  "database": "connected",
  "server_time": "2026-05-01T09:00:00+10:00"
}
```

If `"database": "unavailable"` is returned, check:

- PostgreSQL is running: `systemctl status postgresql`
- The `phoneus_bridge` user credentials are correct
- `pg_hba.conf` allows the connection from `127.0.0.1`

---

## 8. Register the server in Phoneus

In Phoneus → **Billing Settings → FusionPBX Servers**, add:

- **Base URL** — e.g. `https://pbx1.example.com` (no trailing slash)
- **API Key** — the value from `config.php`
- **Domain mappings** — each FusionPBX domain mapped to its Phoneus customer account

---

## Security checklist

- [ ] `config.php` is not web-accessible (it lives above `public/`)
- [ ] `display_errors = Off` in php.ini
- [ ] HTTPS only — use Let's Encrypt or a self-signed cert (configure Phoneus accordingly)
- [ ] Firewall: restrict port 443 to the Phoneus server IP only — the bridge has no need to be publicly accessible
- [ ] `phoneus_bridge` DB user has `SELECT` on `v_xml_cdr` only — verify with `\dp v_xml_cdr` in psql
- [ ] API key is at least 64 hex characters (output of `bin2hex(random_bytes(32))`)

---

## Updating

```bash
cd /var/www/fusionpbx-bridge
git pull
```

No migrations, no build step, no restart required (PHP-FPM picks up file changes automatically).
