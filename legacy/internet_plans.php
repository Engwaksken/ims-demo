<?php
ob_start();

$page_title = 'Manage Internet Plans';
require_once 'includes/header.php';

check_role(['Administrator', 'Operations/Admin', 'Accountant']);

require_once 'includes/internet-plans.php';
?>

<style>
.internet-price-old{text-decoration:line-through;color:var(--ink-200);font-size:12px}
.internet-price-final{font-weight:700;color:var(--green-fg)}
.internet-actions{display:flex;gap:6px;flex-wrap:wrap}
.internet-actions form{margin:0}
.internet-modal{display:none;position:fixed;z-index:9999;inset:0;background:rgba(15,23,42,.55);padding:20px;overflow:auto}
.internet-modal.show{display:block}
.internet-modal-card{background:var(--surface-card);max-width:680px;margin:40px auto;border-radius:var(--radius-xl);box-shadow:var(--shadow-xl);overflow:hidden}
.internet-modal-header{padding:18px 22px;background:var(--surface-hover);border-bottom:1px solid var(--ink-100);display:flex;justify-content:space-between;align-items:center}
.internet-modal-header h2{margin:0;font-size:20px}
.internet-modal-body{padding:22px}
.internet-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.internet-full{grid-column:1/-1}
.internet-close{background:transparent;border:0;font-size:22px;cursor:pointer;color:var(--ink-300)}
@media(max-width:800px){
    .internet-form-grid{grid-template-columns:1fr}
}
</style>

<div class="page-stack internet-wrap">

    <div class="page-hero">
        <div>
            <h1><i class="fas fa-wifi" aria-hidden="true"></i> Manage Internet Plans</h1>
            <p>Create plans, set discounts, bandwidth, and maximum devices per subscription.</p>
        </div>

        <div class="hero-actions">
            <a href="internet_subscriptions" class="btn">
                <i class="fas fa-list" aria-hidden="true"></i> Subscriptions
            </a>

            <button type="button" class="btn btn-primary" onclick="openPlanModal()">
                <i class="fas fa-plus" aria-hidden="true"></i> Add Internet Plan
            </button>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-circle-check"></i> <?= h($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-triangle-exclamation"></i> <?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-wifi" aria-hidden="true"></i> Internet Plans</h3>
            <input type="search" id="planSearch" class="form-control" placeholder="Search plans..." style="max-width:260px;">
        </div>

        <div class="table-responsive">
            <table class="table" id="plansTable">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Plan</th>
                    <th>Duration</th>
                    <th>Price</th>
                    <th>Discount</th>
                    <th>Final Price</th>
                    <th>Bandwidth</th>
                    <th>Devices</th>
                    <th>Status</th>
                    <th style="min-width:170px;">Action</th>
                </tr>
                </thead>

                <tbody>
                <?php if ($plans && $plans->num_rows > 0): ?>
                    <?php $i = 1; while ($row = $plans->fetch_assoc()): ?>
                        <tr>
                            <td><?= $i++ ?></td>

                            <td>
                                <strong><?= h($row['plan_name']) ?></strong><br>
                                <span class="badge badge-info">
                                    <?= h(strtoupper($row['duration_type'])) ?>
                                </span>
                            </td>

                            <td>
                                <?= number_format((int)$row['duration_minutes']) ?> mins<br>
                                <span class="cell-muted">
                                    <?= round(((int)$row['duration_minutes']) / 1440, 2) ?> day(s)
                                </span>
                            </td>

                            <td>
                                <span class="<?= ((float)$row['discount_percent'] > 0) ? 'internet-price-old' : '' ?>">
                                    UGX <?= number_format((float)$row['price']) ?>
                                </span>
                            </td>

                            <td><?= number_format((float)$row['discount_percent'], 2) ?>%</td>

                            <td class="internet-price-final">
                                UGX <?= number_format((float)$row['final_price']) ?>
                            </td>

                            <td><?= h($row['bandwidth_limit'] ?: '-') ?></td>

                            <td>
                                <span class="badge badge-info">
                                    <?= number_format((int)($row['device_limit'] ?? 1)) ?> device(s)
                                </span>
                            </td>

                            <td>
                                <span class="badge <?= $row['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                    <?= h(strtoupper($row['status'])) ?>
                                </span>
                            </td>

                            <td>
                                <div class="internet-actions">
                                    <button type="button"
                                            class="btn btn-sm btn-warning" title="Edit"
                                            onclick='openPlanModal(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button class="btn btn-sm btn-success" type="submit" title="Toggle status">
                                            <i class="fas fa-toggle-on"></i>
                                        </button>
                                    </form>

                                    <form method="post" onsubmit="return confirm('Delete this plan?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button class="btn btn-sm btn-danger" type="submit" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10"><div class="empty-state"><i class="fas fa-wifi" aria-hidden="true"></i><span>No internet plans found.</span></div></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="internet-modal" id="planModal">
    <div class="internet-modal-card">
        <div class="internet-modal-header">
            <h2 id="modalTitle">Add Internet Plan</h2>
            <button type="button" class="internet-close" onclick="closePlanModal()">&times;</button>
        </div>

        <div class="internet-modal-body">
            <form method="post">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="plan_id" value="0">

                <div class="internet-form-grid">
                    <div class="form-group internet-full">
                        <label>Plan Name</label>
                        <input class="form-control" type="text" name="plan_name" id="plan_name" required placeholder="Daily Internet">
                    </div>

                    <div class="form-group">
                        <label>Duration Type</label>
                        <select class="form-control" name="duration_type" id="duration_type" required>
                            <option value="">Select Type</option>
                            <option value="daily">Daily</option>
                            <option value="monthly">Monthly</option>
                            <option value="annual">Annual</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Duration Minutes</label>
                        <input class="form-control" type="number" name="duration_minutes" id="duration_minutes" required min="1">
                        <div class="cell-muted">Daily = 1440, Monthly = 43200, Annual = 525600</div>
                    </div>

                    <div class="form-group">
                        <label>Price</label>
                        <input class="form-control" type="number" step="0.01" name="price" id="price" required min="0">
                    </div>

                    <div class="form-group">
                        <label>Discount Percent</label>
                        <input class="form-control" type="number" step="0.01" name="discount_percent" id="discount_percent" min="0" max="100" value="0">
                    </div>

                    <div class="form-group">
                        <label>Final Price Preview</label>
                        <input class="form-control" type="text" id="final_price_preview" readonly>
                    </div>

                    <div class="form-group">
                        <label>Device Limit Per Subscription</label>
                        <input class="form-control" type="number" name="device_limit" id="device_limit" min="1" value="1" required>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-control" name="status" id="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="form-group internet-full">
                        <label>Bandwidth Limit</label>
                        <input class="form-control" type="text" name="bandwidth_limit" id="bandwidth_limit" placeholder="Example: 5Mbps / Unlimited">
                    </div>
                </div>

                <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;margin-top:8px;">
                    <i class="fas fa-save"></i> Save Plan
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const modal = document.getElementById('planModal');
const modalTitle = document.getElementById('modalTitle');

