<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Nairobi');

require_once __DIR__ . '/asset-import.php';

/* ----------------------------------------------
   HELPERS
---------------------------------------------- */

function redirect_to_assets(): never
{
    header('Location: manage_assets');
    exit;
}

function asset_flash(string $type, string $message): void
{
    $_SESSION[$type] = $message;
}

function asset_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d H:i:s');
}

/**
 * Resolve category from POST.
 * Prefers a numeric category_id; falls back to looking up by category name
 * (for Add/Edit forms that post the name string in `name="category"`).
 */
function resolveCategoryId(mysqli $conn): int
{
    $fromId = (int)($_POST['category_id'] ?? 0);
    if ($fromId > 0) {
        return categoryExists($conn, $fromId) ? $fromId : 0;
    }

    $name = trim((string)($_POST['category'] ?? ''));
    if ($name === '') {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT category_id
        FROM asset_categories
        WHERE category_name = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['category_id'] ?? 0);
}

function categoryExists(mysqli $conn, int $categoryId): bool
{
    $stmt = $conn->prepare("
        SELECT category_id
        FROM asset_categories
        WHERE category_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $categoryId);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

/* ----------------------------------------------
   POST DISPATCHER
---------------------------------------------- */

function handleAssetPostActions(mysqli $conn, int $currentUserId, bool $isAssetManager): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    try {
        match ($action) {
            'add_asset'            => addAsset($conn, $currentUserId, $isAssetManager),
            'import_assets'        => importAssets($conn, $currentUserId, $isAssetManager),
            'update_asset'         => updateAsset($conn, $isAssetManager),
            'delete_asset'         => deleteAsset($conn, $isAssetManager),
            'request_asset'        => requestAsset($conn, $currentUserId),
            'process_request'      => processAssetRequest($conn, $currentUserId, $isAssetManager),
            'delete_request'       => deleteAssetRequest($conn, $isAssetManager),
            'return_asset'         => returnAsset($conn, $isAssetManager),
            'update_depreciation'  => updateAssetDepreciation($conn, $isAssetManager),
            'offload_asset'        => offloadAsset($conn, $currentUserId, $isAssetManager),
            default                => null,
        };
    } catch (Throwable $e) {
        asset_flash('error', $e->getMessage());
        redirect_to_assets();
    }
}

/* ----------------------------------------------
   ADD ASSET
---------------------------------------------- */

