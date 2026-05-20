<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireRole(['Admin', 'Instructor']);
if (!canAccess('reports')) redirect('/pages/unauthorized.php');

$pdo = db();

$filterMonth = sanitize($_GET['month'] ?? date('Y-m'));

$internRoleId = $pdo->query("SELECT id FROM roles WHERE name='Intern'")->fetchColumn();

// Overall stats
$totalInterns    = $pdo->query("SELECT COUNT(*) FROM users WHERE role_id = $internRoleId AND is_active = 1")->fetchColumn();
$completedInterns = $pdo->query("SELECT COUNT(*) FROM users WHERE role_id = $internRoleId AND is_active = 1 AND completed_hours >= required_hours")->fetchColumn();
$totalHoursAll   = $pdo->query("SELECT COALESCE(SUM(completed_hours),0) FROM users WHERE role_id = $internRoleId AND is_active = 1")->fetchColumn();

$monthStart = $filterMonth . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));

$monthHours = $pdo->prepare("SELECT COALESCE(SUM(hours_rendered),0) FROM attendance WHERE date BETWEEN ? AND ? AND status='completed'");
$monthHours->execute([$monthStart, $monthEnd]);
$monthHours = $monthHours->fetchColumn();

$monthDays = $pdo->prepare("SELECT COUNT(DISTINCT date) FROM attendance WHERE date BETWEEN ? AND ? AND status='completed'");
$monthDays->execute([$monthStart, $monthEnd]);
$monthDays = $monthDays->fetchColumn();

