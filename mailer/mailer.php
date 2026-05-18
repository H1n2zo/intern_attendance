<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

function sendMFACode($toEmail, $toName, $code) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USER;
        $mail->Password = MAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = MAIL_PORT;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = 'Your EVSU-OC Verification Code';
        $mail->Body = buildMFAEmailTemplate($toName, $code);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mailer Error: ' . $mail->ErrorInfo);
        return false;
    }
}

function buildMFAEmailTemplate($name, $code) {
    return "
    <div style='font-family:Arial,sans-serif;max-width:480px;margin:auto;background:#f5f5f5;border-radius:12px;overflow:hidden'>
        <div style='background:#1a3c5e;padding:24px;text-align:center'>
            <p style='color:#fff;margin:0;font-size:18px;font-weight:600'>EVSU-OC Attendance</p>
        </div>
        <div style='padding:32px;background:#fff'>
            <p style='color:#333;font-size:15px'>Hi <strong>$name</strong>,</p>
            <p style='color:#555;font-size:14px'>Use this code to complete your login. It expires in 5 minutes.</p>
            <div style='background:#f0f4f8;border-radius:8px;padding:20px;text-align:center;margin:24px 0'>
                <span style='font-size:36px;font-weight:700;letter-spacing:8px;color:#1a3c5e'>$code</span>
            </div>
            <p style='color:#999;font-size:12px'>If you did not request this, please ignore this email.</p>
        </div>
    </div>";
}
