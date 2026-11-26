# Production deployment

Use this guide for a new installation or an upgrade. Deployments are manual; this project does not configure a hosting provider, domain, TLS certificate, or production credentials.

## Before release

1. Review the intended changes and ensure the release contains all application files, migrations, `composer.lock`, and `package-lock.json`. Do not commit `.env`, production credentials, database exports, uploads, local logs, or caches.
2. Use PHP 8.4 or newer supported by `composer.lock`. CI uses Node.js 22; use Node 22 for a matching build environment.
3. Run the checks from the project root:

   ```powershell
   npm ci
   npm run lint:check
   npm run format:check
   npm run types:check
   npm run test:gps
   composer install --no-interaction --prefer-dist --optimize-autoloader
   composer check-platform-reqs
   composer lint:check
   php artisan test
   npm run build
   ```

4. Back up the production database and `storage/app/public`. Restore both to a separate, isolated environment and verify the restored application before upgrading production.
5. Keep the previous application release available for rollback. Before rolling back code, check whether the database schema remains compatible. Do not assume a down migration is safe for production data.

## Prepare the server

1. Configure HTTPS and point the web server document root to the release's `public/` directory. Never serve the project root. Confirm `.env`, `storage/logs`, and other private application files return no public content.
2. Build in a trusted environment with `npm ci` and `npm run build`, then deploy the complete source, lockfiles, and `public/build`. Do not deploy local `.env`, `public/hot`, `node_modules`, local logs/caches, or the Windows `public/storage` junction. The generated `public/storage` link must be created on the server.
3. On the server, install production PHP dependencies:

   ```sh
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   composer check-platform-reqs --no-dev
   ```

4. Create the server `.env` from `.env.production.example`. Replace every `REPLACE_...` value with real credentials, set the real HTTPS `APP_URL`, and configure the database and SMTP service. Keep this file outside source control and restrict its permissions. Do not copy the local `.env`.
5. Generate `APP_KEY` with `php artisan key:generate` only for a new installation. Preserve the existing key during upgrades and restores; changing it invalidates existing encrypted data and sessions.
6. Give the web process write access to `storage/` and `bootstrap/cache/`. Keep `storage/app/public` on persistent storage across releases and link it from each release.
7. After a verified backup, apply migrations and create the upload link:

   ```sh
   php artisan migrate --force
   php artisan storage:link
   ```

   Never run `migrate:fresh`, `migrate:refresh`, or development seeders against school data.
8. Clear and rebuild Laravel's optimized caches, then check configuration:

   ```sh
   php artisan optimize
   php artisan deployment:check
   ```

   The check is read-only and returns a failure code when required configuration or local deployment prerequisites are missing. A pass does not verify message delivery or prove the host is operational.
9. Configure the host scheduler to run from the application directory every minute:

   ```cron
   * * * * * cd /path/to/current-release && php artisan schedule:run >> /dev/null 2>&1
   ```

   This runs `attendance:process`. The local Windows scheduled task does not configure a production host.
10. When using the database queue, supervise a persistent `php artisan queue:work` process with the host's process manager. Run `php artisan queue:restart` after each release and monitor failed jobs and server logs.

## Verify the deployed application

Use approved test accounts for student, instructor, administrator, and guard roles. Confirm each role can access only its permitted pages and records. Check login/logout, password-reset email delivery, uploads, registration, schedules, notifications, reports, and page layouts on desktop and phone.

Test GPS attendance on a real phone over HTTPS, both inside and outside the allowed radius. Confirm manual attendance changes and deletion preserve GPS evidence. Use controlled test classes to verify automatic absences without changing school records.

Configure encrypted database and upload backups outside the web root with suitable access controls and retention. Verify the restore procedure periodically. Public launch depends on actual hosting access, domain/TLS, SMTP credentials, persistent uploads, backups, scheduler, queue supervision when used, and live workflow verification.

## Release archive helper

`scripts/package-release.ps1` creates a source archive from the checked-out project and existing `public/build`. It deliberately skips reparse points such as the local Windows upload junction, private storage, `.env`, `vendor`, and `node_modules`. Review and test the archive before transfer. The script does not install dependencies, configure a server, migrate a database, or deploy the release.
