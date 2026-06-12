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

function ensure_admin_profile_images(PDO $pdo, string $databaseName): void
{
    $columns = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'admins'"
    );
    $columns->execute([$databaseName]);
    $existing = array_column($columns->fetchAll(), 'COLUMN_NAME');

    if (!in_array('profile_image', $existing, true)) {
        $pdo->exec("ALTER TABLE admins ADD profile_image VARCHAR(255) NULL AFTER avatar_color");
    }
    if (!in_array('commission_rate', $existing, true)) {
        $pdo->exec("ALTER TABLE admins ADD commission_rate DECIMAL(5,2) NULL AFTER role");
    }
}

function save_profile_image(array $file, int $adminId): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose a profile picture first.');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Profile picture upload failed.');
    }
    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Profile picture must be 2MB or smaller.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Use a JPG, PNG, or WebP profile picture.');
    }

    $uploadDir = __DIR__ . '/assets/uploads';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Could not create the profile picture folder.');
    }

    $filename = 'admin-' . $adminId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) {
        throw new RuntimeException('Could not save the profile picture.');
    }
    return 'assets/uploads/' . $filename;
}

function remove_profile_image(?string $path): void
{
    if (!$path || !str_starts_with($path, 'assets/uploads/')) {
        return;
    }
    $absolute = __DIR__ . '/' . $path;
    if (is_file($absolute)) {
        unlink($absolute);
    }
}

function toast_type(string $message): string
{
    $message = strtolower($message);
    foreach (['invalid', 'incorrect', 'failed', 'not enough', 'required', 'cannot', 'already', 'choose', 'must be', 'error'] as $needle) {
        if (str_contains($message, $needle)) {
            return 'error';
        }
    }
    return 'success';
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
    $pdo->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('commission_rate', '10')");
}

function ensure_sales_details(PDO $pdo, string $databaseName): void
{
    $columns = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'sales_records'"
    );
    $columns->execute([$databaseName]);
    $existing = array_column($columns->fetchAll(), 'COLUMN_NAME');

    if (!in_array('item_id', $existing, true)) {
        $pdo->exec("ALTER TABLE sales_records ADD item_id INT UNSIGNED NULL AFTER id");
    }
    if (!in_array('sold_by_admin_id', $existing, true)) {
        $pdo->exec("ALTER TABLE sales_records ADD sold_by_admin_id INT UNSIGNED NULL AFTER total_amount");
    }
    if (!in_array('sold_by_name', $existing, true)) {
        $pdo->exec("ALTER TABLE sales_records ADD sold_by_name VARCHAR(120) NULL AFTER sold_by_admin_id");
    }
    if (!in_array('commission_units', $existing, true)) {
        $pdo->exec("ALTER TABLE sales_records ADD commission_units INT NOT NULL DEFAULT 0 AFTER sold_by_name");
    }
    if (!in_array('commission_rate', $existing, true)) {
        $pdo->exec("ALTER TABLE sales_records ADD commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER commission_units");
    }
    if (!in_array('commission_amount', $existing, true)) {
        $pdo->exec("ALTER TABLE sales_records ADD commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER commission_rate");
    }
    if (!in_array('cost_amount', $existing, true)) {
        $pdo->exec("ALTER TABLE sales_records ADD cost_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER total_amount");
    }
    if (!in_array('discount_amount', $existing, true)) {
        $pdo->exec("ALTER TABLE sales_records ADD discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER cost_amount");
    }
    if (!in_array('profit_amount', $existing, true)) {
        $pdo->exec("ALTER TABLE sales_records ADD profit_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER discount_amount");
    }
    if (!in_array('account_email', $existing, true)) {
        $pdo->exec("ALTER TABLE sales_records ADD account_email VARCHAR(160) NULL AFTER item_name");
    }
    if (!in_array('notes', $existing, true)) {
        $pdo->exec("ALTER TABLE sales_records ADD notes VARCHAR(255) NULL AFTER commission_amount");
    }
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
    if (!in_array('product_name', $existing, true)) {
        $pdo->exec("ALTER TABLE inventory_items ADD product_name VARCHAR(120) NULL AFTER id");
    }
    if (!in_array('variant_type', $existing, true)) {
        $pdo->exec("ALTER TABLE inventory_items ADD variant_type VARCHAR(80) NULL AFTER product_name");
    }
    if (!in_array('variant_subtype', $existing, true)) {
        $pdo->exec("ALTER TABLE inventory_items ADD variant_subtype VARCHAR(120) NULL AFTER variant_type");
    }
    if (!in_array('account_email', $existing, true)) {
        $pdo->exec("ALTER TABLE inventory_items ADD account_email VARCHAR(160) NULL AFTER netflix_pin");
    }
    if (!in_array('account_password', $existing, true)) {
        $pdo->exec("ALTER TABLE inventory_items ADD account_password VARCHAR(160) NULL AFTER account_email");
    }
    if (!in_array('notes', $existing, true)) {
        $pdo->exec("ALTER TABLE inventory_items ADD notes VARCHAR(255) NULL AFTER account_password");
    }
    if (!in_array('inventory_added', $existing, true)) {
        $pdo->exec("ALTER TABLE inventory_items ADD inventory_added TINYINT(1) NOT NULL DEFAULT 0 AFTER notes");
    }

    $pdo->exec("UPDATE inventory_items SET product_name = COALESCE(NULLIF(product_name, ''), name)");
    $pdo->exec("UPDATE inventory_items SET variant_type = COALESCE(NULLIF(variant_type, ''), NULL), variant_subtype = COALESCE(NULLIF(variant_subtype, ''), NULL)");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS inventory_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $stmt = $pdo->prepare("INSERT IGNORE INTO inventory_categories (name) VALUES (?)");
    foreach (['Entertainment', 'Editing', 'Educational', 'Others'] as $defaultCategory) {
        $stmt->execute([$defaultCategory]);
    }

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

    $slotColumns = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'inventory_item_slots'"
    );
    $slotColumns->execute([$databaseName]);
    $existingSlotColumns = array_column($slotColumns->fetchAll(), 'COLUMN_NAME');

    if (!in_array('label', $existingSlotColumns, true)) {
        $pdo->exec("ALTER TABLE inventory_item_slots ADD label VARCHAR(80) NULL AFTER pin_code");
    }
    if (!in_array('is_sold', $existingSlotColumns, true)) {
        $pdo->exec("ALTER TABLE inventory_item_slots ADD is_sold TINYINT(1) NOT NULL DEFAULT 0 AFTER label");
    }
}

function ensure_salary_releases(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS salary_releases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id INT UNSIGNED NOT NULL,
            week_start DATE NOT NULL,
            released TINYINT(1) NOT NULL DEFAULT 0,
            released_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY admin_week_unique (admin_id, week_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}
