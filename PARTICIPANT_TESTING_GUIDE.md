# 🧪 Participant Testing Guide - SMART26 Promo System

## Overview
This guide helps participants test all possible scenarios for the promo code system, including quota limits, timing, validation, and edge cases.

---

## 🎯 Accessing the Test Harness

### For Administrators
1. Login to WordPress admin
2. Navigate to **Settings > HOME Promo Manager**
3. Click on **Test Harness** submenu
4. You'll see the testing interface with current system status

### Test Harness Features
- ✅ View current promo status and code quotas
- ✅ Run manual tests with custom parameters
- ✅ Run automated test suite (18 tests)
- ✅ Clear test data without affecting production
- ✅ Time override (simulate past/future/current)
- ✅ Real-time validation results

---

## 📋 Test Scenarios to Cover

### 1. **Timing Tests** ⏰

#### Test 1.1: Valid Code + Valid Time (During Promo)
**Expected**: ✅ SUCCESS
```
Scenario: valid_code_valid_time
Code: SMART26-LIVE1
Time: During promo period
Result: Code accepted, registration successful
```

#### Test 1.2: Valid Code + Before Promo Start
**Expected**: ❌ FAILURE
```
Scenario: valid_code_invalid_time
Code: SMART26-LIVE1
Time: Before start date
Result: "Promo not active" error
```

#### Test 1.3: Valid Code + After Promo End
**Expected**: ❌ FAILURE
```
Scenario: valid_code_invalid_time
Code: SMART26-LIVE1
Time: After end date
Result: "Promo has ended" error
```

---

### 2. **Code Validation Tests** 🔑

#### Test 2.1: Valid Code
**Expected**: ✅ SUCCESS
```
Code: SMART26-LIVE1 (or any active code)
Time: During promo
Result: Code validated successfully
```

#### Test 2.2: Invalid/Non-Existent Code
**Expected**: ❌ FAILURE
```
Code: INVALID-123
Time: During promo
Result: "Code not found" error
```

#### Test 2.3: No Code (Empty)
**Expected**: ✅ SUCCESS (Optional behavior)
```
Code: [leave empty]
Time: During promo
Result: Allowed - codes are optional
Note: User proceeds without promo code
```

#### Test 2.4: Inactive Code
**Expected**: ❌ FAILURE
```
Code: [deactivated code]
Time: During promo
Result: "Code is inactive" error
```

---

### 3. **Quota Limit Tests** 📊

#### Test 3.1: Code with Available Slots
**Expected**: ✅ SUCCESS
```
Code: SMART26-LIVE1
Quota: 25/50 used
Result: Registration successful
New quota: 26/50
```

#### Test 3.2: Code at Maximum Capacity
**Expected**: ❌ FAILURE
```
Code: SMART26-LIVE1
Quota: 50/50 (FULL)
Result: "No slots remaining" error
```

#### Test 3.3: Race Condition (Multiple Users, Last Slot)
**Expected**: ✅ Only ONE succeeds
```
Setup: Code has 1 slot remaining (49/50)
Action: 2+ participants try simultaneously
Result: 
  - First request: SUCCESS (50/50)
  - Second request: FAILURE (quota exceeded)
Note: Atomic query prevents over-allocation
```

#### Test 3.4: Cross-Code Quota Isolation
**Expected**: ✅ Codes remain separate
```
Test:
  1. Fill SMART26-LIVE1 to capacity (50/50)
  2. Try SMART26-LIVE2 (0/50)
Result: SMART26-LIVE2 still accepts registrations
Note: Each code tracks independently
```

---

### 4. **Edge Case Tests** 🔍

#### Test 4.1: Case Insensitive Code
**Expected**: ✅ SUCCESS
```
Actual Code: SMART26-LIVE1
User Enters: smart26-live1
Result: Accepted (case insensitive matching)
```

#### Test 4.2: Code with Whitespace
**Expected**: ✅ SUCCESS (trimmed)
```
User Enters: "  SMART26-LIVE1  "
Result: Trimmed and accepted
```

#### Test 4.3: Duplicate Registration (Same Entry ID)
**Expected**: ❌ FAILURE
```
Action: Try to register same entry_id twice
Result: Unique constraint prevents duplicate
```

#### Test 4.4: Special Characters in Code
**Expected**: ❌ FAILURE (validation)
```
Code: SMART26@#$%
Result: Invalid format error
```

---

### 5. **User Category Tests** 👥

