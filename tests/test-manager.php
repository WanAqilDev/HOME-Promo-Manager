<?php
use PHPUnit\Framework\TestCase;
use HPM\Manager;

class ManagerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset singleton if possible or mock settings
        // Since Manager is a singleton, we might need reflection to reset it or just test state changes
        $reflection = new \ReflectionClass(Manager::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    public function testIsActiveReturnsFalseWhenOutsideRange()
    {
        // Mock settings via globals since Manager reads them in constructor
        $GLOBALS['get_option'] = function ($opt, $default) {
            if ($opt === 'home_promo_manager_settings') {
                return [
                    'start' => '2020-01-01 00:00:00',
                    'end' => '2020-01-02 00:00:00',
                    'timezone' => 'UTC'
                ];
            }
            return $default;
        };

        $mgr = Manager::get_instance();
        $this->assertFalse($mgr->is_active(), 'Should be inactive for past dates');
    }

    public function testIsActiveReturnsTrueWhenInsideRange()
    {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $start = $now->modify('-1 hour')->format('Y-m-d H:i:s');
        $end = $now->modify('+2 hours')->format('Y-m-d H:i:s');

        $GLOBALS['get_option'] = function ($opt, $default) use ($start, $end) {
            if ($opt === 'home_promo_manager_settings') {
                return [
                    'start' => $start,
                    'end' => $end,
                    'timezone' => 'UTC'
                ];
            }
            return $default;
        };

        // Reset instance to pick up new settings
        $reflection = new \ReflectionClass(Manager::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);

        $mgr = Manager::get_instance();
        $this->assertTrue($mgr->is_active(), 'Should be active for current range');
    }

    public function testGetCurrentCodeTiers()
    {
        $GLOBALS['get_option'] = function ($opt, $default) {
            if ($opt === 'home_promo_manager_settings') {
                return [
                    'max' => 100,
                    'tier1_max' => 50,
                    'code_tier1' => 'TIER1',
                    'code_tier2' => 'TIER2'
                ];
            }
            return $default;
        };

        // Reset instance
        $reflection = new \ReflectionClass(Manager::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);

        $mgr = Manager::get_instance();

        $this->assertEquals('TIER1', $mgr->get_current_code(0));
        $this->assertEquals('TIER1', $mgr->get_current_code(49));
        $this->assertEquals('TIER2', $mgr->get_current_code(50));
        $this->assertEquals('TIER2', $mgr->get_current_code(99));
        $this->assertEquals('', $mgr->get_current_code(100));
    }
}
