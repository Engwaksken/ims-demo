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

<style>
/* Manage AI APIs: page-only layout (ai- prefix) */
.ai-name { font-weight: 800; color: var(--ink-700); font-size: var(--fs-base); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ai-provider {
    display: inline-flex; align-items: center; gap: 6px; margin-top: 5px;
    font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: var(--radius-pill);
    background: var(--brand-50); color: var(--brand-700); border: 1px solid var(--brand-100);
}
.ai-model { font-size: var(--fs-xs); color: var(--ink-700); background: var(--ink-50); padding: 3px 7px; border-radius: var(--radius-sm); }
.ai-endpoint { display: block; max-width: 180px; font-size: 11px; color: var(--ink-300); word-break: break-all; }
.ai-key { font-size: var(--fs-xs); color: var(--ink-400); letter-spacing: .08em; background: none; }
.ai-settings { display: flex; flex-direction: column; gap: 4px; align-items: flex-start; }
.ai-chip {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; color: var(--ink-400); background: var(--ink-50);
    border: 1px solid var(--ink-100); padding: 3px 8px; border-radius: var(--radius-pill);
}
.ai-dot { font-size: 7px; }
.ai-updated { font-size: 11px; color: var(--ink-300); white-space: nowrap; }
.ai-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.ai-inline-form { display: inline; margin: 0; padding: 0; }

/* Modal form (global .modal/.modal-card; JS toggles .show and body.modal-open) */
body.modal-open { overflow: hidden !important; }
.modal-backdrop { display: none !important; }
#apiModal .modal-card { max-width: 850px; }
.ai-head-icon { color: var(--brand-600); }
.ai-form-row { margin-top: 14px; }
.ai-req { color: var(--red-fg); }
.ai-key-hint { display: none; margin-top: 5px; font-size: 11px; color: var(--ink-300); }
.ai-form-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.ai-toggles {
    display: flex; gap: 16px; flex-wrap: wrap; align-items: center;
    padding: 10px 14px; background: var(--ink-50); border: 1px solid var(--ink-100); border-radius: var(--radius-md);
}
.ai-toggle { display: flex; align-items: center; gap: 8px; font-size: var(--fs-sm); font-weight: 700; color: var(--ink-500); cursor: pointer; margin: 0; }
.ai-toggle input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--brand-500); cursor: pointer; }
.ai-toggle .fa-circle-check { color: var(--green-fg); }
.ai-toggle .fa-star { color: var(--amber-fg); }

@media (max-width: 768px) {
    .ai-form-3 { grid-template-columns: 1fr; }
}
</style>

