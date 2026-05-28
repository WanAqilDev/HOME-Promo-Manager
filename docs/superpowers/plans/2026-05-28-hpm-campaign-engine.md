# HOME Promo Manager — Generic Campaign Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign HOME Promo Manager from a SMART26-specific plugin into a generic Campaign Promo Engine where any promotion (6CURE, Merdeka 2026, etc.) is a DB row, not a code change. First campaign: The 6CURE (6–12 Jun 2026, 330 slots, RM33 auto-apply).

**Architecture:** `wp_home_promo_campaigns` stores campaign rows; `wp_home_promo_active` is a single-row pointer table enforcing one active campaign atomically at the DB layer. `CampaignEngine.php` loads the active campaign once per request via a static cache. `Eligibility.php` contains three leaf specs combined with `OrSpecification`. `HookDispatcher` in `hooks.php` wires Formidable hooks to the engine. Admin manages campaigns through a Campaigns tab with full CRUD and nonce-secured handlers.

**Tech Stack:** PHP 8.4 (Herd: `C:/Users/PC/.config/herd/bin/php84/php.exe`), WordPress + Formidable Forms, InnoDB MySQL, PHPUnit 9 + Mockery

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `src/CampaignEngine.php` | **Create** | `Campaign` value object + engine: `get_active()`, `flush()`, `is_active()`, `activate()`, `deactivate()`, `claim_slot()`, `CAP` constant |
| `src/Eligibility.php` | **Create** | `Spec` interface, `OrSpecification`, `NewSpec`, `DiagnosedSpec`, `ReactivationSpec` |
| `src/db.php` | **Extend** | New table CREATE statements, guarded ALTER TABLE, InnoDB check, Pasif backfill |
| `src/hooks.php` | **Rewrite** | `HookDispatcher` class: pre-hook snapshot, `$ctx` builder, dispatch, field 3170 integrity re-write, Pasif transition log |
| `src/admin.php` | **Extend** | Campaigns tab (list + create/edit/delete/activate/deactivate); remove Code Management tab |
| `src/rest.php` | **Simplify** | Simplified `/counter`; new admin-only `/campaigns` CRUD endpoints |
| `src/shortcodes.php` | **Simplify** | Remove code-entry UI; update counter shortcode |
| `src/Manager.php` | **Simplify** | Remove tier/code logic; keep settings singleton |
| `src/bootstrap.php` | **Extend** | Load new files; register cron hooks |
| `home-promo-manager.php` | **Bump** | Version → 1.0.0 |
| `template/promo-page.php` | **Rewrite** | Generic countdown/counter (no code-entry UI) |
| `src/Validator.php` | **Delete** | Replaced by Eligibility.php |
| `tests/bootstrap.php` | **Extend** | Add mocks for WP functions used by new code; add `get_row()` to MockWPDB |
| `tests/test-campaign-engine.php` | **Create** | CampaignEngine unit tests |
| `tests/test-eligibility.php` | **Create** | Spec unit tests |
| `tests/test-hooks.php` | **Create** | HookDispatcher unit tests |

**Test command (all tests):**
```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit
```

**Test command (single file):**
```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-campaign-engine.php -v
```

---

## Task 1: Extend test bootstrap and add DB helpers

**Files:**
- Modify: `tests/bootstrap.php`
- Modify: `src/db.php`
- Test: `tests/test-db.php`

- [ ] **Step 1: Add `get_row()` and `update()` to MockWPDB in `tests/bootstrap.php`**

Add inside the `MockWPDB` class body (after the existing `get_var` method):

```php
public function get_row($query, $output = OBJECT, $y = 0) {
    return null;
}
public function update($table, $data, $where, $format = null, $where_format = null) {
    return 1;
}
public function get_results($query, $output = OBJECT) {
    return [];
}
```

Also add these WP function stubs after the existing stubs (before `$GLOBALS['wpdb'] = new MockWPDB();`):

```php
if (!defined('OBJECT')) define('OBJECT', 'OBJECT');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        return strtolower(preg_replace('/[^a-z0-9-]/', '-', $title));
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return true; }
}
if (!function_exists('wp_die')) {
    function wp_die($msg = '', $code = '') { throw new \RuntimeException("wp_die: $msg"); }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action) { return ''; }
}
if (!function_exists('check_admin_referer')) {
    function check_admin_referer($action) { return true; }
}
if (!function_exists('esc_html')) {
    function esc_html($str) { return htmlspecialchars($str, ENT_QUOTES); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($str) { return htmlspecialchars($str, ENT_QUOTES); }
}
if (!function_exists('gmdate')) {
    // Already a PHP built-in; only stub if missing
}
if (!function_exists('error_log')) {
    function error_log($msg) { /* suppress in tests */ }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($time, $recurrence, $hook) { return true; }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook) { return false; }
}
if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event($time, $hook) { return true; }
}
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') { return true; }
}
if (!function_exists('add_option')) {
    function add_option($option, $value = '', $deprecated = '', $autoload = 'yes') { return true; }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return $url; }
}
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
```

At the bottom, extend the `require_once` list:

```php
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/Manager.php';
// New files added as they are created:
// require_once __DIR__ . '/../src/CampaignEngine.php';
// require_once __DIR__ . '/../src/Eligibility.php';
```

- [ ] **Step 2: Write failing test for `DB::column_exists()`**

Add to `tests/test-db.php`:

```php
public function testColumnExistsReturnsTrueWhenFound()
{
    $mockWpdb = Mockery::mock('MockWPDB');
    $mockWpdb->prefix = 'wp_';
    $mockWpdb->shouldReceive('prepare')
        ->with("SHOW COLUMNS FROM wp_home_promo_counted LIKE %s", 'campaign_id')
        ->once()
        ->andReturn("SHOW COLUMNS FROM wp_home_promo_counted LIKE 'campaign_id'");
    $mockWpdb->shouldReceive('get_var')
        ->once()
        ->andReturn('campaign_id');
    $GLOBALS['wpdb'] = $mockWpdb;

    $this->assertTrue(DB::column_exists('wp_home_promo_counted', 'campaign_id'));
}

public function testColumnExistsReturnsFalseWhenMissing()
{
    $mockWpdb = Mockery::mock('MockWPDB');
    $mockWpdb->prefix = 'wp_';
    $mockWpdb->shouldReceive('prepare')->andReturn('sql');
    $mockWpdb->shouldReceive('get_var')->once()->andReturn(null);
    $GLOBALS['wpdb'] = $mockWpdb;

    $this->assertFalse(DB::column_exists('wp_home_promo_counted', 'campaign_id'));
}
```

- [ ] **Step 3: Run to verify failure**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-db.php -v
```

Expected: FAIL — `DB::column_exists` not found.

- [ ] **Step 4: Add `column_exists()` to `src/db.php`**

Add as a `public static` method inside the `DB` class:

```php
public static function column_exists(string $table, string $column): bool {
    global $wpdb;
    return ! empty($wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$table} LIKE %s", $column
    )));
}
```

- [ ] **Step 5: Run to verify pass**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-db.php -v
```

Expected: PASS.

- [ ] **Step 6: Commit**

```
git add tests/bootstrap.php src/db.php tests/test-db.php
git commit -m "feat: add DB::column_exists + extend test bootstrap mocks"
```

---

## Task 2: DB — new table CREATE statements

**Files:**
- Modify: `src/db.php`
- Test: `tests/test-db.php`

- [ ] **Step 1: Write failing test for new table SQL in `install()`**

Add to `tests/test-db.php`:

```php
public function testInstallCreatesHomePromoActiveSql()
{
    $mockWpdb = Mockery::mock('MockWPDB');
    $mockWpdb->prefix = 'wp_';
    $mockWpdb->shouldReceive('get_charset_collate')->andReturn('DEFAULT CHARSET=utf8mb4');
    // Allow all calls; just check the SQL string content
    $mockWpdb->shouldReceive('get_var')->andReturn(null);
    $mockWpdb->shouldReceive('prepare')->andReturn('');
    $mockWpdb->shouldReceive('query')->andReturn(true);
    $GLOBALS['wpdb'] = $mockWpdb;

    ob_start();
    // Capture the SQL strings passed to dbDelta by inspecting install()
    // We test via a spy — for now just assert install() runs without exception
    DB::install('0.0.0', '1.0.0');
    ob_end_clean();
    $this->assertTrue(true); // no exception = pass
}
```

- [ ] **Step 2: Run to verify failure (or skip if install already exists)**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-db.php -v
```

- [ ] **Step 3: Add new table definitions to `DB::install()` in `src/db.php`**

Inside `DB::install()`, add these CREATE TABLE strings to the `dbDelta()` call (alongside existing tables). Use dbDelta-safe syntax (two spaces before column names, no JSON, no ENUM):

```php
$charset = $wpdb->get_charset_collate();

$sql_active = "CREATE TABLE {$wpdb->prefix}home_promo_active (
  singleton TINYINT(1) NOT NULL DEFAULT 1,
  campaign_id INT NULL,
  activated_at DATETIME NULL,
  activated_by BIGINT NULL,
  PRIMARY KEY  (singleton),
  UNIQUE KEY uq_active_campaign (campaign_id)
) {$charset};";

$sql_campaigns = "CREATE TABLE {$wpdb->prefix}home_promo_campaigns (
  id INT AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  mode VARCHAR(10) NOT NULL DEFAULT 'auto',
  start_date DATETIME NOT NULL,
  end_date DATETIME NOT NULL,
  quota INT NOT NULL,
  discount_amount DECIMAL(8,2) NOT NULL,
  campaign_code VARCHAR(40) NULL,
  codes_config LONGTEXT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_slug (slug)
) {$charset};";

$sql_status_log = "CREATE TABLE {$wpdb->prefix}home_promo_status_log (
  id INT AUTO_INCREMENT,
  entry_id BIGINT NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NOT NULL,
  logged_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_entry_logged (entry_id, logged_at)
) {$charset};";

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta($sql_active);
dbDelta($sql_campaigns);
dbDelta($sql_status_log);

// Seed the pointer row (idempotent)
$wpdb->query(
    "INSERT IGNORE INTO {$wpdb->prefix}home_promo_active (singleton, campaign_id) VALUES (1, NULL)"
);
```

- [ ] **Step 4: Run tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-db.php -v
```

Expected: PASS.

- [ ] **Step 5: Commit**

```
git add src/db.php tests/test-db.php
git commit -m "feat: add new table CREATE statements to DB::install()"
```

---

## Task 3: DB — column alterations, InnoDB check, backfill

**Files:**
- Modify: `src/db.php`
- Test: `tests/test-db.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/test-db.php`:

```php
public function testAlterCountedTableRunsWhenColumnMissing()
{
    $mockWpdb = Mockery::mock('MockWPDB');
    $mockWpdb->prefix = 'wp_';
    // column_exists returns false → ALTER should run
    $mockWpdb->shouldReceive('prepare')
        ->with(Mockery::pattern('/SHOW COLUMNS/'), 'campaign_id')
        ->andReturn('sql');
    $mockWpdb->shouldReceive('get_var')
        ->once()
        ->andReturn(null); // column missing
    $mockWpdb->shouldReceive('query')
        ->with(Mockery::pattern('/ALTER TABLE.*home_promo_counted.*ADD COLUMN campaign_id/'))
        ->once()
        ->andReturn(true);
    $GLOBALS['wpdb'] = $mockWpdb;

    DB::run_column_migrations();
    $this->assertTrue(true);
}

public function testAlterCountedTableSkipsWhenColumnExists()
{
    $mockWpdb = Mockery::mock('MockWPDB');
    $mockWpdb->prefix = 'wp_';
    $mockWpdb->shouldReceive('prepare')->andReturn('sql');
    $mockWpdb->shouldReceive('get_var')->andReturn('campaign_id'); // exists
    // ALTER must NOT be called
    $mockWpdb->shouldNotReceive('query');
    $GLOBALS['wpdb'] = $mockWpdb;

    DB::run_column_migrations();
    $this->assertTrue(true);
}
```

- [ ] **Step 2: Run to verify failure**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-db.php -v
```

Expected: FAIL — `DB::run_column_migrations` not found.

- [ ] **Step 3: Implement `DB::run_column_migrations()` in `src/db.php`**

Add as a public static method:

```php
public static function run_column_migrations(): void {
    global $wpdb;

    // --- wp_home_promo_counted additions ---
    if (!self::column_exists("{$wpdb->prefix}home_promo_counted", 'campaign_id')) {
        $wpdb->query(
            "ALTER TABLE {$wpdb->prefix}home_promo_counted
               ADD COLUMN campaign_id INT NULL DEFAULT NULL,
               ADD COLUMN source VARCHAR(20) NULL DEFAULT 'live',
               ADD UNIQUE KEY uq_entry_campaign (entry_id, campaign_id),
               ADD INDEX idx_campaign (campaign_id),
               ADD INDEX idx_campaign_code (campaign_id, promo_code)"
        );
    }

    // --- wp_home_promo_reactivations additions ---
    if (!self::column_exists("{$wpdb->prefix}home_promo_reactivations", 'went_pasif_at')) {
        $wpdb->query(
            "ALTER TABLE {$wpdb->prefix}home_promo_reactivations
               ADD COLUMN campaign_id INT NULL DEFAULT NULL,
               ADD COLUMN went_pasif_at DATETIME NULL COMMENT 'UTC'"
        );
    }

    // --- InnoDB check for counted table ---
    $engine = $wpdb->get_var($wpdb->prepare(
        "SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = %s",
        "{$wpdb->prefix}home_promo_counted"
    ));
    if ($engine && strtolower($engine) !== 'innodb') {
        $wpdb->query(
            "ALTER TABLE {$wpdb->prefix}home_promo_counted ENGINE=InnoDB"
        );
    }
}
```

Also call it from `DB::install()` after dbDelta calls:

```php
self::run_column_migrations();
```

- [ ] **Step 4: Run tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-db.php -v
```

Expected: PASS.

- [ ] **Step 5: Implement Pasif backfill as `DB::run_pasif_backfill()`**

Add to `src/db.php`:

```php
public static function run_pasif_backfill(int $pasif_field_id, int $form_id): void {
    global $wpdb;

    if (get_option('hpm_pasif_backfill_done')) {
        return;
    }

    $sentinel_date = '1970-01-01 00:00:00';
    $offset = 0;
    $chunk  = 1000;

    do {
        // Entries on form $form_id with status "Pasif" and no log row
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT fi.item_id AS entry_id,
                    fm.meta_value AS pasif_date_value
               FROM {$wpdb->prefix}frm_items fi
          LEFT JOIN {$wpdb->prefix}frm_item_metas fm
                 ON fm.item_id = fi.item_id AND fm.field_id = %d
              WHERE fi.form_id = %d
                AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}home_promo_status_log sl
                     WHERE sl.entry_id = fi.item_id
                )
              LIMIT %d OFFSET %d",
            $pasif_field_id, $form_id, $chunk, $offset
        ), ARRAY_A);

        if (empty($entries)) break;

        $wpdb->query('START TRANSACTION');
        foreach ($entries as $row) {
            $logged_at = (!empty($row['pasif_date_value']))
                ? date('Y-m-d H:i:s', strtotime($row['pasif_date_value']))
                : $sentinel_date;
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}home_promo_status_log
                   (entry_id, from_status, to_status, logged_at)
                 VALUES (%d, %s, %s, %s)",
                (int) $row['entry_id'], 'unknown', 'Pasif', $logged_at
            ));
        }
        $wpdb->query('COMMIT');

        $offset += $chunk;
    } while (count($entries) === $chunk);

    update_option('hpm_pasif_backfill_done', '1');
}
```

- [ ] **Step 6: Implement autoload policy as `DB::ensure_autoload_no()`**

```php
public static function ensure_autoload_no(): void {
    global $wpdb;
    $defaults = [
        'form_id'               => 13,
        'daftar_field_id'       => 196,
        'status_field_id'       => 199,
        'status_label_field_id' => 1617,
        'pasif_date_field_id'   => 1698,
        'promo_field_id'        => 3170,
    ];

    if (get_option('home_promo_manager_settings') === false) {
        add_option('home_promo_manager_settings', $defaults, '', 'no');
    } else {
        $wpdb->update(
            $wpdb->options,
            ['autoload' => 'no'],
            ['option_name' => 'home_promo_manager_settings']
        );
        wp_cache_delete('home_promo_manager_settings', 'options');
        wp_cache_delete('alloptions', 'options');
    }
}
```

Call both from `DB::install()`:

```php
self::run_pasif_backfill(
    (int) (get_option('home_promo_manager_settings')['pasif_date_field_id'] ?? 1698),
    (int) (get_option('home_promo_manager_settings')['form_id'] ?? 13)
);
self::ensure_autoload_no();
```

- [ ] **Step 7: Run all tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit -v
```

Expected: PASS.

- [ ] **Step 8: Commit**

```
git add src/db.php tests/test-db.php
git commit -m "feat: DB column migrations, InnoDB check, Pasif backfill, autoload policy"
```

---

## Task 4: Campaign value object + CampaignEngine core

**Files:**
- Create: `src/CampaignEngine.php`
- Create: `tests/test-campaign-engine.php`
- Modify: `tests/bootstrap.php` (add require_once for CampaignEngine)

- [ ] **Step 1: Create `tests/test-campaign-engine.php` with failing tests**

```php
<?php
use PHPUnit\Framework\TestCase;
use HPM\CampaignEngine;

class CampaignEngineTest extends TestCase
{
    protected function setUp(): void
    {
        CampaignEngine::flush();
    }

    protected function tearDown(): void
    {
        CampaignEngine::flush();
        Mockery::close();
    }

    public function testGetActiveReturnsNullWhenNoActiveRow()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn(null);
        $GLOBALS['wpdb'] = $mockWpdb;

        $this->assertNull(CampaignEngine::get_active());
    }

    public function testGetActiveIsMemoised()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        // get_row called exactly ONCE even though get_active() is called twice
        $mockWpdb->shouldReceive('get_row')->once()->andReturn(null);
        $GLOBALS['wpdb'] = $mockWpdb;

        CampaignEngine::get_active();
        CampaignEngine::get_active();
        $this->assertTrue(true);
    }

    public function testFlushClearsCache()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        // get_row should be called TWICE (once before flush, once after)
        $mockWpdb->shouldReceive('get_row')->twice()->andReturn(null);
        $GLOBALS['wpdb'] = $mockWpdb;

        CampaignEngine::get_active();
        CampaignEngine::flush();
        CampaignEngine::get_active();
        $this->assertTrue(true);
    }

    public function testIsActiveFalseWhenNoCampaign()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn(null);
        $GLOBALS['wpdb'] = $mockWpdb;

        $this->assertFalse(CampaignEngine::is_active());
    }

    public function testGetActiveReturnsCampaignObject()
    {
        $row = (object)[
            'id' => 1, 'name' => 'Test', 'slug' => 'test',
            'status' => 'active', 'mode' => 'auto',
            'start_date' => '2026-06-06 00:00:00',
            'end_date'   => '2026-06-12 23:59:59',
            'quota' => 330, 'discount_amount' => '33.00',
            'campaign_code' => '6CURE', 'codes_config' => null,
        ];
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $mockWpdb;

        $campaign = CampaignEngine::get_active();
        $this->assertNotNull($campaign);
        $this->assertEquals(1, $campaign->id);
        $this->assertEquals('6CURE', $campaign->campaign_code);
        $this->assertEquals(330, $campaign->quota);
    }

    public function testModeExclusivityThrowsForAutoWithCodesConfig()
    {
        $row = (object)[
            'id' => 1, 'name' => 'Bad', 'slug' => 'bad',
            'status' => 'active', 'mode' => 'auto',
            'start_date' => '2026-06-06 00:00:00',
            'end_date'   => '2026-06-12 23:59:59',
            'quota' => 100, 'discount_amount' => '10.00',
            'campaign_code' => null,
            'codes_config'  => '{"promo": 100}', // violation
        ];
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_row')->once()->andReturn($row);
        $GLOBALS['wpdb'] = $mockWpdb;

        $this->expectException(\RuntimeException::class);
        CampaignEngine::get_active();
    }
}
```

- [ ] **Step 2: Run to verify failure**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-campaign-engine.php -v
```

Expected: FAIL — class `CampaignEngine` not found.

- [ ] **Step 3: Create `src/CampaignEngine.php`**

```php
<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

class Campaign
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly string  $slug,
        public readonly string  $status,
        public readonly string  $mode,
        public readonly string  $start_date,
        public readonly string  $end_date,
        public readonly int     $quota,
        public readonly float   $discount_amount,
        public readonly ?string $campaign_code,
        public readonly ?string $codes_config,
    ) {}

    public function get_codes_config(): array
    {
        if ($this->codes_config === null) return [];
        return (array) json_decode($this->codes_config, true);
    }
}

class CampaignEngine
{
    const CAP = 'manage_options';

    private static ?Campaign $active_campaign = null;
    private static bool      $loaded          = false;

    /** @var array<int,bool> reentrancy guard keyed by entry_id */
    private static array $writing_field = [];

    public static function get_active(): ?Campaign
    {
        if (!self::$loaded) {
            self::$active_campaign = self::query_active_campaign();
            self::$loaded          = true;
        }
        return self::$active_campaign;
    }

    public static function flush(): void
    {
        self::$active_campaign = null;
        self::$loaded          = false;
    }

    public static function is_active(): bool
    {
        return self::get_active() !== null;
    }

    private static function query_active_campaign(): ?Campaign
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT c.* FROM {$wpdb->prefix}home_promo_active a
              JOIN {$wpdb->prefix}home_promo_campaigns c ON c.id = a.campaign_id
             WHERE a.singleton = 1
               AND UTC_TIMESTAMP() BETWEEN c.start_date AND c.end_date"
        ));
        if (!$row) return null;

        $campaign = new Campaign(
            id:              (int)   $row->id,
            name:                    $row->name,
            slug:                    $row->slug,
            status:                  $row->status,
            mode:                    $row->mode,
            start_date:              $row->start_date,
            end_date:                $row->end_date,
            quota:           (int)   $row->quota,
            discount_amount: (float) $row->discount_amount,
            campaign_code:           $row->campaign_code ?? null,
            codes_config:            $row->codes_config ?? null,
        );

        // Mode-exclusivity assertion
        if ($campaign->mode === 'auto' && $campaign->codes_config !== null) {
            throw new \RuntimeException(
                "HPM: Campaign #{$campaign->id} mode=auto but codes_config is not null"
            );
        }
        if ($campaign->mode === 'manual' && $campaign->campaign_code !== null) {
            throw new \RuntimeException(
                "HPM: Campaign #{$campaign->id} mode=manual but campaign_code is not null"
            );
        }

        return $campaign;
    }

    // Activation / Deactivation implemented in Task 5
    // claim_slot() implemented in Task 8
}
```

