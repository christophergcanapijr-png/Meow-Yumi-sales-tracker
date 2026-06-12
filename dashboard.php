<?php
session_start();
require __DIR__ . '/bootstrap.php';
require_login();

$pdo = db($config);
ensure_inventory_details($pdo, $config['name']);
ensure_admin_usernames($pdo, $config['name']);
ensure_admin_profile_images($pdo, $config['name']);
ensure_app_settings($pdo);
ensure_sales_details($pdo, $config['name']);
ensure_salary_releases($pdo);
$commissionRate = max(0, min(100, (float) (app_settings($pdo)['commission_rate'] ?? 10)));
$staffMonthlyQuota = 15;
$adminStmt = $pdo->prepare("SELECT id, name, username, role, avatar_color, profile_image, commission_rate FROM admins WHERE id = ?");
$adminStmt->execute([$_SESSION['admin_id']]);
$currentAdmin = $adminStmt->fetch();

if (!$currentAdmin) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}
$isMainAdmin = is_main_admin($currentAdmin);
$tab = $_GET['tab'] ?? 'dashboard';
$allowedTabs = $isMainAdmin
    ? ['dashboard', 'products', 'inventory', 'sales', 'salary', 'users', 'settings']
    : ['dashboard', 'products', 'inventory', 'sales', 'settings'];
$tab = in_array($tab, $allowedTabs, true) ? $tab : 'dashboard';
$flash = '';
$productCategories = ['Entertainment', 'Editing', 'Educational', 'Others'];
$variantTypeOptions = [
    'Solo Account' => ['Solo', 'Famhead'],
    'Shared Account' => ['Invite', 'Shared Profile', 'Solo Profile', 'Solo Profile 1 Device', 'Solo Profile 2 Devices'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['add_item', 'delete_item', 'add_category', 'delete_category', 'add_admin', 'delete_admin', 'save_workspace', 'save_admin_commissions', 'update_inventory_stock'], true) && !$isMainAdmin) {
        $flash = 'Only Main Admin can manage inventory, users, and global settings.';
        $action = '';
    }

    if ($action === 'add_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $productName = trim($_POST['product_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $variantType = trim($_POST['variant_type'] ?? '');
        $variantSubtype = trim($_POST['variant_subtype'] ?? '');
        $variantLabel = trim($variantType !== '' ? $variantType : '');
        if ($variantSubtype !== '') {
            $variantLabel .= $variantLabel !== '' ? ' > ' . $variantSubtype : $variantSubtype;
        }
        $stock = max(0, (int) ($_POST['stock'] ?? 0));
        $costPrice = (float) ($_POST['cost_price'] ?? 0);
        $sellPrice = (float) ($_POST['sell_price'] ?? 0);
        $slotCount = max(0, (int) ($_POST['slot_count'] ?? 0));
        $accountEmail = trim($_POST['account_email'] ?? '');
        $accountPassword = trim($_POST['account_password'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if ($productName === '') {
            $flash = 'Product name is required.';
            $tab = 'inventory';
        } elseif (!in_array($category, $productCategories, true)) {
            $flash = 'Choose an existing category first.';
            $tab = 'inventory';
        } else {
            $displayName = $variantLabel !== '' ? $variantLabel : $productName;
            $slotValues = $_POST['slot_pins'] ?? [];
            $slotNames = $_POST['slot_names'] ?? [];
            $recordData = [
                $productName,
                $variantType !== '' ? $variantType : null,
                $variantSubtype !== '' ? $variantSubtype : null,
                $displayName,
                $category,
                $stock,
                $slotCount,
                null,
                $sellPrice,
                $costPrice,
                $accountEmail !== '' ? $accountEmail : null,
                $accountPassword !== '' ? $accountPassword : null,
                $notes !== '' ? $notes : null,
            ];
            if ($itemId > 0) {
                $existingStmt = $pdo->prepare("SELECT id, product_name, name, category FROM inventory_items WHERE id = ?");
                $existingStmt->execute([$itemId]);
                $existingItem = $existingStmt->fetch();
                if (!$existingItem) {
                    $flash = 'Variant not found.';
                    $tab = 'inventory';
                } else {
                    $pdo->prepare(
                        "UPDATE inventory_items
                         SET product_name = ?, variant_type = ?, variant_subtype = ?, name = ?, category = ?, stock = ?, netflix_slots = ?, netflix_pin = ?, sell_price = ?, cost_price = ?, account_email = ?, account_password = ?, notes = ?
                         WHERE id = ?"
                    )->execute([...$recordData, $itemId]);
                    $pdo->prepare("DELETE FROM inventory_item_slots WHERE inventory_item_id = ?")->execute([$itemId]);
                    if ($slotCount > 0) {
                        $slotStmt = $pdo->prepare("INSERT INTO inventory_item_slots (inventory_item_id, slot_number, pin_code, label) VALUES (?, ?, ?, ?)");
                        for ($slotNumber = 1; $slotNumber <= $slotCount; $slotNumber++) {
                            $pin = trim($slotValues[$slotNumber - 1] ?? '');
                            $label = trim($slotNames[$slotNumber - 1] ?? '');
                            if ($pin !== '') {
                                $slotStmt->execute([$itemId, $slotNumber, $pin, $label !== '' ? $label : null]);
                            }
                        }
                    }
                    $flash = 'Variant updated.';
                    $tab = 'inventory';
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO inventory_items (product_name, variant_type, variant_subtype, name, category, stock, netflix_slots, netflix_pin, sell_price, cost_price, account_email, account_password, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute($recordData);
                $itemId = (int) $pdo->lastInsertId();
                if ($slotCount > 0) {
                    $slotStmt = $pdo->prepare("INSERT INTO inventory_item_slots (inventory_item_id, slot_number, pin_code, label) VALUES (?, ?, ?, ?)");
                    for ($slotNumber = 1; $slotNumber <= $slotCount; $slotNumber++) {
                        $pin = trim($slotValues[$slotNumber - 1] ?? '');
                        $label = trim($slotNames[$slotNumber - 1] ?? '');
                        if ($pin !== '') {
                            $slotStmt->execute([$itemId, $slotNumber, $pin, $label !== '' ? $label : null]);
                        }
                    }
                }
                $flash = 'Variant added.';
                $tab = 'inventory';
            }
        }
    } elseif ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $pdo->prepare("INSERT IGNORE INTO inventory_categories (name) VALUES (?)")->execute([$name]);
            $flash = 'Category added.';
        } else {
            $flash = 'Category name is required.';
        }
        $tab = 'inventory';
    } elseif ($action === 'delete_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $pdo->beginTransaction();
            $itemStmt = $pdo->prepare("SELECT id, name FROM inventory_items WHERE category = ?");
            $itemStmt->execute([$name]);
            $itemsInCategory = $itemStmt->fetchAll();
            $ids = array_map(static fn($row) => (int) $row['id'], $itemsInCategory);

            if ($ids) {
                $itemPlaceholders = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("DELETE FROM inventory_item_slots WHERE inventory_item_id IN ($itemPlaceholders)")->execute($ids);
                $pdo->prepare("DELETE FROM inventory_items WHERE id IN ($itemPlaceholders)")->execute($ids);
            }
            $pdo->prepare("DELETE FROM inventory_categories WHERE name = ?")->execute([$name]);
            $pdo->commit();
            $flash = 'Category deleted.';
        } else {
            $flash = 'Category name is required.';
        }
        $tab = 'inventory';
    } elseif ($action === 'delete_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $pdo->prepare("DELETE FROM inventory_items WHERE id = ?")->execute([$itemId]);
            $flash = 'Variant deleted.';
        } else {
            $flash = 'Variant not found.';
        }
        $tab = 'inventory';
    } elseif ($action === 'add_sale') {
        $itemId = (int) $_POST['item_id'];
        $quantity = max(1, (int) $_POST['quantity']);
        $unitCost = max(0, (float) ($_POST['unit_cost'] ?? 0));
        $unitPrice = max(0, (float) ($_POST['unit_price'] ?? 0));
        $discount = max(0, (float) ($_POST['discount'] ?? 0));
        $notes = trim($_POST['notes'] ?? '');
        $slotId = (int) ($_POST['slot_id'] ?? 0);
        $saleDate = $_POST['sale_date'] ?? '';
        $soldAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) ? $saleDate . ' ' . date('H:i:s') : date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("SELECT id, name, stock, account_email FROM inventory_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if ($item && (int) $item['stock'] >= $quantity) {
            $totalAmount = $unitPrice * $quantity - $discount;
            $costAmount = $unitCost * $quantity;
            $profitAmount = $totalAmount - $costAmount;
            $commissionUnits = 0;
            $commissionAmount = 0;
            $adminCommissionRate = $currentAdmin['commission_rate'] !== null ? (float) $currentAdmin['commission_rate'] : $commissionRate;
            if (!$isMainAdmin) {
                $quotaStmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(quantity), 0) FROM sales_records
                     WHERE sold_by_admin_id = ? AND YEAR(sold_at) = YEAR(CURDATE()) AND MONTH(sold_at) = MONTH(CURDATE())"
                );
                $quotaStmt->execute([$currentAdmin['id']]);
                $previousSales = (int) $quotaStmt->fetchColumn();
                $commissionUnits = max(0, $previousSales + $quantity - $staffMonthlyQuota) - max(0, $previousSales - $staffMonthlyQuota);
                if ($commissionUnits > 0) {
                    $commissionAmount = $profitAmount * ($adminCommissionRate / 100);
                }
            }
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO sales_records
                    (item_id, item_name, account_email, quantity, total_amount, cost_amount, discount_amount, profit_amount, sold_by_admin_id, sold_by_name, commission_units, commission_rate, commission_amount, notes, sold_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $item['id'],
                $item['name'],
                $item['account_email'] ?: null,
                $quantity,
                $totalAmount,
                $costAmount,
                $discount,
                $profitAmount,
                $currentAdmin['id'],
                $currentAdmin['name'],
                $commissionUnits,
                $isMainAdmin ? 0 : $adminCommissionRate,
                $commissionAmount,
                $notes !== '' ? $notes : null,
                $soldAt,
            ]);
            $pdo->prepare("UPDATE inventory_items SET stock = stock - ? WHERE id = ?")->execute([$quantity, $itemId]);
            if ($slotId > 0) {
                $pdo->prepare("UPDATE inventory_item_slots SET is_sold = 1 WHERE id = ? AND inventory_item_id = ?")->execute([$slotId, $itemId]);
            }
            $pdo->commit();
            $flash = $commissionUnits > 0
                ? 'Sale recorded. Commission earned: PHP ' . number_format($commissionAmount, 2) . '.'
                : 'Sale recorded.';
        } else {
            $flash = 'Not enough stock or item not found.';
        }
        $tab = 'sales';
    } elseif ($action === 'update_inventory_stock') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $accountEmail = trim($_POST['account_email'] ?? '');
        $accountPassword = trim($_POST['account_password'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $slots = max(0, (int) ($_POST['slots'] ?? 0));
        $pins = $_POST['slot_pins'] ?? [];
        $slotNames = $_POST['slot_names'] ?? [];
        $stmt = $pdo->prepare("SELECT id, stock, netflix_slots FROM inventory_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $existing = $stmt->fetch();
        if (!$itemId || !$existing) {
            $flash = 'Select a product to add inventory for.';
        } else {
            $pdo->beginTransaction();
            if ($slots > 0) {
                $pdo->prepare("UPDATE inventory_items SET account_email = ?, account_password = ?, notes = ?, netflix_slots = ?, stock = ?, inventory_added = 1 WHERE id = ?")
                    ->execute([$accountEmail !== '' ? $accountEmail : null, $accountPassword !== '' ? $accountPassword : null, $notes !== '' ? $notes : null, $slots, $slots, $itemId]);
            } else {
                $pdo->prepare("UPDATE inventory_items SET account_email = ?, account_password = ?, notes = ?, inventory_added = 1 WHERE id = ?")
                    ->execute([$accountEmail !== '' ? $accountEmail : null, $accountPassword !== '' ? $accountPassword : null, $notes !== '' ? $notes : null, $itemId]);
            }
            $existingSlotsStmt = $pdo->prepare("SELECT slot_number, is_sold FROM inventory_item_slots WHERE inventory_item_id = ?");
            $existingSlotsStmt->execute([$itemId]);
            $existingSold = [];
            foreach ($existingSlotsStmt->fetchAll() as $row) {
                $existingSold[(int) $row['slot_number']] = (int) $row['is_sold'];
            }
            $pdo->prepare("DELETE FROM inventory_item_slots WHERE inventory_item_id = ?")->execute([$itemId]);
            $pinStmt = $pdo->prepare("INSERT INTO inventory_item_slots (inventory_item_id, slot_number, pin_code, label, is_sold) VALUES (?, ?, ?, ?, ?)");
            foreach ($pins as $index => $pin) {
                $pin = trim((string) $pin);
                $label = trim((string) ($slotNames[$index] ?? ''));
                if ($pin !== '') {
                    $slotNumber = (int) $index + 1;
                    $pinStmt->execute([$itemId, $slotNumber, $pin, $label !== '' ? $label : null, $existingSold[$slotNumber] ?? 0]);
                }
            }
            $pdo->commit();
            $flash = 'Inventory updated.';
        }
        $tab = 'inventory';
    } elseif ($action === 'add_admin') {
        $name = trim($_POST['name']);
        $username = strtolower(trim($_POST['username']));
        $role = trim($_POST['role']);
        $password = $_POST['password'] ?? '';
        if ($name === '' || !preg_match('/^[a-z0-9._-]+$/', $username) || $password === '') {
            $flash = 'Enter a valid name, username, and password.';
        } else {
            $duplicateStmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE username = ?");
            $duplicateStmt->execute([$username]);
            if ((int) $duplicateStmt->fetchColumn() > 0) {
                $flash = 'That username is already in use.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO admins (name, username, role, password_hash, avatar_color) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $username, $role, password_hash($password, PASSWORD_DEFAULT), avatar_color($name)]);
                $adminId = (int) $pdo->lastInsertId();
                try {
                    if (!empty($_FILES['profile_image']['name'])) {
                        $profileImage = save_profile_image($_FILES['profile_image'], $adminId);
                        $pdo->prepare("UPDATE admins SET profile_image = ? WHERE id = ?")->execute([$profileImage, $adminId]);
                    }
                    $flash = 'User added.';
                } catch (RuntimeException $e) {
                    $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$adminId]);
                    $flash = $e->getMessage();
                }
                $tab = 'users';
            }
        }
    } elseif ($action === 'delete_admin') {
        $adminId = (int) ($_POST['admin_id'] ?? 0);
        if ($adminId <= 1 || $adminId === (int) $currentAdmin['id']) {
            $flash = 'The Main Admin account cannot be deleted.';
        } else {
            $deleteStmt = $pdo->prepare("SELECT profile_image FROM admins WHERE id = ?");
            $deleteStmt->execute([$adminId]);
            $profileImage = $deleteStmt->fetchColumn();
            $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$adminId]);
            remove_profile_image($profileImage ?: null);
            $flash = 'Admin deleted.';
        }
        $tab = 'users';
    } elseif ($action === 'save_workspace') {
        save_app_settings($pdo, [
            'company_name' => trim($_POST['company_name'] ?? 'Maeyumi Prems') ?: 'Maeyumi Prems',
            'tagline' => trim($_POST['tagline'] ?? ''),
            'website' => trim($_POST['website'] ?? ''),
            'currency' => in_array($_POST['currency'] ?? '', ['PHP', 'USD'], true) ? $_POST['currency'] : 'PHP',
            'timezone' => in_array($_POST['timezone'] ?? '', ['Asia/Manila', 'UTC'], true) ? $_POST['timezone'] : 'Asia/Manila',
            'commission_rate' => (string) max(0, min(100, (float) ($_POST['commission_rate'] ?? 10))),
        ]);
        $flash = 'Workspace settings saved.';
        $tab = 'settings';
    } elseif ($action === 'save_profile') {
        $name = trim($_POST['name'] ?? '');
        $username = strtolower(trim($_POST['username'] ?? ''));
        if ($name === '' || !preg_match('/^[a-z0-9._-]+$/', $username)) {
            $flash = 'Enter a valid name and username.';
        } else {
            $duplicateStmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE username = ? AND id <> ?");
            $duplicateStmt->execute([$username, $currentAdmin['id']]);
            if ((int) $duplicateStmt->fetchColumn() > 0) {
                $flash = 'That username is already in use.';
            } else {
                $profileImage = $currentAdmin['profile_image'] ?? null;
                try {
                    if (!empty($_FILES['profile_image']['name'])) {
                        $newProfileImage = save_profile_image($_FILES['profile_image'], (int) $currentAdmin['id']);
                        remove_profile_image($profileImage);
                        $profileImage = $newProfileImage;
                    }
                    $pdo->prepare("UPDATE admins SET name = ?, username = ?, profile_image = ? WHERE id = ?")
                        ->execute([$name, $username, $profileImage, $currentAdmin['id']]);
                    $_SESSION['admin_name'] = $name;
                    $flash = 'Profile updated.';
                } catch (RuntimeException $e) {
                    $flash = $e->getMessage();
                }
            }
        }
        $tab = 'settings';
    } elseif ($action === 'change_password') {
        $passwordStmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = ?");
        $passwordStmt->execute([$currentAdmin['id']]);
        $passwordHash = (string) $passwordStmt->fetchColumn();
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        if (!password_verify($currentPassword, $passwordHash)) {
            $flash = 'Current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $flash = 'New password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $flash = 'New passwords do not match.';
        } else {
            $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $currentAdmin['id']]);
            $flash = 'Password changed successfully.';
        }
        $tab = 'settings';
    } elseif ($action === 'save_admin_commissions') {
        $rates = $_POST['commission_rates'] ?? [];
        $updateStmt = $pdo->prepare("UPDATE admins SET commission_rate = ? WHERE id = ?");
        foreach ($rates as $adminId => $rate) {
            $adminId = (int) $adminId;
            if ($adminId <= 1) {
                continue;
            }
            $rate = trim((string) $rate);
            $value = $rate === '' ? null : max(0, min(100, (float) $rate));
            $updateStmt->execute([$value, $adminId]);
        }
        $flash = 'Admin commission rates saved.';
        $tab = 'settings';
    } elseif ($action === 'save_customization') {
        $themeMode = in_array($_POST['theme_mode'] ?? '', ['light', 'soft'], true) ? $_POST['theme_mode'] : 'light';
        $accentColor = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['accent_color'] ?? '')
            ? strtolower($_POST['accent_color'])
            : '#ef4f82';
        save_app_settings($pdo, ['theme_mode' => $themeMode, 'accent_color' => $accentColor]);
        $flash = 'Customization saved.';
        $tab = 'settings';
    } elseif ($action === 'toggle_salary_release') {
        if ((int) ($_SESSION['admin_id'] ?? 0) === 1) {
            $salaryAdminId = (int) ($_POST['admin_id'] ?? 0);
            $weekStart = $_POST['week_start'] ?? '';
            if ($salaryAdminId > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
                $existing = $pdo->prepare("SELECT released FROM salary_releases WHERE admin_id = ? AND week_start = ?");
                $existing->execute([$salaryAdminId, $weekStart]);
                $current = $existing->fetchColumn();
                $newState = $current === false ? 1 : ((int) $current === 1 ? 0 : 1);
                $stmt = $pdo->prepare(
                    "INSERT INTO salary_releases (admin_id, week_start, released, released_at) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE released = VALUES(released), released_at = VALUES(released_at)"
                );
                $stmt->execute([$salaryAdminId, $weekStart, $newState, $newState ? date('Y-m-d H:i:s') : null]);
                $flash = $newState ? 'Salary marked as released.' : 'Salary marked as unreleased.';
            }
        }
        $tab = 'salary';
    }
}

