<?php
declare(strict_types=1);

/**
 * worker.php (now inside api/)
 *
 * FrankenPHP worker entry point using Slim Framework.
 *
 * The Slim App (with all routes and conversion logic) is bootstrapped once
 * when the worker starts. Then we enter the request loop using
 * frankenphp_handle_request().
 *
 * For php -S / non-worker mode we fall back to $app->run().
 */

require __DIR__ . '/vendor/autoload.php';

$createApp = require __DIR__ . '/src/create_app.php';
$app = $createApp();

// Also make the app available for any direct include if needed.
$GLOBALS['slimApp'] = $app;

if (function_exists('frankenphp_handle_request')) {
    // Worker mode: preload done, now loop.
    // Use nyholm/psr7-server Creator + factories provided by slim/psr7 (already in composer).
    $creator = new \Nyholm\Psr7Server\ServerRequestCreator(
        new \Slim\Psr7\Factory\ServerRequestFactory(),
        new \Slim\Psr7\Factory\UriFactory(),
        new \Slim\Psr7\Factory\UploadedFileFactory(),
        new \Slim\Psr7\Factory\StreamFactory()
    );

    $handler = static function () use ($app, $creator): void {
        try {
            $request = $creator->fromGlobals();
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
    // php -S or normal front controller mode
    $app->run();
}
