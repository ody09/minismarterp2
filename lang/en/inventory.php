<?php

return [
    'title' => 'Inventory', 'hint' => 'Product and batch balances in the main warehouse.', 'physical' => 'Physical stock',
    'sellable' => 'Sellable stock', 'expired' => 'Expired stock', 'quantity' => 'Quantity in pieces', 'transactions' => 'Stock movements',
    'transactions_hint' => 'Recorded movements with their source, operator and posting time.', 'batch_details' => 'Batch balances',
    'type' => 'Movement type', 'receipt' => 'Receipt', 'issue' => 'Issue', 'reversal' => 'Reversal', 'reference' => 'Source reference',
    'posted_at' => 'Posted at', 'actor' => 'Operator', 'line' => 'Line', 'allocation' => 'Allocation', 'operation' => 'Operation details',
    'reason' => 'Reason', 'all_types' => 'All movements', 'from' => 'From date', 'to' => 'To date',
    'search_reference' => 'Search source reference', 'empty_products' => 'No matching products.', 'empty_batches' => 'No batches for this product.',
    'empty_transactions' => 'No stock movements have been posted yet.', 'zero_hint' => 'Products start at zero; posted receipts and issues update their balances.',
    'expiry_hint' => 'Expired goods remain in physical stock and are excluded from sellable stock. Inactive products are not sellable.',
    'packing_locked' => 'Carton packaging cannot change after the first stock movement.',
    'batch_locked' => 'Batch number and dates cannot change after the first stock movement.',
    'history_locked' => 'This information is preserved because it is referenced by stock history.', 'original' => 'Original operation',
    'reversed_by' => 'Reversed by', 'back' => 'Back to inventory', 'status_expired' => 'Expired',
    'status_valid' => 'Valid', 'unit_snapshot' => 'Requested line quantity and unit', 'time_zone' => 'Time zone',
    'errors' => [
        'source_used' => 'This source reference has already been posted.', 'key_conflict' => 'This operation key belongs to a different request.',
        'inactive_or_missing' => 'The product or batch is unavailable for posting.', 'expired' => 'Expired batches cannot be received.',
        'insufficient' => 'Available stock is insufficient for this operation.', 'already_reversed' => 'This operation cannot be reversed or has already been reversed.',
        'overflow' => 'The quantity exceeds the exact storage limit.',
    ],
];
