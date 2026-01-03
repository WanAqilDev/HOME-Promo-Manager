# Smart 26 Promotion Module - Implementation Plan

## Executive Summary
This document outlines the complete implementation plan to migrate the HOME-Promo-Manager system from a 2-tier promotional code system to the Smart 26 campaign requirements with 4 distinct codes, per-code quota tracking, and user-entered validation.

## Campaign Requirements Summary

### Timeline
- **Start**: January 12, 2026, 12:00 PM (tengah hari)
- **End**: January 14, 2026, 11:59 AM (pagi)
- **Duration**: Strictly 48 hours

### Promo Structure
- **Total Slots**: 200 registrations
- **Number of Codes**: 4 distinct codes (one per Live session)
- **Per-Code Quota**: 50 redemptions each
- **Discount**: Flat RM52 off RM200 (Final: RM148)

### Eligibility Criteria
1. New client registrations ✅ (existing)
2. Passive clients (>3 months inactive) ✅ (existing, needs config adjustment)
3. Leads with Diagnostic Session <3 months ago ❌ (NEW)
4. General Leads who inquired ❌ (NEW)

---

## Phase 1: Database Schema Changes

### 1.1 Update `home_promo_counted` Table

**Current Schema:**
```sql
CREATE TABLE home_promo_counted (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_id BIGINT(20) UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_entry (entry_id)
);
```

**NEW Schema:**
```sql
CREATE TABLE home_promo_counted (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_id BIGINT(20) UNSIGNED NOT NULL,
    promo_code VARCHAR(50) NOT NULL,           -- NEW: Track which code was used
    branch VARCHAR(100),                        -- NEW: Branch selection
    user_category VARCHAR(50),                  -- NEW: new/passive/diagnostic/lead
    eligibility_verified TINYINT(1) DEFAULT 0,  -- NEW: Eligibility check flag
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_entry (entry_id),
    INDEX idx_code (promo_code),                -- NEW: Index for per-code queries
    INDEX idx_category (user_category)          -- NEW: Index for reporting
);
```

**Migration SQL:**
```sql
ALTER TABLE {$wpdb->prefix}home_promo_counted 
ADD COLUMN promo_code VARCHAR(50) NOT NULL AFTER entry_id,
ADD COLUMN branch VARCHAR(100) AFTER promo_code,
ADD COLUMN user_category VARCHAR(50) AFTER branch,
ADD COLUMN eligibility_verified TINYINT(1) DEFAULT 0 AFTER user_category,
ADD INDEX idx_code (promo_code),
ADD INDEX idx_category (user_category);
```

**Files to Update:**
- [ ] `src/db.php` - Update `install()` method
- [ ] `src/db.php` - Create migration function `migrate_to_v2()`
- [ ] `src/db.php` - Update `insert_entry()` to accept promo_code, branch, category

---

## Phase 2: Settings Schema Refactoring

### 2.1 New Settings Structure

**Current Settings (2-tier):**
```php
[
    'max' => 480,
    'tier1_max' => 240,
    'code_tier1' => 'promo24',
    'code_tier2' => 'promo12',
]
```

**NEW Settings (4-code system):**
```php
[
    // Campaign timing
    'campaign_start' => '2026-01-12 12:00:00',
    'campaign_end' => '2026-01-14 11:59:00',
    'timezone' => 'Asia/Kuala_Lumpur',
    
    // Code configuration
    'promo_codes' => [
        'SMART26-LIVE1' => ['max' => 50, 'description' => 'Live Session 1'],
        'SMART26-LIVE2' => ['max' => 50, 'description' => 'Live Session 2'],
        'SMART26-LIVE3' => ['max' => 50, 'description' => 'Live Session 3'],
        'SMART26-LIVE4' => ['max' => 50, 'description' => 'Live Session 4'],
    ],
    'total_max' => 200,
    
    // Pricing
    'base_price' => 200.00,
    'discount_amount' => 52.00,
    'final_price' => 148.00,
    
    // Eligibility fields
    'diagnostic_date_field_id' => 0,  // NEW
    'lead_status_field_id' => 0,      // NEW
    'branch_field_id' => 0,           // NEW
    'passive_threshold_days' => 90,   // Configurable (3 months)
    
    // Existing fields
    'form_id' => 13,
    'promo_field_id' => 3170,
    'daftar_field_id' => 196,
    'status_field_id' => 199,
    'pasif_date_field_id' => 1698,
]
```

