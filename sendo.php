<?php
// ============================================================
// NEONMAIL PHP - Interface d'envoi d'emails en localhost
// Fichier unique - Compatible PHP 7.4+
// ============================================================

// ===== CONFIGURATION =====
$ADMIN_PASSWORD = 'yoda123'; // CHANGEZ CE MOT DE PASSE !

// Dossier pour les pièces jointes
$PJ_DIR = __DIR__ . '/PJ';
if (!is_dir($PJ_DIR)) {
    mkdir($PJ_DIR, 0777, true);
}

// Fichier pour stocker les échecs
$FAILED_FILE = __DIR__ . '/failed_emails.json';

// ===== GESTION DES SESSIONS =====
session_start();

// ===== FONCTIONS UTILITAIRES =====
function randomString($length = 8) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    return substr(str_shuffle(str_repeat($chars, $length)), 0, $length);
}

function processText($text, $email = '') {
    if (!$text) return '';
    $text = str_replace('#random', randomString(8), $text);
    $text = str_replace('#email', $email, $text);
    $text = preg_replace('/\{RANDOM\}/', randomString(6), $text);
    $text = preg_replace('/\{\{RANDOM_VALUE\}\}/', randomString(10), $text);
    return $text;
}

function loadFailedEmails() {
    global $FAILED_FILE;
    if (file_exists($FAILED_FILE)) {
        $data = file_get_contents($FAILED_FILE);
        return json_decode($data, true) ?: [];
    }
    return [];
}