<div class="page-stack ai-page">
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-robot" aria-hidden="true"></i> Manage AI APIs</h1>
            <p>Configure, activate and monitor AI providers used for automated application reviews.</p>
        </div>

        <div class="hero-actions">
            <a href="startups-shortlisting" class="btn">
                <i class="fas fa-arrow-left" aria-hidden="true"></i> Back
            </a>
            <button type="button" class="btn btn-primary js-open-api-modal">
                <i class="fas fa-plus" aria-hidden="true"></i> Add AI API
            </button>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle" aria-hidden="true"></i><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle" aria-hidden="true"></i><?= h($error) ?></div>
    <?php endif; ?>

    <div class="stats-grid reports-stats">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-robot" aria-hidden="true"></i></div>
            <div class="stat-info"><span class="stat-label">Total APIs</span><span class="stat-value"><?= (int)$total ?></span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-circle-check" aria-hidden="true"></i></div>
            <div class="stat-info"><span class="stat-label">Active</span><span class="stat-value"><?= (int)$active ?></span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-star" aria-hidden="true"></i></div>
            <div class="stat-info"><span class="stat-label">Default</span><span class="stat-value"><?= (int)$defaults ?></span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-ban" aria-hidden="true"></i></div>
            <div class="stat-info"><span class="stat-label">Inactive</span><span class="stat-value"><?= (int)$inactive ?></span></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list" aria-hidden="true"></i> Configured AI APIs</h3>
            <button type="button" class="btn btn-primary btn-sm js-open-api-modal">
                <i class="fas fa-plus" aria-hidden="true"></i> Add API
            </button>
        </div>

        <?php if (empty($apis)): ?>
            <div class="empty-state">
                <i class="fas fa-robot" aria-hidden="true"></i>
                <p>No AI APIs configured yet. Click <em>Add API</em> to connect your first provider.</p>
                <button type="button" class="btn btn-primary btn-sm js-open-api-modal">
                    <i class="fas fa-plus" aria-hidden="true"></i> Add API
                </button>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
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
                                <div class="ai-name">
                                    <?= h($api['api_name'] ?? '') ?>
                                    <?php if ((int)($api['is_default'] ?? 0) === 1): ?>
                                        <span class="badge badge-warning">
                                            <i class="fas fa-star" aria-hidden="true"></i> Default
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <span class="ai-provider">
                                    <i class="fas fa-microchip" aria-hidden="true"></i><?= h($api['provider_name'] ?? '') ?>
                                </span>
                            </td>

                            <td>
                                <code class="ai-model">
                                    <?= h($api['model_name'] ?? '') ?>
                                </code>
                            </td>

                            <td>
                                <?php if (!empty($api['api_endpoint'])): ?>
                                    <span class="ai-endpoint">
                                        <?= h($api['api_endpoint']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="cell-muted">-</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($api['api_key_last4'])): ?>
                                    <code class="ai-key">....<?= h($api['api_key_last4']) ?></code>
                                <?php else: ?>
                                    <span class="badge badge-secondary">No key</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="ai-settings">
                                    <span class="ai-chip"><i class="fas fa-thermometer-half" aria-hidden="true"></i> <?= h($api['temperature'] ?? '0.30') ?></span>
                                    <span class="ai-chip"><i class="fas fa-coins" aria-hidden="true"></i> <?= number_format((int)($api['max_tokens'] ?? 0)) ?> tok</span>
                                    <span class="ai-chip"><i class="fas fa-clock" aria-hidden="true"></i> <?= h($api['timeout_seconds'] ?? '60') ?>s</span>
                                </div>
                            </td>

                            <td>
                                <?php if ((int)($api['is_active'] ?? 0) === 1): ?>
                                    <span class="badge badge-success"><i class="fas fa-circle ai-dot" aria-hidden="true"></i> Active</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><i class="fas fa-circle ai-dot" aria-hidden="true"></i> Inactive</span>
                                <?php endif; ?>
                            </td>

                            <td class="ai-updated">
                                <?= h(substr(($api['updated_at'] ?: $api['created_at']) ?? '', 0, 16)) ?>
                            </td>

                            <td>
                                <div class="ai-actions">
                                    <button type="button" class="btn btn-secondary btn-sm js-edit-api" title="Edit" aria-label="Edit" data-api="<?= $apiJson ?>">
                                        <i class="fas fa-pencil" aria-hidden="true"></i>
                                    </button>

                                    <form class="ai-inline-form" method="post">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="default">
                                        <input type="hidden" name="ai_api_id" value="<?= (int)($api['ai_api_id'] ?? 0) ?>">
                                        <button class="btn btn-sm <?= (int)($api['is_default'] ?? 0) === 1 ? 'btn-warning' : 'btn-secondary' ?>" title="Set as default" aria-label="Set as default" type="submit">
                                            <i class="fas fa-star" aria-hidden="true"></i>
                                        </button>
                                    </form>

                                    <form class="ai-inline-form" method="post">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="ai_api_id" value="<?= (int)($api['ai_api_id'] ?? 0) ?>">
                                        <button class="btn btn-sm <?= (int)($api['is_active'] ?? 0) === 1 ? 'btn-green' : 'btn-secondary' ?>" title="<?= (int)($api['is_active'] ?? 0) === 1 ? 'Deactivate' : 'Activate' ?>" aria-label="<?= (int)($api['is_active'] ?? 0) === 1 ? 'Deactivate' : 'Activate' ?>" type="submit">
                                            <i class="fas fa-power-off" aria-hidden="true"></i>
                                        </button>
                                    </form>

                                    <form class="ai-inline-form" method="post" onsubmit="return confirm('Delete this AI API?\n\nIf it has review history it will be deactivated instead.');">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="ai_api_id" value="<?= (int)($api['ai_api_id'] ?? 0) ?>">
                                        <button class="btn btn-danger btn-sm" title="Delete" aria-label="Delete" type="submit">
                                            <i class="fas fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /.page-stack -->

<div class="modal" id="apiModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-card" role="document">
        <div class="modal-head">
            <h3 id="modalTitle">
                <i class="fas fa-robot ai-head-icon" aria-hidden="true"></i>
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

                <div class="form-grid ai-form-row">
                    <div class="form-group">
                        <label class="form-label required" for="fModel">Model Name</label>
                        <input class="form-control" type="text" name="model_name" id="fModel" required placeholder="e.g. gemini-1.5-flash">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="fEndpoint">API Endpoint</label>
                        <input class="form-control" type="url" name="api_endpoint" id="fEndpoint" placeholder="https://api.example.com/v1/...">
                    </div>
                </div>

                <div class="form-grid ai-form-row">
                    <div class="form-group full">
                        <label class="form-label" for="fApiKey">
                            API Key <span id="fKeyRequired" class="ai-req">*</span>
                        </label>
                        <input class="form-control" type="password" name="api_key" id="fApiKey" placeholder="Paste API key securely">
                        <div id="fKeyHint" class="ai-key-hint">
                            Current key ends: <code class="ai-key" id="fKeyLast4"></code> - leave blank to keep.
                        </div>
                    </div>
                </div>

                <div class="modal-section-label">Parameters</div>

                <div class="ai-form-3">
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

                <div class="ai-form-row">
                    <div class="ai-toggles">
                        <label class="ai-toggle">
                            <input type="checkbox" name="is_active" id="fActive" checked>
                            <i class="fas fa-circle-check" aria-hidden="true"></i> Active
                        </label>
                        <label class="ai-toggle">
                            <input type="checkbox" name="is_default" id="fDefault">
                            <i class="fas fa-star" aria-hidden="true"></i> Set as Default
                        </label>
                    </div>
                </div>

                <div class="form-group ai-form-row">
                    <label class="form-label" for="fNotes">Notes</label>
                    <textarea class="form-control" name="notes" id="fNotes" placeholder="Optional notes about this API configuration..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary js-close-api-modal">
                        <i class="fas fa-times" aria-hidden="true"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" id="fSubmitBtn">
                        <i class="fas fa-save" aria-hidden="true"></i> <span id="fSubmitLabel">Save API</span>
                    </button>
                </div>
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