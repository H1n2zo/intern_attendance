<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireRole(['Admin']);
if (!canAccess('roles')) redirect('/pages/unauthorized.php');

$pdo = db();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['new_role'])) {
        $name = sanitize($_POST['role_name'] ?? '');
        $desc = sanitize($_POST['description'] ?? '');
        if ($name) {
            try {
                $pdo->prepare("INSERT INTO roles (name, description) VALUES (?,?)")->execute([$name, $desc]);
                $msg = 'Role created.';
            } catch (PDOException $e) {
                $err = 'Role name already exists.';
            }
        }
    }

    if (isset($_POST['save_permissions'])) {
        $roleId = intval($_POST['role_id']);
        $modules = $pdo->query("SELECT id FROM modules")->fetchAll();

        foreach ($modules as $m) {
            $mid = $m['id'];
            $view = isset($_POST["view_$mid"]) ? 1 : 0;
            $create = isset($_POST["create_$mid"]) ? 1 : 0;
            $edit = isset($_POST["edit_$mid"]) ? 1 : 0;
            $delete = isset($_POST["delete_$mid"]) ? 1 : 0;

            $stmt = $pdo->prepare("INSERT INTO role_modules (role_id, module_id, can_view, can_create, can_edit, can_delete) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE can_view=VALUES(can_view), can_create=VALUES(can_create), can_edit=VALUES(can_edit), can_delete=VALUES(can_delete)");
            $stmt->execute([$roleId, $mid, $view, $create, $edit, $delete]);
        }
        $msg = 'Permissions saved.';
    }
}

if (isset($_GET['delete_role'])) {
    $rid = intval($_GET['delete_role']);
    $protected = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?")->execute([$rid]) ? $pdo->query("SELECT COUNT(*) FROM users WHERE role_id = $rid")->fetchColumn() : 0;
    if ($protected > 0) {
        $err = 'Cannot delete a role that has users assigned.';
    } else {
        $pdo->prepare("DELETE FROM roles WHERE id = ? AND name NOT IN ('Admin','Intern','Instructor')")->execute([$rid]);
        $msg = 'Role deleted.';
    }
}

$roles = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll();
$modules = $pdo->query("SELECT * FROM modules ORDER BY sort_order")->fetchAll();

$activeRoleId = intval($_GET['role'] ?? $roles[0]['id'] ?? 1);
$activeRole = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
$activeRole->execute([$activeRoleId]);
$activeRole = $activeRole->fetch();

$perms = [];
$stmt = $pdo->prepare("SELECT * FROM role_modules WHERE role_id = ?");
$stmt->execute([$activeRoleId]);
foreach ($stmt->fetchAll() as $p) {
    $perms[$p['module_id']] = $p;
}

renderHead('Roles & Permissions');
?>
<div class="layout">
    <?php renderSidebar(); ?>
    <div class="main-content">
        <div class="topbar">
            <div>
                <div class="page-title">Roles & Permissions</div>
                <div class="page-subtitle">Manage what each role can access</div>
            </div>
            <div class="topbar-actions">
                <button class="btn btn-primary" onclick="document.getElementById('add-role-modal').classList.add('open')">
                    <i data-feather="plus"></i> New Role
                </button>
            </div>
        </div>

        <div class="content-wrap">
            <?php if ($msg): ?><div class="alert alert-success"><i data-feather="check-circle"></i><?= $msg ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-danger"><i data-feather="alert-circle"></i><?= $err ?></div><?php endif; ?>

            <div style="display:grid;grid-template-columns:220px 1fr;gap:20px;align-items:start">
                <div class="card" style="margin-bottom:0">
                    <div class="card-header"><span class="card-title">Roles</span></div>
                    <div style="padding:8px">
                        <?php foreach ($roles as $r): ?>
                        <a href="?role=<?= $r['id'] ?>" style="display:flex;align-items:center;justify-content:space-between;padding:9px 10px;border-radius:8px;font-size:14px;font-weight:<?= $r['id'] == $activeRoleId ? '600' : '400' ?>;background:<?= $r['id'] == $activeRoleId ? 'var(--navy)' : 'transparent' ?>;color:<?= $r['id'] == $activeRoleId ? '#fff' : 'var(--text)' ?>;margin-bottom:2px;transition:background .15s">
                            <span><?= htmlspecialchars($r['name']) ?></span>
                            <?php if (!in_array($r['name'], ['Admin','Intern','Instructor'])): ?>
                            <a href="?delete_role=<?= $r['id'] ?>" onclick="return confirm('Delete this role?')" style="color:var(--red);display:flex;opacity:.7"><i data-feather="trash-2" style="width:13px;height:13px"></i></a>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card" style="margin-bottom:0">
                    <div class="card-header">
                        <span class="card-title">Permissions — <?= htmlspecialchars($activeRole['name'] ?? '') ?></span>
                        <span style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($activeRole['description'] ?? '') ?></span>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="role_id" value="<?= $activeRoleId ?>">
                        <input type="hidden" name="save_permissions" value="1">
                        <div style="padding:16px 20px">
                            <div class="permission-grid">
                                <div class="perm-header">
                                    <span>Module</span>
                                    <span style="text-align:center">View</span>
                                    <span style="text-align:center">Create</span>
                                    <span style="text-align:center">Edit</span>
                                    <span style="text-align:center">Delete</span>
                                </div>
                                <?php foreach ($modules as $m): ?>
                                <?php $p = $perms[$m['id']] ?? []; ?>
                                <div class="perm-row">
                                    <span style="font-size:14px;font-weight:500">
                                        <span style="margin-right:6px;color:var(--muted);display:inline-flex"><i data-feather="<?= $m['icon'] ?>" style="width:14px;height:14px"></i></span>
                                        <?= htmlspecialchars($m['name']) ?>
                                    </span>
                                    <label><input type="checkbox" name="view_<?= $m['id'] ?>" <?= !empty($p['can_view']) ? 'checked' : '' ?>></label>
                                    <label><input type="checkbox" name="create_<?= $m['id'] ?>" <?= !empty($p['can_create']) ? 'checked' : '' ?>></label>
                                    <label><input type="checkbox" name="edit_<?= $m['id'] ?>" <?= !empty($p['can_edit']) ? 'checked' : '' ?>></label>
                                    <label><input type="checkbox" name="delete_<?= $m['id'] ?>" <?= !empty($p['can_delete']) ? 'checked' : '' ?>></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div style="padding:12px 20px 20px;display:flex;justify-content:flex-end">
                            <button type="submit" class="btn btn-primary"><i data-feather="save"></i> Save Permissions</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="add-role-modal">
    <div class="modal">
        <div class="modal-head">
            <span class="modal-title">Create New Role</span>
            <button class="modal-close" onclick="document.getElementById('add-role-modal').classList.remove('open')"><i data-feather="x"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="new_role" value="1">
            <div class="modal-body">
                <div class="form-group"><label>Role Name</label><input type="text" name="role_name" required placeholder="e.g. Supervisor"></div>
                <div class="form-group"><label>Description</label><input type="text" name="description" placeholder="Brief description of this role"></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('add-role-modal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Role</button>
            </div>
        </form>
    </div>
</div>

<?php renderFoot(); ?>
