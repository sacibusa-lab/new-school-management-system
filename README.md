<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# School Management System

One application replacing three separate platforms — admissions, results and
school fees — for a single school. Built on Laravel 13, Tailwind CSS v4 and
Alpine.js.

## How the pipeline works

1. **Register** — the school office registers each candidate (typed in, or a
   spreadsheet of many at once) and they are given a permanent registration
   number such as `SAC-00001`, in ascending order.
2. **Examine** — candidates sit the paper-and-pencil entrance examination.
   Marks are captured by hand, uploaded from a spreadsheet, or read off a scanned
   scoresheet by AI, which always lands in a review queue for a human to approve.
3. **Decide** — the exam officer sets a cutoff mark. Applicants at or above it are
   transferred automatically into **both** the results portal and the fees portal,
   receiving a year-scoped admission number such as `SAC/2026/001`, a portal
   login, and their first fee invoice.
4. **Resit** — a candidate who misses the cutoff can be entered for a resit, which
   repeats only the papers they failed.

Admission letters can be printed or downloaded as PDF, and text messages are sent
to guardians at each stage (simulated until an SMS gateway key is configured).

## Two number series

| Number | Looks like | Issued | Scope |
| --- | --- | --- | --- |
| Registration number | `SAC-00001` | On application | Global, ascending forever |
| Admission number | `SAC/2026/001` | On admission | Restarts each academic year |

## Roles

Super Admin, Exam Officer, Teacher, Student, Parent / Guardian, Bursar / Accounts,
Admission Officer.

## Setting it up

Requires PHP 8.4+, Composer, Node and MySQL.

```sh
composer install
npm install

cp .env.example .env
php artisan key:generate
# then set DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env

php artisan migrate --seed
npm run build

# Required: applicant photographs and scanned documents are stored on the
# `public` disk, and this symlink is not committed.
php artisan storage:link
```

## Running it locally

```sh
php artisan serve
npm run dev            # in a second terminal, while developing
```

## Tests

```sh
php artisan test
```

The suite runs against MySQL rather than SQLite, because several model scopes use
MySQL functions. Point `DB_DATABASE` at a throwaway database (the default in
`phpunit.xml` is `saci_all_test`) — it is migrated and rolled back per test.

## Notes

- Public self-registration is **off by default**: registration is done by the
  school office. It can be reopened from *Settings → Admissions → Registration
  open*, and the public pages change to match.
- No queue worker is required. Text messages send inline, and anything queued can
  be flushed from the messages screen.

---

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
