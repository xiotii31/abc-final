<?php
// api/display.php — lightweight TV polling
require_once '../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store');

$pdo = getDB();

$nc = $pdo->query("SELECT * FROM now_calling WHERE id=1")->fetch();

$waiting = $pdo->query("
    SELECT ticket_number, patient_type, current_step
    FROM patients
    WHERE status IN ('waiting','in_progress') AND date_visit=CURDATE()
    ORDER BY FIELD(patient_type,'priority','regular','followup'), created_at ASC
    LIMIT 8
")->fetchAll();

$counts = $pdo->query("
    SELECT patient_type, COUNT(*) as cnt
    FROM patients WHERE status IN ('waiting','in_progress') AND date_visit=CURDATE()
    GROUP BY patient_type
")->fetchAll(PDO::FETCH_KEY_PAIR);

$served = $pdo->query("SELECT COUNT(*) FROM patients WHERE status='done' AND date_visit=CURDATE()")->fetchColumn();

jsonResponse([
    'now_calling' => $nc,
    'queue'       => $waiting,
    'counts'      => ['priority'=>(int)($counts['priority']??0),'regular'=>(int)($counts['regular']??0),'followup'=>(int)($counts['followup']??0)],
    'served'      => (int)$served,
    'time'        => date('H:i:s'),
]);
?>
