<?php // Template Name: Promo Countdown 12.12 (Mobile Optimized)
// --- CONFIGURATION ---
$bg_blue = '#5acdf8';
$green = '#62be4d';
$pink = '#ff1a8c';

$api_url = get_rest_url(null, 'promo/v1/counter');

// Server-side time calculation for immediate clock rendering
$server_target_ts = 0;
if (class_exists('\HPM\Manager')) {
    $mgr = \HPM\Manager::get_instance();
    try {
        $tz_string = $mgr->s('timezone') ?: 'Asia/Kuala_Lumpur';
        try {
            $tz = new \DateTimeZone($tz_string);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('Asia/Kuala_Lumpur');
        }
        $end_dt = new \DateTimeImmutable($mgr->s('end'), $tz);
        $server_target_ts = $end_dt->setTimezone(new \DateTimeZone('UTC'))->getTimestamp() * 1000;
    } catch (\Exception $e) {
        $server_target_ts = 0;
    }
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php the_title(); ?> – 12.12 Promo</title>
    <link rel="icon" href="data:,">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Fredoka:wght@600;700&family=Modak&display=swap"
        rel="stylesheet">
    <!-- Note: Tailwind is vendored locally for reliability. -->
    <script src="<?= HOME_PROMO_MANAGER_URL ?>assets/js/tailwindcss.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brandblue: '<?= $bg_blue ?>',
                        brandgreen: '<?= $green ?>',
                        brandpink: '<?= $pink ?>',
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        display: ['Fredoka', 'sans-serif'],
                        mono: ['Modak', 'cursive'],
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        body {
            background:
                <?= $bg_blue ?>
            ;
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            margin: 0;
            overflow-x: hidden;
            /* Critical Fallback CSS */
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        main {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        /* Clouds (kept exactly the same) */
        #background-wrap {
            position: fixed;
            inset: 0;
            z-index: -1;
            padding-top: 50px;
            pointer-events: none;
        }

        @keyframes animateCloud {
            from {
                margin-left: -1000px;
            }

            to {
                margin-left: 100%;
            }
        }

        .x1 {
            animation: animateCloud 35s linear infinite;
            transform: scale(0.65);
        }

        .x2 {
            animation: animateCloud 20s linear infinite;
            transform: scale(0.3);
        }

        .x3 {
            animation: animateCloud 30s linear infinite;
            transform: scale(0.5);
        }

        .x4 {
            animation: animateCloud 18s linear infinite;
            transform: scale(0.4);
        }

        .x5 {
            animation: animateCloud 25s linear infinite;
            transform: scale(0.55);
        }

        .cloud {
            background: #fff;
            border-radius: 100px;
            box-shadow: 0 8px 5px rgba(0, 0, 0, 0.1);
            height: 120px;
            width: 350px;
            position: relative;
        }

        .cloud:after,
        .cloud:before {
            content: '';
            background: #fff;
            position: absolute;
        }

        .cloud:after {
            border-radius: 100px;
            width: 100px;
            height: 100px;
            top: -50px;
            left: 50px;
        }

        .cloud:before {
            border-radius: 200px;
            width: 180px;
            height: 180px;
            top: -90px;
            right: 50px;
        }

        /* Big stacked title */
        .big-title {
            font-family: 'Fredoka', sans-serif;
            font-weight: 700;
            line-height: 0.92;
            text-align: center;
            position: relative;
        }

        /* PROMOSI – Green with thick white stroke */
        .big-title .promosi {
            font-size: clamp(2.8rem, 10vw, 6rem);
            color: <?= $green ?>;
            /* Hybrid approach for cross-browser consistency */
            text-shadow: 
                -5px -5px 0 white, 5px -5px 0 white, -5px 5px 0 white, 5px 5px 0 white,
                -5px 0 0 white, 5px 0 0 white, 0 -5px 0 white, 0 5px 0 white;
            paint-order: stroke fill;
        }

        /* 12.12 – Pink with super thick white stroke */
        .big-title .number {
            font-family: 'Modak', cursive;
            font-size: clamp(5.5rem, 22vw, 15rem);
            color: <?= $pink ?>;
            text-shadow: 
                -9px -9px 0 white, 9px -9px 0 white, -9px 9px 0 white, 9px 9px 0 white,
                -9px 0 0 white, 9px 0 0 white, 0 -9px 0 white, 0 9px 0 white;
            -webkit-text-stroke: 6px white;
            margin: -20px 0 -10px;
        }

        /* OZEM DEALS – Pink */
        .big-title .ozem {
            font-size: clamp(2.8rem, 9vw, 5.5rem);
            color: <?= $pink ?>;
            text-shadow: 
                -5px -5px 0 white, 5px -5px 0 white, -5px 5px 0 white, 5px 5px 0 white,
                -5px 0 0 white, 5px 0 0 white, 0 -5px 0 white, 0 5px 0 white;
        }

        /* Clock – fixed & responsive */
        .flip-clock {
            display: flex;
            justify-content: center;
            gap: 0.75rem;
            margin: 1.5rem 0;
        }

        .flip-unit {
            width: 68px;
            height: 88px;
            display: flex;
            flex-direction: column;
            align-items: center;

            @media (min-width: 480px) {
                width: 84px;
                height: 110px;
            }
        }

        .flip-digit {
            background: #fff;
            color:
                <?= $bg_blue ?>
            ;
            font-weight: 800;
            font-size: 3.2rem;
            border-radius: 16px;
            box-shadow: 0 6px 0 rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            width: 100%;
        }

        @media (max-width: 480px) {
            .flip-digit {
                font-size: 2.4rem;
                border-radius: 12px;
            }
        }

        .flip-label {
            color: white;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
            margin-top: 0.25rem;
        }

        /* Live stats – side by side on bigger screens */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            width: 100%;
            max-width: 600px;
            margin: 1.5rem auto;
        }

        @media (min-width: 540px) {
            .stats-grid {
                gap: 1.5rem;
            }
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 0.75rem;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100%;
        }

        .stat-card.code {
            border: 4px dashed
                <?= $pink ?>
            ;
            background: #fff5fb;
            transform: rotate(-2deg);
        }

        .stat-card.code:hover {
            transform: rotate(0) scale(1.03);
        }

        .final-price {
            font-family: 'Fredoka', sans-serif;
            font-size: clamp(2rem, 5vw, 2.8rem);
            font-weight: 700;
            color:
                <?= $green ?>
            ;
            background: white;
            padding: 8px 16px;
            border-radius: 12px;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.2);
            margin: 0.5rem 0;
        }

        .stat-value-slots {
            font-family: 'Fredoka', sans-serif;
            font-size: clamp(3rem, 8vw, 4.5rem);
            color:
                <?= $green ?>
            ;
            font-weight: 700;
            line-height: 1;
        }

        .pill-discount {
            background: white;
            color:
                <?= $green ?>
            ;
            font-weight: 800;
            font-size: clamp(1.4rem, 4vw, 2rem);
            padding: 0.75rem 2rem;
            border-radius: 999px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .footer-strip {
            background: white;
            padding: 1.5rem;
            text-align: center;
            font-size: 0.9rem;
            color: #64748b;
            font-weight: 500;
            margin-top: auto;
            width: 100%;
        }
    </style>
</head>

<body class="flex flex-col min-h-screen">

    <!-- Clouds Background -->
    <div id="background-wrap">
        <div class="x1">
            <div class="cloud"></div>
        </div>
        <div class="x2">
            <div class="cloud"></div>
        </div>
        <div class="x3">
            <div class="cloud"></div>
        </div>
        <div class="x4">
            <div class="cloud"></div>
        </div>
        <div class="x5">
            <div class="cloud"></div>
        </div>
    </div>

    <main class="flex-grow flex flex-col items-center justify-center px-4 py-8 relative z-10">
        <div class="text-center max-w-4xl w-full">

            <!-- BIG TITLE -->
            <div class="big-title mb-4">
                <div class="promosi">PROMOSI</div>
                <div class="number">12.12</div>
                <div class="ozem">OZEM DEALS</div>
            </div>

            <div class="pill-discount">POTONGAN DISKAUN SEHINGGA 24%</div>

            <p class="text-white font-semibold text-lg mt-4 max-w-lg mx-auto leading-relaxed drop-shadow-md">
                Promosi terhad kepada <span class="text-pink-300">480 pendaftaran terawal sahaja</span>.<br>
                Jangan lepaskan peluang anda!
            </p>

            <!-- COUNTDOWN CLOCK -->
            <!-- COUNTDOWN CLOCK -->
            <div class="flip-clock" id="flipClock"></div>

            <!-- LIVE STATS (side-by-side on ≥540px) -->
            <div class="stats-grid" id="liveStats" style="opacity:0;">
                <div class="stat-card code">
                    <div id="priceDisplay" class="text-center"></div>
                </div>
                <div class="stat-card text-center">
                    <div class="text-sm font-bold text-gray-600 uppercase">Kekosongan (Tier Ini)</div>
                    <div id="apiSlots" class="stat-value-slots">...</div>
                    <div class="text-xs text-gray-600 mt-2">Baki Keseluruhan: <span id="apiTotal">...</span></div>
                </div>
            </div>

            <div class="bg-white text-pink-600 font-bold py-3 px-8 rounded-xl shadow-lg text-lg mt-6 inline-block">
                12 - 24 Disember 2025
            </div>

            <p class="text-white/80 text-sm mt-4">*Terma & Syarat*</p>
        </div>
    </main>

    <footer class="footer-strip">
        Home Maths Therapy © <?= date('Y') ?> • Powered by QCXIS Sdn Bhd
    </footer>

    <!-- Same modal & confetti as before (unchanged) -->
    <!-- ... (copy-paste your existing modal + confetti div) ... -->

    <script>
        const API_ENDPOINT = "<?= $api_url ?>";
        let targetTs = <?= $server_target_ts ?>;
        const ORIGINAL_PRICE = 200.00;
        const PRICE_FORMAT = new Intl.NumberFormat('en-MY', { style: 'currency', currency: 'MYR', minimumFractionDigits: 2 });

        function pad(n) { return n < 10 ? '0' + n : n; }

        function calculatePriceDisplay(code) {
            let rate = 0;
            if (code && (code.includes('24') || code.includes('25'))) rate = 0.24;
            else if (code && (code.includes('12') || code.includes('10'))) rate = 0.12;

            const discount = ORIGINAL_PRICE * rate;
            const final = ORIGINAL_PRICE - discount;
            return {
                percent: Math.round(rate * 100),
                original: PRICE_FORMAT.format(ORIGINAL_PRICE),
                discount: PRICE_FORMAT.format(discount),
                final: PRICE_FORMAT.format(final),
                code: code || '-'
            };
        }

        async function updateRealtimeStats() {
            try {
                const res = await fetch(API_ENDPOINT);
                const d = await res.json();

                if (!d.active || d.remaining_total <= 0) {
                    document.getElementById('liveStats').style.opacity = '1';
                    document.getElementById('priceDisplay').innerHTML = `<div class="text-xl font-bold text-gray-500">PROMOSI TAMAT / SOLD OUT</div><div class="final-price text-3xl">${PRICE_FORMAT.format(ORIGINAL_PRICE)}</div>`;
                    document.getElementById('apiSlots').innerText = "0";
                    return;
                }

                const p = calculatePriceDisplay(d.current_code);
                document.getElementById('priceDisplay').innerHTML = `
                    <div class="text-sm text-gray-600 line-through">${p.original}</div>
                    <div class="bg-rose-600 text-white px-3 py-1 rounded text-sm font-bold my-2">JIMAT ${p.percent}% !</div>
                    <div class="final-price">${p.final}</div>
                    <div class="text-xs mt-2 text-gray-600">Kod: ${p.code}</div>
                `;

                document.getElementById('apiSlots').innerText = d.remaining_tier;
                document.getElementById('apiTotal').innerText = d.remaining_total;

                if (d.remaining_tier < 10) document.getElementById('apiSlots').classList.add('animate-pulse');
                else document.getElementById('apiSlots').classList.remove('animate-pulse');

                if (d.end_time) targetTs = d.end_time * 1000;
                document.getElementById('liveStats').style.opacity = '1';
            } catch (e) { console.error(e); }
        }

        // Clock
        let last = [-1, -1, -1, -1];
        const labels = ['Hari', 'Jam', 'Minit', 'Saat'];
        function render(parts) {
            let html = '';
            parts.forEach((v, i) => {
                const anim = last[i] !== -1 && last[i] !== v;
                html += `<div class="flip-unit">
                    <div class="flip-digit${anim ? ' animate__animated animate__flipInY' : ''}">${pad(v)}</div>
                    <div class="flip-label">${labels[i]}</div>
                </div>`;
            });
            document.getElementById('flipClock').innerHTML = html;
            last = [...parts];
        }

        function tick() {
            if (!targetTs) return;
            let diff = Math.max(targetTs - Date.now(), 0);
            let d = Math.floor(diff / 86400000);
            let h = Math.floor(diff % 86400000 / 3600000);
            let m = Math.floor(diff % 3600000 / 60000);
            let s = Math.floor(diff % 60000 / 1000);
            render([d, h, m, s]);
        }

        // Init
        setInterval(tick, 1000);
        tick();
        updateRealtimeStats();
        setInterval(updateRealtimeStats, 3000);

        // --- ACTIONS ---
        function showCelebration() {
            document.getElementById('confettiBg').style.display = 'block';

            setTimeout(() => {
                document.getElementById('confettiBg').style.display = 'none';
            }

                , 3000);

            confetti({
                particleCount: 180, spread: 100, origin: {
                    y: 0.83
                }

                , colors: ['#5acdf8', '#ff1a8c', '#fff', '#62be4d']
            });
        }

        // --- PROMO POPUP LOGIC ---
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
        }

        function checkPromoEligibility() {
            const promoCode = getCookie('hpm_promo_eligible');
            if (promoCode) {
                const modal = document.getElementById('promoSuccessModal');
                const backdrop = document.getElementById('modalBackdrop');
                const content = document.getElementById('modalContent');
                const codeEl = document.getElementById('modalPromoCode');

                if (modal && codeEl) {
                    // Set code
                    codeEl.innerText = promoCode;

                    // Show modal
                    modal.classList.remove('hidden');

                    // Animate in
                    requestAnimationFrame(() => {
                        backdrop.classList.remove('opacity-0');
                        content.classList.remove('scale-95', 'opacity-0');
                        content.classList.add('scale-100', 'opacity-100');
                    });

                    // Trigger confetti
                    showCelebration();

                    // Clear cookie
                    document.cookie = "hpm_promo_eligible=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                }
            }
        }

        function closePromoModal() {
            const modal = document.getElementById('promoSuccessModal');
            const backdrop = document.getElementById('modalBackdrop');
            const content = document.getElementById('modalContent');

            backdrop.classList.add('opacity-0');
            content.classList.remove('scale-100', 'opacity-100');
            content.classList.add('scale-95', 'opacity-0');

            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        // Check on load
        window.addEventListener('load', checkPromoEligibility);

        // Check periodically (fallback)
        setInterval(checkPromoEligibility, 2000);

    </script>
</body>

</html>