<?php
/*
 * Documents library: create / upload / edit / share modals.
 * Moved unchanged from documents.php when the page became the
 * SharePoint-style library; included by documents.php, which provides
 * $currentFolder, $currentFolderId, $folderTypes, $projects, $programs,
 * $users and $autoOpenUpload.
 */
?>
<style>
.doc-toolbar{
    display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
    margin-bottom:16px
}
.doc-toolbar-left,.doc-toolbar-right{
    display:flex;align-items:center;gap:8px;flex-wrap:wrap
}
/* Breadcrumbs, explorer, items, context menu, drag-and-drop: css/documents.css */

.doc-repo-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px
}
.doc-list-card{
    min-width:0;
    display:flex;align-items:center;gap:12px;
    padding:13px 14px;
    border:1px solid var(--ink-100);
    border-radius:12px;
    background:var(--surface-card);
    box-shadow:0 2px 8px rgba(15,23,42,.04);
    transition:.18s ease
}
.doc-list-card:hover{
    transform:translateY(-1px);
    border-color:var(--brand-200);
    box-shadow:0 6px 16px rgba(15,23,42,.07)
}
.doc-folder-card{cursor:pointer}
.doc-card-icon{
    width:44px;height:44px;display:grid;place-items:center;
    flex-shrink:0;border-radius:10px;font-size:19px
}
.doc-folder-icon{background:var(--brand-50);color:var(--brand-600)}
.doc-card-main{min-width:0;flex:1}
.doc-card-name{
    color:var(--ink-700);font-size:12.5px;font-weight:800;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis
}
.doc-card-meta{
    margin-top:4px;color:var(--ink-300);font-size:10.5px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis
}
.doc-card-actions{
    display:flex;align-items:center;gap:5px;flex-shrink:0
}
.doc-perm-badges{
    display:flex;gap:4px;flex-wrap:wrap;margin-top:6px
}
.doc-perm-badge{
    display:inline-flex;align-items:center;gap:4px;
    padding:3px 7px;border-radius:999px;
    background:var(--ink-50);color:var(--ink-400);
    font-size:9px;font-weight:800
}
.doc-folder-count{
    display:inline-flex;align-items:center;gap:4px;
    margin-top:5px;color:var(--ink-300);font-size:10px;font-weight:700
}
.doc-tabs{
    display:flex;gap:0;overflow-x:auto;margin-bottom:16px;border-bottom:2px solid var(--ink-100)
}
.doc-tab-btn{
    border:0;background:transparent;padding:10px 14px;margin-bottom:-2px;
    border-bottom:2px solid transparent;font-size:12px;font-weight:800;
    color:var(--ink-300);cursor:pointer;white-space:nowrap
}
.doc-tab-btn.active{color:var(--brand-600);border-bottom-color:var(--brand-500)}
.doc-tab-panel{display:none}.doc-tab-panel.active{display:block}
.doc-rights-grid{
    display:grid;grid-template-columns:minmax(160px,1fr) repeat(3,90px);
    gap:8px;align-items:center
}
.doc-rights-head{
    font-size:10px;font-weight:800;color:var(--ink-300);text-transform:uppercase
}
.doc-rights-row{display:contents}
.doc-rights-name{font-size:12px;font-weight:700;color:var(--ink-600)}
.doc-check{text-align:center}
.doc-check input{width:17px;height:17px}
.doc-type-other{display:none;margin-top:8px}
.doc-share-note{
    margin-top:10px;padding:10px 12px;border:1px solid var(--blue-border);
    border-radius:8px;background:var(--blue-bg);color:var(--blue-fg);
    font-size:11px;line-height:1.5
}
@media(max-width:1100px){
    .doc-repo-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:700px){
    .doc-repo-grid{grid-template-columns:1fr}
    .doc-rights-grid{grid-template-columns:minmax(120px,1fr) repeat(3,65px)}
}

.doc-confirm-modal .modal-card {
    max-width: 470px;
}

.doc-confirm-icon {
    width: 58px;
    height: 58px;

    display: grid;
    place-items: center;

    margin: 0 auto 12px;

    border-radius: 50%;

    background: var(--red-bg);
    color: var(--red-fg);

    font-size: 24px;
}

.doc-confirm-title {
    margin-bottom: 5px;

    color: var(--ink-700);

    font-size: 16px;
    font-weight: 800;

    text-align: center;
}

.doc-confirm-message {
    color: var(--ink-300);

    font-size: 12px;
    line-height: 1.6;

    text-align: center;
}

.doc-edit-summary {
    display: flex;
    align-items: center;
    gap: 10px;

    margin-bottom: 14px;
    padding: 11px 12px;

    border: 1px solid var(--ink-100);
    border-radius: 9px;

    background: var(--ink-50);
}

