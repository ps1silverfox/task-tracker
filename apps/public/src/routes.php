<?php

declare(strict_types=1);

use Slim\App;

return static function (App $app): void {
    // Routes are registered by later phases (PUB-02..PUB-08).
    // INVARIANT: only GET routes may ever be registered on this app.
    // Any POST/PUT/PATCH/DELETE route is a violation of bind-level
    // separation; the 405 invariant test (PUB-09) will fail the build.
    unset($app);
};
