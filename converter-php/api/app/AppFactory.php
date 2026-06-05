<?php
declare(strict_types=1);

namespace App;

use App\Controller\ConvertController;
use App\Controller\HealthController;
use App\Controller\HomeController;
use App\Controller\NotFoundController;
use App\Middleware\UploadSizeMiddleware;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\App;

/**
 * Wires up the Slim application: middleware, routes, controllers.
 *
 * Called once at startup — both in FrankenPHP worker mode and in the
 * standard php -S / front-controller mode.
 */
class AppFactory
{
    public static function create(): App
    {
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();

        $maxUploadBytes = (int)(getenv('OFFICE_MAX_UPLOAD_SIZE') ?: (100 << 20));

        $app->add(new UploadSizeMiddleware($maxUploadBytes));

        // Health
        $app->map(['GET', 'HEAD'], '/healthz', new HealthController());

        // Web UI
        $app->get('/', new HomeController());

        // Conversion API
        $convert = new ConvertController();
        $app->post('/api/v1/convert',              [$convert, 'autoConvert']);
        $app->post('/api/v1/convert/xlsb-to-xlsx', [$convert, 'xlsbToXlsx']);
        $app->post('/api/v1/convert/xlsx-to-ods',  [$convert, 'xlsxToOds']);
        $app->post('/api/v1/convert/ods-to-xlsx',  [$convert, 'odsToXlsx']);

        // 404 catch-all
        $app->map(
            ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
            '/{routes:.+}',
            new NotFoundController()
        );

        return $app;
    }
}