.doc-edit-summary i {
    color: var(--brand-500);

    font-size: 20px;
}

.doc-edit-summary strong {
    display: block;

    color: var(--ink-700);

    font-size: 12px;
}

.doc-edit-summary small {
    color: var(--ink-300);

    font-size: 10.5px;
}


/* ==========================================================================
   DRIVE / ONEDRIVE STYLE NEW MENU
   ========================================================================== */

.doc-new-wrap {
    position: relative;
}

.doc-new-menu {
    display: none;

    position: absolute;
    top: calc(100% + 7px);
    right: 0;

    z-index: 1000;

    width: 220px;

    overflow: hidden;

    border: 1px solid var(--ink-100);
    border-radius: 11px;

    background: var(--surface-card);

    box-shadow: 0 15px 35px rgba(15,23,42,.14);
}

.doc-new-menu.open {
    display: block;
}

.doc-new-menu-head {
    padding: 9px 12px;

    border-bottom: 1px solid var(--ink-50);

    color: var(--ink-300);

    font-size: 9.5px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.doc-new-action {
    width: 100%;

    display: flex;
    align-items: center;
    gap: 10px;

    padding: 11px 12px;

    border: 0;

    background: transparent;
    color: var(--ink-600);

    font-family: inherit;
    font-size: 12px;
    font-weight: 700;

    text-align: left;

    cursor: pointer;
}

.doc-new-action:hover {
    background: var(--ink-50);
    color: var(--brand-600);
}

.doc-new-action-icon {
    width: 30px;
    height: 30px;

    display: grid;
    place-items: center;

    border-radius: 8px;

    background: var(--brand-50);
    color: var(--brand-500);

    font-size: 13px;
}

.doc-library-section {
    margin-bottom: 22px;
}

.doc-library-head {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 10px;

    margin-bottom: 10px;
}

.doc-library-title {
    display: flex;
    align-items: center;
    gap: 8px;

    margin: 0;

    color: var(--ink-600);

    font-size: 12px;
    font-weight: 800;
}

.doc-library-title i {
    color: var(--brand-500);
}

.doc-library-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-width: 26px;
    height: 24px;

    padding: 0 7px;

    border-radius: 999px;

    background: var(--ink-50);
    color: var(--ink-300);

    font-size: 10px;
    font-weight: 800;
}


.doc-section-empty {
    padding: 24px 16px;

    border: 1px dashed var(--ink-100);
    border-radius: 10px;

    color: var(--ink-300);

    font-size: 11px;
    text-align: center;
}


/* ==========================================================================
   ADD NEW CHOOSER MODAL
   ========================================================================== */

.doc-add-new-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.doc-add-new-option {
    width: 100%;

    display: flex;
    align-items: center;
    gap: 12px;

    padding: 16px;

    border: 1px solid var(--ink-100);
    border-radius: 11px;

    background: var(--surface-card);
    color: var(--ink-600);

    font-family: inherit;
    text-align: left;

    cursor: pointer;

    transition:
        border-color .18s ease,
        background .18s ease,
        transform .18s ease,
        box-shadow .18s ease;
}

.doc-add-new-option:hover {
    transform: translateY(-1px);

    border-color: var(--brand-300);

    background: var(--brand-50);

    box-shadow: 0 6px 14px rgba(15,23,42,.07);
}

.doc-add-new-option-icon {
    width: 46px;
    height: 46px;

    display: grid;
    place-items: center;

    flex-shrink: 0;

    border-radius: 10px;

    background: var(--brand-50);
    color: var(--brand-600);

    font-size: 18px;
}

.doc-add-new-option.file .doc-add-new-option-icon {
    background: var(--blue-bg);
    color: var(--blue-fg);
}

.doc-add-new-option-title {
    color: var(--ink-700);

    font-size: 12.5px;
    font-weight: 800;
}

.doc-add-new-option-text {
    margin-top: 3px;

    color: var(--ink-300);

    font-size: 10.5px;
    line-height: 1.45;
}

.doc-add-location {
    display: flex;
    align-items: flex-start;
    gap: 9px;

    margin-bottom: 14px;
    padding: 10px 12px;

    border: 1px solid var(--brand-200);
    border-left: 4px solid var(--brand-500);
    border-radius: 8px;

    background: var(--brand-50);
    color: var(--brand-700);

    font-size: 11px;
    line-height: 1.45;
}

.doc-add-location i {
    margin-top: 2px;
    color: var(--brand-600);
}

@media (max-width: 620px) {
    .doc-add-new-grid {
        grid-template-columns: 1fr;
    }
}


/* Library grid/list + context menu styles moved to css/documents.css */

.doc-upload-simple {
    padding: 12px;
    border: 1px dashed var(--ink-100);
    border-radius: 9px;
    background: var(--ink-50);
}

