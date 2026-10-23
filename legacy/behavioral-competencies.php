<?php
require_once __DIR__ . '/includes/config.php';

// Role names are e.g. 'Administrator' (there is no 'admin' role).
if (!isset($_SESSION['user_id']) || !ims_allowed('competencies.manage', ['Administrator', 'HR'], 'Manage behavioural competencies')) {
    header("Location: dashboard");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'add_group') {
        $name = trim($_POST['group_name'] ?? '');
        $desc = trim($_POST['group_description'] ?? '');
        $ord  = (int)($_POST['sort_order'] ?? 0);
        if ($name) {
            $stmt = $conn->prepare("INSERT INTO bc_groups (group_name, description, sort_order, is_active, created_at) VALUES (?,?,?,1,NOW())");
            $stmt->bind_param('ssi', $name, $desc, $ord);
            $stmt->execute();
            $stmt->close();
            $_SESSION['bc_success'] = 'Competency group added.';
        } else {
            $_SESSION['bc_error'] = 'Group name is required.';
        }
        header("Location: behavioral-competencies"); exit();
    }

    if ($act === 'edit_group') {
        $gid    = (int)$_POST['group_id'];
        $name   = trim($_POST['group_name'] ?? '');
        $desc   = trim($_POST['group_description'] ?? '');
        $ord    = (int)($_POST['sort_order'] ?? 0);
        $active = (int)($_POST['is_active'] ?? 1);
        if ($name && $gid) {
            $stmt = $conn->prepare("UPDATE bc_groups SET group_name=?, description=?, sort_order=?, is_active=? WHERE group_id=?");
            $stmt->bind_param('ssiii', $name, $desc, $ord, $active, $gid);
            $stmt->execute();
            $stmt->close();
            $_SESSION['bc_success'] = 'Competency group updated.';
        }
        header("Location: behavioral-competencies"); exit();
    }

    if ($act === 'delete_group') {
        $gid = (int)$_POST['group_id'];
        $stmt = $conn->prepare("DELETE FROM bc_groups WHERE group_id=?");
        $stmt->bind_param('i', $gid);
        $stmt->execute();
        $stmt->close();
        $_SESSION['bc_success'] = 'Competency group deleted.';
        header("Location: behavioral-competencies"); exit();
    }

    if ($act === 'add_indicator') {
        $gid  = (int)$_POST['group_id'];
        $text = trim($_POST['indicator_text'] ?? '');
        $ord  = (int)($_POST['sort_order'] ?? 0);
        if ($gid && $text) {
            $stmt = $conn->prepare("INSERT INTO bc_indicators (group_id, indicator_text, sort_order, is_active, created_at) VALUES (?,?,?,1,NOW())");
            $stmt->bind_param('isi', $gid, $text, $ord);
            $stmt->execute();
            $stmt->close();
            $_SESSION['bc_success'] = 'Indicator added.';
        } else {
            $_SESSION['bc_error'] = 'Indicator text and group are required.';
        }
        header("Location: behavioral-competencies"); exit();
    }

    if ($act === 'edit_indicator') {
        $iid    = (int)$_POST['indicator_id'];
        $text   = trim($_POST['indicator_text'] ?? '');
        $ord    = (int)($_POST['sort_order'] ?? 0);
        $active = (int)($_POST['is_active'] ?? 1);
        if ($iid && $text) {
            $stmt = $conn->prepare("UPDATE bc_indicators SET indicator_text=?, sort_order=?, is_active=? WHERE indicator_id=?");
            $stmt->bind_param('siii', $text, $ord, $active, $iid);
            $stmt->execute();
            $stmt->close();
            $_SESSION['bc_success'] = 'Indicator updated.';
        }
        header("Location: behavioral-competencies"); exit();
    }

    if ($act === 'delete_indicator') {
        $iid = (int)$_POST['indicator_id'];
        $stmt = $conn->prepare("DELETE FROM bc_indicators WHERE indicator_id=?");
        $stmt->bind_param('i', $iid);
        $stmt->execute();
        $stmt->close();
        $_SESSION['bc_success'] = 'Indicator deleted.';
        header("Location: behavioral-competencies"); exit();
    }

    // AJAX reorder — pure JSON response, no header.php needed
    if ($act === 'reorder') {
        header('Content-Type: application/json');
        $type = $_POST['type'] ?? '';
        $ids  = $_POST['ids']  ?? [];
        if (in_array($type, ['group','indicator']) && is_array($ids)) {
            $table = $type === 'group' ? 'bc_groups'     : 'bc_indicators';
            $pk    = $type === 'group' ? 'group_id'      : 'indicator_id';
            $stmt  = $conn->prepare("UPDATE {$table} SET sort_order=? WHERE {$pk}=?");
            foreach ($ids as $order => $id) {
                $o = $order + 1; $i = (int)$id;
                $stmt->bind_param('ii', $o, $i);
                $stmt->execute();
            }
            $stmt->close();
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit();
    }
}

