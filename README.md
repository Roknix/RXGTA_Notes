# RoknixRP GTA Notes

A self-hosted web app for managing GTA roleplay character notes. Supports multiple characters with fully separated data per character.

## Features

- **Characters** — Multiple characters per user, each with independent data
- **Dashboard** — Overview of active orders, liabilities, and claims
- **Biography / Public Profile** — Optional public shareable profile page with selectable fields
- **Contacts** — Contact management with grouping and role info
- **Items & Recipes** — Item inventory with components and locations
- **Vehicles** — Vehicle management with service dates and hideouts
- **Storage** — Storage locations with PIN and notes
- **Locations** — Location database with legality flags
- **Buyers** — Buyer management with needs and priorities
- **Liabilities & Claims** — Track debts owed and claims to collect
- **Work Orders** — Task management with priorities and deadlines
- **Notes** — Free-text Markdown notes per character
- **i18n** — German and English UI (user-selectable)
- **Multi-user** — Admin can create users; first registration becomes admin

## Requirements

- **PHP** 8.0 or higher (tested on 8.5)
- **MySQL** 8.0 or higher (via PDO)
- **Apache** with `mod_rewrite` enabled
- **PHP extensions:** `pdo_mysql`, `openssl`
- No Node.js, no Composer, no build step required

## Installation

### 1. Upload files

Upload the contents of the `webapp/` directory to your web server's document root (or a subdirectory).

### 2. Configure Apache

Ensure `mod_rewrite` is enabled and `AllowOverride All` is set for the directory. The included `.htaccess` handles URL rewriting.

### 3. Create the database

Create a MySQL database and user in your hosting panel, e.g.:

```sql
CREATE DATABASE gtanotes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gtanotes'@'localhost' IDENTIFIED BY 'your-password';
GRANT ALL PRIVILEGES ON gtanotes.* TO 'gtanotes'@'localhost';
```

### 4. Configure credentials

Open `webapp/includes/config.php` and visit the app in your browser once. It will auto-generate `webapp/data/secret.php` with a random `APP_PEPPER`. Then fill in your database credentials in that file:

```php
define('APP_PEPPER', 'auto-generated-keep-this-secret');
define('DB_HOST', 'localhost');       // your DB host
define('DB_NAME', 'gtanotes');        // your DB name
define('DB_USER', 'gtanotes');        // your DB user
define('DB_PASS', 'your-password');   // your DB password
```

> **Important:** Never change `APP_PEPPER` after users have been created — all passwords will become invalid.

### 5. First login

Visit the app in your browser. The first user to register automatically becomes admin. After that, new registrations are disabled; the admin can create additional users via the admin panel.

## Security notes

- `webapp/data/` is protected by `.htaccess` (deny all direct access)
- `webapp/includes/` is protected by `.htaccess` (deny all direct access)
- `webapp/data/secret.php` must never be committed to version control
- The app uses CSRF tokens, bcrypt+pepper passwords, session fingerprinting, and brute-force lockouts
- Content Security Policy is set dynamically via PHP (no `unsafe-inline` for scripts)

## Vendor libraries

The following third-party libraries are included in `webapp/assets/vendor/`:

| Library | Version | License |
|---|---|---|
| [Toast UI Editor](https://github.com/nhn/tui.editor) | 3.2.2 | MIT |
| [EasyMDE](https://github.com/Ionaru/easy-markdown-editor) | — | MIT |

## License

[MIT License](LICENSE) — Copyright (c) 2026 Roknix

Use at your own risk. The software is provided "as is", without warranty of any kind.

## Built with

Developed with the assistance of [Claude Code](https://claude.com/claude-code) by Anthropic.