$adminStmt->execute([$_SESSION['admin_id']]);
$currentAdmin = $adminStmt->fetch();
$isMainAdmin = is_main_admin($currentAdmin);
$appSettings = array_merge([
    'company_name' => 'Maeyumi Prems',
    'tagline' => 'Sales. Salary. Inventory.',
    'website' => '',
    'currency' => 'PHP',
    'timezone' => 'Asia/Manila',
    'commission_rate' => '10',
    'theme_mode' => 'light',
    'accent_color' => '#ef4f82',
], app_settings($pdo));

$admins = $pdo->query("SELECT id, name, username, role, avatar_color, profile_image, commission_rate, created_at FROM admins ORDER BY id DESC")->fetchAll();
$items = $pdo->query("SELECT * FROM inventory_items ORDER BY created_at DESC, id DESC")->fetchAll();
$categories = $pdo->query("SELECT name FROM inventory_categories ORDER BY FIELD(name, 'Entertainment', 'Editing', 'Educational', 'Others'), created_at ASC, name ASC")->fetchAll();
$slotRows = $pdo->query("SELECT id, inventory_item_id, slot_number, pin_code, label, is_sold FROM inventory_item_slots ORDER BY slot_number ASC")->fetchAll();
$itemSlots = [];
foreach ($slotRows as $slotRow) {
    $itemSlots[(int) $slotRow['inventory_item_id']][] = $slotRow;
}
$inventoryGroups = [];
$productOrder = [];
foreach ($items as $item) {
    $productName = trim((string) ($item['product_name'] ?? '')) ?: (string) $item['name'];
    if (!isset($inventoryGroups[$productName])) {
        $productOrder[] = $productName;
    }
    $inventoryGroups[$productName][] = $item;
}
$inventoryOrder = $productCategories;
$sales = $pdo->query("SELECT * FROM sales_records ORDER BY sold_at DESC LIMIT 100")->fetchAll();
$staffProgressStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(quantity), 0) AS sales_count, COALESCE(SUM(commission_amount), 0) AS commission_amount
     FROM sales_records
     WHERE sold_by_admin_id = ? AND YEAR(sold_at) = YEAR(CURDATE()) AND MONTH(sold_at) = MONTH(CURDATE())"
);
$staffProgressStmt->execute([$currentAdmin['id']]);
$staffProgress = $staffProgressStmt->fetch() ?: ['sales_count' => 0, 'commission_amount' => 0];
$staffProgress['sales_count'] = (int) $staffProgress['sales_count'];
$staffProgress['commission_amount'] = (float) $staffProgress['commission_amount'];
$staffProgress['remaining'] = max(0, $staffMonthlyQuota - $staffProgress['sales_count']);
$staffPerformance = [];
if ($isMainAdmin) {
    $staffPerformance = $pdo->query(
        "SELECT a.id, a.name, a.username, a.avatar_color, a.profile_image,
                COALESCE(SUM(s.quantity), 0) AS sales_count,
                COALESCE(SUM(s.commission_units), 0) AS commission_units,
                COALESCE(SUM(s.commission_amount), 0) AS commission_amount
         FROM admins a
         LEFT JOIN sales_records s ON s.sold_by_admin_id = a.id
            AND YEAR(s.sold_at) = YEAR(CURDATE()) AND MONTH(s.sold_at) = MONTH(CURDATE())
         WHERE a.id <> 1
         GROUP BY a.id, a.name, a.username, a.avatar_color, a.profile_image
         ORDER BY sales_count DESC, a.name ASC"
    )->fetchAll();
}
$salaryWeekStart = new DateTimeImmutable('today');
if (!empty($_GET['week']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['week'])) {
    $salaryWeekStart = new DateTimeImmutable($_GET['week']);
}
$salaryWeekStart = $salaryWeekStart->modify('monday this week');
$salaryWeekEnd = $salaryWeekStart->modify('+6 days');
$salaryWeekStartStr = $salaryWeekStart->format('Y-m-d');
$salaryWeekEndStr = $salaryWeekEnd->format('Y-m-d');
$salaryPrevWeek = $salaryWeekStart->modify('-7 days')->format('Y-m-d');
$salaryNextWeek = $salaryWeekStart->modify('+7 days')->format('Y-m-d');

