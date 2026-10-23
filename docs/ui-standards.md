# IMS UI standards

Every internal page (anything that includes `legacy/includes/header.php`) is
styled by two global stylesheets, loaded in this order:

1. `public/css/style.css`: tokens, layout chrome, base forms and alerts
2. `public/css/reports.css`: the component library (header, cards, stat
   cards, buttons, badges, tables, modals, tabs)

Per-page CSS (`public/css/<page>.css`, or a `<style>` block) is only for
layout that is genuinely unique to that page. It must not restyle a global
component.

## Page skeleton

```html
<div class="page-stack">
  <div class="page-hero">
    <div>
      <h1><i class="fas fa-users" aria-hidden="true"></i> Page title</h1>
      <p>One-line description of what the page is for.</p>
    </div>
    <div class="hero-actions">
      <a href="..." class="btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</a>
      <button type="button" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> Add</button>
    </div>
  </div>

  <div class="stats-grid reports-stats"> stat cards </div>

  <div class="card">
    <div class="card-header"><h3><i class="fas fa-list" aria-hidden="true"></i> Section</h3> actions </div>
    <div class="card-body"> content </div>
  </div>
</div>
```

- One `page-hero` per page, first thing in the content. Inside it, `.btn-primary`
  renders solid white and every other `.btn` translucent: no extra classes needed.
- `page-stack` spaces sections 20px apart; don't add margins between sections.

## Components

| Need | Use |
|---|---|
| Statistic | `.stats-grid.reports-stats > .stat-card > .stat-icon.bg-{primary,green,blue,amber,red,purple,slate,teal} + .stat-info > .stat-label + .stat-value` |
| Section | `.card > .card-header + .card-body` (or `.panel > .panel-head + .panel-body`) |
| Table | `<div class="table-responsive"><table class="table">`; add `table-stack` + `data-label` for card rows on phones |
| Buttons | `.btn` + `.btn-primary` (main action), `.btn-secondary`, `.btn-danger`, `.btn-warning`, `.btn-info`, `.btn-soft`; `.btn-sm` in tables |
| Status | `.badge` + `.badge-{primary,success,info,warning,danger,secondary,purple,light,dark}` (aliases `available/assigned/pending/rejected/returned`) or `.status-pill.status-*` |
| Tab count | `<button class="tab-btn">Pending <span class="badge">3</span></button>` |
| Message | `.alert.alert-{success,info,warning,danger}`; add `.alert-stack` when the content is several lines; `data-persist` stops `main.js` adding a close button |
| Label/value facts | `<dl class="detail-grid"><div class="detail-item"><dt class="detail-label">…</dt><dd class="detail-value">…</dd></div></dl>` (`.is-wide` spans the row) |
| Progress | `<div class="meter"><div class="meter-fill tone-{green,amber,red,blue}" style="width:64%"></div></div>` |
| Pagination | `.pagination` with `a`, `a.active` or `span.current`, `.disabled`; `.pagination-info` for "Showing 1–20 of 84" |
| Modal | `.modal` (opened with `.show` or `.open`) `> .modal-content`; the backdrop is `--overlay` |
| Empty | `.empty-state` with an icon and one line of text |
| Filters | `.filters-bar` |
| Form help | `.form-hint` under a field, `.required-mark` after a label; `readonly`/`disabled` fields are greyed automatically |
| Muted helper text | `.cell-muted` |

## Colour and type

- Never hard-code colours. Use tokens: `--brand-50…700`, `--ink-0…900`,
  `--{green,blue,amber,red,slate,purple,teal}-{bg,fg,border}`, `--surface-*`, `--overlay`.
  Chart.js needs real colour values: read the token with
  `getComputedStyle(document.documentElement).getPropertyValue('--blue-fg')`.
  The legacy names (`--primary-color`, `--dark-color`, …) still work and map to these.
- Never set `font-family` on a page: DM Sans comes from `reports.css`.
- Radius `--radius-sm/md/lg/xl/2xl`, shadows `--shadow-sm/md/lg`, spacing `--space-1…8`.

## Exceptions

PDF and print templates rendered by Dompdf (`export-*-pdf.php`, `generate-*.php`,
`download_voucher.php`, the `?export=pdf` branches) and the public pages that
don't use `header.php` (embeds, registration forms) keep their own styles.
