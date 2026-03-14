# Arduino CLI Backend — Project Walkthrough

## 📁 Complete File Structure (23 files)

```
Arduino Cli Backend/
├── public/
│   ├── index.php              ← Single entry point (all requests route here)
│   └── .htaccess              ← Apache URL rewrite rules
├── config/
│   ├── database.php           ← MySQL connection settings
│   ├── app.php                ← CORS, rate limiting, storage paths, compile settings
│   └── arduino.php            ← arduino-cli path, board FQBNs, additional URLs
├── src/
│   ├── Core/
│   │   ├── Database.php       ← PDO singleton wrapper
│   │   ├── Request.php        ← HTTP request parser (JSON body, headers, params)
│   │   ├── Response.php       ← JSON response + SSE + file download helper
│   │   ├── Router.php         ← REST router with dynamic {params}
│   │   └── Logger.php         ← Daily log file writer
│   ├── Middleware/
│   │   ├── CorsMiddleware.php      ← Cross-origin headers
│   │   ├── RateLimitMiddleware.php ← IP-based rate limiting (MySQL)
│   │   └── AuthMiddleware.php      ← Optional API key auth
│   └── V1/                         ← API Version 1
│       ├── Controllers/
│       │   ├── CompileController.php   ← compile, verify, status(SSE), download
│       │   ├── LibraryController.php   ← list, search, install, uninstall
│       │   ├── BoardController.php     ← list, search, install, uninstall, update-index
│       │   └── StatusController.php    ← health check
│       ├── Services/
│       │   ├── CompileService.php      ← Core compile logic with proc_open
│       │   ├── LibraryService.php      ← arduino-cli lib wrapper
│       │   ├── BoardService.php        ← arduino-cli core wrapper
│       │   └── FileService.php         ← Temp dir & binary file management
│       └── routes.php                  ← V1 route definitions
├── storage/
│   ├── logs/                ← Application log files (daily rotation)
│   ├── temp/                ← Temporary sketch directories
│   └── outputs/             ← Compiled binary outputs
├── database/
│   └── migrations.sql       ← Full MySQL schema (6 tables)
├── .htaccess                ← Root redirect to public/
├── .gitignore
└── README.md
```

## 🔌 API Endpoints

### Status
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/status` | Health check + system info |

### Compile & Verify
| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/v1/compile` | Compile code → get binary |
| `POST` | `/api/v1/verify` | Verify code (no binary output) |
| `GET` | `/api/v1/compile/{id}/status` | Job status / SSE log stream |
| `GET` | `/api/v1/compile/{id}/download` | Download compiled binary |

### Libraries
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/libraries` | List installed |
| `GET` | `/api/v1/libraries/search?q=...` | Search available |
| `POST` | `/api/v1/libraries/install` | Install library |
| `DELETE` | `/api/v1/libraries/{name}` | Remove library |

### Boards
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/boards` | List installed platforms |
| `GET` | `/api/v1/boards?all=1` | List all boards |
| `GET` | `/api/v1/boards/search?q=...` | Search platforms |
| `GET` | `/api/v1/boards/known` | Shortname → FQBN map |
| `POST` | `/api/v1/boards/install` | Install platform |
| `POST` | `/api/v1/boards/update-index` | Update board index |
| `DELETE` | `/api/v1/boards/{platform}` | Remove platform |

## 🗄️ Database Tables

| Table | Purpose |
|-------|---------|
| `compile_jobs` | Track compilation jobs with status |
| `compile_logs` | Line-by-line logs for SSE streaming |
| `installed_libraries` | Track installed Arduino libraries |
| `installed_boards` | Track installed board platforms |
| `rate_limits` | IP-based rate limiting |
| `api_keys` | Optional API key authentication |

## 🚀 Setup Steps

1. **Import MySQL schema:**
   ```bash
   mysql -u root -p < database/migrations.sql
   ```

2. **Configure** [config/database.php](file:///c:/Users/Somnath/Documents/Arduino%20Cli%20Backend/config/database.php) with your MySQL credentials

3. **Point Apache DocumentRoot** to the `public/` folder

4. **Test:** `GET /api/v1/status`

## 🔑 Key Design Decisions

- **No Composer required** — PSR-4 autoloading implemented manually in [index.php](file:///c:/Users/Somnath/Documents/Arduino%20Cli%20Backend/public/index.php)
- **API Versioning** — Create `src/V2/` with its own Controllers/Services/routes for future versions
- **Realtime SSE** — Compile logs are stored row-by-row in MySQL, streamed via Server-Sent Events
- **Board shortnames** — Users can pass `"uno"` instead of `"arduino:avr:uno"`
- **proc_open** — Used instead of `shell_exec` for realtime output capture with timeout
