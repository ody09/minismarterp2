const purchaseForm = document.querySelector('[data-purchase-form]');
if (purchaseForm) {
    const rows = purchaseForm.querySelector('[data-purchase-lines]');
    const add = purchaseForm.querySelector('[data-add-line]');
    const reindex = () => {
        const lines = rows.querySelectorAll('[data-purchase-line]');
        lines.forEach((row, index) => {
            row.querySelectorAll('[data-field]').forEach(input => {
                input.name = `items[${index}][${input.dataset.field}]`;
                input.id = `${input.dataset.field}_${index}`;
                input.closest('label').htmlFor = input.id;
            });
            row.querySelector('[data-remove-line]').disabled = lines.length === 1;
        });
        add.disabled = lines.length >= 100;
    };
    add.addEventListener('click', () => {
        if (rows.children.length >= 100) return;
        rows.append(document.getElementById('purchase-line-template').content.cloneNode(true));
        reindex();
        rows.lastElementChild.querySelector('select').focus();
    });
    rows.addEventListener('click', event => {
        const remove = event.target.closest('[data-remove-line]');
        if (!remove || rows.children.length === 1) return;
        const row = remove.closest('[data-purchase-line]');
        const next = row.nextElementSibling || row.previousElementSibling;
        row.remove();
        reindex();
        next.querySelector('select').focus();
    });
    rows.addEventListener('change', event => {
        if (event.target.dataset.field !== 'product_id') return;
        const option = event.target.selectedOptions[0];
        if (!option?.value) return;
        const row = event.target.closest('[data-purchase-line]');
        row.querySelector('[data-field="unit"]').value = option.dataset.unit;
        row.querySelector('[data-field="unit_price"]').value = option.dataset.price;
    });
    reindex();
}
document.querySelectorAll('[data-receipt-line]').forEach(row => {
    const select = row.querySelector('[data-existing-batch]');
    const fields = row.querySelector('[data-new-batch-fields]');
    const update = () => {
        fields.hidden = Boolean(select.value);
        fields.querySelectorAll('input').forEach(input => input.disabled = Boolean(select.value));
    };
    select.addEventListener('change', update);
    update();
});
