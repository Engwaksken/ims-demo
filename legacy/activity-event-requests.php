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
$signatureSchemaReady=(bool)$conn->query('SELECT deliverable_id,requester_signature,requester_signature_mime FROM activity_event_requests LIMIT 0')&&(bool)$conn->query('SELECT signature_data,signature_mime FROM activity_event_request_reviews LIMIT 0');
$uid = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role_name'] ?? $_SESSION['role'] ?? '');
$finance = in_array($role, ['Administrator','Finance','Accountant'], true);
$ops = in_array($role, ['Administrator','Operations/Admin','Chief of Operations'], true);
$procurement = in_array($role, ['Administrator','Procurement Officer'], true);
$director = in_array($role, ['Administrator','Executive Director','Director','CEO'], true);
$notice = '';
if(!$signatureSchemaReady)$notice='Request signatures are not configured yet. Ask an administrator to apply the activity request deliverable and signature migrations.';
$readSignature=static function(string $field='signature_data',string $upload='signature_file'):array {
    if(isset($_FILES[$upload])&&($_FILES[$upload]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$file=$_FILES[$upload];if($file['error']!==UPLOAD_ERR_OK||$file['size']>2*1024*1024)throw new InvalidArgumentException('Signature upload must be a PNG or JPEG image no larger than 2 MB.');$bytes=file_get_contents($file['tmp_name']);$image=$bytes===false?false:@getimagesizefromstring($bytes);if(!$image||!in_array($image['mime']??'', ['image/png','image/jpeg'],true))throw new InvalidArgumentException('Signature upload must be a valid PNG or JPEG image.');return ['data'=>$bytes,'mime'=>$image['mime']];}
    $encoded=(string)($_POST[$field]??'');if(!preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#',$encoded,$matches))throw new InvalidArgumentException('Please draw or upload your signature before continuing.');$bytes=base64_decode($matches[2],true);$image=$bytes===false?false:@getimagesizefromstring($bytes);if(!$bytes||strlen($bytes)>2*1024*1024||!$image||!in_array($image['mime']??'', ['image/png','image/jpeg'],true))throw new InvalidArgumentException('The drawn signature is invalid. Please sign again or upload a PNG/JPEG image.');return ['data'=>$bytes,'mime'=>$image['mime']];
};
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
/** Reads the posted budget item rows. Returns [items, total, error message]. */
function aer_read_budget_items(): array {
    $items=[];$total=0.0;$names=$_POST['budget_item']??[];$quantities=$_POST['budget_quantity']??[];$unitCosts=$_POST['budget_unit_cost']??[];
    if(!is_array($names)||!is_array($quantities)||!is_array($unitCosts)||count($names)>100)return [[],0.0,'Enter a valid budget breakdown with no more than 100 items.'];
    foreach($names as $index=>$rawName){
        $name=trim((string)$rawName);$qty=filter_var($quantities[$index]??null,FILTER_VALIDATE_FLOAT);$unit=filter_var($unitCosts[$index]??null,FILTER_VALIDATE_FLOAT);
        if($name===''&&($qty===false||$unit===false||$qty<=0||$unit<0))continue;
        if($name===''||$qty===false||$qty<=0||$unit===false||$unit<0)return [[],0.0,'Complete each budget item with a description, quantity, and valid unit cost.'];
        $lineTotal=round((float)$qty*(float)$unit,2);$total+=$lineTotal;$items[]=['name'=>$name,'quantity'=>(float)$qty,'unit_cost'=>(float)$unit,'total'=>$lineTotal];
    }
    return $items?[$items,$total,'']:[[],0.0,'Add at least one budget item.'];
}
/** Builds the stored concept note text from its sections. */
function aer_compose_concept(string $background,string $goal,string $objectives,string $outcomes,array $items): string {
    return trim($background."\n\n2. Goal and Objectives\nGoal: ".$goal."\nObjectives: ".$objectives."\n\n4. Expected Outcomes\n".$outcomes."\n\n5. Budget Lines\n".implode("\n",array_map(static fn($item)=>$item['name'].' — '.$item['quantity'].' × '.number_format($item['unit_cost'],2).' = '.number_format($item['total'],2),$items)));
}
/** Splits a stored concept note back into its sections. Older free-text notes go entirely into the background. */
function aer_parse_concept(string $concept): array {
    $parts=['background'=>$concept,'goal'=>'','objectives'=>'','outcomes'=>'','items'=>[]];
    if(!preg_match('/^(.*?)\n\n2\. Goal and Objectives\nGoal: (.*?)\nObjectives: (.*?)\n\n4\. Expected Outcomes\n(.*?)\n\n5\. Budget Lines\n?(.*)$/su',$concept,$m))return $parts;
    $parts=['background'=>trim($m[1]),'goal'=>trim($m[2]),'objectives'=>trim($m[3]),'outcomes'=>trim($m[4]),'items'=>[]];
    foreach(preg_split('/\R/u',trim($m[5]))?:[] as $line){
        if(preg_match('/^(.+) — ([\d.]+) × ([\d,]+(?:\.\d+)?) = [\d,]+(?:\.\d+)?$/u',trim($line),$item))$parts['items'][]=['name'=>$item[1],'quantity'=>(float)$item[2],'unit_cost'=>(float)str_replace(',','',$item[3])];
    }
    return $parts;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) { http_response_code(419); exit('Invalid form token. Refresh and try again.'); }
    $action = (string)($_POST['action'] ?? '');
    try {
    if (!$signatureSchemaReady && in_array($action,['submit','resubmit','review'],true)) {
        $notice='Request signatures are not configured yet. Ask an administrator to apply the activity request deliverable and signature migrations.';
    }
    elseif ($action === 'submit') {
        $title = trim((string)($_POST['title'] ?? '')); $concept = trim((string)($_POST['concept'] ?? ''));
        $goal=trim((string)($_POST['goal']??''));$objectives=trim((string)($_POST['objectives']??''));$outcomes=trim((string)($_POST['expected_outcomes']??''));
        [$budgetItems,$budgetTotal,$notice]=aer_read_budget_items();
        if($notice===""){$concept=aer_compose_concept($concept,$goal,$objectives,$outcomes,$budgetItems);}
        $entity = (string)($_POST['entity_type'] ?? ''); $entityId = (int)($_POST['entity_id'] ?? 0); $milestone = (int)($_POST['milestone_id'] ?? 0);$deliverable=(int)($_POST['deliverable_id']??0);
        $activity = ''; $start = (string)($_POST['start_date'] ?? '');
        $amount = $budgetTotal; $currency = strtoupper(trim((string)($_POST['currency'] ?? 'UGX')));
        if ($notice!=='' ) { /* invalid budget breakdown was already reported */ }
        elseif ($title === '' || $concept === '' || $goal === '' || $objectives === '' || $outcomes === '' || !in_array($entity, ['Project','Program'], true) || $entityId < 1 || $milestone < 1 || $deliverable < 1 || $amount <= 0 || !preg_match('/^[A-Z]{3,10}$/', $currency) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $notice = 'Complete all required fields with a valid date and amount.';
        } else {
            $entityTable = $entity === 'Project' ? 'projects' : 'programs'; $entityKey = $entity === 'Project' ? 'project_id' : 'id';
            $check = $conn->prepare("SELECT {$entityKey} FROM {$entityTable} WHERE {$entityKey} = ?"); $check->bind_param('i', $entityId); $check->execute(); $exists = $check->get_result()->num_rows > 0; $check->close();
            $check = $conn->prepare('SELECT milestone_id FROM milestones WHERE milestone_id = ? AND entity_type = ? AND entity_id = ?'); $check->bind_param('isi', $milestone, $entity, $entityId); $check->execute(); $milestoneExists = $check->get_result()->num_rows > 0; $check->close();
            $check=$conn->prepare('SELECT deliverable_title FROM milestone_deliverables WHERE deliverable_id=? AND milestone_id=?');$check->bind_param('ii',$deliverable,$milestone);$check->execute();$activityRow=$check->get_result()->fetch_assoc();$check->close();$activity=(string)($activityRow['deliverable_title']??'');
            if (!$exists || !$milestoneExists || $activity==='') $notice = 'Choose a valid project/program, milestone, and activity from that milestone.';
            else {
                $signature=$readSignature();
                $ref = 'ACT-' . strtoupper(bin2hex(random_bytes(4)));
                $type = trim((string)($_POST['event_type'] ?? 'Activity')) ?: 'Activity'; $end = (string)($_POST['end_date'] ?? ''); $location = trim((string)($_POST['location'] ?? '')); $participants = max(0, (int)($_POST['participants'] ?? 0));
                $stmt = $conn->prepare("INSERT INTO activity_event_requests (reference_code,title,concept,event_type,entity_type,entity_id,milestone_id,deliverable_id,activity_name,start_date,end_date,location,participants,requested_amount,currency,created_by,requester_signature,requester_signature_mime) VALUES (?,?,?,?,?,?,?,?,?,?,NULLIF(?,''),NULLIF(?,''),?,?,?,?,?,?)");
                if(!$stmt){error_log('Activity/event request prepare failed: '.$conn->error);throw new RuntimeException('Request could not be saved. Confirm all activity-request migrations have been applied.');}
                $signatureBlob='';$stmt->bind_param('sssssiiissssidsibs',$ref,$title,$concept,$type,$entity,$entityId,$milestone,$deliverable,$activity,$start,$end,$location,$participants,$amount,$currency,$uid,$signatureBlob,$signature['mime']);
                $stmt->send_long_data(16,$signature['data']);
                if ($stmt->execute()) { $newId=(int)$conn->insert_id; $stmt->close(); $notifyStage($conn,'Supervisor Review',$newId,$title); $notice='Request submitted for supervisor/operations review.'; }
                else { error_log('Activity/event request insert failed: '.$stmt->error);$stmt->close();throw new RuntimeException('Request could not be saved. Confirm all activity-request migrations have been applied, then try again.'); }
            }
        }
    } elseif ($action === 'resubmit') {
        $id=(int)($_POST['request_id']??0);
        $stmt=$conn->prepare("SELECT request_id,created_by,status FROM activity_event_requests WHERE request_id=? AND created_by=? AND status='Returned'");
        $stmt->bind_param('ii',$id,$uid);$stmt->execute();$returned=$stmt->get_result()->fetch_assoc();$stmt->close();
        $title=trim((string)($_POST['title']??''));$concept=trim((string)($_POST['concept']??''));$eventType=trim((string)($_POST['event_type']??'Activity'))?:'Activity';
        $start=(string)($_POST['start_date']??'');$end=(string)($_POST['end_date']??'');$location=trim((string)($_POST['location']??''));$participants=filter_var($_POST['participants']??0,FILTER_VALIDATE_INT);
        $currency=strtoupper(trim((string)($_POST['currency']??'UGX')));
        $goal=trim((string)($_POST['goal']??''));$objectives=trim((string)($_POST['objectives']??''));$outcomes=trim((string)($_POST['expected_outcomes']??''));
        [$budgetItems,$amount,$budgetError]=aer_read_budget_items();
        if(!$returned){$notice='Only the requester can resubmit a returned request.';}
        elseif($budgetError!==''){$notice=$budgetError;}
        elseif($goal===''||$objectives===''||$outcomes===''){$notice='Complete the goal, objectives, and expected outcomes.';}
        elseif($title===''||$concept===''||!in_array($eventType,['Activity','Event','Training','Workshop'],true)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start)||($end!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end))||($end!==''&&$end<$start)||$participants===false||$participants<0||$amount===false||$amount<=0||!preg_match('/^[A-Z]{3,10}$/',$currency)){$notice='Check the request details, dates, participant count, amount, and currency.';}
        else{
            $concept=aer_compose_concept($concept,$goal,$objectives,$outcomes,$budgetItems);
            $signature=$readSignature();
            $conn->begin_transaction();
            $save=$conn->prepare("UPDATE activity_event_requests SET title=?,concept=?,event_type=?,start_date=?,end_date=NULLIF(?,''),location=?,participants=?,requested_amount=?,currency=?,requester_signature=?,requester_signature_mime=?,status='Supervisor Review' WHERE request_id=? AND created_by=? AND status='Returned'");
            if(!$save)throw new RuntimeException('Could not prepare the returned request for resubmission.');
            $signatureBlob='';$save->bind_param('ssssssidsbsii',$title,$concept,$eventType,$start,$end,$location,$participants,$amount,$currency,$signatureBlob,$signature['mime'],$id,$uid);$save->send_long_data(9,$signature['data']);$ok=$save->execute();$save->close();
            if($ok&&$conn->affected_rows===1){$conn->commit();$notifyStage($conn,'Supervisor Review',$id,$title);notify_user($uid,'Request resubmitted','Your request has been resubmitted for supervisor/operations review.','info',$id,'activity_event_request');$notice='Request resubmitted for supervisor/operations review.';}
            else{$conn->rollback();$notice='The request could not be resubmitted. It may no longer be in Returned status.';}
        }
    } elseif ($action === 'review') {
        $id=(int)($_POST['request_id'] ?? 0); $decision=(string)($_POST['decision'] ?? ''); $comments=trim((string)($_POST['comments'] ?? ''));
        $stmt=$conn->prepare('SELECT * FROM activity_event_requests WHERE request_id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $request=$stmt->get_result()->fetch_assoc(); $stmt->close();
        $stages=['Supervisor Review'=>['Supervisor',$ops],'Procurement Review'=>['Procurement',$procurement],'Finance Review'=>['Finance',$finance],'Director Approval'=>['Director',$director]];
        if (!$request || !isset($stages[$request['status']]) || !$stages[$request['status']][1] || !in_array($decision,['Approved','Returned','Rejected'],true) || ($decision!=='Approved' && $comments==='')) $notice='This action is not permitted, or comments are required for return/rejection.';
        else {
            $signature=$readSignature();
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
            $stmt=$conn->prepare('INSERT INTO activity_event_request_reviews (request_id,stage,action,comments,reviewed_by,signature_data,signature_mime) VALUES (?,?,?,?,?,?,?)');if(!$stmt)throw new RuntimeException('Review could not be recorded. Apply the request signature migration.');$signatureBlob='';$stmt->bind_param('isssibs',$id,$stage,$decision,$comments,$uid,$signatureBlob,$signature['mime']);$stmt->send_long_data(5,$signature['data']);$ok=$stmt->execute();if(!$ok)error_log('Activity/event review insert failed: '.$stmt->error);$stmt->close();
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
    } catch (Throwable $exception) {
        @$conn->rollback();
        if ($exception instanceof InvalidArgumentException) {
            $notice=$exception->getMessage();
        } else {
            error_log('Activity/event request action failed: '.$exception->getMessage());
            $notice=$exception instanceof RuntimeException?$exception->getMessage():'The request could not be completed. Please try again or contact an administrator.';
        }
    }
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$projects=$conn->query('SELECT project_id id, project_name name, currency FROM projects ORDER BY project_name');$programs=$conn->query('SELECT id, program_name name, currency FROM programs ORDER BY program_name');
$milestones=$conn->query('SELECT milestone_id,entity_type,entity_id,milestone_name FROM milestones ORDER BY due_date DESC');
$activities=$conn->query('SELECT deliverable_id,milestone_id,deliverable_title FROM milestone_deliverables ORDER BY deliverable_title');
$stmt=$conn->prepare('SELECT r.*,u.full_name requester,m.milestone_name,CASE WHEN r.entity_type=\'Project\' THEN p.project_name ELSE pr.program_name END entity_name FROM activity_event_requests r LEFT JOIN users u ON u.user_id=r.created_by LEFT JOIN milestones m ON m.milestone_id=r.milestone_id LEFT JOIN projects p ON r.entity_type=\'Project\' AND p.project_id=r.entity_id LEFT JOIN programs pr ON r.entity_type=\'Program\' AND pr.id=r.entity_id WHERE (?=1 OR r.created_by=?) ORDER BY r.created_at DESC');$all=(int)($finance||$ops||$procurement||$director);$stmt->bind_param('ii',$all,$uid);$stmt->execute();$requests=$stmt->get_result();
$requestRows=$requests->fetch_all(MYSQLI_ASSOC);
$requestStats=['total'=>count($requestRows),'pending'=>0,'approved'=>0,'returned'=>0];foreach($requestRows as $r){if(in_array($r['status'],['Supervisor Review','Procurement Review','Finance Review','Director Approval'],true))$requestStats['pending']++;if($r['status']==='Approved')$requestStats['approved']++;if($r['status']==='Returned')$requestStats['returned']++;}
function aer_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
// When a modal form fails, reopen that modal with the message and the values the user entered.
$noticeOk=(bool)preg_match('/^(Request (submitted|resubmitted)|Review recorded|Reminder sent|\d+ budget line\(s\) imported)/',$notice);
$formModal='';$formValues=[];
if($_SERVER['REQUEST_METHOD']==='POST'&&$notice!==''&&!$noticeOk){
    $postedAction=(string)($_POST['action']??'');$postedId=(int)($_POST['request_id']??0);
    $formModal=$postedAction==='submit'?'requestModal':(in_array($postedAction,['resubmit','review'],true)&&$postedId>0?'request-'.$postedAction.'-'.$postedId:'');
    if($formModal!=='')foreach($_POST as $key=>$value)if(!in_array($key,['csrf_token','action','request_id','signature_data','decision'],true))$formValues[$key]=is_array($value)?array_values(array_map('strval',$value)):(string)$value;
}
?>
<style>
.aer-page{max-width:1280px;margin:0 auto;padding:24px;display:grid;gap:24px}.aer-page h1,.aer-page h2,.aer-page h3{color:var(--ink-900)}.aer-page section,.aer-request{background:var(--surface,#fff);border:1px solid var(--line,#dce3ea);border-radius:14px;padding:24px;box-shadow:0 4px 18px rgba(24,39,56,.06)}.aer-page form{display:flex;flex-wrap:wrap;align-items:end;gap:14px;margin-top:16px}.aer-page label{display:grid;gap:6px;flex:1 1 210px;font-weight:600;color:var(--ink-700)}.aer-page input,.aer-page select,.aer-page textarea{width:100%;min-height:42px;padding:9px 12px;border:1px solid var(--line,#cbd5e1);border-radius:8px;background:#fff;color:var(--ink-900);font:inherit}.aer-page textarea{min-height:110px}.aer-page button,.aer-button{min-height:42px;padding:9px 16px;border:0;border-radius:8px;background:var(--brand-600,#1769aa);color:#fff;font:600 14px inherit;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.aer-page button:hover,.aer-button:hover{filter:brightness(.94)}.aer-page .aer-secondary{background:#eaf1f7;color:var(--ink-900)}.aer-request{display:grid;gap:10px}.aer-request p{margin:0;line-height:1.55}.aer-status{display:inline-flex;padding:4px 10px;border-radius:999px;background:#eaf1f7;font-size:.86rem;font-weight:700}.aer-inline{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.aer-page .alert{padding:14px 18px;border-radius:9px}.aer-help{color:var(--ink-500,#64748b);margin:.35rem 0}.aer-table-wrap{width:100%;overflow:auto}.aer-table{width:100%;border-collapse:collapse;min-width:900px}.aer-table th,.aer-table td{padding:12px;border-bottom:1px solid var(--line,#dce3ea);text-align:left;vertical-align:top}.aer-table th{background:#f4f7fa;color:var(--ink-700);font-size:.82rem;text-transform:uppercase}.aer-dialog{margin:auto;width:min(900px,calc(100vw - 24px));max-height:90vh;overflow:auto;border:0;border-radius:16px;padding:26px;box-shadow:0 20px 60px #0004}.aer-dialog::backdrop{background:#0f172a99}.aer-dialog form{margin-top:18px}.aer-dialog-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.aer-dialog-head button{background:#eaf1f7;color:var(--ink-900)}
.aer-tab-nav{display:flex;gap:8px;width:100%;border-bottom:1px solid var(--line,#dce3ea);padding-bottom:10px}.aer-tab-nav button{background:transparent;color:var(--ink-700);border-radius:8px}.aer-tab-nav button[aria-selected="true"]{background:var(--brand-600,#1769aa);color:#fff}.aer-tab-panel{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;width:100%}.aer-tab-panel[hidden]{display:none}.aer-tab-panel label{min-width:0}.aer-tab-panel .aer-wide{grid-column:1/-1}.aer-step-actions{display:flex;justify-content:space-between;gap:10px;width:100%}.aer-amount-note{font-size:.83rem;color:var(--ink-500,#64748b)}.aer-signature-box{display:grid;gap:8px;width:100%;padding:12px;border:1px dashed #94a3b8;border-radius:10px;background:#f8fafc}.aer-signature-canvas{display:block;width:min(100%,440px);height:130px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;touch-action:none}.aer-signature-tools{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.aer-signature-tools input{max-width:360px}.aer-signed-image{max-width:240px;max-height:90px;object-fit:contain;border:1px solid #e2e8f0;border-radius:6px;background:white;padding:4px}
.aer-toast{position:fixed;z-index:12000;top:22px;right:22px;width:min(440px,calc(100vw - 32px));padding:15px 18px;border-radius:12px;background:#12304a;color:#fff;box-shadow:0 14px 38px #0f172a40;font-weight:600;opacity:0;transform:translateY(-8px);transition:opacity .35s ease,transform .35s ease}.aer-toast[hidden]{display:none}.aer-toast.is-visible{opacity:1;transform:translateY(0)}.aer-toast.is-success{background:#126445}.aer-toast.is-error{background:#9b2c2c}.aer-toast.is-hiding{opacity:0;transform:translateY(-8px)}@media(max-width:600px){.aer-toast{top:12px;right:12px;width:calc(100vw - 24px)}}
@media(max-width:760px){.aer-table{display:block;min-width:0;width:100%}.aer-table thead{display:none}.aer-table tbody{display:grid;gap:14px}.aer-table tbody tr{display:grid;grid-template-columns:minmax(0,1fr);border:1px solid var(--line,#dce3ea);border-radius:12px;background:#fff;overflow:hidden}.aer-table td{display:grid;grid-template-columns:minmax(100px,32%) minmax(0,1fr);gap:10px;width:100%;padding:10px 12px;overflow-wrap:anywhere}.aer-table td::before{font-weight:700;color:var(--ink-600,#475569);content:''}.aer-table td:nth-child(1)::before{content:'Reference'}.aer-table td:nth-child(2)::before{content:'Request'}.aer-table td:nth-child(3)::before{content:'Project / program'}.aer-table td:nth-child(4)::before{content:'Activity / milestone'}.aer-table td:nth-child(5)::before{content:'Amount'}.aer-table td:nth-child(6)::before{content:'Status'}.aer-table td:nth-child(7)::before{content:'Requester / date'}.aer-table td:nth-child(8)::before{content:'Actions'}.aer-table td[colspan]{display:block}.aer-table td[colspan]::before{content:none}.aer-table td button{width:auto;max-width:100%}.aer-table td form{margin:0}.aer-dialog{padding:18px}.aer-record-dialog,.aer-review-dialog{width:calc(100vw - 20px);max-width:680px}.aer-signature-tools label{flex-basis:100%}}
@media(max-width:700px){.aer-page{padding:14px}.aer-page section,.aer-request{padding:16px}.aer-page button,.aer-button{width:100%}.aer-tab-panel{grid-template-columns:1fr}.aer-tab-panel .aer-wide{grid-column:auto}.aer-tab-nav button,.aer-step-actions button{width:auto}.aer-tab-nav{flex-wrap:wrap}}
</style>
<style>
/* Messages inside modal forms */
.aer-form-alert{width:100%;padding:12px 14px;border:1px solid #bfd3e6;border-left:4px solid #1769aa;border-radius:8px;background:#eef5fb;color:#12304a;font-size:.9rem;font-weight:600;line-height:1.45;outline:none}
.aer-form-alert.is-error{border-color:#f1b8b2;border-left-color:#b42318;background:#fdf0ef;color:#9b2c2c}
.aer-form-alert.is-success{border-color:#a7dcc3;border-left-color:#12805c;background:#ecf8f2;color:#126445}
.aer-form-alert[hidden]{display:none}
/* Edit / resubmit form */
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.aer-edit-dialog{width:min(760px,calc(100vw - 32px));padding:0;border:1px solid var(--line,#dce3ea);border-radius:14px}
.aer-edit-dialog .aer-dialog-head{position:sticky;top:0;z-index:2;padding:18px 24px;background:var(--surface,#fff);border-bottom:1px solid var(--line,#dce3ea)}
.aer-edit-dialog .aer-dialog-head h2{margin:0;font-size:1.15rem}.aer-edit-dialog .aer-dialog-head .aer-help{margin:4px 0 0;font-size:.85rem}
.aer-edit-dialog .aer-dialog-head button{min-height:34px;padding:6px 12px;font-size:.85rem}
.aer-page .aer-edit-form{display:grid;gap:18px;margin:0;padding:20px 24px 22px}
.aer-edit-section{margin:0;padding:16px 18px 18px;border:1px solid var(--line,#dce3ea);border-radius:10px;min-width:0}
.aer-edit-section legend{padding:0 6px;font-weight:700;font-size:.95rem;color:var(--ink-900)}
.aer-edit-section>.aer-help{margin:0 0 10px;font-size:.84rem}
.aer-edit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}.aer-edit-grid .aer-wide{grid-column:1/-1}
.aer-edit-stack{display:grid;gap:14px}
.aer-edit-form label{flex:none;min-width:0;font-size:.875rem}
.aer-edit-form input,.aer-edit-form select,.aer-edit-form textarea{min-height:40px;padding:8px 12px;font-size:.92rem}
.aer-edit-form textarea{min-height:96px;resize:vertical;line-height:1.5}
.aer-edit-form input:focus,.aer-edit-form select:focus,.aer-edit-form textarea:focus{outline:none;border-color:var(--brand-600,#1769aa);box-shadow:0 0 0 3px #1769aa26}
.aer-edit-form button{min-height:38px;padding:8px 14px;font-size:.88rem}
.aer-req{color:#b42318}
.aer-edit-readonly{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0}
.aer-edit-readonly div{padding:10px 12px;border-radius:8px;background:#f4f7fa;min-width:0}
.aer-edit-readonly dt{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--ink-500,#64748b)}
.aer-edit-readonly dd{margin:4px 0 0;color:var(--ink-900);overflow-wrap:anywhere}
.aer-edit-budget-table{min-width:0;table-layout:fixed}
.aer-edit-budget-table th,.aer-edit-budget-table td{padding:8px 6px;vertical-align:middle}
.aer-edit-budget-table th:nth-child(1){width:40%}.aer-edit-budget-table th:nth-child(2){width:13%}.aer-edit-budget-table th:nth-child(3){width:19%}.aer-edit-budget-table th:nth-child(4){width:16%}.aer-edit-budget-table th:nth-child(5){width:12%}
.aer-edit-budget-table .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.aer-edit-budget-table .remove-budget-item{padding:6px 8px;font-size:.8rem;width:100%}
.aer-edit-budget-foot{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-top:12px}
.aer-edit-total{font-size:.9rem;color:var(--ink-700)}.aer-edit-total strong{margin-left:6px;font-size:1.05rem;color:var(--ink-900);font-variant-numeric:tabular-nums}
.aer-edit-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:14px;border-top:1px solid var(--line,#dce3ea)}.aer-edit-actions.is-split{justify-content:space-between}
.aer-edit-dialog .aer-edit-tabs{gap:4px;padding-bottom:0}
.aer-edit-dialog .aer-edit-tabs button{min-height:38px;padding:8px 14px;font-size:.88rem;border-radius:8px 8px 0 0;border-bottom:2px solid transparent;background:transparent;color:var(--ink-700)}
.aer-edit-dialog .aer-edit-tabs button[aria-selected="true"]{background:transparent;color:var(--brand-600,#1769aa);border-bottom-color:var(--brand-600,#1769aa)}
.aer-edit-panel{display:grid;gap:18px;min-width:0}.aer-edit-panel[hidden]{display:none}
@media(max-width:760px){
.aer-edit-dialog .aer-dialog-head,.aer-page .aer-edit-form{padding-left:16px;padding-right:16px}
.aer-edit-section{padding:12px}
.aer-edit-grid,.aer-edit-readonly{grid-template-columns:1fr}.aer-edit-grid .aer-wide{grid-column:auto}
.aer-edit-budget-table th,.aer-edit-budget-table td{width:auto}
.aer-edit-budget-table td:nth-child(1)::before{content:'Item'}.aer-edit-budget-table td:nth-child(2)::before{content:'Quantity'}.aer-edit-budget-table td:nth-child(3)::before{content:'Unit cost'}.aer-edit-budget-table td:nth-child(4)::before{content:'Total'}.aer-edit-budget-table td:nth-child(5)::before{content:''}
.aer-edit-actions>*{flex:1}
.aer-edit-dialog .aer-edit-tabs{flex-wrap:nowrap;overflow-x:auto}.aer-edit-dialog .aer-edit-tabs button{width:auto;flex:none;white-space:nowrap}
.aer-edit-actions .aer-inline{flex-wrap:nowrap}
}
/* Standard-width concept form */
#requestModal{width:min(760px,calc(100vw - 32px));padding:0;border:1px solid var(--line,#dce3ea);border-radius:14px}
#requestModal .aer-dialog-head{position:sticky;top:0;z-index:2;padding:18px 24px;background:var(--surface,#fff);border-bottom:1px solid var(--line,#dce3ea)}
#requestModal .aer-dialog-head h2{margin:0;font-size:1.15rem}
#requestModal .aer-dialog-head button{min-height:34px;padding:6px 12px;font-size:.85rem}
#requestModal form{margin:0;padding:18px 24px 22px;gap:16px}
#requestModal .aer-tab-nav{gap:4px;padding-bottom:0}
#requestModal .aer-tab-nav button{min-height:38px;padding:8px 14px;font-size:.88rem;border-radius:8px 8px 0 0;border-bottom:2px solid transparent}
#requestModal .aer-tab-nav button[aria-selected="true"]{background:transparent;color:var(--brand-600,#1769aa);border-bottom-color:var(--brand-600,#1769aa)}
#requestModal .aer-tab-panel{gap:14px 18px}
#requestModal label{font-size:.875rem;gap:6px}
#requestModal input,#requestModal select,#requestModal textarea{min-height:40px;padding:8px 12px;font-size:.92rem}
#requestModal textarea{min-height:96px;resize:vertical}
#requestModal input:focus,#requestModal select:focus,#requestModal textarea:focus{outline:none;border-color:var(--brand-600,#1769aa);box-shadow:0 0 0 3px #1769aa26}
#requestModal button{min-height:38px;padding:8px 14px;font-size:.88rem}
#requestModal .aer-step-actions{padding-top:14px;border-top:1px solid var(--line,#dce3ea)}
#requestModal .aer-signature-canvas{width:100%;max-width:440px}
#budgetItemsTable{min-width:0;table-layout:fixed}
#budgetItemsTable th,#budgetItemsTable td{padding:8px 6px;vertical-align:middle}
#budgetItemsTable th:nth-child(1){width:40%}#budgetItemsTable th:nth-child(2){width:13%}#budgetItemsTable th:nth-child(3){width:19%}#budgetItemsTable th:nth-child(4){width:16%}#budgetItemsTable th:nth-child(5){width:12%}
#budgetItemsTable .budget-line-total{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
#budgetItemsTable .remove-budget-item{padding:6px 8px;font-size:.8rem;width:100%}
@media(max-width:760px){
#requestModal .aer-dialog-head,#requestModal form{padding-left:16px;padding-right:16px}
#budgetItemsTable td:nth-child(1)::before{content:'Item'}#budgetItemsTable td:nth-child(2)::before{content:'Quantity'}#budgetItemsTable td:nth-child(3)::before{content:'Unit cost'}#budgetItemsTable td:nth-child(4)::before{content:'Total'}#budgetItemsTable td:nth-child(5)::before{content:''}
#budgetItemsTable th,#budgetItemsTable td{width:auto}
}
</style>
<style>
.aer-truncate{display:inline-block;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom}.aer-request-title{max-width:210px;font-weight:700}.aer-activity-title{max-width:165px}
@media(max-width:760px){.aer-request-title{max-width:calc(100vw - 230px)}.aer-activity-title{max-width:calc(100vw - 245px)}}
</style>
<div class="aer-page"><h1>Activity/Event Requests</h1>
<div id="aerToast" class="aer-toast" role="status" aria-live="polite" hidden></div>
<div class="stats-grid reports-stats"><div class="stat-card"><div class="stat-icon bg-blue"><i class="fas fa-clipboard-list" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Total Requests</span><span class="stat-value"><?=number_format($requestStats['total'])?></span></div></div><div class="stat-card"><div class="stat-icon bg-amber"><i class="fas fa-hourglass-half" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">In Review</span><span class="stat-value"><?=number_format($requestStats['pending'])?></span></div></div><div class="stat-card"><div class="stat-icon bg-green"><i class="fas fa-check-circle" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Approved</span><span class="stat-value"><?=number_format($requestStats['approved'])?></span></div></div><div class="stat-card"><div class="stat-icon bg-purple"><i class="fas fa-rotate-left" aria-hidden="true"></i></div><div class="stat-info"><span class="stat-label">Returned for Changes</span><span class="stat-value"><?=number_format($requestStats['returned'])?></span></div></div></div>
<section><div class="aer-dialog-head"><div><h2>Activity/Event Requests</h2><p class="aer-help">Track request progress and review decisions.</p></div><button type="button" class="aer-button" id="openRequestModal">New request</button></div></section>
<dialog class="aer-dialog" id="requestModal"><div class="aer-dialog-head"><h2>Submit an activity/event concept</h2><button type="button" id="closeRequestModal" aria-label="Close">Close</button></div>
<form method="post" id="requestForm" enctype="multipart/form-data" novalidate><input type="hidden" name="csrf_token" value="<?=aer_h($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="requested_amount" id="requestedAmount">
<div class="aer-tab-nav" role="tablist" aria-label="Request details"><button type="button" role="tab" data-tab="0" aria-selected="true">1. Request</button><button type="button" role="tab" data-tab="1" aria-selected="false">2. Planning & budget</button><button type="button" role="tab" data-tab="2" aria-selected="false">3. Concept</button></div>
<div class="aer-tab-panel" role="tabpanel" data-panel="0"><label>Title<input required name="title" maxlength="255"></label><label>Request type<select name="event_type"><option>Activity</option><option>Event</option><option>Training</option><option>Workshop</option></select></label><label>Project/program type<select required name="entity_type" id="entityType"><option value="">Choose type</option><option>Project</option><option>Program</option></select></label><label>Project/program<select required name="entity_id" id="entityId"><option value="">Choose project/program</option><?php foreach(['Project'=>$projects,'Program'=>$programs] as $kind=>$rows)if($rows)while($row=$rows->fetch_assoc()):?><option data-type="<?=$kind?>" value="<?=$row['id']?>"><?=aer_h($row['name'])?></option><?php endwhile;?></select></label><label class="aer-wide">Workplan milestone<select required name="milestone_id" id="milestoneId"><option value="">Choose milestone</option><?php if($milestones)while($m=$milestones->fetch_assoc()):?><option data-type="<?=aer_h($m['entity_type'])?>" data-entity="<?=$m['entity_id']?>" value="<?=$m['milestone_id']?>"><?=aer_h($m['milestone_name'])?></option><?php endwhile;?></select></label><label>Search activities<input type="search" id="activitySearch" placeholder="Type to filter activities"></label><label>Workplan activity<select required name="deliverable_id" id="activitySelect"><option value="">Select a milestone first</option><?php if($activities)while($a=$activities->fetch_assoc()):?><option data-milestone="<?=$a['milestone_id']?>" value="<?=$a['deliverable_id']?>"><?=aer_h($a['deliverable_title'])?></option><?php endwhile;?></select></label><div class="aer-step-actions"><span></span><button type="button" class="aer-next" data-next>Next</button></div></div>
<div class="aer-tab-panel" role="tabpanel" data-panel="1" hidden><label>Start date<input required type="date" name="start_date"></label><label>End date<input type="date" name="end_date"></label><label>Location<input name="location" maxlength="255"></label><label>Participants<input type="number" min="0" name="participants" value="0"></label><label>Currency<select required name="currency"><option value="UGX" selected>UGX — Ugandan Shilling</option><option value="USD">USD — US Dollar</option><option value="EUR">EUR — Euro</option><option value="GBP">GBP — British Pound</option><option value="KES">KES — Kenyan Shilling</option><option value="TZS">TZS — Tanzanian Shilling</option><option value="RWF">RWF — Rwandan Franc</option><option value="ETB">ETB — Ethiopian Birr</option><option value="CAD">CAD — Canadian Dollar</option><option value="AUD">AUD — Australian Dollar</option></select></label><div class="aer-wide"><h3>Budget breakdown</h3><p class="aer-help">Add each requested item; line totals and the requested amount are calculated automatically.</p><div class="aer-table-wrap"><table class="aer-table" id="budgetItemsTable"><thead><tr><th>Item / budget line</th><th>Quantity</th><th>Unit cost</th><th>Total</th><th></th></tr></thead><tbody id="budgetItemsBody"><tr class="budget-item-row"><td><input required aria-label="Budget item" name="budget_item[]" maxlength="255" placeholder="e.g. Venue hire"></td><td><input required type="number" min="0.01" step="0.01" aria-label="Quantity" name="budget_quantity[]" value="1"></td><td><input required type="number" min="0" step="0.01" aria-label="Unit cost" name="budget_unit_cost[]" placeholder="0.00"></td><td class="num budget-line-total">0.00</td><td><button type="button" class="aer-secondary remove-budget-item" aria-label="Remove budget item">Remove</button></td></tr></tbody></table></div><button type="button" class="aer-secondary" id="addBudgetItem">Add budget item</button><label>Total requested amount<input type="text" id="amountDisplay" value="0.00" readonly aria-describedby="budgetTotalHelp"></label><span class="aer-amount-note" id="budgetTotalHelp">Calculated from the budget items above.</span></div><div class="aer-step-actions"><button type="button" class="aer-secondary" data-back>Back</button><button type="button" class="aer-next" data-next>Next</button></div></div>
<div class="aer-tab-panel" role="tabpanel" data-panel="2" hidden><label class="aer-wide">1. Background and Justification<textarea required name="concept" rows="5" placeholder="Describe the context, need, and rationale for this activity."></textarea></label><label class="aer-wide">2. Goal<textarea required name="goal" rows="3" placeholder="What is the overall goal?"></textarea></label><label class="aer-wide">Objectives<textarea required name="objectives" rows="4" placeholder="List the specific objectives."></textarea></label><label class="aer-wide">4. Expected Outcomes<textarea required name="expected_outcomes" rows="4" placeholder="List the expected, measurable outcomes."></textarea></label><div class="aer-wide"><h3>5. Budget Lines</h3><p class="aer-help">Your itemized budget breakdown and total from Planning &amp; budget will be included in the concept note.</p></div><div class="aer-signature-box aer-wide"><strong>Requester signature (required)</strong><span>Draw your signature below or upload a PNG/JPEG image (max 2 MB).</span><canvas class="aer-signature-canvas" width="440" height="130" aria-label="Draw requester signature"></canvas><input type="hidden" name="signature_data"><div class="aer-signature-tools"><button type="button" class="aer-secondary aer-clear-signature">Clear signature</button><label>Upload signature<input type="file" name="signature_file" accept="image/png,image/jpeg"></label></div></div><div class="aer-step-actions"><button type="button" class="aer-secondary" data-back>Back</button><div class="aer-inline"><button type="submit">Submit concept</button><button class="aer-secondary" type="button" id="cancelRequestModal">Cancel</button></div></div></div></form></dialog>
<section><h2>Requests and approval status</h2><div class="aer-table-wrap"><table class="aer-table"><thead><tr><th>Reference</th><th>Request</th><th>Project/program</th><th>Activity</th><th>Amount</th><th>Status</th><th>Requester / date</th><th>Review</th></tr></thead><tbody><?php if(!$requestRows):?><tr><td colspan="8">No requests found.</td></tr><?php endif;?><?php foreach($requestRows as $r):?><tr><td><?=aer_h($r['reference_code'])?></td><td><span class="aer-truncate aer-request-title" title="<?=aer_h($r['title'])?>"><?=aer_h($r['title'])?></span> <button type="button" class="aer-secondary" data-open-modal="request-details-<?=$r['request_id']?>">Details</button><dialog class="aer-dialog aer-record-dialog" id="request-details-<?=$r['request_id']?>"><div class="aer-dialog-head"><h2><?=aer_h($r['title'])?></h2><button type="button" data-close-modal>Close</button></div><p><?=nl2br(aer_h($r['concept']))?></p><?php if(!empty($r['requester_signature'])):?><p>Requester signature<br><img class="aer-signed-image" alt="Requester signature" src="data:<?=aer_h($r['requester_signature_mime']??'image/png')?>;base64,<?=base64_encode($r['requester_signature'])?>"></p><?php endif;?><?php if($signatureSchemaReady):?><?php $timeline=$conn->prepare('SELECT stage,action,comments,reviewed_at,signature_data,signature_mime FROM activity_event_request_reviews WHERE request_id=? ORDER BY reviewed_at');$timeline->bind_param('i',$r['request_id']);$timeline->execute();$logs=$timeline->get_result();while($log=$logs->fetch_assoc()):?><p><small><?=aer_h($log['stage'])?>: <?=aer_h($log['action'])?><?=($log['comments']??'')!==''?' — '.aer_h($log['comments']):''?> (<?=aer_h($log['reviewed_at'])?>)</small></p><?php if(!empty($log['signature_data'])):?><img class="aer-signed-image" alt="<?=aer_h($log['stage'])?> reviewer signature" src="data:<?=aer_h($log['signature_mime']??'image/png')?>;base64,<?=base64_encode($log['signature_data'])?>"><?php endif;?><?php endwhile;$timeline->close();?><?php endif;?></dialog></td><td><?=aer_h($r['entity_type'])?> #<?=$r['entity_id']?></td><td><span class="aer-truncate aer-activity-title" title="<?=aer_h($r['activity_name'])?>"><?=aer_h($r['activity_name'])?></span><br><small><?=aer_h($r['milestone_name']??'Milestone')?></small></td><td><?=aer_h($r['currency'])?> <?=number_format((float)$r['requested_amount'],2)?></td><td><span class="aer-status"><?=aer_h($r['status'])?></span></td><td><?=aer_h($r['requester']??'Staff')?><br><small><?=aer_h($r['created_at'])?></small></td><td><?php if((int)$r['created_by']===$uid&&$r['status']==='Returned'):?><button type="button" class="aer-button" data-open-modal="request-resubmit-<?=$r['request_id']?>">Edit &amp; resubmit</button><?php $editParts=aer_parse_concept((string)$r['concept']);$editItems=$editParts['items']?:[['name'=>'','quantity'=>1,'unit_cost'=>'']];?><dialog class="aer-dialog aer-review-dialog aer-edit-dialog" id="request-resubmit-<?=$r['request_id']?>" aria-labelledby="request-resubmit-title-<?=$r['request_id']?>"><div class="aer-dialog-head"><div><h2 id="request-resubmit-title-<?=$r['request_id']?>">Edit <?=aer_h($r['reference_code'])?></h2><p class="aer-help">Address the reviewer’s comments, then resubmit this request.</p></div><button type="button" data-close-modal aria-label="Close">Close</button></div><form method="post" enctype="multipart/form-data" class="aer-resubmit-form aer-edit-form" novalidate><input type="hidden" name="csrf_token" value="<?=aer_h($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="resubmit"><input type="hidden" name="request_id" value="<?=$r['request_id']?>"><div class="aer-tab-nav aer-edit-tabs" role="tablist" aria-label="Edit request steps"><button type="button" role="tab" id="edit-tab-<?=$r['request_id']?>-0" aria-controls="edit-panel-<?=$r['request_id']?>-0" data-edit-tab="0" aria-selected="true">1. Request details</button><button type="button" role="tab" id="edit-tab-<?=$r['request_id']?>-1" aria-controls="edit-panel-<?=$r['request_id']?>-1" data-edit-tab="1" aria-selected="false" tabindex="-1">2. Concept note</button><button type="button" role="tab" id="edit-tab-<?=$r['request_id']?>-2" aria-controls="edit-panel-<?=$r['request_id']?>-2" data-edit-tab="2" aria-selected="false" tabindex="-1">3. Budget &amp; signature</button></div>
<div class="aer-edit-panel" role="tabpanel" id="edit-panel-<?=$r['request_id']?>-0" aria-labelledby="edit-tab-<?=$r['request_id']?>-0" data-edit-panel="0"><fieldset class="aer-edit-section"><legend>Request details</legend><div class="aer-edit-grid"><label class="aer-wide"><span class="aer-label">Request title <span class="aer-req" aria-hidden="true">*</span></span><input required autofocus name="title" maxlength="255" value="<?=aer_h($r['title'])?>"></label><label>Request type<select name="event_type"><?php foreach(['Activity','Event','Training','Workshop'] as $eventOption):?><option <?=($r['event_type']===$eventOption?'selected':'')?>><?=aer_h($eventOption)?></option><?php endforeach;?></select></label><label><span class="aer-label">Currency <span class="aer-req" aria-hidden="true">*</span></span><select required name="currency"><?php foreach(['UGX'=>'Ugandan Shilling','USD'=>'US Dollar','EUR'=>'Euro','GBP'=>'British Pound','KES'=>'Kenyan Shilling','TZS'=>'Tanzanian Shilling','RWF'=>'Rwandan Franc','ETB'=>'Ethiopian Birr','CAD'=>'Canadian Dollar','AUD'=>'Australian Dollar'] as $currencyCode=>$currencyName):?><option value="<?=$currencyCode?>" <?=($r['currency']===$currencyCode?'selected':'')?>><?=$currencyCode?> — <?=$currencyName?></option><?php endforeach;?></select></label><label><span class="aer-label">Start date <span class="aer-req" aria-hidden="true">*</span></span><input required type="date" name="start_date" value="<?=aer_h($r['start_date'])?>"></label><label>End date<input type="date" name="end_date" value="<?=aer_h($r['end_date']??'')?>"></label><label>Location<input name="location" maxlength="255" value="<?=aer_h($r['location']??'')?>"></label><label>Participants<input type="number" min="0" name="participants" value="<?=max(0,(int)$r['participants'])?>"></label></div></fieldset>
<fieldset class="aer-edit-section"><legend>Workplan link</legend><p class="aer-help">These stay linked to the original workplan selection and cannot be changed here.</p><dl class="aer-edit-readonly"><div><dt>Project / program</dt><dd><?=aer_h($r['entity_type'])?> · <?=aer_h($r['entity_name']??$r['entity_id'])?></dd></div><div><dt>Workplan milestone</dt><dd><?=aer_h(($r['milestone_name']??'')?:'—')?></dd></div><div><dt>Activity</dt><dd><?=aer_h($r['activity_name']?:'—')?></dd></div></dl></fieldset><div class="aer-edit-actions"><button type="button" class="aer-secondary" data-close-modal>Cancel</button><button type="button" data-edit-next>Next: Concept note</button></div></div>
<div class="aer-edit-panel" role="tabpanel" id="edit-panel-<?=$r['request_id']?>-1" aria-labelledby="edit-tab-<?=$r['request_id']?>-1" data-edit-panel="1" hidden><fieldset class="aer-edit-section"><legend>Concept note</legend><div class="aer-edit-stack"><label><span class="aer-label">1. Background and justification <span class="aer-req" aria-hidden="true">*</span></span><textarea required name="concept" rows="6" placeholder="Describe the context, need, and rationale for this activity."><?=aer_h($editParts['background'])?></textarea></label><label><span class="aer-label">2. Goal <span class="aer-req" aria-hidden="true">*</span></span><textarea required name="goal" rows="3" placeholder="What is the overall goal?"><?=aer_h($editParts['goal'])?></textarea></label><label><span class="aer-label">3. Objectives <span class="aer-req" aria-hidden="true">*</span></span><textarea required name="objectives" rows="4" placeholder="List the specific objectives."><?=aer_h($editParts['objectives'])?></textarea></label><label><span class="aer-label">4. Expected outcomes <span class="aer-req" aria-hidden="true">*</span></span><textarea required name="expected_outcomes" rows="4" placeholder="List the expected, measurable outcomes."><?=aer_h($editParts['outcomes'])?></textarea></label></div></fieldset><div class="aer-edit-actions is-split"><button type="button" class="aer-secondary" data-edit-back>Back</button><button type="button" data-edit-next>Next: Budget &amp; signature</button></div></div>
<div class="aer-edit-panel" role="tabpanel" id="edit-panel-<?=$r['request_id']?>-2" aria-labelledby="edit-tab-<?=$r['request_id']?>-2" data-edit-panel="2" hidden><fieldset class="aer-edit-section aer-edit-budget"><legend>5. Budget lines</legend><div class="aer-table-wrap"><table class="aer-table aer-edit-budget-table"><thead><tr><th>Item / budget line</th><th>Quantity</th><th>Unit cost</th><th class="num">Total</th><th><span class="sr-only">Remove</span></th></tr></thead><tbody><?php foreach($editItems as $item):?><tr class="budget-item-row"><td><input required aria-label="Budget item" name="budget_item[]" maxlength="255" placeholder="e.g. Venue hire" value="<?=aer_h($item['name'])?>"></td><td><input required type="number" min="0.01" step="0.01" aria-label="Quantity" name="budget_quantity[]" value="<?=aer_h($item['quantity'])?>"></td><td><input required type="number" min="0" step="0.01" aria-label="Unit cost" name="budget_unit_cost[]" placeholder="0.00" value="<?=aer_h($item['unit_cost'])?>"></td><td class="num budget-line-total">0.00</td><td><button type="button" class="aer-secondary remove-budget-item" aria-label="Remove budget item">Remove</button></td></tr><?php endforeach;?></tbody></table></div><div class="aer-edit-budget-foot"><button type="button" class="aer-secondary add-budget-item">Add budget item</button><div class="aer-edit-total">Total requested <strong class="budget-grand-total">0.00</strong></div></div><?php if(!$editParts['items']):?><p class="aer-help">Previous amount: <?=aer_h($r['currency'])?> <?=number_format((float)$r['requested_amount'],2)?>. Itemise it above; the total becomes the requested amount.</p><?php endif;?></fieldset>
<div class="aer-signature-box"><strong>New requester signature (required)</strong><span>Sign again or upload a PNG/JPEG image (max 2 MB).</span><canvas class="aer-signature-canvas" width="440" height="130" aria-label="Draw requester signature"></canvas><input type="hidden" name="signature_data"><div class="aer-signature-tools"><button type="button" class="aer-secondary aer-clear-signature">Clear signature</button><label>Upload signature<input type="file" name="signature_file" accept="image/png,image/jpeg"></label></div></div><div class="aer-edit-actions is-split"><button type="button" class="aer-secondary" data-edit-back>Back</button><div class="aer-inline"><button type="button" class="aer-secondary" data-close-modal>Cancel</button><button type="submit">Resubmit for review</button></div></div></div></form></dialog><?php endif;?><?php $stageRoles=['Supervisor Review'=>$ops,'Procurement Review'=>$procurement,'Finance Review'=>$finance,'Director Approval'=>$director];if(!empty($stageRoles[$r['status']])):?><button type="button" class="aer-button" data-open-modal="request-review-<?=$r['request_id']?>">Review</button><dialog class="aer-dialog aer-review-dialog" id="request-review-<?=$r['request_id']?>"><div class="aer-dialog-head"><h2>Review <?=aer_h($r['reference_code'])?></h2><button type="button" data-close-modal>Close</button></div><form method="post" class="aer-review-form" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=aer_h($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="review"><input type="hidden" name="request_id" value="<?=$r['request_id']?>"><textarea name="comments" placeholder="Comments (required for return/rejection)"></textarea><div class="aer-signature-box"><strong>Reviewer signature (required)</strong><span>Draw your signature or upload PNG/JPEG (max 2 MB).</span><canvas class="aer-signature-canvas" width="440" height="130" aria-label="Draw reviewer signature"></canvas><input type="hidden" name="signature_data"><div class="aer-signature-tools"><button type="button" class="aer-secondary aer-clear-signature">Clear signature</button><label>Upload signature<input type="file" name="signature_file" accept="image/png,image/jpeg"></label></div></div><button name="decision" value="Approved">Approve</button><button class="aer-secondary" name="decision" value="Returned">Return</button><button class="aer-secondary" name="decision" value="Rejected">Reject</button></form></dialog><?php endif;?><?php if((!empty($stageRoles[$r['status']])||(int)$r['created_by']===$uid)&&in_array($r['status'],['Supervisor Review','Procurement Review','Finance Review','Director Approval'],true)):?><form method="post" class="aer-inline"><input type="hidden" name="csrf_token" value="<?=aer_h($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="remind"><input type="hidden" name="request_id" value="<?=$r['request_id']?>"><button type="submit" class="aer-secondary">Send reminder</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
<script>
const entityType=document.getElementById('entityType'),entityId=document.getElementById('entityId'),milestone=document.getElementById('milestoneId');
function filterLinks(){const type=entityType.value,id=entityId.value;for(const o of entityId.options)if(o.value)o.hidden=o.dataset.type!==type;for(const o of milestone.options)if(o.value)o.hidden=o.dataset.type!==type||(id&&o.dataset.entity!==id);}
entityType.addEventListener('change',filterLinks);entityId.addEventListener('change',filterLinks);filterLinks();
</script>
<script>
const activitySearch=document.getElementById('activitySearch'),activitySelect=document.getElementById('activitySelect');
const toast=document.getElementById('aerToast');let toastTimer=null,toastFadeTimer=null;function showToast(message,type='info'){if(!message)return;const openDialog=document.querySelector('.aer-page dialog[open]');if(openDialog&&aerFormMessage(openDialog,message,type))return;clearTimeout(toastTimer);clearTimeout(toastFadeTimer);toast.textContent=message;toast.className='aer-toast'+(type==='success'?' is-success':type==='error'?' is-error':'');toast.hidden=false;requestAnimationFrame(()=>toast.classList.add('is-visible'));toastTimer=setTimeout(()=>{toast.classList.remove('is-visible');toast.classList.add('is-hiding');toastFadeTimer=setTimeout(()=>{toast.hidden=true;toast.classList.remove('is-hiding');},350);},5000);}
function filterActivities(){const milestoneId=milestone.value,query=activitySearch.value.trim().toLowerCase();let found=0;for(const option of activitySelect.options){if(!option.value)continue;const visible=option.dataset.milestone===milestoneId&&option.text.toLowerCase().includes(query);option.hidden=!visible;if(visible)found++;}activitySelect.value='';activitySelect.options[0].text=milestoneId?(found?'Choose activity':'No activities match this milestone/search'):'Select a milestone first';}
milestone.addEventListener('change',()=>{filterActivities();activitySearch.value='';filterActivities();});activitySearch.addEventListener('input',filterActivities);filterActivities();
entityType.addEventListener('change',()=>{entityId.value='';milestone.value='';activitySearch.value='';filterLinks();filterActivities();});entityId.addEventListener('change',()=>{milestone.value='';activitySearch.value='';filterActivities();});
const requestForm=document.getElementById('requestForm'),panels=[...requestForm.querySelectorAll('[data-panel]')],tabs=[...requestForm.querySelectorAll('[role="tab"]')],amountDisplay=document.getElementById('amountDisplay'),requestedAmount=document.getElementById('requestedAmount'),budgetItemsBody=document.getElementById('budgetItemsBody');let activeStep=0;
function showStep(step){activeStep=Math.max(0,Math.min(2,step));panels.forEach((panel,index)=>panel.hidden=index!==activeStep);tabs.forEach((tab,index)=>tab.setAttribute('aria-selected',index===activeStep?'true':'false'));const focus=panels[activeStep].querySelector('input,select,textarea');if(focus)focus.focus();}
function validStep(step){for(const control of panels[step].querySelectorAll('input,select,textarea'))if(!control.checkValidity()){showStep(step);requestAnimationFrame(()=>{control.focus({preventScroll:true});control.scrollIntoView({behavior:'smooth',block:'center'});});const field=control.getAttribute('aria-label')||control.name.replace(/\[\]$/,'').replaceAll('_',' ')||'required field';showToast('Please complete '+field+'. '+(control.validationMessage||''),'error');return false;}return true;}
tabs.forEach((tab,index)=>tab.addEventListener('click',()=>{if(index<=activeStep||validStep(activeStep))showStep(index);}));requestForm.querySelectorAll('[data-next]').forEach(button=>button.addEventListener('click',()=>{if(validStep(activeStep))showStep(activeStep+1);}));requestForm.querySelectorAll('[data-back]').forEach(button=>button.addEventListener('click',()=>showStep(activeStep-1)));
function calculateBudget(){let total=0;for(const row of budgetItemsBody.querySelectorAll('.budget-item-row')){const quantity=Number(row.querySelector('[name="budget_quantity[]"]').value)||0,unit=Number(row.querySelector('[name="budget_unit_cost[]"]').value)||0,line=Math.round(quantity*unit*100)/100;total+=line;row.querySelector('.budget-line-total').textContent=line.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}requestedAmount.value=total.toFixed(2);amountDisplay.value=total.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
document.getElementById('addBudgetItem').addEventListener('click',()=>{const row=budgetItemsBody.querySelector('.budget-item-row').cloneNode(true);row.querySelectorAll('input').forEach(input=>{input.value=input.name==='budget_quantity[]'?'1':'';});row.querySelector('.budget-line-total').textContent='0.00';budgetItemsBody.appendChild(row);row.querySelector('[name="budget_item[]"]').focus();});
budgetItemsBody.addEventListener('input',calculateBudget);budgetItemsBody.addEventListener('click',event=>{const remove=event.target.closest('.remove-budget-item');if(!remove)return;const rows=budgetItemsBody.querySelectorAll('.budget-item-row');if(rows.length===1){const row=rows[0];row.querySelectorAll('input').forEach(input=>input.value=input.name==='budget_quantity[]'?'1':'');}else remove.closest('.budget-item-row').remove();calculateBudget();});calculateBudget();
requestForm.addEventListener('submit',event=>{calculateBudget();for(let step=0;step<panels.length;step++){for(const control of panels[step].querySelectorAll('input,select,textarea'))if(!control.checkValidity()){event.preventDefault();showStep(step);showToast(control.validationMessage||'Please complete the required fields before continuing.','error');return;}}const signaturePad=requestForm.querySelector('[name="signature_data"]'),signatureFile=requestForm.querySelector('[name="signature_file"]');if(!signaturePad.value&&!signatureFile.files.length){event.preventDefault();showStep(2);showToast('Draw or upload your signature before submitting.','error');return;}if(!requestedAmount.value||Number(requestedAmount.value)<=0){event.preventDefault();showStep(1);showToast('Add at least one budget item with a total greater than zero.','error');return;}});
document.querySelectorAll('.aer-signature-canvas').forEach(canvas=>{const form=canvas.closest('form'),signature=form.querySelector('[name="signature_data"]'),context=canvas.getContext('2d');let drawing=false;context.lineWidth=2.5;context.lineCap='round';context.strokeStyle='#111827';function point(event){const rect=canvas.getBoundingClientRect();return{x:(event.clientX-rect.left)*canvas.width/rect.width,y:(event.clientY-rect.top)*canvas.height/rect.height};}canvas.addEventListener('pointerdown',event=>{drawing=true;canvas.setPointerCapture(event.pointerId);const p=point(event);context.beginPath();context.moveTo(p.x,p.y);});canvas.addEventListener('pointermove',event=>{if(!drawing)return;const p=point(event);context.lineTo(p.x,p.y);context.stroke();});canvas.addEventListener('pointerup',()=>{if(!drawing)return;drawing=false;signature.value=canvas.toDataURL('image/png');});canvas.addEventListener('pointercancel',()=>{drawing=false;});form.querySelector('.aer-clear-signature').addEventListener('click',()=>{context.clearRect(0,0,canvas.width,canvas.height);signature.value='';});});
document.querySelectorAll('.aer-review-form').forEach(form=>form.addEventListener('submit',event=>{const signature=form.querySelector('[name="signature_data"]'),file=form.querySelector('[name="signature_file"]');if(!signature.value&&!file.files.length){event.preventDefault();showToast('Draw or upload your signature before reviewing this request.','error');}}));
document.querySelectorAll('.aer-resubmit-form').forEach(form=>form.addEventListener('submit',event=>{const signature=form.querySelector('[name="signature_data"]'),file=form.querySelector('[name="signature_file"]');if(!signature.value&&!file.files.length){event.preventDefault();showToast('Draw or upload your new signature before resubmitting.','error');}}));
document.addEventListener('click',event=>{const openButton=event.target.closest('[data-open-modal]'),closeButton=event.target.closest('[data-close-modal]');if(openButton){document.getElementById(openButton.dataset.openModal)?.showModal();}if(closeButton){closeButton.closest('dialog')?.close();}});
const requestModal=document.getElementById('requestModal');document.getElementById('openRequestModal').addEventListener('click',()=>requestModal.showModal());document.getElementById('closeRequestModal').addEventListener('click',()=>requestModal.close());document.getElementById('cancelRequestModal').addEventListener('click',()=>requestModal.close());
const initialNotice=<?=json_encode($notice,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;const formModalId=<?=json_encode($formModal,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,formValues=<?=json_encode((object)$formValues,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,noticeOk=<?=$noticeOk?'true':'false'?>;
if(initialNotice&&!(formModalId&&document.getElementById(formModalId)))showToast(initialNotice,noticeOk?'success':'error');
document.querySelectorAll('.aer-edit-budget').forEach(section=>{
 const body=section.querySelector('tbody'),grand=section.querySelector('.budget-grand-total'),fmt=n=>n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
 const recalc=()=>{let total=0;body.querySelectorAll('.budget-item-row').forEach(row=>{const line=(Number(row.querySelector('[name="budget_quantity[]"]').value)||0)*(Number(row.querySelector('[name="budget_unit_cost[]"]').value)||0);total+=line;row.querySelector('.budget-line-total').textContent=fmt(line);});grand.textContent=fmt(total);};
 section.querySelector('.add-budget-item').addEventListener('click',()=>{const row=body.querySelector('.budget-item-row').cloneNode(true);row.querySelectorAll('input').forEach(input=>{input.value=input.name==='budget_quantity[]'?'1':'';});body.appendChild(row);recalc();row.querySelector('input').focus();});
 body.addEventListener('input',recalc);
 body.addEventListener('click',event=>{const remove=event.target.closest('.remove-budget-item');if(!remove)return;const rows=body.querySelectorAll('.budget-item-row');if(rows.length>1)remove.closest('.budget-item-row').remove();else rows[0].querySelectorAll('input').forEach(input=>{input.value=input.name==='budget_quantity[]'?'1':'';});recalc();});
 recalc();
});
document.querySelectorAll('.aer-edit-form').forEach(form=>{
 const tabs=[...form.querySelectorAll('[data-edit-tab]')],panels=[...form.querySelectorAll('[data-edit-panel]')];let active=0;
 const show=(step,focus=true)=>{active=Math.max(0,Math.min(panels.length-1,step));panels.forEach((panel,i)=>panel.hidden=i!==active);tabs.forEach((tab,i)=>{tab.setAttribute('aria-selected',i===active?'true':'false');tab.tabIndex=i===active?0:-1;});if(focus)panels[active].querySelector('input:not([type=hidden]),select,textarea')?.focus({preventScroll:true});form.closest('dialog')?.scrollTo({top:0});};
 const firstInvalid=step=>[...panels[step].querySelectorAll('input,select,textarea')].find(control=>!control.checkValidity());
 const valid=step=>{const control=firstInvalid(step);if(!control)return true;show(step,false);requestAnimationFrame(()=>{control.focus({preventScroll:true});control.scrollIntoView({behavior:'smooth',block:'center'});control.reportValidity();});return false;};
 tabs.forEach((tab,i)=>{tab.addEventListener('click',()=>{for(let step=active;step<i;step++)if(!valid(step))return;show(i);});tab.addEventListener('keydown',event=>{if(event.key!=='ArrowRight'&&event.key!=='ArrowLeft')return;event.preventDefault();const next=(i+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;tabs[next].click();if(active===next)tabs[next].focus();});});
 form.querySelectorAll('[data-edit-next]').forEach(button=>button.addEventListener('click',()=>{if(valid(active))show(active+1);}));
 form.querySelectorAll('[data-edit-back]').forEach(button=>button.addEventListener('click',()=>show(active-1)));
 form.addEventListener('submit',event=>{for(let step=0;step<panels.length;step++)if(!valid(step)){event.preventDefault();event.stopImmediatePropagation();return;}},true);
 form.closest('dialog')?.addEventListener('close',()=>show(0,false));
});
// Show form messages inside the modal instead of a page toast.
function aerFormMessage(dialog,message,type){
 const form=dialog.querySelector('form');if(!form)return false;
 let box=form.querySelector(':scope > .aer-form-alert');
 if(!box){box=document.createElement('div');box.className='aer-form-alert';box.setAttribute('role','alert');box.tabIndex=-1;form.prepend(box);dialog.addEventListener('close',()=>{box.hidden=true;});}
 box.className='aer-form-alert'+(type==='success'?' is-success':type==='error'?' is-error':'');box.textContent=message;box.hidden=false;
 requestAnimationFrame(()=>{dialog.scrollTo({top:0,behavior:'smooth'});box.focus({preventScroll:true});});
 return true;
}
function aerRestoreForm(form,values){
 const addButton=form.querySelector('#addBudgetItem,.add-budget-item');
 for(const [name,value] of Object.entries(values)){
  if(Array.isArray(value)){
   const selector='[name="'+CSS.escape(name+'[]')+'"]';let fields=form.querySelectorAll(selector);
   while(addButton&&fields.length<value.length&&fields.length<100){addButton.click();fields=form.querySelectorAll(selector);}
   fields.forEach((field,index)=>{if(index<value.length)field.value=value[index];});
   fields[0]?.dispatchEvent(new Event('input',{bubbles:true}));
  }else{
   const field=form.querySelector('[name="'+CSS.escape(name)+'"]');
   if(!field||field.type==='file'||field.type==='hidden'||field.disabled)continue;
   field.value=value;if(field.tagName==='SELECT')field.dispatchEvent(new Event('change',{bubbles:true}));
  }
 }
}
(()=>{const dialog=formModalId&&document.getElementById(formModalId);if(!dialog||!initialNotice)return;const form=dialog.querySelector('form');if(form)aerRestoreForm(form,formValues);dialog.showModal();aerFormMessage(dialog,initialNotice,'error');})();
</script>
</div><?php $stmt->close(); require_once __DIR__ . '/includes/footer.php'; ?>
