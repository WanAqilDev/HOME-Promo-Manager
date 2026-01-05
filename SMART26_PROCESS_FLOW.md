# SMART26 Process Flow Diagrams

## Overview
This document visualizes the complete workflow for the Smart 26 campaign implementation, showing how different components interact from user registration to final confirmation.

---

## Flow 1: User Registration & Code Validation

```mermaid
flowchart TD
    Start([User Opens Promo Page]) --> ViewAPI[Fetch /counter API<br/>Display Available Codes]
    ViewAPI --> UserEnter[User Enters Promo Code<br/>in Formidable Form]
    
    UserEnter --> PreValidate{Pre-Submit Validation<br/>frm_validate_entry hook}
    
    PreValidate -->|Invalid Format| ErrFormat[Show Error:<br/>'Invalid promo code']
    PreValidate -->|Code Not Found| ErrNotFound[Show Error:<br/>'Code does not exist']
    PreValidate -->|Quota Full| ErrQuota[Show Error:<br/>'Code limit reached']
    PreValidate -->|Valid| AllowSubmit[Allow Form Submission]
    
    ErrFormat --> UserEnter
    ErrNotFound --> UserEnter
    ErrQuota --> UserEnter
    
    AllowSubmit --> FormSubmit[Form Submitted<br/>Entry Created]
    FormSubmit --> PostHook[frm_after_create_entry Hook]
    
    PostHook --> CheckActive{Campaign Active?<br/>Manager::is_active}
    CheckActive -->|No| Skip[Skip Processing]
    CheckActive -->|Yes| ValidateCode[Validator::validate_code<br/>entry_id, code]
    
    ValidateCode --> CheckEligibility[Check User Eligibility<br/>4 Categories]
    
    style Start fill:#5acdf8
    style FormSubmit fill:#62be4d
    style ErrFormat fill:#ff1a8c
    style ErrNotFound fill:#ff1a8c
    style ErrQuota fill:#ff1a8c
```

---

## Flow 2: Eligibility Checking (4 Categories)

```mermaid
flowchart TD
    Start([Validator::check_eligibility]) --> GetFields[Retrieve Entry Meta Fields]
    
    GetFields --> Cat1{Category 1:<br/>New Registration?<br/>daftar_field = 'Ya'}
    
    Cat1 -->|Yes| Return1[Return:<br/>eligible=true<br/>category='new']
    Cat1 -->|No| Cat2{Category 2:<br/>Passive Client?<br/>status=2 + pasif_date>90 days}
    
    Cat2 -->|Yes| Return2[Return:<br/>eligible=true<br/>category='passive']
    Cat2 -->|No| Cat3{Category 3:<br/>Diagnostic Session?<br/>diagnostic_date < 90 days}
    
    Cat3 -->|Yes| Return3[Return:<br/>eligible=true<br/>category='diagnostic']
    Cat3 -->|No| Cat4{Category 4:<br/>General Lead?<br/>lead_status='Lead'}
    
    Cat4 -->|Yes| Return4[Return:<br/>eligible=true<br/>category='lead']
    Cat4 -->|No| ReturnFail[Return:<br/>eligible=false<br/>message='Not eligible']
    
    Return1 --> End([Return to Main Flow])
    Return2 --> End
    Return3 --> End
    Return4 --> End
    ReturnFail --> End
    
    style Return1 fill:#62be4d
    style Return2 fill:#62be4d
    style Return3 fill:#62be4d
    style Return4 fill:#62be4d
    style ReturnFail fill:#ff1a8c
```

---

## Flow 3: Code Validation & Recording

