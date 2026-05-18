# EVSU-OC Internship Attendance System

## Project Structure

```
evsu-attendance/
├── index.php                    # Login + MFA page
├── config/
│   ├── config.php               # App constants (DB, mail, keys)
│   ├── database.php             # PDO singleton connection
│   └── schema.sql               # Full DB schema + seed data
├── includes/
│   ├── auth.php                 # Session, RBAC, encryption helpers
│   └── layout.php              # Sidebar, header, footer renderers
├── mailer/
│   └── mailer.php              # PHPMailer MFA email sender
├── assets/
│   ├── css/main.css            # Full stylesheet
│   └── img/selfies/            # Auto-created selfie storage (per user)
├── pages/
│   ├── logout.php
│   ├── unauthorized.php
│   ├── admin/
│   │   ├── dashboard.php       # Stats, recent activity
│   │   ├── interns.php         # Intern CRUD (RBAC-gated)
│   │   ├── attendance.php      # View/filter attendance records
│   │   ├── logs.php            # Login audit log
│   │   ├── roles.php           # Role management + permissions grid
│   │   └── modules.php         # Sidebar module management
│   └── intern/
│       └── home.php            # Time-in/out with live selfie
└── vendor/                     # Composer dependencies (PHPMailer)
```

## Setup

### 1. Install dependencies
```bash
composer require phpmailer/phpmailer
```

### 2. Create database
```bash
mysql -u root -p < config/schema.sql
```

### 3. Configure
Edit `config/config.php`:
- Set `DB_USER`, `DB_PASS`
- Set `MAIL_USER`, `MAIL_PASS` (Gmail app password)
- Change `ENCRYPTION_KEY` and `JWT_SECRET` to strong random strings
- Update `APP_URL` to match your server

### 4. Create an admin user
Run this SQL after setup:
```sql
INSERT INTO users (role_id, first_name, last_name, email, password_hash, is_verified)
VALUES (1, 'Admin', 'User', 'admin@evsu.edu.ph', '$2y$12$...bcrypt_hash...', 1);
```
Or use `password_hash('yourpassword', PASSWORD_BCRYPT, ['cost'=>12])` in PHP.

## Default Roles
| Role       | Access |
|------------|--------|
| Admin      | Full system — including Roles & Modules management |
| Instructor | Dashboard, Attendance, Reports, Logs (view only) |
| Intern     | Own time-in/out page only |

## Security Notes
- Only `@evsu.edu.ph` emails can register
- Passwords hashed with bcrypt (cost 12)
- Student IDs encrypted with AES-256-CBC
- MFA codes sent via PHPMailer, expire in 5 minutes
- All DB queries use prepared statements
- Session cookies are httpOnly + SameSite=Strict
- Row-level access enforced via `role_modules` table
