<?php // Template Name: Promo Countdown 12.12 (Live API)

// --- CONFIGURATION ---
$bg_blue = '#5acdf8';
$green = '#62be4d';
$pink = '#ff1a8c';

$api_url = get_rest_url(null, 'promo/v1/counter');
?>
< !DOCTYPE html>
    <html <?php language_attributes();
    ?>>

    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>
            <?php the_title();

            ?>– 12.12 Promo
        </title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link
            href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Fredoka:wght@600;700&family=Modak&display=swap"
            rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
        <style>
            body {
                background:
                    <?= $bg_blue ?>
                ;
                min-height: 100vh;
                font-family: 'Inter', sans-serif;
                margin: 0;
                display: flex;
                flex-direction: column;
                overflow-x: hidden;
            }

            /* --- CLOUDS CSS --- */
            #background-wrap {
                bottom: 0;
                left: 0;
                padding-top: 50px;
                position: fixed;
                right: 0;
                top: 0;
                z-index: -1;
            }

            @-webkit-keyframes animateCloud {
                0% {
                    margin-left: -1000px;
                }

                100% {
                    margin-left: 100%;
                }
            }

            @-moz-keyframes animateCloud {
                0% {
                    margin-left: -1000px;
                }

                100% {
                    margin-left: 100%;
                }
            }

            @keyframes animateCloud {
                0% {
                    margin-left: -1000px;
                }

                100% {
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
                background: linear-gradient(top, #fff 5%, #f1f1f1 100%);
                filter: progid:DXImageTransform.Microsoft.gradient(startColorstr='#fff', endColorstr='#f1f1f1', GradientType=0);
                border-radius: 100px;
                box-shadow: 0 8px 5px rgba(0, 0, 0, 0.1);
                height: 120px;
                position: relative;
                width: 350px;
            }

            .cloud:after,
            .cloud:before {
                background: #fff;
                content: '';
                position: absolute;
                z-index: -1;
            }

            .cloud:after {
                border-radius: 100px;
                height: 100px;
                left: 50px;
                top: -50px;
                width: 100px;
            }

            .cloud:before {
                border-radius: 200px;
                width: 180px;
                height: 180px;
                right: 50px;
                top: -90px;
            }

            /* --- TYPOGRAPHY & LAYOUT (Unchanged) --- */
            .promo-container {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-align: center;
                width: 100%;
                max-width: 800px;
                margin: 0 auto;
                gap: 0.5rem;
            }

            .title-promosi {
                font-family: 'Fredoka', sans-serif;
                font-weight: 700;
                color:
                    <?= $green ?>
                ;
                font-size: clamp(3rem, 8vw, 5rem);
                line-height: 1;
                -webkit-text-stroke: 8px white;
                paint-order: stroke fill;
                filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
            }

            .title-1212 {
                font-family: 'Modak', cursive;
                color:
                    <?= $pink ?>
                ;
                font-size: clamp(8rem, 25vw, 13rem);
                line-height: 0.85;
                -webkit-text-stroke: 12px white;
                paint-order: stroke fill;
                filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.15));
                margin: -10px 0;
                z-index: 2;
            }

            .title-ozem {
                font-family: 'Fredoka', sans-serif;
                font-weight: 700;
                color:
                    <?= $pink ?>
                ;
                font-size: clamp(2.5rem, 7vw, 4.5rem);
                line-height: 1;
                -webkit-text-stroke: 8px white;
                paint-order: stroke fill;
                margin-bottom: 1rem;
            }

            .pill-discount {
                background: #fff;
                color:
                    <?= $green ?>
                ;
                font-family: 'Inter', sans-serif;
                font-weight: 800;
                font-size: clamp(1.2rem, 3vw, 2.2rem);
                padding: 0.5em 1.5em;
                border-radius: 999px;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                margin-bottom: 0.5rem;
                white-space: nowrap;
            }

            .desc-text {
                font-family: 'Inter', sans-serif;
                color: #1b232c;
                font-size: clamp(0.9rem, 2vw, 1.2rem);
                line-height: 1.4;
                max-width: 90%;
                margin-bottom: 1.5rem;
                font-weight: 500;
            }

            .pill-date {
                background: #fff;
                color:
                    <?= $pink ?>
                ;
                font-family: 'Inter', sans-serif;
                font-weight: 700;
                font-size: clamp(1rem, 3vw, 1.8rem);
                padding: 0.6em 1.5em;
                border-radius: 12px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
                margin-bottom: 1rem;
            }

            .text-tnc {
                font-size: 0.8rem;
                color: #333;
                opacity: 0.7;
                font-weight: 500;
            }

            /* --- Clock Styles (Unchanged) --- */
            .flip-clock {
                display: flex;
                justify-content: center;
                align-items: flex-end;
                gap: 1.5rem;
                margin-bottom: 1rem;
            }

            .flip-unit {
                position: relative;
                width: 94px;
                height: 120px;
                text-align: center;
            }

            @media (max-width: 640px) {
                .flip-unit {
                    width: 60px;
                    height: 80px;
                }
            }

            .flip-digit {
                background: #fff;
                color:
                    <?= $bg_blue ?>
                ;
                font-weight: 800;
                font-size: 4.2rem;
                border-radius: 16px;
                box-shadow: 0 4px 0 rgba(0, 0, 0, 0.1);
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100%;
                width: 100%;
            }

            @media (max-width: 640px) {
                .flip-digit {
                    font-size: 2.5rem;
                    border-radius: 10px;
                }
            }

            .flip-anim {
                animation: popIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            }

            @keyframes popIn {
                0% {
                    transform: scale(0.85);
                    opacity: 0.8;
                }

                50% {
                    transform: scale(1.05);
                }

                100% {
                    transform: scale(1);
                    opacity: 1;
                }
            }

            .labels-container {
                display: flex;
                justify-content: center;
                gap: 1.5rem;
                margin-bottom: 1.5rem;
            }

            .flip-label {
                color: #fff;
                font-size: 0.9rem;
                text-transform: uppercase;
                letter-spacing: 1px;
                font-weight: 600;
                text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
                width: 94px;
                text-align: center;
            }

            @media (max-width: 640px) {
                .flip-label {
                    width: 60px;
                    font-size: 0.7rem;
                }
                .flip-clock {
                    gap: 0.5rem;
                }
                .labels-container {
                    gap: 0.5rem;
                }
            }

            /* --- LIVE STATS STYLES (Unchanged) --- */
            .live-stats-wrapper {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 15px;
                width: 100%;
                max-width: 600px;
                margin-bottom: 1.5rem;
                opacity: 0;
                transition: opacity 0.5s ease;
            }

            .stat-card {
                background: rgba(255, 255, 255, 0.9);
                border-radius: 12px;
                padding: 10px 20px;
                display: flex;
                flex-direction: column;
                align-items: center;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
                min-width: 140px;
            }

            .stat-card.is-code {
                border: 3px dashed
                    <?= $pink ?>
                ;
                background: #fff0f7;
                padding: 15px 20px;
                transform: rotate(-3deg);
                transition: transform 0.2s, box-shadow 0.2s;
                position: relative;
                overflow: hidden;
            }

            .stat-card.is-code:hover {
                transform: rotate(0deg) scale(1.03);
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
            }

            .original-price {
                font-size: 1.1rem;
                color: #777;
                text-decoration: line-through;
                margin-bottom: 4px;
                font-weight: 500;
            }

            .discount-info {
                font-size: 1.1rem;
                color: white;
                background: #e11d48;
                padding: 4px 10px;
                border-radius: 4px;
                margin-bottom: 15px;
                font-weight: 700;
                letter-spacing: 0.5px;
                text-transform: uppercase;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            }

            .final-price {
                font-size: 2.8rem;
                font-family: 'Fredoka', sans-serif;
                color:
                    <?= $green ?>
                ;
                font-weight: 700;
                line-height: 1;

                position: relative;
                z-index: 10;
                background: white;
                padding: 5px 12px;
                border-radius: 8px;
                box-shadow: 0 5px 10px rgba(0, 0, 0, 0.2);
                margin-top: -15px;
                margin-bottom: -5px;
            }

            .stat-card.is-slots {
                border: 3px solid
                    <?= $green ?>
                ;
                background: #f0fff4;
            }

            .stat-label {
                font-size: 0.75rem;
                text-transform: uppercase;
                font-weight: 700;
                color: #555;
                margin-bottom: 2px;
            }

            .stat-value-slots {
                font-family: 'Fredoka', sans-serif;
                font-size: 5rem;
                color:
                    <?= $green ?>
                ;
                font-weight: 700;
            }

            .pulse-text {
                animation: pulseRed 2s infinite;
            }

            @keyframes pulseRed {
                0% {
                    transform: scale(1);
                }

                50% {
                    transform: scale(1.1);
                    color: #e11d48;
                }

                100% {
                    transform: scale(1);
                }
            }

            .footer-strip {
                background: #fff;
                width: 100%;
                padding: 1.5rem 1rem;
                text-align: center;
                margin-top: auto;
                position: relative;
                z-index: 10;
            }

            .footer-strip-txt {
                font-size: 0.9rem;
                color: #64748b;
                font-weight: 500;
            }

            .confetti-bg {
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                z-index: 100;
                pointer-events: none;
            }
        </style>
    </head>

    <body>
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
        <main class="flex-grow flex flex-col justify-center items-center relative z-10 w-full px-4 pt-10 pb-10">
            <div class="promo-container">
                <div class="title-promosi">PROMOSI</div>
                <div class="title-1212">12.12</div>
                <div class="title-ozem">OZEM DEALS</div>
                <div class="pill-discount">POTONGAN DISKAUN SEHINGGA 24%</div>
                <p class="desc-text">Promosi terhad kepada <em style="color:#F80588;"><b>480 pendaftaran terawal
                            sahaja</b></em>.<br>Jangan lepaskan peluang anda ! </p>
                <div id="flipClock" class="flip-clock flex-wrap justify-center sm:flex-nowrap"></div>
                <div class="labels-container"><span class="flip-label">Days</span><span
                        class="flip-label">Hours</span><span class="flip-label">Minutes</span><span
                        class="flip-label">Seconds</span></div>
                <div id="liveStats" class="live-stats-wrapper">
                    <div class="stat-card is-code">
                        <div id="priceDisplay"></div>
                    </div>
                    <div class="stat-card is-slots"><span class="stat-label">Kekosongan (Tier Ini)</span><span
                            id="apiSlots" class="stat-value-slots">...</span></div>
                    <div class="w-full text-center mt-1"><span
                            class="text-xs font-semibold text-gray-700 bg-white/80 px-2 py-1 rounded">Baki Keseluruhan:
                            <span id="apiTotal">...</span></span></div>
                </div>
                <div class="pill-date">12 - 24 Disember 2025</div>
                <div class="text-tnc">*Terma & Syarat*</div>
            </div>
        </main>
        <div class="footer-strip"><span class="footer-strip-txt">Home Maths Therapy &copy;
                <?= date('Y') ?>&nbsp;
                &bull;
                &nbsp;
                Powered by QCXIS Sdn Bhd</span></div>
        <!-- PROMO SUCCESS MODAL -->
        <div id="promoSuccessModal" class="fixed inset-0 z-[999] hidden flex items-center justify-center px-4"
            role="dialog" aria-modal="true">
            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm transition-opacity duration-300 opacity-0"
                id="modalBackdrop"></div>

            <!-- Modal Content -->
            <div class="relative bg-gray-900/80 border border-white/10 rounded-3xl p-8 max-w-md w-full text-center shadow-2xl transform scale-95 opacity-0 transition-all duration-300 overflow-hidden"
                id="modalContent" style="backdrop-filter: blur(20px);">

                <!-- Glow Effect -->
                <div
                    class="absolute top-0 left-1/2 -translate-x-1/2 w-full h-full bg-gradient-to-b from-pink-500/20 to-transparent pointer-events-none">
                </div>

                <!-- Icon -->
                <div class="relative mb-6">
                    <div
                        class="w-24 h-24 mx-auto bg-gradient-to-tr from-green-400 to-emerald-600 rounded-full flex items-center justify-center shadow-lg shadow-green-500/30">
                        <svg class="w-12 h-12 text-white drop-shadow-md" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24" stroke-width="3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                </div>

                <!-- Title -->
                <h2 class="relative text-4xl font-bold text-white mb-3 font-['Fredoka'] tracking-wide drop-shadow-lg">
                    TAHNIAH!
                </h2>

                <!-- Message -->
                <p class="relative text-white/90 mb-8 font-medium text-lg leading-relaxed">
                    Pendaftaran anda berjaya.<br>Anda layak menerima promosi ini!
                </p>

                <!-- Code Box -->
                <div class="relative bg-white/10 rounded-2xl p-5 mb-8 border border-white/20 shadow-inner">
                    <p class="text-xs text-pink-300 uppercase tracking-[0.2em] font-bold mb-2">Kod Promo Anda</p>
                    <div class="text-4xl font-black text-white tracking-widest font-['Fredoka'] drop-shadow-md"
                        id="modalPromoCode">
                        ...
                    </div>
                </div>

                <!-- Button -->
                <button onclick="closePromoModal()"
                    class="relative w-full bg-gradient-to-r from-pink-500 to-rose-600 hover:from-pink-400 hover:to-rose-500 text-white font-bold py-4 px-6 rounded-xl shadow-lg shadow-pink-500/40 transition-all transform hover:scale-[1.02] active:scale-[0.98] text-lg tracking-wide">
                    OK, TERIMA KASIH!
                </button>
            </div>
        </div>
        <div class="confetti-bg" id="confettiBg"></div>
        <script> // --- CONFIG ---
            const API_ENDPOINT = "<?= $api_url ?>";
            let targetTs = 0;

            // --- PRICE CONSTANTS & UTILS ---
            const ORIGINAL_PRICE = 200.00;

            const PRICE_FORMAT = new Intl.NumberFormat('en-MY', {
                style: 'currency', currency: 'MYR', minimumFractionDigits: 2
            });

            function pad(val) {
                return val < 10 ? '0' + val : '' + val;
            }

            function calculatePriceDisplay(promoCode) {
                let discountRate = 0;

                if (promoCode && (promoCode.includes('24') || promoCode.includes('25'))) {
                    discountRate = 0.24;
                } else if (promoCode && (promoCode.includes('12') || promoCode.includes('10'))) {
                    discountRate = 0.12;
                } else {
                    discountRate = 0;
                }

                const discountValue = ORIGINAL_PRICE * discountRate;
                const finalPrice = ORIGINAL_PRICE - discountValue;

                const originalFormatted = PRICE_FORMAT.format(ORIGINAL_PRICE);
                const discountFormatted = PRICE_FORMAT.format(discountValue);
                const finalFormatted = PRICE_FORMAT.format(finalPrice);
                const percentage = Math.round(discountRate * 100);

                return {
                    originalFormatted,
                    discountFormatted,
                    finalFormatted,
                    percentage,
                    promoCode
                }

                    ;
            }

            // --- API FETCHER ---
            async function updateRealtimeStats() {
                try {
                    const response = await fetch(API_ENDPOINT);
                    const data = await response.json();

                    if (!data.active || data.remaining_total <= 0) {
                        document.getElementById('liveStats').style.opacity = '1';

                        document.getElementById('priceDisplay').innerHTML = ` <div class="discount-info" style="background:#555;">PROMOSI TAMAT / SOLD OUT</div><div class="final-price" style="color:#d3d3d3; box-shadow:none;">$ {
                PRICE_FORMAT.format(ORIGINAL_PRICE)
            }

            </div>`;
                        document.getElementById('apiSlots').innerText = "0";
                        return;
                    }

                    // --- 1. PRICE DISPLAY LOGIC ---
                    const priceData = calculatePriceDisplay(data.current_code);

                    let priceHtml = `
            <div class="original-price">${priceData.originalFormatted}</div>
            <div class="discount-info">JIMAT BESAR ${priceData.percentage}% ! (${priceData.discountFormatted})</div>
            <div class="final-price">${priceData.finalFormatted}</div>
            <div class="stat-label mt-3">Harga Semasa (Kod: ${priceData.promoCode})</div>
        `;
                    document.getElementById('priceDisplay').innerHTML = priceHtml;
                    // --- END PRICE DISPLAY LOGIC ---

                    // 2. Update Slots
                    const slotEl = document.getElementById('apiSlots');
                    slotEl.innerText = data.remaining_tier;
                    document.getElementById('apiTotal').innerText = data.remaining_total;

                    if (data.remaining_tier < 10) {
                        slotEl.classList.add('pulse-text');
                    }

                    else {
                        slotEl.classList.remove('pulse-text');
                    }

                    // 3. Sync Countdown Timer with Server Time
                    if (data.end_time) {
                        targetTs = data.end_time * 1000;
                    }

                    document.getElementById('liveStats').style.opacity = '1';

                }

                catch (error) {
                    console.error("Promo API Error:", error);
                }
            }

            // --- CLOCK RENDERER ---
            function getCountdownParts() {
                if (targetTs === 0) return [0,
                    0,
                    0,
                    0];
                let now = Date.now();
                let diff = targetTs - now > 0 ? targetTs - now : 0;
                let days = Math.floor(diff / (1e3 * 60 * 60 * 24));
                let hours = Math.floor(diff / (1e3 * 60 * 60) % 24);
                let minutes = Math.floor(diff / (1e3 * 60) % 60);
                let seconds = Math.floor(diff / 1000 % 60);
                return [days,
                    hours,
                    minutes,
                    seconds];
            }

            let lastParts = [-1,
            -1,
            -1,
            -1];

            function renderClock(parts, animate = true) {
                let html = '';

                for (let i = 0; i < 4; i++) {
                    let flip = animate && lastParts[i] != -1 && parts[i] !== lastParts[i];

                    html += `<div class="flip-unit"><div class="flip-digit${flip ? ' flip-anim' : ''}" id="flip${i}">${pad(parts[i])}</div></div>`;
                }

                document.getElementById('flipClock').innerHTML = html;
                lastParts = [...parts];
            }

            function tickCountdown() {
                let parts = getCountdownParts();
                renderClock(parts, true);

                if (targetTs > 0 && parts.reduce((a, b) => a + b, 0) === 0) {
                    showCelebration();
                    clearInterval(clockInterval);
                }
            }

            // --- INIT ---
            let clockInterval = setInterval(tickCountdown, 980);
            renderClock([0, 0, 0, 0], false);
            updateRealtimeStats();
            setInterval(updateRealtimeStats, 3000); // Polling interval changed to 3 seconds


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