```mermaid
flowchart TD
    Start([Validator::validate_code]) --> Check1{Code Exists in<br/>promo_codes array?}
    
    Check1 -->|No| Fail1[Return: valid=false<br/>'Invalid promo code']
    Check1 -->|Yes| Check2{Campaign Active?<br/>is_active}
    
    Check2 -->|No| Fail2[Return: valid=false<br/>'Promotion ended']
    Check2 -->|Yes| Check3{Code Quota Available?<br/>DB::get_code_usage < max}
    
    Check3 -->|No| Fail3[Return: valid=false<br/>'Code limit reached']
    Check3 -->|Yes| CheckElig[Check Eligibility<br/>See Flow 2]
    
    CheckElig --> Eligible{User Eligible?}
    Eligible -->|No| Fail4[Return: valid=false<br/>'Not eligible']
    Eligible -->|Yes| Success[Return: valid=true<br/>category='new/passive/diagnostic/lead']
    
    Success --> RecordDB[Manager::validate_and_record]
    RecordDB --> GetBranch[Get Branch Selection<br/>from entry meta]
    GetBranch --> InsertDB[DB::insert_entry_with_code<br/>entry_id, code, branch, category]
    
    InsertDB --> Atomic{Atomic Insert<br/>Success?}
    Atomic -->|Race Condition| FailInsert[Return: Code quota exceeded]
    Atomic -->|Success| UpdateMeta[Update Entry Meta<br/>promo_field_id = code]
    
    UpdateMeta --> SendEmail[Send Notification Email<br/>Admin + User]
    SendEmail --> SetCookie[Set Cookie for Popup<br/>hpm_promo_eligible=code]
    SetCookie --> Complete([Complete])
    
    style Success fill:#62be4d
    style Complete fill:#62be4d
    style Fail1 fill:#ff1a8c
    style Fail2 fill:#ff1a8c
    style Fail3 fill:#ff1a8c
    style Fail4 fill:#ff1a8c
    style FailInsert fill:#ff1a8c
```

---

## Flow 4: Database Recording (Atomic Transaction)

```mermaid
flowchart TD
    Start([DB::insert_entry_with_code]) --> Lock[BEGIN TRANSACTION<br/>Row-Level Lock]
    
    Lock --> CheckQuota[SELECT COUNT FROM<br/>home_promo_counted<br/>WHERE promo_code=?]
    
    CheckQuota --> Compare{Usage < Limit?}
    Compare -->|No| Rollback[ROLLBACK<br/>Return false]
    Compare -->|Yes| Insert[INSERT INTO<br/>home_promo_counted<br/>entry_id, promo_code,<br/>branch, user_category]
    
    Insert --> CheckUnique{Unique Constraint<br/>Violation?}
    CheckUnique -->|Yes - Duplicate| Rollback2[ROLLBACK<br/>Already redeemed]
    CheckUnique -->|No| Commit[COMMIT<br/>Return true]
    
    Rollback --> End([End])
    Rollback2 --> End
    Commit --> End
    
    style Commit fill:#62be4d
    style Rollback fill:#ff1a8c
    style Rollback2 fill:#ff1a8c
```

---

## Flow 5: REST API - Real-Time Counter

```mermaid
flowchart TD
    Start([Frontend Calls<br/>/wp-json/promo/v1/counter]) --> CheckActive{Campaign Active?}
    
    CheckActive -->|No| ReturnInactive[Return JSON:<br/>active: false]
    CheckActive -->|Yes| GetCodes[Get promo_codes config<br/>SMART26-LIVE1/2/3/4]
    
    GetCodes --> Loop[For Each Code]
    Loop --> QueryDB[DB::get_code_usage<br/>code]
    QueryDB --> Calculate[Calculate:<br/>used, max, remaining]
    Calculate --> BuildStats[Build code_stats array]
    
    BuildStats --> MoreCodes{More Codes?}
    MoreCodes -->|Yes| Loop
    MoreCodes -->|No| GetTotal[DB::count_entries<br/>Total used]
    
    GetTotal --> BuildResponse[Build JSON Response]
    BuildResponse --> ReturnJSON[Return JSON:<br/>- active: true<br/>- codes: {...}<br/>- total: {...}<br/>- pricing: {...}<br/>- end_time: ...]
    
    ReturnInactive --> End([End])
    ReturnJSON --> End
    
    style ReturnJSON fill:#62be4d
```

---

## Flow 6: Frontend Real-Time Updates

```mermaid
flowchart TD
    Start([Page Load]) --> InitClock[Initialize Countdown Clock<br/>Server-side timestamp]
    InitClock --> FetchAPI[Fetch /counter API]
    
    FetchAPI --> ParseJSON[Parse JSON Response]
    ParseJSON --> CheckStatus{active: true?}
    
    CheckStatus -->|No| ShowEnded[Display:<br/>'PROMOSI TAMAT']
    CheckStatus -->|Yes| UpdateUI[Update UI Elements]
    
    UpdateUI --> UpdateCode[Show Current Code<br/>+ Pricing Display]
    UpdateCode --> UpdateSlots[Show Remaining Slots<br/>Per Code + Total]
    UpdateSlots --> CheckLow{Slots < 10?}
    
    CheckLow -->|Yes| AddPulse[Add Pulse Animation<br/>Urgency Effect]
    CheckLow -->|No| NoPulse[Normal Display]
    
    AddPulse --> SetTimer[Set Interval:<br/>Refresh every 3 seconds]
    NoPulse --> SetTimer
    
    SetTimer --> Wait[Wait 3 seconds]
    Wait --> FetchAPI
    
    ShowEnded --> End([End])
    
    style UpdateUI fill:#62be4d
```

