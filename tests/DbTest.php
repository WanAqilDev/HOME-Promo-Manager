<?php
use PHPUnit\Framework\TestCase;
use HPM\DB;

class DBTest extends TestCase
{

    public function testInsertEntryUsesAtomicQuery()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->rows_affected = 1;

        // Expect prepare to be called with the atomic query structure
        $mockWpdb->shouldReceive('prepare')
            ->with(Mockery::pattern('/INSERT IGNORE INTO .* SELECT .* FROM DUAL WHERE .* < .*/'), 123, 480)
            ->once()
            ->andReturn('SQL');

        $mockWpdb->shouldReceive('query')
            ->with('SQL')
            ->once()
            ->andReturn(true);

        $GLOBALS['wpdb'] = $mockWpdb;

        $result = DB::insert_entry(123, 480);
        $this->assertTrue($result);
    }

    public function testCountReactivations()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';

        $mockWpdb->shouldReceive('get_var')
            ->with(Mockery::pattern('/SELECT COUNT\(\*\) FROM .*reactivations/'))
            ->once()
            ->andReturn('5');

        $GLOBALS['wpdb'] = $mockWpdb;

        $this->assertEquals(5, DB::count_reactivations());
    }

    public function testColumnExistsReturnsTrueWhenFound()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')
            ->with(Mockery::pattern('/SHOW COLUMNS FROM/'), 'campaign_id')
            ->once()
            ->andReturn("SHOW COLUMNS FROM wp_home_promo_counted LIKE 'campaign_id'");
        $mockWpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('campaign_id');
        $GLOBALS['wpdb'] = $mockWpdb;

        $this->assertTrue(DB::column_exists('wp_home_promo_counted', 'campaign_id'));
    }

    public function testColumnExistsReturnsFalseWhenMissing()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')
            ->with(Mockery::pattern('/SHOW COLUMNS FROM/'), 'campaign_id')
            ->once()
            ->andReturn('sql');
        $mockWpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(null);
        $GLOBALS['wpdb'] = $mockWpdb;

        $this->assertFalse(DB::column_exists('wp_home_promo_counted', 'campaign_id'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
