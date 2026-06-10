<?php

$config = require __DIR__ . '/config.php';

function avatar_color(string $name): string
{
    $palette = ['#a9cf88', '#c7b5e8', '#f4ead7', '#8fb174', '#b9d7a2'];
    return $palette[abs(crc32($name)) % count($palette)];
}

function db(array $config): PDO
{
    $charset = $config['charset'] ?? 'utf8mb4';
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset={$charset}",
        $config['user'],
        $config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function require_login(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: index.php');
        exit;
    }
}

function ensure_admin_usernames(PDO $pdo, string $databaseName): void
{
    $columns = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'admins'"
    );
    $columns->execute([$databaseName]);
    $existing = array_column($columns->fetchAll(), 'COLUMN_NAME');

    if (!in_array('username', $existing, true)) {
        $pdo->exec("ALTER TABLE admins ADD username VARCHAR(80) NULL AFTER name");
    }

    $admins = $pdo->query("SELECT id, name, username FROM admins ORDER BY id")->fetchAll();
    $update = $pdo->prepare("UPDATE admins SET username = ? WHERE id = ?");
    foreach ($admins as $admin) {
        if (!empty($admin['username'])) {
            continue;
        }
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $admin['name'])) ?: 'user';
        $username = $base;
        $suffix = 1;
        while ((int) $pdo->query("SELECT COUNT(*) FROM admins WHERE username = " . $pdo->quote($username))->fetchColumn() > 0) {
            $username = $base . $suffix++;
        }
        $update->execute([$username, $admin['id']]);
    }

    $indexes = $pdo->prepare(
        "SELECT INDEX_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'admins' AND INDEX_NAME = 'admins_username_unique'"
    );
    $indexes->execute([$databaseName]);
    if (!$indexes->fetchColumn()) {
        $pdo->exec("ALTER TABLE admins MODIFY username VARCHAR(80) NOT NULL, ADD UNIQUE KEY admins_username_unique (username)");
    }
}

function is_main_admin(array $admin): bool
{
    return (int) ($admin['id'] ?? 0) === 1;
}

function ensure_app_settings(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function app_settings(PDO $pdo): array
{
    $rows = $pdo->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function save_app_settings(PDO $pdo, array $settings): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    foreach ($settings as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

function ensure_inventory_details(PDO $pdo, string $databaseName): void
{
    $columns = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'inventory_items'"
    );
    $columns->execute([$databaseName]);
    $existing = array_column($columns->fetchAll(), 'COLUMN_NAME');

    if (!in_array('netflix_slots', $existing, true)) {
        $pdo->exec("ALTER TABLE inventory_items ADD netflix_slots INT NULL AFTER stock");
    }
    if (!in_array('netflix_pin', $existing, true)) {
        $pdo->exec("ALTER TABLE inventory_items ADD netflix_pin VARCHAR(20) NULL AFTER netflix_slots");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS inventory_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS inventory_item_slots (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            inventory_item_id INT UNSIGNED NOT NULL,
            slot_number INT NOT NULL,
            pin_code VARCHAR(40) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY item_slot_unique (inventory_item_id, slot_number),
            CONSTRAINT fk_inventory_item_slots_item
                FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}
