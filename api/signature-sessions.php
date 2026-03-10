<?php
declare(strict_types=1);

/**
 * Production signature session API for QR-based cross-device signing.
 *
 * Endpoints:
 * - POST   /api/signature-sessions
 * - GET    /api/signature-sessions/{sessionId}
 * - DELETE /api/signature-sessions/{sessionId}
 * - POST   /api/signature-sessions/{sessionId}/complete
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const SESSION_TTL_SECONDS = 2 * 60 * 60; // 2 hours

function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function sessionStorePath(): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'perspective_pov_tools';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . DIRECTORY_SEPARATOR . 'signature_sessions.json';
}

function loadSessions(): array
{
    $path = sessionStorePath();
    if (!file_exists($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveSessions(array $sessions): void
{
    $path = sessionStorePath();
    $temp = $path . '.tmp';
    $json = json_encode($sessions, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        jsonResponse(500, ['error' => 'Failed to serialize session store']);
    }

    $written = @file_put_contents($temp, $json, LOCK_EX);
    if ($written === false) {
        jsonResponse(500, ['error' => 'Failed to write session store']);
    }

    @rename($temp, $path);
}

function cleanupExpiredSessions(array $sessions): array
{
    $now = time();
    foreach ($sessions as $id => $session) {
        $createdAt = (int)($session['createdAt'] ?? 0);
        if ($createdAt <= 0 || ($now - $createdAt) > SESSION_TTL_SECONDS) {
            unset($sessions[$id]);
        }
    }
    return $sessions;
}

function isValidSessionId(?string $sessionId): bool
{
    if (!$sessionId) {
        return false;
    }
    return (bool)preg_match('/^[A-Za-z0-9_-]{6,128}$/', $sessionId);
}

function extractSessionIdFromPath(): ?string
{
    $session = isset($_GET['session']) ? (string)$_GET['session'] : '';
    if ($session !== '') {
        return $session;
    }

    $pathInfo = isset($_SERVER['PATH_INFO']) ? trim((string)$_SERVER['PATH_INFO'], '/') : '';
    if ($pathInfo === '') {
        return null;
    }

    $parts = explode('/', $pathInfo);
    return $parts[0] ?? null;
}

function extractActionFromPath(): ?string
{
    $action = isset($_GET['action']) ? (string)$_GET['action'] : '';
    if ($action !== '') {
        return $action;
    }

    $pathInfo = isset($_SERVER['PATH_INFO']) ? trim((string)$_SERVER['PATH_INFO'], '/') : '';
    if ($pathInfo === '') {
        return null;
    }

    $parts = explode('/', $pathInfo);
    return $parts[1] ?? null;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sessionId = extractSessionIdFromPath();
$action = extractActionFromPath();
$body = readJsonBody();

$sessions = cleanupExpiredSessions(loadSessions());

if ($method === 'POST' && !$sessionId) {
    $newSessionId = (string)($body['sessionId'] ?? '');
    if (!isValidSessionId($newSessionId)) {
        jsonResponse(400, ['error' => 'sessionId is required']);
    }

    $sessions[$newSessionId] = [
        'sessionId' => $newSessionId,
        'status' => 'waiting',
        'signature' => null,
        'createdAt' => time(),
    ];
    saveSessions($sessions);
    jsonResponse(200, ['ok' => true, 'sessionId' => $newSessionId]);
}

if (!isValidSessionId($sessionId)) {
    jsonResponse(400, ['error' => 'Invalid session ID']);
}

if ($method === 'GET' && $action === null) {
    if (!isset($sessions[$sessionId])) {
        jsonResponse(404, ['error' => 'Session not found']);
    }
    jsonResponse(200, $sessions[$sessionId]);
}

if ($method === 'DELETE' && $action === null) {
    unset($sessions[$sessionId]);
    saveSessions($sessions);
    jsonResponse(200, ['ok' => true]);
}

if ($method === 'POST' && $action === 'complete') {
    if (!isset($sessions[$sessionId])) {
        jsonResponse(404, ['error' => 'Session not found']);
    }

    $signature = isset($body['signature']) ? (string)$body['signature'] : '';
    if ($signature === '') {
        jsonResponse(400, ['error' => 'signature is required']);
    }

    $sessions[$sessionId]['status'] = 'complete';
    $sessions[$sessionId]['signature'] = $signature;
    $sessions[$sessionId]['completedAt'] = time();
    saveSessions($sessions);
    jsonResponse(200, ['ok' => true]);
}

jsonResponse(404, ['error' => 'Not found']);
