# VideoShrink — Local H.264 Video Compressor for Windows

**VideoShrink** is a local, self-hosted video compression application built with Laravel, MySQL, FFmpeg (`libx264`), and vanilla JavaScript. It compresses large videos down to strict target sizes (e.g., 20 MB, 40 MB, 80 MB, 100 MB, or custom MB) while preserving full video duration and audio.

The application operates **100% locally on your computer** without AI APIs, external cloud dependencies, paid services, or CDN connections.

---

## Features

- **Chunked Sequential Uploads:** Upload files up to 1 GB in reliable ~10 MB chunks. Assembled on disk without loading entire videos into PHP memory.
- **Accurate Target Size Mode:** Calculates exact bitrate budgets using the convention **1 MB = 1,000,000 bytes**, reserving container overhead margins.
- **Two-Pass CPU H.264 Encoding:** Employs two-pass encoding (`libx264` + `aac`) with unique pass-log paths for maximum compression efficiency.
- **Resolution Scaling & Aspect Ratio Preservation:** Supports landscape and portrait videos, prevents upscaling, and guarantees even pixel dimensions for H.264 compliance.
- **Real-Time Progress & Polling:** Displays separate upload progress and actual two-pass FFmpeg compression progress polled every 2 seconds.
- **Bounded Overshoot Retries:** If an output slightly overshoots the requested target size, VideoShrink automatically recalculates a reduced bitrate and re-encodes (up to 3 attempts).
- **In-Browser Preview & Streaming:** Serves compressed output via controlled routes supporting HTTP `206 Partial Content` (Range requests) for smooth video player scrubbing.
- **Automated Cleanup:** Scheduled background command cleans expired chunks and temporary pass-log files.

---

## 1. Prerequisites (Windows)

Ensure you have the following installed on your Windows computer:

1. **PHP 8.2 or later** (with extensions: `pdo_mysql`, `fileinfo`, `mbstring`, `curl`, `xml`, `openssl`).
   - If using XAMPP, PHP is located at `C:\xampp\php\php.exe`.
