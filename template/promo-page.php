<?php // Template Name: SMART 26 Promo (Animated & Fixed)
$bg_purple = '#510E7E';
$yellow    = '#FFD231';
$lime      = '#48BC13';

$api_url = get_rest_url(null, 'promo/v1/counter');

$server_target_ts = 0;
if (class_exists('\HPM\Manager')) {
    $mgr = \HPM\Manager::get_instance();
    try {
        $tz_string = $mgr->s('timezone') ?: 'Asia/Kuala_Lumpur';
        $tz = new \DateTimeZone($tz_string);
        $end_dt = new \DateTimeImmutable($mgr->s('end'), $tz);
        $server_target_ts = $end_dt->setTimezone(new \DateTimeZone('UTC'))->getTimestamp() * 1000;
    } catch (\Exception $e) { $server_target_ts = 0; }
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART 26 – Promo</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <script src="<?= HOME_PROMO_MANAGER_URL ?>assets/js/tailwindcss.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { brandpurple: '<?= $bg_purple ?>', brandyellow: '<?= $yellow ?>', brandlime: '<?= $lime ?>' },
                    borderRadius: { 'mega': '50px' }
                }
            }
        }
    </script>
    <style>
        :root { --p: <?= $bg_purple ?>; --y: <?= $yellow ?>; }
        
        body {
            background-color: var(--p);
            background-image: 
                radial-gradient(circle at center, #6a1ba3 0%, var(--p) 100%);
            height: 100vh;
            margin: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
            position: relative;
        }

        /* Mobile scaling */
        @media (max-width: 640px) {
            body {
                justify-content: flex-start;
                padding-top: 1rem;
            }
        }

        /* Diagonal lines overlay */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background-image: linear-gradient(135deg, rgba(255, 255, 255, 0.03) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.03) 50%, rgba(255, 255, 255, 0.03) 75%, transparent 75%, transparent);
            background-size: 30px 30px;
            pointer-events: none;
            z-index: 0;
        }

        .ribbon-banner {
            background: white;
            transform: rotate(-3deg);
            box-shadow: 10px 10px 0px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        }
        .ribbon-banner:hover {
            transform: rotate(-1deg) scale(1.02);
            box-shadow: 15px 15px 0px rgba(0,0,0,0.1);
        }

        .cta-dud {
            background: var(--y);
            clip-path: polygon(0% 0%, 100% 0%, 95% 50%, 100% 100%, 0% 100%, 5% 50%);
            padding: 12px 40px;
            animation: breathe 3s ease-in-out infinite;
            cursor: default;
        }
        
        @media (max-width: 640px) {
            .cta-dud {
                padding: 10px 30px;
            }
        }

        @keyframes breathe {
            0%, 100% { transform: scale(1); filter: brightness(1); }
            50% { transform: scale(1.05); filter: brightness(1.1); }
        }

        .flip-digit {
            background: white;
            color: var(--p);
            border-radius: 12px;
            box-shadow: 0 4px 0px #ccc;
            transition: transform 0.2s;
        }
        .flip-digit:hover { transform: translateY(-5px); }

        .bolt-pulse {
            display: inline-block;
            animation: pulseBolt 1.5s infinite;
        }
        @keyframes pulseBolt {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.3); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }

        /* Mobile-specific adjustments */
        @media (max-width: 640px) {
            .mobile-compact { margin-bottom: 0.75rem !important; }
            .mobile-compact-sm { margin-bottom: 0.5rem !important; }
        }
    </style>
</head>

