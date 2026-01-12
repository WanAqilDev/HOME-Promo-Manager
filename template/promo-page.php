<?php 
// --- CONFIGURATION & API SETUP ---
$bg_purple = '#510E7E';
$yellow    = '#FFD231';
$lime      = '#48BC13';

// Fetching the REST API URL for the counter
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&family=Montserrat:wght@900&display=swap" rel="stylesheet">
	<title>HOME PROMO - SMART26</title>
	<link rel="icon" type="image/png" href="https://home.edu.my/sistemklon/oalsumte/2026/01/LOGO-HOME-AI-2-01.png">
	<link rel="apple-touch-icon" href="https://home.edu.my/sistemklon/oalsumte/2026/01/LOGO-HOME-AI-2-01.png">
    <style>
        :root {
            --purple-main: #510E7E;
            --yellow-promo: #FFD231;
            --yellow-fold: #C7A31B;
            --green-pill: #30B11E;
            --dark-bg: #2E0840;
        }

        body, html { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; background-color: var(--dark-bg); font-family: 'Inter', sans-serif; }
        
        /* Allow scrolling on mobile */
        @media (max-width: 1023px) {
            body, html { 
                overflow-y: auto; 
                overflow-x: hidden; 
                padding-top: env(safe-area-inset-top, 0px);
                height: auto;
                min-height: 100vh;
            }
            
            /* Make canvas adaptive to content on mobile */
            #canvas {
                min-height: 100vh !important;
                height: auto !important;
            }
        }

        /* --- Continuous Drift Animations --- */
        @keyframes sweep-tr {
            0%, 100% { transform: translate(0, 0) rotate(-15deg); }
            50% { transform: translate(-5vw, 5vh) rotate(-10deg); }
        }
        @keyframes sweep-bl {
            0%, 100% { transform: translate(0, 0) rotate(-15deg); }
            50% { transform: translate(5vw, -5vh) rotate(-20deg); }
        }
        @keyframes particle-float {
            0% { transform: translateY(0); opacity: 0; }
            50% { opacity: 0.5; }
            100% { transform: translateY(-100vh); opacity: 0; }
        }
        @keyframes flicker {
            0%, 100% { opacity: 1; filter: drop-shadow(0 0 10px var(--yellow-promo)); }
            50% { opacity: 0.8; filter: drop-shadow(0 0 20px var(--yellow-promo)); }
        }

        .animate-sweep-tr { animation: sweep-tr 18s ease-in-out infinite; }
        .animate-sweep-bl { animation: sweep-bl 22s ease-in-out infinite; }
        .animate-flicker { animation: flicker 0.15s ease-in-out infinite; }
        
        /* Particle Style */
        .particle { position: absolute; background-color: white; border-radius: 50%; opacity: 0; animation: particle-float linear infinite; pointer-events: none; }

        /* --- UI & Parallax Layering --- */
        .text-shadow-hard { text-shadow: 6px 6px 0px #3A0B5E; }
        .clip-bolt { clip-path: polygon(45% 0%, 100% 0%, 70% 45%, 100% 45%, 0% 100%, 30% 55%, 0% 55%); }
        .parallax-wrap { transition: transform 0.15s ease-out; will-change: transform; }

        /* --- Folded Ribbon CTA --- */
        .ribbon-wrapper { position: relative; width: 340px; filter: drop-shadow(0 8px 12px rgba(0,0,0,0.4)); margin-top: 1rem; }
        .ribbon-main { position: relative; background-color: var(--yellow-promo); color: black; font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: 22px; text-align: center; padding: 12px 0; z-index: 20; border-radius: 2px; }
        .ribbon-main::before, .ribbon-main::after { content: ''; position: absolute; bottom: -12px; border-style: solid; z-index: 5; }
        .ribbon-main::before { left: 0; border-width: 0 12px 12px 0; border-color: transparent var(--yellow-fold) transparent transparent; }
        .ribbon-main::after { right: 0; border-width: 0 0 12px 12px; border-color: transparent transparent transparent var(--yellow-fold); }
        .ribbon-wing { position: absolute; top: 10px; height: 100%; width: 60px; background-color: var(--yellow-promo); z-index: 10; clip-path: polygon(0 0, 100% 0, 80% 50%, 100% 100%, 0 100%); }
        .wing-left { left: -40px; transform: scaleX(-1); }
        .wing-right { right: -40px; }

        /* Countdown Digit */
        .flip-digit { background: white; color: var(--purple-main); border-radius: 6px; box-shadow: 0 3px 0px #bbb; width: 45px; height: 55px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.4rem; }
        
        /* --- CHROME & FIREFOX MOBILE ONLY FIXES --- */
        body.chrome-mobile .ribbon-wrapper { width: 240px; }
        body.chrome-mobile .ribbon-main { font-size: 16px; padding: 8px 0; }
        body.chrome-mobile .chrome-mobile-logo { margin-top: 2rem; }
    </style>
</head>
<body class="flex items-center justify-center">
    <script>
        // Detect Chrome mobile or Firefox mobile browser and add class
        (function() {
            const isChrome = /Chrome/.test(navigator.userAgent) && /Google Inc/.test(navigator.vendor);
            const isFirefox = /Firefox/.test(navigator.userAgent);
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            
            if ((isChrome || isFirefox) && isMobile) {
                document.body.classList.add('chrome-mobile');
            }
        })();
    </script>

    <div id="canvas" class="relative w-full min-h-screen lg:h-screen bg-[radial-gradient(circle_at_center_40%,_#6A2C91_0%,_#2E0840_100%)] overflow-hidden flex flex-col items-center justify-center lg:overflow-hidden">
        
        <div id="particle-container" class="absolute inset-0 z-10"></div>

        <div id="parallax-tr" class="absolute top-[-5%] right-[-5%] z-0 parallax-wrap">
            <div class="animate-sweep-tr">
                <div class="w-[18vw] h-[30vh] bg-[#FFD231] rounded-[60px] opacity-80 blur-[1px]"></div>
                <div class="mt-4 ml-8 w-32 h-24 bg-[repeating-linear-gradient(45deg,transparent,transparent_10px,#ffffff44_10px,#ffffff44_12px)]"></div>
            </div>
        </div>

        <div id="parallax-bl" class="absolute bottom-[-5%] left-[-5%] z-0 parallax-wrap">
            <div class="animate-sweep-bl">
                <div class="w-[22vw] h-[35vh] bg-[#FFD231] rounded-[80px] opacity-80 blur-[1px]"></div>
                <div class="mb-8 ml-16 w-40 h-32 bg-[repeating-linear-gradient(45deg,transparent,transparent_10px,#ffffff44_10px,#ffffff44_12px)]"></div>
            </div>
        </div>

        <div class="relative z-40 flex flex-col items-center text-center px-4 max-w-2xl py-4 pb-8 pt-8 md:pt-8">
            
            <img src="https://home.edu.my/sistemmaklumat/oalsumte/2026/01/logo-stroke-puteh.png" class="w-32 md:w-40 h-auto mb-4 drop-shadow-xl animate-pulse chrome-mobile-logo" alt="HOME Logo">

            <p class="text-white text-xs font-black tracking-[0.4em] uppercase opacity-80 mb-1">Terapi Matematik</p>
            
            <div class="relative mb-4">
                <h1 class="text-[#FFD231] text-[9vw] lg:text-[110px] font-black leading-none text-shadow-hard tracking-tighter">PROMO</h1>
                <div class="absolute -top-2 -right-12 lg:-right-16 w-12 lg:w-16 h-16 lg:h-24 bg-[#FFD231] clip-bolt rotate-[12deg] animate-flicker"></div>
            </div>

            <div class="relative w-[60vw] max-w-[500px] h-[10vh] max-h-[90px] rotate-[-4deg] mb-8">
                <div class="w-full h-full bg-white shadow-xl flex items-center justify-center border-b-[6px] border-gray-100">
                    <span class="text-[#510E7E] font-['Montserrat'] text-[7vw] lg:text-[75px] font-black tracking-tighter">SMART 26</span>
                </div>
                <div class="absolute -bottom-6 right-2 bg-[#4C0A87] border-2 border-white px-3 py-1 shadow-lg">
                    <span class="text-[#FFD231] font-black text-[10px] uppercase">Potongan Sehingga 26%</span>
                </div>
            </div>

            <div class="bg-[#30B11E] px-6 py-2 rounded-full shadow-lg mb-6 transform hover:scale-105 transition-transform">
                <span class="text-white font-black text-lg md:text-xl">12-14 JANUARI 2026</span>
            </div>

            <div class="text-white font-bold text-xs md:text-sm leading-relaxed mb-4">
                <p>Promosi terbuka kepada <span class="text-[#FFD231] italic underline decoration-2 underline-offset-4">pendaftaran baru.</span></p>
                <p>Jangan lepaskan peluang anda!</p>
            </div>

            <div id="flipClock" class="flex gap-2 mb-6"></div>

            <div id="liveStats" class="grid grid-cols-2 gap-3 w-full max-w-xs mb-6 opacity-0 transition-opacity duration-700">
                <div class="bg-white/10 border border-[#FFD231] p-2 rounded-xl flex flex-col items-center justify-center backdrop-blur-sm">
                    <div id="priceDisplay"></div>
                </div>
                <div class="bg-white p-2 rounded-xl flex flex-col items-center justify-center shadow-md">
                    <span class="text-[8px] font-bold text-[#510E7E] uppercase opacity-50">Slots Left</span>
                    <div id="apiCodeName" class="text-[9px] font-black text-[#FFD231] leading-none mt-1 opacity-0 transition-opacity duration-500">...</div>
                    <div id="apiSlots" class="text-2xl font-black text-[#510E7E] leading-none my-1 opacity-0 transition-opacity duration-500">...</div>
                    <span class="text-[8px] font-bold bg-[#510E7E]/10 px-2 py-0.5 rounded-full text-[#510E7E]">Total: <span id="apiTotal">...</span></span>
                </div>
            </div>

            <div class="ribbon-wrapper group cursor-pointer hover:scale-105 transition-transform active:scale-95 mb-8">
                <div class="ribbon-wing wing-left"></div>
                <div class="ribbon-wing wing-right"></div>
                <div class="ribbon-main">DAFTAR SEKARANG</div>
            </div>

            <a href="https://www.home.edu.my" class="block mb-8 text-[#FFD231] font-black text-xs tracking-widest uppercase opacity-40 hover:opacity-100 transition-opacity">www.home.edu.my</a>
            
            <footer class="w-full text-center mt-4 pb-2">
                <p class="text-[9px] text-gray-500 uppercase tracking-tighter">
                    &copy; 2026 <span class="text-white">HOME Maths Therapy</span> • Powered by <span class="text-[#FFD231]"><a href="https://www.qc.com.my">QCXIS Sdn Bhd</a></span>
                </p>
            </footer>
        </div>
    </div>

    <script>
        // --- API & Countdown Logic ---
        const API_ENDPOINT = "<?= $api_url ?>";
        let targetTs = <?= $server_target_ts ?>;
        const ORIGINAL_PRICE = 200.00;
        const PRICE_FORMAT = new Intl.NumberFormat('en-MY', { style: 'currency', currency: 'MYR', minimumFractionDigits: 0 });

        let activeCodes = [];
        let currentCodeIndex = 0;

        async function updateStats() {
            try {
                const res = await fetch(API_ENDPOINT);
                const d = await res.json();
                const statsEl = document.getElementById('liveStats');
                
                if (!d.active || d.remaining_total <= 0) {
                    document.getElementById('priceDisplay').innerHTML = `<span class="text-white text-[10px] font-black uppercase">SOLD OUT</span>`;
                    return;
                }
                
                const final = ORIGINAL_PRICE * 0.74;
                document.getElementById('priceDisplay').innerHTML = `
                    <div class="text-[8px] text-white/50 line-through font-bold">${PRICE_FORMAT.format(ORIGINAL_PRICE)}</div>
                    <div class="text-xl font-black text-[#FFD231]">${PRICE_FORMAT.format(final)}</div>
                `;

                // Extract active codes data
                if (d.codes && Array.isArray(d.codes)) {
                    activeCodes = d.codes
                        .filter(codeData => codeData.remaining > 0)
                        .map(codeData => ({ code: codeData.code, remaining: codeData.remaining }));
                }

                document.getElementById('apiTotal').innerText = d.remaining_total;
                
                if (d.end_time) targetTs = d.end_time * 1000;
                
                // Show the grid and start code rotation
                statsEl.style.opacity = '1';
                if (activeCodes.length > 0 && !window.codeRotationStarted) {
                    window.codeRotationStarted = true;
                    rotateCode();
                    setInterval(rotateCode, 3000);
                }
            } catch (e) { 
                console.error(e);
            }
        }

        function rotateCode() {
            if (activeCodes.length === 0) return;
            
            const codeNameEl = document.getElementById('apiCodeName');
            const slotsEl = document.getElementById('apiSlots');
            
            // Fade out
            codeNameEl.style.opacity = '0';
            slotsEl.style.opacity = '0';
            
            setTimeout(() => {
                // Update content
                const currentCode = activeCodes[currentCodeIndex];
                codeNameEl.innerText = currentCode.code;
                slotsEl.innerText = currentCode.remaining;
                
                // Fade in
                codeNameEl.style.opacity = '1';
                slotsEl.style.opacity = '1';
                
                // Move to next code
                currentCodeIndex = (currentCodeIndex + 1) % activeCodes.length;
            }, 500);
        }

        function tick() {
            let diff = Math.max(targetTs - Date.now(), 0);
            const parts = [Math.floor(diff / 86400000), Math.floor(diff % 86400000 / 3600000), Math.floor(diff % 3600000 / 60000), Math.floor(diff % 60000 / 1000)];
            const labels = ['HARI', 'JAM', 'MINIT', 'SAAT'];
            document.getElementById('flipClock').innerHTML = parts.map((v, i) => `
                <div class="flex flex-col items-center">
                    <div class="flip-digit">${v < 10 ? '0'+v : v}</div>
                    <span class="text-white/60 text-[8px] font-bold mt-1 tracking-wider">${labels[i]}</span>
                </div>
            `).join('');
        }

        setInterval(tick, 1000); tick();
        setInterval(updateStats, 4000); updateStats();

        // --- FIXED PARALLAX & PARTICLES ---
        const driftTR = document.getElementById('parallax-tr');
        const driftBL = document.getElementById('parallax-bl');
        const particleContainer = document.getElementById('particle-container');

        // Mouse Parallax Fix
        window.addEventListener('mousemove', (e) => {
            const x = (e.clientX / window.innerWidth - 0.5) * 2;
            const y = (e.clientY / window.innerHeight - 0.5) * 2;
            driftTR.style.transform = `translate(${x * 30}px, ${y * 30}px)`;
            driftBL.style.transform = `translate(${x * -40}px, ${y * -40}px)`;
        });

        // Background Particles
        for (let i = 0; i < 40; i++) {
            const p = document.createElement('div');
            p.className = 'particle';
            const size = Math.random() * 3 + 2 + 'px';
            p.style.width = size; p.style.height = size;
            p.style.left = Math.random() * 100 + '%';
            p.style.top = Math.random() * 100 + '%';
            p.style.animationDuration = (Math.random() * 8 + 8) + 's';
            p.style.animationDelay = (Math.random() * -15) + 's';
            particleContainer.appendChild(p);
        }
    </script>
</body>
</html>