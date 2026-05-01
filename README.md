# salesdesk
SalesDesk connects independent auto brokers, dealerships, and their sales teams through a trackable, commission-first sales channel. Every car listed, every link shared, and every lead submitted flows through a transparent attribution engine — so brokers get paid for deals they source, dealers get qualified leads.
# SalesDesk ZA — Developer Setup

South Africa's automotive sales intermediary platform.

## Stack

- PHP 8.2 + PDO (MySQL / MariaDB)
- PHPMailer (SMTP)
- Redis sessions (required before load testing)
- Vanilla JS — no jQuery, no framework

## First-time setup

### 1. Database

```bash
# Create database and user
mysql -u root -p <<SQL
CREATE DATABASE salesdesk_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'salesdesk_user'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON salesdesk_db.* TO 'salesdesk_user'@'localhost';
FLUSH PRIVILEGES;
SQL

# Run migrations in order
mysql -u salesdesk_user -p salesdesk_db < db/0002_initial_schema.sql
mysql -u salesdesk_user -p salesdesk_db < db/0003_sales_executives.sql
mysql -u salesdesk_user -p salesdesk_db < db/0004_indexes.sql
mysql -u salesdesk_user -p salesdesk_db < db/0005_addenda.sql

# Load seed data (dev only — never in production)
mysql -u salesdesk_user -p salesdesk_db < db/seed.sql
```

### 2. Configuration

```bash
cp includes/config.example.php includes/config.php
# Edit includes/config.php — fill in all <-- CHANGE values
```

### 3. PHPMailer

```bash
composer require phpmailer/phpmailer
# or manually place in vendor/PHPMailer/
```

### 4. Redis (required before load testing)

```bash
# Install Redis
sudo apt install redis-server php-redis

# In php.ini:
# session.save_handler = redis
# session.save_path = "tcp://127.0.0.1:6379?prefix=salesdesk_sess_"
```

### 5. Web server

```apache
# Apache .htaccess (web root = public/)
RewriteEngine On
RewriteRule ^c/([^/]+)/?$ /c/$1/index.php [L]
RewriteRule ^([a-z0-9-]+)/?$ /broker/$1/index.php [L]
```

## Test accounts (after seed.sql)

All passwords: `Password1!`

| Email | Role |
|-------|------|
| admin@salesdesk.co.za | Admin |
| broker1@example.com | Broker (org owner) |
| broker2@example.com | Broker (solo) |
| dealer@cardealers.co.za | Dealer principal |
| exec1@cardealers.co.za | Sales exec (verified) |
| exec2@cardealers.co.za | Sales exec (pending) |

## Team ownership

| Team | Responsibility | Owns |
|------|---------------|------|
| **T1** | Platform core, infrastructure | `includes/`, `db/`, `views/layout-app.php`, `cron/`, `app/admin/` |
| **T2** | Auth & onboarding wizards | `auth/` |
| **T3** | Dealer & exec portal | `app/dealer/`, `app/exec/` |
| **T4** | Broker experience & public pages | `app/broker/`, `public/c/`, `api/` |

**T1 is merge gatekeeper** for `includes/`, `db/`, and global CSS/JS files.

## Cron setup

```cron
# Nudge check — daily at 08:00
0 8 * * * php /var/www/salesdesk/cron/nudge-check.php >> /var/log/salesdesk/nudge.log 2>&1

# POPIA scrub — weekly Sunday 02:00
0 2 * * 0 php /var/www/salesdesk/cron/popia-scrub.php >> /var/log/salesdesk/popia.log 2>&1
```

## Architecture reference

See `salesdesk_architecture_v3.html` for the complete team coordination framework.
See `SHARED_CHANGELOG.md` for all shared function additions.

## Before going live

- [ ] Encrypt `bank_accounts.account_number` (known plaintext gap)
- [ ] Set `popia_retention_days` in platform_config with legal team sign-off  
- [ ] Enable Redis sessions (`USE_REDIS_SESSIONS = true`)
- [ ] Set `APP_DEBUG = false` in config.php
- [ ] Run a full load test under Redis session handler
- [ ] Confirm CIPC verification admin flow works end-to-end
- [ ] Never run `db/seed.sql` in production
