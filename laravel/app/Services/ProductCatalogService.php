<?php

namespace App\Services;

use App\Support\NominalLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProductCatalogService
{
    /** @return array{products:array<int,array<string,mixed>>,databaseReady:bool} */
    public function publicCatalog(): array
    {
        $products = DB::table('products')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $packages = DB::table('product_packages')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $notices = DB::table('product_notices')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $monitor = DB::table('digiflazz_seller_monitor')->get()->keyBy('package_id');
        $voucherStock = DB::table('voucher_codes')
            ->where('status', 'available')
            ->distinct()
            ->pluck('stock_key')
            ->flip();

        $reviews = DB::table('product_reviews')
            ->where('is_visible', 1)
            ->selectRaw('product_slug, AVG(rating) as rating_average, COUNT(*) as rating_count')
            ->groupBy('product_slug')
            ->get()
            ->keyBy('product_slug');

        $result = [];
        foreach ($products as $product) {
            $publicPackages = [];

            foreach ($packages->get($product->id, collect()) as $package) {
                $providerCode = strtolower(trim((string) ($package->provider_code ?? '')));
                $providerSku = trim((string) ($package->provider_sku ?? ''));
                $mode = $product->fulfillment_type === 'manual'
                    ? 'manual'
                    : ($providerCode === 'voucher-stock' ? 'voucher_stock' : 'provider');

                $available = $product->fulfillment_type === 'manual';
                if ($providerCode === 'voucher-stock') {
                    $available = $providerSku !== '' && $voucherStock->has($providerSku);
                } elseif ($providerCode === 'digiflazz') {
                    $available = $this->digiflazzAvailable(
                        $monitor->get($package->id),
                        $package->provider_max_price === null ? null : (int) $package->provider_max_price,
                    );
                }

                if (!$available) {
                    continue;
                }

                $publicPackages[] = [
                    'id' => (string) $package->sku,
                    'label' => NominalLabel::clean((string) $product->name, (string) $package->label),
                    'price' => (int) $package->price,
                    'note' => $package->note,
                    'group' => $package->package_group,
                    'imageUrl' => $package->image_url,
                    'fulfillmentMode' => $mode,
                    'fulfillmentReady' => $product->fulfillment_type === 'manual' || ($providerCode !== '' && $providerSku !== ''),
                    'fulfillmentAvailable' => true,
                    '_sort' => [NominalLabel::numericKey((string) $product->name, (string) $package->label), (int) $package->sort_order, (string) $package->label],
                ];
            }

            usort($publicPackages, static function (array $a, array $b): int {
                return $a['_sort'][0] <=> $b['_sort'][0]
                    ?: $a['_sort'][1] <=> $b['_sort'][1]
                    ?: strnatcasecmp($a['_sort'][2], $b['_sort'][2]);
            });
            foreach ($publicPackages as &$package) {
                unset($package['_sort']);
            }
            unset($package);

            if ($publicPackages === []) {
                continue;
            }

            $summary = $reviews->get($product->slug);
            $result[] = [
                'slug' => (string) $product->slug,
                'name' => (string) $product->name,
                'publisher' => (string) $product->publisher,
                'category' => (string) $product->category,
                'imageUrl' => $product->image_url,
                'bannerUrl' => $product->banner_url ?: $product->image_url,
                'description' => $product->description,
                'initials' => (string) $product->initials,
                'accent' => (string) $product->accent,
                'inputLabel' => (string) $product->input_label,
                'inputPlaceholder' => (string) $product->input_placeholder,
                'inputFields' => $this->inputFields($product),
                'nicknameRequired' => trim((string) ($product->nickname_game_code ?? '')) !== '',
                'needsServer' => (bool) $product->needs_server,
                'popular' => (bool) $product->popular,
                'instant' => (bool) $product->instant,
                'fulfillmentType' => (string) $product->fulfillment_type,
                'manualInstructions' => $product->manual_instructions,
                'manualOpenTime' => $product->manual_open_time,
                'manualCloseTime' => $product->manual_close_time,
                'manualTimezone' => $product->manual_timezone ?: 'Asia/Jakarta',
                'packageTabsEnabled' => (bool) $product->package_tabs_enabled,
                'packageTabs' => $this->stringArray($product->package_tabs_json),
                'notices' => $notices->get($product->id, collect())->map(fn ($notice) => [
                    'id' => (int) $notice->id,
                    'title' => (string) $notice->title,
                    'body' => (string) $notice->body,
                    'isActive' => (bool) $notice->is_active,
                    'sortOrder' => (int) $notice->sort_order,
                ])->values()->all(),
                'packages' => $publicPackages,
                'ratingAverage' => (float) ($summary?->rating_average ?? 0),
                'ratingCount' => (int) ($summary?->rating_count ?? 0),
            ];
        }

        return ['products' => $result, 'databaseReady' => true];
    }

    private function digiflazzAvailable(?object $row, ?int $maxPrice = null): bool
    {
        if (!$row || !(bool) $row->buyer_product_status || !(bool) $row->seller_product_status) {
            return false;
        }

        if (!(bool) $row->unlimited_stock && (int) $row->stock <= 0) {
            return false;
        }

        if ($maxPrice !== null && $row->current_price !== null && (int) $row->current_price > $maxPrice) {
            return false;
        }

        $start = trim((string) ($row->start_cut_off ?? ''));
        $end = trim((string) ($row->end_cut_off ?? ''));
        if ($start === '' || $end === '' || $start === $end) {
            return true;
        }

        try {
            $now = CarbonImmutable::now('Asia/Jakarta');
            $minute = ($now->hour * 60) + $now->minute;
            $startMinute = $this->toMinute($start);
            $endMinute = $this->toMinute($end);

            if ($startMinute === null || $endMinute === null) {
                return true;
            }

            $cutoff = $startMinute < $endMinute
                ? $minute >= $startMinute && $minute < $endMinute
                : $minute >= $startMinute || $minute < $endMinute;

            return !$cutoff;
        } catch (Throwable) {
            return false;
        }
    }

    private function toMinute(string $value): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $match)) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = (int) $match[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return $hour * 60 + $minute;
    }

    /** @return array<int,array<string,mixed>> */
    private function inputFields(object $product): array
    {
        $decoded = json_decode((string) ($product->input_fields_json ?? ''), true);
        if (is_array($decoded)) {
            $result = [];
            foreach (array_slice($decoded, 0, 12) as $index => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $label = trim((string) ($field['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $result[] = [
                    'id' => trim((string) ($field['id'] ?? '')) ?: 'field-'.($index + 1),
                    'label' => $label,
                    'placeholder' => trim((string) ($field['placeholder'] ?? '')),
                    'required' => ($field['required'] ?? true) !== false,
                ];
            }
            if ($result !== []) {
                return $result;
            }
        }

        $fields = [[
            'id' => 'account-id',
            'label' => (string) $product->input_label,
            'placeholder' => (string) $product->input_placeholder,
            'required' => true,
        ]];

        if ((bool) $product->needs_server) {
            $fields[] = [
                'id' => 'server-zone',
                'label' => 'Server / Zone ID',
                'placeholder' => 'Contoh: 1234',
                'required' => true,
            ];
        }

        return $fields;
    }

    /** @return list<string> */
    private function stringArray(?string $json): array
    {
        $decoded = json_decode($json ?: '[]', true);
        if (!is_array($decoded)) {
            return [];
        }

        $values = [];
        foreach ($decoded as $value) {
            if (is_string($value) && trim($value) !== '') {
                $values[] = trim($value);
            }
        }

        return array_values(array_unique(array_slice($values, 0, 20)));
    }
}
