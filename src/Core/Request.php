<?php

namespace App\Core;

/**
 * Request Wrapper
 * 
 * Encapsulates the HTTP request data for clean access.
 */
class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private array $query;
    private array $headers;
    private ?array $body;
    private array $params;

    public function __construct()
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri     = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path    = parse_url($this->uri, PHP_URL_PATH) ?? '/';
        $this->query   = $_GET;
        $this->headers = $this->parseHeaders();
        $this->body    = $this->parseBody();
        $this->params  = [];
    }

    /**
     * Parse request headers
     */
    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$header] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        return $headers;
    }

    /**
     * Parse request body (JSON)
     */
    private function parseBody(): ?array
    {
        $contentType = $this->getHeader('content-type') ?? '';

        if (strpos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }

        if (!empty($_POST)) {
            return $_POST;
        }

        return null;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getQuery(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    public function getHeader(string $name): ?string
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function getParam(string $key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Get the client IP address
     */
    public function getClientIp(): string
    {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                return trim($ips[0]);
            }
        }
        return '127.0.0.1';
    }

    /**
     * Get API key from header or query
     */
    public function getApiKey(): ?string
    {
        return $this->getHeader('x-api-key') ?? $this->getQuery('api_key');
    }

    /**
     * Check if request expects JSON
     */
    public function wantsJson(): bool
    {
        $accept = $this->getHeader('accept') ?? '';
        return strpos($accept, 'application/json') !== false || strpos($accept, '*/*') !== false;
    }
}