// ── Flash messages (read after possible redirect) ──
$success = $_SESSION['bc_success'] ?? '';
$error   = $_SESSION['bc_error']   ?? '';
unset($_SESSION['bc_success'], $_SESSION['bc_error']);

// ── Fetch groups + indicators (safe to query here; no redirect after this) ──
$groups = [];
$gr = $conn->query("SELECT * FROM bc_groups ORDER BY sort_order, group_id");
while ($g = $gr->fetch_assoc()) {
    $g['indicators'] = [];
    $ir = $conn->prepare("SELECT * FROM bc_indicators WHERE group_id=? ORDER BY sort_order, indicator_id");
    $ir->bind_param('i', $g['group_id']);
    $ir->execute();
    $res = $ir->get_result();
    while ($ind = $res->fetch_assoc()) $g['indicators'][] = $ind;
    $ir->close();
    $groups[] = $g;
}

$page_title = 'Manage Behavioral Competencies';
include 'includes/header.php';
?>

<style>
/* Behavioral Competencies: page-only layout (components come from reports.css) */
.bc-page .field-row{display:grid;gap:14px;margin-bottom:16px;}
.bc-page .col-2{grid-template-columns:1fr 1fr;}
.bc-page .field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-300);margin-bottom:5px;}
.bc-page .field input,.bc-page .field select,.bc-page .field textarea,
.bc-page .add-indicator-form input{width:100%;padding:8px 11px;border:1px solid var(--ink-100);border-radius:var(--radius-sm);font-size:13px;color:var(--ink-700);background:var(--surface-card);outline:none;transition:border-color .15s;}
.bc-page .field input:focus,.bc-page .field select:focus,.bc-page .field textarea:focus,
.bc-page .add-indicator-form input:focus{border-color:var(--brand-500);box-shadow:var(--focus-ring);}
.bc-page .req{color:var(--red-fg);}
.group-card{margin-bottom:16px;}
.group-card-head{padding:12px 18px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--ink-50);background:var(--surface-hover);}
.group-card-head .g-name{flex:1;font-weight:700;font-size:14px;color:var(--ink-700);}
.group-card-head .g-meta{font-size:12px;color:var(--ink-300);}
.indicator-row{display:flex;align-items:center;gap:10px;padding:10px 18px;border-bottom:1px solid var(--ink-50);transition:background .12s;}
.indicator-row:last-child{border-bottom:none;}
.indicator-row:hover{background:var(--surface-hover);}
.drag-handle{color:var(--ink-100);font-size:14px;flex-shrink:0;cursor:grab;}
.ind-seq{width:24px;text-align:center;font-family:var(--font-mono);font-size:11px;color:var(--ink-300);flex-shrink:0;}
.ind-text{flex:1;font-size:13px;line-height:1.5;}
.ind-text.inactive{color:var(--ink-200);text-decoration:line-through;}
.ind-actions{display:flex;gap:6px;flex-shrink:0;}
.ind-empty{padding:16px 20px;color:var(--ink-300);font-size:13px;font-style:italic;}
.add-indicator-form{padding:12px 18px;background:var(--surface-hover);border-top:1px dashed var(--ink-100);display:none;}
.add-indicator-form.open{display:block;}
.add-indicator-form .irow{display:flex;gap:10px;align-items:flex-end;}
.group-card-foot{padding:10px 18px;border-top:1px solid var(--ink-50);background:var(--surface-hover);}
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(13,17,23,.55);z-index:9000;align-items:center;justify-content:center;padding:24px;}
.modal-backdrop.open{display:flex;}
.modal-backdrop .modal-card{width:100%;max-width:520px;margin:auto;}
.modal-backdrop .modal-foot{padding:14px 24px;border-top:1px solid var(--ink-50);display:flex;gap:10px;justify-content:flex-end;}
@media(max-width:768px){.bc-page .col-2{grid-template-columns:1fr;}}
</style>

