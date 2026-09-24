# Einstein Center — Database Setup Guide

MySQL is used for both local XAMPP development and Hostinger production. Supabase remains documented as an optional alternative.

---

## Option 1 — MySQL (XAMPP / Local)

**File:** `einstein_mysql_setup.sql`

### Prerequisites
- XAMPP installed and running (Apache + MySQL)
- PHP 8.2+ (default with XAMPP)

### Steps

#### 1. Start XAMPP
Open the XAMPP Control Panel and start both **Apache** and **MySQL**.

#### 2. Run the SQL file

**Option A — Using the Windows terminal:**
```powershell
Get-Content einstein_mysql_setup.sql | mysql.exe -u root
```

**Option B — Using phpMyAdmin:**
1. Open `http://localhost/phpmyadmin`
2. Click **Import** in the top menu
3. Choose `einstein_mysql_setup.sql` → click **Go**

**Option C — MySQL Workbench:**
File → Open SQL Script → run it.

#### 3. Verify
```sql
USE einstein_center;
SHOW TABLES;
-- Should show: enrollments, otp_codes, users
```

#### 4. Config check
Open `public/includes/config.php`; its defaults already work for XAMPP when no `.env` file is present:
```php
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=einstein_center
DB_USER=root
DB_PASS=
DB_AUTO_BOOTSTRAP=true
```

No changes needed for local development.

#### 5. Test the site
Open: `http://localhost/einstein_v4/einstein_bugfix/main.html`

Try the enrollment flow. If you see any DB error, run `test_setup.php` in your browser:
`http://localhost/einstein_v4/einstein_bugfix/test_setup.php`

---

## Option 2 — Hostinger (MySQL / MariaDB Production)

The application uses PDO with MySQL-compatible SQL, so Hostinger Web/Cloud hosting can use the same schema. Hostinger’s Web/Cloud plans use MariaDB; the normal PHP connection host is `localhost` and the default port is `3306`.

### 1. Create the database in hPanel

Go to **Websites → Dashboard → Databases → Management**, then create a database and database user. Save these values:

| Setting | Value |
|---|---|
| Host | `localhost` |
| Port | `3306` |
| Database name | The exact name shown by Hostinger, often prefixed with your account name |
| Username | The exact MySQL user shown by Hostinger, often prefixed with your account name |
| Password | The password you set for that database user |

Hostinger documents the database name/user in **Databases Management** and confirms that the hostname for hosted databases is `localhost`.

### 2. Import the schema

Open the new database in Hostinger’s phpMyAdmin and choose **Import**. Before importing `einstein_mysql_setup.sql`, remove these two local-only statements from the copy being imported:

```sql
CREATE DATABASE IF NOT EXISTS einstein_center ...;
USE einstein_center;
```

The database must already be selected in phpMyAdmin. The remaining `CREATE TABLE`, `ALTER TABLE`, and seed statements can be imported as-is. This avoids the database-creation privilege error common on shared hosting.

### 3. Configure the application

Copy `.env.example` to `.env` and replace the placeholders:

```ini
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=YOUR_HOSTINGER_DATABASE_NAME
DB_USER=YOUR_HOSTINGER_DATABASE_USER
DB_PASS=YOUR_HOSTINGER_DATABASE_PASSWORD
DB_CHARSET=utf8mb4
DB_AUTO_BOOTSTRAP=false
```

Upload `config.php`, `.env`, the PHP/HTML/JS files, `uploads/`, and `logs/` to the website’s document root (normally `public_html`). Keep `.env` private; do not place it in a public download location or commit it to Git.

### 4. Verify

Open the website through its real HTTPS domain and test registration, login, enrollment, receipt upload, and the admin portal. If you receive “Access denied,” re-check the exact Hostinger database name, user, and password. If tables are missing, re-import the schema into the selected Hostinger database.

