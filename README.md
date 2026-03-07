# Etterspenning.no

Website and web app files for `etterspenning.no`.

This repository contains:
- Public website pages (`index.html`, `forankringer.html`, `data.html`, etc.)
- Software/project handling frontend pages (`software-*.html`)
- PHP backend API used by software pages (`backend/public/api`)
- An older/legacy PHP admin flow in `public_html/`

## Tech Stack

- Frontend: plain HTML/CSS/JavaScript (no build step)
- Backend: PHP 8 + PDO MySQL/MariaDB
- Database: MariaDB/MySQL

## Project Structure

```text
.
|-- index.html
|-- software*.html
|-- data*.html
|-- verktoy*.html
|-- bilder*.html
|-- forankringer*.html
|-- kontakt*.html
|-- img/
|-- backend/
|   |-- .env.example
|   |-- lib/                 # config, db, auth, http helpers
|   |-- public/api/          # active API endpoints
|   |-- sql/                 # schema scripts
|   `-- tools/               # helper scripts
|-- public_html/             # older admin/request flow
|-- robots.txt
`-- sitemap.xml
```

## Branding Assets

- Primary site/logo asset is `Logo.jpeg` in webroot (repo root).
- Header logo, software branding default, and favicon links reference `Logo.jpeg`.
- If the logo file is replaced, keep the filename `Logo.jpeg` or update all references consistently.

## Local Development

### 1. Configure backend environment

Use `backend/.env.example` as template:

```bash
cp backend/.env.example backend/.env
```

Then set DB credentials and optional mail settings in `backend/.env`.
Session storage defaults to MySQL (`SESSION_STORAGE=db`) for stable auth on shared hosting.
Set `SESSION_STORAGE=files` only if you explicitly want PHP file sessions.

### 2. Create database tables

Import:
- `backend/sql/001_schema.sql`
- `backend/sql/002_project_store.sql`
- `backend/sql/003_app_sessions.sql`
- `backend/sql/004_user_roles.sql`

### 3. Create password hash for a user

```bash
php backend/tools/make-password-hash.php "YourStrongPassword"
```

Use the hash when inserting/updating a user in `users`.

### 4. Important auth note

System admin role is currently tied to this email in `backend/lib/auth.php`:

`ADMIN_LOGIN_EMAIL = admin@example.com`

If you need another admin email, update that constant and your DB user row accordingly.

Company users support these roles:
- `project_manager` (project planning/admin workflows)
- `onsite_user` (field execution workflows)

Both roles are attached to a company (`users.company_id`).

### 5. Onsite assignment flow

- Project managers assign field users per project in `software-prosjektstyring.html`:
  - `Tildel injisering felt`
  - `Tildel TM1 felt`
- Onsite users are redirected to:
  - `software-onsite.html` (NO)
  - `software-onsite-en.html` (EN)
- Onsite dashboard reads assignments from project data (`groutingAssignedEmail/siteEmail`, `stressingAssignedEmail`) and links to field pages.
- There is no manual menu link to onsite pages by default; access is role-routed after login.

#### Onsite access troubleshooting

- If opening `software-onsite.html` redirects to `software-prosjektstyring.html`, the logged-in user is not `onsite_user`.
- Confirm user role in DB:
  - `SELECT id, email, role FROM users WHERE email='user@company.no';`
- Example role update:
  - `UPDATE users SET role='onsite_user' WHERE email='user@company.no';`
- After role changes, clear browser auth state:
  - Delete cookies for `etterspenning.no`
  - Run `localStorage.removeItem("esp_pm_auth_v1")` in browser console.

### 6. Run locally

Run from repo root:

```bash
php -S 127.0.0.1:8080 -t .
```

Open:
- `http://127.0.0.1:8080/index.html`

Serving via HTTP is recommended because several pages load shared fragments/API with `fetch`.

## Active API Endpoints

Base path used by frontend software pages:

`backend/public/api`

Core endpoints:
- `POST /backend/public/api/login.php`
- `GET /backend/public/api/me.php`
- `POST /backend/public/api/logout.php`
- `GET/POST /backend/public/api/projects.php`
- `POST /backend/public/api/notify-spennliste.php`
- `GET /backend/public/api/health.php`
- `GET/POST /backend/public/api/admin/company-tier.php`
- `GET/POST /backend/public/api/admin/company-branding.php`
- `GET/POST/PATCH/DELETE /backend/public/api/admin/users.php`

## Deployment Notes

- Protect non-public backend folders (`backend/lib`, `backend/sql`, `backend/tools`) from direct web access.
- Enable HTTPS.
- Configure PHP mail/SMTP if using `notify-spennliste.php`.
- Do not commit real credentials in `backend/.env`.

## Legacy `public_html` Folder

`public_html/` contains an older admin/request system (`/admin/*` and `/api/admin/*`).
Main software pages in root currently use `backend/public/api` for authentication and project data.