.doc-upload-choice-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 10px;
}

.doc-upload-choice {
    min-height: 115px;
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 14px;
    border: 1px solid var(--ink-100);
    border-radius: 10px;
    background: var(--surface-card);
    cursor: pointer;
}

.doc-upload-choice:hover {
    border-color: var(--brand-300);
    background: var(--brand-50);
}

.doc-upload-choice i {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border-radius: 9px;
    background: var(--blue-bg);
    color: var(--blue-fg);
    font-size: 18px;
}

.doc-upload-choice.folder i {
    background: var(--brand-50);
    color: var(--brand-600);
}

.doc-hidden-file-input {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    opacity: 0;
    pointer-events: none;
}

.doc-folder-upload-summary {
    margin-top: 8px;
    color: var(--ink-300);
    font-size: 10.5px;
}

@media (max-width: 620px) {
    .doc-upload-choice-grid {
        grid-template-columns: 1fr;
    }
}

.doc-move-option {
    white-space: pre;
}


/* Drag-and-drop, drop overlay and upload toast styles: css/documents.css */


/* ==========================================================================
   DOCUMENT MODAL DISPLAY FIX
   Keeps modal header/body/footer inside one centred card.
   ========================================================================== */

#addNewModal,
#createFolderModal,
#editFolderModal,
#deleteFolderModal,
#uploadModal,
#uploadFolderModal,
#editFileModal,
#deleteDocumentModal,
#permissionsModal,
#moveModal {
    position: fixed !important;
    inset: 0 !important;

    z-index: 999999 !important;

    width: 100vw !important;
    height: 100vh !important;

    padding: 24px !important;

    overflow-y: auto !important;

    background: rgba(15, 23, 42, .58) !important;

    box-sizing: border-box !important;
}

#addNewModal.open,
#createFolderModal.open,
#editFolderModal.open,
#deleteFolderModal.open,
#uploadModal.open,
#uploadFolderModal.open,
#editFileModal.open,
#deleteDocumentModal.open,
#permissionsModal.open,
#moveModal.open {
    display: flex !important;

    align-items: center !important;
    justify-content: center !important;
}

#addNewModal .modal-card,
#createFolderModal .modal-card,
#editFolderModal .modal-card,
#deleteFolderModal .modal-card,
#uploadModal .modal-card,
#uploadFolderModal .modal-card,
#editFileModal .modal-card,
#deleteDocumentModal .modal-card,
#permissionsModal .modal-card,
#moveModal .modal-card {
    position: relative !important;

    width: min(100%, 760px) !important;
    max-height: calc(100vh - 48px) !important;

    margin: auto !important;

    display: flex !important;
    flex-direction: column !important;

    overflow: hidden !important;

    border-radius: 16px !important;

    background: var(--surface-card) !important;

    box-shadow: 0 24px 70px rgba(15, 23, 42, .28) !important;

    transform: none !important;
}

#addNewModal .modal-card {
    width: min(100%, 720px) !important;
}

#createFolderModal .modal-card {
    width: min(100%, 680px) !important;
}

#uploadModal .modal-card,
#uploadFolderModal .modal-card,
#editFileModal .modal-card {
    width: min(100%, 720px) !important;
}

#permissionsModal .modal-card {
    width: min(100%, 820px) !important;
}

#deleteDocumentModal .modal-card {
    width: min(100%, 470px) !important;
}

#moveModal .modal-card {
    width: min(100%, 560px) !important;
}

#addNewModal .modal-head,
#createFolderModal .modal-head,
#editFolderModal .modal-head,
#deleteFolderModal .modal-head,
#uploadModal .modal-head,
#uploadFolderModal .modal-head,
#editFileModal .modal-head,
#deleteDocumentModal .modal-head,
#permissionsModal .modal-head,
#moveModal .modal-head {
    flex: 0 0 auto !important;

    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;

    gap: 10px !important;

    padding: 17px 20px !important;

    border-bottom: 1px solid var(--ink-100) !important;

    background: var(--surface-card) !important;
}

#addNewModal .modal-body,
#createFolderModal .modal-body,
#editFolderModal .modal-body,
#deleteFolderModal .modal-body,
#uploadModal .modal-body,
#uploadFolderModal .modal-body,
#editFileModal .modal-body,
#deleteDocumentModal .modal-body,
#permissionsModal .modal-body,
#moveModal .modal-body {
    flex: 1 1 auto !important;

    min-height: 0 !important;

    padding: 20px !important;

    overflow-y: auto !important;

    background: var(--surface-card) !important;
}