**Files to Update:**
- [ ] `src/Manager.php` - Update `__construct()` defaults
- [ ] `src/admin.php` - Update `sanitize_settings()`
- [ ] `src/admin.php` - Update `render_admin_page()` UI
- [ ] `src/db.php` - Update `install()` default settings

---

## Phase 3: Core Logic Updates

### 3.1 Promo Code Validation

**NEW FILE**: `src/Validator.php`

```php
<?php
namespace HPM;

class Validator
{
    private $manager;
    
    public function __construct(Manager $manager) {
        $this->manager = $manager;
    }
    
    /**
     * Validate promo code against all criteria
     * 
     * @param string $code User-entered code
     * @param int $entry_id Formidable entry ID
     * @return array ['valid' => bool, 'message' => string, 'category' => string]
     */
    public function validate_code($code, $entry_id) {
        // 1. Code exists?
        $codes = $this->manager->s('promo_codes');
        if (!isset($codes[$code])) {
            return [
                'valid' => false,
                'message' => 'Invalid promo code.',
                'category' => null
            ];
        }
        
        // 2. Time window active?
        if (!$this->manager->is_active()) {
            return [
                'valid' => false,
                'message' => 'Promotion period has ended.',
                'category' => null
            ];
        }
        
        // 3. Code quota available?
        $usage = DB::get_code_usage($code);
        $max = $codes[$code]['max'];
        if ($usage >= $max) {
            return [
                'valid' => false,
                'message' => 'This code has reached its usage limit.',
                'category' => null
            ];
        }
        
        // 4. User eligible?
        $eligibility = $this->check_eligibility($entry_id);
        if (!$eligibility['eligible']) {
            return [
                'valid' => false,
                'message' => $eligibility['message'],
                'category' => null
            ];
        }
        
        return [
            'valid' => true,
            'message' => 'Code validated successfully!',
            'category' => $eligibility['category']
        ];
    }
    
    /**
     * Check user eligibility based on 4 criteria
     */
    private function check_eligibility($entry_id) {
        // Category 1: New registration
        $daftar_field = $this->manager->s('daftar_field_id');
        $daftar_val = ff_get_entry_meta($entry_id, $daftar_field);
        if ($daftar_val === 'Ya') {
            return ['eligible' => true, 'category' => 'new', 'message' => ''];
        }
        
        // Category 2: Passive client (>90 days)
        $status_field = $this->manager->s('status_field_id');
        $pasif_date_field = $this->manager->s('pasif_date_field_id');
        $threshold = $this->manager->s('passive_threshold_days') ?: 90;
        
        $status = ff_get_entry_meta($entry_id, $status_field);
        $pasif_date = ff_get_entry_meta($entry_id, $pasif_date_field);
        
        if ($status === '2' && !empty($pasif_date)) {
            $days_inactive = (time() - strtotime($pasif_date)) / 86400;
            if ($days_inactive > $threshold) {
                return ['eligible' => true, 'category' => 'passive', 'message' => ''];
            }
        }
        
        // Category 3: Diagnostic session <3 months
        $diagnostic_field = $this->manager->s('diagnostic_date_field_id');
        if ($diagnostic_field) {
            $diagnostic_date = ff_get_entry_meta($entry_id, $diagnostic_field);
            if (!empty($diagnostic_date)) {
                $days_since = (time() - strtotime($diagnostic_date)) / 86400;
                if ($days_since < 90) {
                    return ['eligible' => true, 'category' => 'diagnostic', 'message' => ''];
                }
            }
        }
        
        // Category 4: General lead
        $lead_status_field = $this->manager->s('lead_status_field_id');
        if ($lead_status_field) {
            $lead_status = ff_get_entry_meta($entry_id, $lead_status_field);
            if ($lead_status === 'Lead' || $lead_status === 'Inquiry') {
                return ['eligible' => true, 'category' => 'lead', 'message' => ''];
            }
        }
        
        return [
            'eligible' => false, 
            'category' => null,
            'message' => 'You are not eligible for this promotion.'
        ];
    }
}
```

**Files to Create:**
- [ ] `src/Validator.php` - New validation class

**Files to Update:**
- [ ] `src/bootstrap.php` - Include Validator.php
- [ ] `home-promo-manager.php` - Include Validator.php

---

### 3.2 Update DB Class

**NEW Methods in `src/db.php`:**

