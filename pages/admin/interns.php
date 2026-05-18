<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireRole(['Admin', 'Instructor']);
if (!canAccess('interns')) redirect('/pages/unauthorized.php');

$pdo = db();
$canCreate = canAccess('interns', 'can_create');
$canEdit = canAccess('interns', 'can_edit');
$canDelete = canAccess('interns', 'can_delete');

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canCreate) {
    $fname = sanitize($_POST['first_name'] ?? '');
    $lname = sanitize($_POST['last_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $sid = sanitize($_POST['student_id'] ?? '');
    $company = sanitize($_POST['company'] ?? '');
    $req = intval($_POST['required_hours'] ?? 70);
    $pass = $_POST['password'] ?? '';

    if (!str_ends_with($email, ALLOWED_DOMAIN)) {
        $err = 'Email must be an @evsu.edu.ph address.';
    } elseif (strlen($pass) < 8) {
        $err = 'Password must be at least 8 characters.';
    } else {
        $internRole = $pdo->query("SELECT id FROM roles WHERE name='Intern'")->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO users (role_id, student_id, first_name, last_name, email, password_hash, company, required_hours, is_verified) VALUES (?,?,?,?,?,?,?,?,1)");
        try {
            $stmt->execute([$internRole, encryptField($sid), $fname, $lname, $email, hashPassword($pass), $company, $req]);
            $msg = 'Intern account created successfully.';
        } catch (PDOException $e) {
            $err = 'Email already exists.';
        }
    }
}

if (isset($_GET['delete']) && $canDelete) {
    $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([intval($_GET['delete'])]);
    $msg = 'Intern deactivated.';
}

if (isset($_GET['restore']) && $canEdit) {
    $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([intval($_GET['restore'])]);
    $msg = 'Intern restored.';
}

$search = sanitize($_GET['q'] ?? '');
$showInactive = isset($_GET['inactive']);

$query = "SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'Intern'";
$params = [];

if ($search) {
    $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.company LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}

if (!$showInactive) $query .= " AND u.is_active = 1";
$query .= " ORDER BY u.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$interns = $stmt->fetchAll();

renderHead('Interns');
?>
<div class="layout">
    <?php renderSidebar(); ?>
    <div class="main-content">
        <div class="topbar">
            <div>
                <div class="page-title">Interns</div>
                <div class="page-subtitle"><?= count($interns) ?> record(s) found</div>
            </div>
            <div class="topbar-actions">
                <form method="GET" style="display:flex;gap:8px;align-items:center">
                    <div class="search-bar">
                        <i data-feather="search"></i>
                        <input type="text" name="q" placeholder="Search interns..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:500;cursor:pointer;margin-bottom:0">
                        <input type="checkbox" name="inactive" <?= $showInactive ? 'checked' : '' ?> onchange="this.form.submit()"> Show Inactive
                    </label>
                </form>
                <?php if ($canCreate): ?>
                <button class="btn btn-primary" onclick="document.getElementById('add-modal').classList.add('open')">
                    <i data-feather="user-plus"></i> Add Intern
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-wrap">
            <?php if ($msg): ?><div class="alert alert-success"><i data-feather="check-circle"></i><?= $msg ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-danger"><i data-feather="alert-circle"></i><?= $err ?></div><?php endif; ?>

            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Intern</th>
                            <th>Company</th>
                            <th>Progress</th>
                            <th>Hours</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($interns as $intern): ?>
                        <?php $pct = min(100, round(($intern['completed_hours'] / max(1, $intern['required_hours'])) * 100)); ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px">
                                    <div class="avatar-sm"><?= strtoupper(substr($intern['first_name'], 0, 1)) ?></div>
                                    <div>
                                        <div style="font-weight:600;font-size:14px"><?= htmlspecialchars($intern['first_name'] . ' ' . $intern['last_name']) ?></div>
                                        <div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($intern['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($intern['company'] ?: '—') ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div class="progress-bar-wrap">
                                        <div class="progress-bar-fill" style="width:<?= $pct ?>%"></div>
                                    </div>
                                    <span style="font-size:12px;color:var(--muted)"><?= $pct ?>%</span>
                                </div>
                            </td>
                            <td><span class="badge badge-blue"><?= $intern['completed_hours'] ?>/<?= $intern['required_hours'] ?>h</span></td>
                            <td>
                                <?php if (!$intern['is_active']): ?>
                                    <span class="badge badge-red">Inactive</span>
                                <?php elseif ($pct >= 100): ?>
                                    <span class="badge badge-green">Completed</span>
                                <?php else: ?>
                                    <span class="badge badge-amber">In Progress</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px">
                                    <a href="<?= APP_URL ?>/pages/admin/attendance.php?user=<?= $intern['id'] ?>" class="btn btn-ghost btn-sm"><i data-feather="calendar"></i></a>
                                    <?php if ($canEdit): ?>
                                    <a href="<?= APP_URL ?>/pages/admin/intern-edit.php?id=<?= $intern['id'] ?>" class="btn btn-ghost btn-sm"><i data-feather="edit-2"></i></a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?php if ($intern['is_active']): ?>
                                        <a href="?delete=<?= $intern['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Deactivate this intern?')"><i data-feather="user-x"></i></a>
                                        <?php else: ?>
                                        <a href="?restore=<?= $intern['id'] ?>" class="btn btn-ghost btn-sm"><i data-feather="user-check"></i></a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
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

<?php if ($canCreate): ?>
<div class="modal-overlay" id="add-modal">
    <div class="modal">
        <div class="modal-head">
            <span class="modal-title">Add New Intern</span>
            <button class="modal-close" onclick="document.getElementById('add-modal').classList.remove('open')"><i data-feather="x"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group"><label>First Name</label><input type="text" name="first_name" required></div>
                    <div class="form-group"><label>Last Name</label><input type="text" name="last_name" required></div>
                </div>
                <div class="form-group"><label>EVSU Email</label><input type="email" name="email" placeholder="student@evsu.edu.ph" required></div>
                <div class="form-group"><label>Student ID</label><input type="text" name="student_id"></div>
                <div class="form-group"><label>Company / OJT Establishment</label><input type="text" name="company"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group"><label>Required Hours</label><input type="number" name="required_hours" value="70" min="1"></div>
                    <div class="form-group"><label>Temporary Password</label><input type="password" name="password" required></div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('add-modal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Intern</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php renderFoot(); ?>
