# Online Examination and Result Management System

A complete, database-driven web application for creating, delivering and
automatically marking multiple-choice examinations, built as a school project.

---

## 1. Project Overview

The system has two roles:

- **Administrator** – manages students, creates examinations, adds multiple-choice
  questions, activates examinations, reviews attempts and results, and exports
  reports.
- **Student** – registers, logs in, takes active examinations with a countdown
  timer, and views results and history.

Examinations are marked automatically as soon as they are submitted, and every
result is stored permanently in MySQL.

---

## 2. Features

**Administrator**

- Secure login / logout (students: registration number, full name or email)
- Dashboard with live statistics (students, administrators, examinations,
  questions, attempts, completed / active / upcoming examinations,
  notifications, recent registrations, recent activity, recent examinations
  and results)
- Performance analytics: overall pass rate and average score plus a pass-rate
  bar, average, best and worst score for every examination
- System settings (registration-number format: flexible, alphanumeric, or
  strict dd/mm/yyyy e.g. 28/11/2006 — a format, not an assumed date of birth)
- Create, edit, delete and publish examinations: new exams are saved
  NOT YET OPEN, admin clicks OPEN EXAM to publish (OPEN), and exams past
  their closing time automatically become CLOSED in the database
  (`exam_display_status()` is the single source of truth for admin and
  student dashboards; `start_exam.php`/`take_exam.php` enforce it server-side)
- Optional scheduling window (opens / closes) and a per-student attempt allowance
- Assign examinations to specific students (no assignments = available to all)
- Add, edit, delete and view multiple-choice questions (options A–D + correct
  answer, difficulty easy/medium/difficult, category/subject), search/filter,
  export to CSV and bulk-import them from a CSV file; random question
  selection and random option ordering are applied per attempt
- Copy the questions of one examination into another (selective, with an optional
  "skip questions that already exist" and "update the expected question count")
- Add True/False questions alongside multiple choice
- Generate whole examinations and individual questions with the built-in AI
  assistant, and let the AI flag duplicate or near-duplicate questions
- View and manage registered students (add with assigned registration number,
  edit/view profile, search by name/email/registration number, activate/
  deactivate, pagination, delete, export records) and bulk-import them from a CSV file
- View examination attempts and results, search, filter and sort them
- View an individual student's detailed result with per-question breakdown and
  proctoring/integrity details (IP, browser, focus-loss events)
- Print any result as a formal, A4-friendly statement of result (print or
  "Save as PDF")
- Examination performance report and CSV/Excel-compatible export
- Publish announcements / notices for students (title, message, audience), which
  appear on the student dashboard and on the public landing page
- Security audit log of administrator actions and recent login activity
- Manage their own profile and change their password
- Reset a student's password to a random temporary value (shown once)

**Student**

- Self-registration and secure login / logout by registration number, full name or email
- Dashboard with profile, available / in-progress / completed / passed counts,
  available examinations, recent results and the latest announcements
- Read examination instructions before starting
- Take an examination (student name/registration number, subject, instructions,
  total questions shown) with previous/next navigation, question navigator,
  progress bar and live countdown timer
- Change answers before submitting
- Automatic submission when time expires
- Automatic marking, pass/fail result, history of all attempts
- Print or save the result sheet as PDF
- Examinations open and close automatically according to the schedule the
  administrator sets, and re-attempts are offered only while the allowance lasts
- Manage their own profile (name, department) and change their password

**Public**

- A university-style landing page with a welcome hero, quick-access tiles
  (register, student login, staff login, results), the notice board, a summary
  of what the platform does and a step-by-step "how it works" section

**Security**

- Passwords hashed with `password_hash()` / verified with `password_verify()`
- Password policy enforced on every new or changed password (registration,
  profile change, administrator reset and CSV import): at least 6 characters,
  at least one capital letter, one small letter and one number, and no spaces.
  The forms show the same hint wherever a password is set
- PDO prepared statements everywhere (SQL-injection protection)
- Output escaping with `htmlspecialchars()` (XSS protection)
- CSRF tokens on all state-changing forms and AJAX requests
- Server-side role-based authorization on every protected page
- Server-enforced examination timing (the deadline is stored in MySQL, so a
  tampered browser clock or JavaScript timer gives no advantage)
- Per-examination attempt allowance (default: one attempt) and an optional
  opening/closing window, both enforced server-side
- Session bound to the user agent and regenerated on login
- Login throttling: repeated failed attempts from an email/IP are temporarily
  blocked, and every login attempt (success or failure) is recorded
- Idle (60 min) and absolute (8 h) session timeouts, suspended during an active
  examination so a candidate is never logged out mid-paper
- Proctoring: focus-loss / tab-switch, copy and paste attempts are recorded per
  attempt (with IP and browser) and surfaced to administrators
