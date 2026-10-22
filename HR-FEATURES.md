# HR Advanced — Feature Specification

Extracted from the legacy **Ideon HR System** (`C:\wamp64\www\HR-Platfrom`). This lists HR features and business rules only. The legacy tech stack is deliberately ignored.

Legend:
- **[Legacy]** works in the old app; carry it over.
- **[Partial]** has data tables or menu entries in the old app but an incomplete workflow; finish it.
- **[Fix]** is a legacy bug or inconsistency; the new system should implement the corrected rule.

---

## 1. Multi-Company (Tenancy) & Organization Setup

### 1.1 Companies [Legacy]
- Fields: name, location, address, email, phone, tax PIN, incorporation number, incorporation date, logo (PNG/JPEG ≤ 2 MB), brand colour.
- Creating a company sends an activation email with a link to set up the company admin account.
- Company profile page shows company details, with quick links to Add Branch, Add Department and Add Staff.
- A Super Admin can filter every list and report by company. Every other user only sees their own company.
- **[Fix]** Move all hard-coded per-company rules into **company settings**:
  - medical limits
  - HR contact
  - expected work hours
  - carry-forward cap
  - accrual rate

### 1.2 Branches [Legacy]
CRUD per company. Fields: location, address, email, phone, branch head (staff).

### 1.3 Departments [Legacy]
CRUD per company. Fields: name, email, phone, Head of Department (staff).

### 1.4 Positions / Job Titles [Legacy]
- CRUD per company. Fields: position name and an "Is Supervisor" flag.
- Assigning a staff member to a supervisor position automatically makes them a supervisor.

### 1.5 Employment Levels / Grades [Partial]
- Fields: level name, probation length (with unit), probation review frequency (with unit).
- A level drives probation duration and review cadence.

### 1.6 Supervisors & Reporting Lines [Legacy + Partial]
- Promote a staff member to supervisor. Fields: alias / position name.
- Promoting grants supervisor-level permissions; removing revokes them.
- Each staff member has a **primary supervisor** (approver) and a **secondary supervisor**.
- Supervisors list shows each supervisor's counts of un-approved and rejected leave.
- **[Partial]** Position-based hierarchy (direct and indirect boss per position), with a pending-change approval queue (pending, approved, rejected).
- "Staff without supervisors" report, with an action to resend reminders.

### 1.7 Contact Persons [Legacy]
Company contact persons. Fields: name, email, phone.

### 1.8 Benefits Catalogue [Legacy]
- Benefits defined per company, for example Medical.
- Assigned to staff as a multi-select.

### 1.9 Claim Approvers [Legacy]
- One **Medical Approval Person** and one **Finance Payment Person** per company.
- Assigning an approver grants the matching permissions automatically.

---

## 2. Employee Records (Core HR)

### 2.1 Add Staff (Onboarding Form) [Legacy]
- **Personal:**
  - name, date of birth, gender, postal address, email, phone, place of residence
  - marital status (Single, Married, Divorced, Widowed)
  - photo
- **Statutory declaration (Kenya):** National ID, KRA PIN, NSSF, NHIF, HELB.
- **Next of kin:** at least two. Fields: name, address, email, phone, relationship.
  - **[Fix]** Make relationship a fixed list.
- **Family:** spouse name, spouse phone, spouse email, number of children.
- **Employment:**
  - employee number, date of joining
  - contract type (Permanent, Contract with end date, Casual)
  - probation period in months (max 12); probation end date = joining date + months
  - position, branch, department
  - primary supervisor, secondary supervisor
  - initial pay, current pay
  - leave entitlement days
  - medical cover flag
  - benefits
- **Validation:** reject a duplicate email or phone.
- **Side effects when a staff member is saved:**
  - Create the supervisor record if the position is a supervisor position.
  - Create opening leave balances (see §3.6).
  - Create the medical claim balance from company limits.
  - Grant the default "All staff" permissions, plus supervisor permissions if applicable.
  - Send an account activation email.
- **[Partial]** Qualifications / education records. Fields: qualification, score or grade.
- **[Partial]** ID verification (eKYC) against a national ID service during onboarding.

