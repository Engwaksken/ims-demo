<?php
$page_title = 'Projects Report';

$load_chartjs = true; // header.php loads Chart.js in <head> so inline chart scripts can run
require_once __DIR__ . '/includes/header.php';


check_role(['Administrator', 'Programs Lead', 'MEAL Lead']);

/* =========================================================
   FILTERS
========================================================= */
$filters = [
    'status'    => $_GET['status']    ?? '',
    'donor'     => intval($_GET['donor'] ?? 0),
    'from_date' => $_GET['from_date'] ?? '',
    'to_date'   => $_GET['to_date']   ?? ''
];

$where  = [];
$params = [];
$types  = '';

if ($filters['status']) {
    $where[]  = "p.status = ?";
    $params[] = $filters['status'];
    $types   .= 's';
}

if ($filters['donor']) {
    $where[]  = "p.donor_id = ?";
    $params[] = $filters['donor'];
    $types   .= 'i';
}

if ($filters['from_date']) {
    $where[]  = "p.start_date >= ?";
    $params[] = $filters['from_date'];
    $types   .= 's';
}

if ($filters['to_date']) {
    $where[]  = "p.end_date <= ?";
    $params[] = $filters['to_date'];
    $types   .= 's';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* =========================================================
   FETCH PROJECTS
========================================================= */
$sql = "
    SELECT
        p.project_id,
        p.project_code,
        p.project_name,
        p.status,
        p.start_date,
        p.end_date,
        p.budget,
        p.currency,
        d.donor_name,
        (
            SELECT COUNT(*)
            FROM project_beneficiaries pb
            WHERE pb.project_id = p.project_id
        ) AS beneficiaries,
        (
            SELECT COUNT(*)
            FROM indicators i
            WHERE i.project_id = p.project_id
        ) AS indicators
    FROM projects p
    LEFT JOIN donors d ON p.donor_id = d.donor_id
    $whereSql
    ORDER BY p.start_date DESC
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

/* =========================================================
   DATA AGGREGATION
========================================================= */
$projects      = [];
$total_budget  = 0;
$status_count  = [
    'Ongoing'   => 0,
    'Completed' => 0,
    'Pending'   => 0,
    'Cancelled' => 0
];

while ($row = $result->fetch_assoc()) {
    $projects[] = $row;
    $total_budget += (float)$row['budget'];

    if (isset($status_count[$row['status']])) {
        $status_count[$row['status']]++;
    }
}

/* =========================================================
   FETCH DONORS (FILTER DROPDOWN)
========================================================= */
$donors = [];
$donorRes = $conn->query("
    SELECT donor_id, donor_name
    FROM donors
    ORDER BY donor_name ASC
");

while ($d = $donorRes->fetch_assoc()) {
    $donors[] = $d;
}

?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3><i class="fas fa-chart-bar"></i> Projects Report</h3>

        <div class="btn-group">
            <button class="btn btn-secondary btn-sm" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>

            <a href="exports/report-projects-export.php?<?= h(http_build_query($_GET)); ?>" 
               class="btn btn-success btn-sm">
                <i class="fas fa-file-csv"></i> CSV
            </a>

            <a href="exports/report-projects-pdf.php?<?= h(http_build_query($_GET)); ?>" 
               class="btn btn-danger btn-sm">
                <i class="fas fa-file-pdf"></i> PDF
            </a>
        </div>
    </div>

    <div class="card-body">

        <!-- Filters -->
        <form method="GET" class="filters-bar mb-20">
            <select name="status" class="form-control">
                <option value="">All Status</option>
                <?php foreach($status_count as $s=>$c): ?>
                    <option value="<?= $s ?>" <?= $filter_status==$s?'selected':''; ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>

            <select name="donor" class="form-control">
                <option value="">All Donors</option>
                <?php foreach($donors as $donor): ?>
                    <option value="<?= $donor['donor_id']; ?>" <?= $filter_donor==$donor['donor_id']?'selected':''; ?>>
                        <?= htmlspecialchars($donor['donor_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date); ?>">
            <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date); ?>">

            <button class="btn btn-info"><i class="fas fa-filter"></i> Apply</button>
            <a href="report-projects" class="btn btn-secondary">Clear</a>
        </form>

        <!-- Summary -->
        <div class="stats-grid reports-stats">
            <div class="stat-card">
                <div class="stat-icon bg-primary"><i class="fas fa-diagram-project" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Projects</span>
                    <span class="stat-value"><?= count($projects); ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-green"><i class="fas fa-coins" aria-hidden="true"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Total Budget</span>
                    <span class="stat-value" style="font-size:20px"><?= format_currency($total_budget,'UGX'); ?></span>
                </div>
            </div>
            <?php foreach($status_count as $s=>$c):
                $sk = strtolower((string)$s);
                if (strpos($sk, 'complet') !== false)                                   { $si = ['fa-circle-check', 'bg-green']; }
                elseif (strpos($sk, 'cancel') !== false || strpos($sk, 'suspend') !== false) { $si = ['fa-ban', 'bg-slate']; }
                elseif (strpos($sk, 'hold') !== false || strpos($sk, 'pend') !== false || strpos($sk, 'plan') !== false) { $si = ['fa-clock', 'bg-amber']; }
                elseif (strpos($sk, 'activ') !== false || strpos($sk, 'ongo') !== false || strpos($sk, 'progress') !== false) { $si = ['fa-play', 'bg-blue']; }
                else                                                                      { $si = ['fa-tag', 'bg-purple']; }
            ?>
                <div class="stat-card">
                    <div class="stat-icon <?= $si[1]; ?>"><i class="fas <?= $si[0]; ?>" aria-hidden="true"></i></div>
                    <div class="stat-info">
                        <span class="stat-label"><?= htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="stat-value"><?= $c; ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Report Table -->
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Project</th>
                        <th>Donor</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Budget</th>
                        <th>Participant</th>
                        <th>Indicators</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($projects)): ?>
                        <tr>
                            <td colspan="8" class="text-center">No data available</td>
                        </tr>
                    <?php else: foreach($projects as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['project_code']); ?></td>
                            <td><?= htmlspecialchars($p['project_name']); ?></td>
                            <td><?= htmlspecialchars($p['donor_name'] ?? 'N/A'); ?></td>
                            <td>
                                <?= $p['start_date'] && $p['end_date']
                                    ? date('M Y',strtotime($p['start_date'])).' - '.date('M Y',strtotime($p['end_date']))
                                    : '—'; ?>
                            </td>
                            <td>
                                <?php $badge=['Ongoing'=>'success','Completed'=>'info','Pending'=>'warning','Cancelled'=>'danger'][$p['status']]; ?>
                                <span class="badge badge-<?= $badge ?>"><?= $p['status']; ?></span>
                            </td>
                            <td><?= format_currency($p['budget'],$p['currency']); ?></td>
                            <td class="text-center"><?= $p['beneficiaries']; ?></td>
                            <td class="text-center"><?= $p['indicators']; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<div class="charts-container d-flex flex-column align-items-center">
    <!-- Smaller pie chart -->
    <canvas id="statusChart" width="300" height="300"></canvas>
    <canvas id="budgetChart" height="120" class="mt-3" style="max-width: 100%;"></canvas>
</div>

<script>
// ----------------------
// Helper function to generate dynamic colors
// ----------------------
function generateColors(count) {
    const colors = [];
    for (let i = 0; i < count; i++) {
        const hue = Math.floor((360 / count) * i);
        colors.push(`hsl(${hue}, 70%, 50%)`);
    }
    return colors;
}

// ----------------------
// Project Status Pie Chart
// ----------------------
const statusLabels = <?= json_encode(array_keys($status_count)); ?>;
const statusValues = <?= json_encode(array_values($status_count)); ?>;
const statusColors = generateColors(statusLabels.length);

new Chart(document.getElementById('statusChart'), {
    type: 'pie',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusValues,
            backgroundColor: statusColors,
            borderColor: '#fff',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: { enabled: true }
        }
    }
});

// ----------------------
// Project Budget Bar Chart
// ----------------------
const projectLabels = <?= json_encode(array_map('htmlspecialchars', array_column($projects, 'project_name'))); ?>;
const projectBudgets = <?= json_encode(array_column($projects, 'budget')); ?>;
const barColors = generateColors(projectLabels.length);

new Chart(document.getElementById('budgetChart'), {
    type: 'bar',
    data: {
        labels: projectLabels,
        datasets: [{
            label: 'Budget',
            data: projectBudgets,
            backgroundColor: barColors
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Budget: ' + context.raw.toLocaleString();
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) { return value.toLocaleString(); }
                }
            }
        }
    }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