- Administrator audit trail of every create/update/delete action
- `.htaccess` protection of `config/`, `includes/` and `database/`, plus friendly
  403/404/500 error pages
- Centralised database credentials, no secrets scattered through the code

---

## 3. Technologies Used

| Layer          | Technology                        |
|----------------|-----------------------------------|
| Frontend       | HTML5, CSS3, JavaScript, Bootstrap 5 |
| Backend        | PHP                               |
| Database       | MySQL                             |
| Web server     | Apache                            |
| Dev tools      | VS Code, XAMPP or AppServ         |

No frameworks or other runtimes are required.

---

## 4. System Requirements

- XAMPP (or AppServ) with **Apache**, **PHP 7.4+** and **MySQL**
- A web browser
- VS Code (optional, for editing)

---

## 5. Installation

1. **Copy the project** into your web server document root:
   - XAMPP: `C:\xampp\htdocs\online-examination-system`
   - AppServ: `C:\AppServ\www\online-examination-system`
2. Start **Apache** and **MySQL** from the XAMPP/AppServ control panel.

---

## 6. Database Setup

**Option A – phpMyAdmin**

1. Open `http://localhost/phpmyadmin`.
2. Click **Import** → **Choose File** → select
   `database/database.sql` → **Import**.
3. The script creates the `online_exam_system` database, all tables and sample data.

**Option B – MySQL command line**

```bash
mysql -u root -p < database/database.sql
```

---

## 7. Apache Setup

The project ships with `.htaccess` files that protect the `config/`, `includes/`
and `database/` folders and provide friendly error pages. Apache must be allowed
to read them, which means the document-root `<Directory>` block in `httpd.conf`
needs `AllowOverride` enabled for this project.

For AppServ, add the following inside `C:\AppServ\Apache24\conf\httpd.conf` and
restart Apache:

```apache
<Directory "C:/AppServ/www/online-examination-system">
    AllowOverride All
</Directory>
```

For XAMPP the equivalent directory is `C:/xampp/htdocs/online-examination-system`.
If you would rather not enable overrides, simply delete the `.htaccess` files -
the application still runs, but the `config/`, `includes/` and `database/`
folders would then be directly reachable over HTTP.

---

## 8. How to Configure the Database

Copy `config/database.php.example` to `config/database.php`, then copy
`.env.example` to `.env` and adjust the database credentials there. Both
`config/database.php` and `.env` are intentionally excluded from Git so local
credentials are not committed.

```php
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=online_exam_system
DB_USER=root
DB_PASS=                 # set your MySQL password here if you have one
```

All other files obtain their connection from this single file through the
`db()` function.

---

## 9. How to Run the System Locally

Open your browser at:

```
http://localhost/online-examination-system/
```

---

## 10. Default Administrator Login

```
Email:    admin@example.com
Password: admin123
```

> Change or remove this account before any real deployment.

---

## 11. Student Registration

Visit `http://localhost/online-examination-system/auth/register.php`, fill in the
student / registration number, full name, email, optional department and a
password, then log in.

The password must follow the system policy: at least 6 characters including one
capital letter (A-Z), one small letter (a-z) and one number (0-9), and no
spaces. The same rule applies whenever a password is changed or reset.

A demo student is also included:

```
Email:    student@example.com
Password: student123
```

---

## 12. How to Create an Examination

1. Log in as the administrator.
2. Go to **Examinations**.
3. Fill in the title, course name, course code, instructions, duration,
   number of questions and pass mark.
4. Optionally set **Attempts allowed / student** (leave `1` for a single sitting)
   and a scheduling window using **Opens** / **Closes**. Leave both window fields
   blank for an examination that is always available.
5. Click **Create**. The examination is saved as *Inactive* until you activate it.
   The window times are entered in the server's local timezone.

---

## 13. How to Add Questions

1. In **Examinations**, click the list icon next to an examination (or open
   **Questions** and choose the examination from the dropdown).
2. Enter the question text, options A–D and select the correct answer.
3. Click **Add Question**. Repeat for each question.
4. Return to **Examinations** and toggle the status to **Active** to release it to
   students.

---

## 14. Copying Questions Between Examinations

1. Open **Examinations → (list icon)** to reach the **Questions** screen and pick
   the examination you want to fill.
2. Expand **Copy questions from another examination**.
3. Choose the *source* examination and press **Load questions**. Every question
   of that paper is listed with a tick box.
4. Untick the questions you do not want, choose the *target* examination and
   press **Copy selected questions**.

Two options control the copy:

- **Skip questions that already exist in the target** – compares the question
  text, so re-running the copy does not create duplicates.
- **Also update the target's expected question count** – sets the target's
  *number of questions* to the number of questions it now holds.

