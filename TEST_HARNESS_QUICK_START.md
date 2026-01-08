# 🚀 Test Harness Quick Start

## Access Test Harness
```
WordPress Admin → Settings → HOME Promo Manager → Test Harness
```

## Quick Test (3 steps)
1. **Select Scenario** from dropdown
2. **Click "🧪 Run Test"**
3. **View Result** (SUCCESS ✅ or FAILURE ❌)

## 18 Pre-Configured Test Scenarios

### ✅ Should PASS (Success Expected)
1. ✅ Valid Code + Valid Time
2. ✅ No Code + Valid Time (optional)
3. ✅ Case Insensitive Code
4. ✅ Code With Whitespace
5. ✅ Valid Reactivation
6. ✅ New User Category
7. ✅ Passive User Category
8. ✅ Diagnostic User Category
9. ✅ Lead User Category

### ❌ Should FAIL (Rejection Expected)
10. ❌ Valid Code + BEFORE Promo Start
11. ❌ Valid Code + AFTER Promo End
12. ❌ Invalid Code + Valid Time
13. ❌ Full Quota Code
14. ❌ Inactive Code
15. ❌ Reactivation Invalid Code
16. ❌ Duplicate Registration
17. ❌ Special Characters Code

### ⚠️ Edge Cases
18. 🔄 Race Condition (Last Slot) - Only 1 succeeds

## Automated Test Suite
Click **"▶️ Run Full Test Suite"** to run all 18 tests automatically.

## Time Override Options
- **Before start**: Tests code before promo period
- **During promo**: Normal testing (current behavior)
- **After end**: Tests code after promo expires

## Clear Test Data
Click **"🗑️ Clear All Test Data"** to remove all test entries without affecting production registrations.

---

## Test Scenarios Matrix

| Scenario | Code | Time | Expected |
|----------|------|------|----------|
| Happy Path | SMART26-LIVE1 | During | ✅ SUCCESS |
| Too Early | SMART26-LIVE1 | Before | ❌ FAIL (not active) |
| Too Late | SMART26-LIVE1 | After | ❌ FAIL (ended) |
| Bad Code | INVALID-123 | During | ❌ FAIL (not found) |
| No Code | [empty] | During | ✅ SUCCESS (optional) |
| Full Quota | [full code] | During | ❌ FAIL (no slots) |
| Inactive | [inactive] | During | ❌ FAIL (deactivated) |

## Quota Testing
1. Check current quota in "Active Promo Codes" table
2. Run test with code that has available slots
3. Refresh page - quota should increment
4. When quota reaches max, code should reject new registrations

## Race Condition Testing
1. Set a code to have exactly 1 slot remaining (e.g., 49/50)
2. Have 2+ people submit simultaneously
3. **Expected**: Only 1 succeeds, others get "no slots" error
4. Verify final quota is exactly at max (50/50)

## Common Test Patterns

### Pattern 1: Valid Registration
```
Code: SMART26-LIVE1
Time: During promo
Category: New
Expected: ✅ Registration successful
```

### Pattern 2: Invalid Code
```
Code: WRONG-CODE
Time: During promo
Expected: ❌ Code not found
```

### Pattern 3: Optional Code
```
Code: [leave empty]
Time: During promo
Expected: ✅ Allowed to proceed
Note: User submits without promo
```

### Pattern 4: Quota Full
```
Code: [code at capacity]
Time: During promo
Expected: ❌ No slots remaining
```

---

## Interpreting Results

### SUCCESS ✅
```
✅ Test Result
Status: ✅ SUCCESS
Message: Valid! Registration recorded.
Details: Shows entry_id, code, category
```

### FAILURE ❌
```
❌ Test Result
Status: ❌ FAILED
Message: Code not found
Details: Shows validation error
```

### Test Data
All test entries use entry_id starting from **999900** to distinguish from production data.

---

## After Testing
1. Review all test results
2. Document any unexpected behavior
3. Click "Clear All Test Data" button
4. Verify quotas return to pre-test state

---

## Need Help?
- Enable Debug Mode in main settings
- Check `wp-content/debug.log`
- Browser console (F12) for JavaScript errors
- Refer to PARTICIPANT_TESTING_GUIDE.md for detailed instructions

---

**Pro Tip**: Use the automated test suite first to verify basic functionality, then run manual tests for specific edge cases and quota scenarios.
