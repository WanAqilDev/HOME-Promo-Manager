<?php
/**
 * Template Name: HPM Promo Page
 */
get_header();
$campaign = HPM\CampaignEngine::get_active();
?>
<div id="hpm-promo-wrap" style="max-width:640px;margin:40px auto;text-align:center;font-family:sans-serif;">
  <?php if ($campaign): ?>
    <div id="hpm-poster" style="background:#f0f0f0;height:320px;display:flex;align-items:center;justify-content:center;border-radius:12px;margin-bottom:24px;">
      <span style="font-size:1.2em;color:#888;">[Poster Placeholder — <?= esc_html($campaign->name) ?>]</span>
    </div>
    <h1 style="font-size:2em;"><?= esc_html($campaign->name) ?></h1>
    <p style="font-size:1.3em;background:#d4edda;padding:12px;border-radius:8px;">
      Diskaun RM<?= esc_html(number_format((float)$campaign->discount_amount, 2)) ?> akan dikenakan secara automatik.
    </p>
    <div id="hpm-countdown" style="font-size:2.5em;font-weight:bold;margin:16px 0;">--:--:--</div>
    <div id="hpm-slots" style="font-size:1.4em;">Memuatkan...</div>
    <script>
    (function(){
      function update(){
        fetch('<?= esc_url(rest_url('promo/v1/counter')) ?>')
          .then(r=>r.json()).then(function(d){
            document.getElementById('hpm-slots').textContent =
              d.remaining + ' / ' + d.max + ' slot tersisa';
          });
        var end = new Date('<?= esc_js($campaign->end_date) ?> UTC');
        var now = new Date(); var diff = Math.max(0, end - now);
        var h = String(Math.floor(diff/3600000)).padStart(2,'0');
        var m = String(Math.floor((diff%3600000)/60000)).padStart(2,'0');
        var s = String(Math.floor((diff%60000)/1000)).padStart(2,'0');
        document.getElementById('hpm-countdown').textContent = h+':'+m+':'+s;
      }
      update(); setInterval(update, 5000);
    })();
    </script>
  <?php else: ?>
    <p>Tiada promosi aktif pada masa ini.</p>
  <?php endif; ?>
</div>
<?php get_footer(); ?>
