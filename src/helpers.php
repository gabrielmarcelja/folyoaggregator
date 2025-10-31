<?php
/**
 * Helper functions for FolyoAggregator
 */

if (!function_exists('env')) {
    /**
     * Get environment variable with optional default
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env($key, $default = null) {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        // Convert string values to appropriate types
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
            case 'empty':
            case '(empty)':
                return '';
        }

        // Check if numeric
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }

        return $value;
    }
}

if (!function_exists('config')) {
    /**
     * Get configuration value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function config($key, $default = null) {
        static $config = null;

        if ($config === null) {
            $config = [
                'app' => [
                    'env' => env('APP_ENV', 'production'),
                    'debug' => env('APP_DEBUG', false),
                    'url' => env('APP_URL', 'http://localhost'),
                    'timezone' => env('APP_TIMEZONE', 'UTC'),
                ],
                'database' => [
                    'host' => env('DB_HOST', 'localhost'),
                    'port' => env('DB_PORT', 3306),
                    'name' => env('DB_NAME', 'folyoaggregator'),
                    'user' => env('DB_USER', 'root'),
                    'pass' => env('DB_PASS', ''),
                    'charset' => env('DB_CHARSET', 'utf8mb4'),
                ],
                'redis' => [
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', 6379),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', 0),
                ],
                'cache' => [
                    'ttl_prices' => env('CACHE_TTL_PRICES', 30),
                    'ttl_assets' => env('CACHE_TTL_ASSETS', 300),
                    'ttl_historical' => env('CACHE_TTL_HISTORICAL', 3600),
                ],
                'api' => [
                    'version' => env('API_VERSION', 'v1'),
                    'rate_limit_enabled' => env('API_RATE_LIMIT_ENABLED', true),
                    'cors_enabled' => env('API_CORS_ENABLED', true),
                    'cors_origins' => env('API_CORS_ORIGINS', '*'),
                ],
            ];
        }

        $keys = explode('.', $key);
        $value = $config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }
}

if (!function_exists('app_path')) {
    /**
     * Get application root path
     *
     * @param string $path
     * @return string
     */
    function app_path($path = '') {
        $root = dirname(__DIR__);
        return $path ? $root . '/' . ltrim($path, '/') : $root;
    }
}

if (!function_exists('json_response')) {
    /**
     * Create JSON response
     *
     * @param mixed $data
     * @param int $status
     * @param array $headers
     * @return void
     */
    function json_response($data, $status = 200, $headers = []) {
        http_response_code($status);
        header('Content-Type: application/json');

        foreach ($headers as $key => $value) {
            header("$key: $value");
        }

        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('api_success')) {
    /**
     * Return successful API response
     *
     * @param mixed $data
     * @param string $message
     * @return void
     */
    function api_success($data = null, $message = 'Success') {
        json_response([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c'),
        ]);
    }
}

if (!function_exists('api_error')) {
    /**
     * Return error API response
     *
     * @param string $message
     * @param int $code
     * @param mixed $details
     * @return void
     */
    function api_error($message, $code = 400, $details = null) {
        json_response([
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $code,
                'details' => $details,
            ],
            'timestamp' => date('c'),
        ], $code);
    }
}

if (!function_exists('dd')) {
    /**
     * Dump and die for debugging
     *
     * @param mixed ...$vars
     * @return void
     */
    function dd(...$vars) {
        header('Content-Type: text/plain');
        foreach ($vars as $var) {
            var_dump($var);
        }
        exit;
    }
}