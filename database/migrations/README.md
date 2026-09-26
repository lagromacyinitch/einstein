# Database migrations

Every push to `main` deploys the site, then runs each `.sql` file here that the
live database has not run yet (see `public/api/run_migrations.php`). The result
shows in the "Run database migrations" step of the GitHub Actions log.

- Name files `YYYY_MM_DD_what_it_does.sql`. Files run in name order, so add a
  number after the date (`2026_10_01_01_...`) when same-day files depend on
  each other.
- Never edit a file after it has been deployed. Add a new file instead; the
  runner refuses to continue if an applied file changed.
- Keep each file to one change. MySQL applies table changes immediately, so a
  file that fails halfway keeps whatever ran before the error.
- Before deleting or rewriting data, start the file with
  `-- backup: table_name, other_table`. Those tables are copied to
  `_bak_<timestamp>_<table>` first. Drop the copies in a later migration once
  you no longer need them.
- No student details, payment records, or passwords. These files are in git.
- The live server only runs them on deploy. For your local database, paste the
  new file into phpMyAdmin's SQL tab.
