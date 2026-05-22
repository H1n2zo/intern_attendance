<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/settings.php';

function buildMailer() {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USER;
    $mail->Password   = MAIL_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->isHTML(true);
    return $mail;
}

function sendMail($toEmail, $toName, $subject, $body) {
    try {
        $mail = buildMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->Body    = wrapEmailLayout($body);
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mailer: ' . $e->getMessage());
        return false;
    }
}

function sendMFACode($toEmail, $toName, $code) {
    $body = "
        <p style='color:#333;font-size:15px'>Hi <strong>$toName</strong>,</p>
        <p style='color:#555;font-size:14px'>Use this code to complete your login. It expires in 5 minutes.</p>
        <div style='background:#f0f4f8;border-radius:8px;padding:20px;text-align:center;margin:24px 0'>
            <span style='font-size:36px;font-weight:700;letter-spacing:8px;color:#1a3c5e'>$code</span>
        </div>
        <p style='color:#999;font-size:12px'>If you did not request this, please ignore this email.</p>";
    return sendMail($toEmail, $toName, 'Your EVSU-OC Verification Code', $body);
}

function sendTimeInConfirmation($intern) {
    if (!getSetting('notify_intern_timein')) return;
    $name    = htmlspecialchars($intern['first_name'] . ' ' . $intern['last_name']);
    $time    = date('h:i A');
    $date    = date('l, F j, Y');
    $company = htmlspecialchars($intern['company'] ?: 'your assigned company');

    $body = "
        <p style='color:#333;font-size:15px'>Hi <strong>{$intern['first_name']}</strong>,</p>
        <p style='color:#555;font-size:14px'>Your time-in has been recorded successfully.</p>
        " . buildInfoRow('📍 Location', $company)
          . buildInfoRow('🕐 Time In', $time)
          . buildInfoRow('📅 Date', $date) . "
        <p style='color:#999;font-size:12px;margin-top:20px'>Remember to time out before leaving.</p>";

    sendMail($intern['email'], $intern['first_name'], 'Time-In Recorded — ' . $date, $body);
}

function sendTimeOutConfirmation($intern, $attendance) {
    if (!getSetting('notify_intern_timeout')) return;
    $date       = date('l, F j, Y');
    $timeIn     = date('h:i A', strtotime($attendance['time_in']));
    $timeOut    = date('h:i A');
    $rendered   = number_format($attendance['hours_rendered'], 2);
    $completed  = number_format($intern['completed_hours'], 2);
    $required   = $intern['required_hours'];
    $remaining  = number_format(max(0, $required - $intern['completed_hours']), 2);
    $pct        = min(100, round(($intern['completed_hours'] / max(1, $required)) * 100));

    $body = "
        <p style='color:#333;font-size:15px'>Hi <strong>{$intern['first_name']}</strong>,</p>
        <p style='color:#555;font-size:14px'>Great work today! Here's your attendance summary.</p>
        " . buildInfoRow('📅 Date', $date)
          . buildInfoRow('🕐 Time In', $timeIn)
          . buildInfoRow('🕔 Time Out', $timeOut)
          . buildInfoRow('⏱ Hours Today', $rendered . ' hrs') . "
        <div style='background:#f0f4f8;border-radius:8px;padding:16px 20px;margin:20px 0'>
            <p style='margin:0 0 8px;font-size:13px;color:#666;font-weight:600;text-transform:uppercase;letter-spacing:.04em'>Overall Progress</p>
            <div style='background:#dde3ef;border-radius:99px;height:8px;margin-bottom:8px'>
                <div style='background:#e8a020;height:8px;border-radius:99px;width:{$pct}%'></div>
            </div>
            <p style='margin:0;font-size:13px;color:#333'><strong>{$completed}</strong> of <strong>{$required}</strong> hrs completed &nbsp;·&nbsp; <strong>{$remaining}</strong> hrs remaining</p>
        </div>
        <p style='color:#999;font-size:12px'>Keep it up — you're {$pct}% there!</p>";

    sendMail($intern['email'], $intern['first_name'], 'Time-Out Recorded — ' . $date, $body);
}

function notifyInstructorTimeIn($intern) {
    if (!getSetting('notify_instructor_timein')) return;
    $instructors = getInstructorEmails();
    if (!$instructors) return;

    $name    = htmlspecialchars($intern['first_name'] . ' ' . $intern['last_name']);
    $time    = date('h:i A');
    $date    = date('l, F j, Y');
    $company = htmlspecialchars($intern['company'] ?: '—');

    $body = "
        <p style='color:#333;font-size:15px'>Attendance update</p>
        <p style='color:#555;font-size:14px'><strong>$name</strong> has clocked in.</p>
        " . buildInfoRow('👤 Intern', $name)
          . buildInfoRow('🏢 Company', $company)
          . buildInfoRow('🕐 Time In', $time)
          . buildInfoRow('📅 Date', $date);

    foreach ($instructors as $ins) {
        sendMail($ins['email'], $ins['first_name'], "Time-In: $name — $date", $body);
    }
}