The copy is written to the audit log.

---

## 15. Announcements (Notices)

Administrators post notices from **Announcements** in the navigation:

1. Enter a **title** and the **message**.
2. Choose the **audience** – *Students* (default) or *Students and
   administrators*.
3. Press **Publish**.

Published announcements appear immediately on the student dashboard and in the
**Notice Board** of the public landing page. Existing announcements can be
edited or deleted from the same screen, and every change is written to the
audit log.

---

## 16. How Students Take Examinations

1. Log in as a student and open **Examinations**.
2. An examination can only be started while it is open: before its **Opens** time
   the button reads *Opens …*, after its **Closes** time it reads *Closed*. The
   number of attempts already used and allowed is shown on the card.
3. Read the instructions and click **Start examination**.
4. Answer the questions using the Previous / Next buttons or the question
   navigator. The timer counts down at the top of the page.
5. Click **Submit Examination** and confirm. If time runs out, the examination is
   submitted automatically. If more than one attempt is allowed, the exam can be
   started again until the allowance is used up.

---

## 17. How Results are Generated

On submission, the backend compares each answer with the stored correct answer,
counts the correct/wrong answers, calculates the percentage and decides
pass/fail against the examination's pass mark. The result is written to the
`exam_attempts` and `student_answers` tables. Students see it immediately in
**Results / History**.

---

## 18. How to Export Reports

1. Log in as the administrator and open **Reports**.
2. Optionally filter by examination and click **Apply**.
3. Click **Export CSV**. A spreadsheet file is downloaded containing student
   name, student ID, examination, course, score, percentage, status and date.

---

## 19. Analytics and Printing Results

**Analytics** – the administrator dashboard shows a *Performance Analytics*
panel with the overall pass rate, the average score and the number of
passed/attempted papers, followed by a table with one row per examination
showing the number of attempts, a pass-rate bar, the average score and the
best and worst scores. The same numbers are broken down per examination in
**Reports**.

**Printing** – every result can be printed as a formal statement of result:

- Students: open the result (**Results** or **History → View**) and press
  **Print / Save PDF**.
- Administrators: open **Results → (view icon)** and press **Print / Save PDF**.

The print view (`print/result.php`) is a clean A4 sheet with the candidate's
details, the score, the pass mark, the pass/fail verdict, a per-question
answer breakdown and space for signatures. Use the browser's print dialogue and
choose *Save as PDF* to store it as a document.

---

## 20. Accounts, Passwords and Profile

Both roles manage their own account from the top-right **user menu → My
Profile** (administrators also have a **My Profile** link in the sidebar):

- **Update profile** – change your display name; students can also change their
  department. Registration number and email are fixed.
- **Change password** – enter your current password and the new one twice.
  Passwords must satisfy the system policy (at least 6 characters, one capital
  letter, one small letter, one number, no spaces) and are stored hashed.

Administrators can additionally reset a student's password from **Students**
using the key icon. A random temporary password that satisfies the policy is
generated and displayed once - share it with the student, who can then change it
from their own profile. Every administrator password change and student reset is
written to the audit log.

---

## 21. Importing Data from CSV

Administrators can add many questions or students at once instead of typing them
one by one. Both screens offer a downloadable, ready-to-fill template and a
collapsible **Import … from CSV** card.

**Questions** (`Examinations` → list icon → **Import questions from CSV**).
Columns:

```
question_text,option_a,option_b,option_c,option_d,correct_option
```

`correct_option` is a single letter `A`–`D`.

**Students** (`Students` → **Import students from CSV**). Columns:

```
reg_number,full_name,email,department,password
```

If `password` is left blank a random temporary password is generated; it is
shown once after the import so you can pass it to the student. Students can then
change it from their own profile.

The first row is treated as a header and skipped and blank lines are ignored.
Rows that are missing required values, use an invalid email, or duplicate an
existing registration number / email are skipped. After every import a summary
reports how many rows were imported and how many were skipped, and the action is
written to the audit log. The uploaded file must be UTF-8 and under 1 MB.

---

## 22. AI Assistant (Administrators only)

The system ships with a **built-in AI assistant** (`includes/ai.php`). It runs
completely offline - no internet connection, API key or extra software is
needed - and only administrators can reach it. Students never see any AI
option. When an OpenAI key is configured (`includes/openai.php`), it is used
first for novel questions and the offline engine tops up any shortfall.

Questions are **difficult, university-level** (deep understanding, critical
thinking, application, analysis, interpretation and problem-solving),
professionally worded, and varied - every question tests a different concept
and no commentary is added to the questions themselves. Difficulty and
academic level (university / postgraduate / professional) can be selected.