### 2.2 Employee Directory [Legacy]
- Table columns: photo, employee number, name, gender, age, marital status, phone, email, address, residence, branch, department, position.
- Actions: Edit, Exit.

### 2.3 Edit Staff [Legacy]
- Edit any of the onboarding fields.
- Changing the position to a supervisor position creates or updates the supervisor record.
- A Super Admin can change a user's system role.
- Changing the email updates the login email.
- Gender drives the maternity/paternity entitlement:
  - male: 14 days
  - female: 90 days
  - **[Fix]** The legacy female formula is wrong.

### 2.4 Employee Self-Service Profile [Legacy]
- Profile photo: PNG/JPG, ≤ 2 MB.
- Cover photo: ≤ 2 MB, between 2000×1000 and 2560×1440.
- "About me" text.
- Edit own personal, statutory, family and next-of-kin details.
- Read-only view of own branch, department, position and supervisor.

### 2.5 Account Activation [Legacy]
- "Not Activated" list of staff who haven't set up their login, with a resend-activation-email action.
- Exportable list (PDF) of staff without accounts.

### 2.6 Company Transfer [Legacy]
- Choose a staff member and a target company. The form then loads that company's positions, branches, departments and supervisors.
- On confirm:
  - Create a new employment record in the target company, with a new joining date, position, branch, department, supervisors and pay.
  - Copy personal, statutory, next-of-kin, family, benefits and medical data.
  - Mark the old record **Transferred**.
  - Revoke the old login and any supervisor or approver roles.
  - Send a new activation email.
- **[Fix]** Ask whether to carry over leave balances (the legacy app resets them). Use the target company's claim limits (legacy always used 48,000).

### 2.7 Exit Management [Legacy]
- **Exit Staff** captures:
  - date of exit
  - reason: End of Contract, Resignation, Termination, Death, Medical Grounds, Retrenched, Company Closure, Absconding, Redundancy
  - comments
- On exit:
  - Set status to **Exited**.
  - Revoke the login and any supervisor or approver roles.
- **Exited Employees** list, with a **Re-instate** action.
- **[Partial]** Exit clearance and exit interview workflow (no screens existed).

### 2.8 Re-instate / Return Staff [Legacy]
- Creates a new employment period with:
  - new contract type and end date
  - fresh balances
  - a new activation email
- The old record is marked superseded.
- Employment status values: Active, Exited, Transferred/Superseded.

### 2.9 Staff Report [Legacy]
- Filters: branch, department, position, age bracket.
- Columns: name, gender, age, branch, department, position, salary.
- Export: Copy, Excel, PDF.

---

## 3. Leave Management

### 3.1 Leave Types [Legacy]
Global defaults; **[Fix]** make them configurable per company.

| Type | Default days | Counting | Supporting document required |
|---|---|---|---|
| Annual | 21 per year | Working days | No |
| Sick (full pay) | 15 (opening 7) | Working days | **Yes** |
| Sick (half pay) | 15 | Working days | — |
| Study | 7 | Working days | **Yes** |
| Compassionate | 5 | Working days | No |
| Maternity | 90 | **Calendar days** | **Yes** |
| Paternity | 14 | **Calendar days** | **Yes** |
| Family Responsibility | 3 | Working days | No |
| Hourly | — (draws from Annual) | Hours ÷ 8 | No |

### 3.2 Leave Application [Legacy]
- Shows the employee's current balance for every type.
- Fields:
  - leave type, start date, end date
  - from and to time (hourly leave)
  - handover colleague 1 (optional)
  - handover colleague 2 (optional)
  - duties handed over
  - supporting document
  - comment
- **Day counting:**
  - Inclusive count: `end − start + 1`.
  - Exclude weekends for working-day types.
  - Hourly leave = hours ÷ 8 (an 8-hour day).
  - **[Fix]** Also exclude **public holidays**; add a holiday calendar per company.
  - **[Fix]** Use the same counting function on apply, edit and amend.
