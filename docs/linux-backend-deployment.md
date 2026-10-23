# Linux PHP backend deployment

The backend is Laravel 13 and requires PHP 8.3 or newer. It uses a regular PHP-FPM/Apache or Nginx and MySQL/MariaDB setup; it does not require root access, Docker, Redis or a persistent worker. The host needs Composer/SSH for deployment, or upload a `vendor` directory built for a compatible PHP environment. Deploy the `Backend` directory and set the web server document root to `Backend/public`. Serving the project root can expose configuration and source files; keep `.env`, `vendor`, storage and credentials outside the web document root.

## Server preparation

Install PHP 8.3+, Composer 2, Apache or Nginx, PHP-FPM, and PDO MySQL, cURL, OpenSSL, Mbstring, Fileinfo, XML and Hash extensions. Create a MySQL/MariaDB database and restricted database user. Enable HTTPS and permit outbound HTTPS requests to Google and the activation service.

## Deploy

From the backend project directory:

```sh
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

Create the production `.env` on the host, then set at least:

```dotenv
APP_NAME="School ERP Backend"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.your-school-domain.example
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=school_erp
DB_USERNAME=school_erp_user
DB_PASSWORD=replace-with-server-secret
SCHOOL_ERP_TRIAL_ENABLED=true
SCHOOL_ERP_ACTIVATION_DRIVER=dummy
SCHOOL_ERP_ALLOW_DUMMY_ACTIVATIONS=false
SCHOOL_ERP_TRIAL_DAYS=15
SCHOOL_ERP_TRIAL_MAX_DAYS=30
SCHOOL_ERP_OFFLINE_GRACE_DAYS=7
SCHOOL_ERP_LICENSE_KEY_ID=production-1
SCHOOL_ERP_LICENSE_PRIVATE_KEY_PATH=/secure/path/outside/public/license-signing.key
SCHOOL_ERP_LICENSE_PUBLIC_KEY_PATH=/secure/path/outside/public/license-public.key
SCHOOL_ERP_PAYMENT_DRIVER=dummy
SCHOOL_ERP_ALLOW_DUMMY_PAYMENTS=false
SCHOOL_ERP_MERCHANT_UPI_VPA=
SCHOOL_ERP_MERCHANT_UPI_NAME="School ERP"
API_TOKEN_EXPIRATION_MINUTES=720
```

Create `APP_KEY` once with `php artisan key:generate` and retain it securely. Do not regenerate it during routine deployments; it encrypts stored relay keys and Firebase Installation IDs (FIDs). Configure a long random `SCHOOL_ERP_PLATFORM_BOOTSTRAP_TOKEN` for the one-time operator bootstrap. The value may be removed after visiting `/operator/bootstrap`, creating the platform operator, and signing in. Keep the FCM service-account JSON outside `public/` and outside source control; set `FIREBASE_SERVICE_ACCOUNT_FILE` to its absolute path. Set license-key paths outside `public/`, run `php artisan license:generate-keys` once, and back up the private key securely. Do not regenerate that key during routine deploys. Run `php artisan license:backfill-trials` once after key creation when upgrading a Phase 7 database with existing trial schools; it preserves their original `trial_started_at`. Trial is 15 days and may be extended once to 30 days from its original start.

Run:

```sh
php artisan migrate --force
php artisan optimize
```

The web-server process needs write access to `storage` and `bootstrap/cache`. Back up MySQL and `.env`/key material securely; do not publish those backups from the web root. `GET /api/v1/health` is the health check.

## Initial operator and relay setup

After migrating the database, visit `/operator/bootstrap`, create the platform operator with the one-time bootstrap token, remove that token from the server environment, and sign in at `/operator/login`. The operator portal can create trial school tenants, edit plan prices/features/limits, review and approve a school's single 15-day extension request, issue a one-time activation code, revoke or hold a school, create custom Elite installment schedules, pause a school's relay, set a maximum 50 GB relay storage allowance, configure archive-access dates/allowances/prices, reconcile manual payments, and add/remove explicit parent-to-student access links. Signed license limits are enforced for teacher creation and active student relay snapshots.

Open a school in the operator portal and create a relay credential. Copy the school ID, key ID and secret immediately; the plaintext secret is shown only once. In the local Hub process environment, configure all four values:

```dotenv
SCHOOL_ERP_RELAY_URL=https://api.your-school-domain.example/api/v1
SCHOOL_ERP_RELAY_SCHOOL_ID=<school-uuid>
SCHOOL_ERP_RELAY_KEY_ID=<key-uuid>
SCHOOL_ERP_RELAY_SECRET=<one-time-secret-from-operator-portal>
SCHOOL_ERP_RELAY_INTERVAL_SECONDS=300
```

Restart the Hub after changing relay settings. It sends outbound HTTPS only and continues to keep its main API on loopback. Do not place these settings in a public file, commit them, or share the secret with parents. Hub configuration validation rejects partial credentials and non-HTTPS relay URLs. Parent accounts must be created within the same hosted school tenant, and the operator must explicitly link a parent to a student after that student's first projection has arrived. Parents sign in using the server API root and school ID; their permissions are limited to those links. `Backend/openapi.yaml` documents the request and signature formats.

The current trial flag is global to the installation. Trial school provisioning can be used for testing, but do not expose public self-service school registration without rate limits, email verification and an activation decision. Production host provisioning, real parent identities, retention policy and database backup operations remain the deployment owner's responsibility.

## Trial and activation

Trial lasts 15 days. The school can request one additional 15 days; an operator approves it and the trial ends no later than day 30 from the original start. Once a trial expires, school services go on hold. Sign-in and billing renewal remain available; school-data reads, writes, exports, backups and restore are blocked until an extension or paid activation is issued. The local Hub polls the hosted license when it is online and applies a signed hold/renewal token. Offline installations keep the last signed state until their next successful licensing check. Trial setup marks Google and activation `bypassed_trial`. When trial mode is disabled, verify the original school administrator's Google account and redeem an operator-issued activation code before access.

The license activation path is implemented inside Laravel. When trial is disabled, set `GOOGLE_OAUTH_CLIENT_ID`; the verified Google email must match the school's original administrator. `SCHOOL_ERP_ACTIVATION_DRIVER=dummy` and `SCHOOL_ERP_ALLOW_DUMMY_ACTIVATIONS=true` permit non-empty dummy codes only in local/testing. Keep dummy activation disabled in production. For an existing trial school, issue a one-time code in the operator portal and redeem it with the school UUID and stable server UUID. A future real activation provider must be installed and reviewed before switching production out of trial.

## Plans, UPI and payments

The seeded currency is INR. Starter is free with a 100-student/1-teacher cap. Basic Adware is free and carries an ad-supported entitlement; its seat cap was not specified, so the current limits are unset. Professional costs ₹150 per active projected student annually, paid upfront by the school. Professional Distributed costs ₹200 per parent or ₹500 per teacher annually, paid upfront by each user. Elite has an operator-defined price/services and two or more installments; its license issues when all installments are paid. The Professional school seat count uses active student projections already received by this backend.

Set `SCHOOL_ERP_MERCHANT_UPI_VPA` and `SCHOOL_ERP_MERCHANT_UPI_NAME` to generate the platform's UPI payment URI. A QR is only a payment instruction. The operator portal can record offline cheque, bank transfer, UPI or gateway settlements and activate the related entitlement. School-owned fee collection settings are separate and are stored with encrypted gateway credentials; no school fee checkout is wired yet. Live Razorpay/PayU adapters and verified webhook processing, taxes/GST, invoices and refunds remain unimplemented. Dummy payment simulation is available only in local/testing and always disabled in production.

Archive access controls in the operator portal only set an agreed access window, free-read allowance, optional query price and internal note. Archive datasets, the query UI/API, metering and charge collection have not been implemented. Do not enable archive access for a production school until those pieces exist.

Plan feature flags are stored and signed, but no plan-to-feature matrix was provided. Defaults are empty, so paid-feature UI/API gating beyond seat limits is not configured yet. Ads are represented by the Basic Adware entitlement; no ad-serving integration or approved sponsor content is configured. Keep any future placements first-party, school-approved and non-behavioral.

## Firebase Cloud Messaging

Set `FIREBASE_PROJECT_ID` and `FIREBASE_SERVICE_ACCOUNT_FILE`. The JSON file must be readable by PHP, outside `Backend/public`, and limited to the backend service account. The server obtains short-lived Google OAuth access tokens and sends FCM HTTP v1 messages with generic content and a minimal `connection_test` data type. Student names and other personal data are not included.

## Upgrade and rollback

Run `php artisan migrate --force` after reviewing pending migrations. Take a protected database backup before production schema changes. Do not roll back a deployed migration if it would discard live account or activation data; use a forward migration instead. Keep `APP_DEBUG=false` and review Composer audit output for releases.
