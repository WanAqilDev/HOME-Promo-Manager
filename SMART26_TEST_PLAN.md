# SMART26 Testing Plan

**Campaign Launch:** January 12, 2026  
**Testing Deadline:** January 11, 2026  
**Test Environment:** Staging/Development  
**Form ID:** 13  

---

## 🎯 Testing Objectives

1. Verify SMART26 multi-code system functions correctly
2. Ensure backward compatibility with legacy auto-assign mode
3. Validate all user registration and reactivation flows
4. Confirm code quota enforcement and tracking
5. Test API endpoints for frontend integration
6. Verify admin dashboard functionality

---

## 📋 Pre-Testing Setup

### Environment Preparation
- [ ] Backup production database
- [ ] Set up test WordPress environment
- [ ] Install latest plugin version from feature/smart26-dynamic-codes branch
- [ ] Configure test promo codes in admin:
  ```
  TEST-CODE1: max=5, description="Test Code 1", active=true
  TEST-CODE2: max=3, description="Test Code 2", active=true
  INACTIVE-CODE: max=10, description="Inactive Test", active=false
  ```
- [ ] Set campaign dates to current date/time for testing
- [ ] Enable debug mode in plugin settings

### Test Data Requirements
- [ ] Create 3-5 test Formidable entries with various statuses
- [ ] At least 1 entry with status='2' (Pasif) and pasif_date > 90 days ago
- [ ] At least 1 entry with recent diagnostic date (< 90 days)
- [ ] At least 1 entry marked as Lead

---

## 🧪 Test Cases

### **SECTION 1: Admin Dashboard**

#### Test 1.1: Code Assignment Mode Toggle
**Objective:** Verify mode switching works correctly

**Steps:**
1. Navigate to plugin settings page
2. Observe current mode display
3. Click "Auto-Assign (Legacy)" card
4. Save settings
5. Verify mode changes to 'auto'
6. Click "User-Entered Codes (SMART26)" card
7. Save settings
8. Verify mode changes to 'manual'

**Expected Results:**
- Mode toggle visual feedback works
- Settings save without errors
- Mode persists after page reload
- No console errors

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 1.2: Add New Promo Code
**Objective:** Verify adding new codes via admin UI

**Steps:**
1. Go to "Promo Code Management" section
2. Enter code name: `TEST-NEW-CODE`
3. Enter description: `New Test Code`
4. Enter max quota: `10`
5. Click "Add Code"
6. Check if code appears in statistics table
7. Reload page and verify code persists

**Expected Results:**
- Code saves successfully
- Shows in statistics table with 0/10 usage
- Green progress bar displays
- No duplicate code allowed

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 1.3: Edit Existing Code Quota
**Objective:** Verify quota editing with validation

**Steps:**
1. Find code with 0 usage
2. Increase quota from 5 to 10
3. Save and verify
4. Create 2 test entries using this code
5. Try to reduce quota from 10 to 1 (below usage)
6. Observe validation error

**Expected Results:**
- Increasing quota works
- Decreasing below usage is blocked with error message
- Statistics update correctly

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 1.4: Code Statistics Accuracy
**Objective:** Verify real-time stats are accurate

**Steps:**
1. Note current usage for TEST-CODE1
2. Create new entry with TEST-CODE1
3. Reload admin page
4. Compare usage count

**Expected Results:**
- Usage count increments by 1
- Remaining decreases by 1
- Progress bar updates
- Percentage calculates correctly

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

### **SECTION 2: Form Validation (SMART26 Mode)**

#### Test 2.1: Submit Form WITH Valid Code
**Objective:** Verify valid code submission succeeds

**Steps:**
1. Set mode to 'manual' (SMART26)
2. Fill out Form 13
3. Select Daftar = "Ya"
4. Enter promo code: `TEST-CODE1` (with available slots)
5. Select a branch
6. Submit form

**Expected Results:**
- Form submits successfully
- Entry created with entry_id
- Promo code field = `TEST-CODE1`
- Entry appears in `home_promo_counted` table with:
  - `promo_code` = `TEST-CODE1`
  - `branch` = selected branch
  - `user_category` = `new` (or appropriate)
  - `eligibility_verified` = 1
