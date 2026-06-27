<?php
// config.php — XAMPP defaults
define('DB_HOST',    'localhost');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_NAME',    'abc_queue');
define('DB_CHARSET', 'utf8mb4');

// How many minutes before a timer warning is shown
define('TIMER_WARN_MINUTES',   18);
// How many minutes before auto-escalation alert fires
define('TIMER_LIMIT_MINUTES',  20);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-cache');
    echo json_encode($data);
    exit;
}

// Step labels shown on TV and staff monitor
function stepLabel(string $step): string {
    return [
        'waiting'      => 'Waiting — Please stand by',
        'triage'       => 'Proceed to Triage Area',
        'itr_vitals'   => 'Fill ITR & Vital Signs Check',
        'yellow_chair' => 'Proceed to Yellow Chair — Wait for Doctor',
        'blue_chair'   => 'Proceed to Blue Chair — Wait for Doctor (Skin Testing)',
        'doctor'       => 'Proceed to Doctor Consultation',
        'encoder'      => 'Proceed to Encoder\'s Counter',
        'vaccination'  => 'Proceed to Vaccination Area',
        'done'         => 'Thank you — You are done for today',
    ][$step] ?? $step;
}
?>
