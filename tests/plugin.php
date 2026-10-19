<?php

// Loading tests for Plugin.php: php tests/plugin.php
// Each case runs in its own PHP process against stubs of the Vito and Laravel classes Plugin.php touches, because
// a class declared twice is a fatal error that no try/catch can turn into a failed check.

require __DIR__.'/bootstrap.php';

$stubs = <<<'PHP'
<?php
namespace App\Plugins\Interfaces {
    interface PluginInterface { public function boot(): void; public function getName(): string; }
}
namespace App\Plugins {
    abstract class AbstractPlugin implements Interfaces\PluginInterface
    {
        protected string $name = '';
        protected string $description = '';
        public function boot(): void {}
        public function getName(): string { return $this->name; }
    }
    final class RegisterViews
    {
        public static array $paths = [];
        private string $id;
        public static function make(string $id): self { $v = new self(); $v->id = $id; return $v; }
        public function path(string $path): self { self::$paths[$this->id][] = $path; return $this; }
        public function register(): void {}
    }
}
namespace Illuminate\Support\Facades {
    final class Route
    {
        public static int $groups = 0;
        /** @var array<string, list<string>> uri => middleware */
        public static array $routes = [];
        private static array $current = [];
        private array $middleware = [];
        public static function middleware(array $m): self { $r = new self(); $r->middleware = $m; return $r; }
        public function group(\Closure $routes): void { self::$groups++; $outer = self::$current; self::$current = $this->middleware; $routes(); self::$current = $outer; }
        // Route::get() inside a group, or Route::middleware([...])->get() on its own
        public static function __callStatic(string $verb, array $a): object { return self::add($a[0], self::$current); }
        public function __call(string $verb, array $a): object { return self::add($a[0], $this->middleware); }
        private static function add(string $uri, array $middleware): object
        {
            self::$routes[$uri] = $middleware;
            return new class { public function __call($n, $a) { return $this; } };
        }
    }
}
namespace {
    final class RouterStub { public array $groups = []; public function pushMiddlewareToGroup($g, $m): void { $this->groups[$g][] = $m; } }
    function app(string $id): RouterStub { static $router; return $router ??= new RouterStub(); }
    // Composer's PSR-4 rule for App\ in Vito: App\X\Y maps to app/X/Y.php.
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'App\\Vito\\')) {
            $file = $GLOBALS['root'].'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}
PHP;

/**
 * Installs Plugin.php into each folder under a fresh app/Vito/Plugins, then runs $body in a new PHP process.
 *
 * @param  list<string>  $folders  e.g. ['Ghanemalk/VitoDatabaseManager']
 * @return array{code: int, out: string}
 */
$run = static function (array $folders, string $body) use ($stubs): array {
    $root = sys_get_temp_dir().'/vdbm-plugin-'.bin2hex(random_bytes(4));
    foreach ($folders as $folder) {
        mkdir("{$root}/app/Vito/Plugins/{$folder}", 0777, true);
        copy(dirname(__DIR__).'/Plugin.php', "{$root}/app/Vito/Plugins/{$folder}/Plugin.php");
    }
    $script = "{$root}/case.php";
    file_put_contents($script, $stubs."\nnamespace { \$GLOBALS['root'] = ".var_export($root, true).";\n{$body}\n}\n");
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1', $lines, $code);

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($root);

    return ['code' => $code, 'out' => implode("\n", $lines)];
};

$report = <<<'PHP'
echo json_encode([
    'groups' => Illuminate\Support\Facades\Route::$groups,
    'routes' => Illuminate\Support\Facades\Route::$routes,
    'views' => App\Plugins\RegisterViews::$paths,
    'middleware' => app('router')->groups,
]);
PHP;

// the folder Vito installs into from GitHub
$r = $run(['Ghanemalk/VitoDatabaseManager'], <<<PHP
\$p = new App\\Vito\\Plugins\\Ghanemalk\\VitoDatabaseManager\\Plugin();
\$p->boot();
echo \$p->getName(), "\\n";
{$report}
PHP);
T::eq('installs and boots from the GitHub folder', 0, $r['code']);
T::ok('has its name', str_starts_with($r['out'], "Database Manager\n"), $r['out']);
$state = json_decode((string) strstr($r['out'], '{'), true);
T::eq('registers its routes once', 1, $state['groups'] ?? null);
T::eq('adds the launcher middleware once', 1, count($state['middleware']['web'] ?? []));
$routes = $state['routes'] ?? [];
T::eq('serves its static files without a session (the launcher loads on the sign-in page)', ['web'], $routes['database-manager/assets/{file}'] ?? null);
foreach (['servers/{server}/database-manager/list', 'servers/{server}/database-manager/{database?}', 'servers/{server}/database-manager/{database}/api'] as $uri) {
    T::ok("{$uri} needs a session", in_array('auth', $routes[$uri] ?? [], true), json_encode($routes[$uri] ?? null));
}

// a fork or a manual copy under another folder name
$r = $run(['Someone/MyDbManager'], <<<'PHP'
(new App\Vito\Plugins\Someone\MyDbManager\Plugin())->boot();
echo 'ok';
PHP);
T::eq('loads from any install folder', ['code' => 0, 'out' => 'ok'], $r);

// two copies in one Vito: a manual install plus the same plugin installed from GitHub
$r = $run(['Manual/VitoDatabaseManager', 'Ghanemalk/VitoDatabaseManager'], <<<PHP
foreach (['Manual', 'Ghanemalk'] as \$user) {
    \$class = "App\\\\Vito\\\\Plugins\\\\{\$user}\\\\VitoDatabaseManager\\\\Plugin";
    \$p = new \$class();
    if (! \$p instanceof App\\Plugins\\Interfaces\\PluginInterface) {
        echo "not a plugin: {\$class}\\n";
    }
    \$p->boot();
}
{$report}
PHP);
T::eq('a second copy does not crash Vito', 0, $r['code']);
T::ok('a second copy loads', ! str_contains($r['out'], 'Fatal') && ! str_contains($r['out'], 'not a plugin'), $r['out']);
$state = json_decode((string) strstr($r['out'], '{'), true);
T::eq('two copies register the routes once', 1, $state['groups'] ?? null);
T::eq('two copies add the launcher middleware once', 1, count($state['middleware']['web'] ?? []));

exit(T::done('plugin'));
