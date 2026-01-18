<?php

header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');

// CONFIGURAÇÃO
$logDir = __DIR__ . '/logs';
$trackingLogsFile = $logDir . '/tracking_logs.json';
$trackingMapFile = $logDir . '/tracking_map.json';
$webhookUrl = 'https://eotz4wvybe4cndl.m.pipedream.net';


$testMode = isset($_GET['test_mode']) || 
            isset($_SERVER['HTTP_X_TEST_MODE']) || 
            (isset($_SERVER['HTTP_USER_AGENT']) && 
             strpos($_SERVER['HTTP_USER_AGENT'], 'Python-Verification-Script') !== false);


$trackingId = $_GET['id'] ?? '';
$clickedUrl = $_GET['url'] ?? null;
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$referer = $_SERVER['HTTP_REFERER'] ?? 'direct';
$timestamp = date('c'); // Formato ISO 8601

// VALIDAÇÃO: SE NÃO TEM TRACKING ID, RETORNA PIXEL E SAIR
if (empty($trackingId)) {
    returnPixel();
    exit;
}


$email = null;
if (file_exists($trackingMapFile)) {
    $trackingMapContent = file_get_contents($trackingMapFile);
    $trackingMap = json_decode($trackingMapContent, true);
    
    if (is_array($trackingMap)) {
        $email = $trackingMap[$trackingId] ?? null;
    }
}


$eventType = $clickedUrl ? 'click' : 'open';

$logEntry = [
    'tracking_id' => $trackingId,
    'event' => $eventType,
    'email' => $email,
    'ip' => $ip,
    'user_agent' => $userAgent,
    'timestamp' => $timestamp,
    'test_mode' => $testMode
];

if ($clickedUrl) {
    $logEntry['url'] = $clickedUrl;
    $logEntry['referer'] = $referer;
}

try {
    // Cria pasta logs se não existir
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    // Carrega logs existentes
    $logs = [];
    if (file_exists($trackingLogsFile)) {
        $logsContent = file_get_contents($trackingLogsFile);
        $logs = json_decode($logsContent, true);
        
        // Se não for array válido, inicializa como array vazio
        if (!is_array($logs)) {
            $logs = [];
        }
    }
    
    // Adiciona novo registro
    $logs[] = $logEntry;
    
    // Salva de volta
    file_put_contents(
        $trackingLogsFile, 
        json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    
} 


if (!$testMode && !empty($webhookUrl) && $webhookUrl !== 'https://eotz4wvybe4cndl.m.pipedream.net') {
    try {
        $ch = curl_init($webhookUrl);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($logEntry),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Lenovo-Tracking/1.0'
            ],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);
    } catch (Exception $e) {
        error_log("Erro ao enviar: " . $e->getMessage());
    }
}


returnPixel();

function returnPixel() {
    // GIF transparente 1x1 em base64
    $pixelData = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    echo base64_decode($pixelData);
}
?>