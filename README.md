# CPC Campus Organizations — API (Laravel)

REST API for the **Cordova Public College Campus Organizations** portal. It powers the React
frontend (separate repo: `cpc-orgs-frontend`).

| | |
|---|---|
| Framework | Laravel 12 (PHP 8.2+) |
| Auth | Laravel Sanctum bearer tokens — log in with **school ID number or email** |
| Database | SQLite for local dev · **Neon Postgres** in production (MySQL also works) |
| Hosting | **Render** (Docker, free plan) |

---

## What problems it solves (student point of view)

| Problem at school | What the system does |
|---|---|
| Org info is scattered across Facebook pages and group chats | One directory of every recognized organization, with officers, schedule and events |
| Paper membership forms get lost; no idea if you were accepted | Online application with a status tracker and notification on decision |
| Paper sign-in sheets, proxy signing, lost attendance records | **Rotating QR / 6-character code check-in** (changes every minute), walk-ins by ID number, CSV export |
| You find out you "missed" a required event only at clearance week | **Attendance & Clearance** page per organization, with **online excuse filing** within 7 days |
| Activity proposals are routed on paper for signatures | Officers submit events → OSA approves/returns with a note → published to students |
| Announcements get buried in group chats | Announcement feed + in-app notifications |
| OSA can't see which organizations are struggling | Oversight dashboard: attendance health, inactive orgs, slow application reviews, CSV reports |
| Forgot password with no email server | Request goes to the OSA, who issues a temporary password after checking the school ID |

## Roles

- **Student** — discover/join organizations, register for events, check in, track attendance, clearance and service hours.
- **Organization officer** — a student with an officer position. Switches to the officer workspace to manage applications, members, events, attendance, excuses, announcements and the organization profile.
- **Faculty adviser / OSA** — oversight of all organizations, event approvals, attendance, student lookup, reports and campus-wide announcements.

---

## Run it locally

Requirements: PHP 8.2+ (with `pdo_sqlite`), Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite          # Windows PowerShell: ni database/database.sqlite
php artisan migrate --seed
php artisan serve                       # http://localhost:8000
```

Or in one go: `composer run setup` then `php artisan serve`.

Run the tests: `php artisan test`

### Demo accounts (password for all: `password123`)

| Role | Log in with |
|---|---|
| Student | `2024-00145` or `kyla.abellana@cpc.edu.ph` |
| Officer (JITS President) | `0002` |
| Adviser / OSA | `Admin` or `osa@cpc.edu.ph` |

Sample dates are relative to today. To refresh them later: `php artisan campus:reset-demo` (deletes all data).

---

## Deploy (Neon + Render) — same setup as our last full-stack project

### 1. Database on Neon
1. Create a project at **neon.tech** (region: Singapore is closest to Cebu).
2. Copy the **direct** connection string (not the "pooled" one), e.g.
   `postgresql://user:password@ep-xxxx.ap-southeast-1.aws.neon.tech/neondb?sslmode=require`

### 2. API on Render
1. Push this folder to its own GitHub repo (e.g. `cpc-orgs-backend`).
2. Render → **New → Blueprint** → pick the repo (uses `render.yaml`), or **New → Web Service** → Runtime **Docker**.
3. Set the environment variables:

| Key | Value |
|---|---|
| `APP_KEY` | run `php artisan key:generate --show` locally and paste it |
| `APP_URL` | your Render URL, e.g. `https://cpc-orgs-api.onrender.com` |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | the Neon connection string |
| `DB_SSLMODE` | `require` |
| `FRONTEND_URL` | your Vercel URL, e.g. `https://cpc-orgs.vercel.app` (no trailing slash) |
| `SEED_DEMO_DATA` | `true` for the first deploy (loads sample data only if the DB is empty), `false` later |

4. Deploy. The container runs migrations automatically on start (`docker/start.sh`).
5. Check `https://YOUR-API.onrender.com/up` → should show "Application up".

> Render's free plan sleeps after inactivity, so the first request can take ~50 seconds.
> The frontend shows a friendly "server may be waking up" message when that happens.

**Prefer MySQL (Aiven) like the WDH project?** Set `DB_CONNECTION=mysql`, `DB_URL=mysql://user:pass@host:port/db`
and, if Aiven requires it, `MYSQL_ATTR_SSL_CA` to the CA file path. Nothing else changes.

