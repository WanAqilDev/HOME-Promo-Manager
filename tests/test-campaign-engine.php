<?php
use PHPUnit\Framework\TestCase;
use HPM\CampaignEngine;

class CampaignEngineTest extends TestCase
{
    protected function setUp(): void
    {
        CampaignEngine::flush();
    }

    protected function tearDown(): void
    {
        CampaignEngine::flush();
        Mockery::close();
    }

    public function testGetActiveReturnsNullWhenNoActiveRow()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn(null);
        $GLOBALS['wpdb'] = $mockWpdb;

        $this->assertNull(CampaignEngine::get_active());
    }

    public function testGetActiveIsMemoised()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        // get_row called exactly ONCE even though get_active() is called twice
        $mockWpdb->shouldReceive('get_row')->once()->andReturn(null);
        $GLOBALS['wpdb'] = $mockWpdb;

        CampaignEngine::get_active();
        CampaignEngine::get_active();
        $this->assertTrue(true);
    }

    public function testFlushClearsCache()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        // get_row should be called TWICE (once before flush, once after)
        $mockWpdb->shouldReceive('get_row')->twice()->andReturn(null);
        $GLOBALS['wpdb'] = $mockWpdb;

        CampaignEngine::get_active();
        CampaignEngine::flush();
        CampaignEngine::get_active();
        $this->assertTrue(true);
    }

    public function testIsActiveFalseWhenNoCampaign()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn(null);
        $GLOBALS['wpdb'] = $mockWpdb;

        $this->assertFalse(CampaignEngine::is_active());
    }

    public function testGetActiveReturnsCampaignObject()
    {
        $row = (object)[
            'id' => 1, 'name' => 'Test', 'slug' => 'test',
            'status' => 'active', 'mode' => 'auto',
            'start_date' => '2026-06-06 00:00:00',
            'end_date'   => '2026-06-12 23:59:59',
            'quota' => 330, 'discount_amount' => '33.00',
            'campaign_code' => '6CURE', 'codes_config' => null,
        ];
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $mockWpdb;

        $campaign = CampaignEngine::get_active();
        $this->assertNotNull($campaign);
        $this->assertEquals(1, $campaign->id);
        $this->assertEquals('6CURE', $campaign->campaign_code);
        $this->assertEquals(330, $campaign->quota);
    }

    public function testModeExclusivityThrowsForAutoWithCodesConfig()
    {
        $row = (object)[
            'id' => 1, 'name' => 'Bad', 'slug' => 'bad',
            'status' => 'active', 'mode' => 'auto',
            'start_date' => '2026-06-06 00:00:00',
            'end_date'   => '2026-06-12 23:59:59',
            'quota' => 100, 'discount_amount' => '10.00',
            'campaign_code' => null,
            'codes_config'  => '{"promo": 100}', // violation
        ];
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $mockWpdb;

        $this->expectException(\RuntimeException::class);
        CampaignEngine::get_active();
    }
}