---

## Flow 7: Admin Dashboard Monitoring

```mermaid
flowchart TD
    Start([Admin Opens Dashboard]) --> RenderSettings[Render Settings UI<br/>Campaign Config]
    
    RenderSettings --> GetStats[DB::get_code_stats<br/>Group by promo_code]
    GetStats --> RenderTable[Render Code Usage Table]
    
    RenderTable --> Loop[For Each Code]
    Loop --> ShowRow[Display Row:<br/>Code | Description | Used/Max | Progress Bar]
    ShowRow --> CalcPercent[Calculate: used/max × 100%]
    CalcPercent --> ColorCode{Percentage?}
    
    ColorCode -->|< 50%| Green[Green Progress Bar]
    ColorCode -->|50-80%| Yellow[Yellow Progress Bar]
    ColorCode -->|> 80%| Red[Red Progress Bar<br/>+ Alert Icon]
    
    Green --> MoreCodes{More Codes?}
    Yellow --> MoreCodes
    Red --> MoreCodes
    
    MoreCodes -->|Yes| Loop
    MoreCodes -->|No| GetCategory[DB::get_category_stats<br/>Group by user_category]
    
    GetCategory --> RenderBreakdown[Render Category Breakdown:<br/>New | Passive | Diagnostic | Lead]
    RenderBreakdown --> CheckMilestone{Milestone Reached?<br/>25/50/75/100 per code}
    
    CheckMilestone -->|Yes| SendAlert[Send Email Alert<br/>Admin Notification]
    CheckMilestone -->|No| Skip[Skip]
    
    SendAlert --> End([End])
    Skip --> End
    
    style RenderTable fill:#62be4d
    style SendAlert fill:#5acdf8
```

---

## Flow 8: Reactivation Logic (Existing Flow - Modified)

```mermaid
flowchart TD
    Start([User Updates Entry<br/>Status 2→1]) --> PreHook[frm_pre_update_entry<br/>Priority 5]
    
    PreHook --> CaptureOld[Capture OLD Values:<br/>status, pasif_date<br/>Store in Transient 5min]
    CaptureOld --> FormidableUpdate[Formidable Updates Entry]
    
    FormidableUpdate --> PostHook[frm_after_update_entry<br/>Priority 10]
    PostHook --> GetTransient[Get Transient Data]
    
    GetTransient --> CheckExpiry{Transient Exists?}
    CheckExpiry -->|No| LogError[Log: Transient expired<br/>Skip reactivation]
    CheckExpiry -->|Yes| CheckChange{Status Change<br/>2→1?}
    
    CheckChange -->|No| Skip[Skip]
    CheckChange -->|Yes| CheckDays{Pasif Date<br/>> 90 days?<br/>OR same as created_at?}
    
    CheckDays -->|No| Skip
    CheckDays -->|Yes| CheckDupe{Already Reactivated?<br/>DB::has_reactivation}
    
    CheckDupe -->|Yes| Skip
    CheckDupe -->|No| ValidateCode[NEW: Validate Code<br/>User-entered via form]
    
    ValidateCode --> RecordReact[Manager::record_reactivation<br/>+ Validator::validate_code]
    RecordReact --> InsertReact[DB::log_reactivation<br/>home_promo_reactivations]
    
    InsertReact --> InsertCount[DB::insert_entry_with_code<br/>home_promo_counted]
    InsertCount --> UpdatePromo[Update promo_field_id<br/>with validated code]
    UpdatePromo --> SetCookie[Set Cookie for Popup]
    SetCookie --> Complete([Complete])
    
    LogError --> End([End])
    Skip --> End
    Complete --> End
    
    style Complete fill:#62be4d
    style LogError fill:#ff1a8c
```

---

## Flow 9: Migration Process (v1 → v2)

