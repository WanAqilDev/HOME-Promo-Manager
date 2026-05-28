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

    public function testModeExclusivityThrowsForManualWithCampaignCode()
    {
        $row = (object)[
            'id' => 2, 'name' => 'Manual Bad', 'slug' => 'manual-bad',
            'status' => 'active', 'mode' => 'manual',
            'start_date' => '2026-06-06 00:00:00',
            'end_date'   => '2026-06-12 23:59:59',
            'quota' => 100, 'discount_amount' => '10.00',
            'campaign_code' => 'BADCODE', // violation: manual mode must not have campaign_code
            'codes_config'  => null,
        ];
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $mockWpdb;

        $this->expectException(\RuntimeException::class);
        CampaignEngine::get_active();
    }

    public function testGetCodesConfigReturnsEmptyArrayWhenNull()
    {
        $row = (object)[
            'id' => 1, 'name' => 'Auto', 'slug' => 'auto',
            'status' => 'active', 'mode' => 'auto',
            'start_date' => '2026-01-01 00:00:00',
            'end_date'   => '2030-01-01 00:00:00',
            'quota' => 100, 'discount_amount' => '10.00',
            'campaign_code' => 'CODE', 'codes_config' => null,
        ];
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $mockWpdb;

        $campaign = CampaignEngine::get_active();
        $this->assertSame([], $campaign->get_codes_config());
    }

    public function testGetCodesConfigReturnsDecodedArray()
    {
        $row = (object)[
            'id' => 3, 'name' => 'Manual', 'slug' => 'manual',
            'status' => 'active', 'mode' => 'manual',
            'start_date' => '2026-01-01 00:00:00',
            'end_date'   => '2030-01-01 00:00:00',
            'quota' => 100, 'discount_amount' => '10.00',
            'campaign_code' => null,
            'codes_config'  => '{"promo24": 240, "promo12": 240}',
        ];
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $mockWpdb;

        $campaign = CampaignEngine::get_active();
        $config = $campaign->get_codes_config();
        $this->assertEquals(240, $config['promo24']);
        $this->assertEquals(240, $config['promo12']);
    }

    public function testActivateSucceedsWhenPointerIsNull()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        // START TRANSACTION, UPDATE pointer (1 row affected), UPDATE campaigns set active, UPDATE others set paused, COMMIT = 5 calls
        $mockWpdb->shouldReceive('query')->times(5)->andReturn(1);
        $GLOBALS['wpdb'] = $mockWpdb;

        $result = CampaignEngine::activate(1, 99);
        $this->assertEquals('ok', $result['status']);
    }

    public function testActivateRejectsWhenAnotherCampaignIsActive()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('query')
            ->with('START TRANSACTION')->once()->andReturn(true);
        // UPDATE returns 0 (pointer is occupied by another campaign)
        $mockWpdb->shouldReceive('query')
            ->with('sql')->once()->andReturn(0);
        // Re-SELECT: returns different campaign id (7, not 1)
        $mockWpdb->shouldReceive('get_var')->once()->andReturn('7');
        // ROLLBACK
        $mockWpdb->shouldReceive('query')
            ->with('ROLLBACK')->once()->andReturn(true);
        $GLOBALS['wpdb'] = $mockWpdb;

        $result = CampaignEngine::activate(1, 99);
        $this->assertEquals('conflict', $result['status']);
        $this->assertEquals(7, $result['conflict_id']);
    }

    public function testActivateIsIdempotentWhenAlreadyActive()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('query')
            ->with('START TRANSACTION')->once()->andReturn(true);
        // UPDATE returns 0 (pointer already set)
        $mockWpdb->shouldReceive('query')
            ->with('sql')->once()->andReturn(0);
        // Re-SELECT: same campaign_id = 1 (idempotent)
        $mockWpdb->shouldReceive('get_var')->once()->andReturn('1');
        // UPDATE to set active status + COMMIT
        $mockWpdb->shouldReceive('query')
            ->with('sql')->once()->andReturn(1);
        $mockWpdb->shouldReceive('query')
            ->with('COMMIT')->once()->andReturn(true);
        $GLOBALS['wpdb'] = $mockWpdb;

        $result = CampaignEngine::activate(1, 99);
        $this->assertEquals('ok', $result['status']);
    }

    public function testDeactivateIsIdempotent()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        // START TRANSACTION, UPDATE pointer (0 = already null), UPDATE status, COMMIT — all queries succeed
        $mockWpdb->shouldReceive('query')->andReturn(0);
        $GLOBALS['wpdb'] = $mockWpdb;

        $result = CampaignEngine::deactivate(1, 99);
        $this->assertEquals('ok', $result['status']);
    }

    public function testClaimSlotReturnsNoActiveCampaignWhenNone()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->andReturn(null); // no active campaign
        $GLOBALS['wpdb'] = $mockWpdb;

        $ctx = (object)['entry_id' => 1, 'daftar' => 'Ya', 'went_pasif_at' => null,
                        'pasif_days' => null, 'event' => 'created', 'prev_daftar' => null,
                        'status' => 1, 'status_label' => 'Aktif', 'submitted_code' => null];
        $result = CampaignEngine::claim_slot($ctx);
        $this->assertEquals('no_active_campaign', $result['status']);
    }

    public function testClaimSlotReturnsAlreadyCounted()
    {
        $row = (object)[
            'id' => 1, 'name' => 'T', 'slug' => 't', 'status' => 'active',
            'mode' => 'auto', 'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2030-01-01 00:00:00',
            'quota' => 100, 'discount_amount' => '10.00',
            'campaign_code' => 'TEST', 'codes_config' => null,
        ];
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn($row);
        $mockWpdb->shouldReceive('get_var')->once()->andReturn('1'); // already counted
        $GLOBALS['wpdb'] = $mockWpdb;

        $ctx = (object)['entry_id' => 5, 'daftar' => 'Ya', 'went_pasif_at' => null,
                        'pasif_days' => null, 'event' => 'created', 'prev_daftar' => null,
                        'status' => 1, 'status_label' => 'Aktif', 'submitted_code' => null];
        $result = CampaignEngine::claim_slot($ctx);
        $this->assertEquals('already_counted', $result['status']);
    }

    public function testClaimSlotIneligibleWhenDaftarNotYa()
    {
        $row = (object)[
            'id' => 1, 'name' => 'T', 'slug' => 't', 'status' => 'active',
            'mode' => 'auto', 'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2030-01-01 00:00:00',
            'quota' => 100, 'discount_amount' => '10.00',
            'campaign_code' => 'TEST', 'codes_config' => null,
        ];
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn($row);
        $mockWpdb->shouldReceive('get_var')->once()->andReturn('0'); // not counted
        $GLOBALS['wpdb'] = $mockWpdb;

        $ctx = (object)['entry_id' => 5, 'daftar' => 'Tidak', 'went_pasif_at' => null,
                        'pasif_days' => null, 'event' => 'created', 'prev_daftar' => null,
                        'status' => 1, 'status_label' => 'Aktif', 'submitted_code' => null];
        $result = CampaignEngine::claim_slot($ctx);
        $this->assertEquals('ineligible', $result['status']);
    }

    public function testClaimSlotDeniesOnStatusDivergence()
    {
        $row = (object)[
            'id' => 1, 'name' => 'T', 'slug' => 't', 'status' => 'active',
            'mode' => 'auto', 'start_date' => '2026-01-01 00:00:00',
            'end_date' => '2030-01-01 00:00:00',
            'quota' => 100, 'discount_amount' => '10.00',
            'campaign_code' => 'TEST', 'codes_config' => null,
        ];
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn($row);
        $mockWpdb->shouldReceive('get_var')->once()->andReturn('0'); // not counted
        $GLOBALS['wpdb'] = $mockWpdb;

        // status_label='Aktif' but status=0 (divergence)
        $ctx = (object)['entry_id' => 5, 'daftar' => 'Ya', 'went_pasif_at' => null,
                        'pasif_days' => null, 'event' => 'created', 'prev_daftar' => null,
                        'status' => 0, 'status_label' => 'Aktif', 'submitted_code' => null];
        $result = CampaignEngine::claim_slot($ctx);
        $this->assertEquals('status_divergence', $result['status']);
    }
}