Hostinger references: [upload and set up a database](https://www.hostinger.com/support/1864324-how-to-upload-and-set-up-your-database-at-hostinger/) and [find MySQL database details](https://www.hostinger.com/support/1583552-how-to-find-your-mysql-database-details-in-hostinger/).

---

## Option 3 — Supabase (Cloud / Production)

**Files:** `einstein_supabase_migration.sql` · `config_supabase.php`

Supabase is a hosted PostgreSQL service. It replaces your local MySQL when you go live.

### Steps

#### 1. Create a Supabase project
1. Go to [supabase.com](https://supabase.com) → **New Project**
2. Choose a region close to the Philippines (e.g., Singapore `ap-southeast-1`)
3. Set a strong database password — **save it**, you'll need it in Step 4
4. Wait ~2 minutes for the project to provision

#### 2. Run the migration SQL
1. In your Supabase project, go to **SQL Editor** (left sidebar)
2. Click **New query**
3. Paste the entire contents of `einstein_supabase_migration.sql`
4. Click **Run** (or press `Ctrl+Enter`)
5. You should see: `table_name: enrollments, otp_codes, users`

#### 3. Verify tables
Go to **Table Editor** in the sidebar. You should see three tables:
- `users`
- `otp_codes`
- `enrollments`

#### 4. Get your credentials
Go to **Settings → Database** in your Supabase project:

| What you need | Where to find it |
|---|---|
| Project URL | Settings → API → `https://xxxx.supabase.co` |
| Service Role Key | Settings → API → under "Project API keys" → `service_role` (secret) |
| DB Host | Settings → Database → `db.xxxx.supabase.co` |
| DB Password | The password you set in Step 1 |

#### 5. Update config
Rename `config_supabase.php` to `config.php` (replace the old one), then fill in your credentials:

```php
define('SUPABASE_URL',         'https://YOUR_PROJECT_ID.supabase.co');
define('SUPABASE_SERVICE_KEY', 'YOUR_SERVICE_ROLE_SECRET_KEY');

define('DB_HOST', 'db.YOUR_PROJECT_ID.supabase.co');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD');
```

Leave everything else unchanged.

#### 6. Update save_enrollment.php for Supabase Storage
In `save_enrollment.php`, find the file upload section and replace `move_uploaded_file(...)` with `uploadToSupabase(...)`:

**Find this block:**
```php
$filename = 'pmt_' . $userId . '_' . time() . '.' . strtolower($ext);
$dest     = UPLOAD_DIR . $filename;
if (!move_uploaded_file($file['tmp_name'], $dest))
    throw new Exception('Failed to save payment screenshot. Please try again.');

$screenshotPath = 'uploads/' . $filename;
```

**Replace with:**
```php
$filename       = 'pmt_' . $userId . '_' . time() . '.' . strtolower($ext);
$screenshotPath = uploadToSupabase($file['tmp_name'], $filename);
```

That's the only PHP file that needs changing for file uploads.

#### 7. PHP driver requirement
Your hosting server needs the **PDO PostgreSQL driver** enabled. 

For XAMPP (testing Supabase locally), open `php.ini` and uncomment:
```
extension=pdo_pgsql
extension=pgsql
```
Then restart Apache.

For cPanel/shared hosting, check **PHP Extensions** in cPanel and enable `pdo_pgsql`.

#### 8. Test the connection
Add a temporary test file called `db_test.php` in your project root:
```php
<?php
require_once 'config.php';
try {
    $db = getDB();
    $r  = $db->query("SELECT COUNT(*) AS c FROM users")->fetch();
    echo "✅ Connected! Users: " . $r['c'];
} catch (Exception $e) {
    echo "❌ " . $e->getMessage();
}
```
Open it in your browser. Delete it after testing.

---

## What Changes Between MySQL and Supabase

| Feature | MySQL (XAMPP) | Supabase |
|---|---|---|
| Database engine | MySQL 8.0 | PostgreSQL 15 |
| Connection | `mysql:host=localhost` | `pgsql:host=db.xxx.supabase.co` |
| Auto-increment | `INT AUTO_INCREMENT` | `BIGSERIAL` |
| Booleans | `TINYINT(1)` | `BOOLEAN` |
| Timestamps | `TIMESTAMP` | `TIMESTAMPTZ` |
| File storage | Local `/uploads/` folder | Supabase Storage bucket |
| Backups | Manual / phpMyAdmin | Automatic daily backups |
| Access from internet | ❌ Local only | ✅ Yes |

### PHP query syntax
All your existing PHP queries work **without changes**. PDO abstracts the differences. Only the DSN (connection string) in `config.php` changes.

---

## Files Reference

| File | Purpose |
|---|---|
| `einstein_mysql_setup.sql` | Run once on XAMPP to create the database |
| `einstein_supabase_migration.sql` | Run once in Supabase SQL Editor |
| `config_supabase.php` | Replace `config.php` when switching to Supabase |
| `public/includes/config.php` | Active config — MySQL by default (gitignored) |

---

## Troubleshooting

**"SQLSTATE[HY000] [2002] No connection"**
→ MySQL isn't running. Start it in XAMPP Control Panel.

**"SQLSTATE[42P01] relation does not exist" (Supabase)**
→ The migration SQL didn't run fully. Re-run `einstein_supabase_migration.sql`.

**"Class 'PDO' not found" or pgsql driver error**
→ Enable `pdo_pgsql` in `php.ini` and restart Apache.

**"Access denied for user 'root'@'localhost'"**
→ Your MySQL password in `config.php` is wrong. Check XAMPP → MySQL → shell and reset if needed.

**Supabase upload fails with 401**
→ You used the `anon` key instead of `service_role`. Get the service_role key from Settings → API.
