# Malakia — Idea Generation Platform

Capture the spark before it fades. A self-contained platform for capturing ideas,
charging the ones worth pursuing (votes), discussing them (comments), and tracking
each from **spark → exploring → building → shipped**.

Built as a lightweight **PHP 8 + SQLite** front-controller app — no Composer, no
database server, no build step. Drop it on any PHP host and it runs.

---

## Quick start

### Option A — any PHP host (cPanel / LiteSpeed / Apache / Nginx)
1. Upload the whole folder to your web root (or a subfolder).
2. Make sure the `database/` folder is writable by PHP.
3. Visit the site. On first load it auto-creates the SQLite database, runs the
   schema, and seeds demo data.

The included `.htaccess` routes all requests through `index.php` and blocks direct
access to the `.sqlite` file. On Nginx, route unknown paths to `index.php`.

### Option B — local, zero config
```bash
php -S localhost:8000 router.php
```
Then open http://localhost:8000

### Demo login
```
email:    demo@malakia.app
password: demo1234
```

---

## What it does
- **Auth** — register / login / logout, hashed passwords, session hardening, CSRF on every form, session-id regeneration on login.
- **Idea wall** — public, searchable, filter by category, sort by newest or most-charged.
- **Capture** — title, description, category, tags, lifecycle status.
- **Charge (vote)** — one charge per person per idea, toggle on/off, live AJAX count.
- **Discuss** — threaded comments on each idea.
- **Dashboard** — your ideas + personal stats.
- **Ownership** — only the author can edit or delete an idea (others get a 403).

## Project layout
```
config.php          app config, DB connection, migrations + seed
router.php          dev router for `php -S`
.htaccess           production routing + security
index.php           front controller (all routes)
lib/                helpers, auth, ideas (business logic)
views/              layout, pages, partials
assets/             app.css (design system), app.js (interactions)
database/           SQLite file lives here (auto-created)
```


