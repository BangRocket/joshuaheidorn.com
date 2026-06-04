# joshuaheidorn.com

Personal resume, portfolio, and blog for Joshua Heidorn. **PHP + MySQL** with **Svelte** islands — no Node at runtime.

## Stack

- **PHP 8.2+ / Slim 4 / PDO (MySQL)** — server runtime
- **Twig** templates, **league/commonmark** (Markdown), **Dompdf** (PDF resume)
- **Svelte 5 + Vite** — client islands (typewriter, live search); build-time only
- **Plain CSS** (NeoBrutalism); **Composer**, **Phinx** (migrations), **PHPUnit**

## Features

Home, resume (+ `/resume.pdf` download), blog posts, project case studies, standalone pages, tag/category/project-tag archives, live + full-page search, RSS (posts & projects), sitemap, dark/light mode, and a single-user `/admin` for managing everything.

## Local development

Requires Docker (for MySQL), PHP 8.2+, Composer, Node + Yarn.

```bash
docker compose up -d                       # MySQL on 127.0.0.1:3306
docker compose exec -T db mysql -uroot -e "CREATE DATABASE IF NOT EXISTS joshuaheidorn_test"
composer install
yarn install && yarn build                 # compile Svelte islands
cp .env.example .env                        # set ADMIN_PASSWORD etc.
vendor/bin/phinx migrate -e development
vendor/bin/phinx migrate -e testing
php bin/seed.php                             # import existing content
php bin/user.php                             # create the admin user
php -S 127.0.0.1:8088 -t public public/router.php
```

Visit `http://127.0.0.1:8088/` (site) and `/admin` (login with the credentials from `.env`).

## Tests

```bash
vendor/bin/phpunit
```

## Deploy (shared host with SSH + Composer)

One-time setup: copy `.deploy.env.example` to `.deploy.env` and set `DEPLOY_HOST` + `DEPLOY_PATH`; point the host's web root at `public/` (`.htaccess` rewrites to `index.php`); create `.env` on the host with production DB + admin credentials.

Then deploy with:

```bash
./deploy.sh
```

It builds the Svelte islands locally, rsyncs the app (excluding `vendor/`, `.env`, and `public/uploads/`), then runs `composer install --no-dev` and `vendor/bin/phinx migrate -e production` on the host over SSH.

First deploy only — run once on the host afterward: upload the initial `public/uploads/*`, then `php bin/seed.php` (import content) and `php bin/user.php` (create the admin user).

## Layout

```
public/      front controller, .htaccess, router (dev), css/, assets/ (built), uploads/
app/         bootstrap, routes, Controllers/, Repositories/, Support/, Middleware/, views/
islands/     Svelte components + mount entry
db/          Phinx migrations
bin/         seed.php (import), user.php (admin user)
tests/       PHPUnit
docs/        design spec + implementation plans
```
