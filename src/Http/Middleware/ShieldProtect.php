<?php

namespace XaniaShield\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use XaniaShield\Laravel\ShieldClient;

/**
 * Route middleware that analyses the incoming submission and blocks it before
 * it reaches the controller.
 *
 * Usage on a route:
 *   Route::post('/contact', ...)->middleware('shield');
 *   Route::post('/contact', ...)->middleware('shield:contact');   // form id
 *
 * It maps common field names automatically (email, message/content, name,
 * subject) from the request. For anything non-standard, skip the middleware
 * and call Shield::checkRequest() in your controller with an explicit mapping.
 *
 * On a block verdict it aborts with HTTP 422 by default. Catch it, or publish
 * a custom response, as you prefer.
 */
class ShieldProtect
{
    public function __construct(private ShieldClient $shield) {}

    public function handle(Request $request, Closure $next, ?string $formId = null): Response
    {
        // Only analyse state-changing requests; never GET/HEAD.
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return $next($request);
        }

        if (! $this->shield->configured()) {
            return $next($request); // Not set up — don't get in the way.
        }

        $verdict = $this->shield->checkRequest($request, array_filter([
            'email'           => $request->input('email'),
            'content'         => $request->input('message') ?? $request->input('content'),
            'name'            => $request->input('name'),
            'subject'         => $request->input('subject'),
            'form_identifier' => $formId ?? $request->path(),
        ], fn ($v) => $v !== null));

        if ($verdict->isBlocked()) {
            abort(422, 'This submission was flagged as spam.');
        }

        // Make the verdict available downstream if the app wants it.
        $request->attributes->set('shield_verdict', $verdict);

        return $next($request);
    }
}
