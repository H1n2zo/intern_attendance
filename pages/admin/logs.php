<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireRole(['Admin', 'Instructor']);
if (!canAccess('logs')) redirect('/pages/unauthorized.php');

$pdo = db();

$filter = sanitize($_GET['action'] ?? '');
$filterDate = sanitize($_GET['date'] ?? '');

$query = "SELECT ll.*, u.first_name, u.last_name FROM login_logs ll LEFT JOIN users u ON ll.user_id = u.id WHERE 1=1";
$params = [];

if ($filter) { $query .= " AND ll.action = ?"; $params[] = $filter; }
if ($filterDate) { $query .= " AND DATE(ll.logged_at) = ?"; $params[] = $filterDate; }

$query .= " ORDER BY ll.logged_at DESC LIMIT 200";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actions = ['login_success','login_failed','logout','timeout','mfa_sent','mfa_failed'];

renderHead('Login Logs');
?>
<div class="layout">
    <?php renderSidebar(); ?>
    <div class="main-content">
        <div class="topbar">
            <div>
                <div class="page-title">Login Logs</div>
                <div class="page-subtitle">All authentication activity</div>
            </div>
            <div class="topbar-actions">
                <form method="GET" style="display:flex;gap:8px;align-items:center">
                    <select name="action" style="width:auto;padding:7px 10px;font-size:13px" onchange="this.form.submit()">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $a): ?>
                        <option value="<?= $a ?>" <?= $filter === $a ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$a)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>" style="width:auto;padding:7px 10px;font-size:13px" onchange="this.form.submit()">
                    <?php if ($filter || $filterDate): ?>
                    <a href="logs.php" class="btn btn-ghost btn-sm"><i data-feather="x"></i> Clear</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="content-wrap">
            <div class="card">
                <table>
                    <thead>
                        <tr><th>User</th><th>Action</th><th>IP Address</th><th>Date & Time</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $actionMap = [
                            'login_success' => ['badge-green','Logged In'],
                            'login_failed' => ['badge-red','Failed Login'],
                            'logout' => ['badge-gray','Logged Out'],
                            'mfa_sent' => ['badge-blue','MFA Sent'],
                            'mfa_failed' => ['badge-amber','MFA Failed'],
                            'timeout' => ['badge-gray','Session Timeout'],
                        ];
                        ?>
                        <?php foreach ($logs as $log): ?>
                        <?php [$cls, $label] = $actionMap[$log['action']] ?? ['badge-gray', $log['action']]; ?>
                        <tr>
                            <td>
                                <div style="font-size:14px;font-weight:500"><?= $log['first_name'] ? htmlspecialchars($log['first_name'].' '.$log['last_name']) : '—' ?></div>
                                <div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($log['email'] ?? '') ?></div>
                            </td>
                            <td><span class="badge <?= $cls ?>"><?= $label ?></span></td>
                            <td style="font-size:13px;color:var(--muted);font-family:monospace"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                            <td style="font-size:13px;color:var(--muted)"><?= date('M d, Y h:i:s A', strtotime($log['logged_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$logs): ?>
                        <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:32px">No logs found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php renderFoot(); ?>
