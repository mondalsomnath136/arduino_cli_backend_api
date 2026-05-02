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

---

## Step 8: MCP Server Setup (Remote AI Agent Access)

The MCP server runs as an HTTP endpoint inside Apache — no extra process needed. Follow these steps after completing Steps 1–7.

### 8.1 Install Composer on the Container

```bash
# Install Composer using PHP
cd /var/www/html
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Verify
composer --version
```

### 8.2 Install PHP Dependencies (mcp/sdk)

```bash
cd /var/www/html
composer install --no-dev --optimize-autoloader

# Fix ownership after composer install
sudo chown -R www-data:www-data /var/www/html/vendor
```

### 8.3 Create MCP Sessions Directory

```bash
mkdir -p /var/www/html/storage/mcp_sessions
sudo chown -R www-data:www-data /var/www/html/storage/mcp_sessions
sudo chmod -R 775 /var/www/html/storage/mcp_sessions
```

### 8.4 Apache Configuration for MCP Endpoint

The MCP server is accessible at:
```
http://YOUR-SERVER-IP/mcp.php
```

To ensure `mcp.php` works correctly via Apache, add this to your Apache VirtualHost or `.htaccess`:

```apache
# Allow direct access to mcp.php (it handles its own routing)
<Files "mcp.php">
    Require all granted
</Files>
```

Test the MCP endpoint is reachable:
```bash
curl -X POST http://YOUR-SERVER-IP/mcp.php \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}'
```

**Expected response:**
```json
{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{"tools":{},"resources":{},"prompts":{}},"serverInfo":{"name":"Arduino CLI Backend","version":"1.0.0"}}}
```

---

## Connecting Remote AI Clients to MCP Server

### VS Code Roo Code Extension

Open the Roo Code MCP settings file:
```
%APPDATA%\Code\User\globalStorage\rooveterinaryinc.roo-cline\settings\cline_mcp_settings.json
```

Add the following (replace `YOUR-SERVER-IP`):
```json
{
  "mcpServers": {
    "arduino-cli": {
      "url": "http://YOUR-SERVER-IP/mcp.php",
      "disabled": false,
      "alwaysAllow": []
    }
  }
}
```

### Antigravity IDE

In Antigravity MCP Settings, add a new **HTTP/SSE** server:
- **URL:** `http://YOUR-SERVER-IP/mcp.php`

### Claude Desktop (via mcp-remote proxy)

Claude Desktop only supports STDIO locally. Use the `mcp-remote` bridge:
```json
{
  "mcpServers": {
    "arduino-cli": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "http://YOUR-SERVER-IP/mcp.php"]
    }
  }
}
```
*(Requires Node.js on your Windows PC — not on the server)*

### Any Generic HTTP MCP Client

```
http://YOUR-SERVER-IP/mcp.php
```

---

### MCP Server Troubleshooting

- **MCP returns 500:** Run `sudo tail -f /var/log/apache2/error.log` on the container and check for PHP errors.
- **`vendor/autoload.php not found`:** Run `composer install` inside `/var/www/html/`.
- **`tools/list` returns empty tools:** Ensure `symfony/finder` is installed (`composer require symfony/finder`) and `src/MCP/` directory permissions are readable by `www-data`.
- **Session errors:** Check `/var/www/html/storage/mcp_sessions/` exists and is writable by Apache (`www-data`).