function notifyInstructorTimeOut($intern, $attendance) {
    if (!getSetting('notify_instructor_timeout')) return;
    $instructors = getInstructorEmails();
    if (!$instructors) return;

    $name     = htmlspecialchars($intern['first_name'] . ' ' . $intern['last_name']);
    $date     = date('l, F j, Y');
    $timeIn   = date('h:i A', strtotime($attendance['time_in']));
    $timeOut  = date('h:i A');
    $rendered = number_format($attendance['hours_rendered'], 2);
    $company  = htmlspecialchars($intern['company'] ?: '—');

    $body = "
        <p style='color:#333;font-size:15px'>Attendance update</p>
        <p style='color:#555;font-size:14px'><strong>$name</strong> has clocked out.</p>
        " . buildInfoRow('👤 Intern', $name)
          . buildInfoRow('🏢 Company', $company)
          . buildInfoRow('🕐 Time In', $timeIn)
          . buildInfoRow('🕔 Time Out', $timeOut)
          . buildInfoRow('⏱ Hours Today', $rendered . ' hrs');

    foreach ($instructors as $ins) {
        sendMail($ins['email'], $ins['first_name'], "Time-Out: $name — $date", $body);
    }
}

function notifyLateIntern($intern) {
    if (!getSetting('notify_late_flag')) return;
    $instructors = getInstructorEmails();
    if (!$instructors) return;

    $name      = htmlspecialchars($intern['first_name'] . ' ' . $intern['last_name']);
    $threshold = getSetting('late_threshold_hour') . ':00';
    $date      = date('l, F j, Y');

    $body = "
        <p style='color:#333;font-size:15px'>Late intern alert</p>
        <p style='color:#555;font-size:14px'><strong>$name</strong> has not clocked in yet as of <strong>$threshold</strong>.</p>
        " . buildInfoRow('👤 Intern', $name)
          . buildInfoRow('🏢 Company', htmlspecialchars($intern['company'] ?: '—'))
          . buildInfoRow('📅 Date', $date)
          . buildInfoRow('⚠ Status', 'Late — no time-in recorded') . "
        <p style='color:#999;font-size:12px;margin-top:16px'>You may want to follow up with this intern.</p>";

    foreach ($instructors as $ins) {
        sendMail($ins['email'], $ins['first_name'], "Late Alert: $name — $date", $body);
    }
}

function notifyMissingIntern($intern) {
    if (!getSetting('notify_missing_flag')) return;
    $instructors = getInstructorEmails();
    if (!$instructors) return;

    $name = htmlspecialchars($intern['first_name'] . ' ' . $intern['last_name']);
    $date = date('l, F j, Y');

    $body = "
        <p style='color:#333;font-size:15px'>Missing attendance alert</p>
        <p style='color:#555;font-size:14px'><strong>$name</strong> has no attendance record for today.</p>
        " . buildInfoRow('👤 Intern', $name)
          . buildInfoRow('🏢 Company', htmlspecialchars($intern['company'] ?: '—'))
          . buildInfoRow('📅 Date', $date)
          . buildInfoRow('⚠ Status', 'No record — absent or did not log in') . "
        <p style='color:#999;font-size:12px;margin-top:16px'>Please verify with the intern or their supervisor.</p>";

    foreach ($instructors as $ins) {
        sendMail($ins['email'], $ins['first_name'], "Missing Attendance: $name — $date", $body);
    }
}

function getInstructorEmails() {
    $pdo = db();
    $stmt = $pdo->query("SELECT first_name, email FROM users WHERE role_id = (SELECT id FROM roles WHERE name='Instructor') AND is_active = 1");
    return $stmt->fetchAll();
}

function buildInfoRow($label, $value) {
    return "<div style='display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0;font-size:14px'>
        <span style='color:#888'>$label</span>
        <span style='color:#1a3c5e;font-weight:600'>$value</span>
    </div>";
}

function wrapEmailLayout($body) {
    return "
    <div style='font-family:Arial,sans-serif;max-width:500px;margin:auto;background:#f5f5f5;border-radius:12px;overflow:hidden'>
        <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background:#0f2744'>
            <tr>
                <td style='padding:20px 28px;vertical-align:middle'>
                    <table cellpadding='0' cellspacing='0' border='0'>
                        <tr>
                            <td valign='middle'>
                                <table cellpadding='0' cellspacing='0' border='0' style='display:inline-table'>
                                    <tr>
                                        <td width='32' height='32' align='center' valign='middle'
                                            style='background:#e8a020;border-radius:7px;width:32px;height:32px'>
                                            <span style='color:#0f2744;font-size:16px;font-weight:700;line-height:1'>🎓</span>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                            <td valign='middle' style='padding-left:12px'>
                                <p style='color:#fff;margin:0;font-size:16px;font-weight:600'>EVSU-OC Attendance</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
        <div style='padding:28px;background:#fff'>$body</div>
        <div style='background:#f5f5f5;padding:14px 28px;text-align:center'>
            <p style='color:#aaa;font-size:11px;margin:0'>Eastern Visayas State University — Ormoc Campus · Do not reply to this email</p>
        </div>
    </div>";
}
