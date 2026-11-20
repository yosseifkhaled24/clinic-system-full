<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-17: security headers for Laravel API responses.
 *
 * The Next.js frontend already sends X-Frame-Options / nosniff /
 * Referrer-Policy / Permissions-Policy (next.config.ts) for HTML pages;
 * this middleware gives the API the same baseline protection:
 *
 *  - X-Content-Type-Options: nosniff      — blocks MIME sniffing.
 *  - X-Frame-Options: DENY               — API JSON must not be framed.
 *  - Referrer-Policy: no-referrer         — no URL leakage off-origin.
 *  - Permissions-Policy                   — camera/mic/geo disabled.
 *  - Content-Security-Policy (JSON only)  — `default-src 'none'` style
 *    deny-all policy: API responses are machine-consumed JSON, never
 *    rendered, so nothing legitimate needs scripts/styles/images. It is
 *    deliberately NOT applied to non-JSON responses (debug/whoops HTML
 *    pages, file downloads) where a strict policy could break rendering
 *    without adding value.
 *
 * HSTS is intentionally NOT set: local development runs over plain HTTP
 * and an HSTS header there would poison browsers for localhost. For
 * production the reverse proxy / platform MUST terminate TLS and either
 * enable its own HSTS (recommended: `Strict-Transport-Security:
 * max-age=31536000; includeSubDomains` on the HTTPS listener) — this is
 * a deployment requirement, not a code change, because no production
 * configuration value may be invented here.
 *
 * Headers already present on a response are never overwritten, so any
 * controller-specific policy wins.
 */
class SecurityHeaders
{
    /**
     * Deny-all policy for JSON API payloads (no rendering surface).
     */
    private const JSON_CONTENT_SECURITY_POLICY = "default-src 'none'; "
        . "frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    /**
     * Headers applied to every response, unless already set.
     *
     * @var array<string, string>
     */
    private const DEFAULT_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::DEFAULT_HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if (! $response->headers->has('Content-Security-Policy')
            && str_contains((string) $response->headers->get('Content-Type'), 'application/json')) {
            $response->headers->set('Content-Security-Policy', self::JSON_CONTENT_SECURITY_POLICY);
        }

        return $response;
    }
}
