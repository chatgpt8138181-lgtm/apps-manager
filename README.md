# PHP + MySQL App Manager

A cPanel-compatible PHP 8.x and MySQL/MariaDB version of the Google Sheets App Manager workflow.

## Features

- Admin login with hashed password and session protection
- CSRF protection on all write forms
- Category add/delete with live app counts
- App add/edit/delete with secure icon uploads
- Dynamic category display IDs that reset per category
- Search by app name or display ID
- Search ID behavior works per selected category or across all categories
- Active apps sort above Inactive apps, then older apps first
- Ready/Not Ready and Active/Inactive status badges
- Single result update and bulk update for visible search results

## File Structure

```text
config/database.php
includes/auth.php
includes/bootstrap.php
includes/csrf.php
includes/functions.php
public/index.php
public/login.php
public/logout.php
public/dashboard.php
public/add-app.php
public/search.php
public/categories.php
public/assets/css/style.css
public/uploads/apps/
database/schema.sql
README.md
```

## cPanel Setup

1. In cPanel, open **MySQL Databases**.
2. Create a database, for example `cpaneluser_app_manager`.
3. Create a database user, for example `cpaneluser_app_user`.
4. Set a strong password for that user.
5. Add the user to the database and grant **All Privileges**.
6. Open **phpMyAdmin**, choose the new database, click **Import**, and upload `database/schema.sql`.

## Database Credentials

Edit `config/database.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'cpaneluser_app_manager');
define('DB_USER', 'cpaneluser_app_user');
define('DB_PASS', 'your_database_password');
```

Do not place database credentials in public notes or screenshots.

## Uploading to public_html

Recommended layout when your host allows a `/public` document root:

```text
app-manager/
  config/
  includes/
  database/
  public/   <- document root points here
```

cPanel-friendly layout when files must run directly inside `public_html`:

```text
public_html/
  config/
  includes/
  database/
  assets/
  uploads/
  index.php
  login.php
  logout.php
  dashboard.php
  add-app.php
  search.php
  categories.php
```

For the direct `public_html` layout, copy everything inside `public/` into `public_html/`, then also copy `config/`, `includes/`, and `database/` into `public_html/`. The PHP include paths support both layouts.

## Upload Folder

Create this folder if it does not exist:

```text
public/uploads/apps/
```

For direct `public_html` installs, use:

```text
public_html/uploads/apps/
```

Set folder permissions to `755`. Uploaded image files should be `644`. The included `.htaccess` disables directory listing and blocks executable uploads in the upload folder.

## Default Login

- Username: `admin`
- Password: `password`

Change this immediately after installation.

## Change Admin Password

Create a new password hash with a temporary PHP file:

```php
<?php
echo password_hash('your_new_password', PASSWORD_DEFAULT);
```

Open it once in the browser, copy the hash, then delete the temporary file.

Update the database in phpMyAdmin:

```sql
UPDATE admins
SET password_hash = 'paste_the_new_hash_here'
WHERE username = 'admin';
```

## Usage

1. Visit `login.php`.
2. Sign in with the admin account.
3. Use **Categories** to add or delete categories.
4. Use **Add App** to create apps and upload icons.
5. Use **Search/Edit** to search by name or category display ID, update one app, update all visible results, or delete apps.
6. Use **Dashboard** to view all categories and app counts.

## Display ID Logic

Display IDs are not stored in the database. They are generated dynamically from the sorted visible order:

1. Category order
2. Active apps first
3. Inactive apps second
4. Older apps first within the same loading status

When a category is selected, searching `1` returns display ID 1 inside that category. With **All Categories**, searching `1` returns display ID 1 from every category.

## Backups

Back up both the database and uploaded icons.

- Database: cPanel > phpMyAdmin > select database > Export > Quick SQL export
- Uploads: download `uploads/apps/` through cPanel File Manager or FTP

Restore by importing the SQL file into the same database and uploading the `uploads/apps/` folder back to the site.

## Local Notes

This project does not require Node.js. It only needs PHP 8.x with PDO MySQL enabled and MySQL/MariaDB on the server.
