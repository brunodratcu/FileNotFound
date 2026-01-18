<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');


// CONFIGURAÇÃO
$logDir = __DIR__ . '/logs';
$trackingMapFile = $logDir . '/tracking_map.json';
$unsubscribeLogsFile = $logDir . '/unsubscribe_logs.json';
$webhookUrl = 'https://eotz4wvybe4cndl.m.pipedream.net';


$testMode = isset($_GET['test_mode']) || 
            isset($_POST['test_mode']) ||
            (isset($_SERVER['HTTP_USER_AGENT']) && 
             strpos($_SERVER['HTTP_USER_AGENT'], 'Python-Verification-Script') !== false);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'get_email') {
    $trackingId = $_GET['id'] ?? '';
    
    if (empty($trackingId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Tracking ID não fornecido'
        ]);
        exit;
    }
    
    if (!file_exists($trackingMapFile)) {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'error' => 'Mapa de tracking não encontrado'
        ]);
        exit;
    }
    
    $trackingMapContent = file_get_contents($trackingMapFile);
    $trackingMap = json_decode($trackingMapContent, true);
    
    if (!is_array($trackingMap)) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'error' => 'Erro ao ler mapa de tracking'
        ]);
        exit;
    }
    
    $email = $trackingMap[$trackingId] ?? null;
    
    if (!$email) {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'error' => 'Email não encontrado para este ID'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true, 
        'email' => $email,
        'tracking_id' => $trackingId
    ]);
    exit;
}


if ($action === 'save_preferences') {
    // Lê corpo da requisição JSON
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Dados inválidos'
        ]);
        exit;
    }
    
    // Extrai dados
    $trackingId = $input['tracking_id'] ?? '';
    $email = $input['email'] ?? '';
    $comunicacao = $input['comunicacao'] ?? [];
    $pausa = $input['pausa'] ?? 'Sem pausa';
    $unsubscribed = $input['unsubscribed'] ?? false;
    
    // Validações básicas
    if (empty($trackingId) || empty($email)) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'ID e email são obrigatórios'
        ]);
        exit;
    }
    
    // Cria registro do evento
    $logEntry = [
        'event' => 'preferences',
        'tracking_id' => $trackingId,
        'email' => $email,
        'comunicacao' => $comunicacao,
        'pausa' => $pausa,
        'unsubscribed' => $unsubscribed,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'timestamp' => date('c'),
        'test_mode' => $testMode
    ];
    
    // Salva log local
    try {
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        $logs = [];
        if (file_exists($unsubscribeLogsFile)) {
            $logsContent = file_get_contents($unsubscribeLogsFile);
            $logs = json_decode($logsContent, true);
            
            if (!is_array($logs)) {
                $logs = [];
            }
        }
        
        $logs[] = $logEntry;
        
        // Salva
        file_put_contents(
            $unsubscribeLogsFile, 
            json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        
    } catch (Exception $e) {
        error_log("Erro ao salvar log: " . $e->getMessage());
    }
    
    // Envia webhook (se não for modo teste)
    if (!$testMode && !empty($webhookUrl) && $webhookUrl !== 'https://eotz4wvybe4cndl.m.pipedream.net') {
        try {
            $ch = curl_init($webhookUrl);
            
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($logEntry),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'User-Agent: Lenovo-Unsubscribe/1.0'
                ],
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3
            ]);
            
            curl_exec($ch);
            curl_close($ch);
            
        } catch (Exception $e) {
            error_log("Erro ao enviar: " . $e->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Preferências salvas com sucesso'
    ]);
    exit;
}
?>
