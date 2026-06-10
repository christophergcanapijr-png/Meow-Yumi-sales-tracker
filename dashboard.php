<?php
session_start();
require __DIR__ . '/bootstrap.php';
require_login();

$pdo = db($config);
ensure_inventory_details($pdo, $config['name']);
ensure_admin_usernames($pdo, $config['name']);
ensure_app_settings($pdo);
$adminStmt = $pdo->prepare("SELECT id, name, username, role, avatar_color FROM admins WHERE id = ?");
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
    ? ['dashboard', 'inventory', 'sales', 'users', 'settings']
    : ['dashboard', 'inventory', 'sales', 'settings'];
$tab = in_array($tab, $allowedTabs, true) ? $tab : 'dashboard';
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['add_item', 'add_category', 'delete_category', 'add_admin', 'save_workspace', 'save_customization'], true) && !$isMainAdmin) {
        $flash = 'Only Main Admin can manage inventory, users, and global settings.';
        $action = '';
    }

    if ($action === 'add_item') {
        $categoryChoice = trim($_POST['category'] ?? '');
        $customCategory = trim($_POST['custom_category'] ?? '');
        $category = $categoryChoice === 'Other'
            ? $customCategory
            : $categoryChoice;
        if ($category === '') {
            $flash = 'Choose or create a category first.';
            $tab = 'inventory';
        } else {
        $pdo->prepare("INSERT IGNORE INTO inventory_categories (name) VALUES (?)")->execute([$category]);
        $stmt = $pdo->prepare("INSERT INTO inventory_items (name, category, stock, netflix_slots, netflix_pin, sell_price, cost_price) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            trim($_POST['name']),
            $category,
            (int) $_POST['stock'],
            $category === 'Netflix' ? (int) ($_POST['netflix_slots'] ?? 0) : null,
            null,
            (float) $_POST['sell_price'],
            (float) $_POST['cost_price'],
        ]);
        $itemId = (int) $pdo->lastInsertId();
        if ($category === 'Netflix') {
            $slotNumbers = $_POST['slot_numbers'] ?? [];
            $slotPins = $_POST['slot_pins'] ?? [];
            $slotStmt = $pdo->prepare("INSERT INTO inventory_item_slots (inventory_item_id, slot_number, pin_code) VALUES (?, ?, ?)");
            foreach ($slotNumbers as $index => $slotNumber) {
                $pin = trim($slotPins[$index] ?? '');
                $slotNumber = (int) $slotNumber;
                if ($slotNumber > 0 && $pin !== '') {
                    $slotStmt->execute([$itemId, $slotNumber, $pin]);
                }
            }
        }
        $flash = 'Inventory item added.';
        $tab = 'inventory';
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
            $names = array_map(static fn($row) => $row['name'], $itemsInCategory);

            if ($ids) {
                $itemPlaceholders = implode(',', array_fill(0, count($ids), '?'));
                $namePlaceholders = implode(',', array_fill(0, count($names), '?'));
                $pdo->prepare("DELETE FROM inventory_item_slots WHERE inventory_item_id IN ($itemPlaceholders)")->execute($ids);
                $pdo->prepare("DELETE FROM sales_records WHERE item_name IN ($namePlaceholders)")->execute($names);
                $pdo->prepare("DELETE FROM inventory_items WHERE id IN ($itemPlaceholders)")->execute($ids);
            }
            $pdo->prepare("DELETE FROM inventory_categories WHERE name = ?")->execute([$name]);
            $pdo->commit();
            $flash = 'Category deleted.';
        } else {
            $flash = 'Category name is required.';
        }
        $tab = 'inventory';
    } elseif ($action === 'add_sale') {
        $itemId = (int) $_POST['item_id'];
        $quantity = max(1, (int) $_POST['quantity']);
        $stmt = $pdo->prepare("SELECT name, stock, sell_price FROM inventory_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if ($item && (int) $item['stock'] >= $quantity) {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO sales_records (item_name, quantity, total_amount) VALUES (?, ?, ?)")
                ->execute([$item['name'], $quantity, $quantity * (float) $item['sell_price']]);
            $pdo->prepare("UPDATE inventory_items SET stock = stock - ? WHERE id = ?")->execute([$quantity, $itemId]);
            $pdo->commit();
            $flash = 'Sale recorded.';
        } else {
            $flash = 'Not enough stock.';
        }
        $tab = 'sales';
    } elseif ($action === 'add_admin') {
        $stmt = $pdo->prepare("INSERT INTO admins (name, username, role, password_hash, avatar_color) VALUES (?, ?, ?, ?, ?)");
        $name = trim($_POST['name']);
        $stmt->execute([$name, strtolower(trim($_POST['username'])), trim($_POST['role']), password_hash($_POST['password'], PASSWORD_DEFAULT), avatar_color($name)]);
        $flash = 'User added.';
        $tab = 'users';
    } elseif ($action === 'save_workspace') {
        save_app_settings($pdo, [
            'company_name' => trim($_POST['company_name'] ?? 'Maeyumi Prems') ?: 'Maeyumi Prems',
            'tagline' => trim($_POST['tagline'] ?? ''),
            'website' => trim($_POST['website'] ?? ''),
            'currency' => in_array($_POST['currency'] ?? '', ['PHP', 'USD'], true) ? $_POST['currency'] : 'PHP',
            'timezone' => in_array($_POST['timezone'] ?? '', ['Asia/Manila', 'UTC'], true) ? $_POST['timezone'] : 'Asia/Manila',
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
                $pdo->prepare("UPDATE admins SET name = ?, username = ? WHERE id = ?")
                    ->execute([$name, $username, $currentAdmin['id']]);
                $_SESSION['admin_name'] = $name;
                $flash = 'Profile updated.';
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
    } elseif ($action === 'save_customization') {
        $themeMode = in_array($_POST['theme_mode'] ?? '', ['light', 'soft', 'dark'], true) ? $_POST['theme_mode'] : 'light';
        $accentColor = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['accent_color'] ?? '')
            ? strtolower($_POST['accent_color'])
            : '#ef4f82';
        save_app_settings($pdo, ['theme_mode' => $themeMode, 'accent_color' => $accentColor]);
        $flash = 'Customization saved.';
        $tab = 'settings';
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
    'theme_mode' => 'light',
    'accent_color' => '#ef4f82',
], app_settings($pdo));

