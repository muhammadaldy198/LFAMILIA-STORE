<?php

namespace Tests\Unit;

use App\Services\Fulfillment\DigiflazzDestinationTemplate;
use App\Services\Fulfillment\FulfillmentTargetBuilder;
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
        $this->assertSame('{{user_id}}', $resolver->resolve('other-game', '{{destination}}', ['user_id']));
    }

    public function test_mobile_legends_customer_number_preserves_leading_zeros_and_other_games_keep_separators(): void
    {
        $resolver = new DigiflazzDestinationTemplate;
        $target = new FulfillmentTargetBuilder;
        $input = ['user_id' => '0012345', 'zone_id' => '0099'];

        $this->assertSame('00123450099', $target->customerNo($input, [
            'customer_no_template' => $resolver->resolve(
                'mobile-legends', '{{user_id}}({{zone_id}})', ['user_id', 'zone_id']
            ),
        ]));
        $this->assertSame('0012345#0099', $target->customerNo($input, [
            'customer_no_template' => $resolver->resolve(
                'genshin-impact', '{{user_id}}#{{zone_id}}', ['user_id', 'zone_id']
            ),
        ]));
    }
}
