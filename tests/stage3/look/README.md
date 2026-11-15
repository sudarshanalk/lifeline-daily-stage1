# Stage 3 Screen Look & Accessibility Captures (P3-83 · NFR3-12, NFR3-13)

Visual and viewport verification captures at phone (360×800 px) and desktop (1280×800 px) per package P3-83.
Every capture confirms zero horizontal scroll (`scrollWidth <= 360`) and tap targets ≥ 44×44 px with WCAG 2.2 inline exemptions.

## Directory Structure: `tests/stage3/look/p83/`

### 1. Account Hub and Desktop Menu (P3-30 · FR3-06, FR3-07, FR3-08)
- `account-hub-profile-360.png` / `account-hub-profile-1280.png`: Profile section with personal metrics.
- `account-hub-security-360.png` / `account-hub-security-1280.png`: Sign-in & security details.
- `account-hub-notifications-360.png` / `account-hub-notifications-1280.png`: Notification preferences.
- `account-hub-privacy-360.png` / `account-hub-privacy-1280.png`: Privacy, data export and consents.
- `account-hub-membership-360.png` / `account-hub-membership-1280.png`: Membership tier and status.
- `account-desktop-menu-1280.png`: Desktop chip dropdown menu at 1280 px with keyboard focus.
- `account-more-360.png` / `account-more-1280.png`: `/members/more/` mobile navigation grid.

### 2. Goal Setting (P3-31 · FR3-25, FR3-26, FR3-27)
- `goal-type-360.png` / `goal-type-1280.png`: Goal chooser (clean member with health consent).
- `goal-details-360.png` / `goal-details-1280.png`: Goal details form (weight loss rate and target inputs).
- `goal-refused-360.png` / `goal-refused-1280.png`: Refused / not suitable screen (BMI floor safeguard).
- `goal-third-refused-360.png` / `goal-third-refused-1280.png`: Third goal refused banner with secondary return button.
- `goal-no-consent-360.png` / `goal-no-consent-1280.png`: No health consent guard notice.

### 3. Today Screen (P3-32 · FR3-28, FR3-29)
- `today-no-consent-360.png` / `today-no-consent-1280.png`: General wellbeing view when health consent is absent.
- `today-goal-set-360.png` / `today-goal-set-1280.png`: Active goal view on plan day 3 with quick-log tiles and ring.
- `today-adjustment-360.png` / `today-adjustment-1280.png`: Plan adjustment recommendation banner.
- `today-eating-disorder-360.png` / `today-eating-disorder-1280.png`: Safety guard view (calories/weight-loss hidden).

### 4. Progress and Dashboard (P3-33 · FR3-30, FR3-31)
- `progress-empty-360.png` / `progress-empty-1280.png`: Zero state layout with friendly illustration.
- `progress-readings-360.png` / `progress-readings-1280.png`: Active dashboard with 14-day history and SVG trend charts.

### 5. Six Stage 3 Calculators (P3-57 to P3-5A · FR3-43 to FR3-48)
- **Heart Risk Check:**
  - `calc-heart-risk-guest-360.png` / `calc-heart-risk-guest-1280.png`: Guest questionnaire.
  - `calc-heart-risk-member-360.png` / `calc-heart-risk-member-1280.png`: Signed-in member form.
  - `calc-heart-risk-result-360.png` / `calc-heart-risk-result-1280.png`: Risk percentage result card.
  - `calc-heart-risk-error-360.png` / `calc-heart-risk-error-1280.png`: Validation error summary.
- **Blood Pressure Check:**
  - `calc-bp-guest-360.png` / `calc-bp-guest-1280.png`: Systolic/diastolic entry form.
  - `calc-bp-result-360.png` / `calc-bp-result-360.png`: Category classification result.
  - `calc-bp-urgent-360.png` / `calc-bp-urgent-1280.png`: Urgent medical care alert (185/125).
  - `calc-bp-pulse-left-out-360.png` / `calc-bp-pulse-left-out-1280.png`: Extreme pulse exclusion notice (pulse 225).
- **Metabolic Health Check:**
  - `calc-metabolic-guest-360.png` / `calc-metabolic-guest-1280.png`: Form with waist measurement details and 7-day average option.
  - `calc-metabolic-result-360.png` / `calc-metabolic-result-1280.png`: Metabolic score and risk interpretation.
- **Fitness Age:**
  - `calc-fitness-age-guest-360.png` / `calc-fitness-age-guest-1280.png`: VO2 max and activity form.
  - `calc-fitness-age-result-360.png` / `calc-fitness-age-result-1280.png`: Fitness age comparison result.
- **Heart Rate Zones:**
  - `calc-hr-zones-guest-360.png` / `calc-hr-zones-guest-1280.png`: Resting and max HR inputs.
  - `calc-hr-zones-result-360.png` / `calc-hr-zones-result-1280.png`: 5 training zones breakdown.
  - `calc-hr-zones-talk-test-360.png` / `calc-hr-zones-talk-test-1280.png`: Talk test estimation mode.
- **Sleep and Stress Check:**
  - `calc-sleep-stress-guest-360.png` / `calc-sleep-stress-guest-1280.png`: Sleep duration and stress slider questionnaire.
  - `calc-sleep-stress-result-360.png` / `calc-sleep-stress-result-1280.png`: Combined balance score and track link.
  - `calc-sleep-stress-alert-360.png` / `calc-sleep-stress-alert-1280.png`: Mental health crisis support banner.

### 6. Health Tools Hub (P3-57)
- `health-tools-hub-360.png` / `health-tools-hub-1280.png`: Full hub directory showing all 6 Stage 3 tool cards alongside Stage 1 calculators.

### 7. Sign-In and Two-Step (P3-20 to P3-22 · FR3-01 to FR3-03)
- `signin-code-360.png` / `signin-code-1280.png`: Social providers and email-code login button.
- `signin-enter-code-360.png` / `signin-enter-code-1280.png`: 6-digit one-time code entry.
- `signin-wrong-code-360.png` / `signin-wrong-code-1280.png`: Incorrect code error banner (`role="alert"`).

### 8. Membership and Pause (P3-62, P3-63 · FR3-51, FR3-52)
- `membership-subscription-360.png` / `membership-subscription-1280.png`: PMPro subscription management screen.
- `membership-pause-360.png` / `membership-pause-1280.png`: Retention pause offer modal.

### 9. Cookie Banner (P3-71 · FR3-67)
- `cookie-banner-360.png` / `cookie-banner-1280.png`: Cookie consent banner with symmetrical Accept/Reject actions.

### 10. Offline Page (P3-85 · FR3-58)
- `offline-page-360.png` / `offline-page-1280.png`: Service worker offline fallback page at `/members/offline/`.
