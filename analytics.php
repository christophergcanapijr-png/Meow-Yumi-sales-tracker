<?php
session_start();
require __DIR__ . '/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $pdo = db($config);
    $range = $_GET['range'] ?? 'daily';
    $range = in_array($range, ['daily', 'weekly', 'monthly', 'all'], true) ? $range : 'daily';

    try {
        $selectedDate = new DateTimeImmutable($_GET['date'] ?? 'today');
    } catch (Throwable $e) {
        $selectedDate = new DateTimeImmutable('today');
    }

    $items = $pdo->query("SELECT name, cost_price FROM inventory_items")->fetchAll();
    $sales = $pdo->query("SELECT item_name, quantity, total_amount, sold_at FROM sales_records ORDER BY sold_at ASC")->fetchAll();
    $costByItem = [];
    foreach ($items as $item) {
        $costByItem[$item['name']] = (float) $item['cost_price'];
    }

    $rangeStart = match ($range) {
        'daily' => $selectedDate->setTime(0, 0, 0),
        'weekly' => $selectedDate->modify('monday this week')->setTime(0, 0, 0),
        'monthly' => $selectedDate->modify('first day of this month')->setTime(0, 0, 0),
        default => !empty($sales) ? new DateTimeImmutable($sales[0]['sold_at']) : $selectedDate->setTime(0, 0, 0),
    };
    $rangeEnd = match ($range) {
        'daily' => $selectedDate->setTime(23, 59, 59),
        'weekly' => $selectedDate->modify('sunday this week')->setTime(23, 59, 59),
        'monthly' => $selectedDate->modify('last day of this month')->setTime(23, 59, 59),
        default => new DateTimeImmutable('now'),
    };

    $metrics = ['revenue' => 0.0, 'costs' => 0.0, 'profit' => 0.0, 'count' => 0];
    foreach ($sales as $sale) {
        $soldAt = new DateTimeImmutable($sale['sold_at']);
        if ($soldAt < $rangeStart || $soldAt > $rangeEnd) {
            continue;
        }
        $revenue = (float) $sale['total_amount'];
        $cost = (float) ($costByItem[$sale['item_name']] ?? 0) * (int) $sale['quantity'];
        $metrics['revenue'] += $revenue;
        $metrics['costs'] += $cost;
        $metrics['profit'] += $revenue - $cost;
        $metrics['count'] += (int) $sale['quantity'];
    }

    $labels = [];
    $keys = [];
    if ($range === 'daily') {
        for ($i = 6; $i >= 0; $i--) {
            $point = $selectedDate->modify("-{$i} days");
            $keys[] = $point->format('Y-m-d');
            $labels[] = $point->format('D');
        }
    } elseif ($range === 'weekly') {
        $weekStart = $selectedDate->modify('monday this week');
        for ($i = 0; $i < 7; $i++) {
            $point = $weekStart->modify("+{$i} days");
            $keys[] = $point->format('Y-m-d');
            $labels[] = $point->format('D');
        }
    } elseif ($range === 'monthly') {
        $monthStart = $selectedDate->modify('first day of this month');
        $days = (int) $monthStart->format('t');
        for ($i = 0; $i < $days; $i++) {
            $point = $monthStart->modify("+{$i} days");
            $keys[] = $point->format('Y-m-d');
            $labels[] = $point->format('j');
        }
    } else {
        $chartEnd = !empty($sales) ? new DateTimeImmutable(end($sales)['sold_at']) : $selectedDate;
        $chartStart = $chartEnd->modify('first day of this month')->modify('-11 months');
        for ($i = 0; $i < 12; $i++) {
            $point = $chartStart->modify("+{$i} months");
            $keys[] = $point->format('Y-m');
            $labels[] = $point->format('M');
        }
    }

    $series = [
        'sales' => array_fill(0, count($keys), 0.0),
        'costs' => array_fill(0, count($keys), 0.0),
        'profit' => array_fill(0, count($keys), 0.0),
    ];
    $keyIndexes = array_flip($keys);
    foreach ($sales as $sale) {
        $soldAt = new DateTimeImmutable($sale['sold_at']);
        $key = $range === 'all' ? $soldAt->format('Y-m') : $soldAt->format('Y-m-d');
        if (!isset($keyIndexes[$key])) {
            continue;
        }
        $index = $keyIndexes[$key];
        $revenue = (float) $sale['total_amount'];
        $cost = (float) ($costByItem[$sale['item_name']] ?? 0) * (int) $sale['quantity'];
        $series['sales'][$index] += $revenue;
        $series['costs'][$index] += $cost;
        $series['profit'][$index] += $revenue - $cost;
    }

    echo json_encode([
        'range' => $range,
        'labels' => $labels,
        'metrics' => $metrics,
        'series' => $series,
        'updatedAt' => date('g:i:s A'),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load analytics.']);
}