#### Test 5.1: New User Registration
**Expected**: ✅ Category = "new"
```
Category: New
Code: SMART26-LIVE1
Result: Recorded with category "new"
```

#### Test 5.2: Passive User Registration
**Expected**: ✅ Category = "passive"
```
Category: Passive
Code: SMART26-LIVE1
Result: Recorded with category "passive"
```

#### Test 5.3: Diagnostic User Registration
**Expected**: ✅ Category = "diagnostic"
```
Category: Diagnostic
Code: SMART26-LIVE1
Result: Recorded with category "diagnostic"
```

#### Test 5.4: Lead User Registration
**Expected**: ✅ Category = "lead"
```
Category: Lead
Code: SMART26-LIVE1
Result: Recorded with category "lead"
```

---

### 6. **Reactivation Tests** 🔄

#### Test 6.1: Valid Reactivation with Code
**Expected**: ✅ SUCCESS
```
Scenario: Passive user (90+ days) reactivating
Code: SMART26-LIVE1
Result: Reactivation recorded, code assigned
```

#### Test 6.2: Reactivation without Code
**Expected**: ⚠️ Depends on mode
```
Auto Mode: Code auto-assigned
Manual Mode: No code recorded
```

#### Test 6.3: Reactivation with Invalid Code
**Expected**: ❌ FAILURE
```
Code: INVALID-CODE
Result: Validation fails, no reactivation
```

---

## 🛠️ Step-by-Step Testing Instructions

### Method 1: Manual Testing via Test Harness

1. **Access Test Harness**
   - Go to Settings > HOME Promo Manager > Test Harness

2. **Select Scenario**
   - Choose from dropdown (e.g., "Valid Code + Valid Time")
   - Form auto-fills with appropriate values

3. **Customize (Optional)**
   - Modify code, time, category, or branch
   - Leave code empty to test "no code" scenario

4. **Run Test**
   - Click "🧪 Run Test" button
   - View results immediately below form

5. **Verify Result**
   - Check success/failure status
   - Review detailed message
   - Expand "Details" for full response

6. **Check Quota Updates**
   - Refresh page
   - Verify "Active Promo Codes" table shows updated usage

### Method 2: Automated Test Suite

1. **Run Full Suite**
   - Scroll to "Automated Test Suite" section
   - Click "▶️ Run Full Test Suite"

2. **Review Results**
   - See pass/fail for each of 18 tests
   - Check which scenarios passed/failed
   - Investigate any failures

3. **Interpret Output**
   ```
   ✅ = Test passed (expected behavior)
   ❌ = Test failed (unexpected behavior)
   ```

### Method 3: Real Formidable Form Testing

1. **Setup Form Environment**
   - Create test user accounts
   - Access promo registration form

2. **Test Registration Flow**
   - Fill out form with promo code
   - Submit and check for validation errors
   - Verify code is recorded in database

3. **Check Admin Dashboard**
   - View updated quota in admin UI
   - Verify realtime stats update
   - Check category breakdown

---

## 📊 Testing Checklist

### Pre-Test Setup
- [ ] Verify promo period is set correctly
- [ ] Ensure at least 2-3 active promo codes exist
- [ ] Check initial quota status for each code
- [ ] Set one code to near-capacity for quota tests
- [ ] Note current registration count

### Core Functionality Tests
- [ ] Valid code during promo period
- [ ] Invalid code during promo period
- [ ] No code (optional) during promo period
- [ ] Valid code before promo starts
- [ ] Valid code after promo ends
- [ ] Case insensitive code matching
- [ ] Code with leading/trailing spaces

### Quota Tests
- [ ] Registration with available slots
- [ ] Registration when code is at capacity
- [ ] Race condition (multiple simultaneous requests)
- [ ] Quota isolation between codes
- [ ] Cannot reduce quota below current usage (admin UI)

### Edge Cases
- [ ] Duplicate entry prevention
- [ ] Special characters in code
- [ ] Very long code name
- [ ] Empty/null code handling
- [ ] Database constraint enforcement

### Category Tests
- [ ] New user category assignment
- [ ] Passive user category assignment
- [ ] Diagnostic user category assignment
- [ ] Lead user category assignment

### Admin UI Tests
- [ ] Mode toggle (Auto ↔ SMART26)
- [ ] Add new promo code
- [ ] Delete code (with/without usage)
- [ ] Activate/Deactivate code
- [ ] Realtime stats update
- [ ] Dashboard counter accuracy

### Reactivation Tests
- [ ] Valid reactivation with code
- [ ] Reactivation without code
- [ ] Reactivation with invalid code
- [ ] Duplicate reactivation prevention