---

## Folder structure

```
app/
  Http/
    Controllers/Api/
      AuthController.php            login (ID or email), me, logout, profile, password, forgot password
      AnnouncementController.php    student announcement feed
      NotificationController.php    in-app notifications (bell)
      Student/                      dashboard, organizations, applications, events, check-in, clearance
      Officer/                      overview, applications, members, events, attendance, excuses, announcements, organization
      Adviser/                      overview, organizations, event approvals, attendance, students, reports, announcements
    Middleware/                     EnsureStudent, EnsureOfficer (selected org via X-Organization-Id), EnsureAdviser
    Requests/                       form validation
    Resources/                      JSON shapes returned to the frontend
  Models/                           User, Organization, Membership, MembershipApplication, Event,
                                    EventRegistration, Attendance, ExcuseRequest, Announcement, UserNotification
  Services/
    AttendanceService.php           attendance rates, service hours, clearance
    CheckInCodeService.php          rotating 6-character codes for the QR
    NotificationService.php         in-app notifications
    OrganizationHealthService.php   healthy / monitor / at risk
  Support/                          Campus meta, CSV export, student view of events
config/campus.php                   school name, term, thresholds (all from .env)
database/migrations, seeders        schema + Cordova Public College sample data
docker/                             start script + PHP settings for Render
routes/api.php                      all endpoints
```

## API overview

All endpoints are under `/api`. Send `Authorization: Bearer <token>` after logging in.

| Area | Endpoints |
|---|---|
| Public | `GET meta` · `POST auth/login` · `POST auth/forgot-password` |
| Account | `GET auth/me` · `POST auth/logout` · `PUT auth/profile` · `PUT auth/password` · `GET notifications` · `POST notifications/{id}/read` · `POST notifications/read-all` |
| Student | `GET dashboard` · `GET organizations` · `GET organizations/{slug}` · `POST organizations/{slug}/save` · `POST organizations/{slug}/applications` · `GET my/organizations` · `GET applications` · `GET applications/{id}` · `POST applications/{id}/withdraw` · `GET events?scope=mine\|browse\|past` · `GET events/{id}` · `POST/DELETE events/{id}/register` · `POST events/{id}/excuse` · `GET excuses` · `POST check-in` · `GET clearance` · `GET announcements` |
| Officer (`X-Organization-Id` header) | `GET officer/overview` · `GET officer/applications` · `POST officer/applications/{id}/approve\|reject` · `GET/POST officer/members` · `PATCH/DELETE officer/members/{id}` · `GET officer/members/export` · `GET/POST officer/events` · `GET/PUT/DELETE officer/events/{id}` · `POST officer/events/{id}/submit\|cancel` · `GET officer/events/{id}/attendance` · `POST officer/events/{id}/attendance` · `GET officer/events/{id}/attendance/export` · `POST officer/events/{id}/check-in/open\|close` · `GET officer/events/{id}/check-in/code` · `GET officer/excuses` · `POST officer/excuses/{id}/approve\|reject` · `GET/POST officer/announcements` · `PUT/DELETE officer/announcements/{id}` · `GET/PUT officer/organization` |
| Adviser / OSA | `GET adviser/overview` · `GET adviser/organizations` · `GET adviser/organizations/{id}` · `PATCH adviser/organizations/{id}/status` · `GET adviser/events?tab=` · `POST adviser/events/{id}/approve\|reject` · `GET adviser/attendance` · `GET adviser/students` · `GET adviser/students/{id}` · `POST adviser/students/{id}/reset-password` · `GET adviser/reports/organizations\|attendance\|memberships\|participation` · `GET/POST adviser/announcements` · `PUT/DELETE adviser/announcements/{id}` |

## Term settings

Change these in `.env` / Render at the start of each semester: `ACADEMIC_YEAR`, `SEMESTER`,
`SEMESTER_MONTHS`, `SERVICE_HOURS_GOAL`, `ATTENDANCE_THRESHOLD`, `LATE_AFTER_MINUTES`,
`EXCUSE_WINDOW_DAYS`, `REQUIRE_EVENT_APPROVAL`.
