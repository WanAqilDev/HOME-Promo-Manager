# 🔍 Test Harness Validation Guide

## How to Verify Test Harness Accuracy & Reliability

### Overview
The test harness simulates promo code validation and recording. This guide helps you verify that the test harness produces accurate results that match real production behavior.

---

## ✅ Validation Method 1: Cross-Check with Database

### Step 1: Run a Test
```
1. Go to Tools → Promo Test Harness
2. Run test: Valid Code + Valid Time
3. Note the entry_id (e.g., 999901)
4. Note the result (SUCCESS or FAILURE)
```

### Step 2: Verify in Database
```sql
-- Check if entry was recorded
SELECT * FROM wp_home_promo_counted 
WHERE entry_id = 999901;

-- Expected result:
-- If test showed SUCCESS: Row should exist with correct code
-- If test showed FAILURE: Row should NOT exist
```

### Step 3: Check Quota Impact
```sql
-- Get code usage count
SELECT promo_code, COUNT(*) as count 
FROM wp_home_promo_counted 
WHERE promo_code = 'SMART26-LIVE1' 
GROUP BY promo_code;

-- Compare with admin dashboard
-- Numbers should match exactly
```

### Validation Checklist
- [ ] Test entry appears in database after SUCCESS
- [ ] Test entry does NOT appear after FAILURE
- [ ] Quota count matches admin dashboard
- [ ] Code field contains exact code used
- [ ] Category field matches test selection
- [ ] Branch field matches test input

---

## ✅ Validation Method 2: Compare with Real Form Submission

### Setup Test Environment
1. Create identical promo code in both systems
2. Note initial quota (e.g., 10/50)
3. Prepare test data

### Run Parallel Tests

#### Test A: Test Harness
```
1. Tools → Promo Test Harness
2. Code: SMART26-TEST
3. Time: During promo
4. Result: ✅ SUCCESS
5. New quota: 11/50
```

#### Test B: Real Form
```
1. Fill out actual Formidable Form
2. Enter code: SMART26-TEST
3. Submit form
4. Check if code is accepted
5. New quota: 12/50
```

### Cross-Validation
```sql
-- Both should create entries
SELECT entry_id, promo_code, user_category, branch 
FROM wp_home_promo_counted 
WHERE promo_code = 'SMART26-TEST'
ORDER BY entry_id DESC 
LIMIT 2;

-- Compare field values:
-- ✓ promo_code should match
-- ✓ category should be assigned
-- ✓ branch should be recorded
-- ✓ Both counted toward quota
```

### Expected Outcome
| Aspect | Test Harness | Real Form | Should Match? |
|--------|--------------|-----------|---------------|
| Code accepted | ✅ | ✅ | YES |
| Quota incremented | ✅ | ✅ | YES |
| Database entry | ✅ | ✅ | YES |
| Category assigned | ✅ | ✅ | YES |
| Validation errors | Same | Same | YES |

---

## ✅ Validation Method 3: Quota Limit Testing

### Test: Verify Quota Enforcement

#### Setup
```sql
-- Manually set code to near-capacity
-- Example: SMART26-LIMIT with max=5

-- Add 4 test entries
INSERT INTO wp_home_promo_counted (entry_id, promo_code, branch, user_category)
VALUES 
(999801, 'SMART26-LIMIT', 'Test', 'new'),
(999802, 'SMART26-LIMIT', 'Test', 'new'),
(999803, 'SMART26-LIMIT', 'Test', 'new'),
(999804, 'SMART26-LIMIT', 'Test', 'new');

-- Quota now: 4/5 (1 slot remaining)
```

#### Test Sequence
```
Test 1: 5th registration (last slot)
Code: SMART26-LIMIT
Expected: ✅ SUCCESS
Quota after: 5/5

Test 2: 6th registration (over limit)
Code: SMART26-LIMIT
Expected: ❌ FAILURE - "No slots remaining"
Quota after: 5/5 (unchanged)
```

