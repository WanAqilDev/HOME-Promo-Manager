<?php
namespace HPM;

if (!defined('ABSPATH'))
    exit;

/**
 * Shortcode: [promo_countdown]
 * Server-side static view with countdown.
 */
add_shortcode('promo_countdown', function () {
    $mgr = Manager::get_instance();
    if (!$mgr->is_active())
        return '<p>Promotion has not started or has ended.</p>';
    $count = $mgr->get_count();
    $max = (int) $mgr->s('max');
    if ($count >= $max)
        return '<p>Promotion slots are fully redeemed.</p>';
    $remaining_total = $max - $count;
    $tier1 = (int) $mgr->s('tier1_max');
    $current_code = $mgr->get_current_code($count);
    $remaining_tier = ($count < $tier1) ? ($tier1 - $count) : $remaining_total;
    try {
        $tz_string = $mgr->s('timezone') ?: 'Asia/Kuala_Lumpur';
        try {
            $tz = new \DateTimeZone($tz_string);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('Asia/Kuala_Lumpur');
        }
        $end_dt = new \DateTimeImmutable($mgr->s('end'), $tz);
        $end_ts = $end_dt->setTimezone(new \DateTimeZone('UTC'))->getTimestamp() * 1000;
    } catch (\Exception $e) {
        $end_ts = 0;
    }
    ob_start(); ?>
    <div class="promo-countdown">
        <p><strong>Current Promo:</strong> <?php echo esc_html($current_code); ?></p>
        <p><strong>Slots Left (Current Promo):</strong> <?php echo intval($remaining_tier); ?></p>
        <p><strong>Total Slots Left:</strong> <?php echo intval($remaining_total); ?></p>
        <p><strong>Time Left:</strong> <span id="promo-timer"></span></p>
    </div>
    <script>
        (function () {
            const endTs = <?php echo $end_ts; ?>;
            function tick() {
                const now = Date.now();
                const d = Math.max(0, endTs - now);
                if (d === 0) { document.getElementById('promo-timer').innerText = 'Expired'; return; }
                const days = Math.floor(d / 86400000);
                const hrs = Math.floor((d % 86400000) / 3600000);
                const mins = Math.floor((d % 3600000) / 60000);
                const secs = Math.floor((d % 60000) / 1000);
                document.getElementById('promo-timer').innerText = `${days}d ${hrs}h ${mins}m ${secs}s`;
            }
            setInterval(tick, 1000); tick();
        })();
    </script>
    <?php
    return ob_get_clean();
});

/**
 * Shortcode: [promo_realtime_counter]
 * Front-end JS live widget fetching REST endpoint.
 */
add_shortcode('promo_realtime_counter', function () {
    $endpoint = esc_url(rest_url('promo/v1/counter'));
    ob_start(); ?>
    <div id="promo-realtime-widget"><em>Loading promo information…</em></div>
    <script>
        (function () {
            const endpoint = '<?php echo $endpoint; ?>';
            function render(data) {
                const el = document.getElementById('promo-realtime-widget');
                if (!el) return;
                if (!data.active) {
                    el.innerHTML = '<p>Promotion has not started or has ended.</p>';
                    return;
                }
                if (data.remaining_total <= 0) {
                    el.innerHTML = '<p>Promotion slots are fully redeemed.</p>';
                    return;
                }
                el.innerHTML = `<div>
                <p><strong>Current Promo:</strong> ${data.current_code || 'None'}</p>
                <p><strong>Slots Left (Current):</strong> ${data.remaining_tier}</p>
                <p><strong>Total Slots Left:</strong> ${data.remaining_total}</p>
                <p><strong>Time Left:</strong> <span id="promo-realtime-timer"></span></p>
            </div>`;
                if (!window._hpm_timer_started) {
                    window._hpm_timer_started = true;
                    const endMs = (data.end_time || 0) * 1000;
                    (function tick() {
                        const elTimer = document.getElementById('promo-realtime-timer');
                        if (!elTimer) return;
                        const diff = Math.max(0, endMs - Date.now());
                        if (diff === 0) { elTimer.textContent = 'Expired'; return; }
                        const days = Math.floor(diff / 86400000);
                        const hrs = Math.floor((diff % 86400000) / 3600000);
                        const mins = Math.floor((diff % 3600000) / 60000);
                        const secs = Math.floor((diff % 60000) / 1000);
                        elTimer.textContent = `${days}d ${hrs}h ${mins}m ${secs}s`;
                        setTimeout(tick, 1000);
                    })();
                }
            }
            function update() {
                fetch(endpoint).then(r => r.json()).then(render).catch(e => console.error('Promo widget error', e));
            }
            update(); setInterval(update, 10000);
        })();
    </script>
    <?php
    return ob_get_clean();
});