<div class="page-stack bc-page">
<div class="page-hero">
    <div>
        <h1><i class="fas fa-brain" aria-hidden="true"></i> Behavioral Competencies</h1>
        <p>Manage competency groups and their behavior indicators for staff appraisals.</p>
    </div>
    <div class="hero-actions">
        <button type="button" class="btn btn-primary" onclick="openAddGroupModal()">
            <i class="fas fa-plus" aria-hidden="true"></i> Add Competency Group
        </button>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if (empty($groups)): ?>
<div class="panel">
    <div class="empty-state">
        <i class="fas fa-layer-group"></i>
        <p>No competency groups yet. Click <strong>Add Competency Group</strong> to get started.</p>
    </div>
</div>
<?php else: ?>
<div id="groupsContainer">
<?php foreach ($groups as $gi => $g): ?>
<div class="panel group-card" data-gid="<?php echo $g['group_id']; ?>">
    <div class="group-card-head">
        <span class="g-meta"><?php echo $gi+1; ?>.</span>
        <span class="g-name"><?php echo htmlspecialchars($g['group_name']); ?></span>
        <?php if ($g['description']): ?>
            <span class="g-meta" title="<?php echo htmlspecialchars($g['description']); ?>"><i class="fas fa-info-circle"></i></span>
        <?php endif; ?>
        <span class="badge <?php echo $g['is_active'] ? 'badge-success' : 'badge-secondary'; ?>">
            <?php echo $g['is_active'] ? 'Active' : 'Inactive'; ?>
        </span>
        <span class="g-meta"><?php echo count($g['indicators']); ?> indicators</span>
        <button class="btn btn-secondary btn-sm" onclick='openEditGroupModal(<?php echo json_encode($g); ?>)'>
            <i class="fas fa-edit"></i> Edit
        </button>
        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this group and ALL its indicators?')">
            <input type="hidden" name="action" value="delete_group">
            <input type="hidden" name="group_id" value="<?php echo $g['group_id']; ?>">
            <button class="btn btn-danger btn-sm" type="submit"><i class="fas fa-trash"></i></button>
        </form>
    </div>

    <div class="indicator-list" id="indList-<?php echo $g['group_id']; ?>">
        <?php if (empty($g['indicators'])): ?>
        <div class="ind-empty">No indicators yet — add one below.</div>
        <?php else: ?>
        <?php foreach ($g['indicators'] as $ii => $ind): ?>
        <div class="indicator-row" data-iid="<?php echo $ind['indicator_id']; ?>">
            <i class="fas fa-grip-vertical drag-handle"></i>
            <span class="ind-seq"><?php echo $ii+1; ?></span>
            <span class="ind-text <?php echo $ind['is_active'] ? '' : 'inactive'; ?>">
                <?php echo htmlspecialchars($ind['indicator_text']); ?>
            </span>
            <span class="badge <?php echo $ind['is_active'] ? 'badge-success' : 'badge-secondary'; ?>">
                <?php echo $ind['is_active'] ? 'Active' : 'Off'; ?>
            </span>
            <div class="ind-actions">
                <button class="btn btn-secondary btn-sm" onclick='openEditIndicatorModal(<?php echo json_encode($ind); ?>)'>
                    <i class="fas fa-edit"></i>
                </button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this indicator?')">
                    <input type="hidden" name="action" value="delete_indicator">
                    <input type="hidden" name="indicator_id" value="<?php echo $ind['indicator_id']; ?>">
                    <button class="btn btn-danger btn-sm" type="submit"><i class="fas fa-times"></i></button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="add-indicator-form" id="addIndForm-<?php echo $g['group_id']; ?>">
        <form method="POST">
            <input type="hidden" name="action" value="add_indicator">
            <input type="hidden" name="group_id" value="<?php echo $g['group_id']; ?>">
            <div class="irow">
                <div style="flex:1">
                    <input type="text" name="indicator_text" placeholder="e.g., Demonstrates respect in all interactions"
                           required>
                </div>
                <div style="width:80px">
                    <input type="number" name="sort_order" placeholder="Order" min="1">
                </div>
                <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> Add</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAddForm(<?php echo $g['group_id']; ?>)">Cancel</button>
            </div>
        </form>
    </div>

    <div class="group-card-foot">
        <button class="btn btn-secondary btn-sm" onclick="toggleAddForm(<?php echo $g['group_id']; ?>)">
            <i class="fas fa-plus"></i> Add Indicator
        </button>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<!-- ADD GROUP MODAL -->