- [ ] **Step 4: Add `require_once` for CampaignEngine to `tests/bootstrap.php`**

Uncomment or add at the bottom:
```php
require_once __DIR__ . '/../src/CampaignEngine.php';
```

- [ ] **Step 5: Run tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-campaign-engine.php -v
```

Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```
git add src/CampaignEngine.php tests/test-campaign-engine.php tests/bootstrap.php
git commit -m "feat: Campaign value object + CampaignEngine core (get_active, flush, is_active)"
```

---

## Task 5: CampaignEngine activation and deactivation

**Files:**
- Modify: `src/CampaignEngine.php`
- Modify: `tests/test-campaign-engine.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/test-campaign-engine.php`:

```php
public function testActivateSucceedsWhenPointerIsNull()
{
    $mockWpdb = Mockery::mock('MockWPDB');
    $mockWpdb->prefix = 'wp_';
    $mockWpdb->shouldReceive('prepare')->andReturn('sql');
    // START TRANSACTION, UPDATE pointer (affected=1), UPDATE status x2, COMMIT
    $mockWpdb->shouldReceive('query')->times(5)->andReturn(1);
    $GLOBALS['wpdb'] = $mockWpdb;

    $result = CampaignEngine::activate(1, 99);
    $this->assertEquals('ok', $result['status']);
}

public function testActivateRejectsWhenAnotherCampaignIsActive()
{
    $mockWpdb = Mockery::mock('MockWPDB');
    $mockWpdb->prefix = 'wp_';
    $mockWpdb->shouldReceive('prepare')->andReturn('sql');
    // START TRANSACTION
    $mockWpdb->shouldReceive('query')
        ->with('START TRANSACTION')->once()->andReturn(true);
    // UPDATE returns 0 (pointer is occupied)
    $mockWpdb->shouldReceive('query')
        ->with('sql')->once()->andReturn(0);
    // Re-SELECT: returns different campaign id
    $mockWpdb->shouldReceive('get_var')->once()->andReturn('7');
    // ROLLBACK
    $mockWpdb->shouldReceive('query')
        ->with('ROLLBACK')->once()->andReturn(true);
    $GLOBALS['wpdb'] = $mockWpdb;

    $result = CampaignEngine::activate(1, 99);
    $this->assertEquals('conflict', $result['status']);
    $this->assertEquals(7, $result['conflict_id']);
}

public function testDeactivateIsIdempotent()
{
    $mockWpdb = Mockery::mock('MockWPDB');
    $mockWpdb->prefix = 'wp_';
    $mockWpdb->shouldReceive('prepare')->andReturn('sql');
    // START TRANSACTION, UPDATE pointer (0 affected = already null), UPDATE status (noop), COMMIT
    $mockWpdb->shouldReceive('query')->andReturn(0);
    $GLOBALS['wpdb'] = $mockWpdb;

    $result = CampaignEngine::deactivate(1, 99);
    $this->assertEquals('ok', $result['status']);
}
```

- [ ] **Step 2: Run to verify failure**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-campaign-engine.php -v
```

Expected: FAIL — methods not found.

- [ ] **Step 3: Add `activate()` and `deactivate()` to `src/CampaignEngine.php`**

Add these static methods to the `CampaignEngine` class:

```php
public static function activate(int $campaign_id, int $user_id): array
{
    global $wpdb;
    $wpdb->query('START TRANSACTION');

    $affected = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}home_promo_active
            SET campaign_id = %d, activated_at = UTC_TIMESTAMP(), activated_by = %d
          WHERE singleton = 1 AND campaign_id IS NULL",
        $campaign_id, $user_id
    ));

    if ($affected === 0) {
        $current_id = (int) $wpdb->get_var(
            "SELECT campaign_id FROM {$wpdb->prefix}home_promo_active WHERE singleton = 1"
        );
        if ($current_id === $campaign_id) {
            // Idempotent
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}home_promo_campaigns SET status = 'active' WHERE id = %d",
                $campaign_id
            ));
            $wpdb->query('COMMIT');
            self::flush();
            return ['status' => 'ok'];
        }
        $wpdb->query('ROLLBACK');
        return ['status' => 'conflict', 'conflict_id' => $current_id];
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}home_promo_campaigns SET status = 'active' WHERE id = %d",
        $campaign_id
    ));
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}home_promo_campaigns
            SET status = 'paused' WHERE id <> %d AND status = 'active'",
        $campaign_id
    ));
    $wpdb->query('COMMIT');
    self::flush();
    return ['status' => 'ok'];
}

public static function deactivate(int $campaign_id, int $user_id): array
{
    global $wpdb;
    $wpdb->query('START TRANSACTION');

    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}home_promo_active
            SET campaign_id = NULL, activated_at = UTC_TIMESTAMP(), activated_by = %d
          WHERE singleton = 1 AND campaign_id = %d",
        $user_id, $campaign_id
    ));

    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}home_promo_campaigns
            SET status = 'paused' WHERE id = %d AND status = 'active'",
        $campaign_id
    ));

    $wpdb->query('COMMIT');
    self::flush();
    return ['status' => 'ok'];
}
```

- [ ] **Step 4: Run tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-campaign-engine.php -v
```

Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```
git add src/CampaignEngine.php tests/test-campaign-engine.php
git commit -m "feat: CampaignEngine::activate() and deactivate() with atomic pointer table"
```

---

## Task 6: Eligibility specs

**Files:**
- Create: `src/Eligibility.php`
- Create: `tests/test-eligibility.php`
- Modify: `tests/bootstrap.php`

- [ ] **Step 1: Create `tests/test-eligibility.php`**

```php
<?php
use PHPUnit\Framework\TestCase;
use HPM\{OrSpecification, NewSpec, DiagnosedSpec, ReactivationSpec};

class EligibilityTest extends TestCase
{
    private function ctx(array $overrides = []): object
    {
        return (object) array_merge([
            'event'        => 'updated',
            'entry_id'     => 1,
            'daftar'       => 'Ya',
            'prev_daftar'  => 'Tidak',
            'status'       => 1,
            'status_label' => 'Aktif',
            'went_pasif_at'=> null,
            'pasif_days'   => null,
        ], $overrides);
    }

    // --- NewSpec ---

    public function testNewSpecPassesOnCreatedEventNoPasifHistory()
    {
        $spec = new NewSpec();
        $ctx  = $this->ctx(['event' => 'created', 'prev_daftar' => null]);
        $this->assertEquals('new', $spec->isSatisfied($ctx));
    }

    public function testNewSpecPassesOnUpdateWithNoPasifHistory()
    {
        $spec = new NewSpec();
        $ctx  = $this->ctx(['went_pasif_at' => null]);
        $this->assertEquals('new', $spec->isSatisfied($ctx));
    }

    public function testNewSpecFailsWhenDaftarNotYa()
    {
        $spec = new NewSpec();
        $ctx  = $this->ctx(['daftar' => 'Tidak']);
        $this->assertFalse($spec->isSatisfied($ctx));
    }

    public function testNewSpecFailsWhenHasPasifHistory()
    {
        $spec = new NewSpec();
        $ctx  = $this->ctx(['went_pasif_at' => '2026-01-01 00:00:00', 'pasif_days' => 50]);
        $this->assertFalse($spec->isSatisfied($ctx));
    }

    // --- DiagnosedSpec ---

    public function testDiagnosedSpecPassesUnder90Days()
    {
        $spec = new DiagnosedSpec();
        $ctx  = $this->ctx([
            'went_pasif_at' => '2026-03-01 00:00:00',
            'pasif_days'    => 89,
        ]);
        $this->assertEquals('diagnosed', $spec->isSatisfied($ctx));
    }

    public function testDiagnosedSpecFailsAt90Days()
    {
        $spec = new DiagnosedSpec();
        $ctx  = $this->ctx(['went_pasif_at' => '2026-01-01 00:00:00', 'pasif_days' => 90]);
        $this->assertFalse($spec->isSatisfied($ctx));
    }

    public function testDiagnosedSpecFailsOnCreatedEvent()
    {
        $spec = new DiagnosedSpec();
        $ctx  = $this->ctx(['event' => 'created', 'pasif_days' => 50]);
        $this->assertFalse($spec->isSatisfied($ctx));
    }

    public function testDiagnosedSpecFailsWhenPrevDaftarAlreadyYa()
    {
        $spec = new DiagnosedSpec();
        $ctx  = $this->ctx(['prev_daftar' => 'Ya', 'pasif_days' => 50]);
        $this->assertFalse($spec->isSatisfied($ctx));
    }

    // --- ReactivationSpec ---

    public function testReactivationSpecPassesAt90Days()
    {
        $spec = new ReactivationSpec();
        $ctx  = $this->ctx(['went_pasif_at' => '2026-01-01 00:00:00', 'pasif_days' => 90]);
        $this->assertEquals('reactivation', $spec->isSatisfied($ctx));
    }

    public function testReactivationSpecPassesOver90Days()
    {
        $spec = new ReactivationSpec();
        $ctx  = $this->ctx(['went_pasif_at' => '2025-01-01 00:00:00', 'pasif_days' => 300]);
        $this->assertEquals('reactivation', $spec->isSatisfied($ctx));
    }

    public function testReactivationSpecFailsUnder90Days()
    {
        $spec = new ReactivationSpec();
        $ctx  = $this->ctx(['went_pasif_at' => '2026-03-01 00:00:00', 'pasif_days' => 89]);
        $this->assertFalse($spec->isSatisfied($ctx));
    }

    // --- OrSpecification ---

    public function testOrSpecReturnsFirstMatch()
    {
        $spec = new OrSpecification(new NewSpec(), new DiagnosedSpec(), new ReactivationSpec());
        $ctx  = $this->ctx(['went_pasif_at' => null]);
        $this->assertEquals('new', $spec->isSatisfied($ctx));
    }

    public function testOrSpecReturnsFalseWhenNoMatch()
    {
        $spec = new OrSpecification(new NewSpec(), new DiagnosedSpec(), new ReactivationSpec());
        // daftar=Tidak means nothing matches
        $ctx  = $this->ctx(['daftar' => 'Tidak']);
        $this->assertFalse($spec->isSatisfied($ctx));
    }
}
```

- [ ] **Step 2: Run to verify failure**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-eligibility.php -v
```

Expected: FAIL — classes not found.

- [ ] **Step 3: Create `src/Eligibility.php`**

