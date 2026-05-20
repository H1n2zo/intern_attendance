<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/settings.php';

requireLogin();
requireRole(['Admin']);
if (!canAccess('settings')) redirect('/pages/unauthorized.php');

$pdo = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allSettings = $pdo->query("SELECT key_name FROM settings")->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $pdo->prepare("UPDATE settings SET value = ? WHERE key_name = ?");

    foreach ($allSettings as $key) {
        if (in_array($key, ['notify_intern_timein','notify_intern_timeout','notify_instructor_timein','notify_instructor_timeout','notify_late_flag','notify_missing_flag'])) {
            $val = isset($_POST[$key]) ? '1' : '0';
        } else {
            $val = sanitize($_POST[$key] ?? '');
        }
        if ($val !== '') $stmt->execute([$val, $key]);
    }
    $msg = 'Settings saved.';
}

$attendanceSettings = getSettingGroup('attendance');

$toggleKeys = ['notify_intern_timein','notify_intern_timeout','notify_instructor_timein','notify_instructor_timeout','notify_late_flag','notify_missing_flag'];

renderHead('Settings');
?>
<div class="layout">
    <?php renderSidebar(); ?>
    <div class="main-content">
        <div class="topbar">
            <div>
                <div class="page-title">Settings</div>
                <div class="page-subtitle">Notification and system configuration</div>
            </div>
        </div>

        <div class="content-wrap" style="max-width:680px">
            <?php if ($msg): ?>
            <div class="alert alert-success"><i data-feather="check-circle"></i><?= $msg ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="card" style="margin-bottom:20px">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Attendance Notifications</div>
                            <div style="font-size:12px;color:var(--muted);margin-top:2px">Control which emails are sent when interns record attendance</div>
                        </div>
                    </div>
                    <div style="padding:0 20px">

                        <div style="padding:16px 0;border-bottom:1px solid var(--surface)">
                            <p style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">Intern Emails</p>
                            <?php foreach ($attendanceSettings as $s):
                                if (!in_array($s['key_name'], ['notify_intern_timein','notify_intern_timeout'])) continue; ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:0.5px solid var(--surface)">
                                <div>
                                    <div style="font-size:14px;font-weight:500"><?= htmlspecialchars($s['label']) ?></div>
                                    <div style="font-size:12px;color:var(--muted);margin-top:2px"><?= htmlspecialchars($s['description']) ?></div>
                                </div>
                                <label class="toggle-switch" style="flex-shrink:0;margin-left:16px">
                                    <input type="checkbox" name="<?= $s['key_name'] ?>" <?= $s['value'] ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="padding:16px 0;border-bottom:1px solid var(--surface)">
                            <p style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">Instructor Emails</p>
                            <?php foreach ($attendanceSettings as $s):
                                if (!in_array($s['key_name'], ['notify_instructor_timein','notify_instructor_timeout'])) continue; ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:0.5px solid var(--surface)">
                                <div>
                                    <div style="font-size:14px;font-weight:500"><?= htmlspecialchars($s['label']) ?></div>
                                    <div style="font-size:12px;color:var(--muted);margin-top:2px"><?= htmlspecialchars($s['description']) ?></div>
                                </div>
                                <label class="toggle-switch" style="flex-shrink:0;margin-left:16px">
                                    <input type="checkbox" name="<?= $s['key_name'] ?>" <?= $s['value'] ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="padding:16px 0">
                            <p style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">Late &amp; Missing Alerts</p>

                            <?php foreach ($attendanceSettings as $s):
                                if (!in_array($s['key_name'], ['notify_late_flag','notify_missing_flag'])) continue; ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:0.5px solid var(--surface)">
                                <div>
                                    <div style="font-size:14px;font-weight:500"><?= htmlspecialchars($s['label']) ?></div>
                                    <div style="font-size:12px;color:var(--muted);margin-top:2px"><?= htmlspecialchars($s['description']) ?></div>
                                </div>
                                <label class="toggle-switch" style="flex-shrink:0;margin-left:16px">
                                    <input type="checkbox" name="<?= $s['key_name'] ?>" <?= $s['value'] ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <?php endforeach; ?>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
                                <?php foreach ($attendanceSettings as $s):
                                    if (!in_array($s['key_name'], ['late_threshold_hour','missing_check_hour'])) continue; ?>
                                <div class="form-group" style="margin-bottom:0">
                                    <label style="font-size:13px"><?= htmlspecialchars($s['label']) ?></label>
                                    <input type="number" name="<?= $s['key_name'] ?>" value="<?= htmlspecialchars($s['value']) ?>" min="0" max="23" style="width:100%">
                                    <span style="font-size:11px;color:var(--muted);margin-top:4px;display:block"><?= htmlspecialchars($s['description']) ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="card" style="margin-bottom:20px">
                    <div class="card-header">
                        <div class="card-title">Mail Configuration</div>
                    </div>
                    <div class="card-body">
                        <div style="background:var(--surface);border-radius:8px;padding:14px 16px;font-size:13px;color:var(--muted);margin-bottom:0">
                            <i data-feather="info" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px"></i>
                            SMTP settings are managed in <code style="background:var(--surface-2);padding:1px 5px;border-radius:4px">config/config.php</code>.
                            Update <code>MAIL_HOST</code>, <code>MAIL_USER</code>, <code>MAIL_PASS</code>, and <code>MAIL_PORT</code> there.
                        </div>
                    </div>
                </div>

                <div style="display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-primary"><i data-feather="save"></i> Save Settings</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php renderFoot(); ?>
