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

    public function testBuildCtxSetsCorrectFieldsForCreatedEvent()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        // Sequence for 'created' event:
        // 1. Log query for went_pasif_at → null (no log entry for new record)
        // 2. Fallback SELECT for pasif_field → null (new entry has no previous value)
        $mockWpdb->shouldReceive('get_var')
            ->andReturnValues([null, null]);
        $GLOBALS['wpdb'] = $mockWpdb;

        $ctx = HookDispatcher::build_ctx_for_test('created', 42, [
            'form_id'   => 13,
            'item_meta' => [
                196  => 'Ya',
                199  => '1',
                1617 => 'Aktif',
                3170 => null,
            ],
        ]);

        $this->assertEquals('created', $ctx->event);
        $this->assertEquals(42, $ctx->entry_id);
        $this->assertEquals('Ya', $ctx->daftar);
        $this->assertNull($ctx->prev_daftar);  // null for 'created' events
        $this->assertEquals(1, $ctx->status);  // cast to int
        $this->assertNull($ctx->prev_status);  // null for 'created' events
        $this->assertNull($ctx->went_pasif_at);
        $this->assertNull($ctx->pasif_days);
    }

    public function testOnAfterUpdateEntryEarlyBailsWhenNotTargetForm()
    {
        // Manager defaults to form_id=13; passing form_id=99 → bail immediately
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        // No query calls expected — the method returns before any DB work
        $mockWpdb->shouldNotReceive('query');
        $GLOBALS['wpdb'] = $mockWpdb;

        HookDispatcher::on_after_update_entry(1, ['form_id' => 99, 'item_meta' => []]);
        $this->assertTrue(true);
    }

    public function testPasifTransitionWritesToStatusLog()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        // Verify prepare is called with the INSERT pattern
        $mockWpdb->shouldReceive('prepare')
            ->with(Mockery::pattern('/INSERT.*home_promo_status_log/'), 42, 'Aktif')
            ->once()
            ->andReturn('sql');
        $mockWpdb->shouldReceive('query')->with('sql')->once()->andReturn(true);
        $GLOBALS['wpdb'] = $mockWpdb;

        HookDispatcher::write_pasif_log_if_needed(42, 'Aktif', 'Pasif');
        $this->assertTrue(true);
    }

    public function testPasifLogSkippedWhenAlreadyPasif()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldNotReceive('query'); // no insert
        $GLOBALS['wpdb'] = $mockWpdb;

        HookDispatcher::write_pasif_log_if_needed(42, 'Pasif', 'Pasif');
        $this->assertTrue(true);
    }

    public function testBuildCtxComputesPasifDaysWhenLogEntryExists()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        // build_ctx will call get_var once for the log query to get went_pasif_at
        // Since we return a date, it won't call the fallback for pasif_field
        // Then it calls get_var for TIMESTAMPDIFF
        // For 'updated' event, it also calls get_field_snapshot_or_fallback for prev_daftar, prev_status, prev_status_label
        // Since snapshot is empty, that's 3 more get_var calls
        // Total: log (returns date) → prev_daftar fallback (null) → prev_status fallback (null) → prev_status_label fallback (null) → TIMESTAMPDIFF (45)
        // But actually, the prev_* calls happen before the log query. Let me check line order again.
        // Lines 98-100 call get_field_snapshot_or_fallback first (3 calls)
        // Line 103-107 is the log query (1 call)
        // Line 109 checks if went_pasif_at is null and calls fallback if needed (0 calls in this case)
        // Line 114-117 calls TIMESTAMPDIFF (1 call)
        // So: prev_daftar, prev_status, prev_status_label, log, TIMESTAMPDIFF = 5 calls total
        $mockWpdb->shouldReceive('get_var')
            ->andReturnValues([null, null, null, '2026-03-01 00:00:00', 45]);
        $GLOBALS['wpdb'] = $mockWpdb;

        $ctx = HookDispatcher::build_ctx_for_test('updated', 10, [
            'form_id'   => 13,
            'item_meta' => [
                196  => 'Ya',
                199  => '1',
                1617 => 'Aktif',
                3170 => null,
            ],
        ]);

        $this->assertEquals('2026-03-01 00:00:00', $ctx->went_pasif_at);
        $this->assertEquals(45, $ctx->pasif_days);
    }
}
