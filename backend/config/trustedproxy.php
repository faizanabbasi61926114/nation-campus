<?php

/*
 * Which network peers may speak for the visitor.
 *
 * Laravel's global TrustProxies middleware reads this file on every request, so `$request->ip()`
 * — and the address stamped into audit_logs and the login throttle — resolves X-Forwarded-For only
 * for the proxies listed here. Leave null when the app server is reached directly.
 *
 *   null                  nobody: read the socket peer
 *   '*'                   trust the calling address, i.e. one managed edge in front (Vercel,
 *                         Cloudflare, an ALB). Use only when the API is unreachable except
 *                         through it, otherwise a client can forge its own address.
 *   '10.0.0.5, 10.0.0.6'  specific proxy IPs or CIDR ranges.
 */

return [
    'proxies' => env('TRUSTED_PROXIES'),
];
