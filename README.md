# Information Management System (IMS)

A white-label PHP information management system (programs, projects, KPIs, workplans, HR, hub operations, procurement).

## Branding

Nothing in the code is tied to a particular organisation. The organisation name, logo, favicon,
contact details (email, phone, address, website), primary/secondary/sidebar colours and font are
configured under **Settings > General** and are used across the internal pages, public pages,
emails and PDF/print views.

## Setup

1. `composer install`
2. Copy `.env.example` to `.env` and fill in the database, `APP_KEY`, mail and API settings.
   Set `APP_ENV=production` and `APP_DEBUG=false` on the server. Never change `APP_KEY` once
   set — stored secrets are encrypted with it.
3. Import the database, then run every file in `database/migrations/` (idempotent).
4. Optional cron for carrying pending daily tasks to the next day:
   `5 0 * * * php /path/to/legacy/cron/carry-over-pending-tasks.php`
5. Optional daily cron for automatic reminders to applicants with unsubmitted draft applications
   (days before the deadline set under Manage Applications > Draft reminders):
   `0 8 * * * php /path/to/legacy/cron/application-draft-reminders.php`
6. Hub internet control on pfSense: install `tools/pfsense/ims-cp-agent.php` on the firewall
   (instructions at the top of the file). In the default pull mode pfSense checks in with
   `/hub-network-agent` every minute from its own Cron package, so no IMS cron is needed and it
   works from shared hosting. Only in push mode (IMS server can reach the firewall) add:
   `*/5 * * * * php /path/to/legacy/cron/hub-internet-sync.php`
7. Optional daily cron for automatic hub member subscription reminders (schedule and message
   under Hub Team & Internet > Settings & reminders > Reminders):
   `0 8 * * * php /path/to/legacy/cron/hub-member-reminders.php`

## Notes

- `.env`, database dumps, backups, uploads and logs are deliberately not in version control.
- Uploaded files are served only through `legacy/download-file.php` (login + per-file permission checks).

## PowerPoint previews

PowerPoint presentations (`.ppt` and `.pptx`) are download-only for shared-hosting
compatibility. View shows a message directing users to the Download button to
open the original in Microsoft PowerPoint or LibreOffice. This requires no
server-side converter. Document permissions still apply, and compressed files
are decompressed by the existing download flow.
