# ABC Queue Notification System v2
## City Health Office Davao — Animal Bite Center

---

## What this system does

✅ Does NOT change the existing manual process flow
✅ Receptionist registers each patient and assigns ticket number
✅ Staff calls patients via the monitor → TV shows their number + step instruction
✅ Speaker (Web Speech API) announces the ticket number out loud automatically
✅ Each patient has a 20-minute timer per step — warns at 18 min, alerts at 20 min
✅ Priority patients are visually highlighted and sorted first

---

## File Structure

```
abc-system/
├── config.php              ← DB connection + step labels
├── database.sql            ← Import in phpMyAdmin first
├── priority.png            ← Place your icons here
├── regular.png
├── followup.png
├── api/
│   ├── register.php        ← Register new patient
│   ├── queue.php           ← Manage queue (call/advance/done/skip)
│   └── display.php         ← TV polling endpoint
└── pages/
    ├── staff.html          ← Staff monitor (receptionist uses this)
    └── tv.html             ← TV display (patients watch this)
```

---

## Setup (XAMPP)

1. Copy `abc-system/` to `C:\xampp\htdocs\abc-system\`
2. Open phpMyAdmin → Import `database.sql`
3. Place `priority.png`, `regular.png`, `followup.png` in root of `abc-system/`
4. Open two browser windows:

| Page  | URL                                             | Device |
|-------|-------------------------------------------------|--------|
| Staff | `http://localhost/abc-system/pages/staff.html` | Receptionist/Staff laptop |
| TV    | `http://localhost/abc-system/pages/tv.html`    | TV (press F11 for fullscreen) |

---

## How to use (daily operation)

### Morning
- Press "Reset Queue (New Day)" on staff monitor to start fresh

### When patient arrives
1. Receptionist selects patient type (Priority / Regular / Follow-up)
2. Optionally types patient name
3. Clicks **Register** → system assigns ticket (P001, R001, F001...)
4. Tell the patient their ticket number

### Calling patients
- Click **▶ Call** on a patient row → TV shows their number + step instruction → **speaker announces automatically**
- Once patient completes a step, click **Next →** to advance them to the next step
- TV and speaker announce each new step
- Click **✓** (done) when patient is fully finished
- Click **✕** (skip) if patient is a no-show

### Severe cases (blue chair routing)
- After triage, if wound is severe, click **🚨 Severe** on that patient's row
- System will route them to the blue chair instead of yellow chair

---

## Timer system (20 minutes per step)

| Time | What happens |
|------|-------------|
| 0–17 min | Green timer bar |
| 18–19 min | Yellow bar + warning toast on staff monitor |
| 20 min | Red bar + alert beep + toast → staff should advance or call patient again |

---

## Speaker announcement format
*"Attention please. Calling Priority patient, number P 0 0 1. Priority patient P 0 0 1, please proceed to Triage Area."*

Speaker uses the device's built-in text-to-speech (Web Speech API) — no internet required.

---

## Process flow (unchanged from current manual flow)

```
Patient Arrives
    ↓
Receptionist registers → assigns ticket (P/R/F)

IF Follow-up patient:
    → Encoder's Counter → Vaccination → Release

IF Regular / Priority patient:
    → Triage
    → Fill ITR + Vital Signs
    → IF severe: Blue Chair  ELSE: Yellow Chair
    → Doctor Consultation
    → Encoder's Counter
    → Vaccination
    → Release of Patient
```
