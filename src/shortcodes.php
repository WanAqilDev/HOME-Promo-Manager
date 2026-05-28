<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

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