// Per-intern summary
$internSummary = $pdo->prepare("
    SELECT u.id, u.first_name, u.last_name, u.email, u.company,
           u.completed_hours, u.required_hours,
           COUNT(a.id) as total_days,
           COALESCE(SUM(CASE WHEN a.date BETWEEN ? AND ? THEN a.hours_rendered ELSE 0 END), 0) as month_hours
    FROM users u
    LEFT JOIN attendance a ON a.user_id = u.id AND a.status = 'completed'
    WHERE u.role_id = ? AND u.is_active = 1
    GROUP BY u.id
    ORDER BY u.completed_hours DESC
");
$internSummary->execute([$monthStart, $monthEnd, $internRoleId]);
$interns = $internSummary->fetchAll();

// Daily attendance count for the selected month
$dailyData = $pdo->prepare("
    SELECT date, COUNT(DISTINCT user_id) as count, SUM(hours_rendered) as hours
    FROM attendance
    WHERE date BETWEEN ? AND ? AND status = 'completed'
    GROUP BY date
    ORDER BY date ASC
");
$dailyData->execute([$monthStart, $monthEnd]);
$dailyRows = $dailyData->fetchAll();

// Build chart data
$chartLabels = [];
$chartCounts = [];
$chartHours  = [];
foreach ($dailyRows as $row) {
    $chartLabels[] = date('M d', strtotime($row['date']));
    $chartCounts[] = (int) $row['count'];
    $chartHours[]  = (float) $row['hours'];
}

renderHead('Reports');
?>
<div class="layout">
    <?php renderSidebar(); ?>
    <div class="main-content">
        <div class="topbar">
            <div>
                <div class="page-title">Reports</div>
                <div class="page-subtitle">Internship hours and attendance summary</div>
            </div>
            <div class="topbar-actions">
                <form method="GET" style="display:flex;align-items:center;gap:8px">
                    <input type="month" name="month" value="<?= htmlspecialchars($filterMonth) ?>" style="padding:7px 10px;font-size:13px;border:1px solid var(--border);border-radius:7px;outline:none">
                    <button type="submit" class="btn btn-ghost btn-sm"><i data-feather="filter"></i> Filter</button>
                </form>
                <button class="btn btn-primary btn-sm" onclick="window.print()"><i data-feather="printer"></i> Print</button>
            </div>
        </div>

        <div class="content-wrap">

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue"><i data-feather="users"></i></div>
                    <div>
                        <div class="stat-value"><?= $totalInterns ?></div>
                        <div class="stat-label">Active Interns</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i data-feather="check-circle"></i></div>
                    <div>
                        <div class="stat-value"><?= $completedInterns ?></div>
                        <div class="stat-label">Completed OJT</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon amber"><i data-feather="clock"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($monthHours, 1) ?>h</div>
                        <div class="stat-label">Hours This Month</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue"><i data-feather="calendar"></i></div>
                    <div>
                        <div class="stat-value"><?= $monthDays ?></div>
                        <div class="stat-label">Active Days This Month</div>
                    </div>
                </div>
            </div>

            <?php if ($chartLabels): ?>
            <div class="card" style="margin-bottom:20px">
                <div class="card-header">
                    <span class="card-title">Daily Attendance — <?= date('F Y', strtotime($monthStart)) ?></span>
                </div>
                <div class="card-body">
                    <canvas id="attendanceChart" height="90"></canvas>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Intern Progress</span>
                    <span style="font-size:12px;color:var(--muted)"><?= date('F Y', strtotime($monthStart)) ?> month hours shown</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Intern</th>
                            <th>Company</th>
                            <th>Days Attended</th>
                            <th>This Month</th>
                            <th>Total Progress</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($interns as $intern):
                            $pct = min(100, round(($intern['completed_hours'] / max(1, $intern['required_hours'])) * 100));
                        ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div class="avatar-sm"><?= strtoupper(substr($intern['first_name'], 0, 1)) ?></div>
                                    <div>
                                        <div style="font-weight:600;font-size:14px"><?= htmlspecialchars($intern['first_name'] . ' ' . $intern['last_name']) ?></div>
                                        <div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($intern['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($intern['company'] ?: '—') ?></td>
                            <td style="font-size:13px"><?= $intern['total_days'] ?> days</td>
                            <td><span class="badge badge-blue"><?= number_format($intern['month_hours'], 2) ?>h</span></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div class="progress-bar-wrap" style="width:80px">
                                        <div class="progress-bar-fill" style="width:<?= $pct ?>%"></div>
                                    </div>
                                    <span style="font-size:12px;color:var(--muted);white-space:nowrap">
                                        <?= number_format($intern['completed_hours'], 1) ?> / <?= $intern['required_hours'] ?>h
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php if ($pct >= 100): ?>
                                    <span class="badge badge-green">Completed</span>
                                <?php elseif ($intern['total_days'] == 0): ?>
                                    <span class="badge badge-gray">No Records</span>
                                <?php else: ?>
                                    <span class="badge badge-amber">In Progress</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$interns): ?>
                        <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:32px">No interns found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<?php if ($chartLabels): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('attendanceChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [
            {
                label: 'Interns Present',
                data: <?= json_encode($chartCounts) ?>,
                backgroundColor: 'rgba(26,60,94,0.15)',
                borderColor: '#1a3c5e',
                borderWidth: 2,
                borderRadius: 4,
                yAxisID: 'y',
            },
            {
                label: 'Hours Rendered',
                data: <?= json_encode($chartHours) ?>,
                type: 'line',
                borderColor: '#e8a020',
                backgroundColor: 'rgba(232,160,32,0.08)',
                borderWidth: 2,
                pointBackgroundColor: '#e8a020',
                pointRadius: 3,
                tension: 0.3,
                fill: true,
                yAxisID: 'y1',
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top', labels: { font: { family: 'DM Sans', size: 12 }, boxWidth: 12 } } },
        scales: {
            y:  { position: 'left',  beginAtZero: true, ticks: { stepSize: 1, font: { family: 'DM Sans', size: 11 } }, grid: { color: '#eef2f8' } },
            y1: { position: 'right', beginAtZero: true, ticks: { font: { family: 'DM Sans', size: 11 } }, grid: { drawOnChartArea: false } },
            x:  { ticks: { font: { family: 'DM Sans', size: 11 } }, grid: { color: '#eef2f8' } }
        }
    }
});
</script>
<?php endif; ?>

<style>
@media print {
    .sidebar, .topbar-actions, .btn { display: none !important; }
    .main-content { margin-left: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ccc !important; }
}
</style>

<?php renderFoot(); ?>
