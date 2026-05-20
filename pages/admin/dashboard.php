<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireRole(['Admin', 'Instructor']);

if (!canAccess('dashboard')) redirect('/pages/unauthorized.php');

$pdo = db();

$totalInterns = $pdo->query("SELECT COUNT(*) FROM users WHERE role_id = (SELECT id FROM roles WHERE name='Intern')")->fetchColumn();
$activeToday = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM attendance WHERE date = CURDATE() AND status = 'ongoing'")->fetchColumn();
$completedToday = $pdo->query("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND status = 'completed'")->fetchColumn();
$recentLogs = $pdo->query("SELECT ll.action, ll.logged_at, ll.ip_address, u.first_name, u.last_name, u.email FROM login_logs ll LEFT JOIN users u ON ll.user_id = u.id ORDER BY ll.logged_at DESC LIMIT 8")->fetchAll();

$topInterns = $pdo->query("SELECT u.first_name, u.last_name, u.completed_hours, u.required_hours FROM users u WHERE u.role_id = (SELECT id FROM roles WHERE name='Intern') ORDER BY u.completed_hours DESC LIMIT 5")->fetchAll();

renderHead('Dashboard');
?>
<div class="layout">
    <?php renderSidebar(); ?>
    <div class="main-content">
        <div class="topbar">
            <div>
                <div class="page-title">Dashboard</div>
                <div class="page-subtitle"><?= date('l, F j, Y') ?></div>
            </div>
        </div>

        <div class="content-wrap">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue"><i data-feather="users"></i></div>
                    <div>
                        <div class="stat-value"><?= $totalInterns ?></div>
                        <div class="stat-label">Total Interns</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon amber"><i data-feather="clock"></i></div>
                    <div>
                        <div class="stat-value"><?= $activeToday ?></div>
                        <div class="stat-label">Active Today</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i data-feather="check-circle"></i></div>
                    <div>
                        <div class="stat-value"><?= $completedToday ?></div>
                        <div class="stat-label">Completed Today</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon red"><i data-feather="alert-circle"></i></div>
                    <div>
                        <div class="stat-value"><?= $pdo->query("SELECT COUNT(*) FROM login_logs WHERE action='login_failed' AND DATE(logged_at)=CURDATE()")->fetchColumn() ?></div>
                        <div class="stat-label">Failed Logins Today</div>
                    </div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Top Interns by Hours</span>
                        <a href="<?= APP_URL ?>/pages/admin/attendance.php" class="btn btn-ghost btn-sm"><i data-feather="arrow-right"></i> View All</a>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Intern</th>
                                <th>Progress</th>
                                <th>Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topInterns as $intern): ?>
                            <?php $pct = min(100, round(($intern['completed_hours'] / max(1, $intern['required_hours'])) * 100)); ?>
                            <tr>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <div class="avatar-sm"><?= strtoupper(substr($intern['first_name'], 0, 1)) ?></div>
                                        <span><?= htmlspecialchars($intern['first_name'] . ' ' . $intern['last_name']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="progress-bar-wrap">
                                        <div class="progress-bar-fill" style="width:<?= $pct ?>%"></div>
                                    </div>
                                </td>
                                <td><span class="badge badge-<?= $pct >= 100 ? 'green' : 'blue' ?>"><?= $intern['completed_hours'] ?> / <?= $intern['required_hours'] ?>h</span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$topInterns): ?>
                            <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:20px">No intern data yet</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                
            </div>
        </div>
    </div>
</div>
<?php renderFoot(); ?>
