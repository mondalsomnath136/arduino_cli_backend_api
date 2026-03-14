# Arduino CLI Backend

Production-ready PHP + MySQL REST API backend for Arduino CLI compilation, verification, library management, and board management.

## Requirements

- PHP 7.4+ (with `pdo_mysql`, `json` extensions)
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite` enabled (or Nginx)
- [arduino-cli](https://arduino.github.io/arduino-cli/) installed and accessible in PATH

## Quick Setup

### 1. Database Setup

```bash
mysql -u root -p < database/migrations.sql
```

### 2. Configuration

Edit the config files in `/config/`:
- `database.php` - MySQL credentials
- `app.php` - App settings, CORS, rate limiting
- `arduino.php` - Arduino CLI path and board defaults

Or use environment variables:
```
DB_HOST=192.168.0.162
DB_PORT=3306
DB_NAME=arduino_cli_backend
DB_USER=root
DB_PASS=your_password
ARDUINO_CLI_PATH=arduino-cli
APP_DEBUG=false
```

### 3. Apache Virtual Host

Point your DocumentRoot to the `public/` directory:

```apache
<VirtualHost *:80>
    ServerName arduino-api.local
    DocumentRoot "/path/to/Arduino Cli Backend/public"
    
    <Directory "/path/to/Arduino Cli Backend/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 4. Verify Setup

```bash
curl http://192.168.0.162/api/v1/status
```

## API Endpoints (v1)

### Status
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/status` | Health check |

### Compilation
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/compile` | Compile Arduino code |
| POST | `/api/v1/verify` | Verify code (compile without binary) |
| GET | `/api/v1/compile/{id}/status` | Get compile status (add `?stream=1` for SSE) |
| GET | `/api/v1/compile/{id}/download` | Download compiled binary |

### Libraries
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/libraries` | List installed libraries |
| GET | `/api/v1/libraries/search?q=term` | Search libraries |
| POST | `/api/v1/libraries/install` | Install a library |
| DELETE | `/api/v1/libraries/{name}` | Remove a library |

### Boards
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/boards` | List installed platforms |
| GET | `/api/v1/boards?all=1` | List all available boards |
| GET | `/api/v1/boards/search?q=term` | Search platforms |
| GET | `/api/v1/boards/known` | Get shortname-to-FQBN mapping |
| POST | `/api/v1/boards/install` | Install a platform |
| POST | `/api/v1/boards/update-index` | Update board index |
| DELETE | `/api/v1/boards/{platform}` | Remove a platform |

## Example Requests

### Compile Code
```bash
curl -X POST http://192.168.0.162/api/v1/compile \
  -H "Content-Type: application/json" \
  -d '{
    "code": "void setup() { pinMode(13, OUTPUT); } void loop() { digitalWrite(13, HIGH); delay(1000); digitalWrite(13, LOW); delay(1000); }",
    "board": "uno"
  }'
```

### Verify Code
```bash
curl -X POST http://192.168.0.162/api/v1/verify \
  -H "Content-Type: application/json" \
  -d '{
    "code": "void setup() {} void loop() {}",
    "board": "arduino:avr:nano"
  }'
```

### Realtime Compile Logs (SSE)
```javascript
const evtSource = new EventSource('/api/v1/compile/JOB_ID/status?stream=1');
evtSource.addEventListener('log', (e) => {
    const data = JSON.parse(e.data);
    console.log(`[${data.level}] ${data.message}`);
});
evtSource.addEventListener('complete', (e) => {
    const data = JSON.parse(e.data);
    console.log('Done:', data.status);
    evtSource.close();
});
```

### Install Library
```bash
curl -X POST http://192.168.0.162/api/v1/libraries/install \
  -H "Content-Type: application/json" \
  -d '{"name": "Servo", "version": "1.2.0"}'
```

### Install Board Platform
```bash
curl -X POST http://192.168.0.162/api/v1/boards/install \
  -H "Content-Type: application/json" \
  -d '{"platform": "esp32:esp32"}'
```

## Board Shortnames

You can use shortnames instead of full FQBNs:

| Shortname | FQBN |
|-----------|------|
| `uno` | `arduino:avr:uno` |
| `nano` | `arduino:avr:nano` |
| `mega` | `arduino:avr:mega` |
| `esp32` | `esp32:esp32:esp32` |
| `esp8266` | `esp8266:esp8266:nodemcuv2` |

See full list at `GET /api/v1/boards/known`

## Project Structure

```
├── public/              # Web root
│   ├── index.php        # Single entry point
│   └── .htaccess        # URL rewriting
├── config/              # Configuration files
│   ├── database.php
│   ├── app.php
│   └── arduino.php
├── src/
│   ├── Core/            # Framework core
│   │   ├── Database.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   ├── Router.php
│   │   └── Logger.php
│   ├── Middleware/       # HTTP middleware
│   │   ├── CorsMiddleware.php
│   │   ├── RateLimitMiddleware.php
│   │   └── AuthMiddleware.php
│   └── V1/              # API Version 1
│       ├── Controllers/
│       ├── Services/
│       └── routes.php
├── storage/             # Runtime data
│   ├── logs/
│   ├── temp/
│   └── outputs/
└── database/
    └── migrations.sql
```

## API Versioning

Routes are organized under `/api/v1/`. To add a new API version:

1. Create `src/V2/` directory with Controllers, Services, routes.php
2. Add `$router->loadRoutes(__DIR__ . '/../src/V2/routes.php');` in `public/index.php`

## Security

- **Rate Limiting**: Configurable per-IP rate limiting
- **API Key Auth**: Optional API key authentication (enable in `index.php`)
- **Input Validation**: All inputs are validated before processing
- **SQL Injection Protection**: All queries use prepared statements
- **CORS**: Configurable cross-origin settings
- **No Directory Listing**: Disabled via .htaccess