- **Balance checks:**
  - The request must not exceed the balance for that type.
  - Hourly leave checks against the Annual balance.
  - Maternity and paternity share one balance.
  - Optional annual cap: approved Annual + Hourly days ≤ 21 per calendar year.
- Employees can edit a request (it goes back through the workflow) or cancel it.

### 3.3 Approval Workflow [Legacy]
```
Applied ──(handover chosen)──► Pending Handover ──approve──► Pending Supervisor ──approve──► Approved
   │                              └──reject──► Handover Rejected            ├──amend+approve──► Approved
   └──(no handover)──────────────────────────► Pending Supervisor           └──reject (reason required)──► Rejected
```
- **Handover colleague** (the "Handover Requests" screen):
  - Approve.
  - Reject, with a reason.
  - Amend the dates.
- **Supervisor** (the "Leave Approval" screen):
  - Approve.
  - Reject, with a mandatory reason.
  - **Amend & Approve**: change dates or times, then approve.
- Approval deducts the leave from the correct balance.
- **[Fix]** Study leave deducts from the Study balance; the legacy app deducted it from Annual.
- **[Fix]** Hourly leave deducts from the Annual balance; the legacy app deducted nothing.
- **[Partial]** Optional extra stages (secondary supervisor, HOD, HR) as a configurable approval chain. The legacy app stored these people but never used them.

### 3.4 Leave Recall [Legacy]
- Lists approved leave that hasn't ended yet. HR or the supervisor can recall an employee from it.
- On recall:
  - Set the end date to today.
  - Log the recall.
  - Credit the unused days back to the same balance:
    - leave already started: remaining days
    - leave not yet started: full duration
  - Notify the employee to report on the next working day.
- **[Fix]** Restore working days, not calendar days.

### 3.5 Leave Views [Legacy]
- **My Leave Requests:** type, dates, duration, status, cancel.
- **Staff on Leave Today:** name, branch, type, dates, duration. Exportable.
- **Year at a Glance (Leave Planner):**
  - Team calendar (month, week, day views).
  - Colour by status: pending supervisor = blue, approved = green, pending handover = amber.
  - Stats: Total staff, On leave, Available, Approved, Pending.
- **Leave History (HR):** all applications, filterable, with a view-history modal per employee.

### 3.6 Balances, Accrual & Year-End [Legacy]
- **Opening balances for a new hire:**
  - Annual 1.75
  - Sick 7
  - Maternity 90 (female) / Paternity 14 (male)
  - Compassionate 5
  - Family Responsibility 3
  - Study 7
- **Monthly accrual:** +1.75 Annual days per month (= 21 per year).
  - The run is logged with date and record count.
  - The admin receives a summary.
  - **[Fix]** Apply accrual to active staff only.
- **Year-end carry-forward:** Annual is capped at **5 days**. The excess is **forfeited** and reported.
- **Manual adjustment:** HR can edit any employee's balances, for example to reconcile them.
- **Bulk upload** of leave days from CSV/Excel.
- **Reconciliation tool:** recompute balances from the date of joining, the accrual rate and approved leave taken. Preview first, then apply.
- **[Fix]** Normalise balances to employee × leave type × year, with a transaction ledger (accrual, deduction, recall credit, adjustment, forfeiture). That gives a full audit trail.

### 3.7 Leave Reports [Legacy]
All reports export to Copy/Excel/PDF and filter by company, branch, department, position, age, leave type and year.
- **Leave Taken Report:** tabs per leave type. Per employee: entitled, applied, approved, pending, rejected, balance. Includes edit-balance and view-history actions.
- Leave Roll-Over report.
- Leave Forfeiture report.
- Handover report: handover 1/2 status and supervisor status.
- Leave Balances report.
- Consolidated Leave report.
- Total leave taken (hours) per company and type.
- Leave Forensic Analysis per employee.

---

## 4. Medical Claims

### 4.1 Claim Request [Legacy]
- Fields:
  - claimant: Self, Spouse, Child
  - claim date
  - consultation fee
  - pharmacy / lab / radiology fee
  - total, calculated automatically
  - multiple receipt uploads
