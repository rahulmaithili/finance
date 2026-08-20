# 🏦 IEMS ERP - Income & Expense Management System

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg?style=flat-square&logo=php)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-MariaDB%20%2F%20MySQL-orange.svg?style=flat-square&logo=mysql)](https://www.mysql.com/)
[![Web Server](https://img.shields.io/badge/Server-Apache%20%2F%20PHP%20Built--in-lightgrey.svg?style=flat-square)](http://localhost:8000)
[![License](https://img.shields.io/badge/License-Proprietary-red.svg?style=flat-square)](#)

IEMS ERP is a secure, lightweight, and modern **Financial Accounting & Loan Tracking ERP** built on PHP and MySQL. It empowers businesses and individuals to track cash flows, manage bank accounts, disburse & track lent/borrowed loans, compute EMIs dynamically, collect payments via UPI QR codes, and monitor activities through automated system audits.

---

## 🌟 Key Features

### 📈 1. Financial Dashboard
* **Dynamic KPIs:** Quick-glance metrics for Income (This Month), Expense (This Month), Net Margin, Available Balance, Loan Outstanding, and Lent Receivables.
* **Interactive Charting:** Income vs Expense Analysis over the last 6 months using charts.
* **Recent Ledger Entries:** Quick log showing the latest cash transactions in real-time.

### 🏦 2. Bank Accounts Management
* Track balances across multiple active bank accounts.
* Transfer money seamlessly between accounts with automated transaction logs.
* Track deposit and autodebit sources.

### 💰 3. Income & Expense Tracker
* Log income and expenses with detailed metadata (Categories, Accounts, Dates, Reference Numbers).
* Upload invoice receipts or payment proofs (Images, PDFs).
* Categorized listing with smart colour-coded badges.

### 📊 4. Advanced Loan Managers
* **Lent Loans (Given Assets):** Disburse capital to debtors. Manage borrower info (WhatsApp, Email, Address), calculate monthly EMIs automatically, track total recovered capital, and attach signed agreements.
* **Borrowed Loans (Liabilities):** Log loans taken from banks/institutions, track repayment schedules, calculate monthly EMIs, and auto-debit payments directly from bank balances.

### 🔳 5. Quick Collect & POS Billing
* Generate **Dynamic UPI QR Codes** (deep-links) instantly on print schedules based on the transaction amount.
* **Static Backup QR Code:** Upload settings to show a static backup QR image if dynamic calculation VPAs are not configured.

### ⚙️ 6. System & Brand Customization
* Customize site name, copyright footer text, and logo branding.
* Supports **Global Currency Codes** (INR `₹`, USD `$`, EUR `€`, GBP `£`, and more).
* Toggle **Maintenance Mode** with 1-click.
* Integration fields for **Razorpay Gateway** (Key ID & Secret) and **UPI VPA** settings.

### 🔒 7. Access Control & Auditing
* **User Permissions:** Administrative panel to manage roles (Super Admin, Admin, Manager).
* **Security Audits:** Activity logs screen keeping a detailed audit trail of all transactions, login events, and settings changes.

---

## 🛠️ Tech Stack & Prerequisites

* **Backend:** PHP 8.0 or higher (with PDO-MySQL support enabled)
* **Database:** MariaDB / MySQL Server
* **Frontend UI:** Modern Glassmorphic Dark UI (Tailwind-like custom CSS styles, FontAwesome 6 icons, jQuery, SweetAlert2, DataTables)
* **Local environment tool:** XAMPP or custom PHP/MySQL stack

---

## 🚀 Installation & Local Database Setup

### Step 1: Clone the Repository
Clone the codebase into your local web server directory (e.g. `htdocs` for XAMPP):
```bash
git clone https://github.com/rahulmaithili/finance.git
```

### Step 2: Configure Database Credentials
Open `config.php` and set your MySQL server login credentials:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'income_expense_erp');
```
> **Note:** You do not need to create the database manually. The application auto-detects if the database exists and initializes it on the first load.

### Step 3: Run the Local Services
Double-click the pre-configured launcher utility script in the root directory:
```bash
start-servers.bat
```
This single click launches:
1. **MySQL Database Server** pointing to your custom MariaDB directory.
2. **PHP Built-in Server** on `http://localhost:8000`.
3. Opens your default web browser to the login screen.

---

## 👥 Default Logins (Super Admin Console)

Use these credentials for first-time login:
* **Username:** `super_admin`
* **Password:** *Contact administrator for active OTP and authentication keys.*

---

## 📂 Project Structure

```text
├── uploads/              # Uploaded user profile photos, invoices, and documents
│   ├── branding/         # System logos and static QR images
│   ├── invoices/         # Income and Expense transaction receipts
│   └── documents/        # Loan agreement PDFs and contract copies
├── config.php            # Secure database configuration & environment hooks
├── dashboard.php         # Main financial KPI overview & analytics
├── income.php            # Income tracker module
├── expense.php           # Expense tracker module
├── loans.php             # Liabilities / Borrowed Loans tracker
├── loans-given.php       # Assets / Lent Loans disburser
├── quick-collect.php     # POS billing & dynamic QR collector
├── settings.php          # System customization control board
├── sidebar.php           # Desktop navigation control
├── mobile-menu.php       # Mobile drawer navigation & bottom quick-links bar
└── start-servers.bat     # Windows 1-click development server launcher
```

---

## 🎨 Premium Sidebar Themes
The navigation sidebar features beautiful, colored brand-specific indicators:
* `Dashboard` - Blue
* `Bank Accounts` - Emerald Green
* `Income` - Leaf Green
* `Expenses` - Red
* `Transfers` - Indigo
* `Reports` - Gold Yellow
* `Loans` - Amber Orange
* `Given Loans` - Purple
* `Quick Collect` - Cyan
* `Settings` - Rose

---

## 📄 License
This application is proprietary software. All rights reserved.
