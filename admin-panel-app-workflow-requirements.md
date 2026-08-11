# Admin Panel — App Production Workflow & Daily Task System

Requirements document for the new module to be added into the existing App Management Admin Panel.

---

## 1. Overview

This module controls the full lifecycle of every app, from preparation to production to daily work tasks:

1. **Prepare Production** — app is added, mandatory checklist must be completed.
2. **Sent for Production** — only checklist-complete apps reach here.
3. **Production Result** — status becomes `Live`, `Rejected`, or `Suspended`.
4. **Live Apps** — shown in a separate section.
5. **Ready for Work** — admin tags selected live apps for the task system.
6. **Daily Task Distribution** — apps are distributed daily across Play Consoles in a balanced, non-repeating way.

---

## 2. App Status Flow

```
[Prepare Production]
        |
        |  (all checklist items completed)
        v
[Sent for Production]
        |
        |  (admin updates result from Play Console review)
        +----------------+----------------+
        v                v                v
     [Live]         [Rejected]      [Suspended]
        |
        |  (admin tags app)
        v
[Ready for Work]  --->  enters Daily Task System
```

**Rule:** An app can never skip a stage. The "Send for Production" action stays **disabled/blocked** until the checklist is 100% complete.

---

## 3. Pre-Production Checklist (Mandatory)

When the admin starts adding a new app, the panel shows this checklist. Every item must be marked complete before the app can move from **Prepare Production → Sent for Production**.

| # | Checklist Item | Description |
|---|----------------|-------------|
| 1 | Package name changed | New unique package name set |
| 2 | Application ID changed | `applicationId` updated in Gradle |
| 3 | App icon changed | New launcher icon added |
| 4 | New data updated | App's new content/data (JSON, assets, config) updated |
| 5 | Build folder deleted | Old `build/` folder removed |
| 6 | Cache invalidated | Invalidate Caches / Restart done |
| 7 | Project rebuilt | Clean + Rebuild completed successfully |
| 8 | App name changed in strings | `app_name` updated in `strings.xml` |
| 9 | Privacy policy URL added | Working privacy policy URL saved |
| 10 | App domain URL added | App's domain URL saved |

**Behavior:**

- Checklist progress is saved per app (e.g. `7/10 complete`).
- "Send for Production" button is disabled until `10/10`.
- On completion, app automatically appears in the **Sent for Production** list with the completion date.

---

## 4. Sent for Production List

- Shows all apps whose checklist is complete and that have been sent for production.
- For each app the admin can set the review result:
  - **Live** — app approved and running on Play Store.
  - **Rejected** — app rejected in review.
  - **Suspended** — app suspended.
- Each status has its own filter/tab so rejected and suspended apps are easy to find.

## 5. Live Apps Section

- All apps with status `Live` appear in a **separate Live Apps section**.
- From this section the admin can apply the **"Ready for Work"** tag.

## 6. Ready for Work

- Only apps tagged **Ready for Work** by the admin enter the task system.
- Live apps without this tag are ignored by task distribution.
- Tag can be added/removed anytime; removing it pulls the app out of future tasks.

---

## 7. Play Consoles

Admin registers each Google Play Console in the panel:

- Console name (e.g. `Console A`, `Console B`, `Console C`)
- The live apps that belong to that console (each Ready-for-Work app is linked to one console).

The panel should show per console: total live apps, apps already shown in tasks, apps remaining.

---

## 8. Daily Task Distribution (Core Logic)

**Goal:** Every day, generate a task list that picks apps from every console in a **balanced** way, so that all consoles finish their apps at roughly the same time — and **never repeat** an app that already appeared in a previous task (within the current cycle).

### 8.1 Rules

1. Only `Ready for Work` apps are eligible.
2. An app that appeared in any earlier day's task is skipped until the cycle completes.
3. Daily quota per console is **proportional to that console's total apps**, so bigger consoles give more apps per day and all consoles finish together.
4. When new consoles or new apps are added, the next day's calculation automatically includes them.
5. When a console runs out of unshown apps, it contributes nothing until the next cycle.

