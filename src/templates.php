<?php
namespace HPM;

if (!defined('ABSPATH'))
    exit;

class Templates
{
    private static $instance = null;
    private $templates;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->templates = [
            'promo-page.php' => 'Promo Countdown 12.12 (Live API)',
        ];

        add_filter('theme_page_templates', [$this, 'add_new_template']);
        add_filter('wp_insert_post_data', [$this, 'register_project_templates']);
        add_filter('template_include', [$this, 'view_project_template']);
    }

    public function add_new_template($posts_templates)
    {
        $posts_templates = array_merge($posts_templates, $this->templates);
        return $posts_templates;
    }

    public function register_project_templates($atts)
    {
        $cache_key = 'page_templates-' . md5(get_theme_root() . '/' . get_stylesheet());
        $templates = wp_get_theme()->get_page_templates();
        if (empty($templates)) {
            $templates = [];
        }
        wp_cache_delete($cache_key, 'themes');
        $templates = array_merge($templates, $this->templates);
        wp_cache_add($cache_key, $templates, 'themes', 1800);
        return $atts;
    }

    public function view_project_template($template)
    {
        global $post;
        if (!$post)
            return $template;

        $template_slug = get_page_template_slug($post->ID);

        if (isset($this->templates[$template_slug])) {
            $file = plugin_dir_path(__DIR__) . 'template/' . $template_slug;
            if (file_exists($file)) {
                return $file;
            }
        }
        return $template;
    }
}

Templates::get_instance();
