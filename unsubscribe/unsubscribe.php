<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('NETLIFY_UNSUBSCRIBE_FUNCTION', 'https://marketingbrlenovo.com.br/.netlify/functions/unsubscribe');
define('NETLIFY_GET_EMAIL_FUNCTION', 'https://marketingbrlenovo.com.br/.netlify/functions/get-email');

define('WEBHOOK_URL', 'https://eotz4wvybe4cndl.m.pipedream.net');

define('LOG_DIR', __DIR__ . '/logs');
define('TRACKING_MAP_FILE', LOG_DIR . '/tracking_map.json');
define('UNSUBSCRIBE_LOGS_FILE', LOG_DIR . '/unsubscribe_logs.json');


$testMode = isset($_GET['test_mode']) || 
            isset($_POST['test_mode']) ||
            (isset($_SERVER['HTTP_USER_AGENT']) && 
             strpos($_SERVER['HTTP_USER_AGENT'], 'Python-Verification-Script') !== false);


$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'get_email' && $method === 'GET') {
    handleGetEmail();
    exit;
}

if ($action === 'save_preferences' || $method === 'POST') {
    handleSavePreferences();
    exit;
}


http_response_code(200);
echo json_encode([
    'api' => 'Lenovo Unsubscribe API',
    'version' => '2.0',
    'endpoints' => [
        'get_email' => 'GET ?action=get_email&id={tracking_id}',
        'save_preferences' => 'POST (JSON body)'
    ]
]);
exit;

function handleGetEmail() {
    global $testMode;
    
    $trackingId = $_GET['id'] ?? '';

    if (empty($trackingId)) {
        respondError(400, 'Tracking ID não fornecido');
    }
    
    try {
        $url = NETLIFY_UNSUBSCRIBE_FUNCTION . '?action=get_email&id=' . urlencode($trackingId);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Unsubscribe-PHP/2.0'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data && isset($data['success']) && $data['success']) {
                respondSuccess($data);
            }
        }
    } catch (Exception $e) {
        error_log("Unsubscribe PHP: " . $e->getMessage());
    }
    
    if (file_exists(TRACKING_MAP_FILE)) {
        $trackingMapContent = file_get_contents(TRACKING_MAP_FILE);
        $trackingMap = json_decode($trackingMapContent, true);
        
        if (is_array($trackingMap) && isset($trackingMap[$trackingId])) {
            respondSuccess([
                'success' => true,
                'email' => $trackingMap[$trackingId],
                'tracking_id' => $trackingId,
                'source' => 'local_fallback'
            ]);
        }
    }
    
    respondError(404, 'Email não encontrado para este ID');
}

function handleSavePreferences() {
    global $testMode;
    
    // Lê corpo da requisição
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        respondError(400, 'Dados inválidos ou JSON malformado');
    }
    
    $trackingId = $input['tracking_id'] ?? '';
    $email = $input['email'] ?? '';
    $comunicacao = $input['comunicacao'] ?? [];
    $pausa = $input['pausa'] ?? 'Sem pausa';
    $unsubscribed = $input['unsubscribed'] ?? false;
    $event = $input['event'] ?? 'preferences';

    if (empty($email)) {
        respondError(400, 'Email é obrigatório');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respondError(400, 'Email inválido');
    }
    
    $logEntry = [
        'event' => $event,
        'tracking_id' => $trackingId ?: null,
        'email' => $email,
        'comunicacao' => is_array($comunicacao) ? $comunicacao : [],
        'pausa' => $pausa,
        'unsubscribed' => (bool)$unsubscribed,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'timestamp' => date('c'),
        'test_mode' => $testMode
    ];
    
    $netlifySuccess = false;
    try {
        $ch = curl_init(NETLIFY_UNSUBSCRIBE_FUNCTION);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($logEntry),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Unsubscribe-PHP/2.0'
            ],
            CURLOPT_TIMEOUT => 8
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 || $httpCode === 201) {
            $netlifySuccess = true;
        }
    } catch (Exception $e) {
        error_log("Unsubscribe PHP:" . $e->getMessage());
    }
    
    saveLocalLog($logEntry);

    if (!$testMode && WEBHOOK_URL !== 'https://eotz4wvybe4cndl.m.pipedream.net') {
        sendWebhookAsync(WEBHOOK_URL, $logEntry);
    }
    
    respondSuccess([
        'success' => true,
        'message' => 'Preferências salvas com sucesso',
        'saved_to_netlify' => $netlifySuccess,
        'email' => $email
    ]);
}


function saveLocalLog($logEntry) {
    try {
        if (!is_dir(LOG_DIR)) {
            mkdir(LOG_DIR, 0777, true);
        }
        
        $logs = [];
        if (file_exists(UNSUBSCRIBE_LOGS_FILE)) {
            $logsContent = file_get_contents(UNSUBSCRIBE_LOGS_FILE);
            $logs = json_decode($logsContent, true);
            
            if (!is_array($logs)) {
                $logs = [];
            }
        }
        
        $logs[] = $logEntry;
        
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }
        
        file_put_contents(
            UNSUBSCRIBE_LOGS_FILE, 
            json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        
        return true;
    } catch (Exception $e) {
        error_log("Unsubscribe PHP: Erro ao salvar log local: " . $e->getMessage());
        return false;
    }
}


function sendWebhookAsync($url, $data) {
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Webhook/2.0'
            ],
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 1
        ]);
        
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        error_log("Unsubscribe PHP" . $e->getMessage());
    }
}

function respondSuccess($data) {
    http_response_code(200);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function respondError($code, $message) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>