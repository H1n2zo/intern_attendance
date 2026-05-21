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

## Security Notes
- Only `@evsu.edu.ph` emails can register
- Passwords hashed with bcrypt (cost 12)
- Student IDs encrypted with AES-256-CBC
- MFA codes sent via PHPMailer, expire in 5 minutes
- All DB queries use prepared statements
- Session cookies are httpOnly + SameSite=Strict
- Row-level access enforced via `role_modules` table
