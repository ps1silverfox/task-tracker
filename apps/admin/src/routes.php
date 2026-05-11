<?php

declare(strict_types=1);

use Slim\App;

return static function (App $app): void {
    // Routes are registered by later phases (ADMIN-02..ADMIN-08).
    // Keeping this loader as a closure that mutates $app preserves
    // a single registration entrypoint for both public/index.php and tests.
    unset($app);
};