#### Database Verification
```sql
-- Count should be exactly 5
SELECT COUNT(*) FROM wp_home_promo_counted 
WHERE promo_code = 'SMART26-LIMIT';
-- Result: 5

-- Try to manually insert 6th (should fail)
INSERT INTO wp_home_promo_counted (entry_id, promo_code, branch, user_category)
VALUES (999806, 'SMART26-LIMIT', 'Test', 'new');
-- Should succeed (no database constraint)

-- But test harness validation should prevent this
```

### Validation Checklist
- [ ] Last slot (n/n) is accepted
- [ ] Over-limit (n+1/n) is rejected
- [ ] Error message is clear
- [ ] Quota count is accurate
- [ ] No phantom entries in database

---

## ✅ Validation Method 4: Time Override Testing

### Test: Verify Time Simulation

#### Test A: Before Promo Start
```
Setup in main settings:
  Start: 2026-01-12 12:00:00
  End: 2026-01-24 23:59:00

Test Harness:
  Code: SMART26-LIVE1
  Time Override: "Before start"
  Expected: ❌ FAILURE
  Message: "Promo has not started yet"
```

#### Verify Time Logic
```php
// Check what time override does in test-harness.php
case 'before_start':
    $opts['start'] = date('Y-m-d H:i:s', strtotime('+1 day'));
    $opts['end'] = date('Y-m-d H:i:s', strtotime('+2 days'));
    break;

// This sets start to tomorrow, so promo is "not started"
```

#### Cross-Check with Manual Time Change
```
1. Go to Settings → HOME Promo Manager
2. Change start date to tomorrow
3. Try to validate code via REST API:
   POST /wp-json/promo/v1/validate
   { "code": "SMART26-LIVE1" }

4. Compare response with test harness result
   - Both should return: "valid": false
   - Both should say: "Promo has not started"
```

### Validation Checklist
- [ ] Time override changes settings temporarily
- [ ] Settings restore after test
- [ ] "Before start" rejects codes
- [ ] "After end" rejects codes
- [ ] "During promo" accepts codes
- [ ] Real API gives same results

---

## ✅ Validation Method 5: Code Validation Logic

### Test Each Validation Rule

#### Rule 1: Empty Code (Optional)
```
Test Harness: Code = [empty]
Expected: ✅ SUCCESS with message "No code provided - this is allowed"

Real Form: Leave promo code field empty
Expected: Form submits successfully (no validation error)

Verify: Both allow empty codes
```

#### Rule 2: Invalid Format
```
Test Harness: Code = "INVALID@#$%"
Expected: ❌ FAILURE "Invalid code format"

Database Check: No entry created
```

#### Rule 3: Code Not Found
```
Test Harness: Code = "NONEXISTENT-CODE"
Expected: ❌ FAILURE "Code not found"

Cross-check with Manager::validate_code():
$mgr = \HPM\Manager::get_instance();
$result = $mgr->validate_code('NONEXISTENT-CODE');
var_dump($result);
// Should match test harness result
```

#### Rule 4: Inactive Code
```
Setup: Deactivate SMART26-LIVE1 in admin UI

Test Harness: Code = "SMART26-LIVE1"
Expected: ❌ FAILURE "Code is inactive"

Verify in database:
SELECT * FROM wp_options 
WHERE option_name = 'home_promo_manager_settings';
// Check promo_codes['SMART26-LIVE1']['active'] = false
```

### Validation Checklist
- [ ] Empty code passes (optional behavior)
- [ ] Invalid format fails
- [ ] Non-existent code fails
- [ ] Inactive code fails
- [ ] Error messages match validation rules
- [ ] Manager::validate_code() gives same results

---

## ✅ Validation Method 6: Automated Test Suite Verification

### Run Suite and Cross-Check

```
1. Click "Run Full Test Suite" button
2. Note all results (18 tests)
3. Manually verify 3-5 random tests
```

### Example Verification

#### Suite Test #1: "Valid code during promo"
```
Automated Result: ✅ PASS

Manual Verification:
1. Run same test manually
2. Code: SMART26-LIVE1
3. Time: During promo
4. Result: Should also be ✅ SUCCESS

Database Check:
SELECT COUNT(*) FROM wp_home_promo_counted 
WHERE promo_code = 'SMART26-LIVE1' 
AND entry_id >= 999900;
// Count should increase by 2 (automated + manual)
```

