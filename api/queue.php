<?php
// api/queue.php — main queue management API
// GET  → full queue state
// POST { action, ticket_number?, step?, ... }
require_once '../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

$pdo = getDB();

// ── Step flow order ──────────────────────────────────────────
// Follow-up patients skip triage/itr/vitals/doctor → go encoder → vaccination
// Regular/Priority: waiting → triage → itr_vitals → yellow_chair → doctor → encoder → vaccination → done
// Severe (blue chair): waiting → triage → itr_vitals → blue_chair → doctor → encoder → vaccination → done

function nextStep(string $currentStep, string $patientType, bool $isSevere = false): string {
    if ($patientType === 'followup') {
        return match($currentStep) {
            'waiting'     => 'encoder',
            'encoder'     => 'vaccination',
            'vaccination' => 'done',
            default       => 'done',
        };
    }
    // Regular / Priority
    return match($currentStep) {
        'waiting'      => 'triage',
        'triage'       => 'itr_vitals',
        'itr_vitals'   => $isSevere ? 'blue_chair' : 'yellow_chair',
        'yellow_chair' => 'doctor',
        'blue_chair'   => 'doctor',
        'doctor'       => 'encoder',
        'encoder'      => 'vaccination',
        'vaccination'  => 'done',
        default        => 'done',
    };
}

// ── GET: queue state ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $patients = $pdo->query("
        SELECT id, ticket_number, patient_type, patient_name, status, current_step,
               step_started_at, called_at, created_at, notes,
               TIMESTAMPDIFF(SECOND, step_started_at, NOW()) AS step_elapsed_seconds
        FROM patients
        WHERE status IN ('waiting','in_progress') AND date_visit = CURDATE()
        ORDER BY FIELD(patient_type,'priority','regular','followup'), created_at ASC
    ")->fetchAll();

    $nc = $pdo->query("SELECT * FROM now_calling WHERE id=1")->fetch();

    $counts = ['priority'=>0,'regular'=>0,'followup'=>0,'total'=>0];
    foreach ($patients as $p) { $counts[$p['patient_type']]++; $counts['total']++; }

    $served = $pdo->query("SELECT COUNT(*) FROM patients WHERE status='done' AND date_visit=CURDATE()")->fetchColumn();
    $total  = $pdo->query("SELECT COUNT(*) FROM patients WHERE date_visit=CURDATE()")->fetchColumn();

    // Enrich each patient with step label and timer info
    $enriched = array_map(function($p) {
        $elapsed  = (int)($p['step_elapsed_seconds'] ?? 0);
        $warnSecs = TIMER_WARN_MINUTES * 60;
        $limSecs  = TIMER_LIMIT_MINUTES * 60;
        $p['step_label']       = stepLabel($p['current_step']);
        $p['timer_elapsed']    = $elapsed;
        $p['timer_remaining']  = max(0, $limSecs - $elapsed);
        $p['timer_warning']    = ($elapsed >= $warnSecs && $elapsed < $limSecs);
        $p['timer_expired']    = ($elapsed >= $limSecs);
        $p['timer_pct']        = min(100, round(($elapsed / $limSecs) * 100));
        return $p;
    }, $patients);

    jsonResponse([
        'queue'       => $enriched,
        'now_calling' => $nc,
        'counts'      => $counts,
        'served'      => (int)$served,
        'total'       => (int)$total,
        'warn_mins'   => TIMER_WARN_MINUTES,
        'limit_mins'  => TIMER_LIMIT_MINUTES,
    ]);
}

