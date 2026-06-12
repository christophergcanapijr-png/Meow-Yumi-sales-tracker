<div class="data-table">
    <div class="data-row sales-row head"><span>Account Sold</span><span>Sold By</span><span>Qty</span><span>Price</span><span>Profit</span><span>Commission</span><span>Date</span></div>
    <?php if ($sales): ?>
        <?php foreach ($sales as $sale): ?>
            <div class="data-row sales-row">
                <span><strong><?= e($sale['item_name']) ?></strong><?php if (!empty($sale['account_email'])): ?><small><?= e($sale['account_email']) ?></small><?php endif; ?></span>
                <span><strong><?= e($sale['sold_by_name'] ?: 'Legacy / Unassigned') ?></strong></span>
                <span><?= (int) $sale['quantity'] ?></span>
                <span>PHP <?= number_format((float) $sale['total_amount'], 2) ?></span>
                <span>PHP <?= number_format((float) $sale['profit_amount'], 2) ?></span>
                <span>PHP <?= number_format((float) $sale['commission_amount'], 2) ?></span>
                <span><?= date('M j, Y g:i A', strtotime($sale['sold_at'])) ?></span>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">No sold accounts yet.</div>
    <?php endif; ?>
</div>
