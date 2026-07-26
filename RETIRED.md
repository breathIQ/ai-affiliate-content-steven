# RETIRED - this Laravel backend is no longer production

As of the 2026-07-17 cutover, co2body.com's backend is the Next.js API in the
`co2body-api` repo (github.com/breathIQ/co2body-api), deployed on Vercel with
the database in Supabase (schema `co2body`) and media on Vercel Blob.
api.co2body.com points at Vercel, not Hostinger.

This repo and the Hostinger install it mirrors are kept ONLY as a rollback
target. The Hostinger copy's database is frozen at cutover time and its
scheduler was emptied on 2026-07-26 (backup of the original
`routes/console.php` at `~/standby-backup-console-20260726.php` on the
server) because its still-running crons were rotating TikTok tokens out from
under the live system.

Do not deploy from here, and do not treat anything on the Hostinger server as
live production state.
