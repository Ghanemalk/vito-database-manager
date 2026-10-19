<?php

// Vito installs a plugin into app/Vito/Plugins/<GitHub user>/<repository> and loads the class
// App\Vito\Plugins\<User>\<Repository>\Plugin from this file. The class lives in the plugin's own namespace and
// is aliased below to the name the install folder gives it, so forks and renamed repositories work unchanged.
namespace VitoDbManager;

use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterViews;
use Illuminate\Support\Facades\Route;
use VitoDbManager\Vito\InjectLauncher;
use VitoDbManager\Vito\ManagerController;

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

    protected string $description = 'Browse and edit table data, change structure, run SQL and watch running queries for MySQL, MariaDB and PostgreSQL, right from the Databases page.';

    public function boot(): void
    {
        RegisterViews::make('vdbm')->path(__DIR__.'/resources/views')->register();

        Route::middleware(['web', 'auth', 'has-project'])->group(function (): void {
            Route::get('servers/{server}/database-manager/list', [ManagerController::class, 'databases'])->name('vdbm.databases');
            Route::get('servers/{server}/database-manager/{database?}', [ManagerController::class, 'page'])->whereNumber('database')->name('vdbm.page');
            Route::post('servers/{server}/database-manager/{database}/api', [ManagerController::class, 'api'])->whereNumber('database')->name('vdbm.api');
            Route::get('database-manager/assets/{file}', [ManagerController::class, 'asset'])->where('file', '[a-z]+\.(?:js|css)')->name('vdbm.asset');
        });

        app('router')->pushMiddlewareToGroup('web', InjectLauncher::class);
    }
}

$folder = array_slice(preg_split('#[\\\\/]#', __DIR__), -2);
$expected = 'App\\Vito\\Plugins\\'.implode('\\', $folder).'\\Plugin';
if (! class_exists($expected, false)) {
    class_alias(Plugin::class, $expected);
}
