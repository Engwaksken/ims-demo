<?php
/*
| "Embed & share" modal for job postings (jobs.php, recruitment.php).
| Builds copy-ready code for other websites:
|   - script widget  (jobs-embed.js -> iframe of jobs-embed.php)
|   - plain iframe   (jobs-embed.php)
|   - direct apply link (apply-job?job_id=)
| Applicants always apply on this site (apply-job opens in the top window),
| so their drafts, uploads and duplicate checks keep working.
|
| Expects: $hrEmbedJobs = [['job_id' => .., 'job_title' => .., 'status' => ..], ...]
|          $hrEmbedSelected (optional job_id to preselect)
*/

$hrEmbedBase = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
if ($hrEmbedBase === '') {
    $hrEmbedBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$hrEmbedOrigins = function_exists('ims_embed_allowed_origins') ? ims_embed_allowed_origins() : [];
$hrEmbedSelected = (int)($hrEmbedSelected ?? 0);
?>
<div id="hrEmbedModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="hrEmbedTitle" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="hrEmbedTitle"><i class="fas fa-code" aria-hidden="true"></i> Embed jobs on another website</h3>
            <button type="button" class="close" aria-label="Close" onclick="closeModal('hrEmbedModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="hr-form-grid">
                <div class="form-group">
                    <label for="hrEmbedJob">Show</label>
                    <select id="hrEmbedJob" class="form-control">
                        <option value="0">All open jobs</option>
                        <?php foreach ($hrEmbedJobs as $ej): ?>
                            <option value="<?= (int)$ej['job_id'] ?>"<?= (int)$ej['job_id'] === $hrEmbedSelected ? ' selected' : '' ?>>
                                <?= hr_h($ej['job_title']) ?><?= ($ej['status'] ?? '') !== 'Published' ? ' (' . hr_h($ej['status']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="hrEmbedTheme">Theme</label>
                    <select id="hrEmbedTheme" class="form-control">
                        <option value="light">Light</option>
                        <option value="dark">Dark</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="hrEmbedLimit">Maximum jobs</label>
                    <input id="hrEmbedLimit" type="number" min="0" max="50" value="0" class="form-control">
                    <p class="form-hint">0 = all open jobs.</p>
                </div>
                <div class="form-group">
                    <label for="hrEmbedAccent">Accent colour</label>
                    <input id="hrEmbedAccent" type="text" class="form-control" placeholder="#f97316 (optional)" maxlength="7">
                </div>
                <div class="form-group is-wide">
                    <label class="hr-check"><input type="checkbox" id="hrEmbedSearch" checked> Show search and filters</label>
                    <label class="hr-check"><input type="checkbox" id="hrEmbedHeader" checked> Show header</label>
                </div>
            </div>

            <div class="hr-copy-row">
                <label for="hrEmbedScript">1. Script widget (recommended)</label>
                <button type="button" class="btn btn-sm btn-secondary" data-copy="hrEmbedScript"><i class="fas fa-copy" aria-hidden="true"></i> Copy</button>
            </div>
            <textarea id="hrEmbedScript" class="form-control hr-code" readonly></textarea>

            <div class="hr-copy-row">
                <label for="hrEmbedIframe">2. Plain iframe</label>
                <button type="button" class="btn btn-sm btn-secondary" data-copy="hrEmbedIframe"><i class="fas fa-copy" aria-hidden="true"></i> Copy</button>
            </div>
            <textarea id="hrEmbedIframe" class="form-control hr-code" readonly></textarea>

            <div class="hr-copy-row" id="hrEmbedLinkRow" hidden>
                <label for="hrEmbedLink">3. Direct apply link (emails, social media, job boards)</label>
                <button type="button" class="btn btn-sm btn-secondary" data-copy="hrEmbedLink"><i class="fas fa-copy" aria-hidden="true"></i> Copy</button>
            </div>
            <input id="hrEmbedLink" class="form-control" readonly hidden>

            <div class="alert alert-info" style="margin-top:14px">
                <i class="fas fa-circle-info" aria-hidden="true"></i>
                Job seekers browse the jobs inside your website and click <strong>Apply</strong> to fill in the application form here, where they can save a draft and upload their CV.
                <?php if ($hrEmbedOrigins): ?>
                    The widget may be shown on: <strong><?= hr_h(implode(', ', $hrEmbedOrigins)) ?></strong>.
                <?php else: ?>
                    <br><strong>Before it will display:</strong> ask your system administrator to add the website's address (e.g. https://www.example.org) to <code>EMBED_ALLOWED_ORIGINS</code> in the server's .env file. Until then browsers block the widget; the direct apply link works everywhere.
                <?php endif; ?>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('hrEmbedModal')">Close</button>
        </div>
    </div>
</div>
<script>
(function () {
    const base = <?= json_encode($hrEmbedBase, JSON_UNESCAPED_SLASHES) ?>;
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

    function build() {
        const job = parseInt($('hrEmbedJob').value, 10) || 0;
        const theme = $('hrEmbedTheme').value;
        const limit = Math.max(0, parseInt($('hrEmbedLimit').value, 10) || 0);
        const accent = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test($('hrEmbedAccent').value.trim()) ? $('hrEmbedAccent').value.trim() : '';
        const search = $('hrEmbedSearch').checked;
        const header = $('hrEmbedHeader').checked;
        const container = job ? 'ims-job-' + job : 'ims-jobs';

        const cfg = ['  containerId: ' + JSON.stringify(container), '  theme: ' + JSON.stringify(theme), '  applyBase: ' + JSON.stringify(base)];
        if (job) cfg.push('  job: ' + job);
        if (limit) cfg.push('  limit: ' + limit);
        if (accent) cfg.push('  accent: ' + JSON.stringify(accent));
        if (!search) cfg.push('  showSearch: false', '  showFilter: false');
        if (!header) cfg.push('  showHeader: false');
        $('hrEmbedScript').value =
            '<div id="' + container + '"></div>\n' +
            '<script>\nwindow.IMSJobsConfig = {\n' + cfg.join(',\n') + '\n};\n<\/script>\n' +
            '<script src="' + esc(base) + '/jobs-embed.js" async><\/script>';

        const q = new URLSearchParams({ theme: theme, apply_base: base, show_search: search ? 1 : 0, show_filter: search ? 1 : 0, show_header: header ? 1 : 0 });
        if (job) q.set('job', job);
        if (limit) q.set('limit', limit);
        if (accent) q.set('accent', accent);
        $('hrEmbedIframe').value = '<iframe src="' + esc(base + '/jobs-embed.php?' + q.toString()) + '" title="Job opportunities" loading="lazy" style="width:100%;min-height:' + (job ? 360 : 600) + 'px;border:0;"></iframe>';

        const link = job ? base + '/apply-job?job_id=' + job : '';
        $('hrEmbedLink').value = link;
        $('hrEmbedLink').hidden = !job;
        $('hrEmbedLinkRow').hidden = !job;
    }

    ['hrEmbedJob', 'hrEmbedTheme', 'hrEmbedLimit', 'hrEmbedAccent', 'hrEmbedSearch', 'hrEmbedHeader'].forEach((id) => {
        $(id).addEventListener('input', build);
        $(id).addEventListener('change', build);
    });
    document.querySelectorAll('#hrEmbedModal [data-copy]').forEach((btn) => btn.addEventListener('click', async () => {
        const field = $(btn.dataset.copy);
        try { await navigator.clipboard.writeText(field.value); } catch (e) { field.select(); document.execCommand('copy'); }
        const old = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i> Copied';
        setTimeout(() => { btn.innerHTML = old; }, 1500);
    }));

    window.hrOpenEmbed = function (jobId) {
        if (jobId !== undefined) $('hrEmbedJob').value = String(jobId);
        build();
        openModal('hrEmbedModal');
    };
    build();
})();
</script>
