<?php
/*
 * Documents library: modals for the SharePoint-style features (move,
 * delete / restore confirmations, new version, version history).
 * Included by documents.php; driven by public/js/documents.js.
 */
?>

<!-- MOVE TO (one or many items) -->
<div id="spMoveModal" class="modal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="spMoveTitle">
        <div class="modal-head">
            <h3 id="spMoveTitle"><i class="fas fa-arrow-right-to-bracket" style="color:var(--brand-500);" aria-hidden="true"></i> Move to</h3>
            <button type="button" class="modal-close" onclick="closeModal('spMoveModal')" aria-label="Close">&times;</button>
        </div>

        <form method="POST" action="includes/documents-handler.php" id="spMoveForm">
            <input type="hidden" name="action" value="bulk_move">
            <div class="sp-items-holder"></div>

            <div class="modal-body">
                <p class="sp-modal-lead" id="spMoveSummary">Choose where to move the selected items.</p>

                <div class="form-group">
                    <label class="form-label required" for="spMoveTarget">Destination</label>
                    <select name="target_folder_id" id="spMoveTarget" class="form-control" size="8" required>
                        <option value="">Loading folders...</option>
                    </select>
                    <div class="form-hint" id="spMoveNote">Folders you can't add to aren't listed.</div>
                </div>

                <div class="doc-share-note">
                    <i class="fas fa-circle-info" aria-hidden="true"></i>
                    Moving keeps permissions, versions and properties. Only the location changes.
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeModal('spMoveModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="spMoveSubmit" disabled>
                    <i class="fas fa-arrow-right-to-bracket" aria-hidden="true"></i> Move here
                </button>
            </div>
        </form>
    </div>
</div>

<!-- CONFIRM (delete, delete permanently, empty bin) -->
<div id="spConfirmModal" class="modal doc-confirm-modal" aria-hidden="true">
    <div class="modal-card" role="alertdialog" aria-modal="true" aria-labelledby="spConfirmTitle" aria-describedby="spConfirmMessage">
        <div class="modal-head">
            <h3 id="spConfirmTitle">Delete?</h3>
            <button type="button" class="modal-close" onclick="closeModal('spConfirmModal')" aria-label="Close">&times;</button>
        </div>

        <form method="POST" action="includes/documents-handler.php" id="spConfirmForm">
            <input type="hidden" name="action" id="spConfirmAction" value="">
            <input type="hidden" name="return" value="">
            <div class="sp-items-holder"></div>

            <div class="modal-body">
                <div class="doc-confirm-icon" id="spConfirmIcon"><i class="fas fa-trash-can" aria-hidden="true"></i></div>
                <div class="doc-confirm-message" id="spConfirmMessage"></div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeModal('spConfirmModal')">Cancel</button>
                <button type="submit" class="btn btn-danger" id="spConfirmSubmit">Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- UPLOAD NEW VERSION -->
<div id="spVersionModal" class="modal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="spVersionTitle">
        <div class="modal-head">
            <h3 id="spVersionTitle"><i class="fas fa-file-arrow-up" style="color:var(--brand-500);" aria-hidden="true"></i> Upload new version</h3>
            <button type="button" class="modal-close" onclick="closeModal('spVersionModal')" aria-label="Close">&times;</button>
        </div>

        <form method="POST" action="includes/documents-handler.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_version">
            <input type="hidden" name="return" value="">
            <input type="hidden" name="document_id" id="spVersionDocId" value="">

            <div class="modal-body">
                <p class="sp-modal-lead">
                    Replace <strong id="spVersionDocName"></strong> with a newer file.
                    The current file is kept in its version history.
                </p>

                <div class="form-group">
                    <label class="form-label required" for="spVersionFile">File</label>
                    <input type="file" name="version_file" id="spVersionFile" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="spVersionComment">What changed? <span class="cell-muted">(optional)</span></label>
                    <input type="text" name="comment" id="spVersionComment" class="form-control" maxlength="255" placeholder="e.g. Updated figures for Q3">
                </div>

                <label class="sp-inline-check" id="spVersionCheckinWrap" hidden>
                    <input type="checkbox" name="check_in" value="1" checked>
                    Check the file in after uploading
                </label>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeModal('spVersionModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-upload" aria-hidden="true"></i> Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- VERSION HISTORY -->
<div id="spHistoryModal" class="modal" aria-hidden="true">
    <div class="modal-card sp-modal-wide" role="dialog" aria-modal="true" aria-labelledby="spHistoryTitle">
        <div class="modal-head">
            <h3 id="spHistoryTitle"><i class="fas fa-clock-rotate-left" style="color:var(--brand-500);" aria-hidden="true"></i> Version history</h3>
            <button type="button" class="modal-close" onclick="closeModal('spHistoryModal')" aria-label="Close">&times;</button>
        </div>

        <div class="modal-body">
            <p class="sp-modal-lead"><strong id="spHistoryName"></strong></p>
            <div class="table-responsive">
                <table class="table sp-history">
                    <thead>
                        <tr>
                            <th scope="col">Version</th>
                            <th scope="col">Modified</th>
                            <th scope="col">Modified by</th>
                            <th scope="col">Size</th>
                            <th scope="col">Comment</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody id="spHistoryRows">
                        <tr><td colspan="6" class="cell-muted">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" onclick="closeModal('spHistoryModal')">Close</button>
        </div>
    </div>
</div>

<!-- Immediate actions (restore, check out / in, restore version) -->
<form method="POST" action="includes/documents-handler.php" id="spActionForm" hidden>
    <input type="hidden" name="action" value="">
    <input type="hidden" name="return" value="">
    <input type="hidden" name="document_id" value="">
    <input type="hidden" name="version_id" value="">
    <div class="sp-items-holder"></div>
</form>