**No repeated questions.** Before saving, every candidate is compared
against the questions already stored for that examination (and against the
others in the same batch) for exact, paraphrase and near-duplicate matches,
including option overlap. Duplicates and invalid questions are rejected and
regenerated with a new seed until the requested number of distinct, valid
questions is reached. Manually added questions are checked the same way.

**AI Exam Generator** (`AI Generator` in the navigation, or the *Create an exam
with AI* button on **Examinations**):

1. Type a topic, e.g. `Introduction to Computer Networks`.
2. Choose how many questions, the question type (multiple choice, True/False
   or a mix), difficulty, academic level, the duration and the pass mark.
3. Press **Generate draft**. The AI drafts a full examination - title, course
   code, instructions and every question.
4. Review the draft: edit any question, untick the ones you do not want, or
   press **Regenerate** for a different draft.
5. Press **Save examination**. The exam is created as *Inactive* so you can
   add or refine questions before releasing it to students. Duplicate rows
   are rejected at save time as well.

**AI question generator** (on the **Questions** screen): give it a topic, a
count, a type, a difficulty and an academic level and it writes new questions
straight into the selected examination - only approved, non-duplicate
questions are saved, inside a database transaction.

**AI duplicate check** (on the **Questions** screen): the assistant compares
every question in the examination and reports pairs that are too similar, so
you can remove or reword them.

Everything the AI creates is written to the audit log.

---

## 23. Troubleshooting

| Problem | Solution |
|---------|----------|
| "A database connection error occurred." | Make sure MySQL is running and that `config/database.php` credentials are correct. |
| Blank page / white screen | Enable PHP error display temporarily or check the Apache error log. Ensure PHP 7.4+ is used. |
| "You are not authorized to access that page." | You are logged in with the wrong role; log out and log in as the correct user. |
| Examination not visible to students | The examination status must be set to **Active** and it must contain at least one question. |
| Examination says "Opens …" or "Closed" | The scheduling window is not currently active; adjust the **Opens** / **Closes** fields, or clear them for an always-available examination. |
| "You have used all your attempts" | The student has reached the **Attempts allowed / student** limit; raise it on the examination to permit another sitting. |
| Styles look broken | Confirm your browser can reach the Bootstrap 5 CDN, or host Bootstrap locally. |
| Cannot log in after import | Re-run `database/database.sql`; the default accounts are inserted by that script. |
| Student forgot their password | Log in as an administrator, open **Students**, and use the key icon to reset it; the temporary password is shown once. |
| CSV import reports rows skipped | Check the column order against the template; `correct_option` must be `A`–`D` and emails / registration numbers must be valid and unique. |
| The AI generates questions that are not about my topic | The AI works from keywords - use a specific topic, then edit the draft before saving. It is a drafting aid, not a source of truth. |
| The AI draft repeats a question | Duplicates are rejected automatically. Use the *Check for duplicate questions* button to review any remaining similar pairs in the examination. |
| A True/False question still shows four options | Clear the browser cache; the older page version is cached. |
| Administrator forgot their password | Re-run `database/database.sql`, or update the `admins` row directly in MySQL (store a `password_hash()` value). |
| Timer seems wrong | The timer is anchored to a server-stored deadline; refresh the page to resync. |
| "Too many failed login attempts" | Brute-force protection engaged; wait 15 minutes, or clear the `login_attempts` table. |
| `config/`/`includes/` files return 403 when opened directly | Correct - that is the `.htaccess` protection working as intended. |

---

## 24. Automated Tests

The database-free logic of the system is covered by a small test suite that runs
straight from the command line - no PHPUnit, no test framework, no database:

```bash
cd /path/to/online-examination-system
php tests/run_tests.php
```

It verifies the password policy (and the temporary-password generator), the
output escaping and URL helpers, the CSRF token round-trip, the examination
scheduling window logic, the AI similarity and duplicate detection and the
question generator. The script prints one line per assertion and exits with a
non-zero status if anything fails.

---

## Project Structure

```
online-examination-system/
├── config/            database.php            (credentials + PDO connection)
├── admin/             dashboard, students, examinations, questions, ai_exam,
│                      results, result_view, reports, announcements, audit_log,
│                      profile
├── student/           dashboard, examinations, start_exam, take_exam,
│                      save_answer, submit_exam, log_event, results, history,
│                      profile
├── print/             result                 (A4 statement of result)
├── auth/              login, register, logout
├── includes/          header, footer, navbar, admin_sidebar, auth, functions,
│                      csv, ai
├── assets/            css/style.css, js/script.js, images/
├── database/          database.sql
├── tests/             run_tests.php          (php tests/run_tests.php)
├── .htaccess          directory protection + friendly error pages
├── error.php          friendly 403/404/500 page
├── index.php
└── README.md
```
