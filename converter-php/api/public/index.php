<?php
declare(strict_types=1);

/**
 * Application entry point — FrankenPHP worker mode and standard front-controller.
 *
 * Two execution paths share this single file:
 *
 * 1. FrankenPHP worker mode (production / Docker)
 *    The Slim application is bootstrapped once when the worker process starts.
 *    frankenphp_handle_request() then loops indefinitely, dispatching each
 *    incoming HTTP request to $handler without re-loading the PHP runtime.
 *    Errors inside the loop are caught and logged so one bad request cannot
 *    crash the worker; gc_collect_cycles() runs after every request to prevent
 *    memory accumulation across the long-lived process.
 *
 * 2. Standard PHP server (development / php -S)
 *    When FrankenPHP is not present, $app->run() behaves as a normal Slim
 *    front-controller: bootstrap → handle one request → exit.
 *
 * Entrypoint: api/public/index.php
 * Caddyfile worker directive: /app/api/public/index.php
 * Local dev:  php -S 0.0.0.0:8080 api/public/index.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = \App\AppFactory::create();

if (function_exists('frankenphp_handle_request')) {
    // Build the PSR-7 server-request creator once, reused on every iteration.
    $creator = new \Nyholm\Psr7Server\ServerRequestCreator(
        new \Slim\Psr7\Factory\ServerRequestFactory(),
        new \Slim\Psr7\Factory\UriFactory(),
        new \Slim\Psr7\Factory\UploadedFileFactory(),
        new \Slim\Psr7\Factory\StreamFactory()
    );

    // $handler is called by FrankenPHP for each HTTP request.
    $handler = static function () use ($app, $creator): void {
        try {
            $request  = $creator->fromGlobals();
            $response = $app->handle($request);
            (new \Slim\ResponseEmitter())->emit($response);
        } catch (\Throwable $e) {
            error_log('Unhandled error in worker: ' . $e->getMessage());
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo 'Internal Server Error';
        }
    };

    // Keep the worker alive; returns false only when FrankenPHP shuts down.
    while (frankenphp_handle_request($handler)) {
        gc_collect_cycles();
    }
} else {
    // php -S or php-fpm: handle a single request and exit.
    $app->run();
}
