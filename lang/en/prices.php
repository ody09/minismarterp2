<?php

return [
    'title' => 'Customer prices', 'create' => 'New customer price', 'edit' => 'Edit customer price',
    'customer' => 'Customer', 'select_customer' => 'Select a customer', 'all_customers' => 'All customers',
    'all_products' => 'All products', 'all_states' => 'All states', 'min_quantity' => 'Minimum quantity',
    'valid_from' => 'Valid from', 'valid_to' => 'Valid to', 'open' => 'Open', 'empty' => 'No customer prices found.',
    'hint' => 'The highest eligible quantity tier wins. Prices apply to their exact unit; validity dates are inclusive.',
    'form_hint' => 'Minimum quantity uses the selected unit. Leave either date blank for an open period. Deactivate a rule to retain its history.',
    'errors' => ['dates' => 'The end date must be on or after the start date.',
        'overlap' => 'An active price already overlaps this period for the same customer, product, unit and minimum quantity.',
        'stale' => 'This price changed. Reload it before editing.', 'inactive' => 'Select an existing active customer and product.',
        'conversion' => 'The converted catalog price exceeds the supported price limit. Review product or customer pricing.'],
];