<div class="modal-backdrop bc-page" id="addGroupModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fas fa-plus-circle" aria-hidden="true"></i> Add Competency Group</h3>
            <button class="btn btn-secondary btn-sm" onclick="closeModal('addGroupModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="add_group">
        <div class="modal-body">
            <div class="field-row"><div class="field">
                <label>Group Name <span class="req">*</span></label>
                <input type="text" name="group_name" placeholder="e.g., Acting as a Team Player" required>
            </div></div>
            <div class="field-row"><div class="field">
                <label>Description (optional)</label>
                <textarea name="group_description" rows="2" placeholder="Brief description…"></textarea>
            </div></div>
            <div class="field-row"><div class="field" style="max-width:120px">
                <label>Sort Order</label>
                <input type="number" name="sort_order" min="1" placeholder="1">
            </div></div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" onclick="closeModal('addGroupModal')">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Group</button>
        </div>
        </form>
    </div>
</div>

<!-- EDIT GROUP MODAL -->
<div class="modal-backdrop bc-page" id="editGroupModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fas fa-edit" aria-hidden="true"></i> Edit Competency Group</h3>
            <button class="btn btn-secondary btn-sm" onclick="closeModal('editGroupModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="edit_group">
        <input type="hidden" name="group_id" id="editGroupId">
        <div class="modal-body">
            <div class="field-row"><div class="field">
                <label>Group Name <span class="req">*</span></label>
                <input type="text" name="group_name" id="editGroupName" required>
            </div></div>
            <div class="field-row"><div class="field">
                <label>Description</label>
                <textarea name="group_description" id="editGroupDesc" rows="2"></textarea>
            </div></div>
            <div class="field-row col-2">
                <div class="field"><label>Sort Order</label><input type="number" name="sort_order" id="editGroupOrder" min="1"></div>
                <div class="field"><label>Status</label>
                    <select name="is_active" id="editGroupActive">
                        <option value="1">Active</option><option value="0">Inactive</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" onclick="closeModal('editGroupModal')">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Group</button>
        </div>
        </form>
    </div>
</div>

<!-- EDIT INDICATOR MODAL -->
<div class="modal-backdrop bc-page" id="editIndicatorModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fas fa-edit" aria-hidden="true"></i> Edit Indicator</h3>
            <button class="btn btn-secondary btn-sm" onclick="closeModal('editIndicatorModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="edit_indicator">
        <input type="hidden" name="indicator_id" id="editIndId">
        <div class="modal-body">
            <div class="field-row"><div class="field">
                <label>Indicator Text <span class="req">*</span></label>
                <textarea name="indicator_text" id="editIndText" rows="3" required></textarea>
            </div></div>
            <div class="field-row col-2">
                <div class="field"><label>Sort Order</label><input type="number" name="sort_order" id="editIndOrder" min="1"></div>
                <div class="field"><label>Status</label>
                    <select name="is_active" id="editIndActive">
                        <option value="1">Active</option><option value="0">Inactive</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" onclick="closeModal('editIndicatorModal')">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Indicator</button>
        </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
function openAddGroupModal(){document.getElementById('addGroupModal').classList.add('open');}
function openEditGroupModal(g){
    document.getElementById('editGroupId').value=g.group_id;
    document.getElementById('editGroupName').value=g.group_name;
    document.getElementById('editGroupDesc').value=g.description||'';
    document.getElementById('editGroupOrder').value=g.sort_order||'';
    document.getElementById('editGroupActive').value=g.is_active;
    document.getElementById('editGroupModal').classList.add('open');
}
function openEditIndicatorModal(ind){
    document.getElementById('editIndId').value=ind.indicator_id;
    document.getElementById('editIndText').value=ind.indicator_text;
    document.getElementById('editIndOrder').value=ind.sort_order||'';
    document.getElementById('editIndActive').value=ind.is_active;
    document.getElementById('editIndicatorModal').classList.add('open');
}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-backdrop').forEach(el=>{
    el.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
});
function toggleAddForm(gid){
    const f=document.getElementById('addIndForm-'+gid);
    f.classList.toggle('open');
    if(f.classList.contains('open'))f.querySelector('input[name="indicator_text"]').focus();
}
</script>