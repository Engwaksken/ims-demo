<?php
declare(strict_types=1);
namespace App\Core;

use Throwable;

final class Application
{
    public Router $router;
    private ?Database $db = null;

    public function __construct(public readonly string $basePath)
    {
        date_default_timezone_set((string) env('APP_TIMEZONE', 'Africa/Kampala'));
        $this->startSession();
        $this->router = new Router($this);
        set_exception_handler([$this, 'handleException']);
    }

    public function db(): Database { return $this->db ??= new Database(); }

    private function startSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) return;
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) env('SESSION_LIFETIME', 14400));
        session_name((string) env('SESSION_NAME', 'IMSSESSID'));
        session_set_cookie_params([
            'lifetime'=>(int) env('SESSION_LIFETIME', 14400), 'path'=>'/',
            'secure'=>(bool) env('SESSION_SECURE_COOKIE', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly'=>true, 'samesite'=>'Lax'
        ]);
        session_start();
    }

    public function run(): void
    {
        $this->router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    }

    public function handleException(Throwable $e): void
    {
        error_log($e->__toString());
        if (headers_sent()) return;
        http_response_code(500);
        $message = (bool) env('APP_DEBUG', false) ? $e->getMessage() : 'An unexpected system error occurred.';
        View::render('errors/500', ['message'=>$message]);
    }
}