// ── POST: actions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? '';

    switch ($action) {

        // Call a specific patient to TV + speaker
        case 'call':
            $ticket = $body['ticket_number'] ?? '';
            if (!$ticket) jsonResponse(['error'=>'ticket_number required'],400);

            $pat = $pdo->prepare("SELECT * FROM patients WHERE ticket_number=:t AND date_visit=CURDATE()");
            $pat->execute(['t'=>$ticket]);
            $p = $pat->fetch();
            if (!$p) jsonResponse(['error'=>'Patient not found'],404);

            // Advance to next step
            $isSevere = ($body['severe'] ?? false) || str_contains(($p['notes']??''), 'severe');
            $newStep  = nextStep($p['current_step'], $p['patient_type'], $isSevere);

            $pdo->prepare("UPDATE patients SET status='in_progress', current_step=:step, step_started_at=NOW(), called_at=NOW() WHERE ticket_number=:t AND date_visit=CURDATE()")
                ->execute(['step'=>$newStep, 't'=>$ticket]);

            // Update TV
            $label = stepLabel($newStep);
            $pdo->prepare("UPDATE now_calling SET ticket_number=:t, patient_type=:pt, current_step=:step, step_label=:lbl WHERE id=1")
                ->execute(['t'=>$ticket, 'pt'=>$p['patient_type'], 'step'=>$newStep, 'lbl'=>$label]);

            $pdo->prepare("INSERT INTO call_log (ticket_number,action,step,performed_by) VALUES (:t,'called',:step,'staff')")
                ->execute(['t'=>$ticket,'step'=>$newStep]);

            jsonResponse(['success'=>true,'ticket'=>$ticket,'step'=>$newStep,'step_label'=>$label]);

        // Advance patient one step forward (manual override)
        case 'advance':
            $ticket = $body['ticket_number'] ?? '';
            $pat = $pdo->prepare("SELECT * FROM patients WHERE ticket_number=:t AND date_visit=CURDATE()");
            $pat->execute(['t'=>$ticket]);
            $p = $pat->fetch();
            if (!$p) jsonResponse(['error'=>'Not found'],404);

            $isSevere = ($body['severe'] ?? false);
            $newStep  = nextStep($p['current_step'], $p['patient_type'], $isSevere);
            $newStatus = ($newStep === 'done') ? 'done' : 'in_progress';

            $pdo->prepare("UPDATE patients SET status=:s, current_step=:step, step_started_at=NOW() WHERE ticket_number=:t AND date_visit=CURDATE()")
                ->execute(['s'=>$newStatus,'step'=>$newStep,'t'=>$ticket]);

            if ($newStep !== 'done') {
                $label = stepLabel($newStep);
                $pdo->prepare("UPDATE now_calling SET ticket_number=:t, patient_type=:pt, current_step=:step, step_label=:lbl WHERE id=1")
                    ->execute(['t'=>$ticket,'pt'=>$p['patient_type'],'step'=>$newStep,'lbl'=>$label]);
            }

            $pdo->prepare("INSERT INTO call_log (ticket_number,action,step,performed_by) VALUES (:t,'step_advanced',:step,'staff')")
                ->execute(['t'=>$ticket,'step'=>$newStep]);

            jsonResponse(['success'=>true,'ticket'=>$ticket,'step'=>$newStep,'status'=>$newStatus]);

        // Mark done
        case 'done':
            $ticket = $body['ticket_number'] ?? '';
            $pdo->prepare("UPDATE patients SET status='done', current_step='done', step_started_at=NOW() WHERE ticket_number=:t AND date_visit=CURDATE()")
                ->execute(['t'=>$ticket]);
            $pdo->prepare("INSERT INTO call_log (ticket_number,action,step,performed_by) VALUES (:t,'done','done','staff')")
                ->execute(['t'=>$ticket]);
            jsonResponse(['success'=>true]);

        // Skip patient
        case 'skip':
            $ticket = $body['ticket_number'] ?? '';
            $pdo->prepare("UPDATE patients SET status='skipped', current_step='done' WHERE ticket_number=:t AND date_visit=CURDATE()")
                ->execute(['t'=>$ticket]);
            $pdo->prepare("INSERT INTO call_log (ticket_number,action,step,performed_by) VALUES (:t,'skipped','done','staff')")
                ->execute(['t'=>$ticket]);
            jsonResponse(['success'=>true]);

        // Add note / mark severe
        case 'set_note':
            $ticket = $body['ticket_number'] ?? '';
            $note   = $body['note'] ?? '';
            $pdo->prepare("UPDATE patients SET notes=:n WHERE ticket_number=:t AND date_visit=CURDATE()")
                ->execute(['n'=>$note,'t'=>$ticket]);
            jsonResponse(['success'=>true]);

        // Reset for new day
        case 'reset_daily':
            $pdo->exec("CALL reset_daily()");
            jsonResponse(['success'=>true,'message'=>'Queue reset']);

        default:
            jsonResponse(['error'=>'Unknown action'],400);
    }
}
?>
