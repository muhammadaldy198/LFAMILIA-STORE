<?php

namespace Tests\Feature;

use App\Models\MembershipTier;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerMembershipVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function customer(): User
    {
        return User::create([
            'name' => 'Membership Visibility',
            'email' => 'membership-'.bin2hex(random_bytes(5)).'@example.test',
            'phone' => '0812'.random_int(10000000, 99999999),
            'password' => Hash::make('Customer-password-123'),
        ]);
    }

    public function test_basic_customer_never_receives_mafia_tier_props(): void
    {
        $user = $this->customer();
        $this->actingAs($user, 'web')
            ->get('/account/membership')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Membership')
                ->where('currentCode', 'BASIC')
                ->where('tiers', fn ($tiers) => collect($tiers)->pluck('code')->last() === 'PLATINUM'
                    && ! collect($tiers)->pluck('code')->contains('MAFIA'))
                ->where('profile.next_code', fn ($code) => $code !== 'MAFIA')
                ->etc());
    }

    public function test_platinum_progress_stops_without_exposing_mafia_even_if_configured(): void
    {
        $user = $this->customer();
        app(MembershipService::class)->setMode($user, 'PLATINUM');
        $mafia = MembershipTier::where('code', 'MAFIA')->firstOrFail();
        $mafia->forceFill(['requirements' => ['minimum_spend_idr' => 50000001]])->save();

        $this->actingAs($user, 'web')
            ->get('/account/membership')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Membership')
                ->where('currentCode', 'PLATINUM')
                ->where('tiers', fn ($tiers) => collect($tiers)->pluck('code')->last() === 'PLATINUM'
                    && ! collect($tiers)->pluck('code')->contains('MAFIA'))
                ->where('profile.next_code', null)
                ->where('profile.next_target_idr', null)
                ->where('profile.remaining_to_next_idr', 0)
                ->etc());
    }

    public function test_manual_mafia_holder_receives_own_tier_and_discount(): void
    {
        $user = $this->customer();
        app(MembershipService::class)->setMode($user, 'MAFIA');
        $expected = app(MembershipService::class)->discount($user, 100000);
        $this->assertSame('MAFIA', $expected['tier_code']);

        $this->actingAs($user, 'web')
            ->get('/account/membership')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Membership')
                ->where('currentCode', 'MAFIA')
                ->where('profile.code', 'MAFIA')
                ->where('profile.discount_bps', $expected['discount_bps'])
                ->where('tiers', fn ($tiers) => collect($tiers)->contains('code', 'MAFIA'))
                ->etc());

        $this->assertSame($expected, app(MembershipService::class)->discount($user, 100000));
    }
}
