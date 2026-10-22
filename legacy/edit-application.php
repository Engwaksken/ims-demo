<?php
/*
 * Legacy "Edit Application" page.
 *
 * The original page queried tables that no longer exist (startup_applications /
 * startup_programs). Drafts are now resumed through
 * submit-application?opportunity_id=X&draft_id=Y, and submitted applications are
 * not editable, so this entry point simply sends applicants to My Applications.
 */
header('Location: my-applications');
exit();
