<?php
/**
 * Template Name: HPM Promo Page
 */

$campaign      = HPM\CampaignEngine::get_active();
$mgr           = HPM\Manager::get_instance();
$base          = number_format((float) ($mgr->s('base_price')     ?? 200), 0);
$final         = number_format((float) ($mgr->s('final_price')    ?? 167), 0);
$discount      = number_format((float) ($campaign ? $campaign->discount_amount : 33), 0);
$end_date_js   = $campaign ? esc_js($campaign->end_date) : '';
$cam_name      = $campaign ? esc_html($campaign->name) : '';
$counter_url   = esc_js(esc_url(rest_url('promo/v1/counter')));
$assets        = plugin_dir_url(dirname(__FILE__)) . 'assets/poster/';
$end_display   = $campaign ? wp_date('j M Y', strtotime($campaign->end_date)) : '';
$start_display = $campaign ? wp_date('j M Y', strtotime($campaign->start_date ?? $campaign->end_date)) : '';

add_filter('body_class', function ($classes) {
    $classes[] = 'hpm-promo-body';
    return $classes;
});

add_filter('pre_get_document_title', function () use ($campaign) {
    $site = get_bloginfo('name');
    if ($campaign) {
        return esc_html($campaign->name) . ' – ' . $site;
    }
    return 'Promo – ' . $site;
});

