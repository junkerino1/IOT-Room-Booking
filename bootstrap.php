<?php

date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($conn)) {
    require_once __DIR__ . '/db.php';
}

if (!function_exists('app_base_path')) {
    function app_base_path(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }

        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
        $dir = rtrim(dirname($scriptName), '/');

        if ($dir === '/' || $dir === '.' || $dir === '\\') {
            $dir = '';
        }

        $base = $dir;
        return $base;
    }

    function app_url(string $path = ''): string
    {
        $base = app_base_path();
        $cleanPath = ltrim($path, '/');

        if ($cleanPath === '') {
            return $base !== '' ? $base . '/' : '/';
        }

        return ($base !== '' ? $base : '') . '/' . $cleanPath;
    }

    function app_route_path(): string
    {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $requestPath = str_replace('\\', '/', $requestPath);
        $base = app_base_path();

        if ($base !== '' && strpos($requestPath, $base) === 0) {
            $requestPath = substr($requestPath, strlen($base));
        }

        return trim($requestPath, '/');
    }

    function app_password_matches(string $plainPassword, string $storedPassword): bool
    {
        $algoInfo = password_get_info($storedPassword);
        if (!empty($algoInfo['algo'])) {
            return password_verify($plainPassword, $storedPassword);
        }

        return hash_equals((string)$storedPassword, $plainPassword);
    }

    function app_password_hash(string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_DEFAULT);
    }
}
