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

/**
 * Helper to render the popup HTML/CSS
 */
function hpm_render_popup($amount_text)
{
    $id = uniqid('promo_popup_');
    ob_start();
    ?>
    <style>
        .modal-overlay-<?php echo $id; ?> {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(5px);
        }

        .modal-box-<?php echo $id; ?> {
            background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%);
            border: 4px solid #ff9800;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            text-align: center;
            max-width: 600px;
            width: 90%;
            position: relative;
            animation: popIn-<?php echo $id; ?> 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }

        .modal-title-<?php echo $id; ?> {
            color: #e65100;
            font-family: 'Arial Black', sans-serif;
            font-size: 2.5rem;
            margin: 0 0 15px 0;
            text-transform: uppercase;
            line-height: 1.1;
        }

        .modal-message-<?php echo $id; ?> {
            color: #333;
            font-family: 'Segoe UI', sans-serif;
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 25px;
        }

        .highlight-amount-<?php echo $id; ?> {
            display: block;
            font-size: 3.5rem;
            color: #d84315;
            font-weight: 900;
            margin-top: 10px;
            text-shadow: 2px 2px 0px rgba(255, 255, 255, 0.5);
        }

        .close-btn-<?php echo $id; ?> {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 30px;
            font-weight: bold;
            color: #ff9800;
            cursor: pointer;
            background: none;
            border: none;
            transition: color 0.2s;
        }

        .close-btn-<?php echo $id; ?>:hover {
            color: #d84315;
        }

        @keyframes popIn-<?php echo $id; ?> {
            0% {
                opacity: 0;
                transform: scale(0.5);
            }

            100% {
                opacity: 1;
                transform: scale(1);
            }
        }
    </style>
    <div class="modal-overlay-<?php echo $id; ?>" id="modal-<?php echo $id; ?>">
        <div class="modal-box-<?php echo $id; ?>">
            <button class="close-btn-<?php echo $id; ?>"
                onclick="document.getElementById('modal-<?php echo $id; ?>').style.display='none'">×</button>
            <div style="font-size: 60px; margin-bottom: 10px;">🎉</div>
            <h1 class="modal-title-<?php echo $id; ?>">Tahniah!</h1>
            <div class="modal-message-<?php echo $id; ?>">
                KLIEN ANDA LAYAK MENDAPAT DISKAUN SEBANYAK
                <span class="highlight-amount-<?php echo $id; ?>"><?php echo esc_html($amount_text); ?></span>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('promo_popup_24', function () {
    return hpm_render_popup('RM48!!!!');
});

add_shortcode('promo_popup_12', function () {
    return hpm_render_popup('RM24!!!!');
});