```php
/**
 * Get usage count for a specific promo code
 */
public static function get_code_usage($code) {
    global $wpdb;
    $table = self::table_name();
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE promo_code = %s",
        $code
    ));
}

/**
 * Get usage breakdown by code
 */
public static function get_code_stats() {
    global $wpdb;
    $table = self::table_name();
    return $wpdb->get_results(
        "SELECT promo_code, COUNT(*) as count 
         FROM {$table} 
         GROUP BY promo_code",
        ARRAY_A
    );
}

/**
 * Insert entry with code tracking
 */
public static function insert_entry_with_code($entry_id, $code, $branch, $category, $limit = null) {
    global $wpdb;
    $table = self::table_name();
    
    if ($limit !== null) {
        // Check code-specific quota
        $code_usage = self::get_code_usage($code);
        if ($code_usage >= $limit) {
            return false;
        }
    }
    
    $res = $wpdb->insert(
        $table,
        [
            'entry_id' => (int) $entry_id,
            'promo_code' => $code,
            'branch' => $branch,
            'user_category' => $category,
            'eligibility_verified' => 1,
        ],
        ['%d', '%s', '%s', '%s', '%d']
    );
    
    return $res !== false;
}
```

**Files to Update:**
- [ ] `src/db.php` - Add new methods
- [ ] `src/db.php` - Deprecate old `insert_entry()` (keep for backward compat)

---

### 3.3 Update Manager Class

**Modified Methods in `src/Manager.php`:**

```php
/**
 * Validate and record promo code usage
 */
public function validate_and_record($code, $entry_id) {
    $validator = new Validator($this);
    $result = $validator->validate_code($code, $entry_id);
    
    if (!$result['valid']) {
        return $result;
    }
    
    // Get branch selection
    $branch_field = $this->s('branch_field_id');
    $branch = $branch_field ? ff_get_entry_meta($entry_id, $branch_field) : '';
    
    // Get code quota
    $codes = $this->s('promo_codes');
    $quota = $codes[$code]['max'];
    
    // Record to database
    $inserted = DB::insert_entry_with_code(
        $entry_id, 
        $code, 
        $branch, 
        $result['category'],
        $quota
    );
    
    if (!$inserted) {
        return [
            'valid' => false,
            'message' => 'Code quota exceeded or already redeemed.',
            'category' => null
        ];
    }
    
    // Update entry meta with code
    $promo_field_id = (int) $this->s('promo_field_id');
    ff_update_entry_meta($entry_id, $promo_field_id, $code);
    
    // Send notification email
    $this->send_notification($entry_id, $code, $result['category']);
    
    return [
        'valid' => true,
        'message' => 'Promo code applied successfully!',
        'category' => $result['category'],
        'discount' => $this->s('discount_amount')
    ];
}

/**
 * DEPRECATED: Old tiered code system
 * Kept for backward compatibility
 */
public function get_current_code($count = null) {
    // Check if using new code system
    if ($this->s('promo_codes')) {
        error_log('[HPM] Warning: get_current_code() is deprecated for multi-code campaigns');
        return ''; // Return empty for validation-based system
    }
    
    // Legacy behavior
    if ($count === null)
        $count = $this->get_count();
    $max = (int) $this->s('max');
    $tier1 = (int) $this->s('tier1_max');
    if ($count >= $max)
        return '';
    return ($count < $tier1) ? $this->s('code_tier1') : $this->s('code_tier2');
}
```

**Files to Update:**
- [ ] `src/Manager.php` - Add `validate_and_record()`
- [ ] `src/Manager.php` - Deprecate/update `get_current_code()`
- [ ] `src/Manager.php` - Update `record_activation()` to use new system

---

## Phase 4: Formidable Forms Integration

### 4.1 Code Validation Hook

**Update `src/hooks.php`:**

