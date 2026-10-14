<?php
require_once 'config.php';
check_login();

/* ---------------------------------------------------------
   Helpers
--------------------------------------------------------- */
function redirect_back($query = '') {
    header("Location: ../donors-partners$query");
    exit();
}

/* ---------------------------------------------------------
   ADD DONOR
--------------------------------------------------------- */
if (isset($_POST['add_donor'])) {
    check_role(['Administrator', 'Programs Lead', 'MEAL Lead']);

    $donor_name     = sanitize_input($_POST['donor_name']);
    $contact_person = sanitize_input($_POST['contact_person']);
    $email          = sanitize_input($_POST['email']);
    $phone          = sanitize_input($_POST['phone']);
    $country        = sanitize_input($_POST['country']);
    $website        = sanitize_input($_POST['website']);
    $address        = sanitize_input($_POST['address']);

    if (empty($donor_name)) {
        send_notification($_SESSION['user_id'], 'Donor name is required', 'error');
        redirect_back();
    }

    $check = $conn->prepare("SELECT donor_id FROM donors WHERE donor_name = ?");
    $check->bind_param("s", $donor_name);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        send_notification($_SESSION['user_id'], 'A donor with this name already exists', 'error');
        redirect_back();
    }

    $stmt = $conn->prepare("
        INSERT INTO donors 
        (donor_name, contact_person, email, phone, country, website, address, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("sssssss", $donor_name, $contact_person, $email, $phone, $country, $website, $address);

    if ($stmt->execute()) {
        log_action($_SESSION['user_id'], 'Add Donor', 'donors', $conn->insert_id, "Added donor: $donor_name");
        send_notification($_SESSION['user_id'], 'Donor added successfully!', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error adding donor', 'error');
    }

    redirect_back();
}

/* ---------------------------------------------------------
   EDIT DONOR
--------------------------------------------------------- */
if (isset($_POST['edit_donor'])) {
    check_role(['Administrator', 'Programs Lead', 'Program Director', 'MEAL Lead']);

    $donor_id       = (int)$_POST['donor_id'];
    $donor_name     = sanitize_input($_POST['donor_name']);
    $contact_person = sanitize_input($_POST['contact_person']);
    $email          = sanitize_input($_POST['email']);
    $phone          = sanitize_input($_POST['phone']);
    $country        = sanitize_input($_POST['country']);
    $website        = sanitize_input($_POST['website']);
    $address        = sanitize_input($_POST['address']);

    if (empty($donor_name)) {
        send_notification($_SESSION['user_id'], 'Donor name is required', 'error');
        redirect_back("?edit_donor=$donor_id");
    }

    $check = $conn->prepare("
        SELECT donor_id FROM donors 
        WHERE donor_name = ? AND donor_id != ?
    ");
    $check->bind_param("si", $donor_name, $donor_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        send_notification($_SESSION['user_id'], 'Another donor with this name already exists', 'error');
        redirect_back("?edit_donor=$donor_id");
    }

    $stmt = $conn->prepare("
        UPDATE donors SET
            donor_name = ?, contact_person = ?, email = ?, phone = ?,
            country = ?, website = ?, address = ?
        WHERE donor_id = ?
    ");
    $stmt->bind_param("sssssssi", $donor_name, $contact_person, $email, $phone, $country, $website, $address, $donor_id);

    if ($stmt->execute()) {
        log_action($_SESSION['user_id'], 'Edit Donor', 'donors', $donor_id, "Updated donor: $donor_name");
        send_notification($_SESSION['user_id'], 'Donor updated successfully!', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error updating donor', 'error');
    }

    redirect_back();
}

/* ---------------------------------------------------------
   DELETE DONOR
--------------------------------------------------------- */
if (isset($_POST['delete_donor'])) {
    if (!ims_allowed('donors_partners.delete_donors', ['Administrator'], 'Delete donors')) {
        send_notification($_SESSION['user_id'] ?? 0, 'You are not allowed to do that.', 'danger');
        redirect_back();
    }

    $donor_id = (int)$_POST['donor_id'];

    $check = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM programs WHERE donor_id = ?) AS programs,
            (SELECT COUNT(*) FROM projects WHERE donor_id = ?) AS projects,
            donor_name
        FROM donors WHERE donor_id = ?
    ");
    $check->bind_param("iii", $donor_id, $donor_id, $donor_id);
    $check->execute();
    $data = $check->get_result()->fetch_assoc();

    if ($data['programs'] > 0 || $data['projects'] > 0) {
        send_notification($_SESSION['user_id'], 'Cannot delete donor with linked programs or projects', 'error');
        redirect_back();
    }

    $stmt = $conn->prepare("DELETE FROM donors WHERE donor_id = ?");
    $stmt->bind_param("i", $donor_id);

    if ($stmt->execute()) {
        log_action($_SESSION['user_id'], 'Delete Donor', 'donors', $donor_id, "Deleted donor: {$data['donor_name']}");
        send_notification($_SESSION['user_id'], 'Donor deleted successfully!', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error deleting donor', 'error');
    }

    redirect_back();
}

/* ---------------------------------------------------------
   FILE UPLOAD (PARTNER LETTER)
--------------------------------------------------------- */
function upload_recommendation_letter($file, $existing = null) {
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) return $existing;
    if ($file['error'] !== UPLOAD_ERR_OK) return false;

    $allowed = ['pdf', 'doc', 'docx'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed) || $file['size'] > 5242880) return false;
    if (!ims_validate_upload($file, $allowed, 5242880)['ok']) return false;

    $dir = __DIR__ . '/../uploads/recommendations/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $name = 'letter_' . uniqid() . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], $dir . $name)
        ? "uploads/recommendations/$name"
        : false;
}

/* ---------------------------------------------------------
   ADD PARTNER
--------------------------------------------------------- */
if (isset($_POST['add_partner'])) {
    check_role(['Administrator', 'Programs Lead', 'MEAL Lead']);

    $partner_name   = sanitize_input($_POST['partner_name']);
    $partner_type   = sanitize_input($_POST['partner_type']);
    $contact_person = sanitize_input($_POST['contact_person']);
    $email          = sanitize_input($_POST['email']);
    $phone          = sanitize_input($_POST['phone']);
    $country        = sanitize_input($_POST['country']);
    $website        = sanitize_input($_POST['website_partner']);
    $address        = sanitize_input($_POST['address']);

    /* Validation */
    if (empty($partner_name) || empty($partner_type)) {
        send_notification($_SESSION['user_id'], 'Partner name and type are required', 'error');
        redirect_back();
    }

    /* Prevent duplicates */
    $check = $conn->prepare("SELECT partner_id FROM partners WHERE partner_name = ?");
    $check->bind_param("s", $partner_name);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        send_notification($_SESSION['user_id'], 'A partner with this name already exists', 'error');
        redirect_back();
    }

    /* Upload recommendation letter (optional) */
    $letterPath = upload_recommendation_letter($_FILES['recommend_letter'] ?? null);

    if ($letterPath === false) {
        send_notification($_SESSION['user_id'], 'Invalid recommendation letter file', 'error');
        redirect_back();
    }

    /* Insert partner */
    $stmt = $conn->prepare("
        INSERT INTO partners
        (partner_name, partner_type, contact_person, email, phone, country, website, letter, address, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->bind_param(
        "sssssssss",
        $partner_name,
        $partner_type,
        $contact_person,
        $email,
        $phone,
        $country,
        $website,
        $letterPath,
        $address
    );

    if ($stmt->execute()) {
        $partner_id = $conn->insert_id;

        log_action(
            $_SESSION['user_id'],
            'Add Partner',
            'partners',
            $partner_id,
            "Added partner: $partner_name"
        );

        send_notification($_SESSION['user_id'], 'Partner added successfully!', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error adding partner', 'error');
    }

    redirect_back();
}



/* ---------------------------------------------------------
   EDIT PARTNER
--------------------------------------------------------- */
if (isset($_POST['edit_partner'])) {
    check_role(['Administrator', 'Programs Lead', 'MEAL Lead']);

    $partner_id     = (int)$_POST['partner_id'];
    $partner_name   = sanitize_input($_POST['partner_name']);
    $partner_type   = sanitize_input($_POST['partner_type']);
    $contact_person = sanitize_input($_POST['contact_person']);
    $email          = sanitize_input($_POST['email']);
    $phone          = sanitize_input($_POST['phone']);
    $country        = sanitize_input($_POST['country']);
    $website        = sanitize_input($_POST['website_partner']);
    $address        = sanitize_input($_POST['address']);

    if (empty($partner_name) || empty($partner_type)) {
        send_notification($_SESSION['user_id'], 'Partner name and type are required', 'error');
        redirect_back("?edit_partner=$partner_id");
    }

    $old = $conn->prepare("SELECT letter FROM partners WHERE partner_id = ?");
    $old->bind_param("i", $partner_id);
    $old->execute();
    $oldLetter = $old->get_result()->fetch_assoc()['letter'] ?? null;

    $letter = upload_recommendation_letter($_FILES['recommend_letter'], $oldLetter);
    if ($letter === false) {
        send_notification($_SESSION['user_id'], 'Invalid recommendation letter file', 'error');
        redirect_back("?edit_partner=$partner_id");
    }

    $stmt = $conn->prepare("
        UPDATE partners SET
            partner_name = ?, partner_type = ?, contact_person = ?,
            email = ?, phone = ?, country = ?, website = ?, letter = ?, address = ?
        WHERE partner_id = ?
    ");
    $stmt->bind_param(
        "sssssssssi",
        $partner_name, $partner_type, $contact_person,
        $email, $phone, $country, $website, $letter, $address, $partner_id
    );

    if ($stmt->execute()) {
        log_action($_SESSION['user_id'], 'Edit Partner', 'partners', $partner_id, "Updated partner: $partner_name");
        send_notification($_SESSION['user_id'], 'Partner updated successfully!', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error updating partner', 'error');
    }

    redirect_back();
}

/* ---------------------------------------------------------
   DELETE PARTNER
--------------------------------------------------------- */
if (isset($_POST['delete_partner'])) {
    if (!ims_allowed('donors_partners.delete_partners', ['Administrator'], 'Delete partners')) {
        send_notification($_SESSION['user_id'] ?? 0, 'You are not allowed to do that.', 'danger');
        redirect_back();
    }

    $partner_id = (int)$_POST['partner_id'];

    $check = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM program_partners WHERE partner_id = ?) AS programs,
            (SELECT COUNT(*) FROM project_partners WHERE partner_id = ?) AS projects,
            partner_name
        FROM partners WHERE partner_id = ?
    ");
    $check->bind_param("iii", $partner_id, $partner_id, $partner_id);
    $check->execute();
    $data = $check->get_result()->fetch_assoc();

    if ($data['programs'] > 0 || $data['projects'] > 0) {
        send_notification($_SESSION['user_id'], 'Cannot delete partner with linked records', 'error');
        redirect_back();
    }

    $stmt = $conn->prepare("DELETE FROM partners WHERE partner_id = ?");
    $stmt->bind_param("i", $partner_id);

    if ($stmt->execute()) {
        log_action($_SESSION['user_id'], 'Delete Partner', 'partners', $partner_id, "Deleted partner: {$data['partner_name']}");
        send_notification($_SESSION['user_id'], 'Partner deleted successfully!', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error deleting partner', 'error');
    }

    redirect_back();
}

redirect_back();
