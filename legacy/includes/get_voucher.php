<?php
require_once '../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

function jsonOut($ok, $msg, $extra=[]){
  echo json_encode(array_merge(['success'=>$ok,'message'=>$msg], $extra));
  exit;
}

if (!isset($_SESSION['user_id'])) jsonOut(false, 'Not authenticated.');
if (!ims_allowed('vouchers.view', ['Administrator', 'Finance', 'Accountant'], 'View, print and download payment vouchers')) jsonOut(false, 'Access denied.');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) jsonOut(false, 'Invalid ID.');

$stmt = $conn->prepare("SELECT * FROM payment_vouchers WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) jsonOut(false, 'Voucher not found.');

$items = [];
if (!empty($row['items_json'])) {
  $decoded = json_decode($row['items_json'], true);
  if (is_array($decoded)) $items = $decoded;
}

$row['items'] = $items;

jsonOut(true, 'OK', ['voucher' => $row]);