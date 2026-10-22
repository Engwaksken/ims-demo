<?php
ob_start();
//session_start();
date_default_timezone_set('Africa/Nairobi');

require_once 'includes/config.php';
require_once 'includes/AiReviewService.php';

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!ims_allowed('ai_apis.manage', ['Administrator', 'Operations/Admin'], 'Manage AI API connections')) {
    $_SESSION['error'] = 'Access denied. Admin only.';
    header('Location: dashboard');
    exit;
}

$userId  = (int) $_SESSION['user_id'];
$service = new AiReviewService($conn);

if (empty($_SESSION['csrf_ai_api'])) {
    $_SESSION['csrf_ai_api'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_ai_api'];

require_once 'includes/process-ai-apis.php';

$apis    = aiFetchAll($conn);
$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$total    = count($apis);
$active   = count(array_filter($apis, static fn($a) => (int)($a['is_active'] ?? 0) === 1));
$defaults = count(array_filter($apis, static fn($a) => (int)($a['is_default'] ?? 0) === 1));
$inactive = $total - $active;

$providers = ['OpenAI', 'Gemini', 'Grok', 'Anthropic', 'Groq', 'Mistral', 'DeepSeek', 'OpenRouter', 'Local', 'Other'];

$page_title = 'Manage AI APIs';
require_once 'includes/header.php';
?>

<link rel="stylesheet" href="css/opportunities.css">

<style>
#apiModal.opp-modal{
    display:none;
    position:fixed;
    inset:0;
    width:100%;
    height:100%;
    z-index:999999;
    background:rgba(15,23,42,.72);
    padding:18px;
    overflow:hidden;
    align-items:center;
    justify-content:center;
    backdrop-filter:blur(4px);
    -webkit-backdrop-filter:blur(4px);
}

#apiModal.opp-modal.show{
    display:flex !important;
}

#apiModal .modal-card{
    width:100%;
    max-width:850px;
    height:auto;
    max-height:90vh;
    margin:auto;
    background:var(--surface-card,#fff);
    border-radius:var(--radius-2xl,24px);
    box-shadow:0 25px 80px rgba(0,0,0,.35);
    overflow:hidden;
    display:flex;
    flex-direction:column;
}

#apiModal #apiForm{
    display:flex;
    flex-direction:column;
    flex:1;
    min-height:0;
}

#apiModal .modal-head,
#apiModal .modal-foot{
    flex-shrink:0;
    background:var(--surface-card,#fff);
    z-index:2;
}

#apiModal .modal-body{
    flex:1 1 auto;
    min-height:0;
    overflow-y:auto;
    overflow-x:hidden;
    padding-bottom:22px;
}

body.modal-open{
    overflow:hidden !important;
}

.modal-backdrop{
    display:none !important;
}

@media(max-width:768px){
    #apiModal.opp-modal{
        padding:8px;
        align-items:flex-start;
    }

    #apiModal .modal-card{
        max-height:96vh;
        border-radius:18px;
    }
}
</style>