- No validation errors

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 2.2: Submit Form WITHOUT Code (Optional)
**Objective:** Verify users can submit without promo code

**Steps:**
1. Set mode to 'manual' (SMART26)
2. Fill out Form 13
3. Select Daftar = "Ya"
4. Leave promo code field EMPTY
5. Submit form

**Expected Results:**
- Form submits successfully
- Entry created without promo tracking
- No entry in `home_promo_counted` table
- No validation errors
- User can still complete registration

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 2.3: Submit Form WITH Invalid Code
**Objective:** Verify invalid codes are rejected

**Steps:**
1. Fill out Form 13
2. Select Daftar = "Ya"
3. Enter promo code: `INVALID-CODE-123`
4. Submit form

**Expected Results:**
- Form submission blocked
- Validation error displays on promo field: "Invalid promo code."
- Entry NOT created
- User must fix error to proceed

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 2.4: Submit Form WITH Full Code
**Objective:** Verify quota enforcement

**Steps:**
1. Use TEST-CODE2 (max=3)
2. Create 3 entries with TEST-CODE2
3. Verify quota is full (3/3)
4. Try to submit 4th entry with TEST-CODE2

**Expected Results:**
- 4th submission blocked
- Validation error: "This promo code has reached its maximum limit."
- Quota remains at 3/3
- User cannot bypass limit

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 2.5: Submit Form WITH Inactive Code
**Objective:** Verify inactive codes are rejected

**Steps:**
1. Fill out Form 13
2. Select Daftar = "Ya"
3. Enter promo code: `INACTIVE-CODE`
4. Submit form

**Expected Results:**
- Form submission blocked
- Validation error: "This promo code is no longer active."
- Entry NOT created

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 2.6: Submit Form WITH Code (Daftar = Tidak)
**Objective:** Verify code validation skipped when not registering

**Steps:**
1. Fill out Form 13
2. Select Daftar = "Tidak"
3. Enter any promo code (even invalid)
4. Submit form

**Expected Results:**
- Form submits successfully
- No validation performed
- Entry created but not counted in promo
- No entry in `home_promo_counted` table

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

### **SECTION 3: Category Detection**

#### Test 3.1: New User Category
**Objective:** Verify new users categorized as 'new'

**Steps:**
1. Create fresh entry (no diagnostic date, no lead status)
2. Submit with Daftar = Ya and valid code
3. Check database `user_category` field

**Expected Results:**
- `user_category` = `new`

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 3.2: Diagnostic Category
**Objective:** Verify diagnostic users detected

**Steps:**
1. Create entry with diagnostic_date_field_id set to 30 days ago
2. Submit with Daftar = Ya and valid code
3. Check database `user_category` field

**Expected Results:**
- `user_category` = `diagnostic`

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 3.3: Lead Category
**Objective:** Verify lead users detected

**Steps:**
1. Create entry with lead_status_field_id = "Lead"
2. Submit with Daftar = Ya and valid code
3. Check database `user_category` field

**Expected Results:**
- `user_category` = `lead`

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

### **SECTION 4: Reactivation Flow**

#### Test 4.1: Reactivation WITHOUT Code (SMART26)
**Objective:** Verify reactivation requires code in SMART26 mode

**Steps:**
1. Set mode to 'manual'
2. Find entry with status='2', pasif_date > 90 days ago
3. Leave promo code field empty
4. Change status from '2' to '1'
5. Save entry

**Expected Results:**
- Reactivation fails (no code provided)
- Entry NOT added to `home_promo_counted`
- No reactivation logged
- Debug log shows: "No promo code provided for reactivation"

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 4.2: Reactivation WITH Valid Code
**Objective:** Verify reactivation works with valid code

**Steps:**
1. Set mode to 'manual'
2. Find entry with status='2', pasif_date > 90 days ago
3. Enter promo code: `TEST-CODE1` in promo field
4. Change status from '2' to '1'
5. Save entry
6. Check databases

**Expected Results:**
- Reactivation succeeds
- Entry added to `home_promo_counted` with:
  - `promo_code` = `TEST-CODE1`
  - `user_category` = `passive`
- Entry logged in `home_promo_reactivations` table
- Promo field updated with code
- Debug log confirms reactivation

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 4.3: Reactivation Edge Case (Partial Registration)
**Objective:** Verify same-day registration bypass

