<?php

// Vito installs a plugin into app/Vito/Plugins/<GitHub user>/<repository> and loads the class
// App\Vito\Plugins\<User>\<Repository>\Plugin from this file. The class lives in the plugin's own namespace and
// is aliased below to the name the install folder gives it, so forks and renamed repositories work unchanged.
// One Vito can hold two copies, such as a manual install plus the same plugin from GitHub. The first copy loaded
// declares the classes, every later copy only adds its alias, and boot() runs once: declaring a class twice is a
// fatal error that would take Vito down, and Vito's install check doesn't catch it (it compares other names).
namespace VitoDbManager;

use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterViews;
use Illuminate\Support\Facades\Route;
use VitoDbManager\Vito\InjectLauncher;
use VitoDbManager\Vito\ManagerController;

if (! class_exists(Plugin::class, false)) {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'VitoDbManager\\')) {
            $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen('VitoDbManager\\'))).'.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });

    class Plugin extends AbstractPlugin
    {
        protected string $name = 'Database Manager';

        protected string $description = 'Browse and edit table data, change structure, run SQL and watch running queries for MySQL,'
            .' MariaDB and PostgreSQL, right from the Databases page.';

        private static bool $booted = false;

        public function boot(): void
        {
            if (self::$booted) {
                return;
            }
            self::$booted = true;

            RegisterViews::make('vdbm')->path(__DIR__.'/resources/views')->register();

            Route::middleware(['web', 'auth', 'has-project'])->group(function (): void {
                Route::get('servers/{server}/database-manager/list', [ManagerController::class, 'databases'])->name('vdbm.databases');
                Route::get('servers/{server}/database-manager/{database?}', [ManagerController::class, 'page'])
                    ->whereNumber('database')
                    ->name('vdbm.page');
                Route::post('servers/{server}/database-manager/{database}/api', [ManagerController::class, 'api'])
                    ->whereNumber('database')
                    ->name('vdbm.api');
            });
            // the launcher is also loaded on the sign-in page (see InjectLauncher), so the plugin's static files are
            // served without a session; they are the same files as in the public repository
            Route::middleware(['web'])
                ->get('database-manager/assets/{stamp}/{path}', [ManagerController::class, 'asset'])
                ->where(['stamp' => '[a-f0-9]{10}', 'path' => '[a-z0-9/-]+\.(?:js|css)'])
                ->name('vdbm.asset');

            app('router')->pushMiddlewareToGroup('web', InjectLauncher::class);
        }
    }
}

$folder = array_slice(preg_split('#[\\\\/]#', __DIR__), -2);
$expected = 'App\\Vito\\Plugins\\'.implode('\\', $folder).'\\Plugin';
if (! class_exists($expected, false)) {
    class_alias(Plugin::class, $expected);
}