- Shows the remaining balance.
- Validation: the total must not exceed the remaining balance.
- **[Fix]** Enforce the monthly limit; the legacy app stored it but never checked it.
- Employees can edit or cancel a claim while it is pending.

### 4.2 Limits [Legacy]
- Annual limit and monthly limit per employee, with defaults set per company. Legacy values (KES):
  - Ideon: 48,000 annual / 4,000 monthly
  - Others: 120,000 annual / 10,000 monthly
- Balance = annual limit − approved claims this year.
- Balances reset or are reconciled each year.

### 4.3 Approval & Payment Workflow [Legacy]
```
Submitted ──► Medical Approver (HR) ──approve──► Finance ──mark paid──► Paid (payment confirmation PDF)
                    └──reject (reason)──► Rejected      └──reject (reason)──► Finance Rejected
```
- **Routing:**
  - A claim goes to the company's Medical Approver.
  - If the claimant *is* the approver, it goes to their primary supervisor instead.
- **HR approve:**
  - Deduct from the balance.
  - Notify the claimant ("proceed to Finance").
  - Notify the Finance person with the amount and the claimant's phone.
- **Finance mark paid:** generate a printable "Finance Payment Confirmation" (company, branch, amount, phone number for mobile-money payment).
- **[Fix]** A finance rejection restores the balance.

### 4.4 Claim Screens & Reports [Legacy]
- My Medical Claims: date, amount, HR status, finance status.
- Staff Medical Requests (HR approval queue).
- Financial Claims (Finance payment queue).
- Claims Summary report: filter by company, month, year.
- Medical claims report per employee for the year:
  - total claimed, number approved, doctor fees, lab fees, documents
  - age-band filter
- Total claims per company.
- All reports export to Copy/Excel/PDF.

---

## 5. Attendance (Time & Attendance)

### 5.1 Clock-In / Clock-Out Capture [Legacy]
- Punches come from biometric devices or terminals through an API. Each punch: staff id and timestamp, de-duplicated.
- The devices pull a list of active staff per branch.
- The fingerprint template is stored on the employee.
- **[Partial]** Optional web/mobile clock-in for staff, with geo-fencing as an enhancement.

### 5.2 Attendance Rules [Legacy]
Make these configurable per company or branch.
- Expected start **07:45**: before is **Early**, after is **Late** (show minutes).
- Expected end **17:00**.
- Work time = 17:00 − check-in.
- Overtime = check-out − 17:00, when positive.
- Total actual time = check-out − check-in.
- A missing clock-out is flagged **NA**.
- Times shown in company time zone (Nairobi, UTC+3).

### 5.3 Attendance Screens [Legacy]
- Daily attendance report:
  - filters: company, branch, date or date range
  - columns: date, name, branch, check-in, check-out, Late/Early badge, work time, overtime, total
  - exportable
- Attendance history per employee (modal).
- Dashboard widget: my clock-in/out times for the current week.
- Present/absent sync to an external scorecard: absent when on approved leave that day. Keep this as an optional integration.

---

## 6. Performance Management

### 6.1 Goal / KPI Scorecards (Balanced Scorecard) [Legacy]
- **Structure:** Assessment Plan → Focus Areas → Strategic Objectives (with weight) → Activities/KPIs.
  - Plan fields: name, position, start date, created by.
  - Focus area examples: Capital, Governance.
- **Activity types:**
  - **Quantitative:**
    - unit (KES, %, People, Unit; per company)
    - target value
    - frequency (Daily, Weekly, Monthly, Quarterly, Semi-Annually, Annually; each with a number of days)
    - end date, description
    - KPI type (percentage or absolute)
  - **Qualitative:** questions picked from the competency question bank (§6.4), with a frequency and due date.
- **Assignment mode:**
  - **Cascading:** the supervisor pushes the target down. Two ways to split it:
    - **Distributed:** split the target across selected staff; the sum must be ≤ the target, except for % units.
    - **Equal:** every direct report gets the full target.
  - **Self:** the employee owns and scores the target.

