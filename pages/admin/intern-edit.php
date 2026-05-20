<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireRole(['Admin', 'Instructor']);
if (!canAccess('interns', 'can_edit')) redirect('/pages/unauthorized.php');

$pdo = db();
$id  = intval($_GET['id'] ?? 0);

if (!$id) redirect('/pages/admin/interns.php');

$intern = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$intern->execute([$id]);
$intern = $intern->fetch();

if (!$intern) redirect('/pages/admin/interns.php');

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name  = sanitize($_POST['last_name']  ?? '');
    $company    = sanitize($_POST['company']    ?? '');
    $student_id = sanitize($_POST['student_id'] ?? '');
    $req_hours  = intval($_POST['required_hours'] ?? 70);
    $newpass    = $_POST['new_password'] ?? '';

    if (!$first_name || !$last_name) {
        $err = 'First and last name are required.';
    } else {
        $pdo->prepare("
            UPDATE users SET first_name=?, last_name=?, company=?, student_id=?, required_hours=? WHERE id=?
        ")->execute([$first_name, $last_name, $company, encryptField($student_id), $req_hours, $id]);

        if ($newpass) {
            if (strlen($newpass) < 8) {
                $err = 'New password must be at least 8 characters.';
            } else {
                $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([hashPassword($newpass), $id]);
            }
        }

        if (!$err) {
            $msg = 'Intern updated successfully.';
            $intern = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $intern->execute([$id]);
            $intern = $intern->fetch();
        }
    }
}

$attendance = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? ORDER BY date DESC LIMIT 20");
$attendance->execute([$id]);
$attendance = $attendance->fetchAll();

renderHead('Edit Intern');
?>
<div class="layout">
    <?php renderSidebar(); ?>
    <div class="main-content">
        <div class="topbar">
            <div>
                <div class="page-title">Edit Intern</div>
                <div class="page-subtitle"><?= htmlspecialchars($intern['first_name'] . ' ' . $intern['last_name']) ?></div>
            </div>
            <div class="topbar-actions">
                <a href="<?= APP_URL ?>/pages/admin/interns.php" class="btn btn-ghost btn-sm"><i data-feather="arrow-left"></i> Back</a>
            </div>
        </div>

        <div class="content-wrap">
            <?php if ($msg): ?><div class="alert alert-success"><i data-feather="check-circle"></i><?= $msg ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-danger"><i data-feather="alert-circle"></i><?= $err ?></div><?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

                <div class="card">
                    <div class="card-header"><span class="card-title">Account Details</span></div>
                    <div class="card-body">
                        <form method="POST">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                                <div class="form-group">
                                    <label>First Name</label>
                                    <input type="text" name="first_name" value="<?= htmlspecialchars($intern['first_name']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Last Name</label>
                                    <input type="text" name="last_name" value="<?= htmlspecialchars($intern['last_name']) ?>" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Email <span style="font-size:12px;color:var(--muted);font-weight:400">(cannot change)</span></label>
                                <input type="email" value="<?= htmlspecialchars($intern['email']) ?>" disabled style="background:var(--surface);color:var(--muted)">
                            </div>
                            <div class="form-group">
                                <label>Student ID</label>
                                <input type="text" name="student_id" value="">
                                <span style="font-size:11px;color:var(--muted);margin-top:3px;display:block">Leave blank to keep existing encrypted ID</span>
                            </div>
                            <div class="form-group">
                                <label>Company / OJT Establishment</label>
                                <input type="text" name="company" value="<?= htmlspecialchars($intern['company'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Required Hours</label>
                                <input type="number" name="required_hours" value="<?= $intern['required_hours'] ?>" min="1">
                            </div>

                            <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:4px">
                                <div class="form-group">
                                    <label>New Password <span style="font-size:12px;color:var(--muted);font-weight:400">(leave blank to keep current)</span></label>
                                    <input type="password" name="new_password" placeholder="Min. 8 characters">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center"><i data-feather="save"></i> Save Changes</button>
                        </form>
                    </div>
                </div>

                <div>
                    <div class="card" style="margin-bottom:16px">
                        <div class="card-header"><span class="card-title">Hours Summary</span></div>
                        <div class="card-body">
                            <?php
                            $completed = floatval($intern['completed_hours']);
                            $required  = intval($intern['required_hours']);
                            $remaining = max(0, $required - $completed);
                            $pct       = min(100, round(($completed / max(1, $required)) * 100));
                            ?>
                            <div style="display:flex;align-items:center;gap:24px">
                                <div class="hour-ring">
                                    <svg width="120" height="120" viewBox="0 0 120 120">
                                        <circle cx="60" cy="60" r="50" fill="none" stroke="var(--surface-2)" stroke-width="8"/>
                                        <circle cx="60" cy="60" r="50" fill="none" stroke="<?= $pct >= 100 ? '#20c05a' : '#e8a020' ?>" stroke-width="8"
                                            stroke-dasharray="<?= round(314.16 * $pct / 100) ?> 314.16" stroke-linecap="round"
                                            style="transform:rotate(-90deg);transform-origin:center"/>
                                    </svg>
                                    <div class="hour-ring-text">
                                        <div class="hour-ring-value"><?= $pct ?>%</div>
                                        <div class="hour-ring-label">done</div>
                                    </div>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:10px">
                                    <div>
                                        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">Completed</div>
                                        <div style="font-size:20px;font-weight:700;color:var(--accent)"><?= number_format($completed, 1) ?>h</div>
                                    </div>
                                    <div>
                                        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">Remaining</div>
                                        <div style="font-size:20px;font-weight:700"><?= number_format($remaining, 1) ?>h</div>
                                    </div>
                                    <div>
                                        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">Required</div>
                                        <div style="font-size:20px;font-weight:700;color:var(--muted)"><?= $required ?>h</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Recent Attendance</span>
                            <a href="<?= APP_URL ?>/pages/admin/attendance.php?user=<?= $id ?>" class="btn btn-ghost btn-sm"><i data-feather="arrow-right"></i> All</a>
                        </div>
                        <table>
                            <thead>
                                <tr><th>Date</th><th>In</th><th>Out</th><th>Hours</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance as $a): ?>
                                <tr>
                                    <td style="font-size:13px"><?= date('M d', strtotime($a['date'])) ?></td>
                                    <td style="font-size:13px"><?= $a['time_in'] ? date('h:i A', strtotime($a['time_in'])) : '—' ?></td>
                                    <td style="font-size:13px"><?= $a['time_out'] ? date('h:i A', strtotime($a['time_out'])) : '—' ?></td>
                                    <td><span class="badge badge-<?= $a['hours_rendered'] > 0 ? 'green' : 'gray' ?>"><?= $a['hours_rendered'] > 0 ? number_format($a['hours_rendered'],1).'h' : '—' ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (!$attendance): ?>
                                <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:20px">No records yet</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
<?php renderFoot(); ?>