```mermaid
flowchart TD
    Start([Run Migration Script]) --> Backup[CRITICAL: Backup Database<br/>Full mysqldump]
    
    Backup --> CheckTables{Tables Exist?}
    CheckTables -->|No| CreateNew[Run DB::install<br/>Create new tables]
    CheckTables -->|Yes| AlterTable[ALTER TABLE<br/>Add new columns]
    
    CreateNew --> UpdateSettings
    AlterTable --> AddCols[ADD COLUMN:<br/>promo_code VARCHAR<br/>branch VARCHAR<br/>user_category VARCHAR<br/>eligibility_verified TINYINT]
    
    AddCols --> AddIndexes[ADD INDEX:<br/>idx_code, idx_category]
    AddIndexes --> Backfill[Backfill Existing Entries<br/>SET promo_code='LEGACY']
    
    Backfill --> UpdateSettings[Update Settings Array]
    UpdateSettings --> AddCodes[Add promo_codes:<br/>SMART26-LIVE1/2/3/4]
    AddCodes --> AddPricing[Add pricing:<br/>base=200, discount=52]
    AddPricing --> AddFields[Add field IDs:<br/>diagnostic, lead_status, branch]
    
    AddFields --> Verify[Verify Migration]
    Verify --> TestQuery[Test Query:<br/>SELECT * FROM table LIMIT 1]
    TestQuery --> Success{Migration OK?}
    
    Success -->|No| Rollback[ROLLBACK<br/>Restore from Backup]
    Success -->|Yes| Complete[Migration Complete<br/>Ready for Testing]
    
    Rollback --> End([End - Fix Issues])
    Complete --> End([End - Success])
    
    style Backup fill:#ff1a8c
    style Complete fill:#62be4d
    style Rollback fill:#ff1a8c
```

---

## Flow 10: Admin Code Management (Dynamic Configuration)

```mermaid
flowchart TD
    Start([Admin Opens Dashboard]) --> ViewCodes[Display Existing Codes<br/>from promo_codes array]
    
    ViewCodes --> Action{Admin Action?}
    
    Action -->|Add New Code| AddForm[Show Add Code Form]
    Action -->|Edit Existing| EditForm[Show Edit Code Form]
    Action -->|Delete Code| ConfirmDel{Confirm Delete?}
    Action -->|View Stats| ShowStats[Show Code Usage Stats]
    
    AddForm --> InputNew[Input Fields:<br/>- Code Name SMART26-XXX<br/>- Description Live Session X<br/>- Max Quota 50]
    InputNew --> ValidateNew{Validation}
    
    ValidateNew -->|Duplicate Code| ErrDupe[Error: Code already exists]
    ValidateNew -->|Invalid Format| ErrFormat[Error: Invalid code format]
    ValidateNew -->|Valid| SaveNew[Save to promo_codes array]
    
    EditForm --> InputEdit[Modify Fields:<br/>- Description<br/>- Max Quota]
    InputEdit --> CheckUsage{Code Already Used?}
    
    CheckUsage -->|Yes - Has Usage| WarnEdit[Warning: Code in use<br/>Cannot reduce quota below<br/>current usage]
    CheckUsage -->|No Usage| AllowEdit[Allow All Changes]
    
    WarnEdit --> ValidateEdit{New Max ≥ Current Usage?}
    ValidateEdit -->|No| ErrQuota[Error: Cannot reduce<br/>below current usage]
    ValidateEdit -->|Yes| SaveEdit[Update promo_codes array]
    
    AllowEdit --> SaveEdit
    
    ConfirmDel -->|Cancel| ViewCodes
    ConfirmDel -->|Confirm| CheckUsageDel{Code Has Usage?}
    
    CheckUsageDel -->|Yes| ErrDel[Error: Cannot delete<br/>code with existing redemptions<br/>Archive instead]
    CheckUsageDel -->|No| ArchiveCode[Soft Delete:<br/>Mark as archived<br/>Keep in database for reports]
    
    SaveNew --> UpdateDB[update_option<br/>home_promo_manager_settings]
    SaveEdit --> UpdateDB
    ArchiveCode --> UpdateDB
    
    UpdateDB --> ClearCache[Clear Transients<br/>Force API Refresh]
    ClearCache --> Success[Success Message<br/>Redirect to Dashboard]
    
    ErrDupe --> ViewCodes
    ErrFormat --> ViewCodes
    ErrQuota --> ViewCodes
    ErrDel --> ViewCodes
    
    ShowStats --> DisplayTable[Show Per-Code Stats:<br/>- Code Name<br/>- Usage/Max<br/>- Remaining<br/>- Category Breakdown<br/>- Revenue Tracking]
    DisplayTable --> ExportOption{Export Data?}
    
    ExportOption -->|Yes| GenerateCSV[Generate CSV Report:<br/>entry_id, code, category,<br/>branch, timestamp]
    ExportOption -->|No| ViewCodes
    
    GenerateCSV --> Download[Download File]
    Download --> ViewCodes
    
    Success --> End([End])
    
    style SaveNew fill:#62be4d
    style SaveEdit fill:#62be4d
    style Success fill:#62be4d
    style ErrDupe fill:#ff1a8c
    style ErrFormat fill:#ff1a8c
    style ErrQuota fill:#ff1a8c
    style ErrDel fill:#ff1a8c
```

