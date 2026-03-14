<?php

namespace App\Core;

/**
 * Simple REST Router
 * 
 * Supports dynamic route parameters like {id} and API versioning.
 */
class Router
{
    private array $routes = [];
    private array $middlewares = [];

    /**
     * Register a GET route
     */
    public function get(string $path, callable $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route
     */
    public function post(string $path, callable $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a PUT route
     */
    public function put(string $path, callable $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a DELETE route
     */
    public function delete(string $path, callable $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Add a route
     */
    private function addRoute(string $method, string $path, callable $handler): self
    {
        $this->routes[] = [
            'method'  => $method,
            'path'    => $path,
            'handler' => $handler,
            'pattern' => $this->buildPattern($path),
        ];
        return $this;
    }

    /**
     * Add a global middleware
     */
    public function addMiddleware(callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Build regex pattern from route path
     * Converts {param} to named capture groups
     */
    private function buildPattern(string $path): string
    {
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    /**
     * Resolve and dispatch the current request
     */
    public function dispatch(Request $request): void
    {
        $method = $request->getMethod();
        $path   = rtrim($request->getPath(), '/') ?: '/';

        // Handle OPTIONS preflight
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Run global middlewares
        foreach ($this->middlewares as $middleware) {
            $result = call_user_func($middleware, $request);
            if ($result === false) {
                return; // Middleware halted the request
            }
        }

        // Find matching route
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $path, $matches)) {
                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $request->setParams($params);

                // Call the handler
                call_user_func($route['handler'], $request);
                return;
            }
        }

        // No route matched
        Response::notFound('Endpoint not found: ' . $method . ' ' . $path);
    }

    /**
     * Load route definitions from a file
     * The file should return a closure that accepts a Router instance.
     */
    public function loadRoutes(string $filePath): self
    {
        if (file_exists($filePath)) {
            $routeLoader = require $filePath;
            if (is_callable($routeLoader)) {
                $routeLoader($this);
            }
        }
        return $this;
    }
}
