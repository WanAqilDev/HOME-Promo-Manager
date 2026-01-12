-- HOME Promo Manager - Settings Restoration Script
-- This script analyzes your home_promo_counted table and restores plugin settings

-- Step 1: Check current usage per code
SELECT 
    promo_code,
    COUNT(*) as current_usage,
    GROUP_CONCAT(DISTINCT user_category) as categories_used,
    GROUP_CONCAT(DISTINCT branch) as branches_used
FROM home_promo_counted
GROUP BY promo_code
ORDER BY promo_code;

-- Copy the results above, then run the appropriate INSERT below based on your codes

-- ==============================================================================
-- EXAMPLE RESTORATION (CUSTOMIZE THIS BASED ON STEP 1 RESULTS)
-- ==============================================================================

-- If you had 4 codes with 50 max each (adjust based on your actual setup):
-- DELETE FROM wp_options WHERE option_name = 'home_promo_manager_settings';

-- INSERT INTO wp_options (option_name, option_value, autoload) VALUES (
--   'home_promo_manager_settings',
--   'a:13:{s:7:"form_id";s:2:"13";s:15:"promo_field_id";s:4:"3170";s:16:"status_field_id";s:3:"199";s:21:"pasif_date_field_id";s:4:"3172";s:15:"branch_field_id";s:4:"3171";s:21:"code_assignment_mode";s:6:"manual";s:11:"promo_codes";a:4:{s:13:"SMART26-LIVE1";a:4:{s:3:"max";i:50;s:11:"description";s:14:"Live Session 1";s:6:"active";b:1;s:9:"is_legacy";b:0;}s:13:"SMART26-LIVE2";a:4:{s:3:"max";i:50;s:11:"description";s:14:"Live Session 2";s:6:"active";b:1;s:9:"is_legacy";b:0;}s:13:"SMART26-LIVE3";a:4:{s:3:"max";i:50;s:11:"description";s:14:"Live Session 3";s:6:"active";b:1;s:9:"is_legacy";b:0;}s:15:"SMART26-ONLINE1";a:4:{s:3:"max";i:100;s:11:"description";s:15:"Online Session";s:6:"active";b:1;s:9:"is_legacy";b:0;}}s:10:"debug_mode";s:1:"0";s:8:"timezone";s:17:"Asia/Kuala_Lumpur";s:10:"start_date";s:10:"2026-01-01";s:8:"end_date";s:10:"2026-12-31";s:15:"max_redemptions";s:3:"200";s:9:"tier1_max";s:2:"50";}',
--   'yes'
-- );

-- ==============================================================================
-- QUICK RESTORE GENERATOR
-- ==============================================================================
-- Run this to see the exact serialized format you need:

SELECT 
    promo_code,
    COUNT(*) as current_usage,
    -- Suggested max = current usage + 20% buffer (round up to nearest 10)
    CEILING((COUNT(*) * 1.2) / 10) * 10 as suggested_max
FROM home_promo_counted
GROUP BY promo_code
ORDER BY promo_code;

-- ==============================================================================
-- MANUAL RESTORATION ALTERNATIVE
-- ==============================================================================
-- If the serialized format is too complex, just copy this template:

/*
After running this script, go to WordPress admin:
1. Settings → HOME Promo Manager
2. Scroll to "Code Management"
3. For each code shown in Step 1 results above, click "Add New Promo Code":
   
   Example based on your data:
   - Code Name: SMART26-LIVE1
   - Description: Live Session 1
   - Max Quota: [current_usage + 20 buffer] (e.g., if usage=42, set max=60)
   
4. Click "Save Settings"

The counts will automatically sync from the database!
*/