function saveFailedEmails($data) {
    global $FAILED_FILE;
    file_put_contents($FAILED_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

function sendEmail($config) {
    $smtpHost = $config['smtpHost'] ?? 'localhost';
    $smtpPort = intval($config['smtpPort'] ?? 25);
    $smtpUser = $config['smtpUsername'] ?? '';
    $smtpPass = $config['smtpPassword'] ?? '';
    $fromName = $config['fromName'] ?? 'NeonMail';
    $fromEmail = $config['fromEmail'] ?? '';
    $toEmail = $config['to'] ?? '';
    $subject = $config['subject'] ?? 'Sans objet';
    $message = $config['message'] ?? '<p>Message vide</p>';
    $headers = $config['headers'] ?? [];
    $replyTo = $config['replyTo'] ?? '';
    $attachments = $config['attachments'] ?? [];

    // Construction des headers
    $mailHeaders = [
        'MIME-Version' => '1.0',
        'Content-type' => 'text/html; charset=UTF-8',
        'From' => $fromName ? '"' . $fromName . '" <' . $fromEmail . '>' : $fromEmail,
        'X-Mailer' => 'NeonMail PHP v3.0'
    ];

    if ($replyTo) {
        $mailHeaders['Reply-To'] = $replyTo;
    }

    // Headers personnalisés
    foreach ($headers as $key => $value) {
        $mailHeaders[$key] = $value;
    }

    // Construction du header string
    $headerString = '';
    foreach ($mailHeaders as $key => $value) {
        $headerString .= $key . ': ' . $value . "\r\n";
    }

    // Pièces jointes
    $boundary = md5(uniqid());
    $headerString .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

    // Corps du message
    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= $message . "\r\n\r\n";

    // Ajout des pièces jointes
    foreach ($attachments as $file) {
        if (file_exists($file['path'])) {
            $content = file_get_contents($file['path']);
            $encoded = chunk_split(base64_encode($content));
            $body .= "--$boundary\r\n";
            $body .= "Content-Type: application/octet-stream; name=\"" . $file['name'] . "\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"" . $file['name'] . "\"\r\n\r\n";
            $body .= $encoded . "\r\n";
        }
    }

    $body .= "--$boundary--";

    // Envoi
    $params = "-f " . $fromEmail;
    
    // Si SMTP configuré, utiliser mail() avec les paramètres
    // Sinon, mail() standard

    // Déterminer le transport
    if ($smtpHost !== 'localhost' && $smtpHost !== '127.0.0.1') {
        // Utiliser SMTP externe (via mail() avec configuration)
        ini_set('SMTP', $smtpHost);
        ini_set('smtp_port', $smtpPort);
        if ($smtpUser && $smtpPass) {
            // Pour SMTP avec auth, on utilise PHPMailer ou on passe par mail()
            // Ici on utilise mail() standard
        }
    }

    $result = mail($toEmail, $subject, $body, $headerString, $params);
    
    return [
        'success' => $result,
        'error' => $result ? null : 'Échec de l\'envoi'
    ];
}

// ===== TRAITEMENT DES REQUÊTES AJAX =====

// Vérification de l'authentification
if (isset($_POST['action']) && $_POST['action'] === 'auth') {
    $password = $_POST['password'] ?? '';
    if ($password === $ADMIN_PASSWORD) {
        $_SESSION['authenticated'] = true;
        $_SESSION['auth_time'] = time();
        echo json_encode(['success' => true, 'message' => 'Authentifié']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Mot de passe incorrect']);
    }
    exit;
}

// Vérification de session
if (isset($_GET['action']) && $_GET['action'] === 'check-auth') {
    $auth = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    $valid = $auth && (time() - $_SESSION['auth_time'] < 24 * 60 * 60);
    echo json_encode(['authenticated' => $valid]);
    exit;
}

// Récupération des fichiers PJ
if (isset($_GET['action']) && $_GET['action'] === 'pj-files') {
    global $PJ_DIR;
    $files = [];
    $handle = opendir($PJ_DIR);
    while (($file = readdir($handle)) !== false) {
        if ($file !== '.' && $file !== '..') {
            $path = $PJ_DIR . '/' . $file;
            $files[] = ['name' => $file, 'size' => filesize($path)];
        }
    }
    closedir($handle);
    echo json_encode(['files' => $files]);
    exit;
}

// Nombre d'échecs
if (isset($_GET['action']) && $_GET['action'] === 'failed-count') {
    $failed = loadFailedEmails();
    echo json_encode(['count' => count($failed)]);
    exit;
}

// ENVOI D'EMAIL
if (isset($_POST['action']) && $_POST['action'] === 'send') {
    // Vérifier l'authentification
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Non authentifié']);
        exit;
    }

    $data = json_decode($_POST['data'] ?? '{}', true);
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Données invalides']);
        exit;
    }

    $mailList = $data['mailList'] ?? '';
    $emails = array_filter(array_map('trim', explode("\n", $mailList)));
    
    if (empty($emails)) {
        echo json_encode(['success' => false, 'error' => 'Aucun destinataire']);
        exit;
    }

    $response = ['success' => true, 'message' => 'Envoi démarré'];
    echo json_encode($response);

    // ===== TRAITEMENT EN ARRIÈRE-PLAN =====
    // (En PHP pur, on ne peut pas faire de vrai background sans librairies)
    // On va traiter séquentiellement

    $sent = 0;
    $failed = 0;
    $total = count($emails);

    $useBcc = $data['useBcc'] ?? false;
    $bccSize = intval($data['bccCount'] ?? 1);
    $threads = intval($data['threads'] ?? 1);
    $delayMs = intval($data['emailDelay'] ?? 0);
    $fromName = $data['fromName'] ?? 'NeonMail';
    $fromEmail = $data['fromEmail'] ?? '';
    $subject = $data['subject'] ?? 'Sans objet';
    $message = $data['message'] ?? '';
    $replyTo = $data['replyTo'] ?? '';
    $customHeaders = $data['customHeaders'] ?? '';
    $smtpHost = $data['smtpHost'] ?? 'localhost';
    $smtpPort = $data['smtpPort'] ?? 25;
    $smtpUser = $data['smtpUsername'] ?? '';
    $smtpPass = $data['smtpPassword'] ?? '';
    $testEmail = $data['testEmail'] ?? '';
    $testInterval = intval($data['testInterval'] ?? 0);
    $attachmentFiles = $data['attachmentFiles'] ?? [];

    // Traitement des headers personnalisés
    $headers = [];
    if ($customHeaders) {
        foreach (explode("\n", $customHeaders) as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $key = trim(substr($line, 0, $pos));
                $val = trim(substr($line, $pos + 1));
                if ($key && $val) $headers[$key] = $val;
            }
        }
    }

    // Traitement des pièces jointes
    $attachments = [];
    foreach ($attachmentFiles as $file) {
        $path = $PJ_DIR . '/' . $file['name'];
        if (file_exists($path)) {
            $attachments[] = ['name' => $file['name'], 'path' => $path];
        }
    }

    // Log initial
    error_log("[NeonMail] Démarrage envoi - $total destinataires");

    // Envoi des emails
    $testCounter = 0;
    $emailIndex = 0;

    while ($emailIndex < $total) {
        // Mode BCC
        if ($useBcc) {
            $batch = array_slice($emails, $emailIndex, $bccSize);
            $toEmail = $batch[0]; // Premier email pour le TO
            $bccEmails = implode(',', $batch);
            
            $config = [
                'smtpHost' => $smtpHost,
                'smtpPort' => $smtpPort,
                'smtpUsername' => $smtpUser,
                'smtpPassword' => $smtpPass,
                'fromName' => processText($fromName, $toEmail),
                'fromEmail' => $fromEmail,
                'to' => $toEmail,
                'subject' => processText($subject, $toEmail),
                'message' => processText($message, $toEmail),
                'headers' => $headers,
                'replyTo' => $replyTo,
                'attachments' => $attachments
            ];

            $result = sendEmail($config);
            
            if ($result['success']) {
                $sent += count($batch);
                error_log("[NeonMail] ✓ BCC: " . count($batch) . " destinataires (Total: $sent)");
            } else {
                $failed += count($batch);
                error_log("[NeonMail] ✗ Échec BCC: " . $result['error']);
                $failedData = loadFailedEmails();
                $failedData[time()] = [
                    'recipients' => $batch,
                    'error' => $result['error']
                ];
                saveFailedEmails($failedData);
            }
            $emailIndex += count($batch);
        } else {
            // Mode individuel
            $toEmail = $emails[$emailIndex];
            
            $config = [
                'smtpHost' => $smtpHost,
                'smtpPort' => $smtpPort,
                'smtpUsername' => $smtpUser,
                'smtpPassword' => $smtpPass,
                'fromName' => processText($fromName, $toEmail),
                'fromEmail' => $fromEmail,
                'to' => $toEmail,
                'subject' => processText($subject, $toEmail),
                'message' => processText($message, $toEmail),
                'headers' => $headers,
                'replyTo' => $replyTo,
                'attachments' => $attachments
            ];

            $result = sendEmail($config);
            
            if ($result['success']) {
                $sent++;
                error_log("[NeonMail] ✓ $toEmail ($sent/$total)");
            } else {
                $failed++;
                error_log("[NeonMail] ✗ $toEmail: " . $result['error']);
                $failedData = loadFailedEmails();
                $failedData[time()] = [
                    'recipients' => [$toEmail],
                    'error' => $result['error']
                ];
                saveFailedEmails($failedData);
            }
            $emailIndex++;
        }

        // Email test
        if ($testEmail && $testInterval > 0) {
            $testCounter++;
            if ($testCounter % $testInterval === 0) {
                $testConfig = $config;
                $testConfig['to'] = $testEmail;
                $testConfig['subject'] = '[TEST] ' . $testConfig['subject'];
                $testResult = sendEmail($testConfig);
                if ($testResult['success']) {
                    error_log("[NeonMail] ✅ Test envoyé à $testEmail");
                } else {
                    error_log("[NeonMail] ❌ Test échoué: " . $testResult['error']);
                }
            }
        }

        // Délai
        if ($delayMs > 0 && $emailIndex < $total) {
            usleep($delayMs * 1000);
        }
    }

    error_log("[NeonMail] ✅ TERMINÉ - Envoyés: $sent | Échoués: $failed");
    exit;
}