#addNewModal .modal-foot,
#createFolderModal .modal-foot,
#editFolderModal .modal-foot,
#deleteFolderModal .modal-foot,
#uploadModal .modal-foot,
#uploadFolderModal .modal-foot,
#editFileModal .modal-foot,
#deleteDocumentModal .modal-foot,
#permissionsModal .modal-foot,
#moveModal .modal-foot {
    flex: 0 0 auto !important;

    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;

    gap: 8px !important;

    padding: 14px 20px !important;

    border-top: 1px solid var(--ink-100) !important;

    background: var(--ink-50) !important;
}

#addNewModal .modal-head h3,
#createFolderModal .modal-head h3,
#editFolderModal .modal-head h3,
#deleteFolderModal .modal-head h3,
#uploadModal .modal-head h3,
#uploadFolderModal .modal-head h3,
#editFileModal .modal-head h3,
#deleteDocumentModal .modal-head h3,
#permissionsModal .modal-head h3,
#moveModal .modal-head h3 {
    margin: 0 !important;

    display: flex !important;
    align-items: center !important;

    gap: 8px !important;

    color: var(--ink-700) !important;

    font-size: 17px !important;
    font-weight: 800 !important;
}

#addNewModal .modal-close,
#createFolderModal .modal-close,
#editFolderModal .modal-close,
#deleteFolderModal .modal-close,
#uploadModal .modal-close,
#uploadFolderModal .modal-close,
#editFileModal .modal-close,
#deleteDocumentModal .modal-close,
#permissionsModal .modal-close,
#moveModal .modal-close {
    width: 38px !important;
    height: 38px !important;

    display: grid !important;
    place-items: center !important;

    flex-shrink: 0 !important;

    padding: 0 !important;

    border: 0 !important;
    border-radius: 9px !important;

    background: var(--ink-50) !important;
    color: var(--ink-300) !important;

    font-size: 20px !important;
    line-height: 1 !important;

    cursor: pointer !important;
}

#addNewModal .modal-close:hover,
#createFolderModal .modal-close:hover,
#editFolderModal .modal-close:hover,
#deleteFolderModal .modal-close:hover,
#uploadModal .modal-close:hover,
#uploadFolderModal .modal-close:hover,
#editFileModal .modal-close:hover,
#deleteDocumentModal .modal-close:hover,
#permissionsModal .modal-close:hover,
#moveModal .modal-close:hover {
    background: var(--ink-100) !important;
    color: var(--ink-600) !important;
}

#addNewModal .doc-add-new-grid {
    align-items: stretch !important;
}

#addNewModal .doc-add-new-option {
    min-height: 105px !important;
}

@media (max-width: 700px) {
    #addNewModal,
    #createFolderModal,
    #uploadModal,
    #uploadFolderModal,
    #editFileModal,
    #deleteDocumentModal,
    #permissionsModal,
    #moveModal {
        padding: 12px !important;
    }

    #addNewModal.open,
    #createFolderModal.open,
    #uploadModal.open,
    #uploadFolderModal.open,
    #editFileModal.open,
    #deleteDocumentModal.open,
    #permissionsModal.open,
    #moveModal.open {
        align-items: flex-start !important;
    }

    #addNewModal .modal-card,
    #createFolderModal .modal-card,
    #uploadModal .modal-card,
    #uploadFolderModal .modal-card,
    #editFileModal .modal-card,
    #deleteDocumentModal .modal-card,
    #permissionsModal .modal-card,
    #moveModal .modal-card {
        max-height: calc(100vh - 24px) !important;

        border-radius: 12px !important;
    }

    #addNewModal .modal-head,
    #createFolderModal .modal-head,
    #uploadModal .modal-head,
    #uploadFolderModal .modal-head,
    #editFileModal .modal-head,
    #deleteDocumentModal .modal-head,
    #permissionsModal .modal-head,
    #moveModal .modal-head {
        padding: 14px 15px !important;
    }

    #addNewModal .modal-body,
    #createFolderModal .modal-body,
    #uploadModal .modal-body,
    #uploadFolderModal .modal-body,
    #editFileModal .modal-body,
    #deleteDocumentModal .modal-body,
    #permissionsModal .modal-body,
    #moveModal .modal-body {
        padding: 15px !important;
    }

    #addNewModal .modal-foot,
    #createFolderModal .modal-foot,
    #uploadModal .modal-foot,
    #uploadFolderModal .modal-foot,
    #editFileModal .modal-foot,
    #deleteDocumentModal .modal-foot,
    #permissionsModal .modal-foot,
    #moveModal .modal-foot {
        padding: 12px 15px !important;

        flex-wrap: wrap !important;
    }
}


/* Upload modal form containment */
#uploadModal .modal-card > form {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow: hidden !important;
}

#uploadModal .modal-card > form > .modal-body {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow-y: auto !important;
}