---

## 🚨 Expected Test Results Summary

| Test Scenario | Expected Result | Why |
|---------------|----------------|-----|
| Valid code + valid time | ✅ SUCCESS | Normal case |
| Valid code + wrong time | ❌ FAILURE | Promo not active |
| Invalid code + valid time | ❌ FAILURE | Code validation fails |
| No code + valid time | ✅ SUCCESS | Codes are optional |
| Full quota code | ❌ FAILURE | No slots remaining |
| Inactive code | ❌ FAILURE | Code deactivated |
| Case insensitive | ✅ SUCCESS | Normalized matching |
| Whitespace code | ✅ SUCCESS | Trimmed input |
| Duplicate entry | ❌ FAILURE | Unique constraint |
| Race condition (last slot) | ✅ 1 success, rest fail | Atomic query |

---

## 🐛 Common Issues & Solutions

### Issue 1: Test Not Running
**Symptom**: Form submits but no result shown
**Solution**: 
- Check browser console for JavaScript errors
- Verify nonce field is present
- Ensure you have admin permissions

### Issue 2: Time Override Not Working
**Symptom**: Tests fail even with time override
**Solution**:
- Settings are temporarily modified during test
- Settings restore after test completes
- Try manual time change in main settings if needed

### Issue 3: Quota Not Updating
**Symptom**: Used count doesn't increase
**Solution**:
- Check if code is active
- Verify entry_id is unique
- Review database logs for errors
- Clear test data and retry

### Issue 4: Race Condition Not Prevented
**Symptom**: Multiple users can exceed quota
**Solution**:
- Verify atomic INSERT query is in place (db.php line ~155)
- Check database supports transactions
- Test with actual simultaneous requests (use testing tools like Apache Bench)

---

## 📝 Reporting Test Results

### For Each Failed Test, Document:
1. **Test Scenario**: Which scenario was tested
2. **Input Values**: Code, time, category, etc.
3. **Expected Result**: What should happen
4. **Actual Result**: What actually happened
5. **Error Message**: Exact error text (if any)
6. **Screenshots**: Admin UI or form submission screens
7. **Database State**: Quota before/after test

### Sample Test Report Format:
```
Test ID: 3.3
Scenario: Race Condition - Last Slot
Input:
  - Code: SMART26-LIVE1
  - Initial Quota: 49/50
  - Concurrent Users: 3
Expected: 1 success, 2 failures
Actual: [Your result]
Status: ✅ PASS / ❌ FAIL
Notes: [Any observations]
```

---

## 🔐 Data Management

### During Testing
- Use test harness to avoid polluting production data
- Test entry IDs start at 999900+
- Use "Test Branch" for branch field

### After Testing
1. Click "🗑️ Clear All Test Data" button
2. Confirm deletion
3. Verify quota counts reset to pre-test state
4. Check dashboard shows accurate numbers

### Manual Database Check
```sql
-- View test entries
SELECT * FROM wp_home_promo_counted 
WHERE entry_id >= 999900 
ORDER BY entry_id DESC;

-- Delete test entries
DELETE FROM wp_home_promo_counted 
WHERE entry_id >= 999900;

DELETE FROM wp_home_promo_reactivations 
WHERE entry_id >= 999900;
```

---

## 🎯 Success Criteria

### Test Session is Successful When:
- ✅ All 18 automated tests pass
- ✅ Quota limits are enforced correctly
- ✅ Race conditions are prevented
- ✅ Time validation works accurately
- ✅ Invalid codes are rejected
- ✅ Optional codes are allowed
- ✅ Categories are assigned correctly
- ✅ Admin UI updates in realtime
- ✅ No database errors occur
- ✅ Test data can be cleared completely

---

## 📞 Support During Testing

### If You Encounter Issues:
1. **Enable Debug Mode**
   - Settings > HOME Promo Manager
   - Check "Debug Mode"
   - Review wp-content/debug.log

2. **Check Browser Console**
   - F12 → Console tab
   - Look for JavaScript errors

3. **Verify Database Tables**
   - phpMyAdmin → wp_home_promo_counted
   - Check table structure and data

4. **Contact Developer**
   - Provide test scenario details
   - Share error messages/screenshots
   - Include debug log excerpts

---

## 🚀 Ready to Test!

Follow this guide sequentially, check off items as you complete them, and document any issues you find. The system should handle all scenarios gracefully according to the expected results table.

**Good luck with testing!** 🎉
