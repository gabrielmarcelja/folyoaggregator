<?php
namespace FolyoAggregator\API;

use Exception;

/**
 * Simple router for API endpoints
 */
class Router {
    private array $routes = [];
    private string $basePath = '/api/v1';

    /**
     * Register a GET route
     *
     * @param string $path
     * @param callable $handler
     */
    public function get(string $path, callable $handler): void {
        $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route
     *
     * @param string $path
     * @param callable $handler
     */
    public function post(string $path, callable $handler): void {
        $this->addRoute('POST', $path, $handler);
    }

    /**
     * Add route to routing table
     *
     * @param string $method
     * @param string $path
     * @param callable $handler
     */
    private function addRoute(string $method, string $path, callable $handler): void {
        $pattern = $this->basePath . $path;
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_-]+)', $pattern);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler
        ];
    }

    /**
     * Handle the current request
     */
    public function handle(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Enable CORS
        $this->handleCors();

        // Handle OPTIONS requests for CORS preflight
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        try {
            foreach ($this->routes as $route) {
                if ($route['method'] === $method && preg_match($route['pattern'], $path, $matches)) {
                    // Extract route parameters
                    $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                    // Get request body for POST requests
                    $body = null;
                    if ($method === 'POST') {
                        $input = file_get_contents('php://input');
                        $body = json_decode($input, true);
                    }

                    // Call the handler
                    $response = call_user_func($route['handler'], $params, $body);

                    // Send JSON response
                    header('Content-Type: application/json');
                    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    return;
                }
            }

            // No route found
            $this->sendError('Endpoint not found', 404);

        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 500);
        }
    }

    /**
     * Handle CORS headers
     */
    private function handleCors(): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        $allowedOrigins = env('API_CORS_ORIGINS', '*');

        if ($allowedOrigins === '*' || strpos($allowedOrigins, $origin) !== false) {
            header("Access-Control-Allow-Origin: $origin");
        } else {
            header("Access-Control-Allow-Origin: $allowedOrigins");
        }

        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
        header("Access-Control-Max-Age: 3600");
    }

    /**
     * Send error response
     *
     * @param string $message
     * @param int $code
     */
    private function sendError(string $message, int $code = 400): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $code
            ],
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
        exit;
    }
}