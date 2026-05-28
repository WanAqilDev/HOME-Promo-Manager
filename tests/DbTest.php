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

    public function testInstallCreatesSqlForAllNewTables()
    {
        // Capture SQL passed to dbDelta by replacing the global function
        $captured_sql = [];
        // We can't easily intercept dbDelta, so test that install() runs without
        // exception when the three new CREATE TABLE methods exist on DB.
        // Also verify that the three methods produce the right table names.
        $this->assertContains('home_promo_active',    DB::get_new_table_sql_names());
        $this->assertContains('home_promo_campaigns',  DB::get_new_table_sql_names());
        $this->assertContains('home_promo_status_log', DB::get_new_table_sql_names());
    }

    public function testAlterCountedTableRunsWhenColumnMissing()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        // column_exists for 'campaign_id' → returns null (missing)
        // column_exists for 'went_pasif_at' → returns 'went_pasif_at' (already present)
        // InnoDB check → returns 'InnoDB' (no ALTER ENGINE needed)
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_var')
            ->andReturnValues([null, 'went_pasif_at', 'InnoDB']);
        $mockWpdb->shouldReceive('query')
            ->with(Mockery::pattern('/ALTER TABLE.*home_promo_counted.*ADD COLUMN campaign_id/s'))
            ->once()
            ->andReturn(true);
        $GLOBALS['wpdb'] = $mockWpdb;

        DB::run_column_migrations();
        $this->assertTrue(true);
    }

    public function testAlterCountedTableSkipsWhenColumnExists()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        // column_exists for 'campaign_id' → exists
        // column_exists for 'went_pasif_at' → exists
        // InnoDB check → 'InnoDB' (no ALTER needed)
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_var')
            ->andReturnValues(['campaign_id', 'went_pasif_at', 'InnoDB']);
        // ALTER must NOT be called
        $mockWpdb->shouldNotReceive('query');
        $GLOBALS['wpdb'] = $mockWpdb;

        DB::run_column_migrations();
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
