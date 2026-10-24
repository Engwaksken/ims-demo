<?php
declare(strict_types=1);
$page_title = 'Activity/Event Requests';
require_once __DIR__ . '/includes/config.php';
check_login();
$earlyRole = (string)($_SESSION['role_name'] ?? $_SESSION['role'] ?? '');
if (($_GET['budget_template'] ?? '') === '1') {
    if (!in_array($earlyRole, ['Administrator','Finance','Accountant'], true)) { http_response_code(403); exit('Finance access required.'); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="project-program-budget-template.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['entity_type','entity_code','activity_name','fiscal_year','currency','allocated_amount']);
    fputcsv($out, ['Project','PRJ-001','Example activity',date('Y'),'UGX','1000000']);
    fputcsv($out, ['Program','PRG-001','Example activity',date('Y'),'UGX','500000']);
    fclose($out); exit;
}
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/security.php';
if (!isset($conn) || !$conn instanceof mysqli) { http_response_code(500); exit('Database connection unavailable.'); }
$uid = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role_name'] ?? $_SESSION['role'] ?? '');
$finance = in_array($role, ['Administrator','Finance','Accountant'], true);
$ops = in_array($role, ['Administrator','Operations/Admin','Chief of Operations'], true);
$procurement = in_array($role, ['Administrator','Procurement Officer'], true);
$director = in_array($role, ['Administrator','Executive Director','Director','CEO'], true);
$notice = '';
$notifyStage = static function(mysqli $db, string $status, int $requestId, string $title) use ($uid): void {
    $groups = [
        'Supervisor Review' => ['Operations/Admin','Chief of Operations'],
        'Procurement Review' => ['Procurement Officer'],
        'Finance Review' => ['Finance','Accountant'],
        'Director Approval' => ['Executive Director','Director','CEO'],
    ];
    foreach ($groups[$status] ?? [] as $group) {
        $stmt = $db->prepare('SELECT user_id FROM users WHERE role = ?');
        if (!$stmt) continue;
        $stmt->bind_param('s', $group); $stmt->execute(); $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) if ((int)$row['user_id'] !== $uid) notify_user((int)$row['user_id'], 'Activity/event request requires review', $title . ' is awaiting ' . $group . ' review.', 'info', $requestId, 'activity_event_request');
        $stmt->close();
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) { http_response_code(419); exit('Invalid form token. Refresh and try again.'); }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'submit') {
        $title = trim((string)($_POST['title'] ?? '')); $concept = trim((string)($_POST['concept'] ?? ''));
        $entity = (string)($_POST['entity_type'] ?? ''); $entityId = (int)($_POST['entity_id'] ?? 0); $milestone = (int)($_POST['milestone_id'] ?? 0);
        $activity = trim((string)($_POST['activity_name'] ?? '')); $start = (string)($_POST['start_date'] ?? '');
        $amount = filter_var($_POST['requested_amount'] ?? null, FILTER_VALIDATE_FLOAT); $currency = strtoupper(trim((string)($_POST['currency'] ?? 'UGX')));
        if ($title === '' || $concept === '' || $activity === '' || !in_array($entity, ['Project','Program'], true) || $entityId < 1 || $milestone < 1 || !$amount || $amount <= 0 || !preg_match('/^[A-Z]{3,10}$/', $currency) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $notice = 'Complete all required fields with a valid date and amount.';
        } else {
            $entityTable = $entity === 'Project' ? 'projects' : 'programs'; $entityKey = $entity === 'Project' ? 'project_id' : 'id';
            $check = $conn->prepare("SELECT {$entityKey} FROM {$entityTable} WHERE {$entityKey} = ?"); $check->bind_param('i', $entityId); $check->execute(); $exists = $check->get_result()->num_rows > 0; $check->close();
            $check = $conn->prepare('SELECT milestone_id FROM milestones WHERE milestone_id = ? AND entity_type = ? AND entity_id = ?'); $check->bind_param('isi', $milestone, $entity, $entityId); $check->execute(); $milestoneExists = $check->get_result()->num_rows > 0; $check->close();
            if (!$exists || !$milestoneExists) $notice = 'Choose a valid project/program and one of its milestones.';
            else {
                $ref = 'ACT-' . strtoupper(bin2hex(random_bytes(4)));
                $type = trim((string)($_POST['event_type'] ?? 'Activity')) ?: 'Activity'; $end = (string)($_POST['end_date'] ?? ''); $location = trim((string)($_POST['location'] ?? '')); $participants = max(0, (int)($_POST['participants'] ?? 0));
                $stmt = $conn->prepare("INSERT INTO activity_event_requests (reference_code,title,concept,event_type,entity_type,entity_id,milestone_id,activity_name,start_date,end_date,location,participants,requested_amount,currency,created_by) VALUES (?,?,?,?,?,?,?,?,?,NULLIF(?,''),NULLIF(?,''),?,?,?,?)");
                $stmt->bind_param('sssssii' . 'ssss' . 'idsi',$ref,$title,$concept,$type,$entity,$entityId,$milestone,$activity,$start,$end,$location,$participants,$amount,$currency,$uid);
                if ($stmt->execute()) { $newId=(int)$conn->insert_id; $stmt->close(); $notifyStage($conn,'Supervisor Review',$newId,$title); $notice='Request submitted for supervisor/operations review.'; }
                else { $notice='Unable to submit request. Please try again.'; $stmt->close(); }
            }
        }
    } elseif ($action === 'review') {
        $id=(int)($_POST['request_id'] ?? 0); $decision=(string)($_POST['decision'] ?? ''); $comments=trim((string)($_POST['comments'] ?? ''));
        $stmt=$conn->prepare('SELECT * FROM activity_event_requests WHERE request_id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $request=$stmt->get_result()->fetch_assoc(); $stmt->close();
        $stages=['Supervisor Review'=>['Supervisor',$ops],'Procurement Review'=>['Procurement',$procurement],'Finance Review'=>['Finance',$finance],'Director Approval'=>['Director',$director]];
        if (!$request || !isset($stages[$request['status']]) || !$stages[$request['status']][1] || !in_array($decision,['Approved','Returned','Rejected'],true) || ($decision!=='Approved' && $comments==='')) $notice='This action is not permitted, or comments are required for return/rejection.';
        else {
            [$stage]=$stages[$request['status']]; $next=['Supervisor Review'=>'Procurement Review','Procurement Review'=>'Finance Review','Finance Review'=>'Director Approval','Director Approval'=>'Approved'];
            $status=$decision==='Approved'?$next[$request['status']]:$decision;
            $conn->begin_transaction();
            $budgetOk=true;
            if ($request['status']==='Finance Review' && $decision==='Approved') {
                $budgetYear=(int)substr((string)$request['start_date'],0,4);
                $budgetStmt=$conn->prepare('SELECT allocated_amount,currency FROM project_program_budget_lines WHERE entity_type=? AND entity_id=? AND activity_name=? AND fiscal_year=? FOR UPDATE');
                $budgetStmt->bind_param('sisi',$request['entity_type'],$request['entity_id'],$request['activity_name'],$budgetYear);
                $budgetStmt->execute(); $budgetLine=$budgetStmt->get_result()->fetch_assoc(); $budgetStmt->close();
                $committed=0.0;
                if ($budgetLine && strcasecmp((string)$budgetLine['currency'],(string)$request['currency'])===0) {
                    $commitStmt=$conn->prepare("SELECT COALESCE(SUM(requested_amount),0) committed FROM activity_event_requests WHERE entity_type=? AND entity_id=? AND activity_name=? AND currency=? AND YEAR(start_date)=? AND status IN ('Director Approval','Approved') AND request_id<>?");
                    $commitStmt->bind_param('sissii',$request['entity_type'],$request['entity_id'],$request['activity_name'],$request['currency'],$budgetYear,$id);
                    $commitStmt->execute(); $committed=(float)($commitStmt->get_result()->fetch_assoc()['committed']??0); $commitStmt->close();
                }
                if (!$budgetLine || strcasecmp((string)$budgetLine['currency'],(string)$request['currency'])!==0 || $committed+(float)$request['requested_amount']>(float)($budgetLine['allocated_amount']??0)) {
                    $conn->rollback();
                    $budgetOk=false;
                    $notice=!$budgetLine?'No finance budget line exists for this project/program activity and year.':(strcasecmp((string)$budgetLine['currency'],(string)$request['currency'])!==0?'Request currency does not match the activity budget currency.':'Insufficient available budget. Increase the approved allocation or return the request.');
                }
            }
            if (!$budgetOk) { /* finance budget check stopped the approval */ }
            else {
            $stmt=$conn->prepare('INSERT INTO activity_event_request_reviews (request_id,stage,action,comments,reviewed_by) VALUES (?,?,?,?,?)'); $stmt->bind_param('isssi',$id,$stage,$decision,$comments,$uid); $ok=$stmt->execute(); $stmt->close();
            if($ok){$stmt=$conn->prepare('UPDATE activity_event_requests SET status=? WHERE request_id=?');$stmt->bind_param('si',$status,$id);$ok=$stmt->execute();$stmt->close();}
            if($ok){$conn->commit(); notify_user((int)$request['created_by'],'Activity/event request updated',$request['reference_code'].' is now '.$status.'.'.($comments?' Comment: '.$comments:''),$status==='Rejected'?'danger':'info',$id,'activity_event_request');$signers=$conn->prepare('SELECT DISTINCT reviewed_by FROM activity_event_request_reviews WHERE request_id=?');$signers->bind_param('i',$id);$signers->execute();$signedBy=$signers->get_result();while($signer=$signedBy->fetch_assoc())if((int)$signer['reviewed_by']!==$uid&&(int)$signer['reviewed_by']!==(int)$request['created_by'])notify_user((int)$signer['reviewed_by'],'Activity/event request status update',$request['reference_code'].' is now '.$status.'.'.($comments?' Comment: '.$comments:''),$status==='Rejected'?'danger':'info',$id,'activity_event_request');$signers->close();if($status!=='Approved'&&!in_array($status,['Returned','Rejected'],true))$notifyStage($conn,$status,$id,$request['title']); $notice='Review recorded: '.$status.'.';}else{$conn->rollback();$notice='Could not record review.';}
            }
        }
    } elseif (($action === 'budget' || $action === 'budget_import') && $finance) {
        $budgetRows=[];
        if ($action === 'budget') {
            $budgetRows[]=['entity_type'=>(string)($_POST['entity_type']??''),'entity_code'=>trim((string)($_POST['entity_code']??'')),'activity_name'=>trim((string)($_POST['activity_name']??'')),'fiscal_year'=>(string)($_POST['fiscal_year']??''),'currency'=>strtoupper(trim((string)($_POST['currency']??'UGX'))),'allocated_amount'=>(string)($_POST['allocated_amount']??'')];
        } elseif (!isset($_FILES['budget_file']) || $_FILES['budget_file']['error'] !== UPLOAD_ERR_OK || strtolower(pathinfo((string)$_FILES['budget_file']['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $notice='Choose a valid CSV budget file.';
        } else {
            $input=fopen((string)$_FILES['budget_file']['tmp_name'],'rb'); $headers=$input?fgetcsv($input):false;
            $required=['entity_type','entity_code','activity_name','fiscal_year','currency','allocated_amount'];
            if(!$input||!is_array($headers)||array_map(static fn($v)=>strtolower(trim((string)$v)),$headers)!==$required){$notice='CSV headers must be: '.implode(',',$required).'.';}
            else {while(($row=fgetcsv($input))!==false){if(count($row)!==count($required)){$notice='CSV contains a row with the wrong number of columns.';break;}$budgetRows[]=array_combine($required,array_map('trim',$row));if(count($budgetRows)>1000){$notice='Upload is limited to 1000 budget rows.';break;}}}
            if($input)fclose($input);
        }
        if($notice===''&&$budgetRows){$validated=[];foreach($budgetRows as $row){$entity=$row['entity_type'];$code=trim($row['entity_code']);$activity=trim($row['activity_name']);$year=filter_var($row['fiscal_year'],FILTER_VALIDATE_INT);$currency=strtoupper(trim($row['currency']));$allocation=filter_var($row['allocated_amount'],FILTER_VALIDATE_FLOAT);
                if(!in_array($entity,['Project','Program'],true)||$code===''||$activity===''||$year===false||$year<2000||$year>2100||!preg_match('/^[A-Z]{3,10}$/',$currency)||$allocation===false||$allocation<0){$notice='One or more budget rows contain invalid values; no rows were saved.';break;}
                $table=$entity==='Project'?'projects':'programs';$key=$entity==='Project'?'project_id':'id';$codeColumn=$entity==='Project'?'project_code':'program_code';$lookup=$conn->prepare("SELECT {$key} entity_id FROM {$table} WHERE {$codeColumn}=? LIMIT 1");$lookup->bind_param('s',$code);$lookup->execute();$found=$lookup->get_result()->fetch_assoc();$lookup->close();if(!$found){$notice='Unknown '.$entity.' code '.$code.'; no rows were saved.';break;}
                $validated[]=['entity'=>$entity,'entity_id'=>(int)$found['entity_id'],'activity'=>$activity,'year'=>(int)$year,'currency'=>$currency,'allocation'=>(float)$allocation];
            }
            if($notice===''&&$validated){$conn->begin_transaction();try{foreach($validated as $row){$lock=$conn->prepare("SELECT COALESCE(SUM(requested_amount),0) committed FROM activity_event_requests WHERE entity_type=? AND entity_id=? AND activity_name=? AND currency=? AND YEAR(start_date)=? AND status IN ('Director Approval','Approved') FOR UPDATE");$lock->bind_param('sissi',$row['entity'],$row['entity_id'],$row['activity'],$row['currency'],$row['year']);$lock->execute();$committed=(float)($lock->get_result()->fetch_assoc()['committed']??0);$lock->close();if($row['allocation']<$committed)throw new RuntimeException('Allocation for '.$row['activity'].' is below committed spending.');$save=$conn->prepare('INSERT INTO project_program_budget_lines (entity_type,entity_id,activity_name,fiscal_year,currency,allocated_amount,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE currency=VALUES(currency),allocated_amount=VALUES(allocated_amount),updated_by=VALUES(updated_by)');$save->bind_param('sisisdi',$row['entity'],$row['entity_id'],$row['activity'],$row['year'],$row['currency'],$row['allocation'],$uid);if(!$save->execute())throw new RuntimeException('Could not save imported budget lines.');$save->close();}$conn->commit();$notice=count($validated).' budget line(s) imported.';}catch(Throwable $e){$conn->rollback();$notice=$e instanceof RuntimeException?$e->getMessage():'Budget import failed; no rows were saved.';}}
        }
    } elseif ($action === 'remind') {
        $id=(int)($_POST['request_id']??0);$stmt=$conn->prepare('SELECT request_id,reference_code,title,created_by,status FROM activity_event_requests WHERE request_id=?');$stmt->bind_param('i',$id);$stmt->execute();$request=$stmt->get_result()->fetch_assoc();$stmt->close();
        $stageAccess=['Supervisor Review'=>$ops,'Procurement Review'=>$procurement,'Finance Review'=>$finance,'Director Approval'=>$director];
        if(!$request||!isset($stageAccess[$request['status']])||(!($stageAccess[$request['status']])&&(int)$request['created_by']!==$uid))$notice='You cannot send a reminder for this request.';
        else{$stage=$request['status'];$check=$conn->prepare('SELECT COUNT(*) n FROM activity_event_request_reminders WHERE request_id=? AND stage=? AND sent_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)');$check->bind_param('is',$id,$stage);$check->execute();$recent=(int)($check->get_result()->fetch_assoc()['n']??0);$check->close();
            if($recent>0)$notice='A reminder was already sent for this stage within the last hour.';
            else{$save=$conn->prepare('INSERT INTO activity_event_request_reminders (request_id,stage,sent_by) VALUES (?,?,?)');$save->bind_param('isi',$id,$stage,$uid);if(!$save->execute())$notice='Unable to record reminder. Please try again.';else{$groups=['Supervisor Review'=>['Operations/Admin','Chief of Operations'],'Procurement Review'=>['Procurement Officer'],'Finance Review'=>['Finance','Accountant'],'Director Approval'=>['Executive Director','Director','CEO']];$notified=[];foreach($groups[$stage] as $group){$people=$conn->prepare('SELECT user_id FROM users WHERE role=?');$people->bind_param('s',$group);$people->execute();$recipients=$people->get_result();while($person=$recipients->fetch_assoc()){$recipient=(int)$person['user_id'];if($recipient!==$uid&&!isset($notified[$recipient])){notify_user($recipient,'Activity/event request reminder',$request['reference_code'].' is waiting at '.$stage.'.',$stage==='Finance Review'?'warning':'info',$id,'activity_event_request');$notified[$recipient]=true;}}$people->close();}if((int)$request['created_by']!==$uid)notify_user((int)$request['created_by'],'Activity/event request reminder','A reminder was sent for '.$request['reference_code'].' at '.$stage.'.','info',$id,'activity_event_request');$notice='Reminder sent for '.$stage.'.';}$save->close();}
        }
    }
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$projects=$conn->query('SELECT project_id id, project_name name, currency FROM projects ORDER BY project_name');$programs=$conn->query('SELECT id, program_name name, currency FROM programs ORDER BY program_name');
$milestones=$conn->query('SELECT milestone_id,entity_type,entity_id,milestone_name FROM milestones ORDER BY due_date DESC');
$budgetLines=$finance?$conn->query("SELECT b.*,CASE WHEN b.entity_type='Project' THEN p.project_code ELSE pr.program_code END entity_code,CASE WHEN b.entity_type='Project' THEN p.project_name ELSE pr.program_name END entity_name,COALESCE((SELECT SUM(r.requested_amount) FROM activity_event_requests r WHERE r.entity_type=b.entity_type AND r.entity_id=b.entity_id AND r.activity_name=b.activity_name AND r.currency=b.currency AND YEAR(r.start_date)=b.fiscal_year AND r.status IN ('Director Approval','Approved')),0) committed_amount FROM project_program_budget_lines b LEFT JOIN projects p ON b.entity_type='Project' AND p.project_id=b.entity_id LEFT JOIN programs pr ON b.entity_type='Program' AND pr.id=b.entity_id ORDER BY b.entity_type,b.entity_id,b.fiscal_year DESC,b.activity_name"):false;
$budgetProjects=$finance?$conn->query('SELECT project_code code,project_name name FROM projects ORDER BY project_name'):false;
$budgetPrograms=$finance?$conn->query('SELECT program_code code,program_name name FROM programs ORDER BY program_name'):false;
$stmt=$conn->prepare('SELECT r.*,u.full_name requester, m.milestone_name FROM activity_event_requests r LEFT JOIN users u ON u.user_id=r.created_by LEFT JOIN milestones m ON m.milestone_id=r.milestone_id WHERE (?=1 OR r.created_by=?) ORDER BY r.created_at DESC');$all=(int)($finance||$ops||$procurement||$director);$stmt->bind_param('ii',$all,$uid);$stmt->execute();$requests=$stmt->get_result();
$requestRows=$requests->fetch_all(MYSQLI_ASSOC);
$requestStats=['total'=>count($requestRows),'finance'=>0,'director'=>0,'approved'=>0];foreach($requestRows as $r){if($r['status']==='Finance Review')$requestStats['finance']++;if($r['status']==='Director Approval')$requestStats['director']++;if($r['status']==='Approved')$requestStats['approved']++;}
$budgetStats=['lines'=>0,'allocated'=>0.0,'committed'=>0.0];if($finance&&$budgetLines){foreach($budgetLines as $b){$budgetStats['lines']++;if($b['currency']==='UGX'){$budgetStats['allocated']+=(float)$b['allocated_amount'];$budgetStats['committed']+=(float)$b['committed_amount'];}}$budgetLines->data_seek(0);}
function aer_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<style>
.aer-page{max-width:1280px;margin:0 auto;padding:24px;display:grid;gap:24px}.aer-page h1,.aer-page h2,.aer-page h3{color:var(--ink-900)}.aer-page section,.aer-request{background:var(--surface,#fff);border:1px solid var(--line,#dce3ea);border-radius:14px;padding:24px;box-shadow:0 4px 18px rgba(24,39,56,.06)}.aer-page form{display:flex;flex-wrap:wrap;align-items:end;gap:14px;margin-top:16px}.aer-page label{display:grid;gap:6px;flex:1 1 210px;font-weight:600;color:var(--ink-700)}.aer-page input,.aer-page select,.aer-page textarea{width:100%;min-height:42px;padding:9px 12px;border:1px solid var(--line,#cbd5e1);border-radius:8px;background:#fff;color:var(--ink-900);font:inherit}.aer-page textarea{min-height:110px}.aer-page button,.aer-button{min-height:42px;padding:9px 16px;border:0;border-radius:8px;background:var(--brand-600,#1769aa);color:#fff;font:600 14px inherit;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.aer-page button:hover,.aer-button:hover{filter:brightness(.94)}.aer-page .aer-secondary{background:#eaf1f7;color:var(--ink-900)}.aer-budget-tools{display:grid;gap:24px}.aer-budget-tools form{align-items:end}.aer-budget-list{padding-left:20px;display:grid;gap:8px}.aer-request{display:grid;gap:10px}.aer-request p{margin:0;line-height:1.55}.aer-status{display:inline-flex;padding:4px 10px;border-radius:999px;background:#eaf1f7;font-size:.86rem;font-weight:700}.aer-inline{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.aer-page .alert{padding:14px 18px;border-radius:9px}.aer-help{color:var(--ink-500,#64748b);margin:.35rem 0}.aer-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}.aer-stat{padding:18px;border:1px solid var(--line,#dce3ea);border-radius:12px;background:#fff}.aer-stat span{display:block;color:var(--ink-500,#64748b);font-size:.88rem}.aer-stat strong{display:block;margin-top:6px;font-size:1.5rem;color:var(--ink-900)}.aer-table-wrap{width:100%;overflow:auto}.aer-table{width:100%;border-collapse:collapse;min-width:900px}.aer-table th,.aer-table td{padding:12px;border-bottom:1px solid var(--line,#dce3ea);text-align:left;vertical-align:top}.aer-table th{background:#f4f7fa;color:var(--ink-700);font-size:.82rem;text-transform:uppercase}.aer-dialog{width:min(900px,calc(100vw - 24px));max-height:90vh;overflow:auto;border:0;border-radius:16px;padding:26px;box-shadow:0 20px 60px #0004}.aer-dialog::backdrop{background:#0f172a99}.aer-dialog form{margin-top:18px}.aer-dialog-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.aer-dialog-head button{background:#eaf1f7;color:var(--ink-900)}
@media(max-width:700px){.aer-page{padding:14px}.aer-page section,.aer-request{padding:16px}.aer-page button,.aer-button{width:100%}}
</style>
<div class="aer-page"><h1>Activity/Event Requests</h1>
<?php if($notice!==''):?><p role="status" class="alert alert-info"><?=aer_h($notice)?></p><?php endif;?>
<?php if($finance):?><div class="aer-stats"><div class="aer-stat"><span>Budget lines</span><strong><?=$budgetStats['lines']?></strong></div><div class="aer-stat"><span>Allocated UGX</span><strong><?=number_format($budgetStats['allocated'],0)?></strong></div><div class="aer-stat"><span>Committed UGX</span><strong><?=number_format($budgetStats['committed'],0)?></strong></div><div class="aer-stat"><span>Available UGX</span><strong><?=number_format($budgetStats['allocated']-$budgetStats['committed'],0)?></strong></div><div class="aer-stat"><span>Awaiting Finance</span><strong><?=$requestStats['finance']?></strong></div><div class="aer-stat"><span>Awaiting Director</span><strong><?=$requestStats['director']?></strong></div></div><?php endif;?>
<section><div class="aer-dialog-head"><div><h2>Activity/Event Requests</h2><p class="aer-help">Track request progress and review decisions.</p></div><button type="button" class="aer-button" id="openRequestModal">New request</button></div></section>
<dialog class="aer-dialog" id="requestModal"><div class="aer-dialog-head"><h2>Submit an activity/event concept</h2><button type="button" id="closeRequestModal" aria-label="Close">Close</button></div>
<form method="post"><input type="hidden" name="csrf_token" value="<?=aer_h($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="submit">
<label>Title <input required name="title" maxlength="255"></label> <label>Type <select name="event_type"><option>Activity</option><option>Event</option><option>Training</option><option>Workshop</option></select></label><br>
<label>Project/program <select required name="entity_type" id="entityType"><option value="">Choose type</option><option>Project</option><option>Program</option></select></label> <select required name="entity_id" id="entityId"><option value="">Choose project/program</option><?php foreach(['Project'=>$projects,'Program'=>$programs] as $kind=>$rows)if($rows)while($row=$rows->fetch_assoc()):?><option data-type="<?=$kind?>" value="<?=$row['id']?>"><?=aer_h($row['name'])?></option><?php endwhile;?></select>
<label>Workplan milestone <select required name="milestone_id" id="milestoneId"><option value="">Choose milestone</option><?php if($milestones)while($m=$milestones->fetch_assoc()):?><option data-type="<?=aer_h($m['entity_type'])?>" data-entity="<?=$m['entity_id']?>" value="<?=$m['milestone_id']?>"><?=aer_h($m['milestone_name'])?></option><?php endwhile;?></select></label><br>
<label>Workplan activity <input required name="activity_name" maxlength="255"></label> <label>Start <input required type="date" name="start_date"></label> <label>End <input type="date" name="end_date"></label><br>
<label>Location <input name="location" maxlength="255"></label> <label>Participants <input type="number" min="0" name="participants" value="0"></label><br>
<label>Estimated amount <input required type="number" min="0.01" step="0.01" name="requested_amount"></label> <label>Currency <input required name="currency" value="UGX" maxlength="10"></label><br>
<label>Concept and justification<br><textarea required name="concept" rows="5" style="width:100%"></textarea></label><div class="aer-inline"><button type="submit">Submit concept</button><button class="aer-secondary" type="button" id="cancelRequestModal">Cancel</button></div></form></dialog>
<?php if($finance):?><section class="aer-budget-tools"><div><h2>Manage project/program budgets</h2><p class="aer-help">Use official project/program codes. Saving an existing activity and fiscal year edits that budget line.</p><div class="aer-inline"><a class="aer-button aer-secondary" href="activity-event-requests?budget_template=1"><i class="fas fa-download"></i>&nbsp; Download CSV template</a></div>
<form method="post"><input type="hidden" name="csrf_token" value="<?=aer_h($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="budget"><label>Entity type<select name="entity_type" required><option>Project</option><option>Program</option></select></label><label>Project/program code<select name="entity_code" required><option value="">Select code</option><?php if($budgetProjects)while($p=$budgetProjects->fetch_assoc()):?><option data-type="Project" value="<?=aer_h($p['code'])?>">Project · <?=aer_h($p['code'])?> — <?=aer_h($p['name'])?></option><?php endwhile;?><?php if($budgetPrograms)while($p=$budgetPrograms->fetch_assoc()):?><option data-type="Program" value="<?=aer_h($p['code'])?>">Program · <?=aer_h($p['code'])?> — <?=aer_h($p['name'])?></option><?php endwhile;?></select></label><label>Activity/budget line<input required name="activity_name" maxlength="255"></label><label>Fiscal year<input required type="number" min="2000" max="2100" name="fiscal_year" value="<?=date('Y')?>"></label><label>Currency<input required name="currency" value="UGX" maxlength="10"></label><label>Allocated amount<input required type="number" min="0" step="0.01" name="allocated_amount"></label><button type="submit">Add / update budget</button></form>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=aer_h($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="budget_import"><label>Upload budget CSV<input required type="file" name="budget_file" accept=".csv,text/csv"></label><button type="submit">Upload and import</button></form><p class="aer-help">CSV must include: entity_type, entity_code, activity_name, fiscal_year, currency, allocated_amount. Maximum 1,000 rows; all rows validate before saving.</p></div>
<div><h3>Current budget lines</h3><div class="aer-table-wrap"><table class="aer-table"><thead><tr><th>Project/program code</th><th>Activity</th><th>Year</th><th>Currency</th><th>Allocated</th><th>Committed</th><th>Available</th><th></th></tr></thead><tbody><?php if(!$budgetLines||$budgetLines->num_rows===0):?><tr><td colspan="8">No budget lines set up.</td></tr><?php endif;?><?php if($budgetLines)while($b=$budgetLines->fetch_assoc()):$remaining=(float)$b['allocated_amount']-(float)$b['committed_amount'];?><tr><td><strong><?=aer_h($b['entity_type'])?> <?=aer_h($b['entity_code']??'')?></strong><br><?=aer_h($b['entity_name']??'')?></td><td><?=aer_h($b['activity_name'])?></td><td><?=$b['fiscal_year']?></td><td><?=aer_h($b['currency'])?></td><td><?=number_format((float)$b['allocated_amount'],2)?></td><td><?=number_format((float)$b['committed_amount'],2)?></td><td><?=number_format($remaining,2)?></td><td><button type="button" class="aer-secondary aer-edit-budget" data-type="<?=aer_h($b['entity_type'])?>" data-code="<?=aer_h($b['entity_code']??'')?>" data-activity="<?=aer_h($b['activity_name'])?>" data-year="<?=$b['fiscal_year']?>" data-currency="<?=aer_h($b['currency'])?>" data-allocation="<?=$b['allocated_amount']?>">Edit</button></td></tr><?php endwhile;?></tbody></table></div></div></section><?php endif;?>
<section><h2>Requests and approval status</h2><div class="aer-table-wrap"><table class="aer-table"><thead><tr><th>Reference</th><th>Request</th><th>Project/program</th><th>Activity & milestone</th><th>Amount</th><th>Status</th><th>Requester / date</th><th>Review</th></tr></thead><tbody><?php if(!$requestRows):?><tr><td colspan="8">No requests found.</td></tr><?php endif;?><?php foreach($requestRows as $r):?><tr><td><?=aer_h($r['reference_code'])?></td><td><strong><?=aer_h($r['title'])?></strong><details><summary>Concept and history</summary><p><?=nl2br(aer_h($r['concept']))?></p><?php $timeline=$conn->prepare('SELECT stage,action,comments,reviewed_at FROM activity_event_request_reviews WHERE request_id=? ORDER BY reviewed_at');$timeline->bind_param('i',$r['request_id']);$timeline->execute();$logs=$timeline->get_result();while($log=$logs->fetch_assoc()):?><p><small><?=aer_h($log['stage'])?>: <?=aer_h($log['action'])?><?=($log['comments']??'')!==''?' — '.aer_h($log['comments']):''?> (<?=aer_h($log['reviewed_at'])?>)</small></p><?php endwhile;$timeline->close();?></details></td><td><?=aer_h($r['entity_type'])?> #<?=$r['entity_id']?></td><td><?=aer_h($r['activity_name'])?><br><small><?=aer_h($r['milestone_name']??'Milestone')?></small></td><td><?=aer_h($r['currency'])?> <?=number_format((float)$r['requested_amount'],2)?></td><td><span class="aer-status"><?=aer_h($r['status'])?></span></td><td><?=aer_h($r['requester']??'Staff')?><br><small><?=aer_h($r['created_at'])?></small></td><td><?php $stageRoles=['Supervisor Review'=>$ops,'Procurement Review'=>$procurement,'Finance Review'=>$finance,'Director Approval'=>$director];if(!empty($stageRoles[$r['status']])):?><form method="post" class="aer-review-form"><input type="hidden" name="csrf_token" value="<?=aer_h($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="review"><input type="hidden" name="request_id" value="<?=$r['request_id']?>"><textarea name="comments" placeholder="Comments (required for return/rejection)"></textarea><button name="decision" value="Approved">Approve</button><button class="aer-secondary" name="decision" value="Returned">Return</button><button class="aer-secondary" name="decision" value="Rejected">Reject</button></form><?php endif;?><?php if((!empty($stageRoles[$r['status']])||(int)$r['created_by']===$uid)&&in_array($r['status'],['Supervisor Review','Procurement Review','Finance Review','Director Approval'],true)):?><form method="post" class="aer-inline"><input type="hidden" name="csrf_token" value="<?=aer_h($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="remind"><input type="hidden" name="request_id" value="<?=$r['request_id']?>"><button type="submit" class="aer-secondary">Send reminder</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
<script>
const entityType=document.getElementById('entityType'),entityId=document.getElementById('entityId'),milestone=document.getElementById('milestoneId');
function filterLinks(){const type=entityType.value,id=entityId.value;for(const o of entityId.options)if(o.value)o.hidden=o.dataset.type!==type;for(const o of milestone.options)if(o.value)o.hidden=o.dataset.type!==type||(id&&o.dataset.entity!==id);}
entityType.addEventListener('change',filterLinks);entityId.addEventListener('change',filterLinks);filterLinks();
</script>
<script>
const budgetForm=document.querySelector('.aer-budget-tools form input[name="action"][value="budget"]')?.closest('form'),budgetType=budgetForm?.querySelector('select[name="entity_type"]'),budgetCode=budgetForm?.querySelector('select[name="entity_code"]');
if(budgetType&&budgetCode){function filterBudgetCodes(){for(const option of budgetCode.options)if(option.value)option.hidden=option.dataset.type!==budgetType.value;}budgetType.addEventListener('change',filterBudgetCodes);filterBudgetCodes();}
document.querySelectorAll('.aer-edit-budget').forEach(button=>button.addEventListener('click',()=>{const form=document.querySelector('.aer-budget-tools form input[name="action"][value="budget"]')?.closest('form');if(!form)return;form.querySelector('[name="entity_type"]').value=button.dataset.type;filterBudgetCodes();form.querySelector('[name="entity_code"]').value=button.dataset.code;form.querySelector('[name="activity_name"]').value=button.dataset.activity;form.querySelector('[name="fiscal_year"]').value=button.dataset.year;form.querySelector('[name="currency"]').value=button.dataset.currency;form.querySelector('[name="allocated_amount"]').value=button.dataset.allocation;form.scrollIntoView({behavior:'smooth',block:'center'});}));
const requestModal=document.getElementById('requestModal');document.getElementById('openRequestModal').addEventListener('click',()=>requestModal.showModal());document.getElementById('closeRequestModal').addEventListener('click',()=>requestModal.close());document.getElementById('cancelRequestModal').addEventListener('click',()=>requestModal.close());
</script>
</div><?php $stmt->close(); require_once __DIR__ . '/includes/footer.php'; ?>
