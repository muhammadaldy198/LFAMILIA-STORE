<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MediaMigrationService
{
    /** @return array{referenced:int,existing:int,downloaded:int,failed:int,failures:list<array{key:string,error:string}>} */
    public function migrateReferenced(string $sourceBase, ?callable $log = null): array
    {
        $sourceBase = rtrim(trim($sourceBase), '/');
        if (!preg_match('#^https://[^/]+$#i', $sourceBase)) {
            throw new RuntimeException('Source media harus berupa origin HTTPS tanpa path.');
        }

        $keys = $this->referencedKeys();
        $existing = 0;
        $downloaded = 0;
        $failed = 0;
        $failures = [];

        foreach ($keys as $key) {
            if (DB::table('media_assets')->where('media_key', $key)->exists()) {
                $existing++;
                $log('skip '.$key.' (sudah ada)');
                continue;
            }

            try {
                $response = Http::accept('*/*')
                    ->timeout(20)
                    ->retry(2, 300)
                    ->get($sourceBase.'/api/media/'.$key);

                if (!$response->successful()) {
                    throw new RuntimeException('HTTP '.$response->status());
                }

                $body = $response->body();
                if ($body === '') {
                    throw new RuntimeException('body kosong');
                }

                $contentType = strtolower(trim((string) $response->header('content-type')));
                if (!preg_match('#^image/(?:jpeg|png|webp|gif)(?:;|$)#', $contentType)) {
                    throw new RuntimeException('content-type tidak valid: '.$contentType);
                }

                DB::table('media_assets')->insert([
                    'media_key' => $key,
                    'content_type' => preg_replace('/;.*$/', '', $contentType),
                    'data' => $body,
                    'etag' => hash('sha256', $body),
                    'original_name' => $key,
                    'created_at' => now(),
                ]);
                $downloaded++;
                $log('ok '.$key.' ('.strlen($body).' bytes)');
            } catch (\Throwable $error) {
                $failed++;
                $failures[] = ['key' => $key, 'error' => $error->getMessage()];
                $log('gagal '.$key.': '.$error->getMessage());
            }
        }

        return compact('existing', 'downloaded', 'failed', 'failures') + [
            'referenced' => count($keys),
        ];
    }

    /** @return list<string> */
    public function referencedKeys(): array
    {
        $keys = [];

        $sources = [
            ['products', ['image_url', 'banner_url']],
            ['product_packages', ['image_url']],
            ['home_banners', ['image_url']],
            ['news_articles', ['cover_url']],
            ['store_settings', ['logo_url', 'banner_image_url']],
            ['wallet_topups', ['proof_url']],
            ['payment_channels', ['image_url']],
        ];

        foreach ($sources as [$table, $columns]) {
            $rows = DB::table($table)->get($columns);
            foreach ($rows as $row) {
                foreach ($columns as $column) {
                    $this->collectFromValue($row->{$column} ?? null, $keys);
                }
            }
        }

        $settings = DB::table('payment_page_settings')->pluck('config_json');
        foreach ($settings as $json) {
            $decoded = is_string($json) ? json_decode($json, true) : null;
            $this->collectFromValue($decoded, $keys);
        }

        ksort($keys);
        return array_keys($keys);
    }

    /** @param array<string,true> $keys */
    private function collectFromValue(mixed $value, array &$keys): void
    {
        if (is_string($value)) {
            $match = [];
            if (preg_match('#(?:^|/)(media-[0-9a-f-]{36}\.(?:jpg|jpeg|png|webp|gif))(?:[?#].*)?$#i', trim($value), $match)) {
                $keys[$match[1]] = true;
            }
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collectFromValue($item, $keys);
            }
        }
    }
}