#uploadModal .modal-card > form > .modal-foot {
    flex: 0 0 auto !important;
    width: 100% !important;
    box-sizing: border-box !important;
    position: static !important;
    left: auto !important;
    right: auto !important;
    bottom: auto !important;
}

</style>

<!-- ADD NEW -->
<div id="addNewModal" class="modal">
    <div class="modal-card">

        <div class="modal-head">
            <h3>
                <i class="fas fa-plus-circle" style="color:var(--brand-500);"></i>
                Add New
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('addNewModal')"
                aria-label="Close"
            >
                &times;
            </button>
        </div>

        <div class="modal-body">

            <div class="doc-add-location">
                <i class="fas fa-folder-open"></i>

                <div>
                    <?php if ($currentFolder): ?>
                        New items will be added inside
                        <strong><?= h($currentFolder['folder_name']) ?></strong>.
                    <?php else: ?>
                        New items will be added to the main Documents library.
                    <?php endif; ?>
                </div>
            </div>

            <div class="doc-add-new-grid">

                <button
                    type="button"
                    class="doc-add-new-option"
                    onclick="docChooseNewFolder()"
                >
                    <span class="doc-add-new-option-icon">
                        <i class="fas fa-folder-plus"></i>
                    </span>

                    <span>
                        <span class="doc-add-new-option-title">
                            <?= $currentFolder ? 'New Subfolder' : 'New Folder' ?>
                        </span>

                        <span class="doc-add-new-option-text">
                            Create a folder in the current location.
                        </span>
                    </span>
                </button>

                <button
                    type="button"
                    class="doc-add-new-option file"
                    onclick="docChooseUploadFile()"
                >
                    <span class="doc-add-new-option-icon">
                        <i class="fas fa-file-arrow-up"></i>
                    </span>

                    <span>
                        <span class="doc-add-new-option-title">
                            Upload Files
                        </span>

                        <span class="doc-add-new-option-text">
                            Select one or many files.
                        </span>
                    </span>
                </button>

                <?php if ($currentFolder): ?>
                    <button
                        type="button"
                        class="doc-add-new-option"
                        onclick="docChooseUploadFolder()"
                    >
                        <span class="doc-add-new-option-icon">
                            <i class="fas fa-folder-tree"></i>
                        </span>

                        <span>
                            <span class="doc-add-new-option-title">
                                Upload Folder
                            </span>

                            <span class="doc-add-new-option-text">
                                Upload a folder with all its files and subfolders.
                            </span>
                        </span>
                    </button>
                <?php endif; ?>

            </div>

        </div>

        <div class="modal-foot">
            <button
                type="button"
                class="btn btn-secondary"
                onclick="closeModal('addNewModal')"
            >
                Cancel
            </button>
        </div>

    </div>
</div>


<!-- CREATE FOLDER -->
<div id="createFolderModal" class="modal">
    <div class="modal-card" style="max-width:620px;">
        <div class="modal-head">
            <h3><i class="fas fa-folder-plus" style="color:var(--brand-500);"></i>
            <?= $currentFolder ? 'Create Subfolder' : 'Create Folder' ?></h3>
            <button type="button" class="modal-close" onclick="closeModal('createFolderModal')">&times;</button>
        </div>

        <form method="POST" action="includes/documents-handler.php">
            <input type="hidden" name="action" value="create_folder">
            <input type="hidden" name="parent_folder_id" value="<?= $currentFolderId ?>">

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Document Type</label>
                        <select
                            id="folder_document_type"
                            name="document_type"
                            class="form-control"
                            required
                            onchange="docToggleOtherFolderType()"
                        >
                            <option value="">Select document type...</option>
                            <?php foreach ($folderTypes as $type): ?>
                                <option value="<?= h($type) ?>"><?= h($type) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <div id="folderOtherTypeWrap" class="doc-type-other">
                            <label class="form-label" style="margin-top:8px;">Other Folder Name</label>
                            <input
                                type="text"
                                id="folder_other_name"
                                name="other_type_name"
                                class="form-control"
                                placeholder="e.g. Contracts, Training Materials, MoUs"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Folder Name</label>
                        <input
                            type="text"
                            name="folder_name"
                            class="form-control"
                            placeholder="Leave blank to use document type"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Project</label>
                        <select name="project_id" class="form-control">
                            <option value="">General / None</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?= (int)$project['project_id'] ?>">
                                    <?= h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Program</label>
                        <select name="program_id" class="form-control">
                            <option value="">General / None</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int)$program['id'] ?>">
                                    <?= h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createFolderModal')">
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-folder-plus"></i> Create Folder
                </button>
            </div>
        </form>
    </div>
</div>


