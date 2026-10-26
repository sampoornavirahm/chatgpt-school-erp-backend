# GitHub Actions deployment to Hostinger UAT

This workflow deploys the Laravel backend to the UAT subdomain after a successful MySQL integration run. It is configured for the previously used Hostinger paths:

- Laravel application: `/home/u851760370/domains/madrocket.in/school-erp-backend-uat/Backend`
- Public document folder: `/home/u851760370/domains/madrocket.in/public_html/test`
- PHP CLI: `/opt/alt/php85/usr/bin/php`
- Health check: `https://test.madrocket.in/api/v1/health`

The workflow runs on pushes to `main`, waits for `mysql-integration`, builds production Composer dependencies and Vite assets on GitHub, then transfers the release over SSH. It keeps the server `.env` and `storage` files, applies `php artisan migrate --force`, warms Laravel caches, copies the Vite build to the subdomain's public folder, and checks the health endpoint. Pull requests do not deploy. Production is not targeted by this workflow.

## Add the GitHub Actions environment settings

In the backend GitHub repository, open **Settings → Environments** and create an environment named `uat`.

Add these environment variables:

| Name | Value |
| --- | --- |
| `HOSTINGER_SSH_HOST` | Public SSH host/IP shown in Hostinger's SSH connection details. Do not use the shell prompt's internal hostname unless Hostinger confirms it is reachable externally. |
| `HOSTINGER_SSH_PORT` | SSH port shown in Hostinger. Optional if it is `22`. |
| `HOSTINGER_SSH_USER` | `u851760370` |
| `HOSTINGER_KNOWN_HOSTS` | The verified SSH host-key line for this hostname/port. Confirm its fingerprint with Hostinger before trusting it. |
| `HOSTINGER_APP_PATH` | `/home/u851760370/domains/madrocket.in/school-erp-backend-uat/Backend` |
| `HOSTINGER_PUBLIC_PATH` | `/home/u851760370/domains/madrocket.in/public_html/test` |
| `HOSTINGER_PHP_BIN` | `/opt/alt/php85/usr/bin/php` |
| `HOSTINGER_HEALTH_URL` | `https://test.madrocket.in/api/v1/health` |

Add this environment secret:

| Name | Value |
| --- | --- |
| `HOSTINGER_SSH_PRIVATE_KEY` | Private half of a dedicated SSH key created for GitHub Actions. Add only the matching public half to the Hostinger account's authorized SSH keys. |

Do not use your Hostinger account password or your personal SSH private key for Actions.

## Create the dedicated SSH key

On your Mac, create a new key pair specifically for this deployment. Keep the private key local until you paste it into the GitHub `uat` environment secret. Add the `.pub` public key in Hostinger's SSH key settings.

```sh
ssh-keygen -t ed25519 -C "github-actions-school-erp-uat" -f ~/.ssh/school_erp_hostinger_uat -N ""
```

Copy the private key contents into the GitHub secret `HOSTINGER_SSH_PRIVATE_KEY`; copy the `.pub` file contents into Hostinger. The private key must never be committed or sent in chat.

For `HOSTINGER_KNOWN_HOSTS`, obtain the SSH host key for the exact public hostname and port Hostinger supplies, then verify its fingerprint with Hostinger support before saving it as a GitHub environment variable. This prevents Actions from accepting an unexpected server key.

## How to trigger and check deployment

1. Commit and push code to `main` in the backend repository. You can also use **Actions → Backend CI → Run workflow** on `main` for the first deployment after adding the settings.
2. GitHub Actions runs `Backend CI` and the MySQL integration job.
3. If integration succeeds and `HOSTINGER_SSH_HOST` is configured, the `Deploy UAT to Hostinger` job deploys automatically.
4. Open **Actions → Backend CI** and check the deploy job and its final health-check step.
5. Verify the site at `https://test.madrocket.in`.

The deployment job is skipped while `HOSTINGER_SSH_HOST` is empty. Once configured, any failure in another required deployment setting fails the deployment step with the missing variable names (never their values).

## Safety and rollback notes

- This targets UAT only. Do not point it at the live operator portal or production database.
- The job runs database migrations automatically after CI passes. Keep migrations backward-compatible; migrations that delete or rewrite data need a reviewed backup/restore plan first.
- The workflow does not currently create a database backup or perform an atomic release swap. To protect production, add a verified backup/restore step and a separate protected production environment before enabling production deployment.
- It preserves `.env`, Laravel `storage`, and the external public index file. Do not store deployment credentials or license/Firebase keys in the repository.
- A deployment that has already applied a migration is rolled forward with a corrective migration; do not assume reverting code will safely reverse a database change.
