<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes the visitor's address from the header the hosting platform sets,
 * when the operator says which one that is (CLIENT_IP_HEADER).
 *
 * Why it exists: Laravel's own answer, from X-Forwarded-For, is only right
 * when exactly one proxy sits in front of the app. Railway's edge has more
 * than one, and with only the last hop trusted the "client" turned out to be
 * an intermediate proxy in another country. Every visitor then shared one
 * address - so unique-visitor counts collapsed, geography named the proxy's
 * country, and every per-address rate limit was one bucket for the whole
 * world.
 *
 * It rewrites REMOTE_ADDR (what nginx's realip module does) and reduces
 * X-Forwarded-For to that one address, so every later reader of the client
 * address - hashing, geolocation, throttling - agrees.
 *
 * Only ever enable it where every request reaches the app through a proxy
 * that overwrites that header. Reachable directly, the header is just
 * something a visitor can write.
 */
class RealIpFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = config('features.client_ip_header');

        if ($header) {
            $address = $this->addressIn((string) $request->headers->get($header));

            if ($address !== null) {
                $request->server->set('REMOTE_ADDR', $address);
                $request->headers->set('X-Forwarded-For', $address);
            }
        }

        return $next($request);
    }

    /** The first entry of a possibly comma-separated value, if it is an address at all. */
    private function addressIn(string $value): ?string
    {
        $first = trim(explode(',', $value)[0]);

        return filter_var($first, FILTER_VALIDATE_IP) !== false ? $first : null;
    }
}