<!-- UPLOAD FILE -->
<div id="uploadModal" class="modal">
    <div class="modal-card">

        <div class="modal-head">
            <h3>
                <i class="fas fa-upload" style="color:var(--brand-500);"></i>
                Upload Files
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('uploadModal')"
                aria-label="Close"
            >
                &times;
            </button>
        </div>

        <form
            method="POST"
            action="includes/documents-handler.php"
            enctype="multipart/form-data"
            style="
                display:flex;
                flex-direction:column;
                min-height:0;
                flex:1 1 auto;
            "
        >
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="folder_id" value="<?= $currentFolderId ?>">

            <div class="modal-body">

                <?php if ($autoOpenUpload): ?>
                    <div class="alert alert-success" style="margin-bottom:14px;">
                        <i class="fas fa-circle-check" style="margin-top:1px;"></i>

                        <div>
                            <strong>Folder created successfully.</strong>
                            Add one or more files to this folder now.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($currentFolder): ?>

                    <div class="form-group full">
                        <label class="form-label required">
                            Select Files
                        </label>

                        <input
                            type="file"
                            name="documents[]"
                            id="documentsUploadInput"
                            class="form-control"
                            multiple
                            required
                            onchange="docUpdateSelectedFiles(this)"
                        >

                        <div
                            id="selectedFilesSummary"
                            style="
                                margin-top:7px;
                                color:var(--ink-300);
                                font-size:11px;
                                line-height:1.5;
                            "
                        >
                            Select one or more files. Folder metadata is inherited automatically.
                        </div>
                    </div>

                    <input type="hidden" name="document_name" value="">
                    <input type="hidden" name="document_type" value="Inherited">
                    <input
                        type="hidden"
                        name="project_id"
                        value="<?= (int)($currentFolder['project_id'] ?? 0) ?>"
                    >
                    <input
                        type="hidden"
                        name="program_id"
                        value="<?= (int)($currentFolder['program_id'] ?? 0) ?>"
                    >
                    <input type="hidden" name="description" value="">
                    <input type="hidden" name="tags" value="">

                    <div class="doc-share-note">
                        <i class="fas fa-circle-info"></i>
                        Files inherit the current folder metadata and permissions automatically.
                    </div>

                <?php else: ?>

                    <div class="form-grid">

                        <div class="form-group full">
                            <label class="form-label">
                                Document Name
                            </label>

                            <input
                                type="text"
                                name="document_name"
                                class="form-control"
                                placeholder="Optional for multiple files"
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label required">
                                Document Type
                            </label>

                            <select
                                id="upload_document_type"
                                name="document_type"
                                class="form-control"
                                required
                                onchange="docToggleUploadOtherType()"
                            >
                                <option value="">Select type...</option>

                                <?php foreach ($folderTypes as $type): ?>
                                    <option value="<?= h($type) ?>">
                                        <?= h($type) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div
                                id="uploadOtherTypeWrap"
                                class="doc-type-other"
                            >
                                <label
                                    class="form-label"
                                    style="margin-top:8px;"
                                >
                                    Other Type Name
                                </label>

                                <input
                                    type="text"
                                    name="other_type_name"
                                    id="upload_other_name"
                                    class="form-control"
                                    placeholder="e.g. Contract"
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">
                                Select Files
                            </label>

                            <input
                                type="file"
                                name="documents[]"
                                id="documentsUploadInput"
                                class="form-control"
                                multiple
                                required
                                onchange="docUpdateSelectedFiles(this)"
                            >

                            <div
                                id="selectedFilesSummary"
                                style="
                                    margin-top:7px;
                                    color:var(--ink-300);
                                    font-size:11px;
                                    line-height:1.5;
                                "
                            >
                                You can select multiple files at once.
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                Project
                            </label>

                            <select
                                name="project_id"
                                class="form-control"
                            >
                                <option value="">None</option>

                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= (int)$project['project_id'] ?>">
                                        <?= h(
                                            ($project['project_code'] ?? '')
                                            . ' - '
                                            . ($project['project_name'] ?? '')
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                Program
                            </label>

                            <select
                                name="program_id"
                                class="form-control"
                            >
                                <option value="">None</option>

                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= (int)$program['id'] ?>">
                                        <?= h(
                                            ($program['program_code'] ?? '')
                                            . ' - '
                                            . ($program['program_name'] ?? '')
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">
                                Description
                            </label>

                            <textarea
                                name="description"
                                class="form-control"
                                rows="3"
                            ></textarea>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">
                                Tags
                            </label>

                            <input
                                type="text"
                                name="tags"
                                class="form-control"
                                placeholder="comma-separated tags"
                            >
                        </div>

                    </div>

                <?php endif; ?>

            </div>

            <div class="modal-foot">
                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeModal('uploadModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-success"
                >
                    <i class="fas fa-upload"></i>
                    Upload Files
                </button>
            </div>

        </form>
    </div>
</div>



<!-- EDIT FOLDER -->
<div id="editFolderModal" class="modal">
    <div class="modal-card" style="max-width:680px;">
        <div class="modal-head">
            <h3>
                <i class="fas fa-folder-open" style="color:var(--brand-600);"></i>
                Edit Folder
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('editFolderModal')"
                aria-label="Close"
            >
                &times;
            </button>
        </div>

        <form
            method="POST"
            action="includes/documents-handler.php"
            style="
                display:flex;
                flex-direction:column;
                min-height:0;
                flex:1 1 auto;
            "
        >
            <input type="hidden" name="action" value="edit_folder">
            <input type="hidden" name="folder_id" id="editFolderId">
            <input type="hidden" name="parent_folder_id" id="editFolderParentId">

            <div class="modal-body">
                <div class="doc-edit-summary">
                    <i class="fas fa-folder-open"></i>

                    <div>
                        <strong id="editFolderSummaryName">Folder</strong>
                        <small>Rename or update this folder. Existing files remain inside it.</small>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label required">Folder Name</label>

                        <input
                            type="text"
                            name="folder_name"
                            id="editFolderName"
                            class="form-control"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Folder Type</label>

                        <select
                            name="document_type"
                            id="editFolderType"
                            class="form-control"
                            required
                            onchange="docToggleEditFolderOtherType()"
                        >
                            <option value="">Select type...</option>

                            <?php foreach ($folderTypes as $type): ?>
                                <option value="<?= h($type) ?>">
                                    <?= h($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div
                            id="editFolderOtherTypeWrap"
                            class="doc-type-other"
                        >
                            <label class="form-label" style="margin-top:8px;">
                                Other Type Name
                            </label>

                            <input
                                type="text"
                                name="other_type_name"
                                id="editFolderOtherTypeName"
                                class="form-control"
                                placeholder="e.g. Contracts"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Project</label>

                        <select
                            name="project_id"
                            id="editFolderProject"
                            class="form-control"
                        >
                            <option value="">None</option>

                            <?php foreach ($projects as $project): ?>
                                <option value="<?= (int)$project['project_id'] ?>">
                                    <?= h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Program</label>

                        <select
                            name="program_id"
                            id="editFolderProgram"
                            class="form-control"
                        >
                            <option value="">None</option>

                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int)$program['id'] ?>">
                                    <?= h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Description</label>

                        <textarea
                            name="description"
                            id="editFolderDescription"
                            class="form-control"
                            rows="3"
                        ></textarea>
                    </div>
                </div>

                <div class="doc-share-note">
                    <i class="fas fa-user-shield"></i>
                    Folder owners and users granted Edit permission can modify this folder.
                </div>
            </div>

            <div class="modal-foot">
                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeModal('editFolderModal')"
                >
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>


<!-- EDIT FILE -->
<div id="editFileModal" class="modal">
    <div class="modal-card" style="max-width:680px;">
        <div class="modal-head">
            <h3>
                <i class="fas fa-pen-to-square" style="color:var(--brand-500);"></i>
                Edit Document
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('editFileModal')"
                aria-label="Close"
            >
                &times;
            </button>
        </div>

        <form method="POST" action="includes/documents-handler.php">
            <input type="hidden" name="action" value="edit_document">
            <input type="hidden" name="document_id" id="editDocumentId">
            <input type="hidden" name="folder_id" id="editDocumentFolderId">

            <div class="modal-body">
                <div class="doc-edit-summary">
                    <i class="fas fa-file-pen"></i>

                    <div>
                        <strong id="editDocumentSummaryName">Document</strong>
                        <small>Edit document details without replacing the uploaded file.</small>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label required">Document Name</label>

                        <input
                            type="text"
                            name="document_name"
                            id="editDocumentName"
                            class="form-control"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Document Type</label>

                        <select
                            name="document_type"
                            id="editDocumentType"
                            class="form-control"
                            required
                            onchange="docToggleEditOtherType()"
                        >
                            <option value="">Select type...</option>

                            <?php foreach ($folderTypes as $type): ?>
                                <option value="<?= h($type) ?>">
                                    <?= h($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div id="editOtherTypeWrap" class="doc-type-other">
                            <label class="form-label" style="margin-top:8px;">
                                Other Type Name
                            </label>

                            <input
                                type="text"
                                name="other_type_name"
                                id="editOtherTypeName"
                                class="form-control"
                                placeholder="e.g. Contract"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Project</label>

                        <select
                            name="project_id"
                            id="editDocumentProject"
                            class="form-control"
                        >
                            <option value="">None</option>

                            <?php foreach ($projects as $project): ?>
                                <option value="<?= (int)$project['project_id'] ?>">
                                    <?= h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Program</label>

                        <select
                            name="program_id"
                            id="editDocumentProgram"
                            class="form-control"
                        >
                            <option value="">None</option>

                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int)$program['id'] ?>">
                                    <?= h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Description</label>

                        <textarea
                            name="description"
                            id="editDocumentDescription"
                            class="form-control"
                            rows="3"
                        ></textarea>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Tags</label>

                        <input
                            type="text"
                            name="tags"
                            id="editDocumentTags"
                            class="form-control"
                            placeholder="comma-separated tags"
                        >
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeModal('editFileModal')"
                >
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>


<!-- UPLOAD FOLDER -->
<div id="uploadFolderModal" class="modal">
    <div class="modal-card" style="max-width:620px;">
        <div class="modal-head">
            <h3>
                <i class="fas fa-folder-tree" style="color:var(--brand-600);"></i>
                Upload Folder
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('uploadFolderModal')"
            >
                &times;
            </button>
        </div>

        <form
            method="POST"
            action="includes/documents-handler.php"
            enctype="multipart/form-data"
        >
            <input type="hidden" name="action" value="upload_folder">
            <input type="hidden" name="folder_id" value="<?= $currentFolderId ?>">

            <div class="modal-body">
                <div class="doc-add-location">
                    <i class="fas fa-folder-open"></i>
                    <div>
                        The uploaded folder will be created inside
                        <strong><?= h($currentFolder['folder_name'] ?? 'Documents') ?></strong>.
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label required">Choose Folder</label>

                    <input
                        type="file"
                        name="folder_documents[]"
                        id="folderUploadInput"
                        class="form-control"
                        webkitdirectory
                        directory
                        multiple
                        required
                        onchange="docUpdateFolderUpload(this)"
                    >

                    <div
                        id="folderUploadSummary"
                        class="doc-folder-upload-summary"
                    >
                        Select a folder. Its subfolders and files will be preserved.
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeModal('uploadFolderModal')"
                >
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-cloud-arrow-up"></i>
                    Upload Folder
                </button>
            </div>
        </form>
    </div>
</div>


<!-- PERMISSIONS -->
<div id="permissionsModal" class="modal">
    <div class="modal-card" style="max-width:780px;">
        <div class="modal-head">
            <h3>
                <i class="fas fa-user-shield" style="color:var(--brand-500);"></i>
                Permissions
            </h3>

            <button type="button" class="modal-close" onclick="closeModal('permissionsModal')">&times;</button>
        </div>

        <form method="POST" action="includes/documents-handler.php">
            <input type="hidden" name="action" value="save_permissions">
            <input type="hidden" name="resource_type" id="permResourceType">
            <input type="hidden" name="resource_id" id="permResourceId">

            <div class="modal-body">
                <div style="margin-bottom:12px;font-size:12px;color:var(--ink-400);">
                    Assign rights for <strong id="permResourceName"></strong>.
                </div>

                <div class="doc-rights-grid">
                    <div class="doc-rights-head">User</div>
                    <div class="doc-rights-head" style="text-align:center;">View</div>
                    <div class="doc-rights-head" style="text-align:center;">Edit</div>
                    <div class="doc-rights-head" style="text-align:center;">Download</div>

                    <?php foreach ($users as $user): ?>
                        <div class="doc-rights-row">
                            <div class="doc-rights-name">
                                <?= h($user['full_name']) ?>
                                <small style="display:block;color:var(--ink-300);font-weight:500;">
                                    <?= h($user['role']) ?>
                                </small>
                            </div>

                            <div class="doc-check">
                                <input
                                    type="checkbox"
                                    class="perm-box"
                                    data-user="<?= (int)$user['user_id'] ?>"
                                    data-right="view"
                                    name="permissions[<?= (int)$user['user_id'] ?>][view]"
                                    value="1"
                                >
                            </div>

                            <div class="doc-check">
                                <input
                                    type="checkbox"
                                    class="perm-box"
                                    data-user="<?= (int)$user['user_id'] ?>"
                                    data-right="edit"
                                    name="permissions[<?= (int)$user['user_id'] ?>][edit]"
                                    value="1"
                                >
                            </div>

                            <div class="doc-check">
                                <input
                                    type="checkbox"
                                    class="perm-box"
                                    data-user="<?= (int)$user['user_id'] ?>"
                                    data-right="download"
                                    name="permissions[<?= (int)$user['user_id'] ?>][download]"
                                    value="1"
                                >
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="doc-share-note">
                    Administrators and the owner always retain full access.
                    Folder permissions are inherited by files unless file-specific permissions are added.
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeModal('permissionsModal')">
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Permissions
                </button>
            </div>
        </form>
    </div>
</div>


