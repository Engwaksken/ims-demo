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

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

<style>
:root {
    --ink:#0d1117;--surface:#f0f2f5;--panel:#fff;--accent:#5b5ef4;--accent-lt:#ededfd;
    --success:#16a34a;--success-lt:#dcfce7;--danger:#dc2626;--danger-lt:#fee2e2;
    --warning:#d97706;--warning-lt:#fef3c7;--border:#e2e8f0;--muted:#94a3b8;
    --sans:'Plus Jakarta Sans',sans-serif;--mono:'JetBrains Mono',monospace;
    --radius:10px;--shadow:0 1px 3px rgba(0,0,0,.06),0 4px 12px rgba(0,0,0,.05);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--sans);background:var(--surface);color:var(--ink);font-size:14px;}
.hero{background:linear-gradient(135deg,#1e1b4b 0%,#312e81 100%);color:#fff;padding:26px 32px;border-radius:var(--radius);display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;gap:16px;}
.hero h2{font-size:20px;font-weight:700;letter-spacing:-.3px;}
.hero p{font-size:12px;color:#a5b4fc;margin-top:3px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-family:var(--sans);font-size:13px;font-weight:600;cursor:pointer;border:2px solid transparent;transition:all .15s;text-decoration:none;white-space:nowrap;}
.btn-primary{background:var(--accent);color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-success{background:var(--success);color:#fff;}.btn-success:hover{background:#15803d;}
.btn-outline{background:#fff;color:var(--ink);border-color:var(--border);}.btn-outline:hover{border-color:#94a3b8;}
.btn-danger{background:#fff;color:var(--danger);border-color:var(--border);}.btn-danger:hover{background:var(--danger-lt);border-color:var(--danger);}
.btn-sm{padding:5px 12px;font-size:12px;}.btn-xs{padding:3px 9px;font-size:11px;}
.alert{padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:10px;}
.alert-success{background:var(--success-lt);color:#166534;border-left:4px solid var(--success);}
.alert-danger{background:var(--danger-lt);color:#991b1b;border-left:4px solid var(--danger);}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:20px;overflow:hidden;}
.panel-head{padding:14px 20px;border-bottom:1px solid var(--border);background:#fafbfc;display:flex;align-items:center;justify-content:space-between;gap:10px;}
.panel-head h4{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.panel-head h4 i{color:var(--accent);}
.panel-body{padding:20px;}
.field-row{display:grid;gap:14px;margin-bottom:16px;}
.col-2{grid-template-columns:1fr 1fr;}.col-3{grid-template-columns:1fr 1fr 100px;}
.field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-bottom:5px;}
.field input,.field select,.field textarea{width:100%;padding:8px 11px;border:1.5px solid var(--border);border-radius:7px;font-family:var(--sans);font-size:13px;color:var(--ink);background:#fff;outline:none;transition:border-color .15s;}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(91,94,244,.1);}
.group-card{border:1.5px solid var(--border);border-radius:var(--radius);margin-bottom:16px;overflow:hidden;transition:box-shadow .15s;}
.group-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
.group-card-head{padding:12px 16px;background:#1e1b4b;color:#fff;display:flex;align-items:center;gap:10px;}
.group-card-head .g-name{flex:1;font-weight:700;font-size:14px;}
.group-card-head .g-meta{font-size:11px;color:#a5b4fc;}
.group-card-head .badge{padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;background:rgba(255,255,255,.15);color:#fff;}
.group-card-head .badge.inactive{background:rgba(239,68,68,.3);}
.indicator-list{padding:0;}
.indicator-row{display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid #f1f5f9;transition:background .12s;}
.indicator-row:last-child{border-bottom:none;}
.indicator-row:hover{background:#f8fafc;}
.drag-handle{color:#cbd5e1;font-size:14px;flex-shrink:0;cursor:grab;}
.ind-seq{width:24px;text-align:center;font-family:var(--mono);font-size:11px;color:var(--muted);flex-shrink:0;}
.ind-text{flex:1;font-size:13px;line-height:1.5;}
.ind-text.inactive{color:var(--muted);text-decoration:line-through;}
.ind-actions{display:flex;gap:6px;flex-shrink:0;}
.add-indicator-form{padding:12px 16px;background:#f8fafc;border-top:1.5px dashed var(--border);display:none;}
.add-indicator-form.open{display:block;}
.add-indicator-form .irow{display:flex;gap:10px;align-items:flex-end;}
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;}
.modal-backdrop.open{display:flex;}
.modal{background:#fff;border-radius:var(--radius);width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.25);animation:modalIn .18s ease;}
@keyframes modalIn{from{transform:scale(.95);opacity:0}to{transform:scale(1);opacity:1}}
.modal-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.modal-head h3{font-size:15px;font-weight:700;}
.modal-body{padding:20px;}
.modal-foot{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;}
.empty-state{text-align:center;padding:48px 24px;color:var(--muted);}
.empty-state i{font-size:36px;margin-bottom:12px;display:block;}
.pill{padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;}
.pill-active{background:var(--success-lt);color:#166534;}
.pill-inactive{background:#f1f5f9;color:var(--muted);}
@media(max-width:768px){.col-2,.col-3{grid-template-columns:1fr;}.hero{flex-direction:column;}}
</style>

<div class="hero">
    <div>
        <h2><i class="fas fa-brain"></i> &nbsp;Behavioral Competencies</h2>
        <p>Manage competency groups and their behavior indicators for staff appraisals.</p>
    </div>
    <button class="btn btn-primary" onclick="openAddGroupModal()">
        <i class="fas fa-plus"></i> Add Competency Group
    </button>
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
<div class="group-card" data-gid="<?php echo $g['group_id']; ?>">
    <div class="group-card-head">
        <span class="g-meta"><?php echo $gi+1; ?>.</span>
        <span class="g-name"><?php echo htmlspecialchars($g['group_name']); ?></span>
        <?php if ($g['description']): ?>
            <span class="g-meta" title="<?php echo htmlspecialchars($g['description']); ?>"><i class="fas fa-info-circle"></i></span>
        <?php endif; ?>
        <span class="badge <?php echo $g['is_active'] ? '' : 'inactive'; ?>">
            <?php echo $g['is_active'] ? 'Active' : 'Inactive'; ?>
        </span>
        <span class="g-meta"><?php echo count($g['indicators']); ?> indicators</span>
        <button class="btn btn-outline btn-xs" onclick='openEditGroupModal(<?php echo json_encode($g); ?>)'>
            <i class="fas fa-edit"></i> Edit
        </button>
        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this group and ALL its indicators?')">
            <input type="hidden" name="action" value="delete_group">
            <input type="hidden" name="group_id" value="<?php echo $g['group_id']; ?>">
            <button class="btn btn-danger btn-xs" type="submit"><i class="fas fa-trash"></i></button>
        </form>
    </div>

    <div class="indicator-list" id="indList-<?php echo $g['group_id']; ?>">
        <?php if (empty($g['indicators'])): ?>
        <div style="padding:16px 20px;color:var(--muted);font-size:13px;font-style:italic;">No indicators yet — add one below.</div>
        <?php else: ?>
        <?php foreach ($g['indicators'] as $ii => $ind): ?>
        <div class="indicator-row" data-iid="<?php echo $ind['indicator_id']; ?>">
            <i class="fas fa-grip-vertical drag-handle"></i>
            <span class="ind-seq"><?php echo $ii+1; ?></span>
            <span class="ind-text <?php echo $ind['is_active'] ? '' : 'inactive'; ?>">
                <?php echo htmlspecialchars($ind['indicator_text']); ?>
            </span>
            <span class="pill <?php echo $ind['is_active'] ? 'pill-active' : 'pill-inactive'; ?>">
                <?php echo $ind['is_active'] ? 'Active' : 'Off'; ?>
            </span>
            <div class="ind-actions">
                <button class="btn btn-outline btn-xs" onclick='openEditIndicatorModal(<?php echo json_encode($ind); ?>)'>
                    <i class="fas fa-edit"></i>
                </button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this indicator?')">
                    <input type="hidden" name="action" value="delete_indicator">
                    <input type="hidden" name="indicator_id" value="<?php echo $ind['indicator_id']; ?>">
                    <button class="btn btn-danger btn-xs" type="submit"><i class="fas fa-times"></i></button>
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
                           required style="width:100%;padding:8px 11px;border:1.5px solid var(--border);border-radius:7px;font-family:var(--sans);font-size:13px;">
                </div>
                <div style="width:80px">
                    <input type="number" name="sort_order" placeholder="Order" min="1"
                           style="width:100%;padding:8px 11px;border:1.5px solid var(--border);border-radius:7px;font-family:var(--sans);font-size:13px;">
                </div>
                <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> Add</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="toggleAddForm(<?php echo $g['group_id']; ?>)">Cancel</button>
            </div>
        </form>
    </div>

    <div style="padding:10px 16px;border-top:1px solid var(--border);background:#fafbfc;">
        <button class="btn btn-outline btn-sm" onclick="toggleAddForm(<?php echo $g['group_id']; ?>)">
            <i class="fas fa-plus"></i> Add Indicator
        </button>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ADD GROUP MODAL -->
<div class="modal-backdrop" id="addGroupModal">
    <div class="modal">
        <div class="modal-head">
            <h3><i class="fas fa-plus-circle" style="color:var(--accent)"></i> &nbsp;Add Competency Group</h3>
            <button class="btn btn-outline btn-xs" onclick="closeModal('addGroupModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="add_group">
        <div class="modal-body">
            <div class="field-row"><div class="field">
                <label>Group Name <span style="color:var(--danger)">*</span></label>
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
            <button type="button" class="btn btn-outline" onclick="closeModal('addGroupModal')">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Group</button>
        </div>
        </form>
    </div>
</div>

<!-- EDIT GROUP MODAL -->
<div class="modal-backdrop" id="editGroupModal">
    <div class="modal">
        <div class="modal-head">
            <h3><i class="fas fa-edit" style="color:var(--accent)"></i> &nbsp;Edit Competency Group</h3>
            <button class="btn btn-outline btn-xs" onclick="closeModal('editGroupModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="edit_group">
        <input type="hidden" name="group_id" id="editGroupId">
        <div class="modal-body">
            <div class="field-row"><div class="field">
                <label>Group Name <span style="color:var(--danger)">*</span></label>
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
            <button type="button" class="btn btn-outline" onclick="closeModal('editGroupModal')">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Group</button>
        </div>
        </form>
    </div>
</div>

<!-- EDIT INDICATOR MODAL -->
<div class="modal-backdrop" id="editIndicatorModal">
    <div class="modal">
        <div class="modal-head">
            <h3><i class="fas fa-edit" style="color:var(--accent)"></i> &nbsp;Edit Indicator</h3>
            <button class="btn btn-outline btn-xs" onclick="closeModal('editIndicatorModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="edit_indicator">
        <input type="hidden" name="indicator_id" id="editIndId">
        <div class="modal-body">
            <div class="field-row"><div class="field">
                <label>Indicator Text <span style="color:var(--danger)">*</span></label>
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
            <button type="button" class="btn btn-outline" onclick="closeModal('editIndicatorModal')">Cancel</button>
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