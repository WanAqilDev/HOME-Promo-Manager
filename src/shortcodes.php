<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

const HPM_CATEGORY_COLORS = [
    'new'          => '#3b82f6',
    'diagnosed'    => '#f59e0b',
    'reactivation' => '#8b5cf6',
];
const HPM_CATEGORY_LABELS = [
    'new'          => 'Baru',
    'diagnosed'    => 'Diagnos',
    'reactivation' => 'Reaktivasi',
];

add_shortcode('hpm_counter', function () {
    $campaign = CampaignEngine::get_active();
    if (!$campaign) return '<span class="hpm-counter">Tiada promosi aktif.</span>';
    ob_start();
    ?>
    <span class="hpm-counter" id="hpm-counter-inline">Memuatkan...</span>
    <script>
    fetch('<?= esc_url(rest_url('promo/v1/counter')) ?>')
      .then(r=>r.json()).then(function(d){
        document.getElementById('hpm-counter-inline').textContent =
          d.remaining + ' / ' + d.max + ' slot tersisa';
      });
    </script>
    <?php
    return ob_get_clean();
});

add_shortcode('hpm_promo_status', function ($atts) {
    $atts = shortcode_atts(['entry_id' => 0], $atts);
    $id   = (int) $atts['entry_id'];
    if (!$id) return '';
    $row = DB::get_entry_promo_status($id);
    return hpm_render_promo_status_block($row);
});

add_shortcode('hpm_enrollment_modal', function ($atts) {
    $atts     = shortcode_atts(['entry_id' => 0], $atts);
    $entry_id = (int) $atts['entry_id'];
    if (!$entry_id) return '';

    // Output CSS once per page via wp_head
    static $css_done = false;
    if (!$css_done) {
        $css_done = true;
        add_action('wp_head', function () {
            ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style id="hpm-enroll-modal-css">
#hpm-enroll-overlay{position:fixed;inset:0;z-index:9999;background:rgba(13,43,110,.75);display:none;align-items:center;justify-content:center;padding:16px;}
#hpm-enroll-overlay.hpm-show{display:flex;animation:hpmFadeIn .3s ease-out both;}
@keyframes hpmFadeIn{from{opacity:0}to{opacity:1}}
.hpm-enroll-card{background:#fff;border-radius:24px;padding:28px 24px;max-width:380px;width:100%;text-align:center;box-shadow:0 8px 28px rgba(13,43,110,.18);animation:hpmPopIn .5s cubic-bezier(.34,1.56,.64,1) both;}
@keyframes hpmPopIn{from{transform:scale(.85);opacity:0}to{transform:scale(1);opacity:1}}
.hpm-enroll-logo{height:40px;margin:0 auto 16px;display:block;}
.hpm-enroll-icon{width:60px;height:60px;margin:0 auto 16px;border-radius:50%;background:linear-gradient(135deg,#5cbf2a,#3d9a18);display:flex;align-items:center;justify-content:center;font-family:'Fredoka One',cursive;font-size:1.8em;color:#fff;box-shadow:0 4px 14px rgba(92,191,42,.35);animation:hpmIconBounce .6s cubic-bezier(.34,1.56,.64,1) .1s both;}
@keyframes hpmIconBounce{0%{transform:scale(.5);opacity:0}70%{transform:scale(1.1)}100%{transform:scale(1);opacity:1}}
.hpm-enroll-title{font-family:'Fredoka One',cursive;font-size:1.4em;color:#0d2b6e;line-height:1.4;margin:0 0 12px;}
.hpm-enroll-badge{display:inline-flex;color:#fff;padding:4px 14px;border-radius:12px;font-family:'Nunito',sans-serif;font-size:.75em;font-weight:700;margin-bottom:16px;}
.hpm-enroll-code-label{display:block;font-family:'Nunito',sans-serif;font-size:.85em;font-weight:700;color:#0d2b6e;opacity:.6;margin:12px 0 8px;}
.hpm-enroll-code-pill{display:inline-flex;background:#f02d7d;color:#fff;font-family:'Fredoka One',cursive;font-size:2.2em;padding:12px 24px;border-radius:20px;box-shadow:0 4px 14px rgba(240,45,125,.35);letter-spacing:.05em;margin:8px 0;}
.hpm-enroll-campaign{display:block;font-family:'Nunito',sans-serif;font-size:.8em;font-weight:700;color:#0d2b6e;opacity:.5;margin-top:8px;}
.hpm-enroll-date{display:block;font-family:'Nunito',sans-serif;font-size:.78em;font-weight:600;color:#0d2b6e;opacity:.4;margin:4px 0 20px;}
.hpm-enroll-dismiss{border:2px solid #0d2b6e;background:transparent;color:#0d2b6e;padding:10px 24px;border-radius:20px;font-family:'Nunito',sans-serif;font-size:.9em;font-weight:700;cursor:pointer;transition:all .2s ease;}
.hpm-enroll-dismiss:hover{background:#0d2b6e;color:#fff;}
@media(min-width:768px){
  .hpm-enroll-card{padding:36px 32px;}
  .hpm-enroll-icon{width:80px;height:80px;font-size:2.2em;}
  .hpm-enroll-title{font-size:1.7em;}
  .hpm-enroll-code-pill{font-size:2.8em;padding:16px 32px;}
}
</style>
            <?php
        });
    }

    $assets_url = plugins_url('assets/poster/', HPM_PLUGIN_FILE);
    $api_url    = esc_js(esc_url(rest_url('promo/v1/status/' . $entry_id)));
    $colors_json = wp_json_encode(HPM_CATEGORY_COLORS);
    $labels_json = wp_json_encode(HPM_CATEGORY_LABELS);

    ob_start();
    ?>
<div id="hpm-enroll-overlay">
  <div class="hpm-enroll-card">
    <img class="hpm-enroll-logo" src="<?= esc_url($assets_url) ?>logo_home.png" alt="HOME">
    <div class="hpm-enroll-icon">&#10003;</div>
    <p class="hpm-enroll-title">Tahniah! Klien berjaya<br>didaftarkan dengan promo.</p>
    <span class="hpm-enroll-badge" id="hpm-badge" style="display:none;"></span>
    <span class="hpm-enroll-code-label">Kod Promo</span>
    <div class="hpm-enroll-code-pill" id="hpm-code">—</div>
    <span class="hpm-enroll-campaign" id="hpm-campaign"></span>
    <span class="hpm-enroll-date" id="hpm-enrolled-at"></span>
    <button class="hpm-enroll-dismiss" id="hpm-enroll-close">Tutup</button>
  </div>
</div>
<script>
(function(){
  var apiUrl     = '<?= $api_url ?>';
  var overlay    = document.getElementById('hpm-enroll-overlay');
  var badgeEl    = document.getElementById('hpm-badge');
  var codeEl     = document.getElementById('hpm-code');
  var campaignEl = document.getElementById('hpm-campaign');
  var dateEl     = document.getElementById('hpm-enrolled-at');
  var colors     = <?= $colors_json ?>;
  var labels     = <?= $labels_json ?>;

  fetch(apiUrl)
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (!d.just_enrolled) return;
      codeEl.textContent     = d.code || '—';
      campaignEl.textContent = d.campaign || '';
      dateEl.textContent     = d.enrolled_at ? 'Didaftarkan: ' + d.enrolled_at : '';
      if (d.category && labels[d.category]) {
        badgeEl.textContent      = labels[d.category];
        badgeEl.style.background = colors[d.category] || '#6b7280';
        badgeEl.style.display    = 'inline-flex';
      }
      overlay.classList.add('hpm-show');
    });

  document.getElementById('hpm-enroll-close').addEventListener('click', function(){
    overlay.classList.remove('hpm-show');
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') overlay.classList.remove('hpm-show');
  });
})();
</script>
    <?php
    return ob_get_clean();
});

function hpm_render_promo_status_block(?array $row): string
{
    ob_start();
    if ($row):
        $cat       = $row['user_category'] ?? '';
        $cat_color = HPM_CATEGORY_COLORS[$cat] ?? '#6b7280';
        $cat_label = HPM_CATEGORY_LABELS[$cat] ?? $cat;
        $code      = $row['promo_code'] ?? '—';
        $raw_date  = $row['enrolled_at'] ?? '';
        $ts        = $raw_date ? strtotime($raw_date) : false;
        $enrolled  = ($ts && $ts > 0) ? wp_date('j M Y, g:i a', $ts) : '—';
    ?>
    <div class="hpm-promo-status-block" style="border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;margin:12px 0;background:#f9fafb;">
        <p style="font-weight:600;margin:0 0 8px;font-size:.8em;text-transform:uppercase;letter-spacing:.05em;color:#374151;">Status Promo</p>
        <p style="margin:0 0 6px;color:#16a34a;font-weight:600;">&#10003; Klien ini telah menerima promo.</p>
        <table style="font-size:.88em;border-collapse:collapse;width:100%;">
            <tr>
                <td style="padding:2px 8px 2px 0;color:#6b7280;white-space:nowrap;">Kategori</td>
                <td><span style="background:<?= esc_attr($cat_color) ?>;color:#fff;border-radius:4px;padding:2px 8px;font-size:.8em;font-weight:600;"><?= esc_html($cat_label) ?></span></td>
            </tr>
            <tr>
                <td style="padding:2px 8px 2px 0;color:#6b7280;white-space:nowrap;">Kod Promo</td>
                <td style="font-weight:600;"><?= esc_html($code) ?></td>
            </tr>
            <tr>
                <td style="padding:2px 8px 2px 0;color:#6b7280;white-space:nowrap;">Didaftar</td>
                <td><?= esc_html($enrolled) ?></td>
            </tr>
        </table>
    </div>
    <?php else: ?>
    <div class="hpm-promo-status-block" style="border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;margin:12px 0;background:#f9fafb;">
        <p style="font-weight:600;margin:0 0 8px;font-size:.8em;text-transform:uppercase;letter-spacing:.05em;color:#374151;">Status Promo</p>
        <p style="margin:0;color:#6b7280;">&#8212; Klien ini belum menerima promo.</p>
    </div>
    <?php endif;
    return ob_get_clean();
}