### 6.2 KPI Lifecycle [Legacy]
Status values: Setup → **Cascaded** (5) → **Open / In progress** (3) → **Submitted** (2) → **Approved** (1). Under Review (4) is a side state.
- **The employee receiving a cascaded target can:**
  - Accept it.
  - Cascade it further down.
  - Convert it to a self-assessment.
  - **Request a review**, giving a reason.
- **Review request:** the supervisor amends the metrics (back to Cascaded) or rejects the review with a comment (email sent).
- **Self-scoring:** the employee submits the achieved value and a comment, then it waits for the supervisor.
- **Supervisor:**
  - Approve, with a comment.
  - Reject: score resets to 0, the item goes back to Open, and the employee must resubmit.
  - Request an extension.
- **Roll-up:** the supervisor's score on an activity = sum of subordinates' scores (average for % units). When all subordinates are done, the supervisor's item goes up to their own supervisor. If the supervisor created the activity, it is final.
- Full audit trail of every submit, approve and reject comment.
- **Recurring KPIs:** when a KPI period ends, clone it for the next period (end date + frequency days) and reassign it to active staff.
- **Reminders:** notify staff 7 days, 3 days and 1 day before a KPI is due.

### 6.3 Performance Screens [Legacy]
- **Supervisor:** New Assessment (setup), My Assessments (edit/delete), Reviewed Assessments (view, amend, reject review, request extension), Staff Evaluation (approve, reject), view one employee's performance.
- **Employee:** Cascaded Assessments (accept, review, assign), My Goals, My Evaluation (fill in and submit), My Performance. Filters: year, month, quarter.
- **Admin:** Metrics Setup (categories, sub-categories, questions), Assessment Setup tree.
- **Reports:**
  - Overall Performance Report. Per employee: activity, metric, achievement, score, comments, supervisor, department, location. Export to Excel/PDF.
  - Staff Performance Progress. Per employee counts of Done, Not Done, Not Accepted, Not Approved, Under Review, plus total score.
  - List of staff who haven't accepted their tasks.
- **Performance Dashboard:**
  - performance summary chart
  - department ranking
  - leave balance vs taken
  - attendance hours
  - performance outlook

### 6.4 Competency / 360° Questionnaire [Partial]
- Question bank: Category → Sub-category → Question.
  - Seed categories: Leadership, Management, Work Environment, People Support, Business Planning.
- Assign a questionnaire to an employee, with a due date and frequency.
- Self-scoring per question, plus comments per category.
- Supervisor scoring of the same questionnaire (rater type = self or supervisor).

### 6.5 Performance Boost Program — Coaching & PIP [Partial]
The legacy app had the menu entries and tables but no screens.
- **Coaching:**
  - Discussion points per employee, which can be raised from a KPI. Fields: activity, comment, deadline, status.
  - **GROW sessions:** topic, goal, reality, action plan, staff / supervisor / HR comments, date, next meeting.
  - Outcome: completed, extended, or escalate to PIP.
- **PIP (Performance Improvement Plan):**
  - Fields: concern, target, action plan, resources, end date, review frequency.
  - Periodic check-ins: meeting date, target, progress, comment, recommendation (continue, finish PIP, escalate).
  - Workflow screens: Recommended PIP, Add PIP, PIP check-in, Remove PIP.
- **[Partial]** My Rewards / recognition (menu entry only).

---

## 7. Probation Management [Partial]
- Probation end date = joining date + probation months, or from the employment level.
- Probation status: none/confirmed, on probation, extended.
- **Review workflow:** Primary supervisor comment → Secondary supervisor check → Head of Operations check → HR check, then a decision: Confirm, Extend or Terminate.
- **Alerts:** email HR and the supervisor 7 days, 3 days and 1 day before probation ends.
- **[Fix]** The legacy alerts never fired.
- Screens: Existing Probations, Staff Probations, Secondary Check, HOO Check, HR Check.

---

## 8. Documents (Paperless HR)

