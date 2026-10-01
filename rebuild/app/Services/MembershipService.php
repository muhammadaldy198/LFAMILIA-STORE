<?php

namespace App\Services;

use App\Models\MembershipTier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MembershipService
{
    /**
     * @return array<string, mixed>
     */
    public function profile(User $user): array
    {
        $tiers = MembershipTier::query()->where('is_active', true)->orderBy('rank')->get();
        $lifetimeSpend = (int) DB::table('orders')
            ->where('user_id', $user->id)
            ->whereIn('status', ['PAID', 'PROCESSING', 'SUCCESS'])
            ->sum('total_idr');
        $bonus = max(0, (int) ($user->membership_progress_bonus_idr ?? 0));
        $progress = $lifetimeSpend + $bonus;

        $automatic = $tiers->firstWhere('code', 'BASIC') ?? $tiers->first();
        foreach ($tiers as $tier) {
            $requirements = $this->array($tier->requirements);
            $minimum = $this->minimumSpend($requirements);
            if ($minimum !== null && $progress >= $minimum) {
                $automatic = $tier;
            }
        }

        $mode = strtoupper((string) ($user->membership_mode ?? 'AUTO')) === 'MANUAL' ? 'MANUAL' : 'AUTO';
        $override = $mode === 'MANUAL'
            ? $tiers->firstWhere('code', (string) $user->membership_override_code)
            : null;
        $current = $override ?: $automatic;

        $next = null;
        foreach ($tiers as $tier) {
            if ((int) $tier->rank <= (int) $current?->rank) {
                continue;
            }
            $minimum = $this->minimumSpend($this->array($tier->requirements));
            if ($minimum !== null) {
                $next = ['tier' => $tier, 'minimum' => $minimum];
                break;
            }
        }

        $benefits = $this->array($current?->benefits);
        $discountBps = max(0, min(10000, (int) ($benefits['discount_bps'] ?? 0)));

        return [
            'code' => $current?->code ?? 'BASIC',
            'rank' => (int) ($current?->rank ?? 1),
            'mode' => $mode,
            'override_code' => $override?->code,
            'lifetime_spend_idr' => $lifetimeSpend,
            'progress_bonus_idr' => $bonus,
            'progress_idr' => $progress,
            'discount_bps' => $discountBps,
            'benefits' => $benefits,
            'requirements' => $this->array($current?->requirements),
            'next_code' => $next['tier']->code ?? null,
            'next_target_idr' => $next['minimum'] ?? null,
            'remaining_to_next_idr' => isset($next['minimum']) ? max(0, $next['minimum'] - $progress) : 0,
        ];
    }

    public function discount(User $user, int $subtotalIdr): array
    {
        $profile = $this->profile($user);
        $discount = $profile['discount_bps'] > 0
            ? intdiv(($subtotalIdr * $profile['discount_bps']), 10000)
            : 0;
        $discount = min(max(0, $discount), max(0, $subtotalIdr - 1));

        return [
            'amount_idr' => $discount,
            'tier_code' => $profile['code'],
            'discount_bps' => $profile['discount_bps'],
            'profile' => $profile,
        ];
    }

    public function sync(User $user): array
    {
        $profile = $this->profile($user);
        if ($user->membership_tier_code !== $profile['code']) {
            $user->forceFill(['membership_tier_code' => $profile['code']])->saveQuietly();
        }

        return $profile;
    }

    public function setMode(User $user, string $value): void
    {
        $value = strtoupper(trim($value));
        if ($value === 'AUTO') {
            $user->forceFill([
                'membership_mode' => 'AUTO',
                'membership_override_code' => null,
                'membership_progress_bonus_idr' => 0,
            ])->save();
            $this->sync($user);

            return;
        }

        $tier = MembershipTier::query()->where('code', $value)->where('is_active', true)->firstOrFail();
        $profile = $this->profile($user);
        $minimum = $this->minimumSpend($this->array($tier->requirements));
        $bonus = $minimum !== null ? max(0, $minimum - (int) $profile['lifetime_spend_idr']) : (int) $profile['progress_bonus_idr'];

        $user->forceFill([
            'membership_mode' => 'MANUAL',
            'membership_override_code' => $tier->code,
            'membership_progress_bonus_idr' => $bonus,
            'membership_tier_code' => $tier->code,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function array(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function minimumSpend(array $requirements): ?int
    {
        if (! array_key_exists('minimum_spend_idr', $requirements)) {
            return null;
        }

        return max(0, (int) $requirements['minimum_spend_idr']);
    }
}