function addAsset(mysqli $conn, int $currentUserId, bool $isAssetManager): never
{
    if (!$isAssetManager) {
        asset_flash('error', 'Access denied.');
        redirect_to_assets();
    }

    $asset_code       = trim((string)($_POST['asset_code']       ?? ''));
    $asset_name       = trim((string)($_POST['asset_name']       ?? ''));
    $brand            = trim((string)($_POST['brand']            ?? ''));
    $model            = trim((string)($_POST['model']            ?? ''));
    $serial_number    = trim((string)($_POST['serial_number']    ?? ''));
    $purchase_date    = trim((string)($_POST['purchase_date']    ?? ''));
    $purchase_cost    = (float)($_POST['purchase_cost']          ?? 0);
    $condition_status = trim((string)($_POST['condition_status'] ?? 'Good'));
    $description      = trim((string)($_POST['description']      ?? ''));
    $category_id      = resolveCategoryId($conn);

    if ($asset_code === '') {
        asset_flash('error', 'Asset code is required.');
        redirect_to_assets();
    }

    if ($asset_name === '') {
        asset_flash('error', 'Asset name is required.');
        redirect_to_assets();
    }

    if ($category_id <= 0) {
        asset_flash('error', 'Please select a valid category.');
        redirect_to_assets();
    }

    if (!in_array($condition_status, ['New', 'Good', 'Fair', 'Damaged', 'Disposed'], true)) {
        $condition_status = 'Good';
    }

    $purchase_date = $purchase_date !== '' ? $purchase_date : null;

    /* Duplicate code check */
    $dup = $conn->prepare("SELECT asset_id FROM assets WHERE asset_code = ? LIMIT 1");
    if ($dup) {
        $dup->bind_param('s', $asset_code);
        $dup->execute();
        $dupRow = $dup->get_result()->fetch_assoc();
        $dup->close();

        if ($dupRow) {
            asset_flash('error', 'An asset with this code already exists.');
            redirect_to_assets();
        }
    }

    /*
     * INSERT
     * types: s=asset_code  s=asset_name  i=category_id  s=brand  s=model
     *        s=serial_number  s=purchase_date  d=purchase_cost
     *        s=condition_status  s=description  i=created_by  s=created_at
     * = ssissssdssis  (12)
     */
    $stmt = $conn->prepare("
        INSERT INTO assets
            (asset_code, asset_name, category_id, brand, model, serial_number,
             purchase_date, purchase_cost, condition_status, availability_status,
             description, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available', ?, ?, ?)
    ");

    if (!$stmt) {
        asset_flash('error', 'Failed to prepare asset insert: ' . $conn->error);
        redirect_to_assets();
    }

    $now = asset_now();

    $stmt->bind_param(
        'ssissssdssis',
        $asset_code,
        $asset_name,
        $category_id,
        $brand,
        $model,
        $serial_number,
        $purchase_date,
        $purchase_cost,
        $condition_status,
        $description,
        $currentUserId,
        $now
    );

    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    asset_flash(
        $ok ? 'success' : 'error',
        $ok ? 'Asset added successfully.' : 'Failed to add asset: ' . $err
    );

    redirect_to_assets();
}

/* ----------------------------------------------
   UPDATE ASSET
---------------------------------------------- */

function updateAsset(mysqli $conn, bool $isAssetManager): never
{
    if (!$isAssetManager) {
        asset_flash('error', 'Access denied.');
        redirect_to_assets();
    }

    $asset_id         = (int)($_POST['asset_id']                ?? 0);
    $asset_code       = trim((string)($_POST['asset_code']       ?? ''));
    $asset_name       = trim((string)($_POST['asset_name']       ?? ''));
    $brand            = trim((string)($_POST['brand']            ?? ''));
    $model            = trim((string)($_POST['model']            ?? ''));
    $serial_number    = trim((string)($_POST['serial_number']    ?? ''));
    $purchase_date    = trim((string)($_POST['purchase_date']    ?? ''));
    $purchase_cost    = (float)($_POST['purchase_cost']          ?? 0);
    $condition_status = trim((string)($_POST['condition_status'] ?? 'Good'));
    $availability     = trim((string)($_POST['availability_status'] ?? 'Available'));
    $description      = trim((string)($_POST['description']      ?? ''));
    $category_id      = resolveCategoryId($conn);

    if ($asset_id <= 0) {
        asset_flash('error', 'Invalid asset selected.');
        redirect_to_assets();
    }

    if ($asset_code === '') {
        asset_flash('error', 'Asset code is required.');
        redirect_to_assets();
    }

    if ($asset_name === '') {
        asset_flash('error', 'Asset name is required.');
        redirect_to_assets();
    }

    if ($category_id <= 0) {
        asset_flash('error', 'Please select a valid category.');
        redirect_to_assets();
    }

    if (!in_array($condition_status, ['New', 'Good', 'Fair', 'Damaged', 'Disposed'], true)) {
        $condition_status = 'Good';
    }

    if (!in_array($availability, ['Available', 'Assigned', 'Under Repair', 'Disposed'], true)) {
        $availability = 'Available';
    }

    $purchase_date = $purchase_date !== '' ? $purchase_date : null;

    /*
     * UPDATE
     * types: s s i s s s s d s s s s i
     *        asset_code asset_name category_id brand model serial_number
     *        purchase_date purchase_cost condition_status availability_status
     *        description updated_at asset_id
     * = ssissssdssssi  (13)
     */
    $stmt = $conn->prepare("
        UPDATE assets
        SET asset_code          = ?,
            asset_name          = ?,
            category_id         = ?,
            brand               = ?,
            model               = ?,
            serial_number       = ?,
            purchase_date       = ?,
            purchase_cost       = ?,
            condition_status    = ?,
            availability_status = ?,
            description         = ?,
            updated_at          = ?
        WHERE asset_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        asset_flash('error', 'Failed to prepare asset update: ' . $conn->error);
        redirect_to_assets();
    }

    $now = asset_now();

    $stmt->bind_param(
        'ssissssdssssi',
        $asset_code,
        $asset_name,
        $category_id,
        $brand,
        $model,
        $serial_number,
        $purchase_date,
        $purchase_cost,
        $condition_status,
        $availability,
        $description,
        $now,
        $asset_id
    );

    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    asset_flash(
        $ok ? 'success' : 'error',
        $ok ? 'Asset updated successfully.' : 'Failed to update asset: ' . $err
    );

    redirect_to_assets();
}

/* ----------------------------------------------
   DELETE ASSET
---------------------------------------------- */

function deleteAsset(mysqli $conn, bool $isAssetManager): never
{
    if (!$isAssetManager) {
        asset_flash('error', 'Access denied.');
        redirect_to_assets();
    }

    $asset_id = (int)($_POST['asset_id'] ?? 0);

    if ($asset_id <= 0) {
        asset_flash('error', 'Invalid asset selected.');
        redirect_to_assets();
    }

    $check = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM asset_assignments
        WHERE asset_id = ? AND status = 'Assigned'
    ");

    if ($check) {
        $check->bind_param('i', $asset_id);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        $check->close();

        if ((int)($row['total'] ?? 0) > 0) {
            asset_flash('error', 'Cannot delete an asset while it is still assigned.');
            redirect_to_assets();
        }
    }

    $stmt = $conn->prepare("DELETE FROM assets WHERE asset_id = ? LIMIT 1");

    if (!$stmt) {
        asset_flash('error', 'Failed to prepare delete: ' . $conn->error);
        redirect_to_assets();
    }

    $stmt->bind_param('i', $asset_id);
    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    asset_flash(
        $ok ? 'success' : 'error',
        $ok ? 'Asset deleted successfully.' : 'Failed to delete asset: ' . $err
    );

    redirect_to_assets();
}

/* ----------------------------------------------
   REQUEST ASSET
---------------------------------------------- */

function requestAsset(mysqli $conn, int $currentUserId): never
{
    $asset_id       = (int)($_POST['asset_id']       ?? 0);
    $asset_name     = trim((string)($_POST['asset_name']     ?? ''));
    $category_id    = (int)($_POST['category_id']    ?? 0);
    $request_reason = trim((string)($_POST['request_reason'] ?? ''));
    $urgency_level  = trim((string)($_POST['urgency_level']  ?? 'Medium'));

    if (!in_array($urgency_level, ['Low', 'Medium', 'High'], true)) {
        $urgency_level = 'Medium';
    }

    if ($asset_name === '') {
        asset_flash('error', 'Asset name is required.');
        redirect_to_assets();
    }

    if ($category_id <= 0) {
        asset_flash('error', 'Please select a category.');
        redirect_to_assets();
    }

    if ($request_reason === '') {
        asset_flash('error', 'Request reason is required.');
        redirect_to_assets();
    }

    if (!categoryExists($conn, $category_id)) {
        asset_flash('error', 'Selected category does not exist.');
        redirect_to_assets();
    }

    $now       = asset_now();
    $assetIdDb = $asset_id > 0 ? $asset_id : null;

    if ($assetIdDb !== null) {
        $avail = $conn->prepare("SELECT availability_status FROM assets WHERE asset_id = ? LIMIT 1");
        if ($avail) {
            $avail->bind_param('i', $assetIdDb);
            $avail->execute();
            $availRow = $avail->get_result()->fetch_assoc();
            $avail->close();

            if (!$availRow || $availRow['availability_status'] !== 'Available') {
                asset_flash('error', 'The selected asset is no longer available.');
                redirect_to_assets();
            }
        }
    }

    /*
     * Two INSERT paths — keeps bind_param types clean when asset_id is NULL.
     *
     * With asset_id  ?  i i s i s s s s  (8) = 'iisissss'
     * Without        ?  i s i s s s s    (7) = 'isisssss'
     */
    if ($assetIdDb !== null) {
        $stmt = $conn->prepare("
            INSERT INTO asset_requests
                (staff_id, asset_id, asset_name, category_id, request_reason, urgency_level, status, requested_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, ?)
        ");

        if (!$stmt) {
            asset_flash('error', 'Failed to prepare request: ' . $conn->error);
            redirect_to_assets();
        }

        $stmt->bind_param(
            'iisissss',
            $currentUserId,
            $assetIdDb,
            $asset_name,
            $category_id,
            $request_reason,
            $urgency_level,
            $now,
            $now
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO asset_requests
                (staff_id, asset_name, category_id, request_reason, urgency_level, status, requested_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?)
        ");

        if (!$stmt) {
            asset_flash('error', 'Failed to prepare request: ' . $conn->error);
            redirect_to_assets();
        }

        $stmt->bind_param(
            'isissss',
            $currentUserId,
            $asset_name,
            $category_id,
            $request_reason,
            $urgency_level,
            $now,
            $now
        );
    }

    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    asset_flash(
        $ok ? 'success' : 'error',
        $ok ? 'Asset request submitted successfully.' : 'Failed to submit request: ' . $err
    );

    redirect_to_assets();
}

/* ----------------------------------------------
   PROCESS REQUEST  (approve / reject)
---------------------------------------------- */

function processAssetRequest(mysqli $conn, int $currentUserId, bool $isAssetManager): never
{
    if (!$isAssetManager) {
        asset_flash('error', 'Access denied.');
        redirect_to_assets();
    }

    $request_id     = (int)($_POST['request_id']     ?? 0);
    $decision       = trim((string)($_POST['decision']       ?? ''));
    $asset_id       = (int)($_POST['asset_id']       ?? 0);
    $approval_notes = trim((string)($_POST['approval_notes'] ?? ''));

    if ($request_id <= 0 || !in_array($decision, ['Approved', 'Rejected'], true)) {
        asset_flash('error', 'Invalid request action.');
        redirect_to_assets();
    }

    if ($decision === 'Rejected' && $approval_notes === '') {
        asset_flash('error', 'Please enter a rejection reason.');
        redirect_to_assets();
    }

    $now = asset_now();

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            SELECT request_id, staff_id, status
            FROM asset_requests
            WHERE request_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$request) {
            throw new RuntimeException('Request not found.');
        }

        if ($request['status'] !== 'Pending') {
            throw new RuntimeException('This request has already been processed.');
        }

        /* -- REJECT -- */
        if ($decision === 'Rejected') {
            $stmt = $conn->prepare("
                UPDATE asset_requests
                SET status         = 'Rejected',
                    approved_by    = ?,
                    approval_notes = ?,
                    approved_at    = ?
                WHERE request_id = ?
                LIMIT 1
            ");
            $stmt->bind_param('issi', $currentUserId, $approval_notes, $now, $request_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            asset_flash('success', 'Request rejected successfully.');
            redirect_to_assets();
        }

        /* -- APPROVE -- */
        if ($asset_id <= 0) {
            throw new RuntimeException('Please select an available asset to assign.');
        }

        $stmt = $conn->prepare("
            SELECT asset_id, availability_status
            FROM assets
            WHERE asset_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('i', $asset_id);
        $stmt->execute();
        $asset = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$asset) {
            throw new RuntimeException('Selected asset was not found.');
        }

        if ($asset['availability_status'] !== 'Available') {
            throw new RuntimeException('Selected asset is no longer available.');
        }

        /* Update request ? Issued */
        $stmt = $conn->prepare("
            UPDATE asset_requests
            SET asset_id       = ?,
                status         = 'Issued',
                approved_by    = ?,
                approval_notes = ?,
                approved_at    = ?
            WHERE request_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('iissi', $asset_id, $currentUserId, $approval_notes, $now, $request_id);
        $stmt->execute();
        $stmt->close();

        /* Create assignment */
        $stmt = $conn->prepare("
            INSERT INTO asset_assignments
                (asset_id, staff_id, request_id, assigned_by, status, assigned_at)
            VALUES (?, ?, ?, ?, 'Assigned', ?)
        ");
        $stmt->bind_param('iiiis', $asset_id, $request['staff_id'], $request_id, $currentUserId, $now);
        $stmt->execute();
        $stmt->close();

        /* Mark asset Assigned */
        $stmt = $conn->prepare("
            UPDATE assets
            SET availability_status = 'Assigned',
                updated_at          = ?
            WHERE asset_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('si', $now, $asset_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        asset_flash('success', 'Request approved and asset assigned successfully.');

    } catch (Throwable $e) {
        $conn->rollback();
        asset_flash('error', $e->getMessage());
    }

    redirect_to_assets();
}

/* ----------------------------------------------
   DELETE REQUEST
---------------------------------------------- */

function deleteAssetRequest(mysqli $conn, bool $isAssetManager): never
{
    if (!$isAssetManager) {
        asset_flash('error', 'Access denied.');
        redirect_to_assets();
    }

    $request_id = (int)($_POST['request_id'] ?? 0);

    if ($request_id <= 0) {
        asset_flash('error', 'Invalid request selected.');
        redirect_to_assets();
    }

    $check = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM asset_assignments
        WHERE request_id = ? AND status = 'Assigned'
    ");

    if ($check) {
        $check->bind_param('i', $request_id);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        $check->close();

        if ((int)($row['total'] ?? 0) > 0) {
            asset_flash('error', 'Cannot delete a request with an active assigned asset.');
            redirect_to_assets();
        }
    }

    $stmt = $conn->prepare("DELETE FROM asset_requests WHERE request_id = ? LIMIT 1");

    if (!$stmt) {
        asset_flash('error', 'Failed to prepare delete: ' . $conn->error);
        redirect_to_assets();
    }

    $stmt->bind_param('i', $request_id);
    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    asset_flash(
        $ok ? 'success' : 'error',
        $ok ? 'Request deleted successfully.' : 'Failed to delete request: ' . $err
    );

    redirect_to_assets();
}

/* ----------------------------------------------
   RETURN ASSET
---------------------------------------------- */

function returnAsset(mysqli $conn, bool $isAssetManager): never
{
    if (!$isAssetManager) {
        asset_flash('error', 'Access denied.');
        redirect_to_assets();
    }

    $assignment_id = (int)($_POST['assignment_id'] ?? 0);
    $return_notes  = trim((string)($_POST['return_notes'] ?? ''));

    if ($assignment_id <= 0) {
        asset_flash('error', 'Invalid assignment selected.');
        redirect_to_assets();
    }

    $now = asset_now();

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            SELECT asset_id, request_id
            FROM asset_assignments
            WHERE assignment_id = ? AND status = 'Assigned'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('i', $assignment_id);
        $stmt->execute();
        $assignment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$assignment) {
            throw new RuntimeException('Assignment not found or already returned.');
        }

        $stmt = $conn->prepare("
            UPDATE asset_assignments
            SET status       = 'Returned',
                returned_at  = ?,
                return_notes = ?
            WHERE assignment_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ssi', $now, $return_notes, $assignment_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE assets
            SET availability_status = 'Available',
                updated_at          = ?
            WHERE asset_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('si', $now, $assignment['asset_id']);
        $stmt->execute();
        $stmt->close();

        if (!empty($assignment['request_id'])) {
            $stmt = $conn->prepare("
                UPDATE asset_requests
                SET status = 'Returned'
                WHERE request_id = ?
                LIMIT 1
            ");
            $stmt->bind_param('i', $assignment['request_id']);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();
        asset_flash('success', 'Asset returned successfully.');

    } catch (Throwable $e) {
        $conn->rollback();
        asset_flash('error', $e->getMessage());
    }

    redirect_to_assets();
}

/* ----------------------------------------------
   UPDATE DEPRECIATION
---------------------------------------------- */

function updateAssetDepreciation(mysqli $conn, bool $isAssetManager): never
{
    if (!$isAssetManager) {
        asset_flash('error', 'Access denied.');
        redirect_to_assets();
    }

    $asset_id = (int)($_POST['asset_id']          ?? 0);
    $rate     = (float)($_POST['depreciation_rate'] ?? 0);

    if ($asset_id <= 0 || $rate < 0 || $rate > 100) {
        asset_flash('error', 'Invalid depreciation details. Rate must be between 0 and 100.');
        redirect_to_assets();
    }

    $stmt = $conn->prepare("
        SELECT purchase_cost
        FROM assets
        WHERE asset_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        asset_flash('error', 'Database error: ' . $conn->error);
        redirect_to_assets();
    }

    $stmt->bind_param('i', $asset_id);
    $stmt->execute();
    $asset = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$asset) {
        asset_flash('error', 'Asset not found.');
        redirect_to_assets();
    }

    $purchaseCost = (float)$asset['purchase_cost'];
    $currentValue = max(0.0, $purchaseCost - (($purchaseCost * $rate) / 100));
    $now          = asset_now();

    $stmt = $conn->prepare("
        UPDATE assets
        SET depreciation_rate = ?,
            current_value     = ?,
            updated_at        = ?
        WHERE asset_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        asset_flash('error', 'Failed to prepare depreciation update: ' . $conn->error);
        redirect_to_assets();
    }

    $stmt->bind_param('ddsi', $rate, $currentValue, $now, $asset_id);
    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    asset_flash(
        $ok ? 'success' : 'error',
        $ok ? 'Depreciation updated successfully.' : 'Failed to update depreciation: ' . $err
    );

    redirect_to_assets();
}

/* ----------------------------------------------
   OFFLOAD ASSET
---------------------------------------------- */

function offloadAsset(mysqli $conn, int $currentUserId, bool $isAssetManager): never
{
    if (!$isAssetManager) {
        asset_flash('error', 'Access denied.');
        redirect_to_assets();
    }

    $asset_id = (int)($_POST['asset_id']      ?? 0);
    $reason   = trim((string)($_POST['offload_reason'] ?? ''));

    if ($asset_id <= 0) {
        asset_flash('error', 'Invalid asset selected.');
        redirect_to_assets();
    }

    if ($reason === '') {
        asset_flash('error', 'Offload reason is required.');
        redirect_to_assets();
    }

    /* Block offload while still assigned */
    $check = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM asset_assignments
        WHERE asset_id = ? AND status = 'Assigned'
    ");

    if ($check) {
        $check->bind_param('i', $asset_id);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        $check->close();

        if ((int)($row['total'] ?? 0) > 0) {
            asset_flash('error', 'Cannot offload an asset that is currently assigned.');
            redirect_to_assets();
        }
    }

    $now = asset_now();

    $stmt = $conn->prepare("
        UPDATE assets
        SET availability_status = 'Disposed',
            condition_status    = 'Disposed',
            offload_status      = 'Offloaded',
            offload_reason      = ?,
            offloaded_by        = ?,
            offloaded_at        = ?,
            updated_at          = ?
        WHERE asset_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        asset_flash('error', 'Failed to prepare offload: ' . $conn->error);
        redirect_to_assets();
    }

    $stmt->bind_param('siiss', $reason, $currentUserId, $now, $now, $asset_id);
    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    asset_flash(
        $ok ? 'success' : 'error',
        $ok ? 'Asset offloaded successfully.' : 'Failed to offload asset: ' . $err
    );

    redirect_to_assets();
}

/* ----------------------------------------------
   DATA FETCHERS
---------------------------------------------- */

function getManageAssetsData(mysqli $conn, int $currentUserId, bool $isAssetManager): array
{
    return [
        'stats'            => getAssetStats($conn),
        'categories'       => getAssetCategories($conn),
        'assets'           => getAssets($conn),
        'requests'         => getAssetRequests($conn, $currentUserId, $isAssetManager),
        'assignments'      => getAssetAssignments($conn, $currentUserId, $isAssetManager),
        'available_assets' => getAvailableAssets($conn),
    ];
}

function getAssetStats(mysqli $conn): array
{
    $stats = [
        'total_assets'     => 0,
        'available_assets' => 0,
        'assigned_assets'  => 0,
        'pending_requests' => 0,
    ];

    $res = $conn->query("
        SELECT
            COUNT(*) AS total_assets,
            COALESCE(SUM(CASE WHEN availability_status = 'Available' THEN 1 ELSE 0 END), 0) AS available_assets,
            COALESCE(SUM(CASE WHEN availability_status = 'Assigned'  THEN 1 ELSE 0 END), 0) AS assigned_assets
        FROM assets
    ");

    if ($res) {
        $row                       = $res->fetch_assoc();
        $stats['total_assets']     = (int)($row['total_assets']     ?? 0);
        $stats['available_assets'] = (int)($row['available_assets'] ?? 0);
        $stats['assigned_assets']  = (int)($row['assigned_assets']  ?? 0);
    }

    $res = $conn->query("SELECT COUNT(*) AS total FROM asset_requests WHERE status = 'Pending'");
    if ($res) {
        $stats['pending_requests'] = (int)($res->fetch_assoc()['total'] ?? 0);
    }

    return $stats;
}

function getAssetCategories(mysqli $conn): array
{
    $rows = [];

    $res = $conn->query("
        SELECT category_id, category_name, description
        FROM asset_categories
        ORDER BY category_name ASC
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function getAssets(mysqli $conn): array
{
    $rows = [];

    $res = $conn->query("
        SELECT
            a.*,
            c.category_name,
            u.full_name AS creator_name
        FROM assets a
        LEFT JOIN asset_categories c ON c.category_id = a.category_id
        LEFT JOIN users u            ON u.user_id      = a.created_by
        ORDER BY a.asset_id DESC
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function getAssetRequests(mysqli $conn, int $currentUserId, bool $isAssetManager): array
{
    $rows = [];

    if ($isAssetManager) {
        $res = $conn->query("
            SELECT
                ar.*,
                u.full_name      AS staff_name,
                c.category_name,
                a.asset_code,
                a.asset_name     AS assigned_asset
            FROM asset_requests ar
            JOIN  users u                ON u.user_id      = ar.staff_id
            LEFT JOIN asset_categories c ON c.category_id  = ar.category_id
            LEFT JOIN assets a           ON a.asset_id     = ar.asset_id
            ORDER BY ar.requested_at DESC
        ");

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    $stmt = $conn->prepare("
        SELECT
            ar.*,
            u.full_name      AS staff_name,
            c.category_name,
            a.asset_code,
            a.asset_name     AS assigned_asset
        FROM asset_requests ar
        JOIN  users u                ON u.user_id      = ar.staff_id
        LEFT JOIN asset_categories c ON c.category_id  = ar.category_id
        LEFT JOIN assets a           ON a.asset_id     = ar.asset_id
        WHERE ar.staff_id = ?
        ORDER BY ar.requested_at DESC
    ");

    if ($stmt) {
        $stmt->bind_param('i', $currentUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    }

    return $rows;
}

function getAssetAssignments(mysqli $conn, int $currentUserId, bool $isAssetManager): array
{
    $rows = [];

    if ($isAssetManager) {
        $res = $conn->query("
            SELECT
                aa.*,
                a.asset_code,
                a.asset_name,
                c.category_name,
                u.full_name      AS staff_name,
                ub.full_name     AS assigned_by_name
            FROM asset_assignments aa
            JOIN  assets a               ON a.asset_id     = aa.asset_id
            LEFT JOIN asset_categories c ON c.category_id  = a.category_id
            JOIN  users u                ON u.user_id      = aa.staff_id
            LEFT JOIN users ub           ON ub.user_id     = aa.assigned_by
            ORDER BY aa.assignment_id DESC
        ");

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    $stmt = $conn->prepare("
        SELECT
            aa.*,
            a.asset_code,
            a.asset_name,
            c.category_name,
            u.full_name      AS staff_name,
            ub.full_name     AS assigned_by_name
        FROM asset_assignments aa
        JOIN  assets a               ON a.asset_id     = aa.asset_id
        LEFT JOIN asset_categories c ON c.category_id  = a.category_id
        JOIN  users u                ON u.user_id      = aa.staff_id
        LEFT JOIN users ub           ON ub.user_id     = aa.assigned_by
        WHERE aa.staff_id = ?
        ORDER BY aa.assignment_id DESC
    ");

    if ($stmt) {
        $stmt->bind_param('i', $currentUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    }

    return $rows;
}

function getAvailableAssets(mysqli $conn): array
{
    $rows = [];

    $res = $conn->query("
        SELECT
            a.asset_id,
            a.asset_code,
            a.asset_name,
            a.category_id,
            c.category_name
        FROM assets a
        LEFT JOIN asset_categories c ON c.category_id = a.category_id
        WHERE a.availability_status = 'Available'
        ORDER BY a.asset_name ASC
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}