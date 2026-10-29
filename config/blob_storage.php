<?php

function blobStorageIsEnabled(): bool {
    return getenv('VERCEL') === '1' || getenv('VERCEL_ENV') !== false;
}

function isPublicBlobUrl(?string $value): bool {
    if (!$value || !filter_var($value, FILTER_VALIDATE_URL)) {
        return false;
    }

    $parts = parse_url($value);
    return ($parts['scheme'] ?? '') === 'https'
        && !isset($parts['user'])
        && !isset($parts['pass'])
        && !isset($parts['port'])
        && preg_match('/\A[a-z0-9-]+\.public\.blob\.vercel-storage\.com\z/i', $parts['host'] ?? '') === 1;
}

function storedImageExists(?string $value): bool {
    if (isPublicBlobUrl($value)) {
        return true;
    }

    if (!$value || str_contains($value, '..')
        || (!str_starts_with($value, 'uploads/') && !str_starts_with($value, 'assets/'))
    ) {
        return false;
    }

    return is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $value));
}

function storedImageSrc(string $value, string $localPrefix = '../'): string {
    if (isPublicBlobUrl($value)) {
        return $value;
    }

    return $localPrefix . ltrim($value, '/');
}

function callVercelBlob(array $payload): array {
    $token = getenv('BLOB_READ_WRITE_TOKEN');
    $deploymentHost = getenv('VERCEL_PROJECT_PRODUCTION_URL') ?: getenv('VERCEL_URL');
    if (!$token || !$deploymentHost) {
        throw new RuntimeException('Configure o Blob Store público no projeto Vercel e conecte-o ao ambiente.');
    }

    $deploymentHost = preg_replace('#\Ahttps?://#i', '', $deploymentHost);
    if (!is_string($deploymentHost) || preg_match('/\A[a-z0-9.-]+(?::\d+)?\z/i', $deploymentHost) !== 1) {
        throw new RuntimeException('A URL da implantação Vercel não é válida.');
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $response = file_get_contents('https://' . $deploymentHost . '/api/blob', false, $context);
    if ($response === false) {
        throw new RuntimeException('Não foi possível acessar a função de armazenamento Blob.');
    }

    $result = json_decode($response, true);
    $statusLine = $http_response_header[0] ?? '';
    if (!is_array($result) || preg_match('/\s2\d\d\s/', $statusLine) !== 1) {
        $errorMessage = $result['error'] ?? null;
        if (is_array($errorMessage)) {
            $errorMessage = $errorMessage['message'] ?? $errorMessage['error'] ?? null;
        }
        if (!is_string($errorMessage) || trim($errorMessage) === '') {
            $errorMessage = 'A Vercel Blob não concluiu a operação. ' . $statusLine;
        }
        throw new RuntimeException($errorMessage);
    }

    return $result;
}

function storeImage(string $binary, string $folder, string $filename, string $contentType): string {
    if (!in_array($folder, ['avatars', 'logos'], true)
        || preg_match('/\A[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|gif|webp)\z/i', $filename) !== 1
        || !in_array($contentType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
    ) {
        throw new InvalidArgumentException('O arquivo de imagem não é válido.');
    }

    if (blobStorageIsEnabled()) {
        $result = callVercelBlob([
            'action' => 'upload',
            'pathname' => $folder . '/' . $filename,
            'contentType' => $contentType,
            'data' => base64_encode($binary),
        ]);
        if (!is_string($result['url'] ?? null) || !isPublicBlobUrl($result['url'])) {
            throw new RuntimeException('A Vercel Blob retornou uma URL de imagem inválida.');
        }
        return $result['url'];
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível criar a pasta local para a imagem.');
    }

    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    if (file_put_contents($path, $binary) === false) {
        throw new RuntimeException('Não foi possível salvar a imagem localmente.');
    }

    return 'uploads/' . $folder . '/' . $filename;
}

function deleteStoredImage(?string $value): void {
    if (isPublicBlobUrl($value)) {
        callVercelBlob(['action' => 'delete', 'url' => $value]);
        return;
    }

    if ($value && str_starts_with($value, 'uploads/') && !str_contains($value, '..')) {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $value);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Não foi possível remover a imagem local antiga.');
        }
    }
}