### 8.1 Employee Personal File [Legacy]
- Upload documents by type, either by HR ("HR Admin") or by the employee ("Self").
- Document types:
  - Contracts, Onboarding Forms
  - ID/Passport, NSSF, NHIF, PIN
  - High School, College/University, Professional Certificates
  - CV, Letters of Recommendation, Letters of Service, Internships
  - Employment Contracts, Promotion Letters
  - Performance Appraisal Forms, Training Recommendation Letters
  - Others
- Grouping: Company, Personal, Academic, Professional, Office.
- Formats: PDF, DOC, DOCX, ODT. HR uploads up to 50 MB.
- Delete; list of documents by employee.

### 8.2 Company Documents [Legacy]
- Three tabs: **Memos**, **Newsletters**, **Policies**.
- Each document: name, originating department ("referred by"), effective date, file.
- Add, edit, delete, plus history.
- Read-only "Office Documents" view for all staff.

---

## 9. Employee Engagement

### 9.1 Comments / Suggestion Box [Legacy]
- Staff submit a comment, complaint or suggestion.
  - Categories: Supervision/Management, Work Environment, System/Tools, Work Satisfaction, Career Opportunities, Other.
  - Optional **anonymity**.
- Routed to the company's HR contact.
- Threaded chat-style replies, with a seen/unseen flag and a status (pending or replied).
- Comment history for the employee.
- HR dashboard feed with a breakdown by category.

### 9.2 Announcements / Latest Updates [Legacy]
- HR posts company updates (title, description).
- Updates appear on the dashboard feed.

---

## 10. Dashboards

### Employee Dashboard [Legacy]
- My performance summary chart, overall and per unit.
- My rank in my department.
- Top performers, best performer, most improved.
- Last vs current score %.
- KPI tiles:
  - pending assessments
  - pending handover requests
  - leave approvals (supervisors)
  - direct reports (supervisors)
- My clock-in/out for the current week.
- My department's staff.
- My leave balances and my medical claim balance.
- Latest updates.
- HR only: staff reviews feed.

---

## 11. Notifications (Email + SMS + In-App)

| Event | Recipient | Channel |
|---|---|---|
| Leave applied, with handover | Handover colleague(s) | Email + SMS |
| Leave applied without handover, or handover approved | Primary supervisor | Email + SMS |
| Handover approved / rejected (with reason) | Applicant | Email + SMS |
| Leave approved / amended & approved / rejected (with reason) | Applicant | Email + SMS |
| Leave edited | Supervisor | Email |
| Leave recalled | Employee | Email + SMS |
| Claim submitted | Medical approver | Email |
| Claim approved | Claimant + Finance person | Email |
| Claim rejected (HR or Finance) | Claimant | Email + SMS |
| Account created / transferred / reinstated / company added | New user | Email (activation link) |
| Assessment review rejected | Employee | Email |
| KPI due in 7 / 3 / 1 days | Employee | SMS |
| Probation ending in 7 / 3 / 1 days | HR / supervisor | Email |
| Monthly accrual / year-end forfeiture run | Admin | Email + SMS |

- **In-app notification bell and sidebar badges** show counts of:
  - pending handovers
  - leave approvals
  - claims to approve
  - claims to pay
  - assessment reviews
  - pending self-assessments
  - team items awaiting approval
- SMS goes to Kenyan numbers; convert `07XXXXXXXX` to `2547XXXXXXXX`.

---

## 12. Scheduled Jobs

| Job | Schedule | Effect |
|---|---|---|
| Leave accrual | Monthly | +1.75 Annual days per active employee; log the run; notify admin |
| Year-end carry-forward | Yearly (1 Jan) | Cap Annual at 5 days, forfeit the excess, report it |
| Claim balance reset | Yearly (1 Jan) | Reset claim balances to the annual limit |
| Probation alerts | Daily | 7 / 3 / 1-day alerts |
| KPI reminders | Daily | 7 / 3 / 1-day reminders |
| Recurring KPI rollover | Daily | Clone ended KPIs into the next period |
| Scorecard attendance sync | Daily | Optional integration |

---