**Steps:**
1. Create entry today with status='2', pasif_date = today
2. Immediately change status to '1' with valid code
3. Check reactivation logic

**Expected Results:**
- Reactivation allowed (bypasses 90-day check)
- Debug log shows: "Partial registration detected"
- Entry counted as reactivation

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

### **SECTION 5: Legacy Auto-Assign Mode**

#### Test 5.1: Auto Mode Registration
**Objective:** Verify legacy tier-based system still works

**Steps:**
1. Set mode to 'auto'
2. Fill out Form 13
3. Select Daftar = "Ya"
4. Submit form (code field ignored)

**Expected Results:**
- Form submits successfully
- Auto-assigned code based on count:
  - count < 240 → `promo24`
  - count >= 240 → `promo12`
- Entry added to `home_promo_counted` (without new SMART26 fields)
- Legacy flow preserved

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 5.2: Auto Mode Reactivation
**Objective:** Verify auto reactivation works

**Steps:**
1. Set mode to 'auto'
2. Find entry with status='2', pasif_date > 90 days
3. Change status to '1'
4. Save

**Expected Results:**
- Reactivation succeeds without code validation
- Auto-assigns current tier code
- Works like original system

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

### **SECTION 6: REST API Endpoints**

#### Test 6.1: GET /counter (SMART26 Mode)
**Objective:** Verify API returns per-code stats

**Steps:**
1. Set mode to 'manual'
2. Open browser/Postman
3. GET `https://yoursite.com/wp-json/promo/v1/counter`
4. Inspect JSON response

**Expected Response Structure:**
```json
{
  "active": true,
  "mode": "smart26",
  "total_used": 5,
  "total_max": 200,
  "total_remaining": 195,
  "codes": [
    {
      "code": "TEST-CODE1",
      "description": "Test Code 1",
      "used": 3,
      "max": 5,
      "remaining": 2,
      "percentage": 60.0
    }
  ],
  "categories": {
    "new": 3,
    "diagnostic": 1,
    "lead": 1,
    "passive": 0
  },
  "end_time": 1736784000
}
```

**Expected Results:**
- JSON structure matches above
- Counts are accurate
- Only active codes shown
- Categories sum to total_used

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 6.2: GET /counter (Auto Mode)
**Objective:** Verify legacy API response

**Steps:**
1. Set mode to 'auto'
2. GET `https://yoursite.com/wp-json/promo/v1/counter`

**Expected Response Structure:**
```json
{
  "active": true,
  "mode": "auto",
  "current_code": "promo24",
  "remaining_total": 480,
  "remaining_tier": 240,
  "end_time": 1736784000
}
```

**Expected Results:**
- Legacy format preserved
- Tier logic works
- No SMART26 fields

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 6.3: POST /validate (Valid Code)
**Objective:** Verify validation endpoint works

**Steps:**
1. POST `https://yoursite.com/wp-json/promo/v1/validate`
2. Body: `{"code": "TEST-CODE1"}`
3. Content-Type: `application/json`

**Expected Response:**
```json
{
  "valid": true,
  "message": "Valid! 2 slots remaining.",
  "remaining": 2
}
```

**Expected Results:**
- Returns valid=true for good codes
- Remaining count accurate
- Message user-friendly

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 6.4: POST /validate (Invalid Code)
**Objective:** Verify validation rejects bad codes

**Steps:**
1. POST `https://yoursite.com/wp-json/promo/v1/validate`
2. Body: `{"code": "BAD-CODE"}`

**Expected Response:**
```json
{
  "valid": false,
  "message": "Invalid promo code.",
  "remaining": 0
}
```

**Expected Results:**
- Returns valid=false
- Error message clear
- Remaining = 0

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 6.5: POST /validate (Full Code)
**Objective:** Verify quota enforcement in API

**Steps:**
1. Fill TEST-CODE2 to max (3/3)
2. POST validate with `TEST-CODE2`

**Expected Response:**
```json
{
  "valid": false,
  "message": "This promo code has reached its maximum limit.",
  "remaining": 0
}
```

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

### **SECTION 7: Edge Cases & Error Handling**

#### Test 7.1: Campaign Period (Before Start)
**Objective:** Verify promo inactive before start date

