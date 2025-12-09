<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

/**
 * GitHub Plugin Updater
 * Enables automatic updates from GitHub repository
 */
class Updater {
    private $plugin_slug;
    private $plugin_file;
    private $github_repo;
    private $github_user;
    private $version;
    
    public function __construct($plugin_file, $github_user, $github_repo, $version) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->github_user = $github_user;
        $this->github_repo = $github_repo;
        $this->version = $version;
        
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
    }
    
    /**
     * Check for plugin updates
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Delete the version cache to force a fresh check
        delete_transient('hpm_remote_version');
        
        $remote_version = $this->get_remote_version();
        
        error_log('[HPM Updater] Current version: ' . $this->version . ', Remote version: ' . $remote_version);
        
        if ($remote_version && version_compare($this->version, $remote_version, '<')) {
            $obj = new \stdClass();
            $obj->slug = dirname($this->plugin_slug); // Use folder name as slug
            $obj->plugin = $this->plugin_slug;
            $obj->new_version = $remote_version;
            $obj->url = "https://github.com/{$this->github_user}/{$this->github_repo}";
            $obj->package = "https://github.com/{$this->github_user}/{$this->github_repo}/archive/refs/heads/master.zip";
            $obj->tested = get_bloginfo('version');
            $obj->compatibility = new \stdClass();
            
            $transient->response[$this->plugin_slug] = $obj;
            
            error_log('[HPM Updater] Update available! Added to transient.');
        } else {
            error_log('[HPM Updater] No update needed.');
        }
        
        return $transient;
    }
    
    /**
     * Get plugin information for the update screen
     */
    public function plugin_info($false, $action, $response) {
        if ($action !== 'plugin_information') {
            return $false;
        }
        
        // Check both slug formats
        $folder_slug = dirname($this->plugin_slug);
        if (!isset($response->slug) || ($response->slug !== $this->plugin_slug && $response->slug !== $folder_slug)) {
            return $false;
        }
        
        $remote_version = $this->get_remote_version();
        
        $obj = new \stdClass();
        $obj->name = 'HOME Promo Manager';
        $obj->slug = $folder_slug;
        $obj->plugin = $this->plugin_slug;
        $obj->version = $remote_version;
        $obj->author = '<a href="https://github.com/' . $this->github_user . '">' . $this->github_user . '</a>';
        $obj->homepage = "https://github.com/{$this->github_user}/{$this->github_repo}";
        $obj->requires = '5.0';
        $obj->tested = get_bloginfo('version');
        $obj->download_link = "https://github.com/{$this->github_user}/{$this->github_repo}/archive/refs/heads/master.zip";
        $obj->sections = [
            'description' => 'Manages HOME promotional codes and tracks activations/reactivations.',
            'changelog' => $this->get_changelog()
        ];
        
        return $obj;
    }
    
    /**
     * Rename plugin folder after update
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        $plugin_folder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname($this->plugin_slug);
        
        error_log('[HPM Updater] After install - Source: ' . $result['destination'] . ', Target: ' . $plugin_folder);
        
        // Remove old folder if exists
        if ($wp_filesystem->exists($plugin_folder)) {
            $wp_filesystem->delete($plugin_folder, true);
        }
        
        // Move new folder to correct location
        $wp_filesystem->move($result['destination'], $plugin_folder);
        $result['destination'] = $plugin_folder;
        
        // Reactivate plugin
        if (isset($hook_extra['plugin'])) {
            activate_plugin($hook_extra['plugin']);
        }
        
        error_log('[HPM Updater] Plugin updated and reactivated');
        
        return $result;
    }
    
    /**
     * Get remote version from GitHub
     */
    private function get_remote_version() {
        // Check cache first
        $cached = get_transient('hpm_remote_version');
        if ($cached !== false) {
            return $cached;
        }
        
        $response = wp_remote_get(
            "https://raw.githubusercontent.com/{$this->github_user}/{$this->github_repo}/master/home-promo-manager.php",
            ['timeout' => 10, 'headers' => ['Accept' => 'application/vnd.github.v3.raw']]
        );
        
        if (is_wp_error($response)) {
            error_log('[HPM Updater] Failed to fetch remote version: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        
        if (preg_match('/Version:\s*([0-9.]+)/i', $body, $matches)) {
            $version = $matches[1];
            set_transient('hpm_remote_version', $version, HOUR_IN_SECONDS);
            return $version;
        }
        
        return false;
    }
    
    /**
     * Get changelog from GitHub
     */
    private function get_changelog() {
        $response = wp_remote_get(
            "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/commits?per_page=5",
            ['timeout' => 10]
        );
        
        if (is_wp_error($response)) {
            return 'No changelog available.';
        }
        
        $commits = json_decode(wp_remote_retrieve_body($response));
        
        if (empty($commits)) {
            return 'No changelog available.';
        }
        
        $changelog = '<ul>';
        foreach ($commits as $commit) {
            $date = date('Y-m-d', strtotime($commit->commit->author->date));
            $message = esc_html($commit->commit->message);
            $changelog .= "<li><strong>{$date}</strong>: {$message}</li>";
        }
        $changelog .= '</ul>';
        
        return $changelog;
    }
}