$admins = $pdo->query("SELECT id, name, username, role, avatar_color, created_at FROM admins ORDER BY id DESC")->fetchAll();
$items = $pdo->query("SELECT * FROM inventory_items ORDER BY id DESC")->fetchAll();
$categories = $pdo->query("SELECT name FROM inventory_categories ORDER BY created_at ASC, name ASC")->fetchAll();
$slotRows = $pdo->query("SELECT inventory_item_id, slot_number, pin_code FROM inventory_item_slots ORDER BY slot_number ASC")->fetchAll();
$itemSlots = [];
foreach ($slotRows as $slotRow) {
    $itemSlots[(int) $slotRow['inventory_item_id']][] = $slotRow;
}
$normalizeCategory = static function (array $item): string {
    return in_array($item['category'], ['Streaming', 'Design', 'AI Tools'], true)
        ? $item['name']
        : $item['category'];
};
$inventoryGroups = [];
foreach ($items as $item) {
    $categoryName = $normalizeCategory($item);
    if (in_array($categoryName, array_column($categories, 'name'), true)) {
        $inventoryGroups[$categoryName][] = $item;
    }
}
$inventoryOrder = array_map(static fn($row) => $row['name'], $categories);
$sales = $pdo->query("SELECT * FROM sales_records ORDER BY sold_at DESC LIMIT 30")->fetchAll();
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
    'inventory' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8.5 12 4l8 4.5"></path><path d="M4 8.5 12 13l8-4.5"></path><path d="M4 8.5V18l8 4 8-4V8.5"></path></svg>',
    'sales' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 18h16"></path><path d="M7 15v-4"></path><path d="M12 15V7"></path><path d="M17 15v-6"></path></svg>',
    'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.2"></circle><path d="M3.5 20c0-3 2.9-5.5 5.5-5.5S14.5 17 14.5 20"></path><circle cx="17.5" cy="9.5" r="2.4"></circle><path d="M14.8 20c.2-2.1 1.7-3.7 3.7-3.7 1.5 0 2.9.8 3.5 2"></path></svg>',
    'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3.2"></circle><path d="M19.4 13.4v-2.8l-2 .1a6.9 6.9 0 0 0-.9-1.6l1.3-1.5-2-2-1.6 1.3a6.9 6.9 0 0 0-1.6-.9L12.4 2h-2.8l.1 2a6.9 6.9 0 0 0-1.6.9L6.6 3.6l-2 2 1.3 1.6a6.9 6.9 0 0 0-.9 1.6l-2 .1v2.8l2-.1a6.9 6.9 0 0 0 .9 1.6l-1.3 1.5 2 2 1.6-1.3a6.9 6.9 0 0 0 1.6.9l-.1 2h2.8l-.1-2a6.9 6.9 0 0 0 1.6-.9l1.5 1.3 2-2-1.3-1.6a6.9 6.9 0 0 0 .9-1.6z"></path></svg>',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= ucfirst($tab) ?> | <?= e($appSettings['company_name']) ?></title>
    <link rel="stylesheet" href="assets/style.css?v=app-23">
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
            <?php foreach (['dashboard' => 'Dashboard', 'inventory' => 'Inventory', 'sales' => 'Sales', 'users' => 'Users', 'settings' => 'Settings'] as $key => $label): ?>
                <?php if ($key === 'users' && !$isMainAdmin) continue; ?>
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
            <div class="avatar" style="background:<?= e($currentAdmin['avatar_color']) ?>"><?= strtoupper(substr($currentAdmin['name'], 0, 2)) ?></div>
            <div><strong><?= e($currentAdmin['name']) ?></strong><small><?= e($currentAdmin['role']) ?></small></div>
        </div>
        <a class="logout-btn" href="logout.php">Logout</a>
    </aside>

    <main class="app-main" id="dashboard-content">
        <header class="page-head"><div><p class="eyebrow"><?= e($appSettings['company_name']) ?></p><h1><?= ucfirst($tab) ?></h1></div><span><?= date('F j, Y') ?></span></header>
        <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>

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
                            <img class="cat-cutout" src="assets/cat3-removebg-preview.png" alt="">
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
                <a class="quick-action" href="?tab=settings"><strong>Salary</strong></a>
            </section>

        <?php elseif ($tab === 'inventory'): ?>
            <section class="panel">
                <div class="catalog-head">
                    <div>
                        <h2>Products</h2>
                        <p class="muted">Manage your product catalog</p>
                    </div>
                    <div class="panel-actions">
                        <button class="secondary-btn" type="button" data-refresh-dashboard>↻</button>
                        <?php if ($isMainAdmin): ?>
                            <button class="secondary-btn" type="button" data-open-modal="category-modal">+ Add Category</button>
                            <button class="primary-btn" type="button" data-open-modal="inventory-modal">+ Add Inventory Item</button>
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
                <div class="category-list">
                    <?php foreach ($inventoryOrder as $categoryName): ?>
                        <?php
                            $categoryItems = $inventoryGroups[$categoryName] ?? [];
                            $categoryCount = count($categoryItems);
                            $categoryStock = array_sum(array_map(fn($item) => (int) $item['stock'], $categoryItems));
                        ?>
                        <article class="category-card" data-category-card data-category-name="<?= e(strtolower($categoryName)) ?>">
                            <div class="category-card-head">
                                <button class="category-toggle" type="button" data-toggle-group>
                                <span>
                                    <strong><?= e($categoryName) ?></strong>
                                    <small><?= $categoryCount ?> variants</small>
                                </span>
                                <span class="category-meta">
                                    <strong><?= $categoryCount ?></strong>
                                    <small><?= $categoryStock ?> stock</small>
                                </span>
                                </button>
                                <?php if ($isMainAdmin): ?><button class="danger-btn" type="button" data-delete-category="<?= e($categoryName) ?>">Delete</button><?php endif; ?>
                            </div>
                            <div class="category-body" hidden>
                                <?php if ($categoryItems): ?>
                                    <div class="data-table">
                                        <div class="data-row inventory-row head"><span>Variant</span><span>Stock</span><span>Slots</span><span>PINs</span><span>Cost</span><span>Price</span><span>Profit</span><span></span></div>
                                        <?php foreach ($categoryItems as $item): ?>
                                            <div class="data-row inventory-row" data-product-row data-product-name="<?= e(strtolower($item['name'])) ?>" data-product-category="<?= e(strtolower($categoryName)) ?>">
                                                <span><strong><?= e($item['name']) ?></strong><small><?= e($item['category']) ?></small></span>
                                                <span><?= (int) $item['stock'] ?></span>
                                                <span><?= $item['category'] === 'Netflix' ? (int) $item['netflix_slots'] : '-' ?></span>
                                                <span class="pin-value">
                                                    <?= $item['category'] === 'Netflix' ? count($itemSlots[(int) $item['id']] ?? []) . ' saved' : '-' ?>
                                                </span>
                                                <span>PHP <?= number_format((float) $item['cost_price']) ?></span>
                                                <span>PHP <?= number_format((float) $item['sell_price']) ?></span>
                                                <span>PHP <?= number_format((float) $item['sell_price'] - (float) $item['cost_price']) ?></span>
                                                <span class="row-actions"><button type="button" class="text-btn">View</button></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">No <?= e($categoryName) ?> accounts yet.</div>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="modal" id="inventory-modal" aria-hidden="true">
                <div class="modal-card">
                    <div class="modal-head"><div><p class="eyebrow">Inventory</p><h2>Add Inventory Item</h2></div><button class="icon-btn" type="button" data-close-modal>&times;</button></div>
                    <form method="post" class="form" data-inventory-form>
                        <input type="hidden" name="action" value="add_item">
                        <label>Item name<input name="name" placeholder="Account or package name" required></label>
                        <label>Category<select name="category" data-category-select required>
                            <?php foreach ($inventoryOrder as $categoryName): ?>
                                <option value="<?= e($categoryName) ?>"><?= e($categoryName) ?></option>
                            <?php endforeach; ?>
                            <option value="Other">Other / New Category</option>
                        </select></label>
                        <div class="custom-category-field" data-custom-category-field hidden>
                            <label>New category<input name="custom_category" placeholder="Spotify, Disney, and more"></label>
                        </div>
                        <label>Stock<input name="stock" type="number" min="0" value="0" required></label>
                        <div class="netflix-only" data-netflix-only hidden>
                            <label>Netflix slots<input name="netflix_slots" type="number" min="0" value="0" data-slot-count></label>
                            <div class="slot-builder" data-slot-builder hidden></div>
                        </div>
                        <label>Cost<input name="cost_price" type="number" min="0" step="0.01" required></label>
                        <label>Sell price<input name="sell_price" type="number" min="0" step="0.01" required></label>
                        <button class="primary-btn full" type="submit">Add Item</button>
                    </form>
                </div>
            </div>

            <div class="modal" id="category-modal" aria-hidden="true">
                <div class="modal-card">
                    <div class="modal-head"><div><p class="eyebrow">Categories</p><h2>Add Category</h2></div><button class="icon-btn" type="button" data-close-modal>&times;</button></div>
                    <form method="post" class="form">
                        <input type="hidden" name="action" value="add_category">
                        <label>Category name<input name="name" placeholder="Spotify, Disney, and more" required></label>
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

        <?php elseif ($tab === 'sales'): ?>
            <section class="panel"><div class="panel-head"><h2>Record Sale</h2></div><form method="post" class="inline-form"><input type="hidden" name="action" value="add_sale"><select name="item_id" required><option value="">Select item</option><?php foreach ($items as $item): ?><option value="<?= $item['id'] ?>"><?= e($item['name']) ?> (<?= $item['stock'] ?> left)</option><?php endforeach; ?></select><input name="quantity" type="number" min="1" value="1" required><button class="primary-btn">Record Sale</button></form></section>
            <section class="panel"><div class="panel-head"><h2>Sales History</h2></div><?php include __DIR__ . '/partials/sales-table.php'; ?></section>

        <?php elseif ($tab === 'users'): ?>
            <?php if ($isMainAdmin): ?><section class="panel"><div class="panel-head"><h2>Add User</h2></div><form method="post" class="inline-form"><input type="hidden" name="action" value="add_admin"><input name="name" placeholder="Full name" required><input name="username" placeholder="Username" pattern="[A-Za-z0-9._-]+" required><input name="role" value="Staff" placeholder="Role" required><input name="password" type="password" placeholder="Password" required><button class="primary-btn">Add User</button></form></section><?php endif; ?>
            <section class="users-grid"><?php foreach ($admins as $admin): ?><article class="user-card"><div class="avatar" style="background:<?= e($admin['avatar_color']) ?>"><?= strtoupper(substr($admin['name'],0,2)) ?></div><div><h3><?= e($admin['name']) ?></h3><p><?= e($admin['role']) ?></p><small>@<?= e($admin['username']) ?></small></div></article><?php endforeach; ?></section>

        <?php else: ?>
            <section class="settings-shell">
                <div class="settings-hero">
                    <div>
                        <p class="eyebrow">Settings</p>
                        <h1>Manage your shop controls.</h1>
                        <p class="muted">Account, team access, security, and workspace preferences in one clean place.</p>
                    </div>
                    <div class="settings-profile-card">
                        <img class="cat-cutout" src="assets/cat3-removebg-preview.png" alt="">
                        <div>
                            <strong><?= e($currentAdmin['name']) ?></strong>
                            <small><?= e($currentAdmin['role']) ?></small>
                        </div>
                    </div>
                    <img class="settings-peek-cat cat-cutout" src="assets/cat1-removebg-preview.png" alt="">
                </div>

                <nav class="settings-tabs">
                    <a href="#general"><span>G</span>General</a>
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
                                <?php if ($isMainAdmin): ?><button class="settings-save-btn" type="submit">Save Workspace</button><?php endif; ?>
                            </form>
                            <img class="panel-cat workspace-cat cat-cutout" src="assets/cat2-removebg-preview.png" alt="">
                        </div>
                    </article>

                    <article class="settings-panel profile-settings-panel" id="profile">
                        <div class="settings-panel-head">
                            <span class="soft-icon purple">P</span>
                            <h2>Your Profile</h2>
                        </div>
                        <form method="post" class="settings-form-grid compact-settings-form">
                            <input type="hidden" name="action" value="save_profile">
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
                                    <span><b class="mini-avatar" style="background:<?= e($admin['avatar_color']) ?>"><?= strtoupper(substr($admin['name'], 0, 1)) ?></b><?= e($admin['name']) ?></span>
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
                            <?php if (!$isMainAdmin): ?><span class="settings-lock">Main Admin only</span><?php endif; ?>
                        </div>
                        <form method="post" class="customization-form">
                            <input type="hidden" name="action" value="save_customization">
                            <fieldset class="theme-choices" <?= !$isMainAdmin ? 'disabled' : '' ?>>
                                <legend>Theme Mode</legend>
                                <?php foreach (['light' => 'Clean Light', 'soft' => 'Soft Pastel', 'dark' => 'Dark'] as $themeValue => $themeLabel): ?>
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
                                <label class="custom-color-input">Custom Color<input name="accent_color" type="color" value="<?= e($appSettings['accent_color']) ?>" data-accent-input <?= !$isMainAdmin ? 'disabled' : '' ?>></label>
                            </div>
                            <?php if ($isMainAdmin): ?><button class="settings-save-btn" type="submit">Save Customization</button><?php endif; ?>
                        </form>
                        <img class="panel-cat integrations-cat cat-cutout" src="assets/cat2-removebg-preview.png" alt="">
                    </article>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
<div class="loading-bar" aria-hidden="true"></div>
<script src="assets/app.js?v=app-12"></script>
</body>
</html>
