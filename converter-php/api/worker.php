<?php
declare(strict_types=1);

/**
 * FrankenPHP worker entry point.
 *
 * The Slim application is created once when the worker starts, then the
 * frankenphp_handle_request() loop handles every incoming request without
 * re-bootstrapping. In non-worker mode (php -S, php-fpm, etc.) we fall back
 * to the standard Slim $app->run() front-controller.
 */

require __DIR__ . '/vendor/autoload.php';

$app = \App\AppFactory::create();

if (function_exists('frankenphp_handle_request')) {
    $creator = new \Nyholm\Psr7Server\ServerRequestCreator(
        new \Slim\Psr7\Factory\ServerRequestFactory(),
        new \Slim\Psr7\Factory\UriFactory(),
        new \Slim\Psr7\Factory\UploadedFileFactory(),
        new \Slim\Psr7\Factory\StreamFactory()
    );

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

    while (frankenphp_handle_request($handler)) {
        gc_collect_cycles();
    }
} else {
    $app->run();
}
