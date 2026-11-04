<?php

return [
    'title' => 'Sales orders', 'create' => 'New sales order', 'edit' => 'Edit draft', 'hint' => 'Prepare customer sales and follow their stock issues.',
    'number' => 'Sales order', 'draft' => 'Draft', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled',
    'empty' => 'No sales orders yet.', 'search' => 'Order number or customer', 'save_draft' => 'Save and calculate prices',
    'draft_hint' => 'Prices are calculated on save using the customer, order date and quantity in each selected unit. Editing and saving re-prices the draft. Stock changes only on confirmation.',
    'pricing_hint' => 'A matching customer tier is used first; otherwise the catalog selling price is converted to the selected unit. Review calculated prices before confirming.',
    'source' => 'Price source', 'catalog_price' => 'Catalog price', 'customer_price' => 'Customer price',
    'confirm' => 'Confirm sale', 'review_hint' => 'Review this saved order. Confirmation issues all quantities from the main warehouse, using the earliest valid expiry first. Prices and stock are checked again.',
    'confirm_action' => 'Confirm and issue stock', 'confirmed_notice' => 'Sale confirmed and stock issued.',
    'cancelled_notice' => 'Draft cancelled. Stock was not changed.', 'cancel_order' => 'Cancel draft',
    'confirmed_by' => 'Confirmed by', 'confirmed_at' => 'Confirmed at', 'stock_link' => 'View stock issue',
    'allocations' => 'Batch allocations', 'history' => 'Sales history', 'back' => 'Back to sales',
    'terminal_hint' => 'This document is retained for audit. Editing and draft cancellation are closed.',
    'errors' => ['duplicate' => 'Combine duplicate product/unit lines into one quantity so the correct price tier can be selected.',
        'key_conflict' => 'This submission key was already used with different data.', 'not_draft' => 'Only a draft can be edited, confirmed or cancelled.',
        'stale' => 'This draft changed. Reload it and review its current items before continuing.',
        'inactive' => 'The selected customer or product is missing or inactive.', 'empty' => 'A sale must contain at least one saved item.',
        'packing' => 'Product packing changed. Edit and save this draft to review the current conversion and price.',
        'pricing_changed' => 'Pricing changed since this draft was saved. Edit and save it, then review the updated prices before confirmation.',
        'document_reversal' => 'A sales-linked issue needs a document reversal workflow and cannot be reversed directly.'],
];
