<?php
declare(strict_types=1);

/**
 * Simple server-side export counter.
 *
 * Endpoints:
 * - GET  /api/exports        -> { value: number }
 * - POST /api/exports        -> increments and returns { value: number }
 * - POST /api/exports/reset?key=SECRET -> { value: number }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const RESET_KEY = 'perspectivetools';

function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function storePath(): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'perspective_pov_tools';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'exports_count.json';
}

function readCount(): int
{
    $path = storePath();
    if (!file_exists($path)) {
        return 0;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return 0;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['value'])) {
        return 0;
    }
    $value = (int)$decoded['value'];
    return $value >= 0 ? $value : 0;
}

function writeCount(int $value): void
{
    $payload = json_encode(['value' => max(0, $value)], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        jsonResponse(500, ['error' => 'Failed to serialize export count']);
    }
    $path = storePath();
    $temp = $path . '.tmp';
    $written = @file_put_contents($temp, $payload, LOCK_EX);
    if ($written === false) {
        jsonResponse(500, ['error' => 'Failed to write export count']);
    }
    @rename($temp, $path);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

if ($method === 'GET') {
    jsonResponse(200, ['value' => readCount()]);
}

if ($method === 'POST') {
    if ($action === 'reset') {
        $key = isset($_GET['key']) ? (string)$_GET['key'] : '';
        if ($key !== RESET_KEY) {
            jsonResponse(403, ['error' => 'Forbidden']);
        }
        writeCount(0);
        jsonResponse(200, ['value' => 0]);
    }
    $current = readCount();
    $next = $current + 1;
    writeCount($next);
    jsonResponse(200, ['value' => $next]);
}

jsonResponse(405, ['error' => 'Method not allowed']);
