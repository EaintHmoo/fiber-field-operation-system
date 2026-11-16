# Field Visit Appointment Booking API

API-only Laravel application that books field-visit appointments for contractor (LSP) teams, with a daily slot limit per team. Installation jobs and on-call (repair) jobs share one slot counter per team per **Asia/Yangon** calendar day.

- Submission branch: **`main`**
- Step-by-step checks: [VERIFICATION.md](VERIFICATION.md)
- Time spent, assumptions, AI usage, design decisions: [NOTES.md](NOTES.md)

## Requirements

| | Version used |
|---|---|
| PHP | 8.4 (8.4.26 tested; `composer.json` allows ^8.3) with `pdo_mysql` |
| Laravel | 13 (v13.42.0) |
| Laravel Sanctum | 4.4 |
| MySQL | 8.0+ with InnoDB (9.6 tested) |
| Composer | 2.x |

The automated test suite runs on SQLite in memory by default (`pdo_sqlite`); the concurrency tests need MySQL and the `pcntl` / `posix` extensions (see [Tests](#tests)).

## Installation

```bash
git clone <repository-url> fiber-field-operation-system
cd fiber-field-operation-system

composer install
cp .env.example .env
php artisan key:generate
```

### Database

Create the database (and, optionally, a separate one for running the tests on MySQL):

```bash
mysql -u root -e "CREATE DATABASE fiber_field_ops CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -e "CREATE DATABASE fiber_field_ops_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

`.env.example` is already set for a local MySQL; adjust credentials if needed:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=fiber_field_ops
DB_USERNAME=root
DB_PASSWORD=
```

Migrate and seed:

```bash
php artisan migrate --seed
# later, to reset to the demo state:
php artisan migrate:fresh --seed
```

The demo seeder assumes an empty database (record ids are referenced in [VERIFICATION.md](VERIFICATION.md)), so re-seed with `migrate:fresh --seed`.

Run the API:

```bash
php artisan serve        # http://127.0.0.1:8000
```

## Timezone

- Datetimes are **stored in UTC** (`config/app.php` → `timezone`).
- Every business-day decision uses **Asia/Yangon** (`config/app.php` → `business_timezone`): which calendar day an appointment is on, its weekday (Carbon `dayOfWeek`, 0 = Sunday … 6 = Saturday), slot counting and the 60-day booking window.
- An `appointment_date` sent **without** an offset (`2026-04-02 10:00:00`) is read as Yangon local time. An explicit offset is honoured (`2026-04-01T18:00:00Z` is 2026-04-02 00:30 in Yangon).
- Responses return `appointment_date` in Yangon time with its offset (`2026-04-02T10:00:00+06:30`).

## Demo data

All demo accounts use the password **`password`**.

### Accounts

| Email | Type | Team / scope | Can book for |
|---|---|---|---|
| `a1.lead@alpha.test` | LSP team user | Alpha Fiber Services → Team A1 (id 1) | Team A1 jobs only |
| `a2.lead@alpha.test` | LSP team user | Alpha Fiber Services → Team A2 (id 2) | Team A2 jobs only |
| `b1.lead@bravo.test` | LSP team user | Bravo Networks → Team B1 (id 3) | Team B1 jobs only |
| `ygn.operator@5bb.test` | Operator | Yangon Ops (YGN-EAST) | Alpha teams (A1, A2) |
| `ygn.supervisor@5bb.test` | Operator + `appointment.override` | Yangon Ops (YGN-EAST) | Alpha teams, may override capacity |
| `mdy.operator@5bb.test` | Operator | Mandalay Ops (MDY-CENTRAL) | Bravo team (B1) |
| `super.admin@5bb.test` | Operator, `is_super_admin` | none | every team (no override permission) |

### Area codes

| LSP | Area codes | Operator team covering it |
|---|---|---|
| Alpha Fiber Services (id 1) | YGN-EAST, YGN-WEST | Yangon Ops (via YGN-EAST) |
| Bravo Networks (id 2) | MDY-CENTRAL | Mandalay Ops |

### Capacity

- **Team A1** has no capacity rows → default **8** slots; the row for a weekday is created by its first successful booking.
- **Team A2** has **2** slots on every weekday, so "capacity full" is quick to reach.
- **Team B1** has no capacity rows → default 8.

### Jobs

Dates are relative to when the seeder runs, so they stay inside the 60-day booking window:

- **Demo day** = today in Yangon + 7 days, 10:00 Yangon.
- **Full day** = today in Yangon + 14 days, 09:00 Yangon. Team A1 already has 8 of 8 default slots used.

| Table | id | Team | Status | Appointment | Purpose |
|---|---|---|---|---|---|
| installation_requests | 1–3 | A1 | Pending | — | bookable |
| installation_requests | 4–6 | A2 | Pending | — | fill A2's cap of 2; the third is rejected |
| installation_requests | 7 | B1 | Pending | — | bookable by Team B1 / Mandalay |
| installation_requests | 8 | none (LSP Alpha) | Pending | — | job without team → 422 |
| installation_requests | 9 | A1 | Complete | demo day | **counts** |
| installation_requests | 10 | A1 | Cancelled | demo day | not counted |
| installation_requests | 11 | A1 | Pending, **soft-deleted** | demo day | not counted; API returns 404 |
| installation_requests | 12 | A1 | Pending | 2026-04-02 00:30 Yangon (stored `2026-04-01 18:00:00` UTC) | Yangon boundary |
| on_call_requests | 1 | A1 | Submitted | demo day | **counts** (shared counter) |
| on_call_requests | 2 | A1 | Pending | — | bookable |
| on_call_requests | 3 | B1 | Pending | — | bookable |
| installation_requests | 13–17 | A1 | Pending, Submitted, On Going, In-Progress, Complete | full day | all count |
| on_call_requests | 4–6 | A1 | Pending, Submitted, On Going | full day | all count |

So Team A1 starts with **2 used slots** on the demo day (installation 9 + on-call 1) and **8 of 8** on the full day (5 installation + 3 on-call).

## Authentication

Both actor types use **Laravel Sanctum personal access tokens** (Bearer). Operators also use tokens, not session login: the assignment is API-only, so one auth mechanism serves both. LSP users (`lsp_users`) and operators (`users`) are separate tables and models, so each has its own token endpoint; the token's owner model identifies the actor type.

| Method | Path | Body | Result |
|---|---|---|---|
| POST | `/api/auth/lsp-users/token` | `email`, `password`, optional `device_name` | 201 `{"token": "...", "token_type": "Bearer"}` |
| POST | `/api/auth/operators/token` | same | same |
| GET | `/api/auth/me` | — | who the token belongs to |
| DELETE | `/api/auth/token` | — | 204, revokes the current token |

Token endpoints are throttled (10 requests/minute), and 5 failed logins for the same email + IP lock that combination out briefly. Wrong credentials return 422.

```bash
curl -s -X POST http://127.0.0.1:8000/api/auth/lsp-users/token \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"a1.lead@alpha.test","password":"password"}'
# {"token":"1|Xb3...","token_type":"Bearer"}

curl -s -X POST http://127.0.0.1:8000/api/auth/operators/token \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"ygn.supervisor@5bb.test","password":"password"}'
```

Helper used in the examples below and in VERIFICATION.md:

```bash
API=http://127.0.0.1:8000/api
token() { # token <lsp-users|operators> <email>
  curl -s -X POST "$API/auth/$1/token" -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d "{\"email\":\"$2\",\"password\":\"password\"}" | php -r 'echo json_decode(stream_get_contents(STDIN))->token;'
}
A1=$(token lsp-users a1.lead@alpha.test)
SUP=$(token operators ygn.supervisor@5bb.test)
```

Without a valid token every endpoint below returns **401**.

## API

Base path: `/api`. Send `Accept: application/json` and `Authorization: Bearer <token>`.

### Book or reschedule an appointment

```
POST /api/installation_requests/{id}/appointment
POST /api/on_call_requests/{id}/appointment
```

| Field | Rules |
|---|---|
| `appointment_date` | required; valid datetime; its Yangon calendar day must not be more than **60 days** after today in Yangon |
| `override_reason` | optional; **sending it at all is an override attempt** (needs `appointment.override`, else 403); at least 10 characters |

```bash
curl -s -X POST "$API/installation_requests/1/appointment" \
  -H "Authorization: Bearer $A1" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"appointment_date":"2026-11-23 10:00:00"}'
```

```json
{
  "data": {
    "request_type": "installation",
    "request_id": 1,
    "lsp_team_id": 1,
    "appointment_date": "2026-11-23T10:00:00+06:30",
    "action": "book",
    "idempotent": false,
    "capacity": { "date": "2026-11-23", "day_of_week": 1, "max_slots": 8, "used_slots": 3, "remaining_slots": 5 }
  },
  "message": "Appointment booked."
}
```

`action` is `book` (job had no appointment), `reschedule` (moved to another Yangon day), `override` (booked over a full day), or `null` with `"idempotent": true` when the job was already booked on that Yangon day: no extra slot is used and no log row is written (a new time on the same day is still saved). `capacity` describes the booked day after the change.

Override (operator with `appointment.override`, only needed when the day is full):

```bash
curl -s -X POST "$API/installation_requests/6/appointment" \
  -H "Authorization: Bearer $SUP" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"appointment_date":"2026-11-23 12:00:00","override_reason":"Emergency customer outage"}'
```

### Team capacity

```
GET /api/lsp-teams/{id}/capacity?date=YYYY-MM-DD
```

```bash
curl -s "$API/lsp-teams/1/capacity?date=2026-04-02" -H "Authorization: Bearer $A1" -H 'Accept: application/json'
```

```json
{ "date": "2026-04-02", "day_of_week": 4, "max_slots": 8, "used_slots": 1, "remaining_slots": 7 }
```

`date` is a Yangon calendar date. Reading capacity never creates a capacity row. `remaining_slots` is never negative (an override can push `used_slots` above `max_slots`).

### Status codes

| Status | When |
|---|---|
| 200 | booked, rescheduled, overridden, idempotent re-book; capacity read |
| 401 | missing, invalid or revoked token |
| 403 | job/team outside the caller's team or area codes; `override_reason` sent without `appointment.override` |
| 404 | unknown or soft-deleted job, unknown team |
| 422 | validation error; job has no `current_lsp_team_id`; day at capacity (body includes `message` and `remaining_slots: 0`) |

Authorization runs **before** validation and before any write, so a 403 never changes data and never reveals validation details.

Capacity exceeded:

```json
{
  "message": "The team has no remaining appointment slots on this date.",
  "date": "2026-11-23", "day_of_week": 1, "max_slots": 2, "used_slots": 2, "remaining_slots": 0
}
```

## Authorization rules

Implemented in `app/Services/AuthorizationService.php`, used by the policies `InstallationRequestPolicy`, `OnCallRequestPolicy` and `LspTeamPolicy`, and enforced by the form requests before validation.

**LSP team user** (`lsp_users`)
- Book/reschedule: only jobs whose `current_lsp_team_id` equals the user's `lsp_team_id`. A job with no team never matches → **403**. Another team in the same LSP → 403.
- Capacity: only their own team.
- Never allowed to override capacity.

**Operator** (`users`)
- `is_super_admin = true` → every team.
- Otherwise allowed only if at least one area code is linked **both** to one of the operator's operator teams and to the target LSP (`operator_team_user` → `operator_team_area_code` ↔ `lsp_area_code`). No shared area code → **403**.
- For a job, the target LSP is the assigned team's LSP; if the job has no team, the job's `current_lsp_id` is used, so an in-area operator reaches the **422** "no team" response. A job with neither LSP nor team is 403 for regular operators and 422 for super admins.
- Override: only operators granted `appointment.override` (via `permission_user`). `is_super_admin` does **not** imply the override permission.

**LSP admin user**: not implemented. Company-wide LSP access is not supported; every LSP account is bound to one team.

## Booking rules

Implemented in `app/Services/AppointmentCapacityService.php`.

- A job uses a slot on a team/day when it is not soft-deleted, its `current_lsp_team_id` is that team, its `appointment_date` falls on that Yangon day, and its status is not Cancelled, Termination, Refund or Refund Request. Installation and on-call jobs are counted together.
- Max slots come from `lsp_team_daily_capacities` for the team and Yangon weekday, or **8** when there is no row; the missing row is created inside the booking transaction.
- Same job + same team + same Yangon day → success, no second slot, no log row.
- Reschedule to another day: used slots are derived from `appointment_date`, so updating it frees the old day and consumes the new one in the same transaction.
- Day full → 422 with `remaining_slots: 0`; nothing changes. With `appointment.override` and a reason of at least 10 characters the booking goes through and is logged as `override`.
- Every real change writes `appointment_booking_logs` (`book`, `reschedule` or `override`) in the same transaction as the date change.
- Concurrency: the booking transaction locks the job row and the team's capacity row for that weekday (`lockForUpdate`) before counting, so two requests racing for the last slot are serialized: one succeeds, the other gets 422. The transaction is retried on InnoDB deadlocks. Details in [NOTES.md](NOTES.md#concurrency).

## Project structure

```
app/
├── Enums/                    JobStatus, RequestType, BookingAction, PermissionName
├── Exceptions/               CapacityExceededException, MissingTeamAssignmentException (render 422 JSON)
├── Http/
│   ├── Controllers/Api/      InstallationAppointmentController, OnCallAppointmentController,
│   │                         TeamCapacityController, AuthTokenController
│   ├── Requests/             BookAppointmentRequest, CapacityRequest, IssueTokenRequest
│   └── Resources/            AppointmentBookingResource
├── Models/                   Eloquent models; Concerns/HasAppointmentBooking shared by both job models
├── Policies/                 InstallationRequestPolicy, OnCallRequestPolicy, LspTeamPolicy
├── Repositories/
│   ├── Contracts/            AppointmentRepositoryInterface, CapacityRepositoryInterface, BookingLogRepositoryInterface
│   └── Eloquent/             Eloquent implementations (bound in AppServiceProvider)
└── Services/                 AppointmentCapacityService, AuthorizationService, BookingResult, CapacitySnapshot
```

## Schema notes

Table and column names follow the assignment. Additions and clarifications:

- `lsp_teams` has `UNIQUE(id, lsp_id)` so `lsp_users` and both job tables can use a composite foreign key `(team, lsp) → lsp_teams(id, lsp_id)`: a team assigned to a job or user must belong to that row's LSP.
- `lsp_team_daily_capacities` has `UNIQUE(lsp_team_id, day_of_week)`; `max_slots` defaults to 8.
- Pivot tables (`lsp_area_code`, `operator_team_user`, `operator_team_area_code`, `permission_user`) use a composite primary key (the required composite unique constraint).
- Both job tables have an index on `(current_lsp_team_id, appointment_date)` for the per-day slot count, which is a range query on the stored UTC values.
- `appointment_booking_logs.request_type` / `request_id` is a polymorphic-style reference (`installation` → `installation_requests`, `oncall` → `on_call_requests`) registered as an Eloquent morph map. It has no foreign key; the job is resolved and validated in application code. `appointment_date` there is the Yangon calendar date (`DATE`).
- Permissions use a `permissions` table + `permission_user` pivot; `appointment.override` is seeded.

## Tests

```bash
php artisan test                      # SQLite in memory (phpunit.xml); concurrency tests are skipped
```

Against MySQL (needs the `fiber_field_ops_test` database; it is migrated and emptied by the tests):

```bash
DB_CONNECTION=mysql DB_DATABASE=fiber_field_ops_test php artisan test
DB_CONNECTION=mysql DB_DATABASE=fiber_field_ops_test php artisan test --group=concurrency
```

The concurrency tests fork six processes, each with its own MySQL connection, that try to book the last slot at the same instant, and assert that exactly one succeeds and the others get the capacity error. They need the `pcntl` and `posix` PHP extensions.

Coverage today: repositories, authorization service, policies and the booking service (rules, timezone boundary, idempotency, reschedule, override, rollback, concurrency). HTTP-level endpoint tests are not written yet; the endpoints were verified with the curl walkthrough in [VERIFICATION.md](VERIFICATION.md).
