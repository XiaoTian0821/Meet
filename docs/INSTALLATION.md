# ConnectMeet — Installation & Deployment

## A. Local XAMPP (development)

### 1. Prerequisites
- XAMPP with **Apache**, **MySQL/MariaDB**, and **PHP 8.2+** (8.3 recommended).
- Confirm the PHP module list includes `pdo_mysql`, `openssl`, `random`, `mbstring`:
  `C:\xampp\php\php.exe -m`

### 2. Start services
In the XAMPP Control Panel, start **Apache** (port 80) and **MySQL** (port 3306).

### 3. Place the project
Copy the `Meet/` folder to `C:\xampp\htdocs\Meet\` (or rename to `connectmeet` and update `base_url` in `config/database.php` accordingly).

### 4. Create the database & user
```sql
-- via: mysql -uroot  (or phpMyAdmin -> Import)
SOURCE C:/xampp/htdocs/Meet/database/install.sql;

CREATE USER IF NOT EXISTS 'connectmeet'@'localhost' IDENTIFIED BY 'connectmeet';
GRANT ALL PRIVILEGES ON connectmeet.* TO 'connectmeet'@'localhost';
FLUSH PRIVILEGES;
```

### 5. Configure credentials
Edit `config/database.php`:
```php
'db' => [
    'dsn'      => 'mysql:host=127.0.0.1;port=3306;dbname=connectmeet;charset=utf8mb4',
    'username' => 'connectmeet',
    'password' => 'connectmeet',
    ...
]
```

### 6. Configure the app
In `config/database.php`:
- `app.base_url` → `http://localhost/Meet` (match your folder).
- `webrtc.ice_servers` → the default Google STUN entries are fine for local testing.

### 7. Run
Open **http://localhost/Meet/**. You should see the landing page. The clock should tick and "Start instant meeting" should produce a code + link.

### 8. Test camera/microphone
On `http://localhost/Meet` (a secure context), browsers allow `getUserMedia`.
- Create a meeting, join it in a second browser (or a second Chrome profile), and confirm two-way audio/video.
- If the camera prompt is denied, check the site permissions icon in the address bar and re-enable "Camera" / "Microphone".

> **Note on localhost vs. remote.** `http://localhost` is a *secure context* for media APIs. If you access the app from **another computer** over plain `http://`, the browser will **block camera/microphone**. Use HTTPS for any remote testing.

### 9. Maintenance (optional locally)
To clean up stale/expired state:
```
C:\xampp\php\php.exe C:\xampp\htdocs\Meet\scripts\cleanup.php
```
(Cron on production; run manually in development.)

---

## B. cPanel / shared PHP hosting (production)

### 1. PHP version & extensions
- **PHP 8.3+** (or 8.2 if 8.3 is unavailable).
- Required extensions: `pdo_mysql`, `openssl`, `mbstring`, `random` (built-in on modern PHP).
- Set the PHP version in cPanel → *Select PHP Version*; disable `error_reporting` for production, keep `display_errors` **Off**.

### 2. Document root & permissions
- Upload the project files to `public_html/connectmeet/` (or your chosen subdirectory).
- Suggested ownership / permissions:
  - Directories: `755`
  - Files: `644`
  - `config/database.php`: `600` (owner-only), so credentials aren't world-readable.
- The `.htaccess` already blocks direct web access to `config/`, `scripts/`, `includes/`, `database/`, `tests/`.

### 3. Database & user
In cPanel → *MySQL® Databases*:
1. Create database `yourprefix_connectmeet`.
2. Create a DB user + password (not `root`).
3. **Add the user to the database** and grant **ALL PRIVILEGES**.
4. Import `database/install.sql` via *phpMyAdmin* (select the database first).
5. Put those credentials in `config/database.php` (and `app.base_url` = `https://yourdomain.com/connectmeet`).

### 4. HTTPS
- Install an SSL/TLS certificate (Let's Encrypt is typical in cPanel).
- Enable *force https* for the subdomain/folder.
- In `config/database.php`, set `app.cookie_secure = true`.
- **Media APIs only work over HTTPS in production** — this is mandatory for camera/mic/screen-share.

### 5. Cron (maintenance)
In cPanel → *Cron Jobs*, add:
```
*/15 * * * *  /usr/bin/php /home/USER/public_html/connectmeet/scripts/cleanup.php >/dev/null 2>&1
```
(Adjust the PHP binary path and home directory.)

### 6. STUN / TURN
See `docs/WEBRTC_STUN_TURN.md`. Shared hosting **cannot** run a TURN server or an SFU media relay. You must provision external TURN infrastructure for restrictive NATs, and document that meetings across very different networks may fail without it.

### 7. cPanel limitations
- Shared PHP hosts typically **do not** run persistent daemons, WebSocket servers, or background media relays. ConnectMeet is deliberately built to run on **standard PHP-FPM/Apache + MySQL + HTTP long-poll signaling**, so it works on shared hosting.
- It **cannot** provide a production SFU, server-side recording, or sub-second signaling on shared hosting. These require a VPS with a media server (mediasoup/Janus) — see the upgrade path in the WebRTC guide.

---

## C. Browser compatibility
- **Chrome / Edge (recent)** — full support (captions via Web Speech, recording via MediaRecorder, screen share).
- **Firefox (recent)** — full media; captions and some `setSinkId` paths differ.
- **Safari** — media + screen share work; Web Speech captions are more limited; `MediaRecorder` is partially supported.
- Use HTTPS (or `localhost`) for all media features.
