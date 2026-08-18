<?php

declare(strict_types=1);

$uri = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$publicRoot = realpath(__DIR__.'/public');
$legacyRoot = realpath(__DIR__.'/../legacy-static');

if (!$publicRoot || !$legacyRoot) {
    http_response_code(500);
    exit('Diretórios da aplicação não encontrados.');
}

$redirects = [
    '/app' => '/pedidos/',
    '/app/pedidos' => '/pedidos/',
    '/app/pdv' => '/balcao/',
    '/app/mesas-comandas' => '/',
    '/app/cozinha' => '/kds/cozinha.html',
];

if (isset($redirects[$uri])) {
    header('Location: '.$redirects[$uri], true, 302);
    exit;
}

$publicCandidate = realpath($publicRoot.DIRECTORY_SEPARATOR.ltrim(str_replace('/', DIRECTORY_SEPARATOR, $uri), DIRECTORY_SEPARATOR));
if ($publicCandidate && str_starts_with($publicCandidate, $publicRoot) && is_file($publicCandidate)) {
    return false;
}

if (!str_starts_with($uri, '/api/') && $uri !== '/api' && !str_starts_with($uri, '/filament') && !str_starts_with($uri, '/livewire')) {
    $relative = $uri === '/' ? 'index.html' : ltrim($uri, '/');
    $candidate = realpath($legacyRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));

    if ($candidate && is_dir($candidate)) {
        $candidate = realpath($candidate.DIRECTORY_SEPARATOR.'index.html');
    }

    if ($candidate && str_starts_with($candidate, $legacyRoot) && is_file($candidate)) {
        $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        $mimeTypes = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
        ];
        header('Content-Type: '.($mimeTypes[$extension] ?? 'application/octet-stream'));
        header('Cache-Control: no-store, max-age=0');
        header('Content-Length: '.filesize($candidate));
        readfile($candidate);
        exit;
    }
}

require $publicRoot.'/index.php';
