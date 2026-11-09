(function () {
    var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-module-tab]'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('[data-module-panel]'));
    var storageKey = 'wk5_database2_active_module';

    function activateModule(module, focusTab) {
        var selectedTab = tabs.find(function (tab) {
            return tab.dataset.moduleTab === module;
        });

        if (!selectedTab) {
            return;
        }

        tabs.forEach(function (tab) {
            var isSelected = tab === selectedTab;
            tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            tab.tabIndex = isSelected ? 0 : -1;
        });

        panels.forEach(function (panel) {
            panel.hidden = panel.dataset.modulePanel !== module;
        });

        window.sessionStorage.setItem(storageKey, module);

        if (focusTab) {
            selectedTab.focus();
        }
    }

    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () {
            activateModule(tab.dataset.moduleTab, false);
        });

        tab.addEventListener('keydown', function (event) {
            var nextIndex = index;

            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                nextIndex = (index + 1) % tabs.length;
            } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                nextIndex = (index - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = tabs.length - 1;
            } else {
                return;
            }

            event.preventDefault();
            activateModule(tabs[nextIndex].dataset.moduleTab, true);
        });
    });

    var requestedModule = new URLSearchParams(window.location.search).get('module');
    activateModule(
        requestedModule || window.sessionStorage.getItem(storageKey) || 'overview',
        false
    );
}());

function fillBorrowerUpdate(id, no, name, type, email) {
    document.getElementById('upd_borrower_id').value = id;
    document.getElementById('upd_borrower_no').value = no;
    document.getElementById('upd_full_name').value = name;
    document.getElementById('upd_borrower_type').value = type;
    document.getElementById('upd_email').value = email || '';

    document.getElementById('borrower-update-card').scrollIntoView({ behavior: 'smooth' });
    document.getElementById('upd_borrower_no').focus({ preventScroll: true });
}

function fillEquipmentUpdate(id, propertyNumber, name, category, quantity) {
    document.getElementById('upd_eq_id').value = id;
    document.getElementById('upd_eq_prop').value = propertyNumber;
    document.getElementById('upd_eq_name').value = name;
    document.getElementById('upd_eq_category').value = category || '';
    document.getElementById('upd_eq_qty').value = quantity;

    document.getElementById('equipment-update-card').scrollIntoView({ behavior: 'smooth' });
    document.getElementById('upd_eq_prop').focus({ preventScroll: true });
}

function editTransaction(button) {
    var transaction = JSON.parse(button.dataset.transaction);
    var form = document.getElementById('transaction-form');
    var container = document.getElementById('items-container');
    var rows = Array.prototype.slice.call(container.querySelectorAll('.item-row'));

    rows.slice(1).forEach(function (row) {
        row.remove();
    });

    var firstRow = container.querySelector('.item-row');
    firstRow.querySelector('input[name="borrow_item_id[]"]').value = '';
    firstRow.querySelector('select[name="equipment_id[]"]').value = '';
    firstRow.querySelector('input[name="quantity[]"]').value = '1';

    document.getElementById('transaction-form-heading').textContent =
        'Update Borrowing Transaction #' + transaction.borrow_id;
    form.elements.action.value = 'update_transaction';
    form.elements.borrow_id.value = transaction.borrow_id;
    form.elements.borrower_id.value = transaction.borrower_id;
    form.elements.borrow_date.value = transaction.borrow_date;
    form.elements.due_date.value = transaction.due_date;
    form.elements.status.value = transaction.status;

    transaction.items.forEach(function (item, index) {
        var row = index === 0 ? firstRow : cloneItemRow();
        row.querySelector('input[name="borrow_item_id[]"]').value = item.borrow_item_id;
        row.querySelector('select[name="equipment_id[]"]').value = item.equipment_id;
        row.querySelector('input[name="quantity[]"]').value = item.quantity;
    });

    document.getElementById('transaction-submit').textContent = 'Save Transaction';
    document.getElementById('transaction-cancel-edit').classList.remove('is-hidden');
    document.getElementById('module-borrowing').hidden = false;
    document.getElementById('module-records').hidden = true;

    document.querySelectorAll('[data-module-tab]').forEach(function (tab) {
        var isSelected = tab.dataset.moduleTab === 'borrowing';
        tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        tab.tabIndex = isSelected ? 0 : -1;
    });

    window.sessionStorage.setItem('wk5_database2_active_module', 'borrowing');
    form.scrollIntoView({ behavior: 'smooth' });
}

function cloneItemRow() {
    var container = document.getElementById('items-container');
    var firstRow = container.querySelector('.item-row');
    var clone = firstRow.cloneNode(true);

    clone.querySelector('input[name="borrow_item_id[]"]').value = '';
    clone.querySelector('select[name="equipment_id[]"]').value = '';
    clone.querySelector('input[name="quantity[]"]').value = '1';
    container.appendChild(clone);

    return clone;
}

function addItemRow() {
    cloneItemRow();
}

function resetTransactionForm() {
    var form = document.getElementById('transaction-form');
    var container = document.getElementById('items-container');
    var rows = Array.prototype.slice.call(container.querySelectorAll('.item-row'));

    rows.slice(1).forEach(function (row) {
        row.remove();
    });

    container.querySelector('input[name="borrow_item_id[]"]').value = '';
    container.querySelector('select[name="equipment_id[]"]').value = '';
    container.querySelector('input[name="quantity[]"]').value = '1';
    form.reset();
    form.elements.action.value = 'create_transaction';
    form.elements.borrow_id.value = '';
    form.elements.status.value = 'borrowed';

    document.getElementById('transaction-form-heading').textContent = 'Create Borrowing Transaction';
    document.getElementById('transaction-submit').textContent = 'Create Transaction';
    document.getElementById('transaction-cancel-edit').classList.add('is-hidden');
}

function removeItemRow(button) {
    var container = document.getElementById('items-container');
    var rows = container.querySelectorAll('.item-row');

    if (rows.length <= 1) {
        return;
    }

    button.closest('.item-row').remove();
}
