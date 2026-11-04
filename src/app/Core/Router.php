<?php
/**
 * Zabbix Weathermap - Simple Router
 */

declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];

    /** config and database of the request being dispatched, for the error pages */
    private array $context = [];
    
    public function get(string $path, mixed $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }
    
    public function post(string $path, mixed $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }
    
    public function put(string $path, mixed $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }
    
    public function delete(string $path, mixed $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }
    
    private function addRoute(string $method, string $path, mixed $handler): void
    {
        // Convert path parameters to regex
        $pattern = preg_replace('/\{([a-zA-Z]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';
        
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }
    
    public function dispatch(string $method, string $uri, array $context = []): void
    {
        $this->context = $context;

        // Remove trailing slash
        $uri = rtrim($uri, '/') ?: '/';
        
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            
            if (preg_match($route['pattern'], $uri, $matches)) {
                if ($method !== 'GET' && !Csrf::isValid(Csrf::fromRequest())) {
                    $this->csrfFailed();
                    return;
                }

                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                
                $this->executeHandler($route['handler'], $params, $context);
                return;
            }
        }
        
        // No route found
        $this->notFound();
    }
    
    private function executeHandler(mixed $handler, array $params, array $context): void
    {
        if (is_callable($handler)) {
            call_user_func($handler, $params, $context);
            return;
        }
        
        if (is_string($handler) && str_contains($handler, '@')) {
            [$controllerName, $method] = explode('@', $handler);
            
            $controllerClass = "App\\Controllers\\{$controllerName}";
            
            if (!class_exists($controllerClass)) {
                $this->serverError("Controller not found: {$controllerClass}");
                return;
            }
            
            $controller = new $controllerClass($context);
            
            if (!method_exists($controller, $method)) {
                $this->serverError("Method not found: {$controllerClass}::{$method}");
                return;
            }
            
            call_user_func([$controller, $method], $params);
            return;
        }
        
        $this->serverError("Invalid handler");
    }
    
    private function notFound(): void
    {
        ErrorPage::send(404, $this->auth());
    }

    private function csrfFailed(): void
    {
        ErrorPage::send(419, $this->auth());
    }

    private function serverError(string $message): void
    {
        ErrorPage::send(500, $this->auth(), null, getenv('APP_DEBUG') === 'true' ? $message : null);
    }

    /** The signed-in user, for the shell of an error page; null when the context has no database */
    private function auth(): ?Auth
    {
        $config = $this->context['config'] ?? null;
        $database = $this->context['database'] ?? null;
        if (!$config instanceof Config || !$database instanceof Database) {
            return null;
        }
        try {
            return new Auth($database, $config);
        } catch (\Throwable) {
            return null;
        }
    }
}
