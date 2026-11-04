# Windows local setup

User requirement: friends cloning the GitHub repository on Windows can prepare
the application with one interactive command. PHP 8.4+, Composer and MySQL 8.4+
are installed separately. No Node dependency or shared/default credentials.

Design: `.\setup.cmd` runs a dependency-free PHP bootstrap, Composer install,
then `erp:install`. The installer prompts for MySQL and an initial administrator,
creates an absent schema after confirmation, migrates and seeds additively, and
preserves existing data, APP_KEY and administrator credentials. `composer dev`
uses the same process-local MySQL extension support. Existing unrelated nonempty
databases, production, cached configuration and URL overrides are refused. Secrets
stay in the ignored local .env and hidden input. No reset/drop commands.

1. Test then implement an atomic dotenv updater: literal special characters,
   duplicate keys, unrelated settings preserved, invalid input refused.
2. Test then implement interactive local MySQL setup and bootstrap. Test refusal
   paths without touching MySQL in the memory-only PHPUnit suite.
3. Exercise fresh installation, re-run and unrelated-database rejection against
   disposable owned MySQL schemas with a separate .env. Verify normal suite,
   formatting and Composer metadata; document the two commands.
4. Fresh read-only review, fix material findings, repeat affected verification.

Use the current feature checkout. Do not reset local data or commit/push work.
Windows adaptation: progress lives under the ignored .superpowers/sdd directory;
use native PowerShell/PHP instead of the skill's bash bookkeeping scripts.

Verification: 147 tests / 924 assertions; standalone MySQL installation checks
cover cancellation, fresh/repeat setup, hidden credentials, missing database,
bad credentials and foreign databases including migration-only schemas. The
complete setup.cmd was also run twice in an independent source/vendor copy with
real Composer installation and a disposable MySQL schema; original .env unchanged.

Review corrections: Laravel's environment coercion requires an extra literal
quote layer for reserved/already-quoted values; regression tested through Env.
Windows Composer closes the stdin of script subprocesses (reproduced with a
harmless console probe), so the public one-command entry point is setup.cmd.
The batch calls Composer noninteractively and the installer directly, preserving
the terminal. MySQL secret input strips CR/LF while preserving password spaces.