<div class="ai-page">
    <div class="opp-hero">
        <div class="opp-hero-left">
            <div class="opp-eyebrow"><span class="opp-dot"></span> AI Infrastructure</div>
            <h1><i class="fas fa-robot"></i> Manage AI APIs</h1>
            <p>Configure, activate and monitor AI providers used for automated application reviews.</p>
        </div>

        <div class="opp-hero-actions">
            <a href="startups-shortlisting" class="btn btn-white btn-sm">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <button type="button" class="btn btn-white btn-sm js-open-api-modal">
                <i class="fas fa-plus"></i> Add AI API
            </button>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="flash flash-success"><i class="fas fa-check-circle"></i><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i><?= h($error) ?></div>
    <?php endif; ?>

    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-robot"></i></div>
            <div class="stat-info"><span class="stat-label">Total APIs</span><span class="stat-value"><?= (int)$total ?></span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-circle-check"></i></div>
            <div class="stat-info"><span class="stat-label">Active</span><span class="stat-value"><?= (int)$active ?></span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-star"></i></div>
            <div class="stat-info"><span class="stat-label">Default</span><span class="stat-value"><?= (int)$defaults ?></span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-ban"></i></div>
            <div class="stat-info"><span class="stat-label">Inactive</span><span class="stat-value"><?= (int)$inactive ?></span></div>
        </div>
    </div>

    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-list" style="color:var(--brand-500)"></i> Configured AI APIs</h3>
            <button type="button" class="btn btn-primary btn-sm js-open-api-modal">
                <i class="fas fa-plus"></i> Add API
            </button>
        </div>

        <div class="opp-panel-body" style="padding:0">
            <div class="table-overflow">
                <table class="api-table">
                    <thead>
                    <tr>
                        <th>API / Provider</th>
                        <th>Model</th>
                        <th>Endpoint</th>
                        <th>Key</th>
                        <th>Settings</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($apis)): ?>
                        <tr class="empty-row">
                            <td colspan="8">
                                <div style="display:flex;flex-direction:column;align-items:center;gap:12px">
                                    <div class="opp-empty-icon"><i class="fas fa-robot"></i></div>
                                    <strong style="color:var(--ink-700);font-size:15px">No AI APIs configured yet</strong>
                                    <p style="color:var(--ink-300);font-size:13px">Click <em>Add API</em> to connect your first provider.</p>
                                    <button type="button" class="btn btn-primary btn-sm js-open-api-modal">
                                        <i class="fas fa-plus"></i> Add API
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($apis as $api): ?>
                            <?php
                            $apiJson = htmlspecialchars(
                                json_encode($api, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            ?>
                            <tr>
                                <td>
                                    <div class="api-name">
                                        <?= h($api['api_name'] ?? '') ?>
                                        <?php if ((int)($api['is_default'] ?? 0) === 1): ?>
                                            <span class="badge badge-warning" style="font-size:9px">
                                                <i class="fas fa-star"></i> Default
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="provider-pill" style="margin-top:5px;display:inline-flex">
                                        <i class="fas fa-microchip"></i><?= h($api['provider_name'] ?? '') ?>
                                    </span>
                                </td>

                                <td>
                                    <code style="font-size:12px;color:var(--ink-700);background:var(--ink-50);padding:3px 7px;border-radius:5px">
                                        <?= h($api['model_name'] ?? '') ?>
                                    </code>
                                </td>

                                <td>
                                    <?php if (!empty($api['api_endpoint'])): ?>
                                        <span style="font-size:11px;color:var(--ink-300);word-break:break-all;max-width:180px;display:block">
                                            <?= h($api['api_endpoint']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:var(--ink-200);font-size:12px">-</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (!empty($api['api_key_last4'])): ?>
                                        <span class="key-hint">....<?= h($api['api_key_last4']) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">No key</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div style="display:flex;flex-direction:column;gap:4px">
                                        <span class="setting-chip"><i class="fas fa-thermometer-half"></i> <?= h($api['temperature'] ?? '0.30') ?></span>
                                        <span class="setting-chip"><i class="fas fa-coins"></i> <?= number_format((int)($api['max_tokens'] ?? 0)) ?> tok</span>
                                        <span class="setting-chip"><i class="fas fa-clock"></i> <?= h($api['timeout_seconds'] ?? '60') ?>s</span>
                                    </div>
                                </td>

                                <td>
                                    <?php if ((int)($api['is_active'] ?? 0) === 1): ?>
                                        <span class="badge badge-published"><i class="fas fa-circle" style="font-size:7px"></i> Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><i class="fas fa-circle" style="font-size:7px"></i> Inactive</span>
                                    <?php endif; ?>
                                </td>

                                <td style="font-size:11px;color:var(--ink-300);white-space:nowrap">
                                    <?= h(substr(($api['updated_at'] ?: $api['created_at']) ?? '', 0, 16)) ?>
                                </td>

                                <td>
                                    <div class="tbl-actions">
                                        <button type="button" class="btn btn-secondary btn-sm js-edit-api" title="Edit" data-api="<?= $apiJson ?>">
                                            <i class="fas fa-pencil"></i>
                                        </button>

                                        <form class="inline-f" method="post">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="default">
                                            <input type="hidden" name="ai_api_id" value="<?= (int)($api['ai_api_id'] ?? 0) ?>">
                                            <button class="btn btn-sm <?= (int)($api['is_default'] ?? 0) === 1 ? 'btn-warning' : 'btn-light' ?>" title="Set as default" type="submit">
                                                <i class="fas fa-star"></i>
                                            </button>
                                        </form>

                                        <form class="inline-f" method="post">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="ai_api_id" value="<?= (int)($api['ai_api_id'] ?? 0) ?>">
                                            <button class="btn btn-sm <?= (int)($api['is_active'] ?? 0) === 1 ? 'btn-success' : 'btn-secondary' ?>" title="<?= (int)($api['is_active'] ?? 0) === 1 ? 'Deactivate' : 'Activate' ?>" type="submit">
                                                <i class="fas fa-power-off"></i>
                                            </button>
                                        </form>

                                        <form class="inline-f" method="post" onsubmit="return confirm('Delete this AI API?\n\nIf it has review history it will be deactivated instead.');">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="ai_api_id" value="<?= (int)($api['ai_api_id'] ?? 0) ?>">
                                            <button class="btn btn-danger btn-sm" title="Delete" type="submit">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="opp-modal" id="apiModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-card" role="document">
        <div class="modal-head">
            <h3 id="modalTitle">
                <i class="fas fa-robot" style="color:var(--brand-500)"></i>
                <span id="modalHeadingText">Add AI API</span>
            </h3>
            <button type="button" class="modal-close js-close-api-modal" aria-label="Close">&times;</button>
        </div>

        <form method="post" autocomplete="off" id="apiForm">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="ai_api_id" id="fApiId" value="0">

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required" for="fProvider">Provider</label>
                        <select class="form-control" name="provider_name" id="fProvider" required>
                            <option value="">Select provider...</option>
                            <?php foreach ($providers as $p): ?>
                                <option value="<?= h($p) ?>"><?= h($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required" for="fApiName">Display Name</label>
                        <input class="form-control" type="text" name="api_name" id="fApiName" required placeholder="e.g. Gemini Production">
                    </div>
                </div>

                <div class="form-grid" style="margin-top:14px">
                    <div class="form-group">
                        <label class="form-label required" for="fModel">Model Name</label>
                        <input class="form-control" type="text" name="model_name" id="fModel" required placeholder="e.g. gemini-1.5-flash">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="fEndpoint">API Endpoint</label>
                        <input class="form-control" type="url" name="api_endpoint" id="fEndpoint" placeholder="https://api.example.com/v1/...">
                    </div>
                </div>

                <div class="form-grid" style="margin-top:14px">
                    <div class="form-group full">
                        <label class="form-label" for="fApiKey">
                            API Key <span id="fKeyRequired" style="color:var(--red-fg)">*</span>
                        </label>
                        <input class="form-control" type="password" name="api_key" id="fApiKey" placeholder="Paste API key securely">
                        <div id="fKeyHint" style="font-size:11px;color:var(--ink-300);margin-top:5px;display:none">
                            Current key ends: <span class="key-hint" id="fKeyLast4"></span> - leave blank to keep.
                        </div>
                    </div>
                </div>

                <div class="form-section-label" style="margin-top:16px">Parameters</div>

                <div class="form-grid cols-3">
                    <div class="form-group">
                        <label class="form-label" for="fTemp">Temperature</label>
                        <input class="form-control" type="number" step="0.01" min="0" max="2" name="temperature" id="fTemp" value="0.30">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="fTokens">Max Tokens</label>
                        <input class="form-control" type="number" min="100" name="max_tokens" id="fTokens" value="4000">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="fTimeout">Timeout (s)</label>
                        <input class="form-control" type="number" min="10" name="timeout_seconds" id="fTimeout" value="60">
                    </div>
                </div>

                <div style="margin-top:14px">
                    <div class="toggle-group">
                        <label class="toggle-item">
                            <input type="checkbox" name="is_active" id="fActive" checked>
                            <i class="fas fa-circle-check" style="color:var(--green-fg)"></i> Active
                        </label>
                        <label class="toggle-item">
                            <input type="checkbox" name="is_default" id="fDefault">
                            <i class="fas fa-star" style="color:var(--amber-fg)"></i> Set as Default
                        </label>
                    </div>
                </div>

                <div class="form-group" style="margin-top:14px">
                    <label class="form-label" for="fNotes">Notes</label>
                    <textarea class="form-control" name="notes" id="fNotes" placeholder="Optional notes about this API configuration..."></textarea>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-secondary js-close-api-modal">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary" id="fSubmitBtn">
                    <i class="fas fa-save"></i> <span id="fSubmitLabel">Save API</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    const modalEl = document.getElementById('apiModal');
    const formEl  = document.getElementById('apiForm');

    if (!modalEl || !formEl) return;

    const $ = id => document.getElementById(id);

    const setValue = (id, value) => {
        const el = $(id);
        if (el) el.value = value ?? '';
    };

    const setText = (id, value) => {
        const el = $(id);
        if (el) el.textContent = value ?? '';
    };

    const setDisplay = (id, value) => {
        const el = $(id);
        if (el) el.style.display = value;
    };

    const setChecked = (id, value) => {
        const el = $(id);
        if (el) el.checked = value === true || value === 1 || value === '1';
    };

    function resetModal() {
        formEl.reset();

        setValue('fApiId', '0');
        setValue('fProvider', '');
        setValue('fApiName', '');
        setValue('fModel', '');
        setValue('fEndpoint', '');
        setValue('fApiKey', '');
        setValue('fTemp', '0.30');
        setValue('fTokens', '4000');
        setValue('fTimeout', '60');
        setValue('fNotes', '');

        setChecked('fActive', true);
        setChecked('fDefault', false);

        setText('modalHeadingText', 'Add AI API');
        setText('fSubmitLabel', 'Save API');
        setText('fKeyLast4', '');

        setDisplay('fKeyHint', 'none');
        setDisplay('fKeyRequired', '');
    }

    function openModal(api) {
        resetModal();

        if (api && typeof api === 'object') {
            setText('modalHeadingText', 'Edit AI API');
            setText('fSubmitLabel', 'Update API');

            setValue('fApiId', api.ai_api_id || 0);
            setValue('fProvider', api.provider_name || '');
            setValue('fApiName', api.api_name || '');
            setValue('fModel', api.model_name || '');
            setValue('fEndpoint', api.api_endpoint || '');
            setValue('fTemp', api.temperature || '0.30');
            setValue('fTokens', api.max_tokens || '4000');
            setValue('fTimeout', api.timeout_seconds || '60');
            setValue('fNotes', api.notes || '');

            setChecked('fActive', api.is_active);
            setChecked('fDefault', api.is_default);

            setDisplay('fKeyRequired', 'none');

            if (api.api_key_last4) {
                setText('fKeyLast4', '....' + api.api_key_last4);
                setDisplay('fKeyHint', 'block');
            }
        }

        modalEl.classList.add('show');
        modalEl.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');

        setTimeout(() => {
            const firstInput = $('fProvider');
            if (firstInput) firstInput.focus();
        }, 50);
    }

    function closeModal() {
        modalEl.classList.remove('show');
        modalEl.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
    }

    window.openModal = openModal;
    window.closeModal = closeModal;

    document.querySelectorAll('.js-open-api-modal').forEach(btn => {
        btn.addEventListener('click', () => openModal());
    });

    document.querySelectorAll('.js-edit-api').forEach(btn => {
        btn.addEventListener('click', () => {
            try {
                openModal(JSON.parse(btn.getAttribute('data-api') || '{}'));
            } catch (e) {
                alert('Could not open edit form. Invalid API data.');
            }
        });
    });

    document.querySelectorAll('.js-close-api-modal').forEach(btn => {
        btn.addEventListener('click', closeModal);
    });

    modalEl.addEventListener('click', e => {
        if (e.target === modalEl) closeModal();
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modalEl.classList.contains('show')) closeModal();
    });
})();
</script>

<?php
require_once 'includes/footer.php';
ob_end_flush();
?>