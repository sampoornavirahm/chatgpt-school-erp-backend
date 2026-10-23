# Backend hosting guide

This guide is for the person operating the PHP service. For the local school's desktop/Hub, use the [school desktop guide](school-desktop.md). The backend is Laravel 13 and the Hub remains the authority for local school records.

## Prepare a Linux host

Use PHP 8.3 or newer with PDO MySQL, cURL, OpenSSL, Mbstring, Fileinfo and XML; Composer 2; MySQL or MariaDB; and a web server with HTTPS. Point the web root to `Backend/public`, not to the project root. The intended public domain is `madrocket.in`; decide the final API hostname (for example, `api.madrocket.in`), configure DNS and TLS at the host, and use that HTTPS address in the apps/Hub. DNS, Linux hosting and production credentials have not yet been supplied, so deployment is not live.

From the `Backend` folder on the host:

```sh
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Set production values in `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<your-api-host>`, MySQL host/database/user/password, secure session settings, and the allowed trial/activation options. Keep `.env`, database backups, Firebase JSON and license keys outside the public web root. Give the web process write access only to `storage` and `bootstrap/cache`.

Before the first migration, back up the database. Confirm the Linux host's actual MySQL/MariaDB version; the local MAMP validation used MySQL 5.7.44 and does not prove compatibility with a newer host. Then run:

```sh
php artisan migrate --force
php artisan optimize
```

Deploy updates by taking a backup, reviewing migrations, and running `php artisan migrate --force`. Use forward migrations for live data; do not roll back a migration that would discard school or payment records. Check `GET /api/v1/health` after deployment.

## Bootstrap the operator and school

Set a long random `SCHOOL_ERP_PLATFORM_BOOTSTRAP_TOKEN`, create the first operator at `/operator/bootstrap`, remove the token, and sign in at `/operator/login`. Follow the [operator guide](platform-operator.md) to create a trial school and review its settings.

Set up one school Hub and create a per-school relay credential from the operator portal. Configure the Hub process with the HTTPS relay URL, school ID, key ID and one-time secret. Restart the Hub and verify it reports a successful relay. Do not send the credential to the parent's phone or place it in a public configuration file.

## License, payments and push

Keep the generated Ed25519 private key outside the web root and back it up securely. For online activation, set the verified Google OAuth client ID and choose a real activation provider before disabling trial. Dummy activation/payment drivers are for local/testing only. UPI QR codes are payment instructions; an operator must record and reconcile confirmed offline payments. Razorpay and PayU checkout/webhook processing are not live.

For FCM, set `FIREBASE_PROJECT_ID` and `FIREBASE_SERVICE_ACCOUNT_FILE` to a service account file outside `Backend/public`, with least-privilege access. Check file permissions and test a generic notification from the school admin app. New published notices send a generic alert to linked parents after a successful relay update. The notice body stays in the authenticated in-app inbox. Push failure does not block record sync.

## Back up and monitor

Back up MySQL and private key/config material to a protected destination. Test restore before relying on backups. Review Laravel logs, web-server TLS/health, disk space and database availability after deploys. Do not treat the configured archive-access controls as implemented archive storage/query services: archival datasets, archive metering and archive charging remain future work.

See the detailed [Linux deployment notes](../linux-backend-deployment.md) and [Firebase setup guide](firebase-setup.md) for environment variables and service configuration.