```php
// Validate promo code field before entry creation
add_filter('frm_validate_entry', function($errors, $values) {
    $mgr = Manager::get_instance();
    
    // Only for Form 13 and during active campaign
    if ((int) $values['form_id'] !== (int) $mgr->s('form_id')) {
        return $errors;
    }
    
    if (!$mgr->is_active()) {
        return $errors;
    }
    
    $promo_field_id = (string) $mgr->s('promo_field_id');
    $code = isset($values['item_meta'][$promo_field_id]) 
        ? sanitize_text_field($values['item_meta'][$promo_field_id]) 
        : '';
    
    if (empty($code)) {
        $errors['field' . $promo_field_id] = 'Please enter a promo code.';
        return $errors;
    }
    
    // Validate code (without entry_id yet - will revalidate on submission)
    $codes = $mgr->s('promo_codes');
    if (!isset($codes[$code])) {
        $errors['field' . $promo_field_id] = 'Invalid promo code.';
        return $errors;
    }
    
    // Check code quota
    $usage = DB::get_code_usage($code);
    $max = $codes[$code]['max'];
    if ($usage >= $max) {
        $errors['field' . $promo_field_id] = 'This code has reached its usage limit.';
    }
    
    return $errors;
}, 10, 2);

// Apply discount after validation
add_action('frm_after_create_entry', function($entry_id, $form_id) {
    $mgr = Manager::get_instance();
    
    if ((int) $form_id !== (int) $mgr->s('form_id')) {
        return;
    }
    
    if (!$mgr->is_active()) {
        return;
    }
    
    $promo_field_id = (int) $mgr->s('promo_field_id');
    $code = ff_get_entry_meta($entry_id, $promo_field_id);
    
    if (empty($code)) {
        return;
    }
    
    // Final validation and recording
    $result = $mgr->validate_and_record($code, $entry_id);
    
    if (!$result['valid']) {
        error_log('[HPM] Validation failed after submission: ' . $result['message']);
        // Update field to show error
        ff_update_entry_meta($entry_id, $promo_field_id, 'INVALID: ' . $code);
    } else {
        error_log('[HPM] Code validated: ' . $code . ' for category: ' . $result['category']);
    }
}, 20, 2);
```

**Files to Update:**
- [ ] `src/hooks.php` - Add validation hook
- [ ] `src/hooks.php` - Update entry creation hook

---

## Phase 5: REST API Updates

### 5.1 Update Counter Endpoint

**Modify `src/rest.php`:**

```php
register_rest_route('promo/v1', '/counter', [
    'methods' => 'GET',
    'callback' => function() {
        DB::maybe_create_tables();
        
        $mgr = Manager::get_instance();
        if (!$mgr->is_active()) {
            return rest_ensure_response(['active' => false]);
        }
        
        $codes = $mgr->s('promo_codes');
        $code_stats = [];
        
        foreach ($codes as $code => $config) {
            $usage = DB::get_code_usage($code);
            $code_stats[$code] = [
                'used' => (int) $usage,
                'max' => (int) $config['max'],
                'remaining' => max(0, $config['max'] - $usage),
                'description' => $config['description']
            ];
        }
        
        $total_max = (int) $mgr->s('total_max');
        $total_used = DB::count_entries();
        
        return rest_ensure_response([
            'active' => true,
            'codes' => $code_stats,
            'total' => [
                'used' => $total_used,
                'max' => $total_max,
                'remaining' => max(0, $total_max - $total_used)
            ],
            'pricing' => [
                'base' => (float) $mgr->s('base_price'),
                'discount' => (float) $mgr->s('discount_amount'),
                'final' => (float) $mgr->s('final_price')
            ],
            'end_time' => $this->get_end_timestamp()
        ]);
    },
    'permission_callback' => '__return_true',
]);

// NEW: Validate code endpoint
register_rest_route('promo/v1', '/validate', [
    'methods' => 'POST',
    'callback' => function($request) {
        $code = sanitize_text_field($request->get_param('code'));
        $mgr = Manager::get_instance();
        
        if (!$mgr->is_active()) {
            return rest_ensure_response([
                'valid' => false,
                'message' => 'Promotion period has ended.'
            ]);
        }
        
        $codes = $mgr->s('promo_codes');
        if (!isset($codes[$code])) {
            return rest_ensure_response([
                'valid' => false,
                'message' => 'Invalid promo code.'
            ]);
        }
        
        $usage = DB::get_code_usage($code);
        $max = $codes[$code]['max'];
        
        if ($usage >= $max) {
            return rest_ensure_response([
                'valid' => false,
                'message' => 'This code has reached its usage limit.'
            ]);
        }
        
        return rest_ensure_response([
            'valid' => true,
            'message' => 'Code is valid!',
            'remaining' => $max - $usage
        ]);
    },
    'permission_callback' => '__return_true',
]);
```

**Files to Update:**
- [ ] `src/rest.php` - Refactor `/counter` endpoint
- [ ] `src/rest.php` - Add `/validate` endpoint

---

## Phase 6: Admin Dashboard Enhancements

### 6.1 Settings Page Updates

**Update `src/admin.php`:**