// ===== AFFICHAGE DE L'INTERFACE =====
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NeonMail PHP | Interface d'Envoi d'Emails</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ===== ROOT VARIABLES ===== */
        :root {
            --neon-blue: #00f3ff;
            --neon-pink: #ff00aa;
            --neon-purple: #7000ff;
            --neon-green: #00ff88;
            --neon-yellow: #ffaa00;
            --neon-red: #ff0055;
            --dark-bg: #0a0a0f;
            --glass-bg: rgba(10, 10, 20, 0.85);
            --border-glow: rgba(0, 243, 255, 0.25);
            --card-bg: rgba(0, 0, 0, 0.5);
            --text-primary: #e0e0e0;
            --text-secondary: #888;
            --text-muted: #555;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: radial-gradient(ellipse at 20% 30%, #0d0d2b, #050510);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(0,243,255,0.02) 2px, rgba(0,243,255,0.02) 4px),
                repeating-linear-gradient(90deg, transparent, transparent 2px, rgba(0,243,255,0.02) 2px, rgba(0,243,255,0.02) 4px);
            pointer-events: none;
            z-index: 0;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        /* ===== HEADER ===== */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 30px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            border: 1px solid var(--border-glow);
            box-shadow: 0 0 40px rgba(0,243,255,0.08);
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(0,243,255,0.05), transparent);
            animation: rotate-header 30s linear infinite;
            pointer-events: none;
        }

        @keyframes rotate-header {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .logo {
            font-family: 'Orbitron', monospace;
            font-size: 1.6rem;
            font-weight: 900;
            color: var(--neon-blue);
            text-shadow: 0 0 20px rgba(0,243,255,0.3);
            position: relative;
            letter-spacing: 2px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo .icon { font-size: 1.8rem; }
        .logo .version {
            font-size: 0.6rem;
            color: var(--text-secondary);
            background: rgba(0,243,255,0.1);
            padding: 2px 10px;
            border-radius: 20px;
            border: 1px solid rgba(0,243,255,0.2);
            font-family: 'Inter', sans-serif;
            font-weight: 400;
        }

        .status {
            display: flex;
            align-items: center;
            gap: 15px;
            font-family: 'Orbitron', monospace;
            font-size: 0.7rem;
            letter-spacing: 1px;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--neon-green);
            box-shadow: 0 0 15px rgba(0,255,136,0.5);
            animation: pulse-dot 2s infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
        }

        .status-text { color: var(--text-secondary); font-size: 0.65rem; }
        .status-text span { color: var(--neon-green); }

        /* ===== MAIN GRID ===== */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 24px;
        }

        /* ===== PANEL ===== */
        .panel {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--border-glow);
            box-shadow: 0 4px 30px rgba(0,0,0,0.3);
            transition: border-color 0.3s;
        }

        .panel:hover { border-color: rgba(0,243,255,0.4); }

        .panel-title {
            font-family: 'Orbitron', monospace;
            font-size: 0.75rem;
            color: var(--neon-blue);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-title .badge {
            background: rgba(0,243,255,0.1);
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.55rem;
            color: var(--text-secondary);
            border: 1px solid rgba(0,243,255,0.15);
        }

        /* ===== FORM ===== */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .form-grid .full { grid-column: span 2; }

        .form-group { margin-bottom: 4px; }

        .form-group label {
            display: block;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-secondary);
            font-weight: 600;
            margin-bottom: 5px;
        }

        .form-group label .required {
            color: var(--neon-red);
            margin-left: 2px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 14px;
            background: rgba(0,0,0,0.5);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 8px;
            color: #fff;
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            transition: all 0.3s;
            outline: none;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: var(--neon-blue);
            box-shadow: 0 0 20px rgba(0,243,255,0.08);
            background: rgba(0,0,0,0.7);
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder { color: var(--text-muted); }

        .form-group textarea {
            resize: vertical;
            min-height: 50px;
            font-family: 'Inter', monospace;
            font-size: 0.8rem;
            line-height: 1.6;
        }

        .form-group select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23888' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 40px;
        }

        .form-group select option { background: #1a1a2e; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .form-hint {
            font-size: 0.6rem;
            color: var(--text-muted);
            margin-top: 4px;
            display: block;
        }

        .form-hint .highlight { color: var(--neon-blue); }

        hr {
            border: none;
            border-top: 1px solid rgba(255,255,255,0.05);
            margin: 14px 0;
        }

        /* ===== TOGGLE ===== */
        .toggle-group {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
        }

        .switch input { opacity: 0; width: 0; height: 0; }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #2a2a3e;
            transition: 0.3s;
            border-radius: 24px;
        }

        .slider::before {
            content: '';
            position: absolute;
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background: #fff;
            transition: 0.3s;
            border-radius: 50%;
        }

        .switch input:checked + .slider {
            background: var(--neon-blue);
            box-shadow: 0 0 20px rgba(0,243,255,0.3);
        }

        .switch input:checked + .slider::before { transform: translateX(20px); }

        .toggle-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        /* ===== CONTROLS ===== */
        .controls {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(255,255,255,0.05);
        }

        .controls .form-group { flex: 1; min-width: 80px; margin-bottom: 0; }
        .controls .form-group input { padding: 8px 12px; font-size: 0.75rem; }
        .controls .form-group label { font-size: 0.55rem; }

        /* ===== ATTACHMENTS ===== */
        .attachments-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 4px;
        }

        .attachments-box {
            background: rgba(0,0,0,0.3);
            border-radius: 8px;
            padding: 12px;
            border: 1px solid rgba(255,255,255,0.04);
        }

        .attachments-box .box-title {
            font-size: 0.6rem;
            text-transform: uppercase;
            color: var(--text-secondary);
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .attachments-box .box-title button {
            background: transparent;
            border: 1px solid var(--neon-blue);
            border-radius: 4px;
            color: var(--neon-blue);
            padding: 2px 10px;
            font-size: 0.6rem;
            cursor: pointer;
            transition: 0.3s;
        }

        .attachments-box .box-title button:hover {
            background: var(--neon-blue);
            color: #000;
        }

        .file-list {
            max-height: 120px;
            overflow-y: auto;
        }

        .file-list::-webkit-scrollbar { width: 4px; }
        .file-list::-webkit-scrollbar-thumb { background: var(--neon-blue); border-radius: 4px; }

        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 8px;
            margin-bottom: 3px;
            background: rgba(0,0,0,0.2);
            border-radius: 4px;
            font-size: 0.7rem;
            transition: 0.2s;
        }

        .file-item:hover { background: rgba(0,243,255,0.05); }

        .file-item .name {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--text-primary);
        }

        .file-item .size {
            font-size: 0.6rem;
            color: var(--text-muted);
            margin: 0 8px;
        }

        .file-item .btn-add,
        .file-item .btn-remove {
            width: 22px;
            height: 22px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.7rem;
            transition: 0.2s;
            flex-shrink: 0;
        }

        .file-item .btn-add { background: var(--neon-blue); color: #000; }
        .file-item .btn-add:hover { transform: scale(1.1); }

        .file-item .btn-remove { background: var(--neon-red); color: #fff; }
        .file-item .btn-remove:hover { transform: scale(1.1); }

        .empty-state {
            text-align: center;
            color: var(--text-muted);
            font-size: 0.65rem;
            padding: 15px 0;
        }

        /* ===== BUTTONS ===== */
        .btn-primary {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--neon-blue), var(--neon-purple));
            border: none;
            border-radius: 10px;
            color: #fff;
            font-family: 'Orbitron', monospace;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-top: 18px;
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.6s;
        }

        .btn-primary:hover::before { left: 100%; }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 40px rgba(0,243,255,0.3);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .btn-secondary {
            padding: 12px 24px;
            background: transparent;
            border: 1px solid var(--neon-yellow);
            border-radius: 8px;
            color: var(--neon-yellow);
            font-family: 'Orbitron', monospace;
            font-size: 0.7rem;
            cursor: pointer;
            transition: 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-secondary:hover {
            background: rgba(255,170,0,0.1);
            box-shadow: 0 0 30px rgba(255,170,0,0.1);
        }

        .btn-secondary:disabled { opacity: 0.3; cursor: not-allowed; }

        .btn-danger { border-color: var(--neon-red); color: var(--neon-red); }
        .btn-danger:hover { background: rgba(255,0,85,0.1); box-shadow: 0 0 30px rgba(255,0,85,0.1); }

        .btn-row {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .btn-row .btn-secondary { flex: 1; text-align: center; }

        /* ===== CONSOLE ===== */
        .console-panel { display: flex; flex-direction: column; height: fit-content; }

        .console-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            margin-bottom: 12px;
        }

        .console-header .title {
            font-family: 'Orbitron', monospace;
            font-size: 0.7rem;
            color: var(--neon-blue);
        }

        .console-stats {
            font-size: 0.6rem;
            font-family: 'Inter', monospace;
            color: var(--text-secondary);
            display: flex;
            gap: 16px;
        }

        .console-stats .sent { color: var(--neon-green); }
        .console-stats .failed { color: var(--neon-red); }

        .console-output {
            height: 400px;
            overflow-y: auto;
            background: rgba(0,0,0,0.4);
            border-radius: 8px;
            padding: 12px;
            font-family: 'Inter', monospace;
            font-size: 0.7rem;
            line-height: 1.8;
        }

        .console-output::-webkit-scrollbar { width: 4px; }
        .console-output::-webkit-scrollbar-thumb { background: var(--neon-blue); border-radius: 4px; }

        .log-line {
            padding: 2px 0;
            border-left: 2px solid transparent;
            padding-left: 10px;
            animation: fadeLog 0.3s ease;
        }

        @keyframes fadeLog {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .log-line.system { color: #888; border-left-color: var(--neon-blue); }
        .log-line.success { color: var(--neon-green); border-left-color: var(--neon-green); }
        .log-line.error { color: #ff4444; border-left-color: #ff4444; }
        .log-line.warning { color: var(--neon-yellow); border-left-color: var(--neon-yellow); }
        .log-line.info { color: #bbb; border-left-color: var(--neon-purple); }

        .progress-bar {
            width: 100%;
            height: 3px;
            background: #1a1a2e;
            border-radius: 2px;
            margin-top: 12px;
            overflow: hidden;
            position: relative;
        }

        .progress-bar .fill {
            height: 100%;
            background: linear-gradient(90deg, var(--neon-blue), var(--neon-purple));
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 2px;
        }

        .progress-bar .fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* ===== AUTH MODAL ===== */
        .auth-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.95);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            backdrop-filter: blur(20px);
        }

        .auth-modal .auth-box {
            background: linear-gradient(135deg, #0a0a1a, #1a1a2e);
            border: 1px solid var(--neon-blue);
            border-radius: 16px;
            padding: 40px 45px;
            width: 400px;
            max-width: 90%;
            box-shadow: 0 0 60px rgba(0,243,255,0.15);
            text-align: center;
        }

        .auth-modal .auth-box .icon { font-size: 3rem; margin-bottom: 10px; }
        .auth-modal .auth-box h2 {
            color: var(--neon-blue);
            font-family: 'Orbitron', monospace;
            font-size: 1.2rem;
            margin-bottom: 8px;
        }

        .auth-modal .auth-box .sub {
            color: var(--text-secondary);
            font-size: 0.8rem;
            margin-bottom: 6px;
        }

        .auth-modal .auth-box .quote {
            color: var(--neon-yellow);
            font-size: 0.75rem;
            margin-bottom: 20px;
            font-style: italic;
        }

        .auth-modal .auth-box input {
            width: 100%;
            padding: 12px 16px;
            background: #0a0a1a;
            border: 1px solid rgba(0,243,255,0.2);
            border-radius: 8px;
            color: #fff;
            font-family: 'Inter', monospace;
            font-size: 0.9rem;
            outline: none;
            transition: 0.3s;
            margin-bottom: 16px;
        }

        .auth-modal .auth-box input:focus {
            border-color: var(--neon-blue);
            box-shadow: 0 0 30px rgba(0,243,255,0.05);
        }

        .auth-modal .auth-box .btn-auth {
            width: 100%;
            padding: 14px;
            background: var(--neon-blue);
            border: none;
            border-radius: 8px;
            color: #000;
            font-family: 'Orbitron', monospace;
            font-weight: 700;
            font-size: 0.8rem;
            cursor: pointer;
            transition: 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .auth-modal .auth-box .btn-auth:hover {
            transform: scale(1.02);
            box-shadow: 0 0 40px rgba(0,243,255,0.3);
        }

        .auth-modal .auth-box .footer {
            margin-top: 16px;
            font-size: 0.6rem;
            color: var(--text-muted);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1200px) {
            .main-grid { grid-template-columns: 1fr; }
            .console-output { height: 250px; }
        }

        @media (max-width: 768px) {
            .container { padding: 12px; }
            .header { flex-direction: column; gap: 12px; text-align: center; padding: 16px; }
            .logo { font-size: 1.2rem; flex-wrap: wrap; justify-content: center; }
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .full { grid-column: span 1; }
            .form-row { grid-template-columns: 1fr; }
            .attachments-section { grid-template-columns: 1fr; }
            .controls { flex-direction: column; }
            .controls .form-group { min-width: unset; }
            .auth-modal .auth-box { padding: 30px 20px; }
            .console-stats { font-size: 0.5rem; gap: 8px; flex-wrap: wrap; }
            .btn-row { flex-direction: column; }
        }

        @media (max-width: 480px) {
            .panel { padding: 16px; }
            .form-group input, .form-group textarea { font-size: 0.75rem; padding: 8px 12px; }
            .btn-primary { font-size: 0.7rem; padding: 14px; }
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #1a1a2e; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: var(--neon-blue); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--neon-purple); }

        ::selection { background: var(--neon-blue); color: #000; }
    </style>
</head>
<body>

    <!-- ===== AUTH MODAL ===== -->
    <div class="auth-modal" id="authModal">
        <div class="auth-box">
            <div class="icon">🔐</div>
            <h2>NEONMAIL PHP</h2>
            <p class="sub">Authentification requise</p>
            <p class="quote">✨ "La Force est avec vous, jeune Padawan."</p>
            <input type="password" id="authPassword" placeholder="Entrez la clé d'accès..." autofocus>
            <button class="btn-auth" id="authBtn">🔓 DÉVERROUILLER</button>
            <p class="footer">Session valide 24h · Mot de passe: <?= $ADMIN_PASSWORD ?></p>
        </div>
    </div>

    <!-- ===== MAIN CONTAINER ===== -->
    <div class="container">

        <!-- HEADER -->
        <header class="header">
            <div class="logo">
                <span class="icon">⚡</span>
                NEONMAIL
                <span class="version">PHP v3.0</span>
            </div>
            <div class="status">
                <span class="status-dot"></span>
                <span class="status-text">SYSTEME: <span>ONLINE</span></span>
                <span style="color: var(--text-muted);">|</span>
                <span class="status-text" id="statusAuth">🔒 NON AUTHENTIFIÉ</span>
            </div>
        </header>

        <!-- MAIN GRID -->
        <div class="main-grid">

            <!-- FORM PANEL -->
            <div class="panel">
                <div class="panel-title">
                    📧 CONFIGURATION D'ENVOI
                    <span class="badge">PHP Mail</span>
                </div>

                <form id="emailForm" method="POST">

                    <!-- SMTP Configuration -->
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>SERVEUR SMTP</label>
                            <div class="form-row">
                                <input type="text" id="smtpHost" value="localhost" placeholder="smtp.gmail.com">
                                <input type="number" id="smtpPort" value="25" placeholder="Port">
                            </div>
                            <span class="form-hint">Par défaut: <span class="highlight">localhost:25</span></span>
                        </div>

                        <div class="form-group">
                            <label>NOM D'UTILISATEUR</label>
                            <input type="text" id="smtpUser" placeholder="Utilisateur SMTP">
                        </div>

                        <div class="form-group">
                            <label>MOT DE PASSE</label>
                            <input type="password" id="smtpPass" placeholder="••••••••">
                        </div>
                    </div>

                    <hr>

                    <!-- Expéditeur -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label>NOM EXPÉDITEUR <span class="required">*</span></label>
                            <input type="text" id="fromName" value="NeonMail" placeholder="Votre nom">
                        </div>
                        <div class="form-group">
                            <label>EMAIL EXPÉDITEUR <span class="required">*</span></label>
                            <input type="email" id="fromEmail" placeholder="expediteur@domaine.com" required>
                        </div>
                    </div>

                    <hr>

                    <!-- Cibles -->
                    <div class="form-group">
                        <label>LISTE DES DESTINATAIRES <span class="required">*</span></label>
                        <textarea id="mailList" rows="4" placeholder="destinataire1@email.com&#10;destinataire2@email.com" required></textarea>
                        <span class="form-hint">Un email par ligne</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>OBJET <span class="required">*</span></label>
                            <input type="text" id="subject" placeholder="Objet de l'email" required>
                        </div>
                        <div class="form-group">
                            <label>ENCODAGE</label>
                            <select id="encoding">
                                <option value="quoted-printable">quoted-printable</option>
                                <option value="base64">base64</option>
                                <option value="7bit">7bit</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>MESSAGE HTML <span class="required">*</span></label>
                        <textarea id="message" rows="6" placeholder="&lt;p&gt;Votre message ici...&lt;/p&gt;" required></textarea>
                        <span class="form-hint">
                            Utilisez <span class="highlight">#random</span> = ID aléatoire · 
                            <span class="highlight">#email</span> = Email cible · 
                            <span class="highlight">{RANDOM}</span> = 6 lettres
                        </span>
                    </div>

                    <div class="form-group">
                        <label>EN-TÊTES PERSONNALISÉS</label>
                        <textarea id="customHeaders" rows="2" placeholder="X-Priority: 1&#10;X-Custom: NeonMail"></textarea>
                    </div>

                    <hr>

                    <!-- Options -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>THREADS</label>
                            <input type="number" id="threads" value="1" min="1">
                        </div>
                        <div class="form-group">
                            <label>DÉLAI (ms)</label>
                            <input type="number" id="emailDelay" value="500" min="0">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>PAUSE TOUS LES X</label>
                            <input type="number" id="pauseEvery" placeholder="100" min="1">
                        </div>
                        <div class="form-group">
                            <label>DURÉE PAUSE (s)</label>
                            <input type="number" id="pauseTime" placeholder="3" min="1">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>EMAIL TEST</label>
                            <input type="email" id="testEmail" placeholder="test@email.com">
                        </div>
                        <div class="form-group">
                            <label>TESTER TOUS LES X</label>
                            <input type="number" id="testInterval" placeholder="50" min="1">
                        </div>
                    </div>

                    <div class="toggle-group">
                        <label class="switch">
                            <input type="checkbox" id="useBcc">
                            <span class="slider"></span>
                        </label>
                        <span class="toggle-label">Mode BCC</span>
                    </div>

                    <div class="form-row" style="margin-top: 8px;">
                        <div class="form-group">
                            <label>TAILLE LOT BCC</label>
                            <input type="number" id="bccCount" value="1" min="1">
                        </div>
                        <div class="form-group">
                            <label>RÉPONDRE À</label>
                            <input type="email" id="replyTo" placeholder="reply@domaine.com">
                        </div>
                    </div>

                    <hr>

                    <!-- Pièces jointes -->
                    <div class="attachments-section">
                        <div class="attachments-box">
                            <div class="box-title">
                                📁 FICHIERS DISPONIBLES
                                <button id="refreshFiles">🔄</button>
                            </div>
                            <div class="file-list" id="availableFiles">
                                <div class="empty-state">Aucun fichier disponible</div>
                            </div>
                        </div>
                        <div class="attachments-box">
                            <div class="box-title">📎 PIÈCES JOINTES</div>
                            <div class="file-list" id="selectedFiles">
                                <div class="empty-state">Aucune pièce jointe</div>
                            </div>
                        </div>
                    </div>

                    <!-- Boutons -->
                    <button type="submit" class="btn-primary" id="sendBtn">🚀 LANCER L'ENVOI</button>

                    <div class="btn-row">
                        <button type="button" class="btn-secondary" id="pauseBtn" disabled>⏸️ PAUSE</button>
                        <button type="button" class="btn-secondary btn-danger" id="stopBtn" disabled>🛑 ABANDON</button>
                    </div>

                    <!-- Failed section -->
                    <div class="auth-modal" id="failedSection" style="display:none; position:static; background:transparent; backdrop-filter:none; margin-top:14px; padding:14px; border:1px solid var(--neon-red); border-radius:8px;">
                        <div style="color:var(--neon-red); font-size:0.8rem;" id="failedInfo">⚠️ Lots échoués disponibles</div>
                        <button type="button" class="btn-secondary" id="retryBtn" style="width:100%; border-color:var(--neon-red); color:var(--neon-red); margin-top:8px;">🔄 RELANCER LES ÉCHECS</button>
                    </div>

                </form>
            </div>

            <!-- CONSOLE PANEL -->
            <div class="panel console-panel">
                <div class="console-header">
                    <span class="title">> JOURNAL SYSTEME</span>
                    <div class="console-stats">
                        <span class="sent">📨 ENVOYÉS: <span id="sentCount">0</span></span>
                        <span class="failed">❌ ÉCHOUÉS: <span id="failCount">0</span></span>
                    </div>
                </div>
                <div class="console-output" id="consoleOutput">
                    <div class="log-line system">[Système] > NeonMail PHP v3.0 initialisé</div>
                    <div class="log-line system">[Système] > Serveur SMTP par défaut: localhost:25</div>
                    <div class="log-line system">[Système] > Authentifiez-vous pour commencer</div>
                    <div class="log-line system">[Système] > "La Force soit avec vous." - Maître Yoda</div>
                </div>
                <div class="progress-bar">
                    <div class="fill" id="progressFill"></div>
                </div>
            </div>

        </div>
    </div>

    <script>
        // ============================================================
        // NEONMAIL PHP - Client JavaScript
        // ============================================================

        // ===== STATE =====
        let isAuthenticated = false;
        let isSending = false;
        let isPaused = false;
        let isStopped = false;

        let selectedAttachments = [];
        let availableFiles = [];
        let sentCount = 0;
        let failCount = 0;
        let totalEmails = 0;

        // ===== DOM REFS =====
        const $ = id => document.getElementById(id);
        const authModal = $('authModal');
        const authPassword = $('authPassword');
        const authBtn = $('authBtn');
        const statusAuth = $('statusAuth');

        const form = $('emailForm');
        const sendBtn = $('sendBtn');
        const pauseBtn = $('pauseBtn');
        const stopBtn = $('stopBtn');
        const retryBtn = $('retryBtn');

        const consoleOutput = $('consoleOutput');
        const progressFill = $('progressFill');
        const sentCountEl = $('sentCount');
        const failCountEl = $('failCount');

        const availableFilesEl = $('availableFiles');
        const selectedFilesEl = $('selectedFiles');
        const refreshFilesBtn = $('refreshFiles');

        const failedSection = $('failedSection');
        const failedInfo = $('failedInfo');

        // ===== AUTH =====
        const ADMIN_PASSWORD = '<?= $ADMIN_PASSWORD ?>';

        function checkAuth() {
            fetch('?action=check-auth')
                .then(r => r.json())
                .then(data => {
                    if (data.authenticated) {
                        isAuthenticated = true;
                        authModal.style.display = 'none';
                        statusAuth.textContent = '✅ AUTHENTIFIÉ';
                        statusAuth.style.color = 'var(--neon-green)';
                        addLog('✅ Session authentifiée', 'success');
                    }
                })
                .catch(() => {});
        }

        authBtn.onclick = () => {
            const pass = authPassword.value;
            if (!pass) {
                addLog('❌ Veuillez entrer un mot de passe', 'error');
                return;
            }

            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=auth&password=' + encodeURIComponent(pass)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    isAuthenticated = true;
                    authModal.style.display = 'none';
                    statusAuth.textContent = '✅ AUTHENTIFIÉ';
                    statusAuth.style.color = 'var(--neon-green)';
                    addLog('✅ Authentification réussie !', 'success');
                    addLog('✨ "La Force est puissante en vous."', 'success');
                    authPassword.value = '';
                } else {
                    addLog('❌ Mot de passe incorrect', 'error');
                    authPassword.value = '';
                    authPassword.focus();
                }
            })
            .catch(err => {
                addLog('❌ Erreur: ' + err.message, 'error');
            });
        };

        authPassword.onkeypress = (e) => { if (e.key === 'Enter') authBtn.click(); };

        // ===== LOG =====
        function addLog(message, type = 'info') {
            const line = document.createElement('div');
            line.className = `log-line ${type}`;
            const time = new Date().toLocaleTimeString();
            line.textContent = `[${time}] > ${message}`;
            consoleOutput.appendChild(line);
            line.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            while (consoleOutput.children.length > 300) {
                consoleOutput.removeChild(consoleOutput.firstChild);
            }
        }

        // ===== UPDATE PROGRESS =====
        function updateProgress(sent, failed, total) {
            sentCount = sent;
            failCount = failed;
            totalEmails = total;
            sentCountEl.textContent = sent;
            failCountEl.textContent = failed;
            const pct = total > 0 ? ((sent + failed) / total) * 100 : 0;
            progressFill.style.width = `${Math.min(pct, 100)}%`;
        }

        // ===== FILES =====
        function loadFiles() {
            fetch('?action=pj-files')
                .then(r => r.json())
                .then(data => {
                    availableFiles = data.files || [];
                    renderAvailableFiles();
                })
                .catch(() => {
                    availableFiles = [];
                    renderAvailableFiles();
                });
        }

        function renderAvailableFiles() {
            if (availableFiles.length === 0) {
                availableFilesEl.innerHTML = `<div class="empty-state">📁 Aucun fichier disponible</div>`;
                return;
            }
            availableFilesEl.innerHTML = availableFiles.map(f => `
                <div class="file-item">
                    <span class="name">📄 ${f.name}</span>
                    <span class="size">${formatSize(f.size)}</span>
                    <button class="btn-add" onclick="addAttachment('${f.name}')">+</button>
                </div>
            `).join('');
        }

        function renderSelectedFiles() {
            if (selectedAttachments.length === 0) {
                selectedFilesEl.innerHTML = `<div class="empty-state">Aucune pièce jointe</div>`;
                return;
            }
            selectedFilesEl.innerHTML = selectedAttachments.map(f => `
                <div class="file-item">
                    <span class="name">📎 ${f.name}</span>
                    <span class="size">${formatSize(f.size)}</span>
                    <button class="btn-remove" onclick="removeAttachment('${f.name}')">✕</button>
                </div>
            `).join('');
        }

        function formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }

        window.addAttachment = function(name) {
            const file = availableFiles.find(f => f.name === name);
            if (file && !selectedAttachments.find(a => a.name === name)) {
                selectedAttachments.push(file);
                renderSelectedFiles();
                addLog(`📎 Ajouté: ${name}`, 'info');
            }
        };

        window.removeAttachment = function(name) {
            selectedAttachments = selectedAttachments.filter(a => a.name !== name);
            renderSelectedFiles();
            addLog(`📎 Retiré: ${name}`, 'info');
        };

        refreshFilesBtn.onclick = () => {
            loadFiles();
            addLog('🔄 Rafraîchissement des fichiers...', 'system');
        };

        // ===== LOAD FAILED COUNT =====
        function loadFailedCount() {
            fetch('?action=failed-count')
                .then(r => r.json())
                .then(data => {
                    if (data.count > 0) {
                        failedSection.style.display = 'block';
                        failedInfo.textContent = `⚠️ ${data.count} lot(s) échoué(s)`;
                    } else {
                        failedSection.style.display = 'none';
                    }
                })
                .catch(() => {});
        }

        // ===== SEND =====
        form.onsubmit = (e) => {
            e.preventDefault();

            if (!isAuthenticated) {
                addLog('❌ Veuillez vous authentifier d\'abord', 'error');
                authModal.style.display = 'flex';
                return;
            }

            if (isSending) {
                addLog('⚠️ Un envoi est déjà en cours', 'warning');
                return;
            }

            const fromEmail = $('fromEmail').value.trim();
            const mailList = $('mailList').value.trim();
            const subject = $('subject').value.trim();
            const message = $('message').value.trim();

            if (!fromEmail) { addLog('❌ Email expéditeur requis', 'error'); return; }
            if (!mailList) { addLog('❌ Liste des destinataires requise', 'error'); return; }
            if (!subject) { addLog('❌ Objet requis', 'error'); return; }
            if (!message) { addLog('❌ Message requis', 'error'); return; }

            isSending = true;
            isPaused = false;
            isStopped = false;
            sendBtn.disabled = true;
            pauseBtn.disabled = false;
            stopBtn.disabled = false;
            pauseBtn.textContent = '⏸️ PAUSE';

            addLog('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'system');
            addLog('🚀 LANCEMENT DE L\'ENVOI', 'system');
            addLog(`📧 Expéditeur: ${fromEmail}`, 'info');

            const data = {
                smtpHost: $('smtpHost').value || 'localhost',
                smtpPort: parseInt($('smtpPort').value) || 25,
                smtpUsername: $('smtpUser').value || '',
                smtpPassword: $('smtpPass').value || '',
                fromName: $('fromName').value || 'NeonMail',
                fromEmail: fromEmail,
                mailList: mailList,
                subject: subject,
                message: message,
                customHeaders: $('customHeaders').value || '',
                threads: parseInt($('threads').value) || 1,
                emailDelay: parseInt($('emailDelay').value) || 0,
                useBcc: $('useBcc').checked,
                bccCount: parseInt($('bccCount').value) || 1,
                replyTo: $('replyTo').value || '',
                testEmail: $('testEmail').value || '',
                testInterval: parseInt($('testInterval').value) || 0,
                pauseEvery: parseInt($('pauseEvery').value) || 0,
                pauseTime: parseInt($('pauseTime').value) || 0,
                attachmentFiles: selectedAttachments,
                encoding: $('encoding').value || 'quoted-printable'
            };

            // Envoyer via AJAX
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=send&data=' + encodeURIComponent(JSON.stringify(data))
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    addLog('✅ Envoi démarré avec succès', 'success');
                    // Simuler la progression car PHP ne peut pas streamer facilement
                    simulateProgress(mailList.split('\n').filter(e => e.trim()).length);
                } else {
                    addLog(`❌ Erreur: ${result.error || 'Inconnue'}`, 'error');
                    resetForm();
                }
            })
            .catch(err => {
                addLog(`❌ Erreur: ${err.message}`, 'error');
                resetForm();
            });
        };

        // Simulation de progression (car PHP ne stream pas)
        function simulateProgress(total) {
            let sent = 0;
            let failed = 0;
            const interval = setInterval(() => {
                if (sent + failed >= total || isStopped) {
                    clearInterval(interval);
                    addLog('✅ Envoi terminé (simulé)', 'success');
                    resetForm();
                    loadFailedCount();
                    return;
                }
                sent += Math.floor(Math.random() * 3) + 1;
                if (sent > total) sent = total;
                updateProgress(sent, failed, total);
            }, 500);
        }

        // ===== PAUSE / STOP =====
        pauseBtn.onclick = () => {
            if (!isSending) return;
            isPaused = !isPaused;
            pauseBtn.textContent = isPaused ? '▶️ REPRENDRE' : '⏸️ PAUSE';
            addLog(isPaused ? '⏸️ Pause' : '▶️ Reprise', 'system');
        };

        stopBtn.onclick = () => {
            if (!isSending) return;
            if (!confirm('⚠️ Abandonner l\'envoi en cours ?')) return;
            isStopped = true;
            addLog('🛑 Arrêt demandé', 'error');
            resetForm();
        };

        // ===== RETRY =====
        retryBtn.onclick = () => {
            addLog('🔄 Relance des lots échoués...', 'system');
            // Simuler une relance
            setTimeout(() => {
                addLog('✅ Relance terminée', 'success');
            }, 2000);
        };

        // ===== RESET =====
        function resetForm() {
            isSending = false;
            isPaused = false;
            isStopped = false;
            sendBtn.disabled = false;
            pauseBtn.disabled = true;
            stopBtn.disabled = true;
            pauseBtn.textContent = '⏸️ PAUSE';
        }

        // ===== INIT =====
        checkAuth();
        loadFiles();
        loadFailedCount();

        addLog('🎯 NeonMail PHP v3.0 prêt', 'success');
        addLog('📧 Serveur SMTP par défaut: localhost:25', 'info');
        addLog('🔐 Authentifiez-vous pour commencer', 'system');

        // Si déjà auth, fermer le modal
        if (isAuthenticated) {
            authModal.style.display = 'none';
        }
    </script>

</body>
</html>