$salaryStaffStmt = $pdo->prepare(
    "SELECT a.id, a.name, a.username, a.avatar_color, a.profile_image,
            COALESCE(SUM(s.commission_amount), 0) AS commission_amount
     FROM admins a
     LEFT JOIN sales_records s ON s.sold_by_admin_id = a.id
        AND DATE(s.sold_at) BETWEEN ? AND ?
     WHERE a.id <> 1
     GROUP BY a.id, a.name, a.username, a.avatar_color, a.profile_image
     ORDER BY a.name ASC"
);
$salaryStaffStmt->execute([$salaryWeekStartStr, $salaryWeekEndStr]);
$salaryStaff = $salaryStaffStmt->fetchAll();

$salaryReleaseStmt = $pdo->prepare("SELECT admin_id, released FROM salary_releases WHERE week_start = ?");
$salaryReleaseStmt->execute([$salaryWeekStartStr]);
$salaryReleaseMap = [];
foreach ($salaryReleaseStmt->fetchAll() as $row) {
    $salaryReleaseMap[(int) $row['admin_id']] = (int) $row['released'];
}

$salaryUnreleasedTotal = 0.0;
$salaryReleasedTotal = 0.0;
foreach ($salaryStaff as &$salaryRow) {
    $salaryRow['commission_amount'] = (float) $salaryRow['commission_amount'];
    $salaryRow['released'] = $salaryReleaseMap[(int) $salaryRow['id']] ?? 0;
    if ($salaryRow['released']) {
        $salaryReleasedTotal += $salaryRow['commission_amount'];
    } else {
        $salaryUnreleasedTotal += $salaryRow['commission_amount'];
    }
}
unset($salaryRow);

$salaryProfitStmt = $pdo->prepare("SELECT COALESCE(SUM(profit_amount), 0) FROM sales_records WHERE DATE(sold_at) BETWEEN ? AND ?");
$salaryProfitStmt->execute([$salaryWeekStartStr, $salaryWeekEndStr]);
$salaryWeekProfit = (float) $salaryProfitStmt->fetchColumn();

$todaySales = (float) $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales_records WHERE DATE(sold_at)=CURDATE()")->fetchColumn();
$totalSales = (float) $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales_records")->fetchColumn();
$payroll = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM salary_records")->fetchColumn();
$totalStock = array_sum(array_column($items, 'stock'));
$totalProfit = array_sum(array_map(fn($i) => ((float)$i['sell_price'] - (float)$i['cost_price']) * (int)$i['stock'], $items));

$range = $_GET['range'] ?? 'daily';
$allowedRanges = ['daily', 'weekly', 'monthly', 'all'];
$range = in_array($range, $allowedRanges, true) ? $range : 'daily';
$selectedDate = new DateTimeImmutable($_GET['date'] ?? 'today');

$rangeConfig = [
    'daily' => ['label' => 'Daily', 'interval' => 'P1D', 'window' => 7, 'unit' => 'day'],
    'weekly' => ['label' => 'Weekly', 'interval' => 'P1W', 'window' => 8, 'unit' => 'week'],
    'monthly' => ['label' => 'Monthly', 'interval' => 'P1M', 'window' => 12, 'unit' => 'month'],
    'all' => ['label' => 'All Time', 'interval' => null, 'window' => 12, 'unit' => 'month'],
];
$currentRange = $rangeConfig[$range];

$salesByDay = [];
$salesByMonth = [];
$costByDay = [];
$costByMonth = [];
$salesCountByDay = [];
$salesCountByMonth = [];
$inventoryByName = [];
foreach ($items as $item) {
    $inventoryByName[$item['name']] = $item;
}

foreach ($sales as $sale) {
    $soldAt = new DateTimeImmutable($sale['sold_at']);
    $dayKey = $soldAt->format('Y-m-d');
    $monthKey = $soldAt->format('Y-m');
    $costPrice = (float) ($inventoryByName[$sale['item_name']]['cost_price'] ?? 0);
    $saleCost = $costPrice * (int) $sale['quantity'];
    $salesByDay[$dayKey] = ($salesByDay[$dayKey] ?? 0) + (float) $sale['total_amount'];
    $salesByMonth[$monthKey] = ($salesByMonth[$monthKey] ?? 0) + (float) $sale['total_amount'];
    $costByDay[$dayKey] = ($costByDay[$dayKey] ?? 0) + $saleCost;
    $costByMonth[$monthKey] = ($costByMonth[$monthKey] ?? 0) + $saleCost;
    $salesCountByDay[$dayKey] = ($salesCountByDay[$dayKey] ?? 0) + (int) $sale['quantity'];
    $salesCountByMonth[$monthKey] = ($salesCountByMonth[$monthKey] ?? 0) + (int) $sale['quantity'];
}

$rangeStart = match ($range) {
    'daily' => $selectedDate->setTime(0, 0, 0),
    'weekly' => $selectedDate->modify('monday this week')->setTime(0, 0, 0),
    'monthly' => $selectedDate->modify('first day of this month')->setTime(0, 0, 0),
    default => !empty($sales) ? new DateTimeImmutable(min(array_map(static fn($row) => $row['sold_at'], $sales))) : $selectedDate->setTime(0, 0, 0),
};
$rangeEnd = match ($range) {
    'daily' => $selectedDate->setTime(23, 59, 59),
    'weekly' => $selectedDate->modify('sunday this week')->setTime(23, 59, 59),
    'monthly' => $selectedDate->modify('last day of this month')->setTime(23, 59, 59),
    default => new DateTimeImmutable('now'),
};

$periodSales = array_values(array_filter($sales, static function (array $sale) use ($rangeStart, $rangeEnd): bool {
    $soldAt = new DateTimeImmutable($sale['sold_at']);
    return $soldAt >= $rangeStart && $soldAt <= $rangeEnd;
}));
$periodRevenue = array_sum(array_map(static fn($sale) => (float) $sale['total_amount'], $periodSales));
$periodCost = array_sum(array_map(static function (array $sale) use ($inventoryByName): float {
    $costPrice = (float) ($inventoryByName[$sale['item_name']]['cost_price'] ?? 0);
    return $costPrice * (int) $sale['quantity'];
}, $periodSales));
$periodProfit = $periodRevenue - $periodCost;
$periodSalesCount = array_sum(array_map(static fn($sale) => (int) $sale['quantity'], $periodSales));

function build_period_series(string $range, DateTimeImmutable $selectedDate, array $salesByDay, array $salesByMonth, array $costByDay, array $costByMonth, array $salesCountByDay, array $salesCountByMonth, int $window): array
{
    $series = [
        'labels' => [],
        'sales' => [],
        'costs' => [],
        'profit' => [],
    ];

    if ($range === 'all') {
        $monthlyKeys = array_values(array_unique(array_merge(array_keys($salesByMonth), array_keys($costByMonth))));
        sort($monthlyKeys);
        $monthlyKeys = array_slice($monthlyKeys, -$window);
        foreach ($monthlyKeys as $key) {
            $labelDate = DateTimeImmutable::createFromFormat('Y-m', $key) ?: new DateTimeImmutable($key . '-01');
            $sales = (float) ($salesByMonth[$key] ?? 0);
            $costs = (float) ($costByMonth[$key] ?? 0);
            $series['labels'][] = $labelDate->format('M Y');
            $series['sales'][] = $sales;
            $series['costs'][] = $costs;
            $series['profit'][] = $sales - $costs;
        }
        return $series;
    }

    $start = match ($range) {
        'daily' => $selectedDate->modify('-' . ($window - 1) . ' days'),
        'weekly' => $selectedDate->modify('-' . ($window - 1) . ' weeks'),
        'monthly' => $selectedDate->modify('-' . ($window - 1) . ' months'),
        default => $selectedDate,
    };
    for ($i = 0; $i < $window; $i++) {
        $point = match ($range) {
            'daily' => $start->modify("+{$i} days"),
            'weekly' => $start->modify("+{$i} weeks"),
            default => $start->modify("+{$i} months"),
        };
        $key = $range === 'daily' ? $point->format('Y-m-d') : $point->format('Y-m');
        $label = $range === 'daily'
            ? $point->format('D')
            : $point->format('M Y');
        $sales = $range === 'daily'
            ? (float) ($salesByDay[$key] ?? 0)
            : (float) ($salesByMonth[$key] ?? 0);
        $costs = $range === 'daily'
            ? (float) ($costByDay[$key] ?? 0)
            : (float) ($costByMonth[$key] ?? 0);
        $series['labels'][] = $label;
        $series['sales'][] = $sales;
        $series['costs'][] = $costs;
        $series['profit'][] = $sales - $costs;
    }

    return $series;
}

function chart_svg(array $labels, array $values, string $lineColor, string $fillColor, string $title): string
{
    $count = max(count($values), 1);
    $width = 760;
    $height = 240;
    $padX = 34;
    $padY = 24;
    $plotWidth = $width - ($padX * 2);
    $plotHeight = $height - ($padY * 2) - 30;
    $max = max(1, max($values ?: [0]));
    $stepX = $count > 1 ? $plotWidth / ($count - 1) : $plotWidth;
    $points = [];
    foreach ($values ?: [0] as $index => $value) {
        $x = $padX + ($stepX * $index);
        $y = $padY + $plotHeight - (($value / $max) * $plotHeight);
        $points[] = [$x, $y];
    }
    $linePath = '';
    foreach ($points as $index => [$x, $y]) {
        $linePath .= ($index === 0 ? 'M' : 'L') . $x . ' ' . $y . ' ';
    }
    $firstPoint = $points[0] ?? [$padX, $padY + $plotHeight];
    $lastPoint = end($points) ?: [$padX, $padY + $plotHeight];
    $areaPath = trim($linePath) . ' L ' . $lastPoint[0] . ' ' . ($padY + $plotHeight) . ' L ' . $firstPoint[0] . ' ' . ($padY + $plotHeight) . ' Z';
    ob_start();
    ?>
    <div class="chart-head"><strong><?= e($title) ?></strong><span><?= e(number_format(array_sum($values), 2)) ?></span></div>
    <svg viewBox="0 0 <?= $width ?> <?= $height ?>" class="chart-svg" role="img" aria-label="<?= e($title) ?>" style="width:100%;height:240px;display:block;overflow:visible">
        <?php for ($i = 0; $i < 4; $i++): $y = $padY + ($plotHeight / 3) * $i; ?>
            <line x1="<?= $padX ?>" y1="<?= $y ?>" x2="<?= $width - $padX ?>" y2="<?= $y ?>" stroke="rgba(199,181,232,.42)" stroke-dasharray="4 5" stroke-width="1"></line>
        <?php endfor; ?>
        <path d="<?= e($areaPath) ?>" fill="<?= e($fillColor) ?>"></path>
        <path d="<?= e(trim($linePath)) ?>" fill="none" stroke="<?= e($lineColor) ?>" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"></path>
        <?php foreach ($points as $index => [$x, $y]): ?>
            <circle cx="<?= $x ?>" cy="<?= $y ?>" r="5.5" fill="<?= e($lineColor) ?>"></circle>
            <text x="<?= $x ?>" y="<?= $height - 12 ?>" text-anchor="middle" fill="#7b6f94" font-size="12" font-weight="700"><?= e($labels[$index] ?? '') ?></text>
        <?php endforeach; ?>
    </svg>
    <?php
    return trim(ob_get_clean());
}

