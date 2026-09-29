<?php

namespace App\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class SecurityGuard
{
    public function assertSameOrigin(Request $request): void
    {
        if (in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $fetchSite = strtolower((string) $request->header('sec-fetch-site', ''));
        $origin = $request->headers->get('origin');

        // A cross-site navigation through Cloudflare Access can retain Fetch
        // Metadata from that navigation. The browser Origin header identifies
        // the document that submitted this form and is the decisive CSRF check.
        if (!$origin) {
            if ($fetchSite === 'cross-site') {
                throw new HttpResponseException(response()->json([
                    'error' => 'Permintaan lintas situs ditolak.',
                ], 403));
            }

            return;
        }

        $originParts = parse_url($origin);
        $siteParts = parse_url((string) config('app.url'));
        if (!is_array($originParts) || empty($originParts['scheme']) || empty($originParts['host'])
            || !is_array($siteParts) || empty($siteParts['scheme']) || empty($siteParts['host'])) {
            throw new HttpResponseException(response()->json([
                'error' => 'Origin permintaan tidak valid.',
            ], 403));
        }

        $originPort = isset($originParts['port']) ? ':'.$originParts['port'] : '';
        $sitePort = isset($siteParts['port']) ? ':'.$siteParts['port'] : '';
        $normalizedOrigin = strtolower($originParts['scheme'].'://'.$originParts['host'].$originPort);
        $siteOrigin = strtolower($siteParts['scheme'].'://'.$siteParts['host'].$sitePort);

        // APP_URL is the trusted public origin. The request scheme can appear
        // as HTTP at the Laravel hop even when the browser used HTTPS.
        if (!hash_equals($siteOrigin, $normalizedOrigin)
            || !hash_equals(strtolower($siteParts['host']), strtolower($request->getHost()))) {
            throw new HttpResponseException(response()->json([
                'error' => 'Permintaan lintas situs ditolak.',
            ], 403));
        }
    }

    /** @return array{allowed:bool,retry_after:int} */
    public function rateLimit(Request $request, string $scope, int $limit, int $windowSeconds = 600): array
    {
        try {
            $bucket = intdiv(time(), $windowSeconds) * $windowSeconds;
            $key = (string) ($request->header('CF-Connecting-IP')
                ?: $request->header('X-Forwarded-For')
                ?: $request->ip()
                ?: 'unknown');

            $keyHash = hash('sha256', trim(explode(',', $key)[0]));
            $hits = DB::transaction(function () use ($scope, $bucket, $keyHash): int {
                $query = DB::table('security_rate_limits')
                    ->where('scope', $scope)
                    ->where('bucket_start', $bucket)
                    ->where('key_hash', $keyHash)
                    ->lockForUpdate();

                $row = $query->first();
                if (!$row) {
                    DB::table('security_rate_limits')->insert([
                        'scope' => $scope,
                        'bucket_start' => $bucket,
                        'key_hash' => $keyHash,
                        'hits' => 1,
                    ]);

                    return 1;
                }

                $next = ((int) $row->hits) + 1;
                DB::table('security_rate_limits')
                    ->where('scope', $scope)
                    ->where('bucket_start', $bucket)
                    ->where('key_hash', $keyHash)
                    ->update(['hits' => $next]);

                return $next;
            }, 3);

            return [
                'allowed' => $hits <= $limit,
                'retry_after' => max(1, $windowSeconds - (time() - $bucket)),
            ];
        } catch (Throwable) {
            return ['allowed' => false, 'retry_after' => $windowSeconds];
        }
    }
}
