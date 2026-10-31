<?php

namespace Tests\Unit;

use App\Services\Fulfillment\DigiflazzDestinationTemplate;
use PHPUnit\Framework\TestCase;

class DigiflazzDestinationTemplateTest extends TestCase
{
    public function test_mobile_legends_uses_concatenated_user_and_zone_ids(): void
    {
        $resolver = new DigiflazzDestinationTemplate;
        $this->assertSame('{{user_id}}{{zone_id}}', $resolver->resolve(
            'mobile-legends', '{{user_id}}({{zone_id}})', ['user_id', 'zone_id']
        ));
        $this->assertSame('{{user_id}}{{zone_id}}', $resolver->resolve(
            'mobile-legends', null, ['user_id', 'zone_id']
        ));
    }

    public function test_other_games_keep_their_own_format_instead_of_guessing(): void
    {
        $resolver = new DigiflazzDestinationTemplate;
        $this->assertNull($resolver->resolve('genshin-impact', null, ['user_id', 'zone_id']));
        $this->assertSame('{{user_id}}#{{zone_id}}', $resolver->resolve(
            'genshin-impact', '{{user_id}}#{{zone_id}}', ['user_id', 'zone_id']
        ));
        $this->assertSame('{{destination}}', $resolver->resolve('other-game', null, ['destination']));
    }
}
