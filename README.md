# 14th Central Student Council - PUP Binan Campus Office System

A localhost PHP + MySQL system (built for XAMPP) to digitalize and log the CSC's
student services: **Printan ng Bayan** and **ISKOLEND**, plus
**Officer Attendance** and an **Analytics/Accomplishment Report** dashboard.

Students and officers use the barcode on their school ID. Scan it with a USB
barcode scanner, which types the ID into the focused field like a keyboard.
Student numbers use the format `0000-00000-BN-0`.

---

## 1. Requirements

- **XAMPP** (Apache + MySQL + PHP 8.0 or newer) — https://www.apachefriends.org
- A USB barcode scanner for school ID barcodes (keyboard-wedge mode).
- Internet access **on the office PC's browser** the first time each page loads.
  This system's data and database are 100% local/offline (nothing is uploaded
  anywhere) — but front-end libraries are loaded from a CDN: Bootstrap, jsPDF,
  jsPDF-AutoTable, and Chart.js. If your office has no internet at all, see **Section 6 — Going
  Fully Offline** below.

---

## 2. Installation

1. Copy the entire project folder into your local web root (for example,
   your XAMPP `htdocs` folder) and keep the folder name flexible.
2. Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.
3. Import `database/install.sql` into phpMyAdmin or run it in MySQL to create
   the database and system schema.
4. Copy `config/db.sample.php` to `config/db.php` and update the local MySQL
   credentials if they differ from your machine.
5. Open the application in your browser and access the admin setup page once to
   create the first administrator account.
6. Log in and use the system normally.

The app is designed to work from any folder name or local path under the web
root, and it reads its database settings from `config/db.php`.

---

## 3. Everyday Workflow

### Register students & officers (do this first)
- **Students module:** register each student's Student Number, name, program,
  year, section, and contact number. The page supports live search, sorting,
  and pagination. Scan the barcode printed on their school ID in service modules.
- Student records can be blacklisted with an offense and reason. Active
  blacklists block Printan and ISKOLEND server-side; lifting a blacklist records
  the officer and reason while retaining the full blacklist history.
- **Officers module:** register a student as a CSC officer, set their position
  and birthday, and manage meetings, events, agendas, and deadlines on the
  calendar. Officer birthdays are shown as read-only calendar entries and on
  the dashboard.

### Dashboard reminders
The signed-in dashboard clock and agenda use Philippines time. Events for today
are shown in a reminder modal once per session; officer birthdays today are
celebrated, with birthdays in the next 7 days shown when there are none today.

### Printan ng Bayan
Go to **Printan ng Bayan → Log a Request**, scan the student's barcode, then
choose print/xerox/scan, pages, copies, paper, color, sides, reason, and officer.
The price is calculated from the fixed rate table (₱1/₱2/₱3/₱4/₱7 per page)
and revalidated by the server. Blacklisted students cannot submit a request.
**View Records** provides a daily summary with a date picker, searchable and
paginated records, date/status filters, and PDF export with the council header
and footer. The ledger export is also available at the printan export route.

### ISKOLEND
- **Borrow:** scan the student, filter inventory by category, select an item
  and quantity, and confirm. The server enforces stock, per-item quantity, and
  active-borrow limits inside a database transaction. The due date is based on
  each item's `borrow_days`; available inventory is decremented atomically.
- **Return:** scan the student again to see item images, quantities, due dates,
  and overdue badges. Log each item's condition (Good/Damaged/Lost). Good
  returns restore the full borrowed quantity to stock; damaged/lost items do
  not.
- The per-student borrowing allowance is configurable on **Manage Items →
  Borrowing Limits** and stored in `app_settings` as `MAX_ACTIVE_BORROWS`
  (default 3 outstanding item units). It is shared across all active borrowing
  records and enforced transactionally by the borrow-save endpoint.
- **Inventory:** browse the visual item cards by category or search, export
  the filtered list as a branded PDF, and use the Add/Edit/Restock modals.
  New items require a JPG, JPEG, PNG, or WEBP image up to 2 MB; uploads are
  MIME-checked and stored under `uploads/items/`. Add categories from the
  Add Item modal; the four standard categories are seeded by migration.

### Officer Attendance
Scan an officer's ID barcode once to log **Time In**; scan again later the same
day to log **Time Out**. Hours worked are calculated automatically.

### Analytics & Reports
Go to **Analytics & Reports** for charts (printing revenue trends, most
borrowed items, attendance hours, etc.) — useful
for your accomplishment report, auditing, or State of the Student Council
address. Click **Export Summary as PDF** for a ready-to-print consolidated
report.

### Exporting records
Record lists export PDFs with the council header and footer artwork, page
numbers, and generated timestamps. PDF export requires the pinned jsPDF and
jsPDF-AutoTable CDN libraries.

---

## 4. Database Structure (summary)

| Table | Purpose |
|---|---|
| `students` | Student registry and contact details (barcode = student_no) |
| `officers` | Master list of CSC officers (barcode = officer_id) |
| `officer_events` | Officer calendar events and dashboard agenda reminders |
| `users` | Login accounts for staff operating the system |
| `printan_records` | Every print transaction |
| `iskolend_items` | Lendable item inventory |
| `iskolend_borrow` | Every borrow/return transaction |
| `attendance_records` | Officer time-in/time-out logs |
| `app_settings` | Hashed six-digit PIN and lockout settings |
| `student_blacklist` | Blacklist and lift history, including reasons and officers |

Full column definitions are in `database.sql`.

---

## 5. Security Notes

- Change the default admin password after first login (there's no "change
  password" page yet — you can update it directly in phpMyAdmin using
  `UPDATE users SET password = '<new hash>' WHERE username='admin';`, where
  `<new hash>` is generated with PHP's `password_hash()`).
- Delete `setup.php` once you've created the admin account.
- This system has no built-in protection against being exposed to the public
  internet — it is designed for **local network / localhost use only**, as
  requested.

---

## 6. Going Fully Offline (optional)

For a fully offline setup, download the pinned Bootstrap, jsPDF,
jsPDF-AutoTable, and Chart.js assets from their CDNs and update the script and
stylesheet URLs to point to those local copies.

---

## 7. Folder Structure

```
csc_system/
├── database.sql              ← import this first
├── setup.php                 ← run once, then delete
├── config/db.php             ← database credentials
├── login.php / logout.php
├── dashboard.php
├── includes/                 ← shared header/footer/auth
├── assets/css/style.css
└── modules/
    ├── students/
    ├── officers/
    ├── printan/
    ├── iskolend/
    ├── attendance/
    └── analytics/
```
