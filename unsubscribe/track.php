<?php

header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');


define('NETLIFY_TRACK_FUNCTION', 'https://marketingbrlenovo.com.br/.netlify/functions/track');


define('WEBHOOK_URL', 'https://eotz4wvybe4cndl.m.pipedream.net');

$trackingId = $_GET['id'] ?? '';
$clickedUrl = $_GET['url'] ?? null;
$testMode = isset($_GET['test_mode']) || 
            (isset($_SERVER['HTTP_USER_AGENT']) && 
             strpos($_SERVER['HTTP_USER_AGENT'], 'Python-Verification-Script') !== false);

if (empty($trackingId)) {
    returnPixel();
    exit;
}

$eventData = [
    'tracking_id' => $trackingId,
    'event' => $clickedUrl ? 'click' : 'open',
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    'referer' => $_SERVER['HTTP_REFERER'] ?? 'direct',
    'timestamp' => date('c'),
    'test_mode' => $testMode
];

if ($clickedUrl) {
    $eventData['url'] = $clickedUrl;
}

if (!$clickedUrl) {
    $queryString = http_build_query([
        'id' => $trackingId,
        'test_mode' => $testMode ? '1' : '0'
    ]);
    
    header('Location: ' . NETLIFY_TRACK_FUNCTION . '?' . $queryString, true, 302);
    exit;
}

sendToNetlifyAsync(NETLIFY_TRACK_FUNCTION, $eventData);

if (!$testMode && WEBHOOK_URL !== 'https://eotz4wvybe4cndl.m.pipedream.net') {
    sendWebhookAsync(WEBHOOK_URL, $eventData);
}

// Retorna pixel imediatamente
returnPixel();


function sendToNetlifyAsync($url, $data) {
    // Prepara requisição
    $jsonData = json_encode($data);
    
    if (function_exists('exec') && stripos(PHP_OS, 'WIN') === false) {
        $cmd = sprintf(
            'curl -X POST "%s" -H "Content-Type: application/json" -d %s > /dev/null 2>&1 &',
            escapeshellarg($url),
            escapeshellarg($jsonData)
        );
        exec($cmd);
        return;
    }
    
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Lenovo-Track-PHP/1.0'
            ],
            CURLOPT_TIMEOUT => 2,  // Timeout curto
            CURLOPT_CONNECTTIMEOUT => 1
        ]);
        
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        error_log("Track PHP: Erro ao enviar para Netlify: " . $e->getMessage());
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
                'User-Agent: Webhook/1.0'
            ],
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 1
        ]);
        
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        error_log("Track PHP: Erro ao enviar webhook: " . $e->getMessage());
    }
}

function returnPixel() {
    $pixelData = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    echo base64_decode($pixelData);
}
?>