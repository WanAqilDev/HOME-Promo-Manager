<?php
// Mock WordPress environment
date_default_timezone_set('Asia/Kuala_Lumpur');

class ManagerMock
{
    public function s($key)
    {
        $settings = [
            'start' => '2025-12-12 00:00:00',
            'end' => '2025-12-24 23:59:59',
            'timezone' => 'Asia/Kuala_Lumpur'
        ];
        return $settings[$key] ?? null;
    }

    public function is_active()
    {
        $tz_string = $this->s('timezone') ?: 'Asia/Kuala_Lumpur';
        try {
            $tz = new \DateTimeZone($tz_string);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('Asia/Kuala_Lumpur');
        }

        try {
            $start = new \DateTimeImmutable($this->s('start'), $tz);
            $end = new \DateTimeImmutable($this->s('end'), $tz);
        } catch (\Exception $e) {
            return false;
        }

        // Mock "now" as Dec 10, 2025
        $now = new \DateTimeImmutable('2025-12-10 16:30:00', $tz);

        echo "Debug Info:\n";
        echo "Timezone: " . $tz->getName() . "\n";
        echo "Now: " . $now->format('Y-m-d H:i:s') . "\n";
        echo "Start: " . $start->format('Y-m-d H:i:s') . "\n";
        echo "End: " . $end->format('Y-m-d H:i:s') . "\n";

        return ($now >= $start && $now < $end);
    }
}

$mgr = new ManagerMock();
$is_active = $mgr->is_active();
echo "Is Active? " . ($is_active ? "YES" : "NO") . "\n";
