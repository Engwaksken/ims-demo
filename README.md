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

## Notes

- `.env`, database dumps, backups, uploads and logs are deliberately not in version control.
- Uploaded files are served only through `legacy/download-file.php` (login + per-file permission checks).

## PowerPoint previews

PowerPoint presentations (`.ppt` and `.pptx`) are download-only for shared-hosting
compatibility. View shows a message directing users to the Download button to
open the original in Microsoft PowerPoint or LibreOffice. This requires no
server-side converter. Document permissions still apply, and compressed files
are decompressed by the existing download flow.
