<?php

namespace VitoDbManager\Vito;

use App\Http\Controllers\Controller;
use App\Models\Database;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use VitoDbManager\Engines\Engine;
use VitoDbManager\Support\DbmException;
use VitoDbManager\Transport\SshTransport;

/**
 * The manager page and its JSON API. Everything needs the same permission as Vito's server console
 * (update on the server and on the database): the queries run as the database superuser.
 */
final class ManagerController extends Controller
{
    public const VERSION = '1.0.1';

    private const OPS = ['overview', 'structure', 'rows', 'row', 'insert', 'update', 'delete', 'insert_many', 'sql', 'monitor', 'kill', 'export', 'ddl', 'stash'];

    /** Operations written to the audit log. */
    private const LOGGED = ['insert', 'update', 'delete', 'insert_many', 'sql', 'kill', 'export', 'ddl'];

    private const ASSETS = ['manager.js' => 'text/javascript', 'manager.css' => 'text/css', 'launcher.js' => 'text/javascript'];

    /** Scripts larger than PHP's post_max_size arrive in parts (see stash()). */
    private const STASH_LIMIT = 50 * 1024 * 1024;

    /**
     * The manager opens inside Vito's own layout: the launcher frames this page (?embed=1) on the server's Databases
     * page, so Vito's navigation stays around it. Opened directly, it sends the browser there. ?standalone=1 is the
     * fallback for a Vito whose page the launcher cannot find its way around.
     */
    public function page(Request $request, Server $server, ?Database $database = null): Response|RedirectResponse
    {
        $this->authorize('update', $server);
        $service = $server->database();
        abort_if($service === null, 404);
        $mode = $request->boolean('embed') ? 'embed' : ($request->boolean('standalone') ? 'standalone' : null);
        $databases = $server->databases()->orderBy('name')->get(['id', 'name', 'status']);
        if ($database === null) {
            $first = $databases->first();
            if ($first !== null) {
                return redirect($mode === null ? self::hostUrl($server, $first->id) : self::pageUrl($server, $first->id, $mode));
            }
        } else {
            $this->scoped($server, $database);
            $this->authorize('update', $database);
        }
        if ($mode === null) {
            return redirect(self::hostUrl($server, $database?->id));
        }

        $flavor = (string) $service->name;
        $boot = [
            'version' => self::VERSION,
            'embed' => $mode === 'embed',
            'server' => ['id' => $server->id, 'name' => $server->name],
            'engine' => ['flavor' => $flavor, 'version' => (string) $service->version],
            'database' => $database ? ['id' => $database->id, 'name' => $database->name] : null,
            'databases' => $databases->map(fn (Database $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'ready' => $d->status->value === 'ready',
                'url' => self::pageUrl($server, $d->id, $mode),
            ])->values()->all(),
            'api' => $database ? self::pageUrl($server, $database->id).'/api' : null,
            'back' => self::hostUrl($server),
            'functions' => $database ? $this->engine($server, $database)->functions() : [],
            'user' => ['name' => (string) $request->user()?->name],
        ];

        return response()
            ->view('vdbm::manager', [
                'boot' => $boot,
                'title' => ($database ? $database->name.' · ' : '').$server->name,
                'css' => $this->assetUrl('manager.css'),
                'js' => $this->assetUrl('manager.js'),
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'same-origin')
            // only Vito's own pages may frame the manager
            ->header('Content-Security-Policy', "frame-ancestors 'self'");
    }

    public function api(Request $request, Server $server, Database $database): JsonResponse
    {
        $this->scoped($server, $database);
        $this->authorize('update', $server);
        $this->authorize('update', $database);

        $op = (string) $request->input('op', '');
        $args = $request->input('args', []);
        $args = is_array($args) ? $args : [];
        if (! in_array($op, self::OPS, true)) {
            return $this->failure(new DbmException('Unknown operation.', 'input'));
        }

        // the engine waits up to a minute for the server and may hold a large result
        @set_time_limit(150);
        if ($this->bytes((string) ini_get('memory_limit')) < 768 * 1024 * 1024) {
            @ini_set('memory_limit', '768M');
        }

        try {
            if ($op === 'stash') {
                return $this->success($this->stash($request, $args));
            }
            if ($op === 'sql' && isset($args['stash'])) {
                $args['sql'] = $this->unstash($request, (string) $args['stash']);
                unset($args['stash']);
            }
            $data = $this->engine($server, $database)->handle($op, $args);
        } catch (DbmException $e) {
            $this->audit($request, $server, $database, $op, $args, 'failed: '.$e->getMessage());

            return $this->failure($e);
        } catch (Throwable $e) {
            report($e);
            $this->audit($request, $server, $database, $op, $args, 'failed: '.$e->getMessage());

            return $this->failure(new DbmException('Vito could not reach the database server: '.$e->getMessage(), 'connect'), 502);
        }

        $this->audit($request, $server, $database, $op, $args, 'ok');

        return $this->success($data);
    }

    /**
     * The databases the launcher on Vito's Databases page links to; 403 when the user may not open the manager.
     */
    public function databases(Server $server): JsonResponse
    {
        if (Gate::denies('update', $server) || $server->database() === null) {
            return response()->json(['ok' => false], 403);
        }

        return response()->json([
            'ok' => true,
            'frame' => self::pageUrl($server),
            'databases' => $server->databases()->orderBy('name')->get(['id', 'name'])->map(fn (Database $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'url' => self::hostUrl($server, $d->id),
            ])->values()->all(),
        ]);
    }

    public function asset(string $file): BinaryFileResponse
    {
        abort_unless(isset(self::ASSETS[$file]), 404);

        return response()->file(self::assetPath($file), [
            'Content-Type' => self::ASSETS[$file].'; charset=utf-8',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Plugin routes are added after Vito loads its cached routes, so their names never reach the URL generator:
     * every plugin URL is built from its path.
     */
    public static function pageUrl(Server $server, ?int $database = null, ?string $mode = null): string
    {
        return url('servers/'.$server->id.'/database-manager'.($database !== null ? '/'.$database : ''))
            .($mode !== null ? '?'.$mode.'=1' : '');
    }

    /**
     * Where the manager is shown: the server's Databases page in Vito, with the database to open. Without one the
     * plain Databases page.
     */
    public static function hostUrl(Server $server, ?int $database = null): string
    {
        $page = url('servers/'.$server->id.'/database');
        try {
            $page = route('databases', ['server' => $server->id]);
        } catch (Throwable) {
            // a Vito that renamed the route: the path above
        }

        return $page.($database !== null ? '?manager='.$database : '');
    }

    public static function assetUrl(string $file): string
    {
        $stamp = substr(md5(self::VERSION.'|'.@filemtime(self::assetPath($file))), 0, 10);

        return url('database-manager/assets/'.$file).'?v='.$stamp;
    }

    private static function assetPath(string $file): string
    {
        return dirname(__DIR__, 2).'/resources/assets/'.$file;
    }

    private function engine(Server $server, Database $database): Engine
    {
        $transport = new SshTransport(static function () use ($server): SSH2 {
            $host = str_contains((string) $server->ip, ':') ? '['.$server->ip.']' : (string) $server->ip;
            $ssh = new SSH2($host, (int) $server->port, 15);
            $key = PublicKeyLoader::loadPrivateKey((string) file_get_contents($server->sshKey()['private_key_path']));
            if (! $ssh->login($server->getSshUser(), $key)) {
                throw new DbmException('Vito could not log in to the server over SSH.', 'connect');
            }

            return $ssh;
        });

        return Engine::make(
            (string) $server->database()?->name,
            $transport,
            $database->name,
            new CacheStore(cache()->store(), 'vdbm:'.$server->id.':'.$database->id),
        );
    }

    private function scoped(Server $server, Database $database): void
    {
        abort_unless($database->server_id === $server->id, 404);
    }

    /**
     * Receives one part of a script too large for a single request; op "sql" with args.stash runs it.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, int>
     */
    private function stash(Request $request, array $args): array
    {
        $path = $this->stashPath($request, (string) ($args['id'] ?? ''));
        foreach (glob(dirname($path).'/*.part') ?: [] as $old) {
            if (@filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }
        $data = (string) ($args['data'] ?? '');
        if ((int) ($args['part'] ?? 0) === 0) {
            @unlink($path);
        }
        clearstatcache(true, $path);
        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size + strlen($data) > self::STASH_LIMIT) {
            @unlink($path);
            throw new DbmException('The script is larger than 50 MB. Restore large dumps from Backups instead.', 'input');
        }
        if (@file_put_contents($path, $data, FILE_APPEND | LOCK_EX) === false) {
            throw new DbmException('The script could not be stored on the Vito server.', 'error');
        }

        return ['size' => $size + strlen($data)];
    }

    private function unstash(Request $request, string $id): string
    {
        $path = $this->stashPath($request, $id);
        $sql = is_file($path) ? (string) file_get_contents($path) : null;
        @unlink($path);

        return $sql ?? throw new DbmException('The uploaded script expired. Run it again.', 'input');
    }

    private function stashPath(Request $request, string $id): string
    {
        if (preg_match('/^[a-f0-9]{16,64}$/', $id) !== 1) {
            throw new DbmException('Invalid upload.', 'input');
        }
        $dir = storage_path('app/database-manager');
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir.'/'.(int) $request->user()?->id.'-'.$id.'.part';
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function audit(Request $request, Server $server, Database $database, string $op, array $args, string $outcome): void
    {
        if (! in_array($op, self::LOGGED, true) || ($op === 'ddl' && ! empty($args['dry']))) {
            return;
        }
        $table = $args['table'] ?? null;
        $detail = match ($op) {
            'sql' => (! empty($args['readonly']) ? '[read-only] ' : '').mb_substr((string) ($args['sql'] ?? ''), 0, 4000),
            'ddl' => json_encode(array_diff_key($args, ['dry' => 1]), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'delete' => 'table='.json_encode($table, JSON_UNESCAPED_UNICODE).' keys='.count((array) ($args['keys'] ?? [])),
            'insert_many' => 'table='.json_encode($table, JSON_UNESCAPED_UNICODE).' rows='.count((array) ($args['rows'] ?? [])).' mode='.($args['mode'] ?? 'insert'),
            'kill' => 'id='.json_encode($args['id'] ?? null).(empty($args['connection']) ? ' query' : ' connection'),
            'export' => 'table='.json_encode($table, JSON_UNESCAPED_UNICODE).' format='.($args['format'] ?? 'csv'),
            default => 'table='.json_encode($table, JSON_UNESCAPED_UNICODE),
        };
        $user = $request->user();
        $line = sprintf(
            "[%s] user=%s <%s> ip=%s server=%d db=%s op=%s %s :: %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            (string) $user?->id,
            (string) $user?->email,
            (string) $request->ip(),
            $server->id,
            $database->name,
            $op,
            str_replace(["\r", "\n"], ' ', $outcome),
            str_replace(["\r\n", "\n", "\r"], ' ⏎ ', (string) $detail),
        );
        @file_put_contents(storage_path('logs/database-manager.log'), $line, FILE_APPEND | LOCK_EX);
    }

    private function success(mixed $data): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $data], 200, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function failure(DbmException $e, ?int $status = null): JsonResponse
    {
        $status ??= match ($e->kind) {
            'stale' => 409,
            'connect' => 502,
            'not_found' => 404,
            default => 422,
        };

        return response()->json(['ok' => false, 'error' => $e->toArray()], $status, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
