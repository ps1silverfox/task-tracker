<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the cross-app integration suite (INT-01..INT-03).
 *
 * The workspace is a poly-package layout — `lib/`, `apps/admin/`, and
 * `apps/public/` each ship their own `composer.json` + `vendor/`. Each app's
 * autoloader already pulls in `lib/`'s autoloader (via the `task-tracker/core`
 * path-repo snapshot), so requiring both apps' autoloaders here is enough to
 * resolve every `TaskTracker\*` namespace in this test suite.
 *
 * Composer's PSR-4 ClassLoader is idempotent: registering the same prefix
 * twice just appends a duplicate path, the lookup still succeeds. Loading
 * order is admin first, public second — neither has any class that shadows
 * the other (their namespaces are disjoint: Admin vs Public).
 */

$adminAutoload  = __DIR__ . '/../../apps/admin/vendor/autoload.php';
$publicAutoload = __DIR__ . '/../../apps/public/vendor/autoload.php';

foreach ([$adminAutoload, $publicAutoload] as $autoload) {
    if (!is_file($autoload)) {
        fwrite(
            STDERR,
            "integration bootstrap: missing autoload at {$autoload}\n"
            . "run `composer install` inside the corresponding app first.\n",
        );
        exit(1);
    }
    require_once $autoload;
}

// Root vendor/autoload.php registers the TaskTracker\Tests\Integration\* PSR-4
// prefix for helpers/fixtures dropped into tests/integration/. Optional — the
// suite works without it because PHPUnit directly require()s each *Test.php it
// discovers — but loading it here keeps cross-test helper resolution honest.
$rootAutoload = __DIR__ . '/../../vendor/autoload.php';
if (is_file($rootAutoload)) {
    require_once $rootAutoload;
}