### Admin UI Mockup - Code Management Section

```
┌─────────────────────────────────────────────────────────────┐
│ HOME Promo Manager - Code Configuration                    │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ ┌─ Promo Codes ────────────────────────────────────────┐  │
│ │                                                        │  │
│ │  Code Name         Description    Max   Used  Actions │  │
│ │  ─────────────────────────────────────────────────────│  │
│ │  SMART26-LIVE1  Live Session 1    50    42   [Edit]  │  │
│ │  SMART26-LIVE2  Live Session 2    50    38   [Edit]  │  │
│ │  SMART26-LIVE3  Live Session 3    50     5   [Edit]  │  │
│ │  SMART26-LIVE4  Live Session 4    50     0   [Edit]  │  │
│ │                                                        │  │
│ │  [+ Add New Code]                                     │  │
│ └────────────────────────────────────────────────────────┘  │
│                                                             │
│ ┌─ Add/Edit Code Form ─────────────────────────────────┐  │
│ │                                                        │  │
│ │  Code Name:      [SMART26-LIVE5          ]           │  │
│ │                  (Format: SMART26-XXXXX)              │  │
│ │                                                        │  │
│ │  Description:    [Live Session 5          ]           │  │
│ │                                                        │  │
│ │  Max Quota:      [50    ] slots                       │  │
│ │                  ⚠️  Current usage: 0                  │  │
│ │                  Cannot set below current usage       │  │
│ │                                                        │  │
│ │  Status:         [●] Active  [ ] Archived             │  │
│ │                                                        │  │
│ │  [Save Code]  [Cancel]                                │  │
│ └────────────────────────────────────────────────────────┘  │
│                                                             │
│ ┌─ Bulk Operations ────────────────────────────────────┐  │
│ │                                                        │  │
│ │  [Import Codes from CSV]                              │  │
│ │  [Export All Codes]                                   │  │
│ │  [Clone Existing Code]                                │  │
│ │  [Archive All Unused Codes]                           │  │
│ └────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

### Dynamic Code Configuration Features

| Feature | Implementation | Notes |
|---------|----------------|-------|
| **Add Code** | Admin form → `promo_codes` array | No hard limit on number of codes |
| **Edit Code** | Update array entry | Cannot reduce quota below current usage |
| **Delete Code** | Soft delete (archive flag) | Preserve for reporting |
| **Real-time Validation** | AJAX check for duplicates | Prevent conflicts |
| **Quota Management** | Dynamic max adjustment | Auto-warn if reducing quota |
| **Code Format Validation** | Regex: `/^SMART26-[A-Z0-9\-]+$/i` | Enforce naming convention |
| **Import/Export** | CSV bulk operations | Mass code setup |
| **Code Cloning** | Copy existing code settings | Quick setup for similar codes |

### Settings Storage Structure (Dynamic)

```php
// OLD (v1) - Fixed 2-tier
'code_tier1' => 'promo24',
'code_tier2' => 'promo12',
'tier1_max' => 240