const planId = document.getElementById('plan_id');
const planName = document.getElementById('plan_name');
const durationType = document.getElementById('duration_type');
const durationMinutes = document.getElementById('duration_minutes');
const price = document.getElementById('price');
const discount = document.getElementById('discount_percent');
const finalPreview = document.getElementById('final_price_preview');
const bandwidthLimit = document.getElementById('bandwidth_limit');
const deviceLimit = document.getElementById('device_limit');
const statusField = document.getElementById('status');

function openPlanModal(plan = null) {
    modal.classList.add('show');

    if (plan) {
        modalTitle.innerHTML = 'Edit Internet Plan';
        planId.value = plan.id || 0;
        planName.value = plan.plan_name || '';
        durationType.value = plan.duration_type || '';
        durationMinutes.value = plan.duration_minutes || '';
        price.value = plan.price || '';
        discount.value = plan.discount_percent || 0;
        bandwidthLimit.value = plan.bandwidth_limit || '';
        deviceLimit.value = plan.device_limit || 1;
        statusField.value = plan.status || 'active';
    } else {
        modalTitle.innerHTML = 'Add Internet Plan';
        planId.value = 0;
        planName.value = '';
        durationType.value = '';
        durationMinutes.value = '';
        price.value = '';
        discount.value = 0;
        bandwidthLimit.value = '';
        deviceLimit.value = 1;
        statusField.value = 'active';
    }

    calculateFinalPrice();
}

function closePlanModal() {
    modal.classList.remove('show');
}

function setDurationMinutes() {
    if (durationType.value === 'daily') {
        durationMinutes.value = 1440;
    } else if (durationType.value === 'monthly') {
        durationMinutes.value = 43200;
    } else if (durationType.value === 'annual') {
        durationMinutes.value = 525600;
    }

    calculateFinalPrice();
}

function calculateFinalPrice() {
    const p = parseFloat(price.value || 0);
    const d = parseFloat(discount.value || 0);
    const finalPrice = p - ((p * d) / 100);

    finalPreview.value = 'UGX ' + finalPrice.toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2
    });
}

durationType.addEventListener('change', setDurationMinutes);
price.addEventListener('input', calculateFinalPrice);
discount.addEventListener('input', calculateFinalPrice);

window.addEventListener('click', function(e) {
    if (e.target === modal) {
        closePlanModal();
    }
});

document.getElementById('planSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#plansTable tbody tr').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<?php
require_once 'includes/footer.php';
ob_end_flush();
?>