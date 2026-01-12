<?php
namespace HPM;

if (!defined('ABSPATH'))
    exit;

/**
 * Shortcode: [promo_countdown]
 * Server-side static view with countdown for SMART26.
 */
add_shortcode('promo_countdown', function () {
    $mgr = Manager::get_instance();
    if (!$mgr->is_active())
        return '<p>Promosi belum bermula atau telah tamat.</p>';
    
    // Get SMART26 multi-code stats
    $mode = $mgr->s('code_assignment_mode') ?: 'manual';
    $promo_codes = $mgr->s('promo_codes') ?: [];
    $code_stats_array = DB::get_code_stats();
    
    // Calculate totals
    $total_used = 0;
    $total_max = 0;
    $active_codes = [];
    
    foreach ($promo_codes as $code => $config) {
        if (!($config['active'] ?? true)) continue;
        
        $max = (int) ($config['max'] ?? 0);
        $used = 0;
        foreach ($code_stats_array as $stat) {
            if ($stat['promo_code'] === $code) {
                $used = (int) $stat['count'];
                break;
            }
        }
        
        $remaining = max(0, $max - $used);
        $total_used += $used;
        $total_max += $max;
        
        if ($remaining > 0) {
            $active_codes[] = [
                'code' => $code,
                'remaining' => $remaining,
                'description' => $config['description'] ?? ''
            ];
        }
    }
    
    $remaining_total = max(0, $total_max - $total_used);
    
    if ($remaining_total <= 0)
        return '<p>Semua slot promosi telah ditebus.</p>';
    
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
    <div class="promo-countdown-smart26" style="font-family: 'Inter', sans-serif; padding: 20px; background: linear-gradient(135deg, #6A2C91 0%, #2E0840 100%); border-radius: 15px; color: white;">
        <h3 style="color: #FFD231; font-size: 28px; font-weight: 900; margin: 0 0 15px 0; text-align: center;">PROMO SMART26</h3>
        <p style="text-align: center; margin: 0 0 20px 0; font-size: 14px; opacity: 0.9;">Potongan Sehingga 26% • 12-14 Januari 2026</p>
        
        <?php if (!empty($active_codes)): ?>
        <div style="margin-bottom: 20px;">
            <p style="font-weight: bold; margin: 0 0 10px 0; font-size: 12px; opacity: 0.8; text-transform: uppercase;">Kod Aktif:</p>
            <?php foreach ($active_codes as $ac): ?>
            <div style="background: rgba(255,255,255,0.1); padding: 10px; margin-bottom: 8px; border-radius: 8px; border-left: 3px solid #FFD231;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: bold; color: #FFD231;"><?php echo esc_html($ac['code']); ?></span>
                    <span style="background: white; color: #510E7E; padding: 4px 12px; border-radius: 20px; font-weight: bold; font-size: 13px;"><?php echo intval($ac['remaining']); ?> slot</span>
                </div>
                <?php if (!empty($ac['description'])): ?>
                <div style="font-size: 11px; margin-top: 4px; opacity: 0.8;"><?php echo esc_html($ac['description']); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 10px; text-align: center; margin-bottom: 15px;">
            <p style="margin: 0 0 5px 0; font-size: 11px; opacity: 0.8; text-transform: uppercase;">Jumlah Slot Berbaki</p>
            <p style="font-size: 32px; font-weight: 900; margin: 0; color: #FFD231;"><?php echo intval($remaining_total); ?></p>
        </div>
        
        <div style="text-align: center;">
            <p style="margin: 0 0 10px 0; font-size: 11px; opacity: 0.8; text-transform: uppercase;">Masa Berbaki</p>
            <div id="promo-timer-smart26" style="font-size: 24px; font-weight: bold; color: #FFD231;">--:--:--</div>
        </div>
    </div>
    <script>
        (function () {
            const endTs = <?php echo $end_ts; ?>;
            function tick() {
                const now = Date.now();
                const d = Math.max(0, endTs - now);
                const el = document.getElementById('promo-timer-smart26');
                if (!el) return;
                if (d === 0) { el.innerText = 'Tamat'; return; }
                const days = Math.floor(d / 86400000);
                const hrs = Math.floor((d % 86400000) / 3600000);
                const mins = Math.floor((d % 3600000) / 60000);
                const secs = Math.floor((d % 60000) / 1000);
                el.innerText = `${days} Hari ${hrs} Jam ${mins} Min ${secs} Saat`;
            }
            setInterval(tick, 1000); tick();
        })();
    </script>
    <?php
    return ob_get_clean();
});

/**
 * Shortcode: [promo_realtime_counter]
 * Front-end JS live widget fetching REST endpoint for SMART26.
 */
add_shortcode('promo_realtime_counter', function () {
    $endpoint = esc_url(rest_url('promo/v1/counter'));
    ob_start(); ?>
    <div id="promo-realtime-widget-smart26" style="font-family: 'Inter', sans-serif;"><em>Memuatkan maklumat promosi…</em></div>
    <script>
        (function () {
            const endpoint = '<?php echo $endpoint; ?>';
            let timerInterval = null;
            
            function render(data) {
                const el = document.getElementById('promo-realtime-widget-smart26');
                if (!el) return;
                
                if (!data.active) {
                    el.innerHTML = '<p style="padding: 20px; text-align: center;">Promosi belum bermula atau telah tamat.</p>';
                    return;
                }
                
                if (data.remaining_total <= 0) {
                    el.innerHTML = '<p style="padding: 20px; text-align: center;">Semua slot promosi telah ditebus.</p>';
                    return;
                }
                
                // Build active codes list
                let codesHtml = '';
                if (data.codes && Array.isArray(data.codes)) {
                    const activeCodes = data.codes.filter(c => c.remaining > 0);
                    if (activeCodes.length > 0) {
                        codesHtml = '<div style="margin-bottom: 20px;"><p style="font-weight: bold; margin: 0 0 10px 0; font-size: 12px; opacity: 0.8; text-transform: uppercase;">Kod Aktif:</p>';
                        activeCodes.forEach(ac => {
                            codesHtml += `
                                <div style="background: rgba(255,255,255,0.1); padding: 10px; margin-bottom: 8px; border-radius: 8px; border-left: 3px solid #FFD231;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="font-weight: bold; color: #FFD231;">${ac.code}</span>
                                        <span style="background: white; color: #510E7E; padding: 4px 12px; border-radius: 20px; font-weight: bold; font-size: 13px;">${ac.remaining} slot</span>
                                    </div>
                                    ${ac.description ? `<div style="font-size: 11px; margin-top: 4px; opacity: 0.8;">${ac.description}</div>` : ''}
                                </div>
                            `;
                        });
                        codesHtml += '</div>';
                    }
                }
                
                el.innerHTML = `
                    <div style="padding: 20px; background: linear-gradient(135deg, #6A2C91 0%, #2E0840 100%); border-radius: 15px; color: white;">
                        <h3 style="color: #FFD231; font-size: 28px; font-weight: 900; margin: 0 0 15px 0; text-align: center;">PROMO SMART26</h3>
                        <p style="text-align: center; margin: 0 0 20px 0; font-size: 14px; opacity: 0.9;">Potongan Sehingga 26% • 12-14 Januari 2026</p>
                        
                        ${codesHtml}
                        
                        <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 10px; text-align: center; margin-bottom: 15px;">
                            <p style="margin: 0 0 5px 0; font-size: 11px; opacity: 0.8; text-transform: uppercase;">Jumlah Slot Berbaki</p>
                            <p style="font-size: 32px; font-weight: 900; margin: 0; color: #FFD231;">${data.remaining_total}</p>
                        </div>
                        
                        <div style="text-align: center;">
                            <p style="margin: 0 0 10px 0; font-size: 11px; opacity: 0.8; text-transform: uppercase;">Masa Berbaki</p>
                            <div id="promo-realtime-timer-smart26" style="font-size: 24px; font-weight: bold; color: #FFD231;">--:--:--</div>
                        </div>
                    </div>
                `;
                
                // Setup timer
                if (timerInterval) clearInterval(timerInterval);
                const endMs = (data.end_time || 0) * 1000;
                
                function tick() {
                    const elTimer = document.getElementById('promo-realtime-timer-smart26');
                    if (!elTimer) return;
                    const diff = Math.max(0, endMs - Date.now());
                    if (diff === 0) { 
                        elTimer.textContent = 'Tamat'; 
                        if (timerInterval) clearInterval(timerInterval);
                        return; 
                    }
                    const days = Math.floor(diff / 86400000);
                    const hrs = Math.floor((diff % 86400000) / 3600000);
                    const mins = Math.floor((diff % 3600000) / 60000);
                    const secs = Math.floor((diff % 60000) / 1000);
                    elTimer.textContent = `${days} Hari ${hrs} Jam ${mins} Min ${secs} Saat`;
                }
                
                tick();
                timerInterval = setInterval(tick, 1000);
            }
            
            function update() {
                fetch(endpoint)
                    .then(r => r.json())
                    .then(render)
                    .catch(e => console.error('Promo widget error', e));
            }
            
            update(); 
            setInterval(update, 10000); // Update every 10 seconds
        })();
    </script>
    <?php
    return ob_get_clean();
});