#### Suite Test #7: "Case insensitive code"
```
Automated Result: ✅ PASS

Manual Verification:
Test Harness: Code = "smart26-live1" (lowercase)
Expected: ✅ SUCCESS

Code Inspection:
// Check test-harness.php normalize code
$code = strtoupper(trim($code));
// Confirms case-insensitive handling
```

### Validation Checklist
- [ ] Manual tests match automated results
- [ ] Pass/fail counts are accurate
- [ ] Database reflects automated test runs
- [ ] Can reproduce any automated test manually
- [ ] Edge cases behave as expected

---

## ✅ Validation Method 7: Race Condition Testing

### Critical Test: Atomic Query Verification

#### Setup
```sql
-- Set code to 1 slot remaining
DELETE FROM wp_home_promo_counted WHERE promo_code = 'SMART26-RACE';

-- Add 49 entries
-- (Script or manual inserts to get 49/50)

-- Verify count
SELECT COUNT(*) FROM wp_home_promo_counted 
WHERE promo_code = 'SMART26-RACE';
-- Should return: 49
```

#### Simultaneous Request Simulation

**Using Test Harness** (Slower, but safe):
```
1. Open 2 browser tabs
2. Both go to Test Harness
3. Both select code: SMART26-RACE
4. Click "Run Test" in both tabs VERY quickly
5. Expected: Only 1 succeeds

Database Check:
SELECT COUNT(*) FROM wp_home_promo_counted 
WHERE promo_code = 'SMART26-RACE';
-- Should be exactly 50, not 51
```

**Using cURL** (Faster, more realistic):
```bash
# Create test script
for i in {1..5}; do
  curl -X POST http://yoursite.com/wp-admin/admin-ajax.php \
    -d "action=hpm_test_validate&code=SMART26-RACE" &
done
wait

# Check final count
# Should still be 50, not 50+
```

#### Verify Atomic Query
```php
// Check db.php insert_entry_with_code() uses:
INSERT IGNORE INTO {$table} 
SELECT %d, %s, %s, %s, 1 FROM DUAL
WHERE (SELECT COUNT(*) FROM {$table} WHERE promo_code = %s) < %d;

// This is atomic - prevents race conditions
```

### Validation Checklist
- [ ] Only 1 request succeeds when at capacity
- [ ] Quota never exceeds max
- [ ] No phantom entries created
- [ ] Atomic query prevents over-allocation
- [ ] Error message clear for rejected requests

---

## ✅ Validation Method 8: Error Message Accuracy

### Compare Error Messages

| Scenario | Test Harness Message | Expected Manager Message | Match? |
|----------|---------------------|-------------------------|--------|
| Code not found | "Code not found" | "Invalid promo code" | ✓ |
| Promo not started | "Promo has not started yet" | Same | ✓ |
| Promo ended | "Promo has ended" | Same | ✓ |
| No slots | "No slots remaining" | "This code has reached capacity" | ✓ |
| Inactive code | "Code is inactive" | "This code is no longer active" | ✓ |
| Empty code | "No code provided - this is allowed" | N/A (optional) | ✓ |

### Verify Message Sources
```php
// Test harness calls Manager::validate_code()
// Check src/Manager.php for exact messages

// Example from Manager.php:
if (!$this->is_active()) {
    return [
        'valid' => false,
        'message' => 'Promo period is not active',
        'remaining' => 0
    ];
}

// Test harness should return same message
```

---

## 🎯 Complete Validation Checklist

### Core Functionality
- [ ] Test results match database state
- [ ] Quota counts are accurate
- [ ] Success creates database entry
- [ ] Failure does NOT create entry
- [ ] Time override works correctly
- [ ] Settings restore after time override

### Validation Logic
- [ ] Valid codes accepted during promo
- [ ] Invalid codes rejected
- [ ] Empty codes allowed (optional)
- [ ] Inactive codes rejected
- [ ] Full quota codes rejected
- [ ] Case insensitive matching works
- [ ] Whitespace is trimmed

### Quota System
- [ ] Each code tracks separately
- [ ] Quota limits are enforced
- [ ] Cannot exceed max capacity
- [ ] Race conditions prevented
- [ ] Cross-code isolation maintained

