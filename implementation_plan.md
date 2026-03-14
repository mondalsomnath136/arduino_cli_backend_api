# Arduino CLI Backend - Implementation Plan

## Overview
Production-ready PHP + MySQL REST API backend for Arduino CLI compilation service with API versioning.

## Architecture

### Folder Structure
```
Arduino Cli Backend/
├── public/                          # Web root (point Apache/Nginx here)
│   ├── index.php                    # Entry point - routes all requests
│   └── .htaccess                    # Apache URL rewriting
├── config/
│   ├── database.php                 # MySQL connection config
│   ├── app.php                      # App-level config (paths, limits)
│   └── arduino.php                  # Arduino CLI paths & defaults
├── src/
│   ├── Core/
│   │   ├── Router.php               # Simple REST router
│   │   ├── Request.php              # Request wrapper
│   │   ├── Response.php             # JSON response helper
│   │   ├── Database.php             # PDO wrapper (singleton)
│   │   ├── Middleware.php           # Middleware base
│   │   └── Logger.php              # File-based logger
│   ├── Middleware/
│   │   ├── CorsMiddleware.php       # CORS headers
│   │   ├── RateLimitMiddleware.php  # Rate limiting
│   │   └── AuthMiddleware.php       # API key auth (optional)
│   └── V1/
│       ├── Controllers/
│       │   ├── CompileController.php    # Compile & verify endpoints
│       │   ├── LibraryController.php    # Library CRUD endpoints
│       │   ├── BoardController.php      # Board management endpoints
│       │   └── StatusController.php     # Health check / status
│       ├── Services/
│       │   ├── CompileService.php       # Arduino compile logic
│       │   ├── LibraryService.php       # Library install/remove
│       │   ├── BoardService.php         # Board install/list
│       │   └── FileService.php          # Temp file management
│       └── routes.php                   # V1 route definitions
├── storage/
│   ├── logs/                        # Application logs
│   ├── temp/                        # Temporary compile folders
│   └── outputs/                     # Compiled binary outputs
├── database/
│   └── migrations.sql               # Database schema
├── .htaccess                        # Root redirect to public/
└── composer.json                    # (minimal, no frameworks)
```

## API Endpoints (V1)

### Compilation
- `POST /api/v1/compile` - Compile Arduino code
- `GET  /api/v1/compile/{id}/status` - Get compile status (SSE for realtime)
- `GET  /api/v1/compile/{id}/download` - Download compiled binary
- `POST /api/v1/verify` - Verify (compile without binary output)

### Libraries
- `GET    /api/v1/libraries` - List installed libraries
- `POST   /api/v1/libraries/install` - Install a library
- `DELETE /api/v1/libraries/{name}` - Remove a library
- `GET    /api/v1/libraries/search` - Search available libraries

### Boards
- `GET    /api/v1/boards` - List installed boards
- `POST   /api/v1/boards/install` - Install a board platform
- `DELETE /api/v1/boards/{platform}` - Remove a board platform
- `GET    /api/v1/boards/search` - Search available board platforms

### Status
- `GET /api/v1/status` - Health check

## Database Tables
1. `compile_jobs` - Track compilation jobs
2. `compile_logs` - Store compile output logs (line by line for SSE)
3. `installed_libraries` - Track installed libraries
4. `installed_boards` - Track installed boards

## Key Features
- API Versioning (`/api/v1/...`)
- CORS support
- Rate limiting
- Realtime compile logs via SSE (Server-Sent Events)
- Binary file download after successful compile
- Proper error handling with JSON responses
- MySQL for state tracking
- File-based logging