```php
function render_admin_page() {
    $mgr = Manager::get_instance();
    $codes = $mgr->s('promo_codes');
    
    // Display per-code statistics
    echo '<h2>Code Usage Statistics</h2>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>
        <th>Code</th>
        <th>Description</th>
        <th>Used</th>
        <th>Max</th>
        <th>Remaining</th>
        <th>Progress</th>
    </tr></thead>';
    echo '<tbody>';
    
    foreach ($codes as $code => $config) {
        $usage = DB::get_code_usage($code);
        $max = $config['max'];
        $remaining = max(0, $max - $usage);
        $percent = $max > 0 ? ($usage / $max) * 100 : 0;
        
        echo '<tr>';
        echo '<td><strong>' . esc_html($code) . '</strong></td>';
        echo '<td>' . esc_html($config['description']) . '</td>';
        echo '<td>' . $usage . '</td>';
        echo '<td>' . $max . '</td>';
        echo '<td>' . $remaining . '</td>';
        echo '<td>';
        echo '<div class="hpm-progress" style="width:200px;height:20px;background:#f0f0f1;border-radius:10px;">';
        echo '<div class="hpm-bar" style="width:' . $percent . '%;height:100%;background:#2271b1;"></div>';
        echo '</div>';
        echo sprintf('%.1f%%', $percent);
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    
    // Category breakdown
    echo '<h2>Registration Categories</h2>';
    $category_stats = DB::get_category_stats();
    // ... render category table
}
```

**Settings Form Fields:**

```php
<!-- Code Configuration Section -->
<h3>Promo Codes (Smart 26)</h3>
<table class="form-table">
    <tr>
        <th>Code 1 (Live Session 1)</th>
        <td>
            <input type="text" name="promo_codes[SMART26-LIVE1][name]" 
                   value="SMART26-LIVE1" style="width:200px;">
            <input type="number" name="promo_codes[SMART26-LIVE1][max]" 
                   value="50" style="width:80px;"> slots
        </td>
    </tr>
    <!-- Repeat for LIVE2, LIVE3, LIVE4 -->
</table>

<!-- Pricing Section -->
<h3>Pricing</h3>
<table class="form-table">
    <tr>
        <th>Base Price (RM)</th>
        <td><input type="number" step="0.01" name="base_price" value="200.00"></td>
    </tr>
    <tr>
        <th>Discount Amount (RM)</th>
        <td><input type="number" step="0.01" name="discount_amount" value="52.00"></td>
    </tr>
    <tr>
        <th>Final Price (RM)</th>
        <td><strong>RM <span id="final-price">148.00</span></strong> (auto-calculated)</td>
    </tr>
</table>

<!-- Eligibility Fields -->
<h3>Eligibility Configuration</h3>
<table class="form-table">
    <tr>
        <th>Diagnostic Session Date Field ID</th>
        <td><input type="number" name="diagnostic_date_field_id" value="<?= $opts['diagnostic_date_field_id'] ?>"></td>
    </tr>
    <tr>
        <th>Lead Status Field ID</th>
        <td><input type="number" name="lead_status_field_id" value="<?= $opts['lead_status_field_id'] ?>"></td>
    </tr>
    <tr>
        <th>Branch Selection Field ID</th>
        <td><input type="number" name="branch_field_id" value="<?= $opts['branch_field_id'] ?>"></td>
    </tr>
    <tr>
        <th>Passive Threshold (days)</th>
        <td><input type="number" name="passive_threshold_days" value="90"> days (default: 90 = 3 months)</td>
    </tr>
</table>
```

**Files to Update:**
- [ ] `src/admin.php` - Update `render_admin_page()`
- [ ] `src/admin.php` - Update `sanitize_settings()` for new fields
- [ ] `src/db.php` - Add `get_category_stats()` method

---

## Phase 7: Frontend Updates

### 7.1 Promo Page Template

**Update `template/promo-page.php`:**

