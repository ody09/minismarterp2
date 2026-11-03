<?php

return [
    'title' => 'Reports', 'business' => 'Business overview', 'hint' => 'Actual posted activity and current stock alerts.',
    'sales' => 'Sales report', 'purchases' => 'Purchase report', 'inventory' => 'Inventory alerts',
    'sales_hint' => 'Confirmed sales only, filtered by confirmation time and customer.',
    'purchases_hint' => 'Received purchases only, filtered by receipt time and supplier.',
    'inventory_hint' => 'Current main-warehouse balances. These are not historical balances for a selected period.',
    'period_hint' => 'Inclusive posting days in :timezone. Commercial order dates are shown separately.',
    'period' => 'Reporting period', 'apply' => 'Apply filters', 'amount' => 'Total amount', 'documents' => 'Posted documents',
    'pieces' => 'Quantity in pieces', 'sales_total' => 'Confirmed sales', 'purchases_total' => 'Received purchases',
    'open' => 'Open report', 'recent' => 'Recent posted documents', 'posted_at' => 'Posted at', 'empty' => 'No posted documents match this period.',
    'no_access' => 'No business report permissions are assigned to this account.', 'all_parties' => 'All counterparties',
    'expiry_days' => 'Near-expiry days', 'stock_now' => 'Current stock', 'products' => 'Matching products',
    'low' => 'Low-stock products', 'near_batches' => 'Near-expiry batches', 'expired_batches' => 'Expired batches',
    'near_quantity' => 'Near-expiry pieces', 'alert' => 'Alert filter', 'alert_all' => 'All balances', 'alert_low' => 'Low stock',
    'alert_near' => 'Near expiry', 'alert_expired' => 'Expired', 'stock_rule' => 'Low stock means active products at or below their sellable reorder level. Near expiry includes positive physical batches from :today through :until.',
    'product_balances' => 'Product balances', 'batch_balances' => 'Positive batch balances', 'low_list' => 'Products needing replenishment',
    'expiry_list' => 'Batches needing review', 'no_alerts' => 'No matching alerts.', 'no_batches' => 'No matching positive batches.',
    'no_expiry' => 'No expiry date', 'valid' => 'Valid', 'current_date' => 'Business date',
];
