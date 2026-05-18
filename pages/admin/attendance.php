<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireRole(['Admin', 'Instructor']);
if (!canAccess('attendance')) redirect('/pages/unauthorized.php');

$pdo = db();

$filterUser = isset($_GET['user']) ? intval($_GET['user']) : null;
$filterDate = sanitize($_GET['date'] ?? '');

$query = "SELECT a.*, u.first_name, u.last_name, u.email FROM attendance a JOIN users u ON a.user_id = u.id WHERE 1=1";
$params = [];

if ($filterUser) { $query .= " AND a.user_id = ?"; $params[] = $filterUser; }
if ($filterDate) { $query .= " AND a.date = ?"; $params[] = $filterDate; }

$query .= " ORDER BY a.date DESC, a.time_in DESC LIMIT 100";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();

$filterUserName = '';
if ($filterUser) {
    $u = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $u->execute([$filterUser]);
    $u = $u->fetch();
    if ($u) $filterUserName = $u['first_name'] . ' ' . $u['last_name'];
}

renderHead('Attendance Records');
?>
<div class="layout">
    <?php renderSidebar(); ?>
    <div class="main-content">
        <div class="topbar">
            <div>
                <div class="page-title">Attendance Records<?= $filterUserName ? " — $filterUserName" : '' ?></div>
                <div class="page-subtitle"><?= count($records) ?> record(s)</div>
            </div>
            <div class="topbar-actions">
                <form method="GET" style="display:flex;gap:8px;align-items:center">
                    <?php if ($filterUser): ?><input type="hidden" name="user" value="<?= $filterUser ?>"><?php endif; ?>
                    <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>" style="width:auto;padding:7px 10px;font-size:13px">
                    <button type="submit" class="btn btn-ghost btn-sm"><i data-feather="filter"></i> Filter</button>
                    <?php if ($filterUser || $filterDate): ?>
                    <a href="attendance.php" class="btn btn-ghost btn-sm"><i data-feather="x"></i> Clear</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="content-wrap">
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Intern</th>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Hours</th>
                            <th>Selfies</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div class="avatar-sm"><?= strtoupper(substr($r['first_name'],0,1)) ?></div>
                                    <div>
                                        <div style="font-size:14px;font-weight:600"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
                                        <div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($r['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="font-size:13px"><?= date('M d, Y', strtotime($r['date'])) ?></td>
                            <td style="font-size:13px"><?= $r['time_in'] ? date('h:i A', strtotime($r['time_in'])) : '—' ?></td>
                            <td style="font-size:13px"><?= $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—' ?></td>
                            <td>
                                <?php if ($r['hours_rendered'] > 0): ?>
                                <span class="badge badge-green"><?= number_format($r['hours_rendered'], 2) ?>h</span>
                                <?php else: ?>
                                <span class="badge badge-gray">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:4px">
                                    <?php if ($r['selfie_in']): ?>
                                    <img src="<?= APP_URL ?>/<?= htmlspecialchars($r['selfie_in']) ?>" class="selfie-thumb" title="Time-in selfie" onclick="viewSelfie(this.src)">
                                    <?php else: ?>
                                    <span style="font-size:12px;color:var(--muted)">—</span>
                                    <?php endif; ?>
                                    <?php if ($r['selfie_out']): ?>
                                    <img src="<?= APP_URL ?>/<?= htmlspecialchars($r['selfie_out']) ?>" class="selfie-thumb" title="Time-out selfie" onclick="viewSelfie(this.src)">
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-<?= $r['status'] === 'completed' ? 'green' : 'amber' ?>">
                                    <?= ucfirst($r['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$records): ?>
                        <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:32px">No attendance records found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="selfie-modal" onclick="this.classList.remove('open')">
    <div style="max-width:360px;border-radius:14px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4)">
        <img id="selfie-preview" src="" style="width:100%;display:block">
    </div>
</div>

<script>
function viewSelfie(src) {
    document.getElementById('selfie-preview').src = src;
    document.getElementById('selfie-modal').classList.add('open');
}
</script>

<?php renderFoot(); ?>