```php
<!-- Price Display -->
<div class="price-container">
    <div class="original-price">RM 200.00</div>
    <div class="discount-badge">-RM 52 (26% OFF)</div>
    <div class="final-price">RM 148.00</div>
</div>

<!-- Code Input Section -->
<div class="code-input-section">
    <label for="promo-code">Enter Your Smart 26 Code:</label>
    <input type="text" id="promo-code" placeholder="SMART26-LIVE1" maxlength="20">
    <button id="validate-code">Validate Code</button>
    <div id="validation-message"></div>
</div>

<!-- Live Code Availability -->
<div id="code-availability">
    <h3>Live Session Codes Availability</h3>
    <div id="code-list"></div>
</div>

<script>
// Fetch live code stats
async function updateCodeStats() {
    const response = await fetch('<?= rest_url('promo/v1/counter') ?>');
    const data = await response.json();
    
    if (!data.active) {
        document.getElementById('code-availability').innerHTML = 
            '<p>Promotion has ended.</p>';
        return;
    }
    
    let html = '<ul class="code-stats">';
    for (const [code, stats] of Object.entries(data.codes)) {
        const status = stats.remaining > 0 ? 'Available' : 'Sold Out';
        const statusClass = stats.remaining > 0 ? 'available' : 'sold-out';
        
        html += `<li class="${statusClass}">
            <strong>${code}</strong>: 
            ${stats.remaining} / ${stats.max} slots remaining
            <span class="status">${status}</span>
        </li>`;
    }
    html += '</ul>';
    
    document.getElementById('code-list').innerHTML = html;
}

// Validate code on button click
document.getElementById('validate-code').addEventListener('click', async () => {
    const code = document.getElementById('promo-code').value.trim();
    
    const response = await fetch('<?= rest_url('promo/v1/validate') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code })
    });
    
    const result = await response.json();
    const msgEl = document.getElementById('validation-message');
    
    if (result.valid) {
        msgEl.className = 'success';
        msgEl.innerHTML = '✅ ' + result.message + ' (' + result.remaining + ' slots left)';
    } else {
        msgEl.className = 'error';
        msgEl.innerHTML = '❌ ' + result.message;
    }
});

// Update stats every 5 seconds
setInterval(updateCodeStats, 5000);
updateCodeStats();
</script>

<style>
.code-stats li.sold-out {
    color: #d63638;
    text-decoration: line-through;
}
.code-stats li.available {
    color: #00a32a;
}
.validation-message.success {
    color: #00a32a;
    font-weight: bold;
}
.validation-message.error {
    color: #d63638;
    font-weight: bold;
}
</style>
```

**Files to Update:**
- [ ] `template/promo-page.php` - Update pricing display
- [ ] `template/promo-page.php` - Add code validation UI
- [ ] `template/promo-page.php` - Update JavaScript for multi-code system

---

## Phase 8: Testing & Quality Assurance

### 8.1 Unit Tests

**NEW FILE**: `tests/test-validator.php`

```php
<?php
use PHPUnit\Framework\TestCase;
use HPM\Validator;
use HPM\Manager;

class ValidatorTest extends TestCase
{
    public function testValidCodeWithAvailableQuota() {
        // Mock manager with valid settings
        $mgr = $this->createMock(Manager::class);
        $mgr->method('s')->willReturnMap([
            ['promo_codes', [
                'SMART26-LIVE1' => ['max' => 50]
            ]],
            ['form_id', 13]
        ]);
        $mgr->method('is_active')->willReturn(true);
        
        $validator = new Validator($mgr);
        $result = $validator->validate_code('SMART26-LIVE1', 123);
        
        $this->assertTrue($result['valid']);
    }
    
    public function testInvalidCode() {
        $mgr = $this->createMock(Manager::class);
        $mgr->method('s')->willReturn([]);
        
        $validator = new Validator($mgr);
        $result = $validator->validate_code('INVALID', 123);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('Invalid promo code.', $result['message']);
    }
    
    public function testCodeQuotaExceeded() {
        // Mock DB to return max usage
        // ... test implementation
    }
}
```

**Files to Create:**
- [ ] `tests/test-validator.php`
- [ ] `tests/test-smart26-integration.php`

### 8.2 Integration Tests

```php
class Smart26IntegrationTest extends TestCase
{
    public function testFullWorkflow() {
        // 1. Create entry
        // 2. Enter valid code
        // 3. Verify discount applied
        // 4. Check DB recorded correctly
        // 5. Verify quota decremented
    }
}
```

### 8.3 Manual Testing Checklist

- [ ] Code validation (valid/invalid/expired/full)
- [ ] Per-code quota enforcement (50/50)
- [ ] Total quota cap (200 total)
- [ ] Time window enforcement (48 hours)
- [ ] Eligibility checks (all 4 categories)
- [ ] Admin dashboard displays correct stats
- [ ] REST API returns accurate data
- [ ] Email notifications sent correctly
- [ ] Branch selection recorded
- [ ] Finance reporting (Dana ANP tagging)

---

## Phase 9: Migration & Deployment

### 9.1 Database Migration Script

**NEW FILE**: `migrations/migrate_v1_to_v2.php`