// NEW (v2) - Dynamic multi-code
'promo_codes' => [
    'SMART26-LIVE1' => [
        'max' => 50,
        'description' => 'Live Session 1',
        'active' => true,
        'created_at' => '2026-01-05 10:00:00',
        'archived' => false
    ],
    'SMART26-LIVE2' => [...],
    'SMART26-CUSTOM1' => [...],  // Admin can add unlimited codes
    'PROMO-SPECIAL' => [...],     // Different naming allowed
]
```

### API Response with Dynamic Codes

```json
{
  "active": true,
  "codes": {
    "SMART26-LIVE1": {
      "used": 42,
      "max": 50,
      "remaining": 8,
      "description": "Live Session 1",
      "status": "active"
    },
    "SMART26-LIVE2": {
      "used": 38,
      "max": 50,
      "remaining": 12,
      "description": "Live Session 2",
      "status": "active"
    },
    "SMART26-CUSTOM1": {
      "used": 0,
      "max": 25,
      "remaining": 25,
      "description": "VIP Exclusive",
      "status": "active"
    }
  },
  "total": {
    "used": 80,
    "max": 200,
    "remaining": 120
  }
}
```

---

## Key Decision Points & Validation Gates

### 1. **Pre-Submit Validation** (Frontend + Backend)
- ✅ Code format check (SMART26-LIVE[1-4])
- ✅ Code existence check (in promo_codes array)
- ✅ Quota availability (real-time check)
- ❌ Block submission if any fail

### 2. **Eligibility Verification** (4 Categories - Sequential Check)
1. **New Registration**: `daftar_field = 'Ya'` → **PASS** immediately
2. **Passive Client**: `status=2` + `pasif_date > 90 days` → **PASS**
3. **Diagnostic Session**: `diagnostic_date < 90 days` → **PASS**
4. **General Lead**: `lead_status = 'Lead'` → **PASS**
5. **None Match**: → **FAIL** - User not eligible

### 3. **Atomic Recording** (Race Condition Prevention)
```sql
BEGIN TRANSACTION;
  SELECT COUNT(*) FROM table WHERE promo_code=? FOR UPDATE;
  IF count < limit THEN
    INSERT INTO table VALUES (...);
  COMMIT;
ELSE
  ROLLBACK;
```

### 4. **Critical Timestamps** (All in Asia/Kuala_Lumpur TZ)
- Campaign Start: `2026-01-12 12:00:00`
- Campaign End: `2026-01-14 11:59:00`
- Duration: 48 hours exactly

---

## Error Handling Patterns

| Error Scenario | Detection Point | Response | User Message |
|----------------|-----------------|----------|--------------|
| Invalid code format | `frm_validate_entry` | Block submit | "Invalid promo code" |
| Code quota full | `Validator::validate_code` | Reject | "Code limit reached" |
| Not eligible | `check_eligibility` | Reject | "Not eligible for promo" |
| Duplicate redemption | DB unique constraint | Reject | "Already redeemed" |
| Campaign ended | `Manager::is_active` | Skip processing | "Promotion ended" |
| Transient expired | Reactivation flow | Log + skip | (Silent - debug log) |

---

## Performance Considerations

### Database Queries per Registration:
1. `frm_validate_entry`: 1 SELECT (code usage check)
2. `check_eligibility`: 4-6 SELECTs (entry meta fields)
3. `insert_entry_with_code`: 1 SELECT + 1 INSERT (atomic)
4. `update_entry_meta`: 1 INSERT/UPDATE
5. **Total**: ~8-10 queries per registration

### Optimization:
- ✅ Indexes on `promo_code`, `user_category`
- ✅ Transient caching for reactivation (5 min)
- ✅ Frontend API polling: 3 seconds (not real-time WebSocket)

---

## Testing Scenarios

### Scenario 1: Normal Registration
1. User enters valid code `SMART26-LIVE1`
2. User is new registration (`daftar='Ya'`)
3. Code has 45/50 slots used
4. ✅ **Expected**: Code applied, slot count → 46/50

### Scenario 2: Code Quota Exceeded
1. User enters `SMART26-LIVE1`
2. Code already at 50/50
3. ❌ **Expected**: Form validation error "Code limit reached"

### Scenario 3: Ineligible User
1. User enters valid code
2. User is NOT new, NOT passive, NO diagnostic, NOT lead
3. ❌ **Expected**: Validation error "Not eligible"

### Scenario 4: Race Condition
1. Two users submit simultaneously with same code at 49/50
2. Both pass pre-validation
3. Database atomic insert ensures only ONE succeeds
4. ✅ **Expected**: One success, one failure

### Scenario 5: Campaign Ended
1. User tries to register after `2026-01-14 11:59:00`
2. `is_active()` returns false
3. ❌ **Expected**: Form disabled, API returns `active: false`

---

## Notes for Implementation

1. **Validator.php exists but wrong spec** - needs refactoring to match this flow
2. **Database migration MUST run before Jan 12** - allow time for testing
3. **Transient expiry** - consider increasing to 10 minutes for safety
4. **Cookie-based popup** - only works if user stays on same browser
5. **Email notifications** - configure SMTP properly for production

---

**Document Version**: 1.0  
**Created**: 2026-01-05  
**Purpose**: Pre-implementation flow validation
