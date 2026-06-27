<?php
// api/register.php
// POST { type: "priority"|"regular"|"followup", name: "optional" }
require_once '../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error'=>'POST only'],405);

$body = json_decode(file_get_contents('php://input'), true);
$type = $body['type'] ?? '';
$name = trim($body['name'] ?? '');

if (!in_array($type, ['priority','regular','followup']))
    jsonResponse(['error'=>'Invalid type'],400);

$prefix = ['priority'=>'P','regular'=>'R','followup'=>'F'][$type];
$pdo = getDB();
$pdo->beginTransaction();

$pdo->prepare("INSERT INTO queue_counters (prefix, date_active, current_count)
    VALUES (:p, CURDATE(), 1)
    ON DUPLICATE KEY UPDATE current_count = current_count + 1")
    ->execute(['p'=>$prefix]);

$count = $pdo->prepare("SELECT current_count FROM queue_counters WHERE prefix=:p AND date_active=CURDATE()");
$count->execute(['p'=>$prefix]);
$num = $count->fetchColumn();

$ticket = $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);

$pdo->prepare("INSERT INTO patients (ticket_number, patient_type, patient_name, status, current_step, date_visit)
    VALUES (:t, :type, :name, 'waiting', 'waiting', CURDATE())")
    ->execute(['t'=>$ticket, 'type'=>$type, 'name'=>$name ?: null]);

$pdo->prepare("INSERT INTO call_log (ticket_number, action, step, performed_by) VALUES (:t,'registered','waiting','receptionist')")
    ->execute(['t'=>$ticket]);

$pdo->commit();
jsonResponse(['success'=>true, 'ticket_number'=>$ticket, 'patient_type'=>$type, 'patient_name'=>$name]);
?>
