<?php

namespace VitoDbManager\Vito;

use App\Models\Server;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Adds the launcher script to every full page load of Vito, so the Databases page (a React page we can't edit) gets
 * an "Open" button per database. Inertia's in-app navigations are JSON and keep the loaded script, which is why it
 * goes on the sign-in page too: signing in is such a navigation, and the script must already be there after it.
 * On the Databases page itself the tag also carries the database list, so the buttons come with the first render.
 */
final class InjectLauncher
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || $request->headers->has('X-Inertia')
            || ! $response instanceof Response || $response instanceof StreamedResponse || $response instanceof BinaryFileResponse
            || ! str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }
        $html = $response->getContent();
        if (! is_string($html) || str_contains($html, 'data-vdbm-launcher') || ($at = stripos($html, '</head>')) === false) {
            return $response;
        }

        // the button is a convenience: a fault here must never cost Vito its own page
        try {
            $list = null;
            $server = $request->route('server');
            if ($request->user() !== null && $server instanceof Server && $request->is('servers/*/database')) {
                $list = ManagerController::launcherList($server);
            }
            $tag = '<script src="'.e(ManagerController::assetUrl('launcher.js')).'" data-vdbm-launcher data-base="'.e(url('/')).'"'
                .($list !== null ? ' data-list="'.e((string) json_encode($list, JSON_UNESCAPED_SLASHES)).'"' : '')
                .' defer></script>';
            $response->setContent(substr_replace($html, $tag, $at, 0));
            $response->headers->remove('Content-Length');
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }
}
