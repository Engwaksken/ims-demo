<?php
declare(strict_types=1);
$page_title = 'Activity/Event Requests';
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
            if($ok){$conn->commit(); notify_user((int)$request['created_by'],'Activity/event request updated',$request['reference_code'].' is now '.$status.'.'.($comments?' Comment: '.$comments:''),$status==='Rejected'?'danger':'info',$id,'activity_event_request'); if($status!=='Approved'&&!in_array($status,['Returned','Rejected'],true))$notifyStage($conn,$status,$id,$request['title']); $notice='Review recorded: '.$status.'.';}else{$conn->rollback();$notice='Could not record review.';}
            }
        }
    } elseif ($action === 'budget' && $finance) {
        $entity=(string)($_POST['entity_type']??'');$entityId=(int)($_POST['entity_id']??0);$activity=trim((string)($_POST['activity_name']??''));$year=(int)($_POST['fiscal_year']??date('Y'));$currency=strtoupper(trim((string)($_POST['currency']??'UGX')));$allocation=filter_var($_POST['allocated_amount']??null,FILTER_VALIDATE_FLOAT);
        $exists=false;
        if (in_array($entity,['Project','Program'],true)&&$entityId>0) { $table=$entity==='Project'?'projects':'programs';$key=$entity==='Project'?'project_id':'id';$check=$conn->prepare("SELECT {$key} FROM {$table} WHERE {$key}=?");$check->bind_param('i',$entityId);$check->execute();$exists=$check->get_result()->num_rows>0;$check->close(); }
        if($exists&&$activity!==''&&$year>=2000&&$year<=2100&&$allocation!==false&&$allocation>=0&&preg_match('/^[A-Z]{3,10}$/',$currency)){$commit=$conn->prepare("SELECT COALESCE(SUM(requested_amount),0) committed FROM activity_event_requests WHERE entity_type=? AND entity_id=? AND activity_name=? AND currency=? AND YEAR(start_date)=? AND status IN ('Director Approval','Approved')");$commit->bind_param('sissi',$entity,$entityId,$activity,$currency,$year);$commit->execute();$committed=(float)($commit->get_result()->fetch_assoc()['committed']??0);$commit->close();if($allocation<$committed){$notice='Allocation cannot be lower than the committed amount ('.number_format($committed,2).').';}else{$stmt=$conn->prepare('INSERT INTO project_program_budget_lines (entity_type,entity_id,activity_name,fiscal_year,currency,allocated_amount,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE currency=VALUES(currency),allocated_amount=VALUES(allocated_amount),updated_by=VALUES(updated_by)');$stmt->bind_param('sisisdi',$entity,$entityId,$activity,$year,$currency,$allocation,$uid);$notice=$stmt->execute()?'Budget line saved.':'Unable to save budget line.';$stmt->close();}}else $notice='Provide a valid project/program, activity, year, currency, and allocation.';
    }
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$projects=$conn->query('SELECT project_id id, project_name name, currency FROM projects ORDER BY project_name');$programs=$conn->query('SELECT id, program_name name, currency FROM programs ORDER BY program_name');
$milestones=$conn->query('SELECT milestone_id,entity_type,entity_id,milestone_name FROM milestones ORDER BY due_date DESC');
$budgetLines=$finance?$conn->query("SELECT b.*,COALESCE((SELECT SUM(r.requested_amount) FROM activity_event_requests r WHERE r.entity_type=b.entity_type AND r.entity_id=b.entity_id AND r.activity_name=b.activity_name AND r.currency=b.currency AND YEAR(r.start_date)=b.fiscal_year AND r.status IN ('Director Approval','Approved')),0) committed_amount FROM project_program_budget_lines b ORDER BY b.entity_type,b.entity_id,b.fiscal_year DESC,b.activity_name"):false;
$stmt=$conn->prepare('SELECT r.*,u.full_name requester, m.milestone_name FROM activity_event_requests r LEFT JOIN users u ON u.user_id=r.created_by LEFT JOIN milestones m ON m.milestone_id=r.milestone_id WHERE (?=1 OR r.created_by=?) ORDER BY r.created_at DESC');$all=(int)($finance||$ops||$procurement||$director);$stmt->bind_param('ii',$all,$uid);$stmt->execute();$requests=$stmt->get_result();
function aer_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<main class="container" style="max-width:1200px;margin:2rem auto;padding:0 1rem"><h1>Activity/Event Requests</h1>
<?php if($notice!==''):?><p role="status" class="alert alert-info"><?=aer_h($notice)?></p><?php endif;?>
<section class="card" style="padding:1.25rem;margin:1rem 0"><h2>Submit a concept</h2><p>Link your request to an approved project/program and its workplan milestone. Finance checks budget availability before CEO approval.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?=aer_h($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="submit">
<label>Title <input required name="title" maxlength="255"></label> <label>Type <select name="event_type"><option>Activity</option><option>Event</option><option>Training</option><option>Workshop</option></select></label><br>
<label>Project/program <select required name="entity_type" id="entityType"><option value="">Choose type</option><option>Project</option><option>Program</option></select></label> <select required name="entity_id" id="entityId"><option value="">Choose project/program</option><?php foreach(['Project'=>$projects,'Program'=>$programs] as $kind=>$rows)if($rows)while($row=$rows->fetch_assoc()):?><option data-type="<?=$kind?>" value="<?=$row['id']?>"><?=aer_h($row['name'])?></option><?php endwhile;?></select>
<label>Workplan milestone <select required name="milestone_id" id="milestoneId"><option value="">Choose milestone</option><?php if($milestones)while($m=$milestones->fetch_assoc()):?><option data-type="<?=aer_h($m['entity_type'])?>" data-entity="<?=$m['entity_id']?>" value="<?=$m['milestone_id']?>"><?=aer_h($m['milestone_name'])?></option><?php endwhile;?></select></label><br>
<label>Workplan activity <input required name="activity_name" maxlength="255"></label> <label>Start <input required type="date" name="start_date"></label> <label>End <input type="date" name="end_date"></label><br>
<label>Location <input name="location" maxlength="255"></label> <label>Participants <input type="number" min="0" name="participants" value="0"></label><br>
<label>Estimated amount <input required type="number" min="0.01" step="0.01" name="requested_amount"></label> <label>Currency <input required name="currency" value="UGX" maxlength="10"></label><br>
<label>Concept and justification<br><textarea required name="concept" rows="5" style="width:100%"></textarea></label><button type="submit">Submit concept</button></form></section>
<?php if($finance):?><section class="card" style="padding:1.25rem;margin:1rem 0"><h2>Manage project/program activity budgets</h2><p>Finance-approved requests awaiting Director approval and fully approved requests count as commitments. Available funds are reserved during Finance review.</p><form method="post"><input type="hidden" name="csrf_token" value="<?=aer_h($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="budget"><select name="entity_type"><option>Project</option><option>Program</option></select><input required type="number" min="1" name="entity_id" placeholder="Project/program ID"><input required name="activity_name" placeholder="Budget activity"><input required type="number" name="fiscal_year" value="<?=date('Y')?>"><input required name="currency" value="UGX"><input required type="number" min="0" step="0.01" name="allocated_amount" placeholder="Allocation"><button>Save budget line</button></form><ul><?php if($budgetLines)while($b=$budgetLines->fetch_assoc()):$remaining=(float)$b['allocated_amount']-(float)$b['committed_amount'];?><li><?=aer_h($b['entity_type'])?> #<?=$b['entity_id']?> — <?=aer_h($b['activity_name'])?> (<?=$b['fiscal_year']?>): allocated <?=aer_h($b['currency'])?> <?=number_format((float)$b['allocated_amount'],2)?> · committed <?=number_format((float)$b['committed_amount'],2)?> · available <?=number_format($remaining,2)?></li><?php endwhile;?></ul></section><?php endif;?>
<section><h2>Requests and approval status</h2><?php if($requests->num_rows===0):?><p>No requests found.</p><?php endif;?><?php while($r=$requests->fetch_assoc()):?><article class="card" style="padding:1.25rem;margin:1rem 0"><h3><?=aer_h($r['reference_code'])?> — <?=aer_h($r['title'])?></h3><p><b><?=aer_h($r['status'])?></b> · <?=aer_h($r['entity_type'])?> #<?=$r['entity_id']?> · <?=aer_h($r['milestone_name']??'Milestone')?> / <?=aer_h($r['activity_name'])?></p><p><?=nl2br(aer_h($r['concept']))?></p><p><?=aer_h($r['currency'])?> <?=number_format((float)$r['requested_amount'],2)?> · Submitted by <?=aer_h($r['requester']??'Staff')?></p>
<?php $timeline=$conn->prepare('SELECT stage,action,comments,reviewed_at FROM activity_event_request_reviews WHERE request_id=? ORDER BY reviewed_at');$timeline->bind_param('i',$r['request_id']);$timeline->execute();$logs=$timeline->get_result();while($log=$logs->fetch_assoc()):?><p><small><?=aer_h($log['stage'])?>: <?=aer_h($log['action'])?><?=($log['comments']??'')!==''?' — '.aer_h($log['comments']):''?> (<?=aer_h($log['reviewed_at'])?>)</small></p><?php endwhile;$timeline->close();?>
<?php $stageRoles=['Supervisor Review'=>$ops,'Procurement Review'=>$procurement,'Finance Review'=>$finance,'Director Approval'=>$director];if(!empty($stageRoles[$r['status']])):?><form method="post"><input type="hidden" name="csrf_token" value="<?=aer_h($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="review"><input type="hidden" name="request_id" value="<?=$r['request_id']?>"><textarea name="comments" placeholder="Comments (required for return/rejection)"></textarea><button name="decision" value="Approved">Approve / sign</button><button name="decision" value="Returned">Return for changes</button><button name="decision" value="Rejected">Reject</button></form><?php endif;?></article><?php endwhile;?></section></main>
<script>
const entityType=document.getElementById('entityType'),entityId=document.getElementById('entityId'),milestone=document.getElementById('milestoneId');
function filterLinks(){const type=entityType.value,id=entityId.value;for(const o of entityId.options)if(o.value)o.hidden=o.dataset.type!==type;for(const o of milestone.options)if(o.value)o.hidden=o.dataset.type!==type||(id&&o.dataset.entity!==id);}
entityType.addEventListener('change',filterLinks);entityId.addEventListener('change',filterLinks);filterLinks();
</script>
<?php $stmt->close(); require_once __DIR__ . '/includes/footer.php'; ?>
