<div class="data-table">
    <div class="data-row sales-row head"><span>Item</span><span>Quantity</span><span>Total</span><span>Date</span></div>
    <?php foreach ($sales as $sale): ?>
        <div class="data-row sales-row"><span><strong><?= e($sale['item_name']) ?></strong></span><span><?= (int)$sale['quantity'] ?></span><span>PHP <?= number_format((float)$sale['total_amount']) ?></span><span><?= date('M j, Y g:i A', strtotime($sale['sold_at'])) ?></span></div>
    <?php endforeach; ?>
</div>
