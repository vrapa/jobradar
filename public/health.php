<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$database = 'unavailable';
$status = 503;

try {
    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'jobradar';
    $user = getenv('DB_USER') ?: 'jobradar';
    $password = getenv('DB_PASSWORD') ?: '';

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->query('SELECT 1');
    $database = 'ready';
    $status = 200;
} catch (Throwable) {
    $database = 'unavailable';
}

http_response_code($status);
echo json_encode(
    [
        'application' => 'JobRadar',
        'status' => $status === 200 ? 'ready' : 'degraded',
        'database' => $database,
    ],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
);