2. **Composer 2.x**: [https://getcomposer.org/](https://getcomposer.org/)
3. **MySQL / MariaDB**:
   - Running locally on port `3306` (e.g., via XAMPP or Windows Service).
4. **FFmpeg & FFprobe (with libx264 support)**:
   - Must be installed and accessible.

---

## 2. Installing FFmpeg with libx264 on Windows

### Option A: Using Windows Package Manager (Winget) — Recommended
Open PowerShell as Administrator and run:
```powershell
winget install Gyan.FFmpeg
```
This installs the Gyan.dev FFmpeg build which includes `libx264` and automatically adds FFmpeg to your system `PATH`.

### Option B: Using Pre-installed Media Tools (e.g., Kdenlive)
If you already have video tools installed (such as Kdenlive), you can point directly to their binaries:
```env
FFMPEG_BINARY='C:\Program Files\Kdenlive\bin\ffmpeg.exe'
FFPROBE_BINARY='C:\Program Files\Kdenlive\bin\ffprobe.exe'
```

### Option C: Manual Download
1. Download the release essentials build from [https://www.gyan.dev/ffmpeg/builds/](https://www.gyan.dev/ffmpeg/builds/).
2. Extract the archive to `C:\ffmpeg`.
3. Add `C:\ffmpeg\bin` to your Windows System Environment Variables `PATH`.

### Verify FFmpeg and FFprobe
In PowerShell, run:
```powershell
ffmpeg -version
ffprobe -version
```
Ensure `--enable-libx264` appears in the configuration output.

---

## 3. Database Setup

1. Start your local MySQL service (e.g., start MySQL from the XAMPP Control Panel).
2. Create the dedicated database named `video_compressor`:
```powershell
# Using the mysql client:
mysql -h 127.0.0.1 -u root -e "CREATE DATABASE IF NOT EXISTS video_compressor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

---

## 4. Configuration (`.env`)

Verify your `.env` configuration file in the project root:

```ini
APP_NAME=VideoShrink
APP_ENV=local
APP_KEY=base64:... # Existing key preserved - DO NOT REGENERATE ON EXISTING INSTALLS
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=video_compressor
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=3660

# VideoShrink Settings (Single quotes prevent backslash escape errors on Windows)
FFMPEG_BINARY='C:\Program Files\Kdenlive\bin\ffmpeg.exe'
FFPROBE_BINARY='C:\Program Files\Kdenlive\bin\ffprobe.exe'
MAX_UPLOAD_SIZE=1073741824
VIDEO_MAX_PROCESSING_SECONDS=3600
VIDEO_TEMP_RETENTION_HOURS=24
VIDEO_COMPLETED_RETENTION_DAYS=7
VIDEO_QUEUE=video
```

> **Note on Windows Paths:** Wrap paths containing spaces or backslashes in **single quotes** (`'...'`) so Laravel's Dotenv parser does not treat backslashes as escape sequences.

---

## 5. Installation & Execution Steps

### Step 1: Install Composer Dependencies
```powershell
composer install
```

### Step 2: Generate Application Key (New Installations Only)
> ⚠️ **Skip this step if an `APP_KEY` is already present in `.env`!**
```powershell
php artisan key:generate
```

### Step 3: Run Database Migrations
```powershell
php artisan migrate
```

### Step 4: Start the Web Server (Terminal 1)
Bind the development server to `127.0.0.1`:
```powershell
php artisan serve --host=127.0.0.1 --port=8000
```
Open your browser and visit: **[http://127.0.0.1:8000](http://127.0.0.1:8000)**

### Step 5: Start the Dedicated Video Queue Worker (Terminal 2)
Video compression jobs run in the background on the dedicated `video` queue. Run the worker in a second terminal:
```powershell
php artisan queue:work --queue=video --timeout=3600 --tries=1
```

> ⚠️ **Keep Your Computer On:** Video compression utilizes local CPU cycles. Do not put the computer to sleep or close the queue worker while compression is actively processing.

### Step 6: Start Scheduled Cleanup (Terminal 3 - Optional for Automated Maintenance)
To automatically clean up abandoned upload chunks and old temporary files:
```powershell
php artisan schedule:work
```
Or run the cleanup routine manually at any time:
```powershell
php artisan video:clean-temp
```

---

## 6. Upload Mechanics & Limits

VideoShrink uses **client-side chunking** (~10 MB chunks):
- Large videos (up to 1 GB) bypass standard PHP `upload_max_filesize` limits because each HTTP request transfers only one 10 MB chunk.
- Chunks are assembled sequentially using buffered streams into private application storage (`storage/app/videos/input/`).
- The assembled file is validated using FFprobe before being accepted.

Recommended `php.ini` values (e.g., in `C:\xampp\php\php.ini`):
- `upload_max_filesize = 20M` (must be greater than the 10 MB chunk size)
- `post_max_size = 25M`
- `memory_limit = 256M`
- `max_execution_time = 300`

---

## 7. Troubleshooting

### 1. FFmpeg or FFprobe Not Found
- **Error:** `FFprobe failed to inspect media file` or `CommandNotFoundException`.
- **Solution:** Verify the path configured in `.env` exists:
  ```powershell
  Test-Path "C:\Program Files\Kdenlive\bin\ffmpeg.exe"
  ```
  If installing FFmpeg freshly via winget or zip, set `FFMPEG_BINARY='ffmpeg'` and ensure `ffmpeg.exe` is in your system `PATH`.

### 2. `libx264` Unavailable
- **Error:** `Unknown encoder 'libx264'`.
- **Solution:** Your FFmpeg build was compiled without H.264 support. Install the Gyan.dev build using `winget install Gyan.FFmpeg`.

### 3. MySQL Connection Failure
- **Error:** `SQLSTATE[HY000] [2002] Can't connect to MySQL server`.
- **Solution:** 
  1. Verify MySQL is running on port 3306. In PowerShell:
     ```powershell
     Test-NetConnection -ComputerName 127.0.0.1 -Port 3306
     ```
  2. If using XAMPP, open XAMPP Control Panel and start MySQL, or run:
     ```powershell
     C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone
     ```

### 4. Queue Worker Not Running / Video Stuck at "Queued"
- **Symptom:** UI says "Queued" but progress stays at 0%.
- **Solution:** Ensure the queue worker is running in a terminal with the `--queue=video` flag:
  ```powershell
  php artisan queue:work --queue=video --timeout=3600 --tries=1
  ```

### 5. Failed Uploads
- **Symptom:** Chunk upload fails or returns an error.
- **Solution:** VideoShrink includes automatic retry logic (3 attempts per chunk). If all fail, check your `php.ini` `upload_max_filesize` and ensure `storage/app/temp` has write permissions.

### 6. Storage Permission Errors
- **Error:** `Permission denied` when saving chunks or output files.
- **Solution:** Ensure the `storage/` directory and subdirectories are writable by PHP:
  ```powershell
  icacls "E:\Video Compress\storage" /grant "Everyone:(OI)(CI)F"
  ```

### 7. Encoding Timeouts
- **Symptom:** Long videos time out after a few minutes.
- **Solution:** `VIDEO_MAX_PROCESSING_SECONDS` in `.env` is set to 3600 (1 hour). For very long 4K videos, increase `VIDEO_MAX_PROCESSING_SECONDS=7200` and adjust `DB_QUEUE_RETRY_AFTER=7260`.

### 8. Infeasible Target Size Error
- **Symptom:** UI displays "Target size of X MB is too small for a Ys video".
- **Solution:** A video of given duration requires a minimum bitrate to remain playable. Choose a higher target size (e.g., 40 MB or 80 MB) or choose a lower resolution preset (e.g., 480p or 720p).

### 9. Insufficient Disk Space
- **Symptom:** FFmpeg errors with `No space left on device` or truncated output.
- **Solution:** Ensure your local drive has free space equal to at least 2.5× the size of the uploaded video (accounting for source, pass-logs, and final MP4).

---

## 8. Verification & Running Tests

Run the complete test suite including unit tests, API feature tests, and the FFmpeg synthetic smoke test:
```powershell
php artisan test
```

All 19 tests will execute, including:
- Bitrate calculation with and without audio streams
- Infeasible target size rejection
- Chunk ordering and size enforcement
- Duplicate compression request protection
- Video deletion locking during active encoding
- Real synthetic video generation, two-pass libx264 encoding, duration preservation, and target size verification.
