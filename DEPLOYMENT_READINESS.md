# Deployment readiness review — 2026-11-26

## Status

Local verification is recorded below. Public launch is conditional on production configuration and live-host verification. No deployment was performed. The local `.env` must remain unchanged.

## Local checks

Run from the project root on 2026-11-26 with PHP 8.4.0 and Node 24.15.0. CI is configured for Node 22.

| Check | Result |
| --- | --- |
| `composer check-platform-reqs` | Passed on PHP 8.4.0. |
| `composer lint:check` | Passed (Pint). |
| `npm ci` | Blocked: Windows returned `EPERM` unlinking `node_modules/lightningcss-win32-x64-msvc/lightningcss.win32-x64-msvc.node`. Retried once with the same result. |
| `npm run lint:check`, `npm run format:check`, `npm run types:check` | Not runnable because `npm ci` did not complete and ESLint, Prettier, and TypeScript executables are unavailable. |
| `npm run test:gps` | Blocked because dependency `typescript` is missing after the failed `npm ci`. |
| `php artisan test` | Blocked: PHPUnit reports it cannot open `vendor/autoload.php`, although the file exists and can be required directly by PHP. |
| `npm run build` | Not run because frontend dependencies are unavailable. |

The frontend install blocker appears tied to the local Windows filesystem or a process holding the native binary. Resolve that on the build machine (close processes using the file or use a clean checkout/build environment), then rerun the checks. Do not delete or replace project data to work around it. The PHPUnit bootstrap issue also needs resolution before release. These local results do not replace a clean CI run.

## Required before public launch

- Create the server `.env` from `.env.production.example` and replace all database and SMTP placeholders with real credentials. Never commit this file or its secrets.
- Set the production environment, disable debug, set the real HTTPS URL, secure session cookies, and configure a verified sender address.
- Exclude `public/hot`; deploy `public/build` and the complete intended application source with both lockfiles.
- Configure HTTPS, the `public/` web root, persistent uploads, server scheduler, and a supervised queue worker when using the database queue.
- Back up the production database and uploads, then verify both restores in a separate environment before applying migrations.
- Verify all four roles, access isolation, real email delivery, uploads, registration, schedules, notifications, reports, responsive layouts, real-device GPS behavior, automatic absences on controlled test classes, logs, backups, and restore on the deployed host.

`php artisan deployment:check` checks production configuration and local prerequisites only. A passing result does not prove email delivery, running scheduler/queue processes, safe web-server configuration, a working backup, or operational readiness.
