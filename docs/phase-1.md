# Phase 1: Laravel foundation and access control

Scope: Laravel 13 monolith, Blade, administrator-provisioned accounts, login/logout,
roles and permissions, Arabic/English, tests. No ERP transactions or AI.

Decisions:
- Use installed PHP 8.4.27 (compatible with Laravel 13), without changing global PHP configuration.
- Reuse the empty checkout on `codex/phase-1-foundation`; a worktree cannot start from a repository with no commits.
- MySQL remains the application database. The owner supplies a dedicated account in `.env`.
- Tests use isolated SQLite in memory; they never migrate the application database.
- Arabic and English are confirmed. Public registration is disabled; administrators provision accounts.
- MySQL migrations run only against the verified empty project database. No default passwords, queue workers, public API, or AI service.

Tasks:
- [x] Install scaffold and verify baseline tests.
- [x] Write failing access-control, authentication, administration and localization tests.
- [x] Implement schema, seed permission catalogue, authentication and administration.
- [x] Implement bilingual Blade views and static CSS.
- [x] Run tests, formatting, dependency checks and independent review.
- [x] Document startup and database setup.

Verified: 42 automated tests (156 assertions), MySQL migrations/seed and rollback
checks, two-connection MySQL row locking, and test refusal under cached MySQL
configuration. Browser checks confirmed both Arabic RTL and English LTR login views.
The owner still creates the first administrator using the hidden-password CLI;
no permanent account or default credential was generated.

Acceptance: authenticated users see only authorized pages; forged requests fail;
inactive users cannot sign in or keep using an old session; privilege assignment
cannot exceed the actor's own access; the last active administrator cannot be
removed; no password is stored in plaintext; Arabic/English switch preserves RTL/LTR.
