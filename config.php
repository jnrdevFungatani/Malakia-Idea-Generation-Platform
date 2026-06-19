<?php
/**
 * Malakia Idea Generation Platform — application config + database bootstrap.
 *
 * Uses SQLite so the app runs with ZERO database setup: the file is created
 * automatically on first request, the schema is migrated, and demo ideas are
 * seeded. Drop the folder on any PHP 8.1+ host (LiteSpeed/Apache/Nginx) and go.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// App settings
// ---------------------------------------------------------------------------
const APP_NAME = 'Malakia';
const APP_TAGLINE = 'Idea Generation Platform';

// Where the SQLite database lives. Kept outside the web path where possible.
define('DB_PATH', __DIR__ . '/database/malakia.sqlite');

// Resolve the base URL path the app is served from (works in subdirectories).
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$scriptDir = rtrim($scriptDir, '/');
define('BASE_PATH', $scriptDir === '' ? '' : $scriptDir);

// ---------------------------------------------------------------------------
// Session — hardened cookie settings
// ---------------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('malakia_session');
    session_start();
}

// ---------------------------------------------------------------------------
// Database connection (PDO + SQLite) with auto-migration + seed
// ---------------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $firstRun = !file_exists(DB_PATH);

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA journal_mode = WAL;');

    migrate($pdo);

    if ($firstRun) {
        seed($pdo);
    }

    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            name          TEXT    NOT NULL,
            username      TEXT    NOT NULL UNIQUE,
            email         TEXT    NOT NULL UNIQUE,
            password_hash TEXT    NOT NULL,
            created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
        );
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS ideas (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL,
            title       TEXT    NOT NULL,
            description TEXT    NOT NULL,
            category    TEXT    NOT NULL DEFAULT 'General',
            tags        TEXT    NOT NULL DEFAULT '',
            status      TEXT    NOT NULL DEFAULT 'spark',
            created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS votes (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            idea_id    INTEGER NOT NULL,
            user_id    INTEGER NOT NULL,
            created_at TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE (idea_id, user_id),
            FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS comments (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            idea_id    INTEGER NOT NULL,
            user_id    INTEGER NOT NULL,
            body       TEXT    NOT NULL,
            created_at TEXT    NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    SQL);
}

function seed(PDO $pdo): void
{
    // Demo author so the idea wall looks alive on first run.
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, username, email, password_hash)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        'Studio Demo',
        'studio',
        'demo@malakia.app',
        password_hash('demo1234', PASSWORD_DEFAULT),
    ]);
    $demoId = (int) $pdo->lastInsertId();

    $ideas = [
        ['Spotlight Capture', 'A one-keystroke quick-capture overlay that floats over any screen so a thought never escapes before you write it down.', 'Product', 'capture,ux,shortcut', 'building'],
        ['Idea Heatmap', 'Visualise which themes a team keeps circling back to over months, so recurring obsessions surface on their own.', 'Analytics', 'data,viz,teams', 'exploring'],
        ['Silent Standup', 'Async voice notes that auto-transcribe into structured idea cards — no meeting required.', 'Workflow', 'async,voice,remote', 'spark'],
        ['Constellation View', 'Render the idea wall as a star map where related sparks pull toward each other by shared tags.', 'Design', 'visualization,graph', 'exploring'],
        ['Eureka Replay', 'Scrub back through how an idea evolved — every edit, vote and comment as a timeline you can replay.', 'Product', 'history,timeline', 'shipped'],
        ['Borrowed Brains', 'Invite an outsider for 24 hours to react to a single idea with total fresh-eyes context.', 'Community', 'feedback,fresh-eyes', 'spark'],
    ];

    $ins = $pdo->prepare(
        'INSERT INTO ideas (user_id, title, description, category, tags, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, datetime("now", ?))'
    );
    $offset = -1;
    foreach ($ideas as $i) {
        $ins->execute([$demoId, $i[0], $i[1], $i[2], $i[3], $i[4], "{$offset} hours"]);
        $offset -= 7;
    }
}