```php
<?php
/**
 * Migration script: v1 (2-tier) → v2 (4-code Smart 26)
 * 
 * Run this ONCE before campaign activation
 */

namespace HPM;

if (!defined('ABSPATH')) exit;

class MigrationV1toV2 {
    
    public static function run() {
        global $wpdb;
        
        echo "Starting migration...\n";
        
        // 1. Alter table structure
        $table = DB::table_name();
        
        $sql = "ALTER TABLE {$table} 
                ADD COLUMN IF NOT EXISTS promo_code VARCHAR(50) NOT NULL AFTER entry_id,
                ADD COLUMN IF NOT EXISTS branch VARCHAR(100) AFTER promo_code,
                ADD COLUMN IF NOT EXISTS user_category VARCHAR(50) AFTER branch,
                ADD COLUMN IF NOT EXISTS eligibility_verified TINYINT(1) DEFAULT 0 AFTER user_category,
                ADD INDEX IF NOT EXISTS idx_code (promo_code),
                ADD INDEX IF NOT EXISTS idx_category (user_category)";
        
        $wpdb->query($sql);
        
        echo "Table structure updated.\n";
        
        // 2. Backfill existing entries (if any)
        // Mark old entries with legacy code
        $wpdb->query("
            UPDATE {$table} 
            SET promo_code = 'LEGACY', 
                user_category = 'unknown',
                eligibility_verified = 0
            WHERE promo_code = '' OR promo_code IS NULL
        ");
        
        echo "Existing entries backfilled.\n";
        
        // 3. Update settings
        $opts = get_option('home_promo_manager_settings', []);
        
        // Preserve old settings, add new ones
        $opts['promo_codes'] = [
            'SMART26-LIVE1' => ['max' => 50, 'description' => 'Live Session 1'],
            'SMART26-LIVE2' => ['max' => 50, 'description' => 'Live Session 2'],
            'SMART26-LIVE3' => ['max' => 50, 'description' => 'Live Session 3'],
            'SMART26-LIVE4' => ['max' => 50, 'description' => 'Live Session 4'],
        ];
        $opts['total_max'] = 200;
        $opts['base_price'] = 200.00;
        $opts['discount_amount'] = 52.00;
        $opts['final_price'] = 148.00;
        $opts['campaign_start'] = '2026-01-12 12:00:00';
        $opts['campaign_end'] = '2026-01-14 11:59:00';
        $opts['passive_threshold_days'] = 90;
        
        update_option('home_promo_manager_settings', $opts);
        
        echo "Settings migrated.\n";
        echo "Migration complete! ✅\n";
    }
}

// Run if executed directly
if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../src/db.php';
    MigrationV1toV2::run();
}
```

**Files to Create:**
- [ ] `migrations/migrate_v1_to_v2.php`

### 9.2 Deployment Checklist

**Pre-Deployment:**
- [ ] Backup database
- [ ] Run migration script on staging
- [ ] Test all 4 promo codes
- [ ] Verify admin dashboard
- [ ] Test REST API endpoints
- [ ] Run full test suite

**Deployment Day (Jan 11, 2026):**
- [ ] Run migration on production (morning)
- [ ] Configure campaign dates in admin
- [ ] Test one code manually
- [ ] Monitor error logs
- [ ] Notify marketing team

**Campaign Day (Jan 12, 12:00 PM):**
- [ ] Verify campaign auto-activated
- [ ] Monitor first 10 registrations
- [ ] Check quota tracking
- [ ] Watch for validation errors

**Post-Campaign (Jan 14, 12:00 PM):**
- [ ] Verify campaign auto-deactivated
- [ ] Export registration data
- [ ] Generate Finance report (Dana ANP)
- [ ] Archive campaign data

---

## Phase 10: Documentation

### 10.1 Admin User Guide

**NEW FILE**: `docs/ADMIN_GUIDE_SMART26.md`

```markdown
# Smart 26 Campaign Admin Guide

## Pre-Campaign Setup

1. Navigate to **Settings > HOME Promo Manager**
2. Configure campaign dates:
   - Start: 2026-01-12 12:00:00
   - End: 2026-01-14 11:59:00
3. Verify promo codes:
   - SMART26-LIVE1 (50 slots)
   - SMART26-LIVE2 (50 slots)
   - SMART26-LIVE3 (50 slots)
   - SMART26-LIVE4 (50 slots)
4. Set Formidable field IDs:
   - Diagnostic Date Field: [ID]
   - Lead Status Field: [ID]
   - Branch Field: [ID]

## Monitoring During Campaign

- Dashboard shows real-time code usage
- Email alerts at 25, 50, 75, 100 registrations per code
- Check quota frequently during Live sessions

## Post-Campaign

- Export data: **Tools > Export Smart 26 Data**
- Generate Finance report for Dana ANP claims
```

