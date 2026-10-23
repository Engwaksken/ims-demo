<?php
declare(strict_types=1);

$load_chartjs = true; // header.php loads Chart.js in <head> so inline chart scripts can run
require_once 'includes/header.php';
check_role(['Administrator', 'MEAL Lead', 'Programs Lead','Executive Director', 'Program Director']);

function h(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/* -- Survey ID -------------------------------------------------------- */
$surveyId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($surveyId <= 0) die("Invalid survey ID.");

/* -- Load survey meta ------------------------------------------------- */
$surveyName = 'Survey';
$res = $conn->query("SELECT survey_name FROM surveys WHERE survey_id = $surveyId LIMIT 1");
if ($res && $meta = $res->fetch_assoc()) {
    $surveyName = $meta['survey_name'];
}

/* -- Load questions with options -------------------------------------- */
$questions = [];
$res = $conn->query("
    SELECT q.question_id, q.question_text, q.question_type,
           GROUP_CONCAT(o.option_label ORDER BY o.option_id ASC) AS options
    FROM survey_questions q
    LEFT JOIN survey_options o ON o.question_id = q.question_id
    WHERE q.survey_id = $surveyId
    GROUP BY q.question_id
    ORDER BY q.question_order ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['options'] = $row['options'] ? explode(',', $row['options']) : [];
        $row['labels']  = $row['options'];
        $row['data']    = array_fill(0, count($row['labels']), 0);
        $questions[]    = $row;
    }
}

/* -- Total responses -------------------------------------------------- */
$totalResponses = 0;
$res = $conn->query("SELECT COUNT(*) AS cnt FROM survey_responses WHERE survey_id = $surveyId");
if ($res && $row = $res->fetch_assoc()) {
    $totalResponses = (int) $row['cnt'];
}

/* -- Helpers ---------------------------------------------------------- */
$type_icons = [
    'text'     => 'fa-font',
    'textarea' => 'fa-align-left',
    'number'   => 'fa-hashtag',
    'radio'    => 'fa-dot-circle',
    'checkbox' => 'fa-check-square',
    'select'   => 'fa-chevron-circle-down',
    'date'     => 'fa-calendar-alt',
];
$type_labels = [
    'text'     => 'Short Text',
    'textarea' => 'Paragraph',
    'number'   => 'Number',
    'radio'    => 'Multiple Choice',
    'checkbox' => 'Checkbox',
    'select'   => 'Dropdown',
    'date'     => 'Date',
];
$chart_types = ['radio' => 'pie', 'checkbox' => 'pie', 'select' => 'pie'];
$text_types  = ['text', 'textarea', 'date'];
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/survey.css">

<!-- Progress bar for polling refresh -->
<div class="refresh-bar" id="refresh-bar"></div>

<div class="surveys-wrap page-stack">

    <!-- -- Page Hero ---------------------------------------------------- -->
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-chart-bar" aria-hidden="true"></i> <?= h($surveyName) ?></h1>
            <p>Live response analytics - auto-refreshes every 5 seconds.</p>
        </div>
        <div class="hero-actions" style="align-items:center;">
            <div class="live-pill">
                <span class="live-dot"></span> Live
            </div>
            <a href="surveys" class="btn">
                <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Surveys
            </a>
        </div>
    </div>

    <!-- -- Stats Row ---------------------------------------------------- -->
    <div class="stats-grid reports-stats analytics-stats">

        <div class="stat-card">
            <div class="stat-icon bg-blue">
                <i class="fas fa-inbox"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Total Responses</div>
                <div class="stat-value" id="stat-total"><?= $totalResponses ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-primary">
                <i class="fas fa-list-ul"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Questions</div>
                <div class="stat-value"><?= count($questions) ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon bg-green">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Last Updated</div>
                <div class="stat-value" id="stat-updated" style="font-size:16px;letter-spacing:0;font-weight:700;">-</div>
            </div>
        </div>

    </div>

    <!-- -- Charts Grid -------------------------------------------------- -->
    <?php if (empty($questions)): ?>
        <div class="card">
        <div class="empty-state">
            <i class="fas fa-chart-pie" aria-hidden="true"></i>
            <h4>No questions found</h4>
            <p>Add questions to this survey in the builder to see analytics here.</p>
            <a href="survey-builder?id=<?= $surveyId ?>" class="btn btn-primary">
                <i class="fas fa-tools"></i> Go to Builder
            </a>
        </div>
        </div>
    <?php else: ?>
        <div class="charts-grid" id="charts-grid">
            <?php foreach ($questions as $i => $q):
                $qid    = (int) $q['question_id'];
                $qtype  = $q['question_type'];
                $isText = in_array($qtype, $text_types, true);
                $icon   = $type_icons[$qtype]  ?? 'fa-circle';
                $label  = $type_labels[$qtype] ?? $qtype;
            ?>
                <div class="card chart-card" data-qid="<?= $qid ?>">

                    <div class="chart-card-head">
                        <div class="chart-card-qnum"><?= $i + 1 ?></div>
                        <div class="chart-card-title"><?= h($q['question_text']) ?></div>
                        <span class="badge badge-info">
                            <i class="fas <?= $icon ?>"></i> <?= $label ?>
                        </span>
                    </div>

                    <div class="chart-card-body">
                        <?php if ($isText): ?>
                            <!-- Text / date questions show answer list -->
                            <div class="text-answers-list" id="text-list-<?= $qid ?>">
                                <div class="text-answer-item placeholder">
                                    <i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i> Loading responses…
                                </div>
                            </div>
                        <?php else: ?>
                            <canvas id="chart-<?= $qid ?>"></canvas>
                        <?php endif; ?>
                    </div>

                    <div class="chart-card-foot">
                        <i class="fas fa-users" style="color:var(--ink-200);font-size:12px;"></i>
                        <span class="foot-count" id="foot-count-<?= $qid ?>">0</span>
                        <span class="foot-label">response<?php ?></span>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div><!-- /.surveys-wrap -->

