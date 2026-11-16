# Notes

## Time spent

> **TODO (candidate): fill in your actual time before submitting.**

| Area | Time |
|---|---|
| Reading the brief, schema design | |
| Migrations, models, seeders | |
| Repositories, authorization, booking service | |
| Controllers, form requests, routes | |
| Tests, concurrency verification | |
| README / VERIFICATION / NOTES | |
| **Total** | |

## AI tools used

- **Claude Code** (Anthropic, model Claude Opus 5.5) as a pair programmer throughout: schema and migration drafts, models, repositories, services, policies, controllers, tests and these documents. Work was done incrementally, one layer at a time, with each step reviewed and run before moving on.
- **Laravel Boost** (`laravel/boost`, dev dependency) supplied Laravel 13 coding guidelines and skills to the assistant. Its generated files (`CLAUDE.md`, `AGENTS.md`, `.claude/`, `boost.json`) are local-only and git-ignored; `php artisan boost:install` recreates them. It is not used at runtime.

Every behaviour claimed in README.md and VERIFICATION.md was checked by running it: the PHPUnit suite on SQLite and MySQL, a forked-process concurrency test on MySQL, and the VERIFICATION.md commands executed end to end against a live server. Problems found that way are listed under [Issues found during development](#issues-found-during-development).

## Assumptions

1. **Operators authenticate with Sanctum tokens**, not sessions. The assignment is API-only, so one mechanism serves both actor types. Operators (`users`) and LSP users (`lsp_users`) have separate token endpoints because they are separate tables.
2. **No LSP admin user.** It is optional; every LSP account is bound to exactly one team, which is the strictest reading.
3. **Datetimes are stored in UTC**; Asia/Yangon is used for every business-day decision (calendar day, weekday, counting, 60-day window). An `appointment_date` without an offset is Yangon local time; an explicit offset is honoured.
4. **The 60-day window compares Yangon calendar days**: today + 60 is the last bookable day (inclusive). **Past dates are accepted**: the brief only limits how far ahead a booking may be, and its own boundary example (2026-04-02) is in the past by the time it is evaluated.
5. **Sending `override_reason` is an override attempt.** Without `appointment.override` it returns 403 even when slots are free (rule 10: "user without permission who sends override fields → 403"). With the permission, the override is only used when the day is full: on a day with free slots the booking is a normal `book` / `reschedule`, and the supplied reason is still stored on the log row.
6. **`is_super_admin` widens team access only**; it does not imply `appointment.override`.
7. **LSP user + job with no team → 403.** Rule 12 allows only jobs whose `current_lsp_team_id` matches the user's team, and NULL never matches. For operators the area check falls back to the job's `current_lsp_id`, so an in-area operator reaches the required 422 (rule 8). A job with neither LSP nor team is 403 for regular operators and 422 for super admins.
8. **Authorization runs before validation** (form request `authorize()`), so a forbidden caller always gets 403, never a 422 that reveals details, and nothing is written.
9. **Same-day re-book** (same job, same team, same Yangon day) returns 200 without using a slot or writing a log row (the brief allows skipping the log). If the time of day differs, the new time is saved.
10. **The job's own status does not block booking.** The brief defines which statuses count toward capacity, not which jobs may be booked, so e.g. a Cancelled job can still be given a date (it just does not use a slot).
11. **Reading capacity never creates rows**; default capacity rows are created only inside a booking transaction.
12. **Soft-deleted or unknown jobs return 404** (route model binding excludes soft-deleted rows).

## Design decisions

### Layers

- **Controllers** (`app/Http/Controllers/Api`) only bind the model, rely on the form request for authorization and validation, call the service and return a resource. No queries, transactions or rules.
- **Form requests** run the policies in `authorize()` and validate input (`BookAppointmentRequest`, `CapacityRequest`, `IssueTokenRequest`).
- **Policies** (`InstallationRequestPolicy`, `OnCallRequestPolicy`, `LspTeamPolicy`) are thin and delegate to **`AuthorizationService`**, the single place for the team-ownership, area-code and override rules. The area-code overlap is written once instead of three times.
- **`AppointmentCapacityService`** holds every booking rule: Yangon day and weekday, default capacity, locking, counting, idempotency, reschedule, override, audit log, transaction.
- **Repositories** (interfaces in `Repositories/Contracts`, Eloquent implementations bound in `AppServiceProvider`) do persistence only. `AppointmentRepositoryInterface` works across both job tables, selected by the `RequestType` enum, so the shared counter is one method that sums both tables.
- **No separate `AppointmentService`.** The brief marks it optional; with route model binding resolving the job and one service method serving both endpoints, it would have been a pass-through layer.
- **Enums** (`JobStatus`, `RequestType`, `BookingAction`, `PermissionName`) replace magic strings. `JobStatus::nonSlotConsuming()` is the one definition of the statuses that do not count.

### Used slots are derived, not stored

There is no counter column. Used slots are always counted from `appointment_date` across both job tables. That keeps the count consistent with soft deletes and status changes made anywhere, and it makes rescheduling atomic by construction: one `UPDATE` of `appointment_date` frees the old day and consumes the new one. The count is a range query on stored UTC values, `[Yangon 00:00, next Yangon 00:00)` converted to UTC, served by the index `(current_lsp_team_id, appointment_date)`. Wrapping the column in a function would prevent index use.

### Concurrency

Inside one `DB::transaction` (InnoDB):

1. `SELECT … FOR UPDATE` the job row, so concurrent requests for the same job see its latest date.
2. `SELECT … FOR UPDATE` the team's capacity row for the Yangon weekday. If it is missing, `insertOrIgnore()` the default (8) and lock it. `UNIQUE(lsp_team_id, day_of_week)` guarantees a single row.
3. Count used slots, then check capacity, update the date and write the log, then commit.

Every booking for a team and weekday therefore waits on the same row lock, so two requests for the last slot are serialized: the second one counts after the first commits and gets 422.

Two details matter, both proven with real MySQL sessions:

- **The lock must come before any plain read in the transaction.** Under REPEATABLE READ the first non-locking read fixes the transaction's snapshot. An earlier version checked "does the capacity row exist?" with a plain read before locking. Each request's snapshot was then taken before it waited for the lock, its count after the lock could not see the winner's commit, and **6 of 6 racing requests booked a 1-slot day**. The concurrency test caught this; locking first fixed it.
- **The brief's suggested "always `insertOrIgnore()`, then lock" deadlocks on MySQL.** `INSERT IGNORE` on an existing row takes a *shared* lock on the duplicate; two bookings then both try to upgrade to exclusive and one gets `ERROR 1213 Deadlock`. That was reproduced with two `mysql` sessions. So the default row is inserted only when the locking read finds nothing.
- The remaining deadlock (racing gap locks while a team's row for a weekday does not exist yet) only happens until that row exists. `DB::transaction(…, attempts: 3)` retries it. With retries disabled, the "missing row" concurrency test fails with a deadlock every time; with them it passes.

`tests/Feature/Services/AppointmentCapacityServiceConcurrencyTest` forks six processes with their own MySQL connections, releases them at the same instant against the last slot (both with an existing capacity row and with a missing one), and asserts exactly one success. Removing `lockForUpdate()` makes both cases fail.

### Schema

- **Composite foreign keys** `(current_lsp_team_id, current_lsp_id) → lsp_teams(id, lsp_id)` (and the same on `lsp_users`): the database guarantees an assigned team belongs to the row's LSP. MySQL skips the check when either column is NULL, which is why the authorization layer resolves a job's LSP from its team first.
- **Polymorphic-style audit reference**: `appointment_booking_logs.request_type` (`installation` / `oncall`) + `request_id`, registered as an Eloquent morph map from the `RequestType` enum. It cannot have a foreign key, so the job is always resolved through the model in application code. `request_type` and `action` are DB enums. Rows reference soft-deleted jobs fine (`withTrashed()`).
- Pivot tables use the composite unique pair as primary key, with a reverse index for lookups from the other side.
- Deletes are **restricted** on business data (LSPs, teams, statuses, jobs, logs) so audit history cannot disappear; pivot and capacity rows cascade with their parent.

### Timezone safety

Eloquent and the query builder format a `Carbon` without converting its timezone, so saving a Yangon-time Carbon would store Yangon wall time as if it were UTC. Two guards:

- an `appointment_date` mutator on the shared `HasAppointmentBooking` trait converts every write to the storage timezone, whatever the code path (service, factory, seeder);
- the repository converts range bounds before querying.

## Issues found during development

| Issue | How it was found | Fix |
|---|---|---|
| Lost-update race: 6/6 parallel requests booked a 1-slot day | forked-process concurrency test on MySQL | lock the capacity row before any plain read (see Concurrency) |
| "Always `insertOrIgnore()` then lock" deadlocks under contention | two concurrent `mysql` sessions | insert only when the locking read finds no row; retry the transaction |
| Yangon-time Carbon values stored as UTC wall time | service test against a factory-created job | `appointment_date` mutator converts to UTC on write |
| Unauthenticated request without `Accept: application/json` → 500 (`Route [login] not defined`) | curl walkthrough | `redirectGuestsTo(null)`: API-only app returns 401 |
| Spec scenario 3 ("ninth booking when max is 8") not reachable with seed data | writing VERIFICATION.md | seeded a "full day" with 8 counted Team A1 jobs |

## Testing status

- `php artisan test`: 89 tests (87 on SQLite + 2 MySQL-only concurrency tests). All pass on MySQL; the concurrency pair is skipped on SQLite.
- Covered: repositories (counting rules, timezone range, default row, locking guard, audit log), `AuthorizationService` (every rule, for both job types), policies (wiring, 403 messages, guests), `AppointmentCapacityService` (all assignment scenarios, rollback on log failure, timezone boundary, weekday capacity), concurrency.
- **Not yet written:** HTTP-level endpoint tests (status codes, response shapes, authorize-before-validate ordering). Those behaviours were verified by running every command in VERIFICATION.md against a live server.