**Files to Create:**
- [ ] `docs/ADMIN_GUIDE_SMART26.md`
- [ ] `docs/API_REFERENCE.md`
- [ ] `docs/TROUBLESHOOTING.md`

### 10.2 Update README

**Update `README.md`:**

```markdown
## Smart 26 Campaign Features

- ✅ 4 distinct promo codes with individual quotas
- ✅ User-entered code validation
- ✅ 4-tier eligibility checking
- ✅ Per-code usage tracking
- ✅ Real-time quota monitoring
- ✅ Branch selection recording
- ✅ Finance integration (Dana ANP)
```

---

## Summary: Files Changed

### New Files (11)
- [ ] `src/Validator.php` - Code validation logic
- [ ] `tests/test-validator.php` - Unit tests
- [ ] `tests/test-smart26-integration.php` - Integration tests
- [ ] `migrations/migrate_v1_to_v2.php` - Migration script
- [ ] `docs/ADMIN_GUIDE_SMART26.md` - Admin documentation
- [ ] `docs/API_REFERENCE.md` - API docs
- [ ] `docs/TROUBLESHOOTING.md` - Support docs
- [ ] `SMART26_IMPLEMENTATION_PLAN.md` - This document

### Modified Files (10)
- [ ] `src/db.php` - Schema changes, new methods
- [ ] `src/Manager.php` - Validation logic, code tracking
- [ ] `src/admin.php` - Settings UI, dashboard
- [ ] `src/hooks.php` - Validation hooks
- [ ] `src/rest.php` - API endpoints
- [ ] `template/promo-page.php` - Frontend UI
- [ ] `home-promo-manager.php` - Include new files
- [ ] `README.md` - Feature updates
- [ ] `RELEASE_NOTES.md` - Version 2.0.0 changelog
- [ ] `phpunit.xml` - Test configuration

---

## Timeline Estimate

| Phase | Duration | Dependencies |
|-------|----------|--------------|
| Phase 1: Database | 1 day | None |
| Phase 2: Settings | 1 day | Phase 1 |
| Phase 3: Core Logic | 2 days | Phase 1-2 |
| Phase 4: FF Integration | 1 day | Phase 3 |
| Phase 5: REST API | 1 day | Phase 3 |
| Phase 6: Admin UI | 1 day | Phase 2-3 |
| Phase 7: Frontend | 1 day | Phase 5 |
| Phase 8: Testing | 2 days | Phase 1-7 |
| Phase 9: Migration | 1 day | Phase 8 |
| Phase 10: Documentation | 1 day | Phase 1-9 |
| **Total** | **12 days** | |

**Recommended Start Date**: December 30, 2025  
**Code Freeze**: January 10, 2026  
**Production Deployment**: January 11, 2026  
**Campaign Launch**: January 12, 2026 12:00 PM

---

## Risk Mitigation

### Critical Risks

1. **Race Condition**: Multiple users redeeming same code simultaneously
   - Mitigation: Use database transactions in `insert_entry_with_code()`
   
2. **Code Quota Overflow**: 51st user gets through
   - Mitigation: Atomic check-and-insert query with row locking

3. **Time Window Drift**: Server timezone mismatch
   - Mitigation: All timestamps use configured timezone, logged for verification

4. **Eligibility False Positives**: Ineligible users pass validation
   - Mitigation: Multi-layer checks (frontend, backend, database)

5. **Data Migration Failure**: Existing data corrupted
   - Mitigation: Full database backup, rollback plan, dry-run on staging

---

## Success Criteria

- [ ] All 200 slots can be redeemed (no more, no less)
- [ ] Each code caps at exactly 50 redemptions
- [ ] Campaign auto-starts at Jan 12, 12:00 PM
- [ ] Campaign auto-ends at Jan 14, 11:59 AM
- [ ] Ineligible users cannot redeem codes
- [ ] Admin dashboard shows real-time stats
- [ ] Finance receives tagged data for Dana ANP
- [ ] Zero critical bugs during 48-hour window

---

## Rollback Plan

If critical issues occur during campaign:

1. **Immediate**: Disable campaign via admin (set end date to past)
2. **Short-term**: Manually process valid registrations offline
3. **Recovery**: Fix issue, re-enable with adjusted quotas
4. **Last Resort**: Revert to v1.4.4 code, manual promo code tracking

---

**Document Version**: 1.0  
**Last Updated**: 2026-01-02  
**Author**: Copilot AI + Wan Aqil Hazim  
**Status**: Ready for Implementation
