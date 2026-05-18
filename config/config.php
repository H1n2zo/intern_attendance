<?php
define('APP_NAME', 'EVSU-OC Internship Attendance');
define('APP_URL', 'http://localhost/evsu-attendance');
define('ALLOWED_DOMAIN', '@evsu.edu.ph');

define('DB_HOST', 'localhost');
define('DB_NAME', 'evsu_attendance');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('ENCRYPTION_KEY', 'CHANGE_THIS_TO_A_SECURE_32_CHAR_KEY');
define('JWT_SECRET', 'CHANGE_THIS_JWT_SECRET_KEY');

define('SESSION_LIFETIME', 3600);
define('MFA_CODE_EXPIRY', 300);
define('REQUIRED_HOURS', 70);

define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USER', 'hansmichael.gabor@evsu.edu.ph');
define('MAIL_PASS', 'nmke mxws gcsh dmnk');
define('MAIL_FROM', 'noreply@evsu.edu.ph');
define('MAIL_FROM_NAME', 'EVSU-OC Intern Attendance');
