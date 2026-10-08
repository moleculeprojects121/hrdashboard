<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Keep these values on the server. They are not exposed in index.html.
const APPS_SCRIPT_URL = 'https://script.google.com/macros/s/AKfycbwWoQGFU5YuS42gL6kwZmzBjLec_-00cbWC3ld5gWN7kIKu6AuUxfY8uEff_j82bod26A/exec';
const API_TOKEN = 'MOLECULE_123';

function fail_response(string $message, int $status = 500): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function request_apps_script(string $url, ?string $body = null): string {
    $ch = curl_init($url);
    if ($ch === false) {
        fail_response('Could not initialize the database connection.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'Molecule-HR-Dashboard/1.0',
    ];

    if ($body !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $body;
        $options[CURLOPT_HTTPHEADER] = [
            'Accept: application/json',
            'Content-Type: text/plain;charset=utf-8',
        ];
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        fail_response('Google Apps Script connection failed: ' . $error);
    }
    if ($status < 200 || $status >= 300) {
        fail_response('Google Apps Script returned HTTP ' . $status . '.');
    }

    json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        fail_response('Google Apps Script returned an invalid response. Confirm the web app is deployed for Anyone.');
    }

    return $response;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $params = $_GET;
    $params['token'] = API_TOKEN;
    echo request_apps_script(APPS_SCRIPT_URL . '?' . http_build_query($params));
    exit;
}

if ($method !== 'POST') {
    fail_response('Method not allowed.', 405);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);
if (!is_array($body)) {
    fail_response('Invalid JSON request.', 400);
}

$body['token'] = API_TOKEN;
$action = (string) ($body['action'] ?? '');

// Idempotent operations use doGet because Apps Script redirects POST replies.
$getActions = ['update', 'archive', 'restore', 'delete'];
if (in_array($action, $getActions, true)) {
    $params = [
        'token' => API_TOKEN,
        'action' => $action,
        'table' => (string) ($body['table'] ?? ''),
        'id' => (string) ($body['id'] ?? ''),
    ];
    foreach (($body['record'] ?? []) as $key => $value) {
        if ($value !== null) {
            $params['field_' . $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
    }
    echo request_apps_script(APPS_SCRIPT_URL . '?' . http_build_query($params));
    exit;
}

echo request_apps_script(
    APPS_SCRIPT_URL,
    json_encode($body, JSON_UNESCAPED_SLASHES)
);
