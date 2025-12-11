# HOME Promo Manager

A WordPress plugin to manage and track promotional slots for HOME campaigns, with real-time counters, admin controls, and Formidable Forms integration.

## Features

- **Promo Slot Management:**
  - Tracks promo slot usage and limits (tiered codes, max slots).
  - Assigns promo codes to form entries based on registration and status.
  - Supports reactivation logic for returning users.

- **Formidable Forms Integration:**
  - Hooks into Formidable Forms to automate promo code assignment and slot counting.
  - Uses entry meta fields for promo, status, and registration logic.

- **Admin UI:**
  - Settings page under WordPress Settings > HOME Promo Manager.
  - Configure promo period, form IDs, field IDs, codes, and slot limits.
  - Manual button to clear counted entries (for testing or reset).

- **Shortcodes:**
  - `[promo_countdown]`: Displays a static countdown and slot info.
  - `[promo_realtime_counter]`: Displays a live widget with real-time slot/counter info via REST API.

- **REST API:**
  - Public endpoint `/wp-json/promo/v1/counter` returns current promo status, codes, slots, and countdown.

- **Automatic Email Notifications:**
  - Sends milestone emails to admin when slot thresholds are reached.

## Installation

1. Copy the plugin folder to your WordPress `wp-content/plugins` directory.
2. Activate the plugin via the WordPress admin panel.
3. Configure settings under Settings > HOME Promo Manager.

## Configuration

- **Promo Period:** Set start/end date/time (Asia/Kuala_Lumpur timezone).
- **Form IDs & Field IDs:** Set the Formidable Form and field IDs for promo, registration, status, and reactivation logic.
- **Codes & Limits:** Set tiered promo codes and slot limits.
- **Admin Email:** Set the email for milestone notifications.

## Usage

- Add `[promo_countdown]` or `[promo_realtime_counter]` shortcodes to any post or page.
- Use the admin page to monitor, configure, or reset promo slots.

## Technical Notes

- All core logic is modularized in `src/`.
- Requires Formidable Forms Pro for entry meta integration.
- Designed for extensibility and safe operation in WordPress environments.

## Support

For issues or feature requests, contact Wan Aqil Hazim, QCXIS Sdn Bhd.

## Testing & Verification

To verify the reactivation logic:

1.  **Prerequisites**:
    *   Ensure you have a user entry in Form 13 with status '2' (Pasif).
    *   Ensure the 'Pasif Date' field for that entry is set to a date more than 90 days ago.

2.  **Test Reactivation**:
    *   Edit the entry in Form 13 (via admin or frontend).
    *   Change the status from '2' (Pasif) to '1' (Aktif).
    *   Save the entry.

3.  **Verify Result**:
    *   Check the entry meta: The 'Promo Code' field should now be populated with a new code.
    *   Check the database: A new row should be added to the `home_promo_reactivations` table.
    *   Check the counter: The total slot count should increment by 1.

---

**Version:** 0.1.6
**Author:** Wan Aqil Hazim, QCXIS Sdn Bhd
**Requires PHP:** 7.4+
