<?php

/*
 * Runs the manager in a browser without Vito, against a database on this machine. Meant for working on the UI.
 *
 *   VDBM_FLAVOR=mysql VDBM_CLIENT="mysql -h 127.0.0.1 -P 3306 -u root" VDBM_DATABASE=scratch \
 *       php -S 127.0.0.1:8080 tests/dev/server.php
 *
 * then open http://127.0.0.1:8080.
 *
 * VDBM_FLAVOR is mysql, mariadb or postgresql. VDBM_CLIENT is the command-line client with its connection options,
 * for PostgreSQL e.g. "psql -h 127.0.0.1 -U postgres". It runs in `sh` (on Windows the one from Git for Windows).
 * Use a scratch database: the manager can change anything in it, and this server has no sign-in.
 */

use VitoDbManager\Engines\Engine;
use VitoDbManager\Support\ArrayStore;
use VitoDbManager\Support\DbmException;

$root = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'VitoDbManager\\')) {
        require $root.'/src/'.str_replace('\\', '/', substr($class, strlen('VitoDbManager\\'))).'.php';
    }
});
require __DIR__.'/FileTransport.php';

$flavor = getenv('VDBM_FLAVOR') ?: 'mysql';
$client = getenv('VDBM_CLIENT') ?: ($flavor === 'postgresql' ? 'psql' : $flavor);
$database = getenv('VDBM_DATABASE') ?: 'scratch';
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$engine = static fn (): Engine => Engine::make($flavor, new FileTransport, $database, new ArrayStore, $client);

// the manager's files, under a stamp like Vito serves them (see ManagerController::asset())
if (preg_match('#^/assets/[^/]+/([a-z0-9-]+(?:/[a-z0-9-]+)*\.(js|css))$#', $path, $match) === 1) {
    $file = $root.'/resources/assets/'.$match[1];
    if (! is_file($file)) {
        http_response_code(404);

        return true;
    }
    header('Content-Type: '.($match[2] === 'js' ? 'text/javascript' : 'text/css').'; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($file);

    return true;
}

if ($path === '/api' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $request = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $op = (string) ($request['op'] ?? '');
    $args = is_array($request['args'] ?? null) ? $request['args'] : [];
    header('Content-Type: application/json');
    try {
        if ($op === 'stash') {
            $data = stash($args);
        } else {
            if ($op === 'sql' && isset($args['stash'])) {
                $args['sql'] = unstash((string) $args['stash']);
                unset($args['stash']);
            }
            $data = $engine()->handle($op, $args);
        }
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (DbmException $e) {
        http_response_code(match ($e->kind) {
            'stale' => 409,
            'connect' => 502,
            'not_found' => 404,
            default => 422,
        });
        echo json_encode(['ok' => false, 'error' => $e->toArray()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        // as ManagerController::api() answers it
        http_response_code(502);
        $error = new DbmException('Vito could not reach the database server: '.$e->getMessage(), 'connect');
        echo json_encode(['ok' => false, 'error' => $error->toArray()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    return true;
}

if ($path === '/' || $path === '/index.html') {
    $stamp = (string) time();
    $boot = [
        'version' => 'dev',
        // ?embed=1 renders the page as Vito frames it on its Databases page
        'embed' => ($_GET['embed'] ?? '') === '1',
        'server' => ['id' => 1, 'name' => 'localhost'],
        'engine' => ['flavor' => $flavor, 'version' => ''],
        'database' => ['id' => 1, 'name' => $database],
        'databases' => [['id' => 1, 'name' => $database, 'ready' => true, 'url' => '/']],
        'api' => '/api',
        'back' => '/',
        'functions' => $engine()->functions(),
        'user' => ['name' => 'Developer'],
    ];
    header('Content-Type: text/html; charset=utf-8');
    renderView($root.'/resources/views/manager.blade.php', [
        'boot' => $boot,
        'appearance' => getenv('VDBM_APPEARANCE') ?: 'system',
        'title' => $database.' · localhost',
        'css' => "/assets/{$stamp}/manager/styles/main.css",
        'js' => "/assets/{$stamp}/manager/main.js",
    ]);

    return true;
}

http_response_code(404);
echo 'Not found.';

return true;

/**
 * Renders the plugin's own Blade view, so the page here is the page Vito shows. Only the few Blade features that
 * view uses are compiled: {{ }}, {!! !!}, @json() and @class().
 *
 * @param  array<string, mixed>  $vars
 */
function renderView(string $file, array $vars): void
{
    $php = (string) file_get_contents($file);
    $php = preg_replace('/\{\{\s*(.+?)\s*\}\}/', '<?= htmlspecialchars((string) ($1)) ?>', $php);
    $php = preg_replace('/\{!!\s*(.+?)\s*!!\}/', '<?= $1 ?>', $php);
    $php = preg_replace('/@json\((.+?)\)(?=;)/', '<?= json_encode($1) ?>', $php);
    $php = preg_replace('/@class\((\[.*?\])\)/', 'class="<?= cssClasses($1) ?>"', $php);

    $compiled = tempnam(sys_get_temp_dir(), 'vdbm-view');
    file_put_contents($compiled, $php);
    (static function (string $__file, array $__vars): void {
        extract($__vars);
        require $__file;
    })($compiled, $vars);
    unlink($compiled);
}

/** @param  array<int|string, mixed>  $classes */
function cssClasses(array $classes): string
{
    $names = [];
    foreach ($classes as $name => $condition) {
        if (is_int($name)) {
            $names[] = $condition;
        } elseif ($condition) {
            $names[] = $name;
        }
    }

    return implode(' ', $names);
}

function csrf_token(): string
{
    return 'dev';
}

function asset(string $path): string
{
    return '/'.ltrim($path, '/');
}

/**
 * Large SQL scripts arrive in parts first, as in ManagerController::stash().
 *
 * @param  array<string, mixed>  $args
 * @return array{size: int}
 */
function stash(array $args): array
{
    $file = stashFile((string) ($args['id'] ?? ''));
    if ((int) ($args['part'] ?? 0) === 0) {
        @unlink($file);
    }
    file_put_contents($file, (string) ($args['data'] ?? ''), FILE_APPEND);
    clearstatcache(true, $file);

    return ['size' => (int) filesize($file)];
}

function unstash(string $id): string
{
    $file = stashFile($id);
    $sql = is_file($file) ? (string) file_get_contents($file) : throw new DbmException('The uploaded script expired. Run it again.', 'input');
    unlink($file);

    return $sql;
}

function stashFile(string $id): string
{
    if (preg_match('/^[a-f0-9]{16,64}$/', $id) !== 1) {
        throw new DbmException('Invalid upload.', 'input');
    }

    return sys_get_temp_dir().'/vdbm-stash-'.$id.'.part';
}