<script>
(function () {
    'use strict';

    const SURVEY_ID   = <?= $surveyId ?>;
    const POLL_MS     = 5000;

    /* Design-system palette for chart segments */
    const PALETTE_BG  = [
        'rgba(249,115,22,.75)',   /* brand-500  */
        'rgba(29, 78,216,.70)',   /* blue       */
        'rgba(21,128, 61,.70)',   /* green      */
        'rgba(180, 83,  9,.70)',  /* amber      */
        'rgba(185, 28, 28,.70)',  /* red        */
        'rgba(71, 85,105,.70)',   /* slate      */
        'rgba(139, 92,246,.70)',  /* purple     */
    ];
    const PALETTE_BDR = PALETTE_BG.map(c => c.replace(/[\d.]+\)$/, '1)'));

    /* Question metadata from PHP */
    const QUESTIONS = <?= json_encode(array_map(function ($q) use ($chart_types, $text_types) {
        return [
            'id'      => (int) $q['question_id'],
            'type'    => $q['question_type'],
            'labels'  => $q['labels'],
            'isText'  => in_array($q['question_type'], $text_types, true),
            'chart'   => $chart_types[$q['question_type']] ?? 'bar',
        ];
    }, $questions), JSON_THROW_ON_ERROR) ?>;

    const charts = {};

    /* -- Build initial charts -- */
    QUESTIONS.forEach(function (q) {
        if (q.isText) return;
        const canvas = document.getElementById('chart-' + q.id);
        if (!canvas) return;

        charts[q.id] = new Chart(canvas, {
            type: q.chart,
            data: {
                labels: q.labels,
                datasets: [{
                    label: 'Responses',
                    data: q.labels.map(() => 0),
                    backgroundColor: PALETTE_BG,
                    borderColor: PALETTE_BDR,
                    borderWidth: 1.5,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: { family: "'DM Sans', sans-serif", size: 12 },
                            padding: 16,
                            usePointStyle: true,
                        },
                    },
                    tooltip: {
                        bodyFont: { family: "'DM Sans', sans-serif" },
                        titleFont: { family: "'DM Sans', sans-serif", weight: '700' },
                    },
                },
                scales: q.chart === 'pie' ? {} : {
                    y: {
                        beginAtZero: true,
                        precision: 0,
                        ticks: {
                            font: { family: "'DM Sans', sans-serif", size: 12 },
                            stepSize: 1,
                        },
                        grid: { color: 'rgba(0,0,0,.06)' },
                    },
                    x: {
                        ticks: { font: { family: "'DM Sans', sans-serif", size: 12 } },
                        grid: { display: false },
                    },
                },
            },
        });
    });

    /* -- Refresh bar helpers -- */
    const bar = document.getElementById('refresh-bar');
    function barRun()  { bar.className = 'refresh-bar running'; }
    function barDone() {
        bar.className = 'refresh-bar done';
        setTimeout(() => { bar.className = 'refresh-bar'; }, 400);
    }

    /* -- Update last-updated clock -- */
    function updateClock() {
        const el = document.getElementById('stat-updated');
        if (!el) return;
        const now = new Date();
        el.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    /* -- Render text answers list -- */
    function renderTextList(qid, answers) {
        const list = document.getElementById('text-list-' + qid);
        if (!list) return;

        if (!answers || answers.length === 0) {
            list.innerHTML = '<div class="text-answer-item placeholder"><i class="fas fa-inbox" style="margin-right:6px;"></i>No responses yet.</div>';
            return;
        }

        list.innerHTML = answers.slice(0, 10).map(function (a) {
            return '<div class="text-answer-item">' + String(a).replace(/</g, '&lt;') + '</div>';
        }).join('');
    }

    /* -- Main polling function -- */
    function fetchData() {
        barRun();

        fetch('includes/get-survey-stats.php?survey_id=' + SURVEY_ID)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                barDone();
                updateClock();

                if (!data.questions) return;

                let total = 0;

                data.questions.forEach(function (q) {
                    const meta = QUESTIONS.find(function (m) { return m.id === q.question_id; });
                    if (!meta) return;

                    const count = Array.isArray(q.data)
                        ? q.data.reduce(function (a, b) { return a + b; }, 0)
                        : 0;
                    total += count;

                    /* foot count */
                    const footEl = document.getElementById('foot-count-' + q.question_id);
                    if (footEl) footEl.textContent = count;

                    if (meta.isText) {
                        renderTextList(q.question_id, q.answers || []);
                    } else {
                        const chart = charts[q.question_id];
                        if (!chart) return;
                        chart.data.labels = q.labels || meta.labels;
                        chart.data.datasets[0].data = q.data || [];
                        chart.update('active');
                    }
                });

                const statEl = document.getElementById('stat-total');
                if (statEl) statEl.textContent = total;
            })
            .catch(function (err) {
                barDone();
                console.warn('Analytics poll failed:', err);
            });
    }

    /* -- Kick off -- */
    fetchData();
    setInterval(fetchData, POLL_MS);
    updateClock();

})();
</script>

<?php include 'includes/footer.php'; ?>