## 13. Users, Roles & Permissions [Legacy]
- **System roles:** Super Admin (all companies), Administrator/HR, Company Account, Staff.
- **Permission model:** Modules → menu items/pages → per-user grants.
- **Role templates** used for bulk grants:
  - All Staff
  - Supervisor
  - Administrator/HR
  - Finance
  - Super User
- **Module Management:**
  - Modules: name, icon, order, status.
  - Menu items: name, slug, parent, order, status, access template.
- **User Roles screen:**
  - Per company, list staff with Manage and **Make Admin** actions.
  - Bulk assign templates: General, Supervisor, Administrator, Super Admin.
  - Per-user permission matrix, colour-coded by template.
- Automatic grants: new staff get All Staff; supervisors get Supervisor; approvers get Medical or Finance.
- **Auth flows:**
  - login
  - activate account via emailed link
  - forgot / reset password (token, single use)
  - logout
  - session timeout (4 hours)

---

## 14. Integrations
- **Biometric/attendance devices:**
  - Pull active staff per branch.
  - Push clock-in/out punches.
- **SMS gateway** (Africa's Talking) and **transactional email**.
- **eKYC** national ID verification (Creditinfo Kenya). Proof of concept only.
- **External scorecard** present/absent sync.

---

## 15. Payroll [New]

### 15.1 Setup
- **Pay components per company:**
  - Earnings: basic pay; allowances (house, transport, commuter, etc.); overtime; bonus; commission; arrears.
  - Deductions: loans, advances, SACCO, insurance, union dues, HELB.
  - Employer contributions.
- **Component settings:**
  - Calculation type: fixed amount, % of basic, % of gross, or formula.
  - Taxable or non-taxable.
  - Pensionable or not.
  - Recurring or one-off.
- **Employee pay profile:**
  - basic salary
  - assigned components, each with an amount or rate and an effective-from date
  - payment method: bank, M-Pesa or cash
  - bank / branch / account, or M-Pesa phone
  - tax exemptions or reliefs, such as insurance relief and a disability exemption
- **Salary history:** every pay change is versioned with an effective date and a reason (promotion, increment, adjustment). This replaces the single "current pay" text field from the legacy app.
- **Pay calendar:** monthly pay periods with a cut-off date and a pay date.

### 15.2 Statutory Engine (Kenya, table-driven with effective dates)
All rates live in editable statutory tables, so a Finance Act change is a data update, not a code change. These defaults need checking against the current KRA, NSSF and SHA publications.
- **PAYE**, monthly bands:
  - 10% up to 24,000
  - 25% from 24,001 to 32,333
  - 30% from 32,334 to 500,000
  - 32.5% from 500,001 to 800,000
  - 35% above 800,000
- **Personal relief:** 2,400 per month. **Insurance relief:** 15% of premiums, capped.
- **NSSF (Tier I / Tier II):** 6% employee plus 6% employer, between the Lower and Upper Earnings Limits. The limits are configurable for each phase.
- **SHIF:** 2.75% of gross, with a minimum of 300. It replaced NHIF.
- **Affordable Housing Levy:** 1.5% employee plus 1.5% employer.
- Allowable deductions are subtracted from taxable pay before PAYE: pension and NSSF up to their caps, and SHIF and Housing Levy where current law allows.
- **HELB** is a fixed deduction taken from the statutory declaration.

### 15.3 Payroll Run Workflow
```
Draft ──► Calculated ──► Reviewed (HR) ──► Approved (Finance) ──► Paid / Locked
                └── recalculate any time before approval ──┘
```
- **What a run pulls in automatically:**
  - Active staff, prorated for joiners and leavers by days worked.
  - **Unpaid leave** (half pay for half-paid sick leave) from the Leave module.
  - **Overtime hours** from Attendance, at configurable multipliers (1.5× weekday, 2× rest day or holiday).
  - Loan instalments.
  - One-off earnings and deductions entered for the period.
- **Review screen:**
  - Variance against last month, per employee and per component.
  - Flags for negative net pay, or net pay below a set % of gross (the one-third rule).
- **Approval:** the run has to be approved, with a full audit trail. Approval locks the run; corrections go through an off-cycle run.
- **Off-cycle runs** for bonuses, arrears and final dues.
- **Final dues on exit:**
  - prorated salary
  - leave encashment of the unused annual balance
  - notice pay
  - deductions for outstanding loans or advances

### 15.4 Loans & Advances
- Fields: principal, interest (flat or reducing), number of instalments, start period, schedule.
- Deducted automatically each run.
- Shows the outstanding balance.
- Settle early, or pause.
- **Salary advance request:** an employee applies, it goes for approval, and it is recovered in the next payroll.

### 15.5 Outputs
- **Payslips** (PDF):
  - employee self-service download with history
  - bulk email after approval, with optional password protection (e.g. ID number)
- **Bank transfer file** (CSV) and **M-Pesa bulk payment file**.
- **Statutory returns:**
  - KRA P10 / PAYE schedule
  - NSSF schedule
  - SHIF schedule
  - Housing Levy schedule
  - HELB schedule
- **P9A** annual tax card per employee.
- **Reports:**
  - payroll summary and register
  - cost per department and branch
  - component breakdown
  - employer cost
  - month-on-month variance
  - year-to-date totals
- **Journal export** for accounting: GL account mapping per component.

### 15.6 Security
- Only Payroll Officer, HR and Finance roles can see payroll.
- Salary figures are hidden by default in shared views.
- Every change to pay data or a run is logged in the audit trail.

---

## 16. Advanced Authentication [New]
- Email + password login, with "remember me".
- **Two-factor authentication** (TOTP authenticator app) with recovery codes. Admins can require it for chosen roles, such as HR, Finance and Super Admin.
- **Login throttling and account lockout** after repeated failures, with a timed unlock or an admin unlock.
- **Password policy:**
  - minimum length and complexity
  - check against known breached passwords
  - optional expiry
  - **forced change on first login**
- **Account activation by signed, expiring email link.** This replaces the legacy guessable id links.
- **Forgot / reset password** with a single-use, expiring token.
- **Login history:** time, IP, device/browser, success or failure.
- **Active sessions** list: sign out other devices, or a single session.
- **Admin impersonation** ("log in as") for support, fully audited, with a visible banner.
- Idle session timeout with a warning modal.
- Suspicious-login email alert for a new device or location.

## 17. Advanced Dashboard [New]
- **Widgets by role**, and each user can rearrange or hide them:
  - **Employee:**
    - greeting and today's status
    - leave balances (ring charts)
    - claim balance
    - latest payslip net pay (masked until clicked)
    - my KPIs progress
    - this week's attendance
    - upcoming holidays
    - my pending requests
  - **Supervisor:** everything in Employee, plus:
    - **approvals inbox** covering handovers, leave, KPI submissions and claims, with one-click approve or reject
    - team on leave today and this week
    - team attendance today (present, late, absent)
    - team KPI progress
  - **HR / Admin:**
    - headcount KPIs: total, joiners, leavers, turnover %, by gender, department and contract type
    - headcount trend chart
    - probations ending soon
    - contracts expiring soon
    - not-activated accounts
    - staff without supervisors
    - document expiries
  - **Finance / Payroll:**
    - payroll cost trend
    - current run status
    - claims awaiting payment
    - statutory remittance due dates
- **Who's Out:** a mini-calendar of who is on leave today and this week.
- **Celebrations:** birthdays and work anniversaries this week.
- **Announcements feed** with read receipts, plus quick access to company documents.
- **Quick actions:** Apply leave, Submit claim, Download payslip, Add employee, Run payroll.
- **Recent activity feed:** audit events relevant to the user.
- **Global search (Ctrl+K)** across employees, pages and documents.
- **Real-time notification bell** with unread count and mark-all-read.
- **Light and dark themes**, and a responsive mobile layout.

---

## Excluded from Scope (not HR)
- Call-centre / loan telemarketing (agents, contacted clients, call feedback).
- Job-board newsletter (daily jobs email).
- Template demo pages: task manager, static mock dashboards.
- 21 denormalised leave-report tables and other snapshot tables. Replace them with live queries.
