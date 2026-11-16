# Verification

Step-by-step checks for every scenario in the assignment, plus authorization, validation and concurrency checks. Each step lists the seed state, the request, the expected HTTP status and how to confirm it in the database.

Run the steps **in order from a freshly seeded database**: later steps rely on the state left by earlier ones. Seed data is described in [README.md](README.md#demo-data). Dates are relative to the day you seed, so the commands compute them.

## 0. Setup

Terminal 1, reset the data and start the API. `PHP_CLI_SERVER_WORKERS` lets the built-in server handle requests in parallel, which step C needs:

```bash
php artisan migrate:fresh --seed
PHP_CLI_SERVER_WORKERS=4 php artisan serve
```

Terminal 2, define the helpers (bash or zsh), then sign in as every demo account:

```bash
API=http://127.0.0.1:8000/api

# Yangon calendar date N days from today
ymd() { php -r "echo (new DateTimeImmutable('today +$1 days', new DateTimeZone('Asia/Yangon')))->format('Y-m-d');"; }
DAY=$(ymd 7)       # demo day: Team A1 has 2 used slots
FULL=$(ymd 14)     # full day: Team A1 has 8 of 8 used slots
LATER=$(ymd 9)     # empty day used for reschedule and the race
TOO_FAR=$(ymd 61)  # outside the 60-day booking window

token() { # token <lsp-users|operators> <email>
  curl -s -X POST "$API/auth/$1/token" -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d "{\"email\":\"$2\",\"password\":\"password\"}" | php -r 'echo json_decode(stream_get_contents(STDIN))->token;'
}

call() { # call <token> <METHOD> <path> [json-body]  -> prints the body and "HTTP <status>"
  if [ -n "$4" ]; then
    curl -s -w '\nHTTP %{http_code}\n' -X "$2" "$API$3" -H "Authorization: Bearer $1" \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$4"
  else
    curl -s -w '\nHTTP %{http_code}\n' -X "$2" "$API$3" -H "Authorization: Bearer $1" -H 'Accept: application/json'
  fi
}

sql() { mysql -u root fiber_field_ops -t -e "$1"; }   # add -p / -h to match your .env

A1=$(token lsp-users a1.lead@alpha.test)        # LSP user, Team A1
A2=$(token lsp-users a2.lead@alpha.test)        # LSP user, Team A2 (cap 2)
B1=$(token lsp-users b1.lead@bravo.test)        # LSP user, Team B1
OP=$(token operators ygn.operator@5bb.test)     # operator, Alpha area, no override
SUP=$(token operators ygn.supervisor@5bb.test)  # operator, Alpha area, appointment.override
MDY=$(token operators mdy.operator@5bb.test)    # operator, Bravo area only
SA=$(token operators super.admin@5bb.test)      # super admin, no override
echo "DAY=$DAY FULL=$FULL LATER=$LATER"
```

Seed-state check, Team A1 capacity on the demo day and the full day:

```bash
call "$A1" GET "/lsp-teams/1/capacity?date=$DAY"    # used_slots 2, remaining_slots 6
call "$A1" GET "/lsp-teams/1/capacity?date=$FULL"   # used_slots 8, remaining_slots 0
```

**Expected:** `HTTP 200`, `max_slots: 8` for both (Team A1 has no capacity rows, so the default applies).

All `appointment_date` columns are stored in UTC. The SQL below adds `+06:30` with `CONVERT_TZ` to show Yangon time.

---

## Assignment scenarios

### 1. Book under capacity → 200, log `book`, used_slots +1

**Seed state:** installation 1 (Team A1) has no appointment; Team A1 has 2 used slots on the demo day.

```bash
call "$A1" POST /installation_requests/1/appointment "{\"appointment_date\":\"$DAY 10:00:00\"}"
call "$A1" GET "/lsp-teams/1/capacity?date=$DAY"
```

**Expected:** `HTTP 200`, `"action":"book"`, `"capacity":{... "used_slots":3, "remaining_slots":5}`; the capacity call shows `used_slots: 3`.

**DB check:**

```bash
sql "SELECT id, appointment_date AS utc, CONVERT_TZ(appointment_date,'+00:00','+06:30') AS yangon FROM installation_requests WHERE id = 1"
sql "SELECT request_type, request_id, lsp_team_id, appointment_date, action, actor_lsp_user_id FROM appointment_booking_logs WHERE request_type = 'installation' AND request_id = 1"
sql "SELECT lsp_team_id, day_of_week, max_slots FROM lsp_team_daily_capacities WHERE lsp_team_id = 1"
```

- `utc` is 03:30 on the demo day; `yangon` is 10:00.
- One log row: `installation | 1 | 1 | <DAY> | book | 1`.
- A default capacity row (`max_slots 8`) now exists for Team A1 on that weekday, created inside the booking transaction.

### 2. Same job, same team, same Yangon day again → still one slot

**Seed state:** after step 1.

```bash
call "$A1" POST /installation_requests/1/appointment "{\"appointment_date\":\"$DAY 15:00:00\"}"
```

**Expected:** `HTTP 200`, `"action":null`, `"idempotent":true`, `"used_slots":3` (unchanged).

**DB check:**

```bash
sql "SELECT COUNT(*) AS logs_for_job_1 FROM appointment_booking_logs WHERE request_type = 'installation' AND request_id = 1"
sql "SELECT CONVERT_TZ(appointment_date,'+00:00','+06:30') AS yangon FROM installation_requests WHERE id = 1"
```

`logs_for_job_1` is still `1` (an idempotent re-book writes no log). The time moved to 15:00 on the same day; no extra slot was used.

### 3. Ninth booking when max is 8 → 422, appointment unchanged

**Seed state:** Team A1 has 8 of 8 default slots used on the full day (installation 13–17 + on-call 4–6, statuses Pending / Submitted / On Going / In-Progress / Complete, all counted). Installation 2 has no appointment.

```bash
call "$A1" POST /installation_requests/2/appointment "{\"appointment_date\":\"$FULL 10:00:00\"}"
```

**Expected:** `HTTP 422`

```json
{"message":"The team has no remaining appointment slots on this date.","date":"<FULL>","day_of_week":<n>,"max_slots":8,"used_slots":8,"remaining_slots":0}
```

**DB check:**

```bash
sql "SELECT id, appointment_date FROM installation_requests WHERE id = 2"
sql "SELECT COUNT(*) AS logs_for_job_2 FROM appointment_booking_logs WHERE request_type = 'installation' AND request_id = 2"
```

`appointment_date` is still `NULL`; `logs_for_job_2` is `0`.

### 4. Override with permission + reason ≥ 10 chars → 200, log `override`, may exceed max

**Seed state:** full day still at 8/8. `ygn.supervisor@5bb.test` has `appointment.override` and covers Alpha's area.

```bash
call "$SUP" POST /installation_requests/2/appointment "{\"appointment_date\":\"$FULL 10:00:00\",\"override_reason\":\"Emergency customer outage\"}"
call "$SUP" GET "/lsp-teams/1/capacity?date=$FULL"
```

**Expected:** `HTTP 200`, `"action":"override"`; capacity shows `used_slots: 9`, `remaining_slots: 0` (never negative).

**DB check:**

```bash
sql "SELECT request_type, request_id, appointment_date, action, reason, actor_user_id, actor_lsp_user_id FROM appointment_booking_logs WHERE request_type = 'installation' AND request_id = 2"
```

One row: `installation | 2 | <FULL> | override | Emergency customer outage | 2 | NULL` (user 2 is the supervisor).

### 5. Override without permission → 403, date unchanged

**Seed state:** installation 3 (Team A1) has no appointment. `ygn.operator@5bb.test` can book Alpha's jobs but lacks `appointment.override`.

```bash
# operator in the right area, no override permission
call "$OP" POST /installation_requests/3/appointment "{\"appointment_date\":\"$FULL 11:00:00\",\"override_reason\":\"Emergency customer outage\"}"
# LSP users can never override, even on their own team's job
call "$A1" POST /installation_requests/3/appointment "{\"appointment_date\":\"$FULL 11:00:00\",\"override_reason\":\"Emergency customer outage\"}"
# sending override fields is an override attempt even when slots are free
call "$OP" POST /installation_requests/3/appointment "{\"appointment_date\":\"$DAY 11:00:00\",\"override_reason\":\"Emergency customer outage\"}"
```

**Expected:** `HTTP 403` × 3, `{"message":"You do not have permission to override appointment capacity."}`.

With the permission but a reason under 10 characters, validation fails:

```bash
call "$SUP" POST /installation_requests/3/appointment "{\"appointment_date\":\"$FULL 11:00:00\",\"override_reason\":\"too short\"}"
```

**Expected:** `HTTP 422`, error on `override_reason`.

**DB check:**

```bash
sql "SELECT id, appointment_date FROM installation_requests WHERE id = 3"
```

Still `NULL`.

### 6. Team B token books Team A's job → 403

**Seed state:** installation 3 belongs to Team A1; `b1.lead@bravo.test` is in Team B1 (another LSP). `a2.lead@alpha.test` is in Team A2 (same LSP as A1, different team).

```bash
call "$B1" POST /installation_requests/3/appointment "{\"appointment_date\":\"$DAY 11:00:00\"}"
call "$A2" POST /installation_requests/3/appointment "{\"appointment_date\":\"$DAY 11:00:00\"}"
call "$B1" GET "/lsp-teams/1/capacity?date=$DAY"
```

**Expected:** `HTTP 403` for the two bookings (`You are not allowed to book appointments for this job.`), and `HTTP 403` for the capacity read (`You are not allowed to view this team's capacity.`).

**DB check:** `sql "SELECT id, appointment_date FROM installation_requests WHERE id = 3"` still shows `NULL`.

### 7. Reschedule day A → day B → A count down, B count up (one transaction)

**Seed state:** installation 1 is booked on the demo day (step 1); Team A1 has 3 used slots on the demo day and 0 on `LATER`.

```bash
call "$A1" POST /installation_requests/1/appointment "{\"appointment_date\":\"$LATER 10:00:00\"}"
call "$A1" GET "/lsp-teams/1/capacity?date=$DAY"
call "$A1" GET "/lsp-teams/1/capacity?date=$LATER"
```

**Expected:** `HTTP 200`, `"action":"reschedule"`; demo day `used_slots` goes 3 → **2**, `LATER` goes 0 → **1**.

**DB check:**

```bash
sql "SELECT appointment_date, action FROM appointment_booking_logs WHERE request_type = 'installation' AND request_id = 1 ORDER BY id"
```

Two rows: `<DAY> | book`, then `<LATER> | reschedule`.

Used slots are derived from `appointment_date`, so the single `UPDATE` frees the old day and consumes the new one. The `UPDATE` and the log row are written in one transaction (`AppointmentCapacityService::book()`); `tests/Feature/Services/AppointmentCapacityServiceTest::test_failed_audit_log_rolls_back_the_appointment_update` shows a failing log write leaves the date unchanged.

### 8. Soft-deleted job on that day → not counted

**Seed state:** on the demo day Team A1 has installation 9 (Complete), installation 10 (Cancelled), installation 11 (Pending, **soft-deleted**) and on-call 1 (Submitted).

```bash
sql "SELECT 'installation' AS type, r.id, s.name AS status, r.deleted_at FROM installation_requests r JOIN statuses s ON s.id = r.status_id
     WHERE r.current_lsp_team_id = 1 AND DATE(CONVERT_TZ(r.appointment_date,'+00:00','+06:30')) = '$DAY'
     UNION ALL
     SELECT 'oncall', r.id, s.name, r.deleted_at FROM on_call_requests r JOIN statuses s ON s.id = r.status_id
     WHERE r.current_lsp_team_id = 1 AND DATE(CONVERT_TZ(r.appointment_date,'+00:00','+06:30')) = '$DAY'"
call "$A1" GET "/lsp-teams/1/capacity?date=$DAY"
```

**Expected:** four rows listed, `used_slots: 2` (installation 9 and on-call 1 only). The soft-deleted job cannot be booked either:

```bash
call "$SA" POST /installation_requests/11/appointment "{\"appointment_date\":\"$DAY 10:00:00\"}"   # HTTP 404
```

**DB check:** restore the job and the count goes up; delete it again and it goes back:

```bash
sql "UPDATE installation_requests SET deleted_at = NULL WHERE id = 11"
call "$A1" GET "/lsp-teams/1/capacity?date=$DAY"    # used_slots 3
sql "UPDATE installation_requests SET deleted_at = NOW() WHERE id = 11"
call "$A1" GET "/lsp-teams/1/capacity?date=$DAY"    # used_slots 2
```

### 9. Job in status Cancelled on that day → not counted

**Seed state:** installation 10 is Cancelled on the demo day; installation 9 is **Complete** on the same day and **is** counted.

```bash
sql "UPDATE installation_requests SET status_id = (SELECT id FROM statuses WHERE name = 'Pending') WHERE id = 10"
call "$A1" GET "/lsp-teams/1/capacity?date=$DAY"    # used_slots 3: once Pending it counts
sql "UPDATE installation_requests SET status_id = (SELECT id FROM statuses WHERE name = 'Cancelled') WHERE id = 10"
call "$A1" GET "/lsp-teams/1/capacity?date=$DAY"    # used_slots 2: Cancelled is excluded again
```

**Expected:** `HTTP 200`; `used_slots` 3 then 2. Termination, Refund and Refund Request are excluded the same way; every other status counts (step 3's full day is filled with Pending, Submitted, On Going, In-Progress and Complete jobs).

### 10. Yangon boundary: 2026-04-02 00:30 Yangon counts on 2 April

**Seed state:** installation 12 (Team A1) has `appointment_date` stored as `2026-04-01 18:00:00` UTC = `2026-04-02 00:30` in Yangon.

```bash
sql "SELECT id, appointment_date AS utc, CONVERT_TZ(appointment_date,'+00:00','+06:30') AS yangon FROM installation_requests WHERE id = 12"
call "$A1" GET "/lsp-teams/1/capacity?date=2026-04-02"
call "$A1" GET "/lsp-teams/1/capacity?date=2026-04-01"
```

**Expected:** `utc = 2026-04-01 18:00:00`, `yangon = 2026-04-02 00:30:00`; 2 April shows `used_slots: 1` (`day_of_week: 4`, Thursday), 1 April shows `used_slots: 0`.

Bookings follow the same rule. An explicit UTC offset is converted to the Yangon day (`T00:00:00Z` is 06:30 in Yangon):

```bash
call "$A1" POST /on_call_requests/2/appointment "{\"appointment_date\":\"${LATER}T00:00:00Z\"}"
```

**Expected:** `HTTP 200`, `"appointment_date":"<LATER>T06:30:00+06:30"`, `capacity.date` = `<LATER>`.

---

## Additional checks

### A. Operator area codes → 403 outside the area, 200 inside

**Seed state:** Mandalay Ops covers MDY-CENTRAL (Bravo only); Yangon Ops covers YGN-EAST (Alpha only); the super admin has no operator team.

```bash
call "$MDY" POST /installation_requests/3/appointment "{\"appointment_date\":\"$DAY 11:00:00\"}"   # Alpha job: 403
call "$MDY" GET "/lsp-teams/1/capacity?date=$DAY"                                               # Alpha team: 403
call "$MDY" POST /installation_requests/7/appointment "{\"appointment_date\":\"$DAY 11:00:00\"}"   # Bravo job: 200
call "$OP"  GET "/lsp-teams/3/capacity?date=$DAY"                                               # Yangon operator, Bravo team: 403
call "$SA"  GET "/lsp-teams/3/capacity?date=$DAY"                                               # super admin: 200, used_slots 1
```

**DB check:** `sql "SELECT request_type, request_id, action, actor_user_id FROM appointment_booking_logs WHERE actor_user_id = 3"`. One `installation | 7 | book | 3` row (user 3 is the Mandalay operator). Installation 3 is still `NULL`.

### B. Job without `current_lsp_team_id` → 422, no update

**Seed state:** installation 8 belongs to LSP Alpha but has no team.

```bash
call "$OP" POST /installation_requests/8/appointment "{\"appointment_date\":\"$DAY 11:00:00\"}"   # operator in area: 422
call "$SA" POST /installation_requests/8/appointment "{\"appointment_date\":\"$DAY 11:00:00\"}"   # super admin: 422
call "$A1" POST /installation_requests/8/appointment "{\"appointment_date\":\"$DAY 11:00:00\"}"   # LSP user: 403 (not their team)
```

**Expected:** `HTTP 422` `{"message":"The job is not assigned to an LSP team and cannot be booked."}` twice, then `HTTP 403`.

**DB check:** `sql "SELECT id, appointment_date FROM installation_requests WHERE id = 8"` still shows `NULL`.

### C. Two requests race for the last slot → exactly one succeeds (rule 11)

**Seed state:** Team A2 has 2 slots per day and nothing booked on `LATER`. The server must be running with `PHP_CLI_SERVER_WORKERS` (step 0) so the two requests really run in parallel.

```bash
call "$A2" POST /installation_requests/4/appointment "{\"appointment_date\":\"$LATER 09:00:00\"}"   # 1 of 2 used
for id in 5 6; do
  call "$A2" POST /installation_requests/$id/appointment "{\"appointment_date\":\"$LATER 10:00:00\"}" &
done
wait
```

**Expected:** of the two parallel requests, exactly one `HTTP 200` and one `HTTP 422` (`remaining_slots: 0`). Which job wins varies.

**DB check:**

```bash
sql "SELECT id, appointment_date FROM installation_requests WHERE id IN (4, 5, 6)"
call "$A2" GET "/lsp-teams/2/capacity?date=$LATER"   # used_slots 2, remaining_slots 0
```

Two of the three jobs have a date; capacity is exactly 2 of 2.

The deterministic version of this check forks six processes, each with its own MySQL connection, that hit the last slot at the same instant. It covers both an existing capacity row and a missing one (default created under contention):

```bash
DB_CONNECTION=mysql DB_DATABASE=fiber_field_ops_test php artisan test --group=concurrency
```

### D. Validation

```bash
call "$A1" POST /installation_requests/3/appointment "{\"appointment_date\":\"$TOO_FAR 10:00:00\"}"   # 422: more than 60 days ahead
call "$A1" POST /installation_requests/3/appointment '{"appointment_date":"next-week-ish"}'          # 422: not a valid date
call "$A1" POST /installation_requests/3/appointment '{}'                                           # 422: required
call "$A1" GET "/lsp-teams/1/capacity?date=02-04-2026"                                              # 422: date must be Y-m-d
```

**Expected:** `HTTP 422` with an `errors` object for each. Day 60 itself is accepted; past dates are accepted (see NOTES.md).

### E. Authentication

```bash
call "" GET /auth/me                       # 401: no token
call "$A1" GET /auth/me                    # 200: {"actor_type":"lsp_user", ..., "lsp_team_id":1}
call "$SUP" GET /auth/me                   # 200: {"actor_type":"operator", ...}
call "$B1" DELETE /auth/token              # 204: token revoked
call "$B1" GET /auth/me                    # 401: revoked token
```

### F. Audit log summary

```bash
sql "SELECT id, request_type, request_id, lsp_team_id, appointment_date, action, reason, actor_user_id, actor_lsp_user_id
     FROM appointment_booking_logs ORDER BY id"
```

**Expected after all steps above:** one row per real change and none for rejected or idempotent requests:

| request | action | actor |
|---|---|---|
| installation 1 | book (step 1) | LSP user 1 |
| installation 2 | override, with reason (step 4) | operator 2 |
| installation 1 | reschedule (step 7) | LSP user 1 |
| on-call 2 | book (step 10) | LSP user 1 |
| installation 7 | book (step A) | operator 3 |
| installation 4 | book (step C) | LSP user 2 |
| installation 5 or 6 | book (step C winner) | LSP user 2 |
