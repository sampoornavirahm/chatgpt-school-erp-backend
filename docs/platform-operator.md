# Platform operator portal guide

The operator portal is the hosted PHP backend's staff-only control panel. Protect operator credentials and the one-time bootstrap token. Do not publish the development server or give platform access to school users.

## Create the first operator

1. Deploy or start the backend and apply its database migrations.
2. Set `SCHOOL_ERP_PLATFORM_BOOTSTRAP_TOKEN` on the server, visit `/operator/bootstrap`, and use the token to create the first platform operator.
3. Remove the bootstrap token from the server environment, then sign in at `/operator/login`.

Bootstrap is a one-time operation. Store server backups and license-signing keys securely outside the web root.

## Operate a school

- **Schools:** create or select a school to review its status, server ID, plan, trial and service settings.
- **Trial extension:** approve a school's request for one additional 15-day period; the total trial is capped at 30 days from the original start.
- **Service access:** place a school on hold or restore access according to the recorded payment/activation decision. On hold, sign-in and renewal stay available while school services are paused.
- **Plans:** review the seeded INR plans and update configurable limits/features as product decisions are finalized. Keep live payment providers disabled until credentials and webhook verification are configured.
- **Payments:** record and reconcile cheque, bank, UPI or confirmed gateway payments after reviewing the external proof. The portal records the operator's decision; it does not independently confirm a bank transfer.
- **Activation:** create a one-time activation code for the correct school and server ID. Share it through a secure channel; the code is not reusable.
- **Relay and parents:** create/rotate a school's relay credential and link a hosted parent only to a student already synchronized for that school. Treat relay secrets as service credentials.
- **Archive access:** the controls currently record agreed access dates, read allowance and price settings. Archive datasets, archive search, metering and payment collection are not implemented; do not promise an archive query based on these controls alone.

## Protect production

Use HTTPS, `APP_DEBUG=false`, an isolated MySQL database, protected backups, a private Ed25519 signing key and a Firebase service account file outside `public/`. Rotate credentials if they are exposed. Never place keys in a school app or user guide. See the [backend hosting guide](backend-hosting.md) for server setup.
