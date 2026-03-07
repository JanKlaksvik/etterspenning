# Webhotel Checklist (PHP + MariaDB)

Use this list to confirm your existing webhotel is enough for production.

## 1. Runtime support

- [ ] PHP 8.0+ available (`php -v` or hosting panel info)
- [ ] PDO MySQL extension enabled (`pdo_mysql`)
- [ ] Sessions enabled (`session_start` works)
- [ ] HTTPS/SSL certificate active on your domain

## 2. Database support

- [ ] MariaDB or MySQL database available
- [ ] You can create/import SQL files
- [ ] You can create a dedicated DB user with password
- [ ] Remote DB access is **not** required (same-host DB is fine)

## 3. Web server behavior

- [ ] PHP executes in your web root (or a subfolder like `backend/public/`)
- [ ] You can prevent directory listing for backend folders
- [ ] You can upload/update files safely (SFTP/SSH/panel)

## 4. Security baseline

- [ ] `HTTPS` forced (redirect HTTP -> HTTPS)
- [ ] Strong DB password and separate DB user for this site
- [ ] `.env` file stored outside public listing or access-blocked
- [ ] Regular backup of DB enabled in hosting panel

## 5. Email and operations

- [ ] SMTP/mail sending available for notifications and password reset flows
- [ ] Access to error logs (PHP/web server) in hosting panel
- [ ] Cron support available (optional, useful later for cleanup tasks)

## 6. What is enough for your setup

Your webhotel is enough **if all boxes above are true**. You do not need another server.

If PHP execution or DB is missing, you need either:

1. Upgrade the current hosting plan, or
2. Add a small backend host and keep the frontend where it is.