add_action('wp_head', function () {
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --hpm-pink:      #f02d7d;
      --hpm-pink-dark: #b5115a;
      --hpm-lime:      #5cbf2a;
      --hpm-lime-dark: #3d9a18;
      --hpm-orange:    #f5821f;
      --hpm-navy:      #0d2b6e;
    }

    html body.hpm-promo-body {
      font-family: 'Nunito', sans-serif;
      background: linear-gradient(180deg, #c2e8f8 0%, #d9f0fb 55%, #b0ddf0 100%) fixed;
      margin: 0; padding: 0;
    }

    /* Hide theme fixed header on promo page — standalone landing page */
    body.hpm-promo-body #header-fixed,
    body.hpm-promo-body #footer-fixed {
      display: none !important;
    }

    /* ─── BACKGROUND DECO ───────────────────── */
    .hpm-bg-deco { position: fixed; inset: 0; pointer-events: none; z-index: 0; overflow: hidden; }

    .hpm-cloud {
      position: absolute;
      background: rgba(255,255,255,.72); border-radius: 50px; filter: blur(1px);
    }
    .hpm-cloud::before, .hpm-cloud::after {
      content: ''; position: absolute;
      background: rgba(255,255,255,.72); border-radius: 50%;
    }
    .hpm-c1 { width: 140px; height: 42px; top: 5%;  left: -14px; }
    .hpm-c1::before { width: 68px; height: 68px; top: -32px; left: 20px; }
    .hpm-c1::after  { width: 50px; height: 50px; top: -22px; left: 60px; }
    .hpm-c2 { width: 100px; height: 30px; top: 10%; right: 4%; }
    .hpm-c2::before { width: 50px; height: 50px; top: -24px; left: 14px; }
    .hpm-c2::after  { width: 36px; height: 36px; top: -15px; left: 46px; }
    .hpm-c3 { width: 160px; height: 48px; top: 38%; left: -22px; }
    .hpm-c3::before { width: 80px; height: 80px; top: -38px; left: 22px; }
    .hpm-c3::after  { width: 58px; height: 58px; top: -26px; left: 72px; }
    .hpm-c4 { width: 90px; height: 26px; top: 62%; right: -10px; }
    .hpm-c4::before { width: 46px; height: 46px; top: -22px; left: 12px; }
    .hpm-c4::after  { width: 32px; height: 32px; top: -14px; left: 40px; }
    .hpm-c5 { width: 120px; height: 36px; bottom: 18%; left: 8%; }
    .hpm-c5::before { width: 60px; height: 60px; top: -28px; left: 18px; }
    .hpm-c5::after  { width: 44px; height: 44px; top: -18px; left: 56px; }

    .hpm-math {
      position: absolute; font-family: 'Fredoka One', cursive;
      color: rgba(13,43,110,.1); pointer-events: none; user-select: none;
    }
    .hpm-m1 { font-size: 1.8em; top: 7%;  left: 5%;    --r: -12deg; transform: rotate(-12deg); animation: hpmFloatY 6s ease-in-out infinite; }
    .hpm-m2 { font-size: 1.2em; top: 16%; right: 7%;   --r: 8deg;   transform: rotate(8deg);   animation: hpmFloatY 7s ease-in-out infinite 1s; }
    .hpm-m3 { font-size: 2em;   top: 40%; left: 2%;    --r: -6deg;  transform: rotate(-6deg);  animation: hpmFloatY 8s ease-in-out infinite .5s; }
    .hpm-m4 { font-size: 1.3em; top: 55%; right: 4%;   --r: 14deg;  transform: rotate(14deg);  animation: hpmFloatY 5s ease-in-out infinite 2s; }
    .hpm-m5 { font-size: 1.6em; top: 72%; left: 7%;    --r: -10deg; transform: rotate(-10deg); animation: hpmFloatY 7s ease-in-out infinite 1.5s; }
    .hpm-m6 { font-size: 1.1em; bottom:10%; right: 9%; --r: 5deg;   transform: rotate(5deg);   animation: hpmFloatY 6s ease-in-out infinite .8s; }
    @keyframes hpmFloatY {
      0%, 100% { transform: translateY(0) rotate(var(--r, -6deg)); }
      50%       { transform: translateY(-10px) rotate(var(--r, -6deg)); }
    }

    /* ─── CORNER DECORATIONS ────────────────── */
    .hpm-corner-wrap { position: fixed; inset: 0; pointer-events: none; z-index: 8; overflow: hidden; }
    .hpm-corner-green   { position: absolute; bottom: -100px; right: -80px; width: 340px; height: 340px; background: radial-gradient(circle at 40% 40%, var(--hpm-lime), var(--hpm-lime-dark)); border-radius: 50%; box-shadow: inset -8px -8px 20px rgba(0,0,0,.1); }
    .hpm-corner-green-2 { position: absolute; bottom: -40px;  right: 120px; width: 180px; height: 180px; background: var(--hpm-lime); border-radius: 50%; opacity: .75; }
    .hpm-corner-blue    { position: absolute; top: -60px; right: -60px; width: 200px; height: 200px; background: radial-gradient(circle, #90d8f4, #5bbde0); border-radius: 50%; opacity: .55; }
    .hpm-corner-pink    { position: absolute; top: -70px; left: -70px; width: 220px; height: 220px; background: radial-gradient(circle at 55% 55%, var(--hpm-pink), var(--hpm-pink-dark)); border-radius: 50%; opacity: .5; }
    .hpm-corner-pink-2  { position: absolute; top: -20px; left: 100px; width: 110px; height: 110px; background: var(--hpm-pink); border-radius: 50%; opacity: .3; }
    .hpm-corner-orange  { position: absolute; bottom: -110px; left: -80px; width: 260px; height: 260px; background: radial-gradient(circle at 45% 45%, #f9a94a, var(--hpm-orange)); border-radius: 50%; opacity: .6; }
    .hpm-corner-orange-2{ position: absolute; bottom: -50px; left: 130px; width: 130px; height: 130px; background: var(--hpm-orange); border-radius: 50%; opacity: .35; }

    /* ─── PAGE GRID ─────────────────────────── */
    .hpm-page {
      position: relative; z-index: 1; min-height: 100vh;
      display: grid;
      grid-template-columns: 1fr auto;
      grid-template-areas:
        "hdr  hdr"
        "hero hero"
        "stp  mag"
        "pill pill"
        "cd   cd"
        "cta  cta"
        "ftr  ftr";
      padding: 0 16px 48px; gap: 0; align-items: start;
    }
    .hpm-header          { grid-area: hdr; }
    .hpm-hero            { grid-area: hero; }
    .hpm-steps           { grid-area: stp; }
    .hpm-mag             { grid-area: mag; }
    .hpm-pill            { grid-area: pill; }
    .hpm-countdown       { grid-area: cd; }
    .hpm-cta             { grid-area: cta; }
    .hpm-footer          { grid-area: ftr; }

    @media (min-width: 768px) {
      .hpm-page {
        grid-template-columns: 1fr 380px;
        grid-template-areas:
          "hdr  hdr"
          "hero mag"
          "stp  mag"
          "pill mag"
          "cd   mag"
          "cta  mag"
          "ftr  mag";
        padding: 0 60px 60px; column-gap: 48px;
        max-width: 1400px; margin: 0 auto;
      }
      .hpm-mag { align-self: center; }
    }
    @media (min-width: 1200px) {
      .hpm-page { grid-template-columns: 1fr 460px; column-gap: 64px; padding: 0 80px 80px; }
    }

    /* ─── HEADER ────────────────────────────── */
    .hpm-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 18px 0 0; position: relative; z-index: 10;
    }
    .hpm-brand img { height: 60px; filter: drop-shadow(0 2px 4px rgba(13,43,110,.12)); }
    @media (min-width: 768px) { .hpm-brand img { height: 80px; } }
    .hpm-date-pill {
      background: var(--hpm-navy); color: #fff;
      font-family: 'Fredoka One', cursive; font-size: .8em;
      letter-spacing: .05em; padding: 6px 16px; border-radius: 20px;
    }

    /* ─── HERO ──────────────────────────────── */
    .hpm-hero { padding: 14px 0 0; position: relative; z-index: 10; }
    .hpm-title-img {
      width: min(340px, 92%); display: block;
      filter: drop-shadow(0 4px 12px rgba(13,43,110,.15));
      animation: hpmPopIn .5s cubic-bezier(.34,1.56,.64,1) both;
    }
    .hpm-caption-img {
      width: min(300px, 85%); display: block; margin-top: 6px;
      filter: drop-shadow(0 2px 6px rgba(13,43,110,.1));
      animation: hpmPopIn .5s cubic-bezier(.34,1.56,.64,1) .1s both;
    }
    @media (min-width: 768px) {
      .hpm-hero { padding: 28px 0 0; }
      .hpm-title-img { width: min(480px, 96%); }
      .hpm-caption-img { width: min(420px, 94%); margin-top: 8px; }
    }
    @keyframes hpmPopIn {
      from { transform: scale(.85); opacity: 0; }
      to   { transform: scale(1);   opacity: 1; }
    }

    /* ─── STEPS ─────────────────────────────── */
    .hpm-steps {
      padding-top: 14px; position: relative; z-index: 10;
      display: flex; flex-direction: column; gap: 8px; padding-right: 10px;
    }
    .hpm-step {
      border-radius: 16px; padding: 11px 13px;
      display: flex; align-items: center; gap: 10px;
      animation: hpmSlideIn .4s ease both;
    }
    .hpm-step.s1 { background: var(--hpm-pink); box-shadow: 0 4px 14px rgba(240,45,125,.35); }
    .hpm-step.s2 { background: var(--hpm-lime); box-shadow: 0 4px 14px rgba(92,191,42,.35); animation-delay: .08s; }
    @keyframes hpmSlideIn {
      from { transform: translateX(-12px); opacity: 0; }
      to   { transform: translateX(0);     opacity: 1; }
    }
    .hpm-step-num { font-family: 'Fredoka One', cursive; font-size: 1.6em; color: rgba(255,255,255,.28); line-height: 1; flex-shrink: 0; width: 22px; text-align: center; }
    .hpm-step-text { font-size: .82em; color: #fff; font-weight: 800; line-height: 1.35; }
    .hpm-step-text em { font-style: normal; color: rgba(255,255,255,.72); font-weight: 700; }
    @media (min-width: 768px) {
      .hpm-steps { padding-top: 20px; padding-right: 0; gap: 12px; }
      .hpm-step { padding: 14px 18px; gap: 14px; }
      .hpm-step-text { font-size: .92em; }
      .hpm-step-num { font-size: 2.2em; width: 32px; }
    }

    /* ─── MAGNIFIER ─────────────────────────── */
    .hpm-mag { position: relative; z-index: 10; padding-top: 14px; animation: hpmFloatMag 4s ease-in-out infinite; }
    .hpm-mag-img { width: 170px; display: block; filter: drop-shadow(0 6px 20px rgba(13,43,110,.2)); }
    @media (min-width: 768px) { .hpm-mag { padding-top: 0; } .hpm-mag-img { width: 100%; max-width: 380px; } }
    @media (min-width: 1200px) { .hpm-mag-img { max-width: 440px; } }
    @keyframes hpmFloatMag {
      0%, 100% { transform: translateY(0) rotate(-1.5deg); }
      50%       { transform: translateY(-10px) rotate(1deg); }
    }

    /* ─── SLOT PILL ──────────────────────────── */
    .hpm-pill {
      display: inline-flex; align-items: center; gap: 10px;
      background: #fff; border-radius: 100px; padding: 7px 18px 7px 7px;
      box-shadow: 0 4px 18px rgba(13,43,110,.14); margin-top: 12px;
      align-self: center; justify-self: center;
      animation: hpmPopIn .4s cubic-bezier(.34,1.56,.64,1) .2s both;
      position: relative; z-index: 10;
    }
    @media (min-width: 768px) { .hpm-pill { margin-top: 20px; padding: 8px 22px 8px 8px; justify-self: center; } }
    .hpm-pill-icon {
      width: 36px; height: 36px; border-radius: 50%; background: var(--hpm-pink);
      flex-shrink: 0; box-shadow: 0 2px 8px rgba(240,45,125,.3); position: relative;
    }
    .hpm-pill-icon::before {
      content: '!'; font-family: 'Fredoka One', cursive; font-size: 1.1em; color: #fff;
      position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
    }
    @media (min-width: 768px) { .hpm-pill-icon { width: 42px; height: 42px; } }
    .hpm-pill-big { font-family: 'Fredoka One', cursive; font-size: 1.15em; color: var(--hpm-pink); line-height: 1; display: block; }
    .hpm-pill-sub { font-size: .66em; color: var(--hpm-navy); opacity: .5; font-weight: 800; display: block; margin-top: 1px; }
    @media (min-width: 768px) { .hpm-pill-big { font-size: 1.3em; } .hpm-pill-sub { font-size: .72em; } }

    /* ─── COUNTDOWN ──────────────────────────── */
    .hpm-countdown { padding-top: 16px; position: relative; z-index: 10; }
    .hpm-cd-label {
      font-family: 'Fredoka One', cursive; font-size: .78em; color: var(--hpm-navy); opacity: .45;
      letter-spacing: .14em; text-transform: uppercase; margin-bottom: 8px; text-align: center;
    }
    .hpm-cd-row { display: flex; align-items: flex-start; gap: 6px; flex-wrap: wrap; justify-content: center; }
    .hpm-cd-tile {
      background: #fff; border-radius: 14px; padding: 11px 12px 8px; min-width: 62px; text-align: center;
      box-shadow: 0 4px 16px rgba(13,43,110,.12), 0 0 0 2px rgba(240,45,125,.1);
      position: relative; overflow: hidden;
    }
    .hpm-cd-tile::after {
      content: ''; position: absolute; inset: 0;
      background: linear-gradient(135deg, rgba(255,255,255,.5) 0%, transparent 55%); pointer-events: none;
    }
    .hpm-cd-digits {
      font-family: 'Fredoka One', cursive; font-size: 2.2em; color: var(--hpm-pink);
      line-height: 1; font-variant-numeric: tabular-nums; display: block;
    }
    .hpm-cd-digits.hpm-bump { animation: hpmBump .25s ease; }
    @keyframes hpmBump { 0% { transform: scale(1.15); } 100% { transform: scale(1); } }
    .hpm-cd-unit {
      font-family: 'Fredoka One', cursive; font-size: .54em; color: var(--hpm-navy); opacity: .42;
      text-transform: uppercase; letter-spacing: .1em; margin-top: 2px; display: block;
    }
    .hpm-cd-sep { font-family: 'Fredoka One', cursive; font-size: 2em; color: var(--hpm-pink); opacity: .38; align-self: center; padding-bottom: 22px; line-height: 1; }
    @media (min-width: 768px) {
      .hpm-countdown { padding-top: 24px; }
      .hpm-cd-tile { padding: 14px 18px 10px; min-width: 80px; border-radius: 18px; }
      .hpm-cd-digits { font-size: 3em; }
      .hpm-cd-unit { font-size: .6em; margin-top: 4px; }
      .hpm-cd-sep { font-size: 2.6em; padding-bottom: 30px; }
      .hpm-cd-row { gap: 8px; }
    }

    /* ─── CTA + FOOTER ──────────────────────── */
    .hpm-cta { margin-top: 16px; font-size: .88em; color: var(--hpm-navy); opacity: .58; font-weight: 700; line-height: 1.6; position: relative; z-index: 10; text-align: center; }
    .hpm-cta strong { color: var(--hpm-pink); opacity: 1; }
    @media (min-width: 768px) { .hpm-cta { margin-top: 20px; font-size: 1em; text-align: left; } }
    .hpm-footer { margin-top: 20px; display: flex; gap: 20px; flex-wrap: wrap; font-size: .76em; color: var(--hpm-navy); opacity: .4; font-weight: 700; position: relative; z-index: 10; }
    @media (min-width: 768px) { .hpm-footer { margin-top: 28px; font-size: .84em; gap: 28px; } }

    /* ─── INACTIVE ───────────────────────────── */
    .hpm-inactive { display: flex; align-items: center; justify-content: center; min-height: 60vh; }
    .hpm-inactive p { font-family: 'Fredoka One', cursive; font-size: 1.2em; color: var(--hpm-navy); opacity: .45; }

    /* ─── MOBILE FIXES (≤767px) ─────────────── */
    @media (max-width: 767px) {
      .hpm-page {
        grid-template-columns: 1fr 120px;
      }
      .hpm-mag-img {
        width: 110px;
      }
      .hpm-cd-row {
        flex-wrap: nowrap;
        gap: 4px;
      }
      .hpm-cd-tile {
        min-width: 52px;
        padding: 8px 6px 6px;
      }
      .hpm-cd-digits {
        font-size: 1.9em;
      }
      .hpm-cd-sep {
        font-size: 1.8em;
        padding-bottom: 18px;
      }
      .hpm-corner-green    { width: 190px; height: 190px; bottom: -55px; right: -44px; }
      .hpm-corner-green-2  { width: 100px; height: 100px; bottom: -22px; right: 66px; }
      .hpm-corner-blue     { width: 110px; height: 110px; top: -33px; right: -33px; }
      .hpm-corner-pink     { width: 120px; height: 120px; top: -38px; left: -38px; }
      .hpm-corner-pink-2   { width: 60px;  height: 60px;  top: -11px; left: 55px; }
      .hpm-corner-orange   { width: 145px; height: 145px; bottom: -60px; left: -44px; }
      .hpm-corner-orange-2 { width: 72px;  height: 72px;  bottom: -28px; left: 72px; }
    }
    </style>
    <?php
});

get_header();
?>

<?php if (!$campaign): ?>

<div class="hpm-bg-deco">
  <div class="hpm-cloud hpm-c1"></div>
  <div class="hpm-cloud hpm-c2"></div>
</div>
<div class="hpm-inactive">
  <p>Tiada promosi aktif pada masa ini.</p>
</div>

<?php else: ?>

<div class="hpm-corner-wrap">
  <div class="hpm-corner-pink"></div>
  <div class="hpm-corner-pink-2"></div>
  <div class="hpm-corner-blue"></div>
  <div class="hpm-corner-orange"></div>
  <div class="hpm-corner-orange-2"></div>
  <div class="hpm-corner-green"></div>
  <div class="hpm-corner-green-2"></div>
</div>

<div class="hpm-bg-deco">
  <div class="hpm-cloud hpm-c1"></div>
  <div class="hpm-cloud hpm-c2"></div>
  <div class="hpm-cloud hpm-c3"></div>
  <div class="hpm-cloud hpm-c4"></div>
  <div class="hpm-cloud hpm-c5"></div>
  <span class="hpm-math hpm-m1">a+b=c&#178;</span>
  <span class="hpm-math hpm-m2">3&times;3=9</span>
  <span class="hpm-math hpm-m3">&radic;36</span>
  <span class="hpm-math hpm-m4">&sum;n</span>
  <span class="hpm-math hpm-m5">x&#178;+y&#178;</span>
  <span class="hpm-math hpm-m6">&pi;&asymp;3.14</span>
</div>

<div class="hpm-page">

  <header class="hpm-header">
    <div class="hpm-brand">
      <img src="<?= esc_url($assets . 'logo_home.png') ?>" alt="HOME">
    </div>
    <?php if ($start_display && $end_display): ?>
    <div class="hpm-date-pill"><?= esc_html($start_display) ?> – <?= esc_html($end_display) ?></div>
    <?php endif; ?>
  </header>

  <div class="hpm-hero">
    <img class="hpm-title-img" src="<?= esc_url($assets . 'title.png') ?>" alt="<?= esc_attr($cam_name) ?>">
    <img class="hpm-caption-img" src="<?= esc_url($assets . 'captions.png') ?>" alt="Discover the HOME 6 Principles">
  </div>

  <div class="hpm-steps">
    <div class="hpm-step s1">
      <div class="hpm-step-num">1</div>
      <div class="hpm-step-text">Like and Comment one of the <em>HOME 6 Principles</em></div>
    </div>
    <div class="hpm-step s2">
      <div class="hpm-step-num">2</div>
      <div class="hpm-step-text">Redeem <em>RM<?= esc_html($discount) ?> OFF</em> your registration</div>
    </div>
  </div>

  <div class="hpm-mag">
    <img class="hpm-mag-img" src="<?= esc_url($assets . 'magnifiying.png') ?>" alt="RM<?= esc_html($discount) ?> OFF">
  </div>

  <div class="hpm-pill">
    <div class="hpm-pill-icon"></div>
    <div>
      <span class="hpm-pill-big" id="hpm-slots-remaining">Memuatkan...</span>
      <span class="hpm-pill-sub" id="hpm-slots-used"></span>
    </div>
  </div>

  <div class="hpm-countdown">
    <div class="hpm-cd-label">Tamat dalam</div>
    <div class="hpm-cd-row">
      <div class="hpm-cd-tile"><span class="hpm-cd-digits" id="hpm-days">00</span><span class="hpm-cd-unit">Hari</span></div>
      <div class="hpm-cd-sep">:</div>
      <div class="hpm-cd-tile"><span class="hpm-cd-digits" id="hpm-hours">00</span><span class="hpm-cd-unit">Jam</span></div>
      <div class="hpm-cd-sep">:</div>
      <div class="hpm-cd-tile"><span class="hpm-cd-digits" id="hpm-mins">00</span><span class="hpm-cd-unit">Min</span></div>
      <div class="hpm-cd-sep">:</div>
      <div class="hpm-cd-tile"><span class="hpm-cd-digits" id="hpm-secs">00</span><span class="hpm-cd-unit">Saat</span></div>
    </div>
  </div>

  <div class="hpm-cta">
    Hubungi outlet anda untuk mendapatkan <strong>kod promo</strong>.
  </div>

  <footer class="hpm-footer">
    <span>homemathstherapy</span>
    <span>www.home.edu.my</span>
  </footer>

</div><!-- .hpm-page -->

<script>
(function(){
  var endTime = new Date('<?= $end_date_js ?> UTC').getTime();
  if (isNaN(endTime)) return;
  var prev = {};
  function pad(n){ return String(n).padStart(2,'0'); }
  function tick(){
    var diff = Math.max(0, endTime - Date.now());
    var vals = {
      days:  Math.floor(diff / 86400000),
      hours: Math.floor((diff % 86400000) / 3600000),
      mins:  Math.floor((diff % 3600000) / 60000),
      secs:  Math.floor((diff % 60000) / 1000)
    };
    ['days','hours','mins','secs'].forEach(function(k){
      var el = document.getElementById('hpm-' + k);
      var v  = pad(vals[k]);
      if (v !== prev[k]) {
        el.textContent = v;
        el.classList.remove('hpm-bump');
        void el.offsetWidth;
        el.classList.add('hpm-bump');
        prev[k] = v;
      }
    });
  }
  tick(); setInterval(tick, 1000);

  fetch('<?= $counter_url ?>')
    .then(function(r){ return r.json(); })
    .then(function(d){
      var remaining = d.remaining !== undefined ? d.remaining : (d.max - d.used);
      document.getElementById('hpm-slots-remaining').textContent = remaining + ' slot lagi!';
      document.getElementById('hpm-slots-used').textContent = d.used + ' / ' + d.max + ' digunakan';
    })
    .catch(function(){
      document.getElementById('hpm-slots-remaining').textContent = 'Slot terhad';
    });
})();
</script>

<?php endif; ?>

<?php get_footer(); ?>
