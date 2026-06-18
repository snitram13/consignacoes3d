<?php
/* Envia o recibo em PDF por email diretamente ao cliente (via SMTP).
   Recebe: POST com csrf, movement_id e o ficheiro 'pdf' (gerado no browser). */
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

function fail(string $code, string $msg, int $http = 400): void {
    http_response_code($http);
    echo json_encode(['ok' => false, 'code' => $code, 'error' => $msg]);
    exit;
}

$u = current_user();
if (!$u) fail('auth', 'Sessão expirada. Atualize a página e entre de novo.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('method', 'Método inválido.', 405);
if (!hash_equals(csrf_token(), $_POST['csrf'] ?? '')) fail('csrf', 'Sessão expirada. Atualize a página.', 403);

$useBrevo = BREVO_API_KEY !== '';
if (!$useBrevo && (SMTP_HOST === '' || SMTP_USER === '')) {
    fail('smtp_not_configured', 'Envio por servidor não configurado (nem Brevo nem SMTP).');
}

/* movimento + cliente, garantindo que pertence ao utilizador */
$st = db()->prepare('
    SELECT m.*, c.name AS c_name, c.email AS c_email
    FROM movements m
    JOIN clients c ON c.id = m.client_id
    WHERE m.id = ? AND c.user_id = ?
');
$st->execute([(int)($_POST['movement_id'] ?? 0), $u['id']]);
$m = $st->fetch();
if (!$m) fail('not_found', 'Recibo não encontrado.', 404);
if (!$m['c_email'] || !filter_var($m['c_email'], FILTER_VALIDATE_EMAIL)) {
    fail('no_client_email', 'Este cliente não tem email válido na ficha. Edite o cliente e adicione o email.');
}

/* PDF enviado pelo browser */
if (empty($_FILES['pdf']['tmp_name']) || !is_uploaded_file($_FILES['pdf']['tmp_name'])) {
    fail('no_pdf', 'PDF do recibo em falta.');
}
if ($_FILES['pdf']['size'] > 5 * 1024 * 1024) fail('pdf_too_big', 'PDF demasiado grande.');
$pdfData = file_get_contents($_FILES['pdf']['tmp_name']);
if (substr($pdfData, 0, 5) !== '%PDF-') fail('bad_pdf', 'O ficheiro recebido não é um PDF.');

$titulo = $m['type'] === 'acerto' ? 'Recibo de Consignação · Acerto' : 'Recibo de Consignação · Entrega';
$assunto = $titulo . ' — ' . $m['c_name'] . ' — Nº ' . $m['rec_id'];
$corpo = (string)($_POST['body'] ?? '');
if ($corpo === '') {
    $corpo = $titulo . "\nNº " . $m['rec_id'] . ' · ' . fmt_date($m['mov_date']) . "\n\nSegue em anexo o recibo em PDF.";
}
$pdfName = 'Recibo_' . preg_replace('/[^\w\-]+/', '_', $m['rec_id']) . '_' . date('d-m-Y', strtotime($m['mov_date'])) . '.pdf';

$fromName  = SMTP_FROM_NAME ?: ($u['brand'] ?: ($u['name'] ?: APP_NAME));
$fromEmail = SMTP_USER ?: ($u['email'] ?: '');
$userEmail = ($u['email'] && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) ? $u['email'] : '';

/* ── Opção A: Brevo (API HTTP, porta 443 — funciona no Render grátis) ── */
if ($useBrevo) {
    if ($fromEmail === '') {
        fail('no_sender', 'Falta o email remetente (defina SMTP_USER ou o email no perfil).');
    }
    $payload = [
        'sender'      => ['name' => $fromName, 'email' => $fromEmail],
        'to'          => [['email' => $m['c_email'], 'name' => $m['c_name']]],
        'subject'     => $assunto,
        'textContent' => $corpo,
        'attachment'  => [['content' => base64_encode($pdfData), 'name' => $pdfName]],
    ];
    if ($userEmail) {
        $payload['cc']      = [['email' => $userEmail]];          // cópia para o fornecedor
        $payload['replyTo'] = ['email' => $userEmail, 'name' => $fromName];
    }
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['api-key: ' . BREVO_API_KEY, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 25,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($http >= 200 && $http < 300) {
        echo json_encode(['ok' => true, 'to' => $m['c_email'], 'via' => 'brevo']);
        exit;
    }
    $detail = $cerr ?: (is_string($resp) ? substr($resp, 0, 300) : '');
    fail('send_failed', 'Falha no envio (Brevo, HTTP ' . $http . '): ' . $detail, 500);
}

/* ── Opção B: SMTP (PHPMailer) — uso local ou Render pago ── */
require_once __DIR__ . '/includes/phpmailer/PHPMailer.php';
require_once __DIR__ . '/includes/phpmailer/SMTP.php';
require_once __DIR__ . '/includes/phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

try {
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->Port = (int)SMTP_PORT;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = (int)SMTP_PORT === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;

    $fromName = SMTP_FROM_NAME ?: ($u['brand'] ?: ($u['name'] ?: APP_NAME));
    $mail->setFrom(SMTP_USER, $fromName);
    $mail->addAddress($m['c_email'], $m['c_name']);
    if ($u['email'] && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
        $mail->addCC($u['email']); // cópia para o fornecedor
    }
    $mail->addReplyTo($u['email'] && filter_var($u['email'], FILTER_VALIDATE_EMAIL) ? $u['email'] : SMTP_USER, $u['name'] ?: $fromName);

    $mail->Subject = $assunto;
    $mail->Body = $corpo;
    $mail->addStringAttachment($pdfData, $pdfName, PHPMailer::ENCODING_BASE64, 'application/pdf');

    $mail->send();
    echo json_encode(['ok' => true, 'to' => $m['c_email']]);
} catch (Exception $e) {
    fail('send_failed', 'Falha no envio: ' . $mail->ErrorInfo, 500);
}