/**
 * Helper to render the popup HTML/CSS for SMART26
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
            background-color: rgba(0, 0, 0, 0.85);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            backdrop-filter: blur(10px);
            animation: fadeIn-<?php echo $id; ?> 0.3s ease-out forwards;
        }

        .modal-box-<?php echo $id; ?> {
            background: linear-gradient(135deg, #6A2C91 0%, #2E0840 100%);
            border: 5px solid #FFD231;
            padding: 50px 40px;
            border-radius: 25px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.8), 0 0 40px rgba(255, 210, 49, 0.3);
            text-align: center;
            max-width: 650px;
            width: 90%;
            position: relative;
            animation: popIn-<?php echo $id; ?> 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
            font-family: 'Inter', 'Segoe UI', sans-serif;
        }

        .modal-stars-<?php echo $id; ?> {
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 80px;
            filter: drop-shadow(0 5px 15px rgba(255, 210, 49, 0.6));
            animation: bounce-<?php echo $id; ?> 1s infinite;
        }

        .modal-title-<?php echo $id; ?> {
            color: #FFD231;
            font-family: 'Montserrat', 'Arial Black', sans-serif;
            font-size: 3rem;
            font-weight: 900;
            margin: 20px 0 15px 0;
            text-transform: uppercase;
            line-height: 1;
            text-shadow: 3px 3px 0px rgba(0, 0, 0, 0.3);
            letter-spacing: -1px;
        }

        .modal-subtitle-<?php echo $id; ?> {
            color: white;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 25px;
            opacity: 0.95;
            line-height: 1.4;
        }

        .modal-badge-<?php echo $id; ?> {
            display: inline-block;
            background: white;
            color: #510E7E;
            padding: 20px 35px;
            border-radius: 15px;
            margin: 20px 0;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
            transform: rotate(-2deg);
        }

        .highlight-amount-<?php echo $id; ?> {
            display: block;
            font-size: 4rem;
            color: #510E7E;
            font-weight: 900;
            line-height: 1;
            text-shadow: none;
        }

        .modal-promo-label-<?php echo $id; ?> {
            color: white;
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: 20px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .close-btn-<?php echo $id; ?> {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 35px;
            font-weight: bold;
            color: #FFD231;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid #FFD231;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            transition: all 0.3s;
            line-height: 38px;
            padding: 0;
        }

        .close-btn-<?php echo $id; ?>:hover {
            background: #FFD231;
            color: #510E7E;
            transform: rotate(90deg);
        }

        @keyframes fadeIn-<?php echo $id; ?> {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes popIn-<?php echo $id; ?> {
            0% {
                opacity: 0;
                transform: scale(0.5) rotate(-5deg);
            }
            70% {
                transform: scale(1.05) rotate(2deg);
            }
            100% {
                opacity: 1;
                transform: scale(1) rotate(0deg);
            }
        }

        @keyframes bounce-<?php echo $id; ?> {
            0%, 100% { transform: translateX(-50%) translateY(0); }
            50% { transform: translateX(-50%) translateY(-10px); }
        }

        @media (max-width: 640px) {
            .modal-box-<?php echo $id; ?> {
                padding: 40px 25px;
            }
            .modal-title-<?php echo $id; ?> {
                font-size: 2.2rem;
            }
            .highlight-amount-<?php echo $id; ?> {
                font-size: 3rem;
            }
        }
    </style>
    <div class="modal-overlay-<?php echo $id; ?>" id="modal-<?php echo $id; ?>">
        <div class="modal-box-<?php echo $id; ?>">
            <div class="modal-stars-<?php echo $id; ?>">🎉</div>
            <button class="close-btn-<?php echo $id; ?>"
                onclick="document.getElementById('modal-<?php echo $id; ?>').style.display='none'">×</button>
            
            <h1 class="modal-title-<?php echo $id; ?>">Tahniah!</h1>
            <div class="modal-subtitle-<?php echo $id; ?>">
                KLIEN ANDA LAYAK MENDAPAT<br>DISKAUN SEBANYAK
            </div>
            
            <div class="modal-badge-<?php echo $id; ?>">
                <span class="highlight-amount-<?php echo $id; ?>"><?php echo esc_html($amount_text); ?></span>
            </div>
            
            <div class="modal-promo-label-<?php echo $id; ?>">
                ✨ PROMO SMART26 ✨
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('promo_popup_26', function () {
    return hpm_render_popup('26%');
});

add_shortcode('promo_popup_smart26', function () {
    return hpm_render_popup('SEHINGGA 26%');
});