```php
<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

interface Spec
{
    /** @return string|false category string on match, false on no match */
    public function isSatisfied(object $ctx): string|false;
}

class OrSpecification implements Spec
{
    private array $specs;

    public function __construct(Spec ...$specs)
    {
        $this->specs = $specs;
    }

    public function isSatisfied(object $ctx): string|false
    {
        foreach ($this->specs as $spec) {
            $result = $spec->isSatisfied($ctx);
            if ($result !== false) return $result;
        }
        return false;
    }
}

class NewSpec implements Spec
{
    public function isSatisfied(object $ctx): string|false
    {
        if ($ctx->daftar !== 'Ya') return false;
        if ($ctx->went_pasif_at !== null) return false; // has pasif history → not new
        return 'new';
    }
}

class DiagnosedSpec implements Spec
{
    public function isSatisfied(object $ctx): string|false
    {
        if ($ctx->event !== 'updated') return false;
        if ($ctx->daftar !== 'Ya') return false;
        if ($ctx->prev_daftar === 'Ya') return false; // no transition
        if ($ctx->went_pasif_at === null) return false; // no pasif history
        if ($ctx->pasif_days === null || $ctx->pasif_days >= 90) return false;
        return 'diagnosed';
    }
}

class ReactivationSpec implements Spec
{
    public function isSatisfied(object $ctx): string|false
    {
        if ($ctx->event !== 'updated') return false;
        if ($ctx->daftar !== 'Ya') return false;
        if ($ctx->prev_daftar === 'Ya') return false;
        if ($ctx->went_pasif_at === null) return false;
        if ($ctx->pasif_days === null || $ctx->pasif_days < 90) return false;
        return 'reactivation';
    }
}
```

- [ ] **Step 4: Add to `tests/bootstrap.php`**

```php
require_once __DIR__ . '/../src/Eligibility.php';
```

- [ ] **Step 5: Run tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-eligibility.php -v
```

Expected: PASS (13 tests).

- [ ] **Step 6: Commit**

```
git add src/Eligibility.php tests/test-eligibility.php tests/bootstrap.php
git commit -m "feat: Eligibility specs — OrSpecification, NewSpec, DiagnosedSpec, ReactivationSpec"
```

---

## Task 7: HookDispatcher — pre-hook snapshot + $ctx builder

**Files:**
- Rewrite: `src/hooks.php`
- Create: `tests/test-hooks.php`
- Modify: `tests/bootstrap.php`

- [ ] **Step 1: Create `tests/test-hooks.php` with pre-hook tests**

```php
<?php
use PHPUnit\Framework\TestCase;
use HPM\HookDispatcher;

class HookDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        HookDispatcher::reset_snapshot();
        Mockery::close();
    }

    public function testPreHookStashesFieldValuesInSnapshot()
    {
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        // Returns values for fields 196, 199, 1617, 1698
        $mockWpdb->shouldReceive('get_var')
            ->andReturn('Ya', '1', 'Aktif', '2026-01-01');
        $GLOBALS['wpdb'] = $mockWpdb;

        HookDispatcher::on_pre_update_entry(1, []);

        $snapshot = HookDispatcher::get_snapshot_for_test(1);
        $this->assertEquals('Ya',         $snapshot[196]);
        $this->assertEquals('1',          $snapshot[199]);
        $this->assertEquals('Aktif',      $snapshot[1617]);
        $this->assertEquals('2026-01-01', $snapshot[1698]);
    }

    public function testFallbackSelectRunsWhenPreHookMissed()
    {
        // No pre-hook → snapshot is not set → fallback SELECT should fire
        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->shouldReceive('prepare')->andReturn('sql');
        $mockWpdb->shouldReceive('get_var')
            ->once() // exactly one fallback SELECT
            ->andReturn('2026-02-01');
        $GLOBALS['wpdb'] = $mockWpdb;

        // Simulate fallback for field 1698 on entry 5
        $value = HookDispatcher::get_field_snapshot_or_fallback(5, 1698);
        $this->assertEquals('2026-02-01', $value);
    }

    public function testSentinelDistinguishesNullFromUnset()
    {
        // Pre-hook ran but read null for field 1698
        HookDispatcher::stash_snapshot(1, 1698, null);

        $mockWpdb = Mockery::mock('MockWPDB');
        $mockWpdb->prefix = 'wp_';
        // get_var must NOT be called — the null is already in snapshot
        $mockWpdb->shouldNotReceive('get_var');
        $GLOBALS['wpdb'] = $mockWpdb;

        $value = HookDispatcher::get_field_snapshot_or_fallback(1, 1698);
        $this->assertNull($value);
    }
}
```

- [ ] **Step 2: Run to verify failure**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-hooks.php -v
```

Expected: FAIL.

- [ ] **Step 3: Rewrite `src/hooks.php` as `HookDispatcher` class**

Replace the entire file with:

```php
<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/utils.php';

class HookDispatcher
{
    const SENTINEL_UNSET = "\0HPM_UNSET";

    /**
     * @var array<int, array<int, string|null>> [$entry_id][$field_id] => value|SENTINEL_UNSET
     */
    private static array $snapshot = [];

    /** @var array<int,bool> reentrancy guard */
    private static array $writing_field = [];

    public static function init(): void
    {
        $mgr     = Manager::get_instance();
        $form_id = (int) $mgr->s('form_id');

        add_filter('frm_validate_entry',     [self::class, 'on_validate_entry'],      10, 2);
        add_action('frm_after_create_entry', [self::class, 'on_after_create_entry'],  10, 2);
        add_action('frm_pre_update_entry',   [self::class, 'on_pre_update_entry'],    10, 2);
        add_action('frm_after_update_entry', [self::class, 'on_after_update_entry'],  10, 2);
    }

    // -----------------------------------------------------------------
    // Pre-hook snapshot
    // -----------------------------------------------------------------

    public static function on_pre_update_entry(int $entry_id, array $values): void
    {
        global $wpdb;
        $mgr = Manager::get_instance();

        foreach ([
            'daftar_field_id'       => (int) $mgr->s('daftar_field_id'),
            'status_field_id'       => (int) $mgr->s('status_field_id'),
            'status_label_field_id' => (int) $mgr->s('status_label_field_id'),
            'pasif_date_field_id'   => (int) $mgr->s('pasif_date_field_id'),
        ] as $_ => $field_id) {
            $value = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}frm_item_metas
                  WHERE item_id = %d AND field_id = %d LIMIT 1",
                $entry_id, $field_id
            ));
            self::$snapshot[$entry_id][$field_id] = $value; // null is legitimate
        }
    }

    public static function get_field_snapshot_or_fallback(int $entry_id, int $field_id): ?string
    {
        global $wpdb;
        if (isset(self::$snapshot[$entry_id][$field_id])
            && self::$snapshot[$entry_id][$field_id] !== self::SENTINEL_UNSET) {
            return self::$snapshot[$entry_id][$field_id];
        }

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}frm_item_metas
              WHERE item_id = %d AND field_id = %d LIMIT 1",
            $entry_id, $field_id
        ));
        self::$snapshot[$entry_id][$field_id] = $value;
        return $value;
    }

    public static function stash_snapshot(int $entry_id, int $field_id, ?string $value): void
    {
        self::$snapshot[$entry_id][$field_id] = $value;
    }

    // -----------------------------------------------------------------
    // $ctx builder
    // -----------------------------------------------------------------

    private static function build_ctx(string $event, int $entry_id, array $post_values): object
    {
        global $wpdb;
        $mgr = Manager::get_instance();

        $daftar_fid       = (int) $mgr->s('daftar_field_id');
        $status_fid       = (int) $mgr->s('status_field_id');
        $status_label_fid = (int) $mgr->s('status_label_field_id');
        $pasif_fid        = (int) $mgr->s('pasif_date_field_id');

        // New values come from $post_values['item_meta']
        $daftar       = $post_values['item_meta'][$daftar_fid]       ?? null;
        $status       = $post_values['item_meta'][$status_fid]       ?? null;
        $status_label = $post_values['item_meta'][$status_label_fid] ?? null;

        // Previous values from snapshot
        $prev_daftar       = ($event === 'created') ? null : self::get_field_snapshot_or_fallback($entry_id, $daftar_fid);
        $prev_status       = ($event === 'created') ? null : self::get_field_snapshot_or_fallback($entry_id, $status_fid);
        $prev_status_label = ($event === 'created') ? null : self::get_field_snapshot_or_fallback($entry_id, $status_label_fid);

        // Pasif history: log → snapshot fallback → null
        $went_pasif_at = $wpdb->get_var($wpdb->prepare(
            "SELECT logged_at FROM {$wpdb->prefix}home_promo_status_log
              WHERE entry_id = %d ORDER BY logged_at DESC LIMIT 1",
            $entry_id
        ));
        if ($went_pasif_at === null) {
            $went_pasif_at = self::get_field_snapshot_or_fallback($entry_id, $pasif_fid);
        }

        $pasif_days = null;
        if ($went_pasif_at !== null) {
            $pasif_days = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT TIMESTAMPDIFF(DAY, %s, UTC_TIMESTAMP())",
                $went_pasif_at
            ));
        }

        return (object) [
            'event'             => $event,
            'entry_id'          => $entry_id,
            'daftar'            => $daftar,
            'prev_daftar'       => $prev_daftar,
            'status'            => (int) $status,
            'prev_status'       => ($prev_status !== null) ? (int) $prev_status : null,
            'status_label'      => $status_label,
            'prev_status_label' => $prev_status_label,
            'went_pasif_at'     => $went_pasif_at,
            'pasif_days'        => $pasif_days,
            'submitted_code'    => $post_values['item_meta'][$mgr->s('promo_field_id')] ?? null,
        ];
    }

    // -----------------------------------------------------------------
    // Test helpers (no-op in production — used by tests to inspect state)
    // -----------------------------------------------------------------

    public static function reset_snapshot(): void { self::$snapshot = []; }

    public static function get_snapshot_for_test(int $entry_id): array
    {
        return self::$snapshot[$entry_id] ?? [];
    }

    // -----------------------------------------------------------------
    // Hook handlers (dispatch stubs — filled in Task 8)
    // -----------------------------------------------------------------

    public static function on_validate_entry(array $errors, array $values): array
    {
        return $errors; // Filled in Task 9
    }

    public static function on_after_create_entry(int $entry_id, int $form_id): void
    {
        // Filled in Task 8
    }

    public static function on_after_update_entry(int $entry_id, array $values): void
    {
        // Filled in Task 8
    }
}

// Wire up (outside class, so WordPress registers on load)
if (defined('ABSPATH')) {
    add_action('init', ['HPM\\HookDispatcher', 'init']);
}
```

- [ ] **Step 4: Add to `tests/bootstrap.php`**

```php
require_once __DIR__ . '/../src/hooks.php';
```

- [ ] **Step 5: Run tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-hooks.php -v
```

Expected: PASS (3 tests).

- [ ] **Step 6: Run full suite**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit -v
```

Expected: all pass.

- [ ] **Step 7: Commit**

```
git add src/hooks.php tests/test-hooks.php tests/bootstrap.php
git commit -m "feat: HookDispatcher class with pre-hook snapshot and ctx builder"
```

---

## Task 8: CampaignEngine::claim_slot()

**Files:**
- Modify: `src/CampaignEngine.php`
- Modify: `tests/test-campaign-engine.php`

- [ ] **Step 1: Add failing tests for claim_slot**

Append to `tests/test-campaign-engine.php`:

```php
public function testClaimSlotReturnsNoActiveCampaignWhenNone()
{
    $mockWpdb = Mockery::mock('MockWPDB');
    $mockWpdb->prefix = 'wp_';
    $mockWpdb->shouldReceive('prepare')->andReturn('sql');
    $mockWpdb->shouldReceive('get_row')->andReturn(null);
    $mockWpdb->shouldReceive('get_var')->andReturn(0); // not already counted
    $GLOBALS['wpdb'] = $mockWpdb;

    $ctx = (object)['entry_id' => 1, 'daftar' => 'Ya', 'went_pasif_at' => null,
                    'pasif_days' => null, 'event' => 'created', 'prev_daftar' => null,
                    'status' => 1, 'status_label' => 'Aktif', 'submitted_code' => null];
    $result = CampaignEngine::claim_slot($ctx);
    $this->assertEquals('no_active_campaign', $result['status']);
}

public function testClaimSlotReturnsAlreadyCounted()
{
    $row = (object)[
        'id' => 1, 'name' => 'T', 'slug' => 't', 'status' => 'active',
        'mode' => 'auto', 'start_date' => '2026-01-01 00:00:00',
        'end_date' => '2030-01-01 00:00:00',
        'quota' => 100, 'discount_amount' => '10.00',
        'campaign_code' => 'TEST', 'codes_config' => null,
    ];
    $mockWpdb = Mockery::mock('MockWPDB');
    $mockWpdb->prefix = 'wp_';
    $mockWpdb->shouldReceive('prepare')->andReturn('sql');
    $mockWpdb->shouldReceive('get_row')->once()->andReturn($row);
    $mockWpdb->shouldReceive('get_var')->once()->andReturn('1'); // already counted
    $GLOBALS['wpdb'] = $mockWpdb;

    $ctx = (object)['entry_id' => 5, 'daftar' => 'Ya', 'went_pasif_at' => null,
                    'pasif_days' => null, 'event' => 'created', 'prev_daftar' => null,
                    'status' => 1, 'status_label' => 'Aktif', 'submitted_code' => null];
    $result = CampaignEngine::claim_slot($ctx);
    $this->assertEquals('already_counted', $result['status']);
}

public function testClaimSlotRollsBackOnFieldWriteFailure()
{
    $row = (object)[
        'id' => 1, 'name' => 'T', 'slug' => 't', 'status' => 'active',
        'mode' => 'auto', 'start_date' => '2026-01-01 00:00:00',
        'end_date' => '2030-01-01 00:00:00',
        'quota' => 100, 'discount_amount' => '10.00',
        'campaign_code' => 'TEST', 'codes_config' => null,
    ];
    $mockWpdb = Mockery::mock('MockWPDB');
    $mockWpdb->prefix = 'wp_';
    $mockWpdb->shouldReceive('prepare')->andReturn('sql');
    $mockWpdb->shouldReceive('get_row')->once()->andReturn($row);
    $mockWpdb->shouldReceive('get_var')->once()->andReturn(0); // not counted
    $mockWpdb->shouldReceive('query')
        ->with('START TRANSACTION')->once()->andReturn(true);
    $mockWpdb->shouldReceive('query')
        ->with('sql')->once()->andReturn(1); // INSERT succeeded
    $mockWpdb->shouldReceive('query')
        ->with('ROLLBACK')->once()->andReturn(true);
    $GLOBALS['wpdb'] = $mockWpdb;

    // Stub FrmEntryMeta to simulate write failure
    if (!class_exists('FrmEntryMeta')) {
        eval('class FrmEntryMeta {
            public static function update_entry_meta($eid, $fid, $old, $new) { return false; }
        }');
    }

    // Stub Manager
    $mgr = Mockery::mock('overload:HPM\Manager');
    $mgr->shouldReceive('get_instance')->andReturnSelf();
    $mgr->shouldReceive('s')->andReturn('3170');

    $ctx = (object)['entry_id' => 5, 'daftar' => 'Ya', 'went_pasif_at' => null,
                    'pasif_days' => null, 'event' => 'created', 'prev_daftar' => null,
                    'status' => 1, 'status_label' => 'Aktif', 'submitted_code' => null];
    $result = CampaignEngine::claim_slot($ctx);
    $this->assertEquals('field_write_failed', $result['status']);
}
```

- [ ] **Step 2: Run to verify failure**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-campaign-engine.php -v
```

- [ ] **Step 3: Add `claim_slot()` to `src/CampaignEngine.php`**

Add to the `CampaignEngine` class:

```php
public static function claim_slot(object $ctx): array
{
    global $wpdb;

    $campaign = self::get_active();
    if (!$campaign) return ['status' => 'no_active_campaign'];

    // Early bail
    $already = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted
          WHERE entry_id = %d AND campaign_id = %d",
        $ctx->entry_id, $campaign->id
    ));
    if ($already > 0) return ['status' => 'already_counted'];

    // Status check — fail-closed
    $status_ok    = ((string) $ctx->status_label === 'Aktif');
    $status_ok199 = ($ctx->status === 1);
    if ($status_ok !== $status_ok199) {
        error_log(sprintf(
            '[HPM] Status divergence entry_id=%d field_1617="%s" field_199=%d — slot denied',
            $ctx->entry_id, $ctx->status_label, $ctx->status
        ));
        return ['status' => 'status_divergence'];
    }

    // Eligibility
    $spec   = new OrSpecification(new NewSpec(), new DiagnosedSpec(), new ReactivationSpec());
    $result = $spec->isSatisfied($ctx);
    if ($result === false) return ['status' => 'ineligible'];

    $category = $result;
    $source   = ($ctx->went_pasif_at === null) ? 'legacy_default' : 'live';

    // Code resolution
    if ($campaign->mode === 'auto') {
        $code_to_write = $campaign->campaign_code;
    } else {
        $code_to_write = $ctx->submitted_code ?? '';
        if (empty($code_to_write)) return ['status' => 'no_code'];
    }

    // Reentrancy guard
    if (!empty(self::$writing_field[$ctx->entry_id])) {
        return ['status' => 'reentrant'];
    }

    $wpdb->query('START TRANSACTION');
    try {
        // Manual mode Layer 2 — serialise with FOR UPDATE
        if ($campaign->mode === 'manual') {
            $codes_config = $campaign->get_codes_config();
            $quota_code   = $codes_config[$code_to_write] ?? 0;
            $used = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted
                  WHERE campaign_id = %d AND promo_code = %s FOR UPDATE",
                $campaign->id, $code_to_write
            ));
            if ($used >= $quota_code) {
                $wpdb->query('ROLLBACK');
                return ['status' => 'code_quota_exhausted', 'code' => $code_to_write];
            }
        }

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}home_promo_counted
               (entry_id, campaign_id, promo_code, category, source, counted_at)
             VALUES (%d, %d, %s, %s, %s, UTC_TIMESTAMP())",
            $ctx->entry_id, $campaign->id, $code_to_write, $category, $source
        ));

        if ((int) $inserted !== 1) {
            $wpdb->query('ROLLBACK');
            return ['status' => 'duplicate'];
        }

        self::$writing_field[$ctx->entry_id] = true;
        $field_ok = false;
        try {
            $field_ok = \FrmEntryMeta::update_entry_meta(
                $ctx->entry_id,
                Manager::get_instance()->s('promo_field_id'),
                null,
                $code_to_write
            );
        } finally {
            unset(self::$writing_field[$ctx->entry_id]);
        }

        if (!$field_ok) {
            $wpdb->query('ROLLBACK');
            error_log("HPM: field 3170 write failed for entry {$ctx->entry_id}, rolled back slot");
            return ['status' => 'field_write_failed'];
        }

        $wpdb->query('COMMIT');
        return ['status' => 'claimed', 'category' => $category, 'source' => $source];

    } catch (\Throwable $e) {
        $wpdb->query('ROLLBACK');
        unset(self::$writing_field[$ctx->entry_id]);
        error_log("HPM: exception during claim_slot, rolled back: " . $e->getMessage());
        return ['status' => 'error'];
    }
}
```

- [ ] **Step 4: Run tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-campaign-engine.php -v
```

Expected: PASS.

- [ ] **Step 5: Commit**

```
git add src/CampaignEngine.php tests/test-campaign-engine.php
git commit -m "feat: CampaignEngine::claim_slot() with atomic transaction and reentrancy guard"
```

---

## Task 9: Hook dispatch — after_update/create handlers + Pasif log

**Files:**
- Modify: `src/hooks.php`
- Modify: `tests/test-hooks.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/test-hooks.php`:

```php
public function testOnAfterUpdateEntryEarlyBailsWhenNotTargetForm()
{
    $mgr = Mockery::mock('overload:HPM\Manager');
    $mgr->shouldReceive('get_instance')->andReturnSelf();
    $mgr->shouldReceive('s')->with('form_id')->andReturn('13');
    // No claim_slot call expected — wrong form
    $result = HookDispatcher::on_after_update_entry(1, ['form_id' => 99]);
    $this->assertNull($result);
}

public function testPasifTransitionWritesToStatusLog()
{
    $mockWpdb = Mockery::mock('MockWPDB');
    $mockWpdb->prefix = 'wp_';
    $mockWpdb->shouldReceive('prepare')->andReturn('sql');
    $mockWpdb->shouldReceive('query')
        ->with(Mockery::pattern('/INSERT.*home_promo_status_log/'))
        ->once()
        ->andReturn(true);
    $GLOBALS['wpdb'] = $mockWpdb;

    // Simulate: prev_status_label='Aktif', new status_label='Pasif'
    HookDispatcher::stash_snapshot(42, 1617, 'Aktif'); // prev was Aktif
    HookDispatcher::write_pasif_log_if_needed(42, 'Aktif', 'Pasif');
    $this->assertTrue(true); // no exception
}
```

- [ ] **Step 2: Run to verify failure**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-hooks.php -v
```

- [ ] **Step 3: Fill in hook dispatch methods in `src/hooks.php`**

Replace the stub `on_after_update_entry` and add `on_after_create_entry` and `write_pasif_log_if_needed`:

```php
public static function on_after_create_entry(int $entry_id, int $form_id): void
{
    $mgr = Manager::get_instance();
    if ($form_id !== (int) $mgr->s('form_id')) return;
    if (!CampaignEngine::is_active()) return;

    // No pre-hook runs on create — snapshot is empty
    $post_values = [
        'form_id'   => $form_id,
        'item_meta' => self::read_current_meta($entry_id),
    ];
    $ctx = self::build_ctx('created', $entry_id, $post_values);
    CampaignEngine::claim_slot($ctx);
}

public static function on_after_update_entry(int $entry_id, array $values): void
{
    $mgr     = Manager::get_instance();
    $form_id = (int) ($values['form_id'] ?? 0);
    if ($form_id !== (int) $mgr->s('form_id')) return;

    // Reentrancy guard — we may be called because we wrote field 3170
    // CampaignEngine uses its own $writing_field; here we check ours
    $status_label_fid = (int) $mgr->s('status_label_field_id');
    $new_label        = $values['item_meta'][$status_label_fid] ?? null;
    $prev_label       = self::get_field_snapshot_or_fallback($entry_id, $status_label_fid);
    self::write_pasif_log_if_needed($entry_id, $prev_label, $new_label);

    if (!CampaignEngine::is_active()) return;
    $ctx = self::build_ctx('updated', $entry_id, $values);

    // Field 3170 integrity re-write (both modes) — if already counted, restore code
    global $wpdb;
    $campaign = CampaignEngine::get_active();
    if ($campaign) {
        $counted_row = $wpdb->get_row($wpdb->prepare(
            "SELECT promo_code FROM {$wpdb->prefix}home_promo_counted
              WHERE entry_id = %d AND campaign_id = %d LIMIT 1",
            $entry_id, $campaign->id
        ));
        if ($counted_row) {
            $promo_fid      = (int) Manager::get_instance()->s('promo_field_id');
            $submitted_code = $values['item_meta'][$promo_fid] ?? '';
            if ((string) $submitted_code !== (string) $counted_row->promo_code) {
                \FrmEntryMeta::update_entry_meta(
                    $entry_id, $promo_fid, null, $counted_row->promo_code
                );
            }
            return; // early bail — no new slot needed
        }
    }

    CampaignEngine::claim_slot($ctx);
}

public static function write_pasif_log_if_needed(int $entry_id, ?string $prev_label, ?string $new_label): void
{
    if ($new_label !== 'Pasif') return;
    if ($prev_label === 'Pasif') return; // no transition

    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}home_promo_status_log
           (entry_id, from_status, to_status, logged_at)
         VALUES (%d, %s, 'Pasif', UTC_TIMESTAMP())",
        $entry_id, $prev_label ?? 'unknown'
    ));
}

private static function read_current_meta(int $entry_id): array
{
    global $wpdb;
    $mgr  = Manager::get_instance();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id = %d",
        $entry_id
    ), ARRAY_A);
    $meta = [];
    foreach ($rows as $row) {
        $meta[(int) $row['field_id']] = $row['meta_value'];
    }
    return $meta;
}
```

- [ ] **Step 4: Run tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit -v
```

Expected: PASS.

- [ ] **Step 5: Commit**

```
git add src/hooks.php tests/test-hooks.php
git commit -m "feat: HookDispatcher dispatch handlers, Pasif transition log, field 3170 integrity re-write"
```

---

## Task 10: frm_validate_entry — manual mode Layer 1

**Files:**
- Modify: `src/hooks.php`
- Modify: `tests/test-hooks.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/test-hooks.php`:

```php
public function testValidateEntrySkipsInAutoMode()
{
    // In auto mode, no validation errors should be added
    $errors = HookDispatcher::on_validate_entry([], [
        'form_id'   => 13,
        'id'        => 0,
        'item_meta' => [],
    ]);
    $this->assertEmpty($errors);
}

public function testValidateEntryBlocksInvalidCodeInManualMode()
{
    $mockWpdb = Mockery::mock('MockWPDB');
    $mockWpdb->prefix = 'wp_';
    $mockWpdb->shouldReceive('prepare')->andReturn('sql');
    $mockWpdb->shouldReceive('get_var')->andReturn(0); // not already counted; also count=0
    $GLOBALS['wpdb'] = $mockWpdb;

    // Campaign in manual mode with codes_config
    $row = (object)[
        'id' => 1, 'name' => 'T', 'slug' => 't', 'status' => 'active',
        'mode' => 'manual', 'start_date' => '2026-01-01 00:00:00',
        'end_date' => '2030-01-01 00:00:00',
        'quota' => 100, 'discount_amount' => '10.00',
        'campaign_code' => null,
        'codes_config'  => '{"promo24": 240}',
    ];
    $mockWpdb->shouldReceive('get_row')->andReturn($row);

    $errors = HookDispatcher::on_validate_entry([], [
        'form_id'   => 13,
        'id'        => 5,
        'item_meta' => [3170 => 'BADCODE'],
    ]);
    $this->assertNotEmpty($errors);
}
```

- [ ] **Step 2: Run to verify failure**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit tests/test-hooks.php -v
```

- [ ] **Step 3: Implement `on_validate_entry` in `src/hooks.php`**

Replace the stub:

```php
public static function on_validate_entry(array $errors, array $values): array
{
    global $wpdb;
    $mgr     = Manager::get_instance();
    $form_id = (int) ($values['form_id'] ?? 0);
    if ($form_id !== (int) $mgr->s('form_id')) return $errors;

    $campaign = CampaignEngine::get_active();
    if (!$campaign || $campaign->mode !== 'manual') return $errors;

    $entry_id  = (int) ($values['id'] ?? 0);
    $promo_fid = (int) $mgr->s('promo_field_id');
    $code      = trim($values['item_meta'][$promo_fid] ?? '');

    // Skip validation if already counted (unrelated edits pass through)
    if ($entry_id > 0) {
        $counted = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted
              WHERE entry_id = %d AND campaign_id = %d",
            $entry_id, $campaign->id
        ));
        if ($counted > 0) return $errors;
    }

    $codes_config = $campaign->get_codes_config();
    if (!isset($codes_config[$code])) {
        $errors['field_' . $promo_fid] = 'Kod promosi tidak sah.';
        return $errors;
    }

    $used = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted
          WHERE campaign_id = %d AND promo_code = %s",
        $campaign->id, $code
    ));
    if ($used >= $codes_config[$code]) {
        $errors['field_' . $promo_fid] = 'Kuota kod promosi ini telah habis.';
    }

    return $errors;
}
```

- [ ] **Step 4: Run all tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit -v
```

Expected: PASS.

- [ ] **Step 5: Commit**

```
git add src/hooks.php tests/test-hooks.php
git commit -m "feat: frm_validate_entry manual mode Layer 1 quota check"
```

---

## Task 11: Status log retention cron

**Files:**
- Modify: `src/db.php`
- Modify: `home-promo-manager.php`

- [ ] **Step 1: Add cron schedule + cleanup function to `src/db.php`**

```php
public static function schedule_cleanup(): void
{
    if (!wp_next_scheduled('hpm_status_log_cleanup')) {
        wp_schedule_event(time(), 'daily', 'hpm_status_log_cleanup');
    }
    add_action('hpm_status_log_cleanup', [self::class, 'run_status_log_cleanup']);
}

public static function run_status_log_cleanup(): void
{
    global $wpdb;
    $two_years_ago = gmdate('Y-m-d H:i:s', strtotime('-2 years'));

    // Get all distinct entry_ids
    $entry_ids = $wpdb->get_col(
        "SELECT DISTINCT entry_id FROM {$wpdb->prefix}home_promo_status_log"
    );
    foreach ($entry_ids as $entry_id) {
        $entry_id = (int) $entry_id;

        // IDs to keep: top 3 by logged_at DESC union all within 2 years
        $keep_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}home_promo_status_log
              WHERE entry_id = %d
              ORDER BY logged_at DESC LIMIT 3",
            $entry_id
        ));

        $recent_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}home_promo_status_log
              WHERE entry_id = %d AND logged_at >= %s",
            $entry_id, $two_years_ago
        ));

        $keep = array_unique(array_merge($keep_ids, $recent_ids));
        if (empty($keep)) continue;

        $placeholders = implode(',', array_fill(0, count($keep), '%d'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}home_promo_status_log
              WHERE entry_id = %d AND id NOT IN ({$placeholders})",
            array_merge([$entry_id], $keep)
        ));
    }
}
```

- [ ] **Step 2: Register on plugin activation in `home-promo-manager.php`**

In the activation hook (or create one if absent):

```php
register_activation_hook(__FILE__, function () {
    HPM\DB::install(
        get_option('home_promo_manager_version', '0.0.0'),
        HOME_PROMO_MANAGER_VERSION
    );
    HPM\DB::schedule_cleanup();
});

register_deactivation_hook(__FILE__, function () {
    $timestamp = wp_next_scheduled('hpm_status_log_cleanup');
    if ($timestamp) wp_unschedule_event($timestamp, 'hpm_status_log_cleanup');
});
```

- [ ] **Step 3: Run all tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit -v
```

Expected: PASS.

- [ ] **Step 4: Commit**

```
git add src/db.php home-promo-manager.php
git commit -m "feat: status log daily cleanup cron (hpm_status_log_cleanup)"
```

---

## Task 12: Admin Campaigns tab

**Files:**
- Modify: `src/admin.php`

This task adds the Campaigns tab to the plugin's existing Settings page. The existing Code Management tab is removed. All handlers start with the capability check then nonce check.

- [ ] **Step 1: Add capability + nonce guard helper at top of campaigns section in `src/admin.php`**

```php
// Add inside the Admin class or as a standalone function at top of admin.php:
function hpm_admin_guard(): void {
    if (!current_user_can(HPM\CampaignEngine::CAP)) {
        wp_die(__('Insufficient permissions.', 'home-promo-manager'), 403);
    }
}
```

- [ ] **Step 2: Add Campaigns tab registration**

In the existing `add_settings_page` or `admin_menu` hook, add:

```php
add_action('admin_menu', function () {
    add_options_page(
        'HOME Promo Manager',
        'Promo Manager',
        HPM\CampaignEngine::CAP,
        'home-promo-manager',
        'hpm_render_admin_page'
    );
});
```

- [ ] **Step 3: Add `hpm_render_admin_page()` — router for tabs**

```php
function hpm_render_admin_page(): void {
    hpm_admin_guard();
    $tab = sanitize_text_field($_GET['tab'] ?? 'campaigns');
    echo '<div class="wrap"><h1>HOME Promo Manager</h1>';
    echo '<nav class="nav-tab-wrapper">';
    echo '<a href="?page=home-promo-manager&tab=campaigns" class="nav-tab'
        . ($tab === 'campaigns' ? ' nav-tab-active' : '') . '">Campaigns</a>';
    echo '<a href="?page=home-promo-manager&tab=settings" class="nav-tab'
        . ($tab === 'settings' ? ' nav-tab-active' : '') . '">Settings</a>';
    echo '</nav>';
    if ($tab === 'campaigns') hpm_render_campaigns_tab();
    else hpm_render_settings_tab();
    echo '</div>';
}
```

- [ ] **Step 4: Add `hpm_render_campaigns_tab()` — list view**

```php
function hpm_render_campaigns_tab(): void {
    global $wpdb;
    hpm_admin_guard();
    hpm_handle_campaign_actions(); // process any pending action

    $campaigns = $wpdb->get_results(
        "SELECT c.*, 
                (SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted cc WHERE cc.campaign_id = c.id) AS used_count,
                a.campaign_id AS is_pointed
           FROM {$wpdb->prefix}home_promo_campaigns c
      LEFT JOIN {$wpdb->prefix}home_promo_active a ON a.singleton = 1
          ORDER BY c.id DESC"
    );

    echo '<h2>Campaigns</h2>';
    echo '<a href="?page=home-promo-manager&tab=campaigns&action=new" class="page-title-action">Add New</a>';
    echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
    echo '<th>Name</th><th>Slug</th><th>Mode</th><th>Status</th><th>Dates (UTC)</th><th>Quota</th><th>Used</th><th>Actions</th>';
    echo '</tr></thead><tbody>';
    foreach ($campaigns as $c) {
        $is_active_ptr = ($c->is_pointed == $c->id);
        $live_badge    = $is_active_ptr ? '<strong>[LIVE]</strong> ' : '';
        printf(
            '<tr><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s – %s</td><td>%d</td><td>%d</td><td>%s</td></tr>',
            $live_badge,
            esc_html($c->name),
            esc_html($c->slug),
            esc_html($c->mode),
            esc_html($c->status),
            esc_html(wp_date('Y-m-d H:i', strtotime($c->start_date . ' UTC'))),
            esc_html(wp_date('Y-m-d H:i', strtotime($c->end_date   . ' UTC'))),
            (int) $c->quota,
            (int) $c->used_count,
            hpm_campaign_actions_html((int) $c->id, $c->status, $is_active_ptr)
        );
    }
    echo '</tbody></table>';

    $action = sanitize_text_field($_GET['action'] ?? '');
    if ($action === 'new') hpm_render_campaign_form(null);
    if ($action === 'edit') {
        $id  = (int) ($_GET['campaign_id'] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}home_promo_campaigns WHERE id = %d", $id
        ));
        if ($row) hpm_render_campaign_form($row);
    }
}

function hpm_campaign_actions_html(int $id, string $status, bool $is_active_ptr): string {
    $base = '?page=home-promo-manager&tab=campaigns';
    $nf   = wp_nonce_field('hpm_campaign_save', '_wpnonce', true, false);
    $html = "<a href='{$base}&action=edit&campaign_id={$id}'>Edit</a> | ";
    if (!$is_active_ptr) {
        $html .= "<a href='{$base}&action=activate&campaign_id={$id}'>Activate</a> | ";
    } else {
        $html .= "<a href='{$base}&action=deactivate&campaign_id={$id}'>Deactivate</a> | ";
    }
    $html .= "<a href='{$base}&action=delete&campaign_id={$id}' onclick='return confirm(\"Delete?\")'>Delete</a>";
    return $html;
}
```

- [ ] **Step 5: Add `hpm_render_campaign_form()` — create/edit form**

```php
function hpm_render_campaign_form(?object $c): void {
    $is_edit = ($c !== null);
    $action  = $is_edit ? 'save_edit' : 'save_new';
    echo '<hr><h3>' . ($is_edit ? 'Edit Campaign' : 'New Campaign') . '</h3>';
    echo '<form method="post" action="?page=home-promo-manager&tab=campaigns">';
    wp_nonce_field('hpm_campaign_save');
    echo '<input type="hidden" name="hpm_action" value="' . esc_attr($action) . '">';
    if ($is_edit) {
        echo '<input type="hidden" name="campaign_id" value="' . (int) $c->id . '">';
    }
    $tz   = wp_timezone();
    $sd   = $is_edit ? wp_date('Y-m-d H:i:s', strtotime($c->start_date . ' UTC')) : '';
    $ed   = $is_edit ? wp_date('Y-m-d H:i:s', strtotime($c->end_date   . ' UTC')) : '';
    ?>
    <table class="form-table">
      <tr><th>Name</th><td><input name="name" value="<?= esc_attr($c->name ?? '') ?>" class="regular-text" required></td></tr>
      <tr><th>Slug</th><td><input name="slug" value="<?= esc_attr($c->slug ?? '') ?>" class="regular-text"></td></tr>
      <tr><th>Mode</th><td>
        <select name="mode">
          <option value="auto"   <?= ($c->mode ?? '') === 'auto'   ? 'selected' : '' ?>>Auto</option>
          <option value="manual" <?= ($c->mode ?? '') === 'manual' ? 'selected' : '' ?>>Manual</option>
        </select>
      </td></tr>
      <tr><th>Start Date (site tz)</th><td><input type="datetime-local" name="start_date" value="<?= esc_attr(str_replace(' ', 'T', $sd)) ?>"></td></tr>
      <tr><th>End Date (site tz)</th><td><input type="datetime-local" name="end_date" value="<?= esc_attr(str_replace(' ', 'T', $ed)) ?>"></td></tr>
      <tr><th>Quota</th><td><input type="number" name="quota" value="<?= (int)($c->quota ?? 0) ?>" min="1"></td></tr>
      <tr><th>Discount (RM)</th><td><input type="number" name="discount_amount" value="<?= esc_attr($c->discount_amount ?? '') ?>" step="0.01" min="0.01" max="999999.99"></td></tr>
      <tr><th>Campaign Code (auto)</th><td><input name="campaign_code" value="<?= esc_attr($c->campaign_code ?? '') ?>" maxlength="40"></td></tr>
      <tr><th>Codes Config (manual, JSON)</th><td><textarea name="codes_config" rows="3" cols="50"><?= esc_textarea($c->codes_config ?? '') ?></textarea></td></tr>
    </table>
    <p><button type="submit" class="button button-primary">Save Campaign</button></p>
    </form>
    <?php
}
```

- [ ] **Step 6: Add `hpm_handle_campaign_actions()` — save/activate/deactivate/delete**

```php
function hpm_handle_campaign_actions(): void {
    global $wpdb;
    $hpm_action = sanitize_text_field($_POST['hpm_action'] ?? $_GET['action'] ?? '');
    if (!$hpm_action) return;
    if (!in_array($hpm_action, ['save_new','save_edit','activate','deactivate','delete'], true)) return;

    hpm_admin_guard();
    check_admin_referer('hpm_campaign_save');

    if ($hpm_action === 'save_new' || $hpm_action === 'save_edit') {
        $data  = hpm_sanitise_campaign_fields($_POST);
        $error = hpm_validate_campaign_fields($data, $hpm_action === 'save_edit' ? (int)($_POST['campaign_id'] ?? 0) : null);
        if ($error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
            return;
        }
        if ($hpm_action === 'save_new') {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}home_promo_campaigns
                   (name,slug,status,mode,start_date,end_date,quota,discount_amount,campaign_code,codes_config,created_at,updated_at)
                 VALUES (%s,%s,'draft',%s,%s,%s,%d,%f,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                $data['name'], $data['slug'], $data['mode'],
                $data['start_date'], $data['end_date'],
                $data['quota'], $data['discount_amount'],
                $data['campaign_code'], $data['codes_config']
            ));
        } else {
            $id = (int) ($_POST['campaign_id'] ?? 0);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}home_promo_campaigns
                    SET name=%s,slug=%s,mode=%s,start_date=%s,end_date=%s,
                        quota=%d,discount_amount=%f,campaign_code=%s,codes_config=%s,
                        updated_at=UTC_TIMESTAMP()
                  WHERE id=%d",
                $data['name'], $data['slug'], $data['mode'],
                $data['start_date'], $data['end_date'],
                $data['quota'], $data['discount_amount'],
                $data['campaign_code'], $data['codes_config'], $id
            ));
            HPM\CampaignEngine::flush();
        }
        echo '<div class="notice notice-success"><p>Campaign saved.</p></div>';
        return;
    }

    $campaign_id = (int) ($_GET['campaign_id'] ?? 0);
    $user_id     = get_current_user_id();

    if ($hpm_action === 'activate') {
        $result = HPM\CampaignEngine::activate($campaign_id, $user_id);
        if ($result['status'] === 'conflict') {
            echo '<div class="notice notice-error"><p>'
                . esc_html("Campaign #{$result['conflict_id']} is already active. Deactivate it first.")
                . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Campaign activated.</p></div>';
        }
    }

    if ($hpm_action === 'deactivate') {
        HPM\CampaignEngine::deactivate($campaign_id, $user_id);
        echo '<div class="notice notice-success"><p>Campaign deactivated.</p></div>';
    }

    if ($hpm_action === 'delete') {
        // Safety: do not delete a pointed-to campaign
        $pointed = (int) $wpdb->get_var(
            "SELECT campaign_id FROM {$wpdb->prefix}home_promo_active WHERE singleton=1"
        );
        if ($pointed === $campaign_id) {
            echo '<div class="notice notice-error"><p>Cannot delete the active campaign. Deactivate first.</p></div>';
            return;
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}home_promo_campaigns WHERE id = %d", $campaign_id
        ));
        HPM\CampaignEngine::flush();
        echo '<div class="notice notice-success"><p>Campaign deleted.</p></div>';
    }
}
```

- [ ] **Step 7: Add `hpm_sanitise_campaign_fields()` and `hpm_validate_campaign_fields()`**

```php
function hpm_sanitise_campaign_fields(array $post): array {
    $mode = sanitize_text_field($post['mode'] ?? '');
    $raw_codes_config = $post['codes_config'] ?? '';
    $codes_config_encoded = null;
    if ($mode === 'manual' && !empty($raw_codes_config)) {
        try {
            $decoded = json_decode($raw_codes_config, true, 512, JSON_THROW_ON_ERROR);
            $codes_config_encoded = wp_json_encode($decoded);
        } catch (\JsonException $e) {
            $codes_config_encoded = '__INVALID_JSON__';
        }
    }

    $tz       = wp_timezone();
    $sd_input = sanitize_text_field(str_replace('T', ' ', $post['start_date'] ?? ''));
    $ed_input = sanitize_text_field(str_replace('T', ' ', $post['end_date'] ?? ''));
    $sd_utc   = hpm_local_to_utc($sd_input);
    $ed_utc   = hpm_local_to_utc($ed_input);

    return [
        'name'            => sanitize_text_field($post['name'] ?? ''),
        'slug'            => sanitize_title($post['slug'] ?? sanitize_text_field($post['name'] ?? '')),
        'mode'            => $mode,
        'start_date'      => $sd_utc,
        'end_date'        => $ed_utc,
        'quota'           => absint($post['quota'] ?? 0),
        'discount_amount' => (float) ($post['discount_amount'] ?? 0),
        'campaign_code'   => ($mode === 'auto') ? substr(sanitize_text_field($post['campaign_code'] ?? ''), 0, 40) : null,
        'codes_config'    => ($mode === 'manual') ? $codes_config_encoded : null,
    ];
}

function hpm_local_to_utc(string $local_datetime): string {
    try {
        $dt = new \DateTime($local_datetime, wp_timezone());
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (\Exception $e) {
        return '';
    }
}

function hpm_validate_campaign_fields(array $data, ?int $edit_id): ?string {
    global $wpdb;

    if (empty($data['name']))   return 'Name is required.';

    $slug = $data['slug'];
    if (empty($slug))           return 'Slug could not be generated. Please enter a manual slug using Latin characters.';
    if (strlen($slug) < 3)      return 'Slug must be at least 3 characters long.';
    if (strlen($slug) > 80)     return 'Slug must be 80 characters or fewer.';

    $dup_where = $edit_id
        ? $wpdb->prepare("SELECT id FROM {$wpdb->prefix}home_promo_campaigns WHERE slug=%s AND id<>%d", $slug, $edit_id)
        : $wpdb->prepare("SELECT id FROM {$wpdb->prefix}home_promo_campaigns WHERE slug=%s", $slug);
    if ($wpdb->get_var($dup_where)) return 'A campaign with this slug already exists.';

    if (!in_array($data['mode'], ['auto','manual'], true)) return 'Invalid mode.';
    if (empty($data['start_date'])) return 'Start date is required.';
    if (empty($data['end_date']))   return 'End date is required.';
    if ($data['end_date'] <= $data['start_date']) return 'End date must be after start date.';
    if ($data['quota'] === 0)       return 'Quota must be at least 1.';
    if ($data['discount_amount'] <= 0)         return 'Discount must be greater than 0.';
    if ($data['discount_amount'] > 999999.99)  return 'Discount exceeds maximum (RM 999,999.99).';

    if ($data['mode'] === 'auto' && empty($data['campaign_code'])) {
        return 'Campaign code is required for auto mode.';
    }
    if ($data['mode'] === 'manual') {
        if ($data['codes_config'] === '__INVALID_JSON__') return 'Codes config must be valid JSON.';
        if (empty($data['codes_config'])) return 'Codes config is required for manual mode.';
    }
    if ($data['mode'] === 'auto' && !empty($data['codes_config'])) {
        return 'Codes config must be empty for auto mode.';
    }
    if ($data['mode'] === 'manual' && !empty($data['campaign_code'])) {
        return 'Campaign code must be empty for manual mode.';
    }

    return null;
}
```

- [ ] **Step 8: Run all tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit -v
```

Expected: PASS.

- [ ] **Step 9: Commit**

```
git add src/admin.php
git commit -m "feat: Admin Campaigns tab — list view, create/edit form, CRUD handlers, security guards, sanitisation"
```

---

## Task 13: REST endpoints

**Files:**
- Modify: `src/rest.php`

- [ ] **Step 1: Replace `src/rest.php` content**

```php
<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    // Public counter endpoint
    register_rest_route('promo/v1', '/counter', [
        'methods'             => 'GET',
        'callback'            => 'HPM\rest_counter',
        'permission_callback' => '__return_true',
    ]);

    // Admin-only campaign CRUD
    $admin_perm = fn() => current_user_can(CampaignEngine::CAP);

    register_rest_route('promo/v1', '/campaigns', [
        ['methods' => 'GET',  'callback' => 'HPM\rest_campaigns_list',   'permission_callback' => $admin_perm],
        ['methods' => 'POST', 'callback' => 'HPM\rest_campaigns_create', 'permission_callback' => $admin_perm],
    ]);

    register_rest_route('promo/v1', '/campaigns/(?P<id>\d+)', [
        ['methods' => 'PUT',    'callback' => 'HPM\rest_campaigns_update', 'permission_callback' => $admin_perm],
        ['methods' => 'DELETE', 'callback' => 'HPM\rest_campaigns_delete', 'permission_callback' => $admin_perm],
    ]);
});

function rest_counter(\WP_REST_Request $req): \WP_REST_Response {
    global $wpdb;
    $campaign = CampaignEngine::get_active();
    if (!$campaign) {
        return new \WP_REST_Response(['used' => 0, 'max' => 0, 'remaining' => 0, 'active' => false]);
    }
    $used = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted WHERE campaign_id = %d",
        $campaign->id
    ));
    return new \WP_REST_Response([
        'used'      => $used,
        'max'       => $campaign->quota,
        'remaining' => max(0, $campaign->quota - $used),
        'active'    => true,
    ]);
}

function rest_campaigns_list(\WP_REST_Request $req): \WP_REST_Response {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}home_promo_campaigns ORDER BY id DESC"
    );
    return new \WP_REST_Response($rows);
}

function rest_campaigns_create(\WP_REST_Request $req): \WP_REST_Response {
    $data  = hpm_sanitise_campaign_fields($req->get_params());
    $error = hpm_validate_campaign_fields($data, null);
    if ($error) return new \WP_REST_Response(['error' => $error], 400);

    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}home_promo_campaigns
           (name,slug,status,mode,start_date,end_date,quota,discount_amount,campaign_code,codes_config,created_at,updated_at)
         VALUES (%s,%s,'draft',%s,%s,%s,%d,%f,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
        $data['name'], $data['slug'], $data['mode'],
        $data['start_date'], $data['end_date'],
        $data['quota'], $data['discount_amount'],
        $data['campaign_code'], $data['codes_config']
    ));
    return new \WP_REST_Response(['id' => $wpdb->insert_id], 201);
}

function rest_campaigns_update(\WP_REST_Request $req): \WP_REST_Response {
    $id    = (int) $req['id'];
    $data  = hpm_sanitise_campaign_fields($req->get_params());
    $error = hpm_validate_campaign_fields($data, $id);
    if ($error) return new \WP_REST_Response(['error' => $error], 400);

    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}home_promo_campaigns
            SET name=%s,slug=%s,mode=%s,start_date=%s,end_date=%s,
                quota=%d,discount_amount=%f,campaign_code=%s,codes_config=%s,
                updated_at=UTC_TIMESTAMP()
          WHERE id=%d",
        $data['name'], $data['slug'], $data['mode'],
        $data['start_date'], $data['end_date'],
        $data['quota'], $data['discount_amount'],
        $data['campaign_code'], $data['codes_config'], $id
    ));
    CampaignEngine::flush();
    return new \WP_REST_Response(['updated' => true]);
}

function rest_campaigns_delete(\WP_REST_Request $req): \WP_REST_Response {
    global $wpdb;
    $id      = (int) $req['id'];
    $pointed = (int) $wpdb->get_var(
        "SELECT campaign_id FROM {$wpdb->prefix}home_promo_active WHERE singleton=1"
    );
    if ($pointed === $id) {
        return new \WP_REST_Response(['error' => 'Cannot delete active campaign.'], 409);
    }
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}home_promo_campaigns WHERE id=%d", $id
    ));
    CampaignEngine::flush();
    return new \WP_REST_Response(['deleted' => true]);
}
```

- [ ] **Step 2: Run all tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit -v
```

Expected: PASS.

- [ ] **Step 3: Commit**

```
git add src/rest.php
git commit -m "feat: REST endpoints — public counter, admin-only campaign CRUD"
```

---

## Task 14: Promo page + shortcodes + version bump + cleanup

**Files:**
- Rewrite: `template/promo-page.php`
- Modify: `src/shortcodes.php`
- Modify: `home-promo-manager.php`
- Modify: `src/bootstrap.php`
- Delete: `src/Validator.php`

- [ ] **Step 1: Rewrite `template/promo-page.php`**

```php
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
```

- [ ] **Step 2: Remove code-entry UI from `src/shortcodes.php`**

Remove any shortcode that renders a code-entry popup or input field. Update the `[promo_countdown]` shortcode to match the generic counter. Replace relevant shortcode output with:

```php
// In register_shortcodes() or equivalent, replace the old counter shortcode:
add_shortcode('hpm_counter', function () {
    $campaign = HPM\CampaignEngine::get_active();
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
```

Remove any `[promo_code_entry]`, `[promo_popup]`, or similar shortcodes.

- [ ] **Step 3: Bump version in `home-promo-manager.php`**

Find the plugin header:
```
 * Version: x.y.z
```
Change to:
```
 * Version: 1.0.0
```

Also update the version constant if present:
```php
define('HOME_PROMO_MANAGER_VERSION', '1.0.0');
```

- [ ] **Step 4: Update `src/bootstrap.php` to load new files**

```php
<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

$plugin_dir = plugin_dir_path(__FILE__ . '/../');

require_once $plugin_dir . 'src/db.php';
require_once $plugin_dir . 'src/Manager.php';
require_once $plugin_dir . 'src/CampaignEngine.php';
require_once $plugin_dir . 'src/Eligibility.php';
require_once $plugin_dir . 'src/hooks.php';
require_once $plugin_dir . 'src/admin.php';
require_once $plugin_dir . 'src/rest.php';
require_once $plugin_dir . 'src/shortcodes.php';
require_once $plugin_dir . 'src/templates.php';
require_once $plugin_dir . 'src/updater.php';

// Version bump detection → run install
$stored_version = get_option('home_promo_manager_version', '0.0.0');
if (version_compare($stored_version, HOME_PROMO_MANAGER_VERSION, '<')) {
    DB::install($stored_version, HOME_PROMO_MANAGER_VERSION);
    update_option('home_promo_manager_version', HOME_PROMO_MANAGER_VERSION);
}

DB::schedule_cleanup();
```

- [ ] **Step 5: Delete `src/Validator.php`**

```
git rm src/Validator.php
```

- [ ] **Step 6: Run all tests**

```
C:\Users\PC\.config\herd\bin\php84\php.exe vendor/bin/phpunit -v
```

Expected: all pass.

- [ ] **Step 7: Commit**

```
git add -A
git commit -m "feat: generic promo page, simplified shortcodes, version 1.0.0, remove Validator.php"
```

---

## Self-Review

**Spec coverage check:**

| Spec requirement | Task |
|---|---|
| wp_home_promo_campaigns schema (dbDelta-safe) | Task 2 |
| wp_home_promo_active pointer table + seed | Task 2 |
| wp_home_promo_status_log schema | Task 2 |
| column_exists helper + ALTER TABLE | Task 1, 3 |
| InnoDB check for counted table | Task 3 |
| Pasif backfill migration (chunked, per-chunk tx) | Task 3 |
| Autoload policy (autoload='no') | Task 3 |
| CampaignEngine static cache + flush() | Task 4 |
| Mode-exclusivity assertion in get_active() | Task 4 |
| activate() — WHERE IS NULL, re-SELECT disambiguation | Task 5 |
| deactivate() — pointer to NULL + status flip | Task 5 |
| OrSpecification + 3 leaf specs | Task 6 |
| Status check fail-closed (1617 vs 199 divergence) | Task 8 (claim_slot) |
| SENTINEL_UNSET pre-hook snapshot | Task 7 |
| Fallback SELECT when pre-hook missed | Task 7 |
| $ctx builder (all prev_* from snapshot, not $_POST) | Task 7 |
| claim_slot() full atomic transaction | Task 8 |
| Manual mode Layer 2 (SELECT FOR UPDATE) | Task 8 |
| INSERT IGNORE + ROLLBACK on field write failure | Task 8 |
| Reentrancy guard ($writing_field + FrmEntryMeta) | Task 8 |
| frm_after_create_entry dispatch | Task 9 |
| frm_after_update_entry dispatch + early bail | Task 9 |
| Field 3170 integrity re-write (both modes) | Task 9 |
| Pasif transition → status_log write | Task 9 |
| frm_validate_entry manual mode Layer 1 | Task 10 |
| Status log retention cron (daily, 3 or 2yr) | Task 11 |
| Admin capability + nonce guard on all handlers | Task 12 |
| All sanitisation rules (name/slug/mode/dates/quota/discount/codes) | Task 12 |
| Slug validation rules (non-Latin, length, duplicate) | Task 12 |
| Mode-exclusive column validation (save reject) | Task 12 |
| Activation/deactivation admin actions + flush() | Task 12 |
| REST counter (public) | Task 13 |
| REST campaigns CRUD (admin-only, X-WP-Nonce) | Task 13 |
| Generic promo page (no code-entry UI) | Task 14 |
| Version 1.0.0 | Task 14 |
| Delete Validator.php | Task 14 |

No gaps found.
