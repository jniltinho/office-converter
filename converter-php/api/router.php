<?php
declare(strict_types=1);

// Compatibility front-controller (inside api/).
// Delegates to worker.php (same dir) which handles both FrankenPHP worker mode
// and regular php -S / php router.php mode using the Slim app.
require __DIR__ . '/worker.php';