**Steps:**
1. Set start date to tomorrow
2. Try to submit form with code

**Expected Results:**
- Validation bypassed (promo not active)
- Form submits but no promo tracking
- API returns `{"active": false}`

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 7.2: Campaign Period (After End)
**Objective:** Verify promo inactive after end date

**Steps:**
1. Set end date to yesterday
2. Try to submit form with code

**Expected Results:**
- Validation shows: "Promo period has ended."
- Form blocked if code entered
- API returns `{"active": false}`

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 7.3: Concurrent Submissions (Race Condition)
**Objective:** Test quota enforcement under load

**Steps:**
1. Set TEST-CODE2 to 2/3 (1 slot remaining)
2. Open 3 browser tabs
3. Fill form in all 3 tabs simultaneously
4. Submit all 3 at nearly same time
5. Check database

**Expected Results:**
- Only 1 submission succeeds (reaches 3/3)
- Other 2 blocked by validation
- No over-quota entries
- Atomic database insertion prevents race

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 7.4: Special Characters in Code
**Objective:** Verify code sanitization

**Steps:**
1. Try code with SQL injection: `TEST'; DROP TABLE--`
2. Try XSS: `<script>alert('xss')</script>`
3. Try spaces: `TEST CODE 1`

**Expected Results:**
- Sanitized by `sanitize_text_field()`
- No security vulnerabilities
- Invalid code error shown

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 7.5: Empty/Null Values
**Objective:** Handle missing data gracefully

**Steps:**
1. Submit with code but no branch
2. Submit with code but no category data
3. Check database defaults

**Expected Results:**
- Empty branch stored as empty string
- Category defaults to 'new'
- No PHP errors/warnings

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

### **SECTION 8: Database Integrity**

#### Test 8.1: Duplicate Entry Prevention
**Objective:** Verify unique constraint works

**Steps:**
1. Create entry with TEST-CODE1
2. Try to insert same entry_id again via direct DB
3. Check for errors

**Expected Results:**
- Duplicate prevented by UNIQUE constraint
- No duplicate entries in table

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

#### Test 8.2: Reactivation Duplicate Prevention
**Objective:** Ensure one reactivation per entry

**Steps:**
1. Reactivate entry once
2. Change status back to '2'
3. Try to reactivate again

**Expected Results:**
- Second reactivation blocked
- Debug log: "Already reactivated. Skipping."
- Only one entry in reactivations table

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

### **SECTION 9: Debug & Logging**

#### Test 9.1: Debug Mode Logging
**Objective:** Verify debug logs work

**Steps:**
1. Enable debug_mode in settings
2. Submit form with code
3. Check `wp-content/debug.log`

**Expected Results:**
- Logs show validation steps
- Entry creation logged
- Category detection logged
- No sensitive data exposed

**Status:** ⬜ Not Started | ⬜ Passed | ⬜ Failed

---

## 📊 Test Results Summary

| Section | Total Tests | Passed | Failed | Skipped |
|---------|-------------|--------|--------|---------|
| Admin Dashboard | 4 | - | - | - |
| Form Validation | 6 | - | - | - |
| Category Detection | 3 | - | - | - |
| Reactivation Flow | 3 | - | - | - |
| Legacy Auto Mode | 2 | - | - | - |
| REST API | 5 | - | - | - |
| Edge Cases | 5 | - | - | - |
| Database Integrity | 2 | - | - | - |
| Debug & Logging | 1 | - | - | - |
| **TOTAL** | **31** | **-** | **-** | **-** |

---

## 🐛 Issues Found

| ID | Severity | Description | Status | Fixed In |
|----|----------|-------------|--------|----------|
| - | - | - | - | - |

---

## ✅ Sign-Off Checklist

- [ ] All critical tests passed
- [ ] No major bugs found
- [ ] Performance acceptable
- [ ] Security validated
- [ ] API endpoints functional
- [ ] Admin dashboard working
- [ ] Debug mode disabled for production
- [ ] Staging environment matches production
- [ ] Rollback plan prepared
- [ ] Ready for production deployment

**Tested By:** _______________  
**Date:** _______________  
**Sign-Off:** _______________  

---

## 📝 Notes

Use this section for additional observations, concerns, or recommendations discovered during testing.

