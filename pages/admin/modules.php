<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';

requireLogin();
requireRole(['Admin']);
if (!canAccess('modules')) redirect('/pages/unauthorized.php');

$pdo = db();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_module'])) {
        $name = sanitize($_POST['name'] ?? '');
        $slug = sanitize($_POST['slug'] ?? '');
        $icon = sanitize($_POST['icon'] ?? 'grid');
        $order = intval($_POST['sort_order'] ?? 0);
        if ($name && $slug) {
            try {
                $pdo->prepare("INSERT INTO modules (name, slug, icon, sort_order) VALUES (?,?,?,?)")->execute([$name, $slug, $icon, $order]);
                $msg = 'Module added.';
            } catch (PDOException $e) {
                $err = 'Module slug already exists.';
            }
        }
    }

    if (isset($_POST['toggle_module'])) {
        $id = intval($_POST['module_id']);
        $pdo->prepare("UPDATE modules SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $msg = 'Module updated.';
    }

    if (isset($_POST['edit_module'])) {
        $id = intval($_POST['module_id']);
        $name = sanitize($_POST['name'] ?? '');
        $icon = sanitize($_POST['icon'] ?? 'grid');
        $order = intval($_POST['sort_order'] ?? 0);
        $pdo->prepare("UPDATE modules SET name=?, icon=?, sort_order=? WHERE id=?")->execute([$name, $icon, $order, $id]);
        $msg = 'Module updated.';
    }
}

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $pdo->prepare("DELETE FROM modules WHERE id = ?")->execute([$id]);
    $msg = 'Module deleted.';
}

$modules = $pdo->query("SELECT * FROM modules ORDER BY sort_order, id")->fetchAll();

$featherIcons = ['grid','users','calendar','bar-chart-2','shield','lock','layers','settings','home','file-text','inbox','bell','map','star','activity','cpu'];

renderHead('Modules');
?>
<div class="layout">
    <?php renderSidebar(); ?>
    <div class="main-content">
        <div class="topbar">
            <div>
                <div class="page-title">Modules</div>
                <div class="page-subtitle">Manage sidebar navigation modules</div>
            </div>
            <div class="topbar-actions">
                <button class="btn btn-primary" onclick="document.getElementById('add-modal').classList.add('open')">
                    <i data-feather="plus"></i> Add Module
                </button>
            </div>
        </div>

        <div class="content-wrap">
            <?php if ($msg): ?><div class="alert alert-success"><i data-feather="check-circle"></i><?= $msg ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-danger"><i data-feather="alert-circle"></i><?= $err ?></div><?php endif; ?>

            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Module</th>
                            <th>Slug</th>
                            <th>Icon</th>
                            <th>Order</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($modules as $m): ?>
                        <tr>
                            <td style="color:var(--muted);font-size:13px"><?= $m['id'] ?></td>
                            <td style="font-weight:600;font-size:14px">
                                <span style="display:inline-flex;align-items:center;gap:7px">
                                    <i data-feather="<?= $m['icon'] ?>" style="width:15px;height:15px;color:var(--muted)"></i>
                                    <?= htmlspecialchars($m['name']) ?>
                                </span>
                            </td>
                            <td><code style="background:var(--surface);padding:2px 7px;border-radius:4px;font-size:12px"><?= htmlspecialchars($m['slug']) ?></code></td>
                            <td style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($m['icon']) ?></td>
                            <td style="font-size:13px"><?= $m['sort_order'] ?></td>
                            <td>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="toggle_module" value="1">
                                    <input type="hidden" name="module_id" value="<?= $m['id'] ?>">
                                    <label class="toggle-switch">
                                        <input type="checkbox" <?= $m['is_active'] ? 'checked' : '' ?> onchange="this.form.submit()">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </form>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px">
                                    <button class="btn btn-ghost btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($m)) ?>)"><i data-feather="edit-2"></i></button>
                                    <a href="?delete=<?= $m['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this module?')"><i data-feather="trash-2"></i></a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="add-modal">
    <div class="modal">
        <div class="modal-head">
            <span class="modal-title">Add Module</span>
            <button class="modal-close" onclick="document.getElementById('add-modal').classList.remove('open')"><i data-feather="x"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="add_module" value="1">
            <div class="modal-body">
                <div class="form-group"><label>Module Name</label><input type="text" name="name" required placeholder="e.g. Reports"></div>
                <div class="form-group"><label>Slug (URL key)</label><input type="text" name="slug" required placeholder="e.g. reports" pattern="[a-z0-9\-]+"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group">
                        <label>Icon (Feather)</label>
                        <select name="icon">
                            <?php foreach ($featherIcons as $ic): ?>
                            <option value="<?= $ic ?>"><?= $ic ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Sort Order</label><input type="number" name="sort_order" value="0" min="0"></div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('add-modal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Module</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="edit-modal">
    <div class="modal">
        <div class="modal-head">
            <span class="modal-title">Edit Module</span>
            <button class="modal-close" onclick="document.getElementById('edit-modal').classList.remove('open')"><i data-feather="x"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="edit_module" value="1">
            <input type="hidden" name="module_id" id="edit-id">
            <div class="modal-body">
                <div class="form-group"><label>Module Name</label><input type="text" name="name" id="edit-name" required></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group">
                        <label>Icon</label>
                        <select name="icon" id="edit-icon">
                            <?php foreach ($featherIcons as $ic): ?>
                            <option value="<?= $ic ?>"><?= $ic ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Sort Order</label><input type="number" name="sort_order" id="edit-order" min="0"></div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('edit-modal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(m) {
    document.getElementById('edit-id').value = m.id;
    document.getElementById('edit-name').value = m.name;
    document.getElementById('edit-icon').value = m.icon;
    document.getElementById('edit-order').value = m.sort_order;
    document.getElementById('edit-modal').classList.add('open');
    feather.replace();
}
</script>

<?php renderFoot(); ?>
