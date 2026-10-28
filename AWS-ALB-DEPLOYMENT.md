# AWS ALB Deployment Guide — HR Advanced

## What was configured

### 1. Trust Proxies (`bootstrap/app.php`)
`$middleware->trustProxies(at: '*')` added.

The ALB terminates HTTPS and forwards requests to EC2 over HTTP with:
- `X-Forwarded-For` — real client IP
- `X-Forwarded-Proto: https` — so Laravel knows the original scheme

Without this, `request()->secure()` returns `false`, CSRF validation fails,
session cookies are not marked Secure, and `url()` / `asset()` generate `http://` links.

---

## EC2 / Server `.env` settings

Copy `.env.example` to `.env` and set these values:

```env
APP_URL=https://hr.yourdomain.com
APP_ENV=production
APP_DEBUG=false

# RDS
DB_CONNECTION=mysql
DB_HOST=your-rds-endpoint.rds.amazonaws.com
DB_PORT=3306
DB_DATABASE=hr_advanced
DB_USERNAME=hr_user
DB_PASSWORD=<strong-password>

# Session — MUST be secure=true because ALB terminates SSL
SESSION_DRIVER=database
SESSION_DOMAIN=.yourdomain.com
SESSION_SECURE_COOKIE=true

# Sanctum — must match the domain the browser uses
SANCTUM_STATEFUL_DOMAINS=hr.yourdomain.com

# S3 for file uploads (employee photos, documents, payslips)
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<key>
AWS_SECRET_ACCESS_KEY=<secret>
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=hr-advanced-storage
```

---

## ALB Configuration

### Target Group
| Setting | Value |
|---|---|
| Protocol | HTTP |
| Port | 80 (or 8000 if using `php artisan serve`) |
| Health check path | `/up` |
| Health check codes | 200 |

### Listener Rules
| Listener | Action |
|---|---|
| HTTPS :443 | Forward to target group |
| HTTP :80 | Redirect to HTTPS :443 |

### Security Groups
- ALB SG: inbound 80, 443 from `0.0.0.0/0`
- EC2 SG: inbound 80 (or 8000) **from ALB SG only** — never expose EC2 directly

---

## EC2 Deployment Steps

```bash
# 1. Clone / pull code
git pull origin main

# 2. Install PHP deps
composer install --no-dev --optimize-autoloader

# 3. Build frontend assets
npm ci && npm run build

# 4. Set env
cp .env.example .env
# edit .env with production values above
php artisan key:generate

# 5. Run migrations
php artisan migrate --force

# 6. Symlink storage
php artisan storage:link

# 7. Cache config/routes for performance
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 8. Sync permissions
php artisan hr:sync-permissions

# 9. Queue worker (use supervisor or systemd)
php artisan queue:work --sleep=3 --tries=3 --daemon
```

---

## Multi-instance / Auto Scaling

If running multiple EC2 instances behind the ALB:

| Concern | Solution |
|---|---|
| Sessions | Already `SESSION_DRIVER=database` — shared RDS ✅ |
| Cache | Change `CACHE_STORE=redis` pointing to ElastiCache |
| File uploads | Already S3-ready via `FILESYSTEM_DISK=s3` ✅ |
| Queue | Point `QUEUE_CONNECTION=sqs` or use a shared Redis queue |

---

## Health Check

Laravel exposes `/up` (configured in `bootstrap/app.php` `->withRouting(health: '/up')`).
Set this as the ALB target group health check path. Returns `200 OK` when the app is running.