### 8.2 Quota Formula

```
cycle_days            = configurable (e.g. 5 days per cycle)
daily_quota(console)  = ceil(console_total_apps / cycle_days)
```

Each day, take `daily_quota` apps from each console's **remaining (unshown)** list, oldest-added first.

### 8.3 Worked Example

Consoles: **A = 10 apps**, **B = 8 apps**, **C = 5 apps** → cycle = 5 days
Quotas: A → 2/day, B → 2/day, C → 1/day

| Day | Console A | Console B | Console C | Total |
|-----|-----------|-----------|-----------|-------|
| Day 1 (10th) | A1, A2 | B1, B2 | C1 | 5 |
| Day 2 (11th) | A3, A4 | B3, B4 | C2 | 5 |
| Day 3 (12th) | A5, A6 | B5, B6 | C3 | 5 |
| Day 4 (13th) | A7, A8 | B7, B8 | C4 | 5 |
| Day 5 (14th) | A9, A10 | — (done) | C5 | 4 |

- No app is ever repeated within the cycle.
- If more consoles/apps are added, quotas recalculate automatically the next day.
- After a full cycle ends, admin can choose: stop, or start a new cycle (all apps become eligible again).

### 8.4 Task Screen

- **Today's Task** view: date + list of today's apps grouped by console.
- Each task item shows: app name, console name, status/checkbox (Pending / Done).
- **Task History**: past dates with the apps that were assigned, so "already shown" apps are traceable.

---

## 9. Suggested Database Structure

```sql
consoles (
  id            INT PK,
  name          VARCHAR,
  created_at    DATETIME
)

apps (
  id                 INT PK,
  name               VARCHAR,
  package_name       VARCHAR,
  application_id     VARCHAR,
  privacy_policy_url VARCHAR,
  app_domain_url     VARCHAR,
  status             ENUM('prepare','sent','live','rejected','suspended'),
  ready_for_work     TINYINT(1) DEFAULT 0,
  console_id         INT NULL FK -> consoles.id,
  sent_at            DATETIME NULL,
  live_at            DATETIME NULL,
  created_at         DATETIME
)

app_checklist (
  id         INT PK,
  app_id     INT FK -> apps.id,
  item_key   VARCHAR,          -- e.g. 'package_name', 'icon', 'rebuild'
  is_done    TINYINT(1) DEFAULT 0,
  done_at    DATETIME NULL
)

daily_tasks (
  id          INT PK,
  task_date   DATE,
  app_id      INT FK -> apps.id,
  console_id  INT FK -> consoles.id,
  is_done     TINYINT(1) DEFAULT 0,
  cycle_no    INT DEFAULT 1
)
```

**Key checks:**

- App can move to `sent` only if all its `app_checklist` rows are `is_done = 1`.
- Daily task generation query: pick apps `WHERE ready_for_work = 1 AND status = 'live' AND app_id NOT IN (current cycle's daily_tasks)` grouped per console with the quota limit.

---

## 10. Admin Panel UI Sections

1. **Prepare Production** — new apps + checklist progress.
2. **Sent for Production** — with Live / Rejected / Suspended actions.
3. **Live Apps** — separate section + "Ready for Work" toggle.
4. **Rejected / Suspended** — filtered lists.
5. **Consoles** — manage consoles and their apps.
6. **Today's Task** — auto-generated daily list, grouped by console.
7. **Task History** — day-wise record of all past tasks.

---

## 11. Acceptance Criteria (Quick Test)

- [ ] App cannot be sent for production with an incomplete checklist.
- [ ] Completing the 10th checklist item instantly enables "Send for Production".
- [ ] Live / Rejected / Suspended statuses update correctly and Live apps show separately.
- [ ] Only "Ready for Work" apps ever appear in tasks.
- [ ] Daily task never repeats an app within a cycle.
- [ ] Distribution stays balanced (10/8/5 example produces the table in §8.3).
- [ ] Adding a new console or new apps changes next day's quotas automatically.