### Time Validation
- [ ] Before start: Codes rejected
- [ ] During promo: Codes accepted
- [ ] After end: Codes rejected
- [ ] Time override simulates correctly
- [ ] Real-time checks match test harness

### Database Integrity
- [ ] No duplicate entries
- [ ] Unique constraints enforced
- [ ] Test entries use 999900+ range
- [ ] Clear function removes test data
- [ ] Production data unaffected

### Error Handling
- [ ] Error messages are accurate
- [ ] Messages match Manager class
- [ ] User-friendly language
- [ ] Technical details available
- [ ] Consistent across scenarios

---

## 🚨 Red Flags (Issues to Watch For)

### Warning Sign 1: Mismatched Quota
```
Test harness shows: 25/50
Admin dashboard shows: 24/50
❌ PROBLEM: Counts don't match

Solution: Check database directly
SELECT COUNT(*) FROM wp_home_promo_counted WHERE promo_code = 'X';
```

### Warning Sign 2: Test Succeeds, Real Form Fails
```
Test harness: ✅ Code accepted
Real form: ❌ Validation error
❌ PROBLEM: Test harness not accurate

Solution: Compare validation logic
- Check hooks.php frm_validate_entry
- Check Manager::validate_code()
- Ensure both use same rules
```

### Warning Sign 3: Quota Exceeded
```
Max quota: 50
Database count: 52
❌ PROBLEM: Quota limit not enforced

Solution: Check atomic query in db.php
- Ensure INSERT...SELECT...WHERE is used
- Test race condition handling
- Verify database supports subqueries
```

### Warning Sign 4: Test Data Persists
```
Click "Clear Test Data"
Test entries still in database
❌ PROBLEM: Clear function not working

Solution: Check entry_id range
DELETE FROM wp_home_promo_counted WHERE entry_id >= 999900;
```

---

## 📊 Reliability Testing Protocol

### Daily Testing (During Development)
1. Run automated suite (18 tests)
2. Verify 100% pass rate
3. Spot-check 2-3 manual tests
4. Compare quota with database
5. Clear test data

### Weekly Testing (Pre-Launch)
1. Full manual test of all 18 scenarios
2. Cross-check each with real form
3. Database integrity verification
4. Race condition testing
5. Performance testing (multiple simultaneous tests)

### Pre-Production Testing
1. Test with actual production data structure
2. Verify all validation rules
3. Confirm quota limits work
4. Test time boundaries (exact start/end times)
5. Simulate high load (20+ concurrent tests)
6. Document any discrepancies

---

## 🔧 Troubleshooting Test Harness Issues

### Issue: Test hangs or times out
```
Check:
1. PHP max_execution_time setting
2. Database connection
3. Large dataset (slow queries)
4. JavaScript console errors
```

### Issue: Results don't match expectations
```
Debug:
1. Enable debug mode in main settings
2. Check wp-content/debug.log
3. Review Manager::validate_code() logic
4. Compare with real form validation
```

### Issue: Database not updating
```
Verify:
1. Database user has INSERT permission
2. Table exists (wp_home_promo_counted)
3. Unique constraint not violated
4. Check for PHP/MySQL errors
```

---

## ✅ Final Confidence Check

Before trusting test harness results:

1. **✓ Database Cross-Check**: At least 5 tests verified in database
2. **✓ Real Form Comparison**: At least 3 parallel tests with actual form
3. **✓ Quota Enforcement**: Tested max capacity scenario
4. **✓ Time Validation**: All 3 time periods tested (before/during/after)
5. **✓ Edge Cases**: Empty code, invalid format, case sensitivity verified
6. **✓ Race Condition**: Last-slot test completed successfully
7. **✓ Error Messages**: Match Manager class messages
8. **✓ Automated Suite**: 100% pass rate on automated tests

**When all 8 checks pass, test harness is validated as accurate and reliable.** ✅

---

## 📝 Documentation

Keep a testing log:
```
Date: 2026-01-08
Test: Valid code + valid time
Harness Result: ✅ SUCCESS
Database Check: Entry found (ID 999901)
Quota: 25/50 → 26/50 (matches dashboard)
Status: ✅ VALIDATED
```

This creates an audit trail proving test harness accuracy.
