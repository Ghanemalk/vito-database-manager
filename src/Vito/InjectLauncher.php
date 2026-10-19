<?php

namespace VitoDbManager\Vito;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Adds the launcher script to Vito's full page loads, so the Databases page (a React page we can't edit)
 * gets an "Open" button per database. Inertia's in-app navigations are JSON and keep the loaded script.
 */
final class InjectLauncher
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || $request->headers->has('X-Inertia') || $request->user() === null
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
            $tag = '<script src="'.e(ManagerController::assetUrl('launcher.js')).'" data-vdbm-launcher data-base="'.e(url('/')).'" defer></script>';
            $response->setContent(substr_replace($html, $tag, $at, 0));
            $response->headers->remove('Content-Length');
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }
}