<body class="flex flex-col items-center">

    <div class="absolute top-0 left-0 z-20 animate__animated animate__fadeInDown">
        <div class="bg-white py-2 px-4 sm:py-4 sm:px-6 rounded-br-mega shadow-2xl transition-transform hover:scale-105">
            <img src="https://home.edu.my/sistemklon/oalsumte/2026/01/LOGO-HOME-AI-2-01.png" 
                 alt="HOME Logo" 
                 class="w-16 sm:w-24 md:w-28 h-auto object-contain">
        </div>
    </div>

    <main class="w-full max-w-lg px-4 sm:px-6 flex flex-col items-center z-10 text-center">
        
        <h2 class="text-white font-bold tracking-[0.3em] text-[8px] sm:text-[9px] mb-1 uppercase animate__animated animate__fadeIn animate__delay-1s">
            Terapi Matematik
        </h2>
        
        <h1 class="text-4xl sm:text-5xl md:text-6xl font-black text-brandyellow italic tracking-tighter drop-shadow-2xl mb-2 leading-none animate__animated animate__zoomIn">
            PROMO <span class="not-italic text-2xl sm:text-3xl bolt-pulse">⚡</span>
        </h1>

        <div class="ribbon-banner w-[110%] -ml-[5%] px-3 py-1.5 sm:px-4 sm:py-2 mb-3 sm:mb-4 flex justify-center animate__animated animate__fadeInLeft animate__delay-1s">
            <span class="text-3xl sm:text-4xl md:text-5xl font-black text-[#9D8BB1] tracking-tighter leading-none uppercase">Smart 26</span>
        </div>

        <div class="bg-brandpurple border-2 border-white px-3 py-1 sm:px-4 sm:py-1.5 rotate-[-3deg] mb-3 sm:mb-4 shadow-lg animate__animated animate__fadeInRight animate__delay-1s">
            <span class="text-brandyellow font-bold text-[9px] sm:text-[10px] tracking-widest uppercase">Potongan Sehingga 26%</span>
        </div>

        <div class="flex gap-1.5 sm:gap-2 md:gap-3 mb-3 sm:mb-4 animate__animated animate__fadeInUp animate__delay-2s" id="flipClock"></div>

        <div class="grid grid-cols-2 gap-2 sm:gap-3 w-full opacity-0 transition-all duration-700 transform translate-y-4 max-w-sm mb-3 sm:mb-4" id="liveStats">
            <div class="bg-brandpurple/40 border-2 border-brandyellow p-2 sm:p-2.5 rounded-2xl flex flex-col justify-center items-center shadow-lg backdrop-blur-md hover:border-white transition-colors">
                <div id="priceDisplay"></div>
            </div>
            <div class="bg-white p-2 sm:p-2.5 rounded-2xl text-brandpurple shadow-lg flex flex-col justify-center transition-transform hover:scale-95">
                <div class="text-[7px] sm:text-[8px] font-bold uppercase opacity-60">Kekosongan Tier</div>
                <div id="apiSlots" class="text-3xl sm:text-4xl font-black leading-none my-1">...</div>
                <div class="text-[7px] sm:text-[8px] font-bold bg-brandpurple/10 px-2 py-0.5 rounded-full uppercase">Total: <span id="apiTotal">...</span></div>
            </div>
        </div>

        <div class="bg-brandlime text-white font-black text-sm sm:text-base px-6 sm:px-8 py-2.5 sm:py-3 rounded-full shadow-2xl mb-3 sm:mb-4 animate__animated animate__pulse animate__infinite">
            12 - 14 JANUARI 2026
        </div>

        <p class="text-white text-[11px] sm:text-xs leading-relaxed font-medium mb-3 sm:mb-4 animate__animated animate__fadeIn animate__delay-3s px-2">
            Promosi terbuka kepada <span class="text-brandyellow italic font-black text-xs sm:text-sm underline decoration-brandlime">pendaftaran baru</span>.<br>
            Jangan lepaskan peluang anda!
        </p>

        <div class="mb-2 sm:mb-3 cta-dud animate__animated animate__fadeInUp animate__delay-4s">
            <span class="text-brandpurple font-black text-base sm:text-lg md:text-xl tracking-tighter">DAFTAR SEKARANG</span>
        </div>

        <a href="https://www.home.edu.my" class="text-brandyellow/60 text-[8px] sm:text-[9px] font-bold tracking-[0.4em] hover:text-white transition-all uppercase">WWW.HOME.EDU.MY</a>

    </main>

    <!-- Footer - Compact -->
    <footer class="absolute bottom-0 w-full text-gray-400 py-2 sm:py-3 text-center z-10">
        <p class="text-[8px] sm:text-[9px] mb-0.5 sm:mb-1">
            &copy; 2026 <span class="text-white font-semibold">HOME Math Therapy</span>
        </p>
        <p class="text-[7px] sm:text-[8px]">
            Powered by <a href="https://qcxis.com" target="_blank" rel="noopener" class="text-brandyellow hover:text-white transition-colors">QCXIS Sdn Bhd</a>
        </p>
    </footer>

    <script>
        const API_ENDPOINT = "<?= $api_url ?>";
        let targetTs = <?= $server_target_ts ?>;
        const ORIGINAL_PRICE = 270.00;
        const PRICE_FORMAT = new Intl.NumberFormat('en-MY', { style: 'currency', currency: 'MYR', minimumFractionDigits: 0 });

        function pad(n) { return n < 10 ? '0' + n : n; }

        async function updateRealtimeStats() {
            try {
                const res = await fetch(API_ENDPOINT);
                const d = await res.json();
                const statsEl = document.getElementById('liveStats');
                
                if (!d.active || d.remaining_total <= 0) {
                    statsEl.style.opacity = '1';
                    statsEl.classList.remove('translate-y-4');
                    document.getElementById('priceDisplay').innerHTML = `<div class="text-white font-black text-sm uppercase">TAMAT</div>`;
                    return;
                }

                const final = ORIGINAL_PRICE * 0.74;
                document.getElementById('priceDisplay').innerHTML = `
                    <div class="text-[9px] sm:text-[10px] text-white/70 line-through font-bold">${PRICE_FORMAT.format(ORIGINAL_PRICE)}</div>
                    <div class="text-2xl sm:text-3xl font-black text-brandyellow leading-none my-1 animate__animated animate__flash">${PRICE_FORMAT.format(final)}</div>
                    <div class="text-[7px] sm:text-[8px] bg-brandlime text-white px-2 py-0.5 rounded font-black uppercase">JIMAT 26%</div>
                `;

                document.getElementById('apiSlots').innerText = d.remaining_tier;
                document.getElementById('apiTotal').innerText = d.remaining_total;
                
                if (d.end_time) targetTs = d.end_time * 1000;
                
                // Show the grid
                statsEl.style.opacity = '1';
                statsEl.classList.remove('translate-y-4');
            } catch (e) { 
                console.error('API Error:', e);
            }
        }

        function render(parts) {
            const labels = ['HARI', 'JAM', 'MIN', 'SAAT'];
            const isMobile = window.innerWidth < 640;
            const digitClass = isMobile ? 'w-11 h-14 text-xl' : 'w-12 h-16 text-2xl';
            const labelClass = isMobile ? 'text-[7px] mt-1' : 'text-[7px] sm:text-[8px] mt-1.5';
            
            document.getElementById('flipClock').innerHTML = parts.map((v, i) => `
                <div class="flex flex-col items-center">
                    <div class="flip-digit ${digitClass} flex items-center justify-center font-black">${pad(v)}</div>
                    <div class="${labelClass} text-white uppercase font-black tracking-widest opacity-80">${labels[i]}</div>
                </div>
            `).join('');
        }

        function tick() {
            let diff = Math.max(targetTs - Date.now(), 0);
            render([
                Math.floor(diff / 86400000), 
                Math.floor(diff % 86400000 / 3600000), 
                Math.floor(diff % 3600000 / 60000), 
                Math.floor(diff % 60000 / 1000)
            ]);
        }

        // Initialize
        setInterval(tick, 1000);
        setInterval(updateRealtimeStats, 3000);
        tick(); 
        setTimeout(updateRealtimeStats, 500);
    </script>
</body>
</html>