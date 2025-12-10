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

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