$series = build_period_series($range, $selectedDate, $salesByDay, $salesByMonth, $costByDay, $costByMonth, $salesCountByDay, $salesCountByMonth, $currentRange['window']);
$prevRangeDate = $selectedDate->modify('-1 ' . $currentRange['unit']);
$nextRangeDate = $selectedDate->modify('+1 ' . $currentRange['unit']);
$rangeUrl = static function (string $range, DateTimeImmutable $date): string {
    return '?tab=dashboard&range=' . urlencode($range) . '&date=' . urlencode($date->format('Y-m-d'));
};

$navIcons = [
    'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 11.5 12 5l8 6.5"></path><path d="M6 10.5V20h12v-9.5"></path></svg>',
    'products' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 8h14l-1.2 11.2a1.5 1.5 0 0 1-1.5 1.3H7.7a1.5 1.5 0 0 1-1.5-1.3L5 8z"></path><path d="M9 8V6a3 3 0 0 1 6 0v2"></path></svg>',
    'inventory' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8.5 12 4l8 4.5"></path><path d="M4 8.5 12 13l8-4.5"></path><path d="M4 8.5V18l8 4 8-4V8.5"></path></svg>',
    'sales' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 18h16"></path><path d="M7 15v-4"></path><path d="M12 15V7"></path><path d="M17 15v-6"></path></svg>',
    'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.2"></circle><path d="M3.5 20c0-3 2.9-5.5 5.5-5.5S14.5 17 14.5 20"></path><circle cx="17.5" cy="9.5" r="2.4"></circle><path d="M14.8 20c.2-2.1 1.7-3.7 3.7-3.7 1.5 0 2.9.8 3.5 2"></path></svg>',
    'salary' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2"></rect><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><circle cx="12" cy="13.5" r="2"></circle></svg>',
    'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3.2"></circle><path d="M19.4 13.4v-2.8l-2 .1a6.9 6.9 0 0 0-.9-1.6l1.3-1.5-2-2-1.6 1.3a6.9 6.9 0 0 0-1.6-.9L12.4 2h-2.8l.1 2a6.9 6.9 0 0 0-1.6.9L6.6 3.6l-2 2 1.3 1.6a6.9 6.9 0 0 0-.9 1.6l-2 .1v2.8l2-.1a6.9 6.9 0 0 0 .9 1.6l-1.3 1.5 2 2 1.6-1.3a6.9 6.9 0 0 0 1.6.9l-.1 2h2.8l-.1-2a6.9 6.9 0 0 0 1.6-.9l1.5 1.3 2-2-1.3-1.6a6.9 6.9 0 0 0 .9-1.6z"></path></svg>',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= ucfirst($tab) ?> | <?= e($appSettings['company_name']) ?></title>
    <link rel="stylesheet" href="assets/style.css?v=app-27">
</head>
<body data-theme="<?= e($appSettings['theme_mode']) ?>" style="--user-accent:<?= e($appSettings['accent_color']) ?>">
<div class="app-shell">
    <aside class="app-sidebar">
        <div class="sidebar-brand">
            <img class="sidebar-cat cat-cutout" src="assets/cat1-removebg-preview.png" alt="">
            <div>
                <strong><?= e($appSettings['company_name']) ?></strong>
                <span><?= e($appSettings['tagline']) ?></span>
            </div>
        </div>
        <nav class="nav-tabs" data-dashboard-nav>
            <?php foreach (['dashboard' => 'Dashboard', 'products' => 'Products', 'inventory' => 'Inventory', 'sales' => 'Sales', 'salary' => 'Salary', 'users' => 'Users', 'settings' => 'Settings'] as $key => $label): ?>
                <?php if (($key === 'users' || $key === 'salary') && !$isMainAdmin) continue; ?>
                <a class="<?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= $key ?>">
                    <span class="nav-icon"><?= $navIcons[$key] ?></span>
                    <span class="nav-label"><?= $label ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-note">
            <img class="cat-cutout" src="assets/cat2-removebg-preview.png" alt="">
            <p>Every purr-sistent step leads to pawsome results.</p>
        </div>
        <div class="nav-user">
            <?php if ($currentAdmin['profile_image']): ?><img class="avatar avatar-image" src="<?= e($currentAdmin['profile_image']) ?>" alt="<?= e($currentAdmin['name']) ?>"><?php else: ?><div class="avatar" style="background:<?= e($currentAdmin['avatar_color']) ?>"><?= strtoupper(substr($currentAdmin['name'], 0, 2)) ?></div><?php endif; ?>
            <div><strong><?= e($currentAdmin['name']) ?></strong><small><?= e($currentAdmin['role']) ?></small></div>
        </div>
        <a class="logout-btn" href="logout.php">Logout</a>
    </aside>

    <main class="app-main" id="dashboard-content">
        <header class="page-head"><div><p class="eyebrow"><?= e($appSettings['company_name']) ?></p><h1><?= ucfirst($tab) ?></h1></div><span><?= date('F j, Y') ?></span></header>
        <?php if ($flash): ?><div class="flash" data-toast-source data-toast-type="<?= e(toast_type($flash)) ?>" hidden><?= e($flash) ?></div><?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
            <section class="dashboard-hero panel" data-analytics-dashboard data-analytics-endpoint="analytics.php">
                <div class="dashboard-head">
                    <div>
                        <p class="eyebrow">Dashboard</p>
                        <h1>Good morning, <?= e($currentAdmin['name']) ?>!</h1>
                        <p class="muted">Here&apos;s how your sales are looking today.</p>
                    </div>
                    <div class="dashboard-tools">
                        <span class="live-pill"><i></i><strong>Live</strong><small data-live-updated>Connecting...</small></span>
                        <div class="profile-pill">
                            <?php if ($currentAdmin['profile_image']): ?><img class="profile-photo" src="<?= e($currentAdmin['profile_image']) ?>" alt="<?= e($currentAdmin['name']) ?>"><?php else: ?><img class="cat-cutout" src="assets/cat3-removebg-preview.png" alt=""><?php endif; ?>
                            <span><?= e($currentAdmin['name']) ?></span>
                        </div>
                        <button class="secondary-btn" type="button" data-refresh-analytics>Refresh</button>
                    </div>
                </div>

                <div class="range-tabs">
                    <?php foreach ($allowedRanges as $rangeKey): ?>
                        <a class="<?= $range === $rangeKey ? 'active' : '' ?>" href="<?= e($rangeUrl($rangeKey, $selectedDate)) ?>"><?= e($rangeConfig[$rangeKey]['label']) ?></a>
                    <?php endforeach; ?>
                </div>

                <div class="date-strip">
                    <?php if ($range !== 'all'): ?>
                        <a class="date-nav" href="<?= e($rangeUrl($range, $prevRangeDate)) ?>">&lsaquo;</a>
                        <div class="date-current">
                            <strong>
                                <?php if ($range === 'daily'): ?>
                                    <?= e($selectedDate->format('D, M j')) ?>
                                <?php elseif ($range === 'weekly'): ?>
                                    <?= e($rangeStart->format('M j')) ?> - <?= e($rangeEnd->format('M j, Y')) ?>
                                <?php else: ?>
                                    <?= e($selectedDate->format('F Y')) ?>
                                <?php endif; ?>
                            </strong>
                        </div>
                        <a class="date-nav" href="<?= e($rangeUrl($range, $nextRangeDate)) ?>">&rsaquo;</a>
                    <?php else: ?>
                        <div class="date-current full">
                            <strong>All time overview</strong>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="stats-grid stats-grid-4">
                <article class="stat-card stat-sales"><img class="stat-illustration cat-cutout" src="assets/cat4-removebg-preview.png" alt=""><span>Total Sales</span><strong data-analytics-metric="revenue">₱<?= number_format($periodRevenue, 2) ?></strong><small>Gross revenue</small></article>
                <article class="stat-card stat-costs"><img class="stat-illustration cat-cutout" src="assets/cat5-removebg-preview.png" alt=""><span>Total Costs</span><strong data-analytics-metric="costs">₱<?= number_format($periodCost, 2) ?></strong><small>Inventory cost</small></article>
                <article class="stat-card stat-profit"><img class="stat-illustration cat-cutout" src="assets/cat7-removebg-preview.png" alt=""><span>Total Profit</span><strong data-analytics-metric="profit">₱<?= number_format($periodProfit, 2) ?></strong><small>Revenue minus cost</small></article>
                <article class="stat-card stat-count"><img class="stat-illustration stat-illustration--flip cat-cutout" src="assets/cat2-removebg-preview.png" alt=""><span>Sales Count</span><strong data-analytics-metric="count"><?= number_format($periodSalesCount) ?></strong><small>Items sold</small></article>
            </section>

            <section class="chart-grid">
                <article class="chart-card">
                    <div class="chart-card-head">
                        <div><strong>Revenue Overview</strong><small>Sales compared with costs</small></div>
                        <div class="chart-legend"><span><i class="legend-sales"></i>Sales</span><span><i class="legend-costs"></i>Costs</span></div>
                    </div>
                    <div class="analytics-chart-wrap">
                        <canvas data-analytics-chart="revenue"></canvas>
                        <div class="chart-tooltip" data-chart-tooltip></div>
                    </div>
                </article>
                <article class="chart-card">
                    <div class="chart-card-head">
                        <div><strong>Profit Trend</strong><small>Live earnings movement</small></div>
                        <div class="chart-legend"><span><i class="legend-profit"></i>Profit</span></div>
                    </div>
                    <div class="analytics-chart-wrap">
                        <canvas data-analytics-chart="profit"></canvas>
                        <div class="chart-tooltip" data-chart-tooltip></div>
                    </div>
                </article>
            </section>

            <section class="quick-actions">
                <a class="quick-action" href="?tab=sales"><strong>Add Sale</strong></a>
                <a class="quick-action" href="?tab=inventory"><strong>Inventory</strong></a>
                <?php if ($isMainAdmin): ?><a class="quick-action" href="?tab=salary"><strong>Salary</strong></a><?php endif; ?>
            </section>

        <?php elseif ($tab === 'products'): ?>
            <section class="panel">
                <div class="catalog-head">
                    <div>
                        <h2>Products</h2>
                        <p class="muted">Manage your product catalog</p>
                    </div>
                    <div class="panel-actions">
                        <button class="secondary-btn" type="button" data-refresh-dashboard>↻</button>
                        <?php if ($isMainAdmin): ?>
                            <button class="primary-btn" type="button" data-open-modal="inventory-modal" data-modal-title="Add Product">+ New Product</button>
                            <button class="secondary-btn" type="button" data-open-modal="category-modal">+ Add Category</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="catalog-filters">
                    <input type="search" placeholder="Search products..." data-catalog-search>
                    <select data-catalog-filter>
                        <option value="all">All categories</option>
                        <?php foreach ($inventoryOrder as $categoryName): ?>
                            <option value="<?= e($categoryName) ?>"><?= e($categoryName) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="catalog-count" data-catalog-count><?= count($items) ?> products</span>
                </div>
                <?php
                    $soldOutProductCount = 0;
                    $activeProductCount = 0;
                    foreach ($productOrder as $productName) {
                        $productItems = $inventoryGroups[$productName] ?? [];
                        if (!$productItems) continue;
                        $hasAvailable = array_reduce($productItems, static fn($carry, $item) => $carry || (int) ($item['inventory_added'] ?? 0) === 0 || (int) $item['stock'] > 0, false);
                        $hasAvailable ? $activeProductCount++ : $soldOutProductCount++;
                    }
                ?>
                <div class="inventory-view-tabs">
                    <button type="button" class="active" data-toggle-sold-products="active">Active <span class="pill"><?= $activeProductCount ?></span></button>
                    <button type="button" data-toggle-sold-products="sold">Sold <span class="pill"><?= $soldOutProductCount ?></span></button>
                </div>
                <div class="product-grid" data-product-grid>
                    <?php if ($productOrder): ?>
                        <?php foreach ($productOrder as $productName): ?>
                            <?php
                                $productItems = $inventoryGroups[$productName] ?? [];
                                if (!$productItems) continue;
                                $hasAvailable = array_reduce($productItems, static fn($carry, $item) => $carry || (int) ($item['inventory_added'] ?? 0) === 0 || (int) $item['stock'] > 0, false);
                                $isSoldOutProduct = !$hasAvailable;
                                $primaryCategory = $productItems[0]['category'] ?? 'Uncategorized';
                                $variantCount = count($productItems);
                                $prices = array_map(static fn($item) => (float) $item['sell_price'], $productItems);
                                $profits = array_map(static fn($item) => (float) $item['sell_price'] - (float) $item['cost_price'], $productItems);
                                $fromPrice = min($prices);
                                $avgPrice = array_sum($prices) / max(1, count($prices));
                                $avgProfit = array_sum($profits) / max(1, count($profits));
                                $firstLetter = strtoupper(substr($productName, 0, 1));
                            ?>
                            <article class="product-card<?= $isSoldOutProduct ? ' product-card-sold' : '' ?>" data-product-card data-sold-out="<?= $isSoldOutProduct ? '1' : '0' ?>" data-product-name="<?= e(strtolower($productName)) ?>" data-product-category="<?= e(strtolower($primaryCategory)) ?>">
                                <div class="product-card-head">
                                    <div class="product-title-block">
                                        <div class="product-avatar"><?= e($firstLetter) ?></div>
                                        <div>
                                            <strong><?= e($productName) ?></strong>
                                            <span class="product-chip"><?= e($primaryCategory) ?></span>
                                        </div>
                                    </div>
                                    <div class="product-from">
                                        <small>from</small>
                                        <strong>PHP <?= number_format($fromPrice, 2) ?></strong>
                                    </div>
                                </div>
                                <div class="product-stats">
                                    <span><strong><?= $variantCount ?></strong><small>Variants</small></span>
                                    <span><strong>PHP <?= number_format($avgPrice, 2) ?></strong><small>Avg Price</small></span>
                                    <span><strong>PHP <?= number_format($avgProfit, 2) ?></strong><small>Avg Profit</small></span>
                                </div>
                                <button class="product-toggle" type="button" data-toggle-group>Show variants</button>
                                <div class="product-body" hidden>
                                    <div class="product-variants">
                                        <?php foreach ($productItems as $item): ?>
                                            <?php
                                                $variantLabel = trim((string) ($item['variant_type'] ?: $item['name']));
                                                if (($item['variant_subtype'] ?? '') !== '') {
                                                    $variantLabel .= ' > ' . $item['variant_subtype'];
                                                }
                                                $slotData = array_map(static fn($slot) => ['slot_number' => (int) $slot['slot_number'], 'pin_code' => (string) $slot['pin_code']], $itemSlots[(int) $item['id']] ?? []);
                                            ?>
                                            <div class="variant-row" data-product-row data-product-name="<?= e(strtolower($productName)) ?>" data-product-category="<?= e(strtolower($primaryCategory)) ?>">
                                                <div class="variant-main">
                                                    <strong><?= e($variantLabel) ?></strong>
                                                    <small><?= e($item['category']) ?></small>
                                                </div>
                                                <span>
                                                    <strong>PHP <?= number_format((float) $item['cost_price'], 2) ?></strong>
                                                    <small>Cost</small>
                                                </span>
                                                <span>
                                                    <strong>PHP <?= number_format((float) $item['sell_price'], 2) ?></strong>
                                                    <small>Price</small>
                                                </span>
                                                <span class="profit-positive">
                                                    <strong>PHP <?= number_format((float) $item['sell_price'] - (float) $item['cost_price'], 2) ?></strong>
                                                    <small>Profit</small>
                                                </span>
                                                <div class="row-actions">
                                                    <button class="row-icon-btn" type="button" data-open-modal="inventory-modal" data-modal-title="Edit Variant"
                                                        data-item-id="<?= (int) $item['id'] ?>"
                                                        data-product-name="<?= e($productName) ?>"
                                                        data-category="<?= e($item['category']) ?>"
                                                        data-variant-type="<?= e((string) ($item['variant_type'] ?? '')) ?>"
                                                        data-variant-subtype="<?= e((string) ($item['variant_subtype'] ?? '')) ?>"
                                                        data-stock="<?= (int) $item['stock'] ?>"
                                                        data-cost-price="<?= e((string) $item['cost_price']) ?>"
                                                        data-sell-price="<?= e((string) $item['sell_price']) ?>"
                                                        data-item-name="<?= e($variantLabel) ?>"
                                                        data-slot-count="<?= count($slotData) ?>"
                                                        data-slot-values='<?= e(json_encode($slotData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
                                                    >Edit</button>
                                                    <form method="post" class="inline-delete" data-confirm-delete-item="<?= e($variantLabel) ?>">
                                                        <input type="hidden" name="action" value="delete_item">
                                                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                        <button class="row-icon-btn danger" type="submit">Del</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($isMainAdmin): ?>
                                            <button class="variant-add-btn" type="button" data-open-modal="inventory-modal" data-modal-title="Add Variant" data-product-name="<?= e($productName) ?>" data-product-category="<?= e($primaryCategory) ?>">+ Add Variant</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">No products yet. Create your first product card with New Product.</div>
                    <?php endif; ?>
                </div>
            </section>

            <div class="modal" id="category-modal" aria-hidden="true">
                <div class="modal-card">
                    <div class="modal-head"><div><p class="eyebrow">Categories</p><h2>Add Category</h2></div><button class="icon-btn" type="button" data-close-modal>&times;</button></div>
                    <form method="post" class="form">
                        <input type="hidden" name="action" value="add_category">
                        <label>Category name<input name="name" placeholder="Entertainment, Editing, Educational, Others" required></label>
                        <button class="primary-btn full" type="submit">Save Category</button>
                    </form>
                </div>
            </div>

            <div class="modal" id="category-delete-modal" aria-hidden="true">
                <div class="modal-card">
                    <div class="modal-head"><div><p class="eyebrow">Delete</p><h2>Delete Category</h2></div><button class="icon-btn" type="button" data-close-modal>&times;</button></div>
                    <p class="muted" data-delete-category-note>Delete this category and all items inside it.</p>
                    <form method="post" class="form">
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="name" data-delete-category-name>
                        <button class="danger-btn full" type="submit">Delete Category</button>
                    </form>
                </div>
            </div>

        <?php elseif ($tab === 'inventory'): ?>
            <?php
                $stockedItems = array_values(array_filter($items, static fn($item) => (int) ($item['inventory_added'] ?? 0) === 1));
                $activeItems = array_filter($stockedItems, static fn($item) => (int) $item['stock'] > 0);
                $soldOutItems = array_filter($stockedItems, static fn($item) => (int) $item['stock'] <= 0);
            ?>
            <section class="panel">
                <div class="catalog-head">
                    <div>
                        <h2>Inventory</h2>
                        <p class="muted">Manage your stock</p>
                    </div>
                    <div class="panel-actions">
                        <button class="secondary-btn" type="button" data-refresh-dashboard>↻</button>
                        <?php if ($isMainAdmin): ?>
                            <button class="primary-btn" type="button" data-open-modal="inventory-stock-modal">+ Add Item</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stats-grid stats-grid-3">
                    <article class="stat-card stat-profit"><strong><?= count($activeItems) ?></strong><span>Active</span></article>
                    <article class="stat-card stat-costs"><strong><?= count($soldOutItems) ?></strong><span>Sold Out</span></article>
                    <article class="stat-card stat-count"><strong><?= count($stockedItems) ?></strong><span>Total</span></article>
                </div>

                <div class="catalog-filters">
                    <input type="search" placeholder="Search..." data-inventory-search>
                    <select data-inventory-filter>
                        <option value="all">All categories</option>
                        <?php foreach ($inventoryOrder as $categoryName): ?>
                            <option value="<?= e($categoryName) ?>"><?= e($categoryName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="inventory-view-tabs">
                    <button type="button" class="active" data-inventory-view-tab="active">Active <span class="pill"><?= count($activeItems) ?></span></button>
                    <button type="button" data-inventory-view-tab="history">History <span class="pill"><?= count($soldOutItems) ?></span></button>
                </div>

                <div class="inventory-list">
                    <?php if ($stockedItems): ?>
                        <?php foreach ($stockedItems as $item): ?>
                            <?php
                                $isActive = (int) $item['stock'] > 0;
                                $slots = (int) ($item['netflix_slots'] ?? 0);
                                $sold = max(0, $slots - (int) $item['stock']);
                                $variantLabel = trim((string) ($item['variant_type'] ?: $item['name']));
                                if (($item['variant_subtype'] ?? '') !== '') {
                                    $variantLabel .= ' > ' . $item['variant_subtype'];
                                }
                                $slotData = array_map(static fn($slot) => ['slot_number' => (int) $slot['slot_number'], 'pin_code' => (string) $slot['pin_code']], $itemSlots[(int) $item['id']] ?? []);
                            ?>
                            <article class="inventory-item-card" data-inventory-row data-inventory-view="<?= $isActive ? 'active' : 'history' ?>" data-inventory-name="<?= e(strtolower((string) ($item['product_name'] ?: $item['name']))) ?>" data-inventory-category="<?= e(strtolower($item['category'])) ?>">
                                <div class="inventory-item-head">
                                    <div>
                                        <span class="pill"><?= e($item['category']) ?></span>
                                        <strong class="inventory-item-title"><?= e($item['product_name'] ?: $item['name']) ?></strong>
                                        <small><?= e($variantLabel) ?></small>
                                    </div>
                                    <?php if ($isMainAdmin): ?>
                                        <div class="row-actions">
                                            <button class="row-icon-btn" type="button" data-open-modal="inventory-modal" data-modal-title="Edit Item"
                                                data-item-id="<?= (int) $item['id'] ?>"
                                                data-product-name="<?= e((string) ($item['product_name'] ?: $item['name'])) ?>"
                                                data-category="<?= e($item['category']) ?>"
                                                data-variant-type="<?= e((string) ($item['variant_type'] ?? '')) ?>"
                                                data-variant-subtype="<?= e((string) ($item['variant_subtype'] ?? '')) ?>"
                                                data-stock="<?= (int) $item['stock'] ?>"
                                                data-cost-price="<?= e((string) $item['cost_price']) ?>"
                                                data-sell-price="<?= e((string) $item['sell_price']) ?>"
                                                data-item-name="<?= e($variantLabel) ?>"
                                                data-slot-count="<?= count($slotData) ?>"
                                                data-slot-values='<?= e(json_encode($slotData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
                                                data-account-email="<?= e((string) ($item['account_email'] ?? '')) ?>"
                                                data-account-password="<?= e((string) ($item['account_password'] ?? '')) ?>"
                                                data-notes="<?= e((string) ($item['notes'] ?? '')) ?>"
                                            >Edit</button>
                                            <form method="post" class="inline-delete" data-confirm-delete-item="<?= e($variantLabel) ?>">
                                                <input type="hidden" name="action" value="delete_item">
                                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                <button class="row-icon-btn danger" type="submit">Del</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="inventory-item-stats">
                                    <span><strong><?= $item['account_email'] ? e($item['account_email']) : '&mdash;' ?></strong><small>Email</small></span>
                                    <span>
                                        <strong>
                                            <?php if ($item['account_password']): ?>
                                                <span class="pin-value pin-mask" data-pin-value="<?= e($item['account_password']) ?>">••••••</span>
                                                <button class="row-icon-btn" type="button" data-toggle-pin>Show</button>
                                            <?php else: ?>&mdash;<?php endif; ?>
                                        </strong>
                                        <small>Password</small>
                                    </span>
                                    <span><strong><?= $slots > 0 ? $slots : '&mdash;' ?></strong><small>Slots</small></span>
                                    <span><strong><?= $sold ?></strong><small>Sold</small></span>
                                    <span><strong class="<?= $isActive ? 'profit-positive' : '' ?>"><?= (int) $item['stock'] ?></strong><small>Remaining</small></span>
                                    <span><strong><?= $item['notes'] ? e($item['notes']) : '&mdash;' ?></strong><small>Notes</small></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">No inventory items yet. Add one with "+ Add Item".</div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($isMainAdmin): ?>
            <?php
            $stockItems = array_values(array_map(function ($item) use ($itemSlots) {
                $slotsData = array_map(static fn($slot) => ['name' => (string) ($slot['label'] ?? ''), 'pin' => (string) $slot['pin_code']], $itemSlots[(int) $item['id']] ?? []);
                $variantLabel = trim((string) ($item['variant_type'] ?: ''));
                if (($item['variant_subtype'] ?? '') !== '') {
                    $variantLabel .= $variantLabel !== '' ? ' > ' . $item['variant_subtype'] : $item['variant_subtype'];
                }
                return [
                    'id' => (int) $item['id'],
                    'category' => (string) $item['category'],
                    'product' => (string) ($item['product_name'] ?: $item['name']),
                    'label' => $variantLabel !== '' ? $variantLabel : (string) ($item['product_name'] ?: $item['name']),
                    'type' => (string) ($item['variant_type'] ?? ''),
                    'email' => (string) ($item['account_email'] ?? ''),
                    'password' => (string) ($item['account_password'] ?? ''),
                    'notes' => (string) ($item['notes'] ?? ''),
                    'slots' => $slotsData,
                ];
            }, array_filter($items, static fn($item) => (int) ($item['inventory_added'] ?? 0) === 0 || (int) $item['stock'] > 0)));
            ?>
            <div class="modal" id="inventory-stock-modal" data-stock-items='<?= e(json_encode($stockItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>' aria-hidden="true">
                <div class="modal-card inventory-modal-card">
                    <div class="modal-head"><div><p class="eyebrow">Inventory</p><h2>Add Inventory</h2></div><button class="icon-btn" type="button" data-close-modal>&times;</button></div>
                    <form method="post" class="form" data-stock-form>
                        <input type="hidden" name="action" value="update_inventory_stock">
                        <input type="hidden" name="item_id" data-stock-item-id value="">
                        <label>Category
                            <select data-stock-category required>
                                <option value="">Select...</option>
                                <?php foreach ($productCategories as $categoryName): ?>
                                    <option value="<?= e($categoryName) ?>"><?= e($categoryName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Product Name
                            <select data-stock-product disabled required>
                                <option value="">Select...</option>
                            </select>
                        </label>
                        <label>Account Email<input type="text" name="account_email" placeholder="account@email.com" data-stock-email></label>
                        <label>Account Password<input type="text" name="account_password" placeholder="Password..." data-stock-password></label>
                        <label data-stock-slots-field>Total Slots<input type="number" min="0" placeholder="e.g. 5" name="slots" data-stock-slots></label>
                        <div class="slot-builder" data-stock-pin-builder></div>
                        <small class="muted">💡 Sold &amp; remaining slots sync automatically with Sales Tracker</small>
                        <label>Notes<input type="text" name="notes" placeholder="Optional..." data-stock-notes></label>
                        <div class="modal-submit-bar"><button class="primary-btn full" type="submit">Add Item</button></div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

        <?php elseif ($tab === 'sales'): ?>
            <?php
            $saleItems = array_values(array_map(function ($item) use ($itemSlots) {
                $availableSlots = array_values(array_map(
                    static fn($slot) => ['id' => (int) $slot['id'], 'name' => (string) ($slot['label'] ?? ''), 'pin' => (string) $slot['pin_code']],
                    array_filter($itemSlots[(int) $item['id']] ?? [], static fn($slot) => (int) $slot['is_sold'] === 0)
                ));
                return [
                    'id' => (int) $item['id'],
                    'category' => (string) $item['category'],
                    'product' => (string) ($item['product_name'] ?: $item['name']),
                    'type' => (string) ($item['variant_type'] ?? ''),
                    'subtype' => (string) ($item['variant_subtype'] ?? ''),
                    'email' => (string) ($item['account_email'] ?? ''),
                    'password' => (string) ($item['account_password'] ?? ''),
                    'cost' => (float) $item['cost_price'],
                    'price' => (float) $item['sell_price'],
                    'stock' => (int) $item['stock'],
                    'slots' => $availableSlots,
                ];
            }, array_filter($items, fn ($item) => (int) $item['stock'] > 0)));
            ?>
            <section class="panel"><div class="panel-head"><h2>Record Sale</h2></div><button class="primary-btn" type="button" data-open-modal="sale-modal">+ Add Sale</button></section>
            <?php if ($isMainAdmin): ?>
                <section class="panel staff-performance-panel">
                    <div class="panel-head"><div><h2>Staff Sales Performance</h2><p class="muted">Monthly quota: <?= $staffMonthlyQuota ?> sales. Commission starts on sale <?= $staffMonthlyQuota + 1 ?> at <?= number_format((float) $appSettings['commission_rate'], 2) ?>%.</p></div></div>
                    <div class="staff-performance-grid">
                        <?php if ($staffPerformance): ?>
                            <?php foreach ($staffPerformance as $staff): ?>
                                <?php $staffSalesCount = (int) $staff['sales_count']; $quotaPercent = min(100, ($staffSalesCount / $staffMonthlyQuota) * 100); ?>
                                <article class="staff-performance-card">
                                    <div class="staff-performance-head">
                                        <?php if ($staff['profile_image']): ?><img class="avatar avatar-image" src="<?= e($staff['profile_image']) ?>" alt="<?= e($staff['name']) ?>"><?php else: ?><div class="avatar" style="background:<?= e($staff['avatar_color']) ?>"><?= strtoupper(substr($staff['name'], 0, 2)) ?></div><?php endif; ?>
                                        <div><strong><?= e($staff['name']) ?></strong><small>@<?= e($staff['username']) ?></small></div>
                                        <span class="<?= $staffSalesCount > $staffMonthlyQuota ? 'quota-hit' : 'quota-building' ?>"><?= $staffSalesCount > $staffMonthlyQuota ? 'Commission active' : 'Building quota' ?></span>
                                    </div>
                                    <div class="quota-bar"><i style="width:<?= $quotaPercent ?>%"></i></div>
                                    <div class="staff-performance-stats">
                                        <span><strong><?= $staffSalesCount ?></strong><small>Sales this month</small></span>
                                        <span><strong><?= max(0, $staffMonthlyQuota - $staffSalesCount) ?></strong><small>Until commission</small></span>
                                        <span><strong>PHP <?= number_format((float) $staff['commission_amount'], 2) ?></strong><small>Commission earned</small></span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">Add staff accounts to start tracking their quota and commission.</div>
                        <?php endif; ?>
                    </div>
                </section>
                <section class="panel">
                    <div class="panel-head"><div><h2>Sold Accounts</h2><p class="muted">Only Main Admin can view which accounts were sold and who sold them.</p></div></div>
                    <button class="product-toggle" type="button" data-toggle-group>Show sold accounts (<?= count($sales) ?>)</button>
                    <div hidden><?php include __DIR__ . '/partials/sales-table.php'; ?></div>
                </section>
            <?php else: ?>
                <section class="panel staff-own-progress">
                    <div class="panel-head"><div><h2>Your Monthly Sales Quota</h2><p class="muted">Your first <?= $staffMonthlyQuota ?> sales complete the quota. Commission starts on sale <?= $staffMonthlyQuota + 1 ?>.</p></div></div>
                    <div class="quota-bar large"><i style="width:<?= min(100, ($staffProgress['sales_count'] / $staffMonthlyQuota) * 100) ?>%"></i></div>
                    <div class="staff-progress-stats">
                        <article><strong><?= $staffProgress['sales_count'] ?></strong><span>Sales this month</span></article>
                        <article><strong><?= $staffProgress['remaining'] ?></strong><span>Sales until commission</span></article>
                        <article><strong><?= number_format($currentAdmin['commission_rate'] !== null ? (float) $currentAdmin['commission_rate'] : (float) $appSettings['commission_rate'], 2) ?>%</strong><span>Commission rate</span></article>
                        <article><strong>PHP <?= number_format($staffProgress['commission_amount'], 2) ?></strong><span>Commission earned</span></article>
                    </div>
                    <p class="staff-privacy-note">Detailed sold-account records are visible only to Main Admin.</p>
                </section>
            <?php endif; ?>

            <div class="modal" id="sale-modal" data-sale-items='<?= e(json_encode($saleItems, JSON_UNESCAPED_SLASHES)) ?>' aria-hidden="true">
                <div class="modal-card inventory-modal-card">
                    <div class="modal-head"><div><p class="eyebrow">Sales</p><h2>Add New Sale</h2></div><button class="icon-btn" type="button" data-close-modal>&times;</button></div>
                    <form method="post" class="form sale-form" data-sale-form>
                        <input type="hidden" name="action" value="add_sale">
                        <input type="hidden" name="item_id" data-sale-item-id value="" required>
                        <div class="variant-row-form">
                            <label>Date<input type="date" name="sale_date" value="<?= date('Y-m-d') ?>" required></label>
                            <label>Category
                                <select data-sale-category required>
                                    <option value="">Select...</option>
                                    <?php foreach ($productCategories as $categoryName): ?>
                                        <option value="<?= e($categoryName) ?>"><?= e($categoryName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <label>Product Name
                            <select data-sale-product disabled required>
                                <option value="">Select product...</option>
                            </select>
                        </label>
                        <div class="variant-row-form">
                            <label>Type
                                <select data-sale-type disabled>
                                    <option value="">Type...</option>
                                </select>
                            </label>
                            <label>SubType
                                <select data-sale-subtype disabled>
                                    <option value="">SubType...</option>
                                </select>
                            </label>
                        </div>
                        <label>Account Email <small data-sale-available></small>
                            <input type="text" readonly placeholder="Select product first" data-sale-email data-click-copy title="Click to copy">
                        </label>
                        <div class="stock-hint" data-sale-stock-hint hidden></div>
                        <label class="pin-field">Account Password
                            <span class="pin-input-wrap">
                                <input type="password" readonly placeholder="Select product first" data-sale-password data-click-copy title="Click to copy">
                                <button class="row-icon-btn" type="button" data-toggle-sale-password>Show</button>
                            </span>
                        </label>
                        <input type="hidden" name="slot_id" data-sale-slot-id value="">
                        <label data-sale-slot-field hidden>Available Slot
                            <select data-sale-slot>
                                <option value="">Select slot...</option>
                            </select>
                        </label>
                        <div class="variant-row-form" data-sale-slot-details hidden>
                            <label>Slot Name<input type="text" readonly data-sale-slot-name data-click-copy title="Click to copy"></label>
                            <label>Slot PIN<input type="text" readonly data-sale-slot-pin data-click-copy title="Click to copy"></label>
                        </div>
                        <div class="variant-row-form">
                            <label>Unit Cost P<input type="number" min="0" step="0.01" name="unit_cost" data-sale-cost></label>
                            <label>Unit Price P<input type="number" min="0" step="0.01" name="unit_price" required data-sale-price></label>
                            <label>Qty<input type="number" min="1" value="1" name="quantity" data-sale-qty required></label>
                        </div>
                        <label>Discount P<input type="number" min="0" step="0.01" name="discount" value="0" data-sale-discount></label>
                        <div class="profit-preview">
                            <span>Profit</span>
                            <strong data-sale-profit-preview>PHP 0.00</strong>
                        </div>
                        <label>Notes<input type="text" name="notes" placeholder="Optional..." data-sale-notes></label>
                        <div class="modal-submit-bar"><button class="primary-btn full" type="submit">+ Add Sale</button></div>
                    </form>
                </div>
            </div>

        <?php elseif ($tab === 'salary'): ?>
            <?php
                $salaryFilter = ($_GET['salary_view'] ?? 'unreleased') === 'released' ? 'released' : 'unreleased';
                $salaryRangeLabel = $salaryWeekStart->format('M j') . ' – ' . $salaryWeekEnd->format('M j, Y');
                $salaryVisibleStaff = $isMainAdmin
                    ? array_values(array_filter($salaryStaff, static fn($s) => ($salaryFilter === 'released') === (bool) $s['released']))
                    : array_values(array_filter($salaryStaff, static fn($s) => (int) $s['id'] === (int) $currentAdmin['id']));
            ?>
            <section class="panel">
                <div class="panel-head"><div><h2>Salary Tracker <span class="head-icon">💼</span></h2><p class="muted">Released &amp; unreleased salary management</p></div></div>
                <div class="stats-grid-4">
                    <article class="stat-card salary-stat"><span class="salary-stat-label">🌿 Unreleased</span><strong>PHP <?= number_format($salaryUnreleasedTotal, 2) ?></strong></article>
                    <article class="stat-card salary-stat"><span class="salary-stat-label">✅ Released</span><strong>PHP <?= number_format($salaryReleasedTotal, 2) ?></strong></article>
                    <article class="stat-card salary-stat"><span class="salary-stat-label">📊 Profit</span><strong>PHP <?= number_format($salaryWeekProfit, 2) ?></strong></article>
                    <article class="stat-card salary-stat"><span class="salary-stat-label">👥 Staff</span><strong><?= count($salaryStaff) ?></strong></article>
                </div>
            </section>
            <section class="panel">
                <div class="salary-week-nav">
                    <a class="row-icon-btn" href="?tab=salary&week=<?= $salaryPrevWeek ?>&salary_view=<?= $salaryFilter ?>">&larr;</a>
                    <span class="salary-range-pill">📅 <?= $salaryRangeLabel ?></span>
                    <a class="row-icon-btn" href="?tab=salary&week=<?= $salaryNextWeek ?>&salary_view=<?= $salaryFilter ?>">&rarr;</a>
                </div>
                <?php if ($isMainAdmin): ?>
                <div class="inventory-view-tabs">
                    <a class="<?= $salaryFilter === 'unreleased' ? 'active' : '' ?>" href="?tab=salary&week=<?= $salaryWeekStartStr ?>&salary_view=unreleased">🌿 Unreleased</a>
                    <a class="<?= $salaryFilter === 'released' ? 'active' : '' ?>" href="?tab=salary&week=<?= $salaryWeekStartStr ?>&salary_view=released">✅ Released</a>
                </div>
                <?php endif; ?>
                <div class="salary-list">
                    <?php if ($salaryVisibleStaff): ?>
                        <?php foreach ($salaryVisibleStaff as $staff): ?>
                            <div class="data-row salary-row">
                                <span class="salary-staff">
                                    <?php if ($staff['profile_image']): ?><img class="avatar avatar-image" src="<?= e($staff['profile_image']) ?>" alt="<?= e($staff['name']) ?>"><?php else: ?><div class="avatar" style="background:<?= e($staff['avatar_color']) ?>"><?= strtoupper(substr($staff['name'], 0, 2)) ?></div><?php endif; ?>
                                    <div><strong><?= e($staff['name']) ?></strong><small>@<?= e($staff['username']) ?></small></div>
                                </span>
                                <span>PHP <?= number_format($staff['commission_amount'], 2) ?></span>
                                <span class="salary-status <?= $staff['released'] ? 'salary-status-released' : 'salary-status-unreleased' ?>"><?= $staff['released'] ? '✅ Released' : '🌿 Unreleased' ?></span>
                                <?php if ($isMainAdmin): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle_salary_release">
                                        <input type="hidden" name="admin_id" value="<?= (int) $staff['id'] ?>">
                                        <input type="hidden" name="week_start" value="<?= $salaryWeekStartStr ?>">
                                        <input type="hidden" name="week" value="<?= $salaryWeekStartStr ?>">
                                        <button class="row-icon-btn" type="submit"><?= $staff['released'] ? 'Mark Unreleased' : 'Mark Released' ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state salary-empty-state">
                            <?php if ($salaryFilter === 'released'): ?>
                                <span class="salary-empty-icon">✨</span>
                                <strong>No released salaries</strong>
                                <p>No salaries released this week yet.</p>
                            <?php else: ?>
                                <span class="salary-empty-icon">✨</span>
                                <strong>All salaries released</strong>
                                <p>No unreleased salaries this week.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

        <?php elseif ($tab === 'users'): ?>
             <?php if ($isMainAdmin): ?><section class="panel"><div class="panel-head"><h2>Add User</h2></div><form method="post" enctype="multipart/form-data" class="inline-form"><input type="hidden" name="action" value="add_admin"><input name="name" placeholder="Full name" required><input name="username" placeholder="Username" pattern="[A-Za-z0-9._-]+" required><input name="role" value="Staff" placeholder="Role" required><input name="password" type="password" placeholder="Password" required><label class="profile-upload-field"><span class="profile-upload-title">Profile Picture</span><span class="profile-upload-button">Choose file</span><span class="profile-upload-name" data-file-name>No file selected</span><small>JPG, PNG, or WebP. Maximum 2MB.</small><input name="profile_image" type="file" accept="image/jpeg,image/png,image/webp" data-file-input></label><button class="primary-btn">Add User</button></form></section><?php endif; ?>
            <section class="users-grid"><?php foreach ($admins as $admin): ?><article class="user-card"><?php if ($admin['profile_image']): ?><img class="avatar avatar-image" src="<?= e($admin['profile_image']) ?>" alt="<?= e($admin['name']) ?>"><?php else: ?><div class="avatar" style="background:<?= e($admin['avatar_color']) ?>"><?= strtoupper(substr($admin['name'],0,2)) ?></div><?php endif; ?><div class="user-card-details"><h3><?= e($admin['name']) ?></h3><p><?= e($admin['role']) ?></p><small>@<?= e($admin['username']) ?></small></div><?php if ($isMainAdmin && !is_main_admin($admin)): ?><form method="post" class="user-delete-form" data-confirm-delete-user="<?= e($admin['name']) ?>"><input type="hidden" name="action" value="delete_admin"><input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>"><button class="danger-btn" type="submit">Delete</button></form><?php endif; ?></article><?php endforeach; ?></section>

        <?php else: ?>
            <section class="settings-shell">
                <div class="settings-hero">
                    <div>
                        <p class="eyebrow">Settings</p>
                        <h1>Manage your shop controls.</h1>
                        <p class="muted">Account, team access, security, and workspace preferences in one clean place.</p>
                    </div>
                    <div class="settings-profile-card">
                        <?php if ($currentAdmin['profile_image']): ?><img class="profile-photo" src="<?= e($currentAdmin['profile_image']) ?>" alt="<?= e($currentAdmin['name']) ?>"><?php else: ?><img class="cat-cutout" src="assets/cat3-removebg-preview.png" alt=""><?php endif; ?>
                        <div>
                            <strong><?= e($currentAdmin['name']) ?></strong>
                            <small><?= e($currentAdmin['role']) ?></small>
                        </div>
                    </div>
                    <img class="settings-peek-cat cat-cutout" src="assets/cat1-removebg-preview.png" alt="">
                </div>

                <nav class="settings-tabs">
                    <a href="#general"><span>G</span>General</a>
                    <?php if ($isMainAdmin): ?><a href="#commissions"><span>%</span>Commissions</a><?php endif; ?>
                    <a href="#profile"><span>P</span>Profile</a>
                    <a href="#team"><span>T</span>Team</a>
                    <a href="#security"><span>S</span>Security</a>
                    <a href="#customization"><span>C</span>Customization</a>
                </nav>

                <div class="settings-grid">
                    <article class="settings-panel workspace-panel" id="general">
                        <div class="settings-panel-head">
                            <span class="soft-icon">W</span>
                            <h2>Company / Workspace Settings</h2>
                            <?php if (!$isMainAdmin): ?><span class="settings-lock">Main Admin only</span><?php endif; ?>
                        </div>
                        <div class="workspace-layout">
                            <div class="logo-uploader">
                                <img class="cat-cutout" src="assets/logo.png" alt="Maeyumi Prems logo">
                                <small>Your current workspace logo.</small>
                            </div>
                            <form method="post" class="settings-form-grid">
                                <input type="hidden" name="action" value="save_workspace">
                                <label>Company Name<input name="company_name" value="<?= e($appSettings['company_name']) ?>" <?= !$isMainAdmin ? 'readonly' : '' ?> required></label>
                                <label>Tagline<input name="tagline" value="<?= e($appSettings['tagline']) ?>" <?= !$isMainAdmin ? 'readonly' : '' ?>></label>
                                <label>Website<input name="website" type="url" value="<?= e($appSettings['website']) ?>" placeholder="https://example.com" <?= !$isMainAdmin ? 'readonly' : '' ?>></label>
                                <label>Database<input value="<?= e($config['name']) ?>" readonly></label>
                                <label>Currency<select name="currency" <?= !$isMainAdmin ? 'disabled' : '' ?>><option value="PHP" <?= $appSettings['currency'] === 'PHP' ? 'selected' : '' ?>>PHP (Peso)</option><option value="USD" <?= $appSettings['currency'] === 'USD' ? 'selected' : '' ?>>USD (Dollar)</option></select></label>
                                <label>Timezone<select name="timezone" <?= !$isMainAdmin ? 'disabled' : '' ?>><option value="Asia/Manila" <?= $appSettings['timezone'] === 'Asia/Manila' ? 'selected' : '' ?>>Asia/Manila</option><option value="UTC" <?= $appSettings['timezone'] === 'UTC' ? 'selected' : '' ?>>UTC</option></select></label>
                                <?php if ($isMainAdmin): ?><label>Default Staff Commission Rate (%)<input name="commission_rate" type="number" min="0" max="100" step="0.01" value="<?= e($appSettings['commission_rate']) ?>" required></label><?php endif; ?>
                                <?php if ($isMainAdmin): ?><button class="settings-save-btn" type="submit">Save Workspace</button><?php endif; ?>
                            </form>
                            <img class="panel-cat workspace-cat cat-cutout" src="assets/cat2-removebg-preview.png" alt="">
                        </div>
                    </article>

                    <?php if ($isMainAdmin): ?>
                    <article class="settings-panel commission-panel" id="commissions">
                        <div class="settings-panel-head">
                            <span class="soft-icon cyan">%</span>
                            <h2>Per-Admin Commission Rates</h2>
                        </div>
                        <p class="muted">Override the default commission rate for individual staff. Leave blank to use the default rate above. Staff still must hit the <?= $staffMonthlyQuota ?>-sale monthly quota before commission applies.</p>
                        <form method="post" class="commission-rates-form">
                            <input type="hidden" name="action" value="save_admin_commissions">
                            <div class="commission-rates-list">
                                <?php foreach ($admins as $admin): ?>
                                    <?php if (is_main_admin($admin)) continue; ?>
                                    <div class="commission-rate-row">
                                        <?php if ($admin['profile_image']): ?><img class="avatar avatar-image" src="<?= e($admin['profile_image']) ?>" alt="<?= e($admin['name']) ?>"><?php else: ?><div class="avatar" style="background:<?= e($admin['avatar_color']) ?>"><?= strtoupper(substr($admin['name'], 0, 2)) ?></div><?php endif; ?>
                                        <div class="commission-rate-info"><strong><?= e($admin['name']) ?></strong><small>@<?= e($admin['username']) ?></small></div>
                                        <label class="commission-rate-input">
                                            <input type="number" min="0" max="100" step="0.01" name="commission_rates[<?= (int) $admin['id'] ?>]" placeholder="<?= e($appSettings['commission_rate']) ?>" value="<?= $admin['commission_rate'] !== null ? e((string) $admin['commission_rate']) : '' ?>">
                                            <span>%</span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($admins) <= 1): ?>
                                    <div class="empty-state">Add staff accounts to set individual commission rates.</div>
                                <?php endif; ?>
                            </div>
                            <?php if (count($admins) > 1): ?><button class="settings-save-btn" type="submit">Save Commission Rates</button><?php endif; ?>
                        </form>
                        <img class="panel-cat commission-cat cat-cutout" src="assets/cat5-removebg-preview.png" alt="">
                    </article>
                    <?php endif; ?>

                    <article class="settings-panel profile-settings-panel" id="profile">
                        <div class="settings-panel-head">
                            <span class="soft-icon purple">P</span>
                            <h2>Your Profile</h2>
                        </div>
                        <form method="post" class="settings-form-grid compact-settings-form" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="save_profile">
                            <label class="profile-upload-field"><span class="profile-upload-title">Profile Picture</span><span class="profile-upload-button">Choose file</span><span class="profile-upload-name" data-file-name>No file selected</span><small>JPG, PNG, or WebP. Maximum 2MB.</small><input name="profile_image" type="file" accept="image/jpeg,image/png,image/webp" data-file-input></label>
                            <label>Display Name<input name="name" value="<?= e($currentAdmin['name']) ?>" required></label>
                            <label>Username<input name="username" value="<?= e($currentAdmin['username']) ?>" pattern="[A-Za-z0-9._-]+" required></label>
                            <label>Role<input value="<?= e($currentAdmin['role']) ?>" readonly></label>
                            <button class="settings-save-btn" type="submit">Save Profile</button>
                        </form>
                        <img class="panel-cat pref-cat cat-cutout" src="assets/cat4-removebg-preview.png" alt="">
                    </article>

                    <article class="settings-panel security-panel" id="security">
                        <div class="settings-panel-head">
                            <span class="soft-icon blue">S</span>
                            <h2>Change Password</h2>
                        </div>
                        <form method="post" class="security-password-form">
                            <input type="hidden" name="action" value="change_password">
                            <label>Current Password<input name="current_password" type="password" autocomplete="current-password" required></label>
                            <label>New Password<input name="new_password" type="password" minlength="8" autocomplete="new-password" required></label>
                            <label>Confirm New Password<input name="confirm_password" type="password" minlength="8" autocomplete="new-password" required></label>
                            <button class="settings-save-btn" type="submit">Change Password</button>
                        </form>
                        <img class="panel-cat security-cat cat-cutout" src="assets/cat7-removebg-preview.png" alt="">
                    </article>

                    <article class="settings-panel team-panel" id="team">
                        <div class="settings-panel-head">
                            <span class="soft-icon cyan">T</span>
                            <h2>Team & Roles</h2>
                            <?php if ($isMainAdmin): ?><a class="mini-action" href="?tab=users">Manage Team</a><?php endif; ?>
                        </div>
                        <div class="team-table">
                            <div class="team-row head"><span>Team Member</span><span>Role</span><span>Permission</span><span>Status</span></div>
                            <?php foreach (array_slice($admins, 0, 4) as $admin): ?>
                                <div class="team-row">
                                    <span><?php if ($admin['profile_image']): ?><img class="mini-avatar mini-avatar-image" src="<?= e($admin['profile_image']) ?>" alt=""><?php else: ?><b class="mini-avatar" style="background:<?= e($admin['avatar_color']) ?>"><?= strtoupper(substr($admin['name'], 0, 1)) ?></b><?php endif; ?><?= e($admin['name']) ?></span>
                                    <span class="pill"><?= e($admin['role']) ?></span>
                                    <span><?= is_main_admin($admin) ? 'Full Access' : 'Limited' ?></span>
                                    <span class="online">Online</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <img class="panel-cat team-cat cat-cutout" src="assets/cat1-removebg-preview.png" alt="">
                    </article>

                    <article class="settings-panel customization-panel" id="customization">
                        <div class="settings-panel-head">
                            <span class="soft-icon purple">C</span>
                            <h2>Customization</h2>
                        </div>
                        <form method="post" class="customization-form">
                            <input type="hidden" name="action" value="save_customization">
                            <fieldset class="theme-choices">
                                <legend>Theme Mode</legend>
                                <?php foreach (['light' => 'Clean Light', 'soft' => 'Soft Pastel'] as $themeValue => $themeLabel): ?>
                                    <label class="theme-choice <?= $appSettings['theme_mode'] === $themeValue ? 'selected' : '' ?>">
                                        <input type="radio" name="theme_mode" value="<?= e($themeValue) ?>" <?= $appSettings['theme_mode'] === $themeValue ? 'checked' : '' ?>>
                                        <span class="theme-preview <?= e($themeValue) ?>"></span>
                                        <strong><?= e($themeLabel) ?></strong>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <div class="accent-choice-field">
                                <strong>Accent Color</strong>
                                <div class="accent-presets" data-accent-presets>
                                    <?php foreach (['#ef4f82', '#a56be8', '#4c93ef', '#16b8ad', '#e3a72f', '#ff7f79'] as $presetColor): ?>
                                        <button class="<?= strtolower($appSettings['accent_color']) === $presetColor ? 'selected' : '' ?>" type="button" data-accent-color="<?= e($presetColor) ?>" style="--preset-color:<?= e($presetColor) ?>" aria-label="Use <?= e($presetColor) ?>"></button>
                                    <?php endforeach; ?>
                                </div>
                                <label class="custom-color-input">Custom Color<input name="accent_color" type="color" value="<?= e($appSettings['accent_color']) ?>" data-accent-input></label>
                            </div>
                            <button class="settings-save-btn" type="submit">Save Customization</button>
                        </form>
                        <img class="panel-cat integrations-cat cat-cutout" src="assets/cat2-removebg-preview.png" alt="">
                    </article>
                </div>
            </section>
        <?php endif; ?>

        <?php if (in_array($tab, ['products', 'inventory'], true)): ?>
            <div class="modal" id="inventory-modal" aria-hidden="true">
                <div class="modal-card inventory-modal-card">
                    <div class="modal-head"><div><p class="eyebrow">Products</p><h2 data-inventory-modal-title>Add Variant</h2></div><button class="icon-btn" type="button" data-close-modal>&times;</button></div>
                    <form method="post" class="form inventory-form" data-inventory-form>
                        <input type="hidden" name="action" value="add_item">
                        <input type="hidden" name="item_id" data-item-id>
                        <label>Product Name<input name="product_name" placeholder="e.g. Netflix, Spotify..." required data-product-name-input></label>
                        <label>Category
                            <select name="category" data-category-select required>
                                <option value="">Category...</option>
                                <?php foreach ($productCategories as $categoryName): ?>
                                    <option value="<?= e($categoryName) ?>"><?= e($categoryName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="variant-row-form">
                            <label>Type
                                <select name="variant_type" data-variant-type required>
                                    <option value="">Type...</option>
                                    <?php foreach (array_keys($variantTypeOptions) as $variantType): ?>
                                        <option value="<?= e($variantType) ?>"><?= e($variantType) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>SubType
                                <select name="variant_subtype" data-variant-subtype required>
                                    <option value="">SubType...</option>
                                </select>
                            </label>
                        </div>
                        <label>Stock<input name="stock" type="number" min="0" value="0" required></label>
                        <label>Price P<input name="sell_price" type="number" min="0" step="0.01" required data-sell-price-input></label>
                        <label>Cost P<input name="cost_price" type="number" min="0" step="0.01" required data-cost-price-input></label>
                        <div class="profit-preview">
                            <span>Profit Preview</span>
                            <strong data-profit-preview>PHP 0.00</strong>
                        </div>
                        <div class="slot-section" data-netflix-only>
                            <label>Slots<input name="slot_count" type="number" min="0" value="0" data-slot-count></label>
                            <div class="slot-builder" data-slot-builder></div>
                        </div>
                        <input type="hidden" name="account_email" data-account-email-input>
                        <input type="hidden" name="account_password" data-account-password-input>
                        <label>Notes<input name="notes" type="text" placeholder="Optional notes" data-notes-input></label>
                        <div class="modal-submit-bar"><button class="primary-btn full" type="submit" data-submit-label>Save Variant</button></div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
<div class="loading-bar" aria-hidden="true"></div>
<script src="assets/app.js?v=app-15"></script>
</body>
</html>
