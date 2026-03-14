# TurnKey Linux (Apache/MySQL/PHP) - Setup Guide

This guide provides step-by-step instructions to install and configure the **Arduino CLI Backend API** on a freshly installed **TurnKey Linux LAMP stack** or any Ubuntu/Debian based Apache server.

---

## Step 1: Upload the Project
Upload the entire project folder to your Apache `DocumentRoot`, which is typically `/var/www/html` or `/var/www/yourdomain.com`.

If using GitHub:
```bash
cd /var/www/
git clone https://github.com/yourusername/arduino-cli-backend.git html
# Make sure the project files are directly inside /var/www/html/
```

---

## Step 2: Install Arduino CLI

The backend requires the `arduino-cli` binary to compile code. Run these commands as `root` to install it globally.

```bash
# 1. Download and install script
curl -fsSL https://raw.githubusercontent.com/arduino/arduino-cli/master/install.sh | sh

# 2. Move binary to global bin directory
sudo mv bin/arduino-cli /usr/local/bin/

# 3. Initialize config and update board index
arduino-cli config init
arduino-cli core update-index
```
*(Remember the path `/usr/local/bin/arduino-cli`, you will need it later for the `.env` file.)*

---

## Step 3: Fix "404 Not Found" (Configure Apache)

By default, TurnKey Linux disables `mod_rewrite` and ignores `.htaccess` files. Since this API routes all URLs (`/api/v1/...`) through `public/index.php`, you **must** enable URL rewriting.

**1. Enable `mod_rewrite`:**
```bash
sudo a2enmod rewrite
```

**2. Allow `.htaccess` overrides in Apache Configuration:**
Open the main Apache configuration file:
```bash
sudo nano /etc/apache2/apache2.conf
```

Scroll down to the `<Directory /var/www/>` block and change `AllowOverride None` to `AllowOverride All`. 

It should look exactly like this:
```apache
<Directory /var/www/>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```
Save and exit (`Ctrl+O`, `Enter`, `Ctrl+X`).

**3. Restart Apache to apply changes:**
```bash
sudo systemctl restart apache2
```

---

## Step 4: Set Directory Permissions (CRITICAL)

Apache runs as the user `www-data`. It needs permission to create temporary folders and save compiled `.hex`/`.bin` files in the `storage/` directory.

Run these commands on your project root (`/var/www/html`):

```bash
# Give ownership to Apache
sudo chown -R www-data:www-data /var/www/html

# Give Read/Write/Execute permissions to the storage directory
sudo chmod -R 777 /var/www/html/storage
```

---

## Step 5: Database Setup

TurnKey comes with MariaDB/MySQL pre-installed. You need to create a database and import the tables.

**1. Login to MySQL:**
```bash
mysql -u root -p
```
*(Enter your MySQL password if prompted)*

**2. Run the following SQL queries inside the MySQL prompt:**
```sql
CREATE DATABASE arduino_cli_backend CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'arduino_api'@'localhost' IDENTIFIED BY 'StrongPass123';
GRANT ALL PRIVILEGES ON arduino_cli_backend.* TO 'arduino_api'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**3. Import the tables from the schema:**
```bash
mysql -u arduino_api -p arduino_cli_backend < /var/www/html/database/migrations.sql
```

---

## Step 6: Configure `.env` Environment Variables

Copy the default environment variables or update the existing `.env` file in the project root.

```bash
nano /var/www/html/.env
```

Update it with your database credentials and the `arduino-cli` path:

```env
# Database Configuration
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=arduino_cli_backend
DB_USER=arduino_api
DB_PASS=StrongPass123

# Application Settings
APP_DEBUG=false

# Full Absolute Path to arduino-cli binary
ARDUINO_CLI_PATH=/usr/local/bin/arduino-cli
```
Save and exit (`Ctrl+O`, `Enter`, `Ctrl+X`).

---

## Step 7: Test the API

You are fully setup! Test the backend from your browser or Postman using the Server IP:

```
http://YOUR-SERVER-IP/api/v1/status
```

**Expected JSON Response:**
```json
{
  "success": true,
  "data": {
    "status": "ok",
    "version": "v1",
    "app": "Arduino CLI Backend",
    "database": "connected",
    "arduino_cli": "arduino-cli  Version: 1.0.4 Commit: ...",
    "storage": {
      "temp_writable": true,
      "logs_writable": true,
      "outputs_writable": true
    }
  }
}
```

---

### Troubleshooting

- **500 Internal Server Error:** Check your database credentials in `.env`.
- **404 Not Found on API routes (but `/` works):** Apache `AllowOverride` is still set to `None` or `mod_rewrite` is disabled. Re-check **Step 3**.
- **`sh: arduino-cli: command not found` in Compile output:** The `ARDUINO_CLI_PATH` in your `.env` is incorrect. Find the true path by running `which arduino-cli` in SSH.
- **Permission Denied on `/storage/temp`:** Apache does not have write access. Re-run `sudo chmod -R 777 /var/www/html/storage` (Step 4).
