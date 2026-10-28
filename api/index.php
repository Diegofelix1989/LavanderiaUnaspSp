<?php

$projectRoot = realpath(__DIR__ . '/..');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($projectRoot === false || !is_string($requestPath)) {
    http_response_code(400);
    exit('Requisição inválida.');
}

$requestPath = rawurldecode($requestPath);
if (str_contains($requestPath, "\0")) {
    http_response_code(400);
    exit('Requisição inválida.');
}

$relativePath = ltrim($requestPath, '/');
if ($relativePath === '') {
    $relativePath = 'index.php';
}

$isPage = $relativePath === 'index.php'
    || preg_match('#\Aviews/[A-Za-z0-9_-]+\.php\z#', $relativePath) === 1;
$isStaticAsset = preg_match('#\A(?:assets|uploads)/[A-Za-z0-9_./-]+\z#', $relativePath) === 1;

if (!$isPage && !$isStaticAsset) {
    http_response_code(404);
    exit('Página não encontrada.');
}

$targetFile = realpath($projectRoot . DIRECTORY_SEPARATOR . $relativePath);
if (
    $targetFile === false
    || !is_file($targetFile)
) {
    http_response_code(404);
    exit('Página não encontrada.');
}

if ($isPage) {
    $viewsPrefix = realpath($projectRoot . DIRECTORY_SEPARATOR . 'views') . DIRECTORY_SEPARATOR;
    if (
        $relativePath !== 'index.php'
        && !str_starts_with($targetFile, $viewsPrefix)
    ) {
        http_response_code(404);
        exit('Página não encontrada.');
    }

    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    $_SERVER['PHP_SELF'] = '/' . $relativePath;
    $_SERVER['SCRIPT_NAME'] = '/' . $relativePath;
    require $targetFile;
    exit;
}

$assetDirectory = str_starts_with($relativePath, 'assets/') ? 'assets' : 'uploads';
$assetRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . $assetDirectory);
$assetPrefix = $assetRoot === false ? '' : $assetRoot . DIRECTORY_SEPARATOR;
$allowedExtensions = ['css', 'gif', 'ico', 'jpg', 'jpeg', 'js', 'png', 'svg', 'webp'];
if (
    $assetPrefix === ''
    || !str_starts_with($targetFile, $assetPrefix)
    || !in_array(strtolower(pathinfo($targetFile, PATHINFO_EXTENSION)), $allowedExtensions, true)
) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$mimeTypes = [
    'css' => 'text/css; charset=utf-8',
    'gif' => 'image/gif',
    'ico' => 'image/x-icon',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'js' => 'application/javascript; charset=utf-8',
    'png' => 'image/png',
    'svg' => 'image/svg+xml',
    'webp' => 'image/webp',
];
$extension = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . filesize($targetFile));
readfile($targetFile);
