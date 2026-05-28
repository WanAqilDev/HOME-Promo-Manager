<?php
use PHPUnit\Framework\TestCase;
use HPM\HookDispatcher;

class HookDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        HookDispatcher::reset_snapshot();
        Mockery::close();
    }

    public function testPreHookStashesFieldValuesInSnapshot()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        // Returns values for fields 196, 199, 1617, 1698 in order
        $mockWpdb->shouldReceive('get_var')
            ->andReturnValues(['Ya', '1', 'Aktif', '2026-01-01']);
        $GLOBALS['wpdb'] = $mockWpdb;

        // Mock Manager to return field IDs
        $settings = [
            'daftar_field_id'       => 196,
            'status_field_id'       => 199,
            'status_label_field_id' => 1617,
            'pasif_date_field_id'   => 1698,
        ];
        // Stub get_option to return settings
        // (bootstrap.php already stubs get_option to return $default)
        // We inject via Manager mock using Mockery::mock('overload:...')
        // But Manager is already loaded — use a simpler approach:
        // Override the global wpdb and let Manager::get_instance()->s() resolve
        // Since Manager reads from get_option and our stub returns false,
        // Manager will use hardcoded defaults if it has them.
        // Instead, let's just test at a lower level — call on_pre_update_entry
        // and read back the snapshot using get_snapshot_for_test().

        // We need Manager to return real field IDs. The Manager in tests
        // reads get_option() which our stub returns false for.
        // Check what Manager::s() returns for missing options...
        // Manager has default field IDs: daftar=196, status=199, label=1617, pasif=1698
        // So this should work without mocking Manager.

        HookDispatcher::on_pre_update_entry(1, []);

        $snapshot = HookDispatcher::get_snapshot_for_test(1);
        $this->assertEquals('Ya',         $snapshot[196]);
        $this->assertEquals('1',          $snapshot[199]);
        $this->assertEquals('Aktif',      $snapshot[1617]);
        $this->assertEquals('2026-01-01', $snapshot[1698]);
    }

    public function testFallbackSelectRunsWhenPreHookMissed()
    {
        // No pre-hook → snapshot is not set → fallback SELECT should fire
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_var')
            ->once() // exactly one fallback SELECT
            ->andReturn('2026-02-01');
        $GLOBALS['wpdb'] = $mockWpdb;

        $value = HookDispatcher::get_field_snapshot_or_fallback(5, 1698);
        $this->assertEquals('2026-02-01', $value);
    }

    public function testSentinelDistinguishesNullFromUnset()
    {
        // Pre-hook ran but read null for field 1698
        HookDispatcher::stash_snapshot(1, 1698, null);

        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        // get_var must NOT be called — the null is already in snapshot
        $mockWpdb->shouldNotReceive('get_var');
        $GLOBALS['wpdb'] = $mockWpdb;

        $value = HookDispatcher::get_field_snapshot_or_fallback(1, 1698);
        $this->assertNull($value);
    }
}
