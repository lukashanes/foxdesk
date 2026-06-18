(function () {
    var rows = Array.prototype.slice.call(document.querySelectorAll('.report-detail-row'));
    var totalAmountEl = document.getElementById('detail-billable-amount');
    if (!rows.length || !totalAmountEl) return;

    var surface = document.querySelector('[data-app-contract-surface="reporting-review"]');
    var currency = surface && surface.dataset.reportCurrency ? surface.dataset.reportCurrency : 'CZK';

    function numberValue(value) {
        var parsed = parseFloat(String(value || '').replace(',', '.'));
        return Number.isFinite(parsed) ? parsed : null;
    }

    function formatMoney(amount) {
        return Number(amount || 0).toLocaleString('cs-CZ', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).replace(/\u00a0/g, ' ') + ' ' + currency;
    }

    function formatDuration(minutes) {
        minutes = Math.max(0, Math.round(Number(minutes) || 0));
        var hours = Math.floor(minutes / 60);
        var mins = minutes % 60;
        return hours > 0 ? hours + 'h ' + mins + 'min' : mins + ' min';
    }

    function bulkPreviewForRow(row, billableMinutes, originalRate) {
        var check = row.querySelector('.bulk-entry-check');
        var form = document.getElementById('bulk-billing-form');
        if (!check || !check.checked || !form) return null;

        var action = form.querySelector('[name="bulk_action"]');
        var selectedAction = action ? action.value : 'set_rate';
        var value = null;
        if (selectedAction === 'set_rate') {
            value = numberValue(form.querySelector('[name="bulk_rate"]')?.value);
            if (value === null) return null;
            return { rate: value, amount: billableMinutes > 0 ? (billableMinutes / 60) * value : 0 };
        }
        if (selectedAction === 'discount_percent') {
            value = numberValue(form.querySelector('[name="bulk_discount_percent"]')?.value);
            if (value === null) return null;
            var discountedRate = originalRate * (1 - Math.min(Math.max(value, 0), 100) / 100);
            return { rate: discountedRate, amount: billableMinutes > 0 ? (billableMinutes / 60) * discountedRate : 0 };
        }
        if (selectedAction === 'discount_amount') {
            value = numberValue(form.querySelector('[name="bulk_discount_amount"]')?.value);
            if (value === null) return null;
            var discountedAmount = Math.max(0, (billableMinutes > 0 ? (billableMinutes / 60) * originalRate : 0) - Math.max(0, value));
            var discountedAmountRate = billableMinutes > 0 ? discountedAmount / (billableMinutes / 60) : 0;
            return { rate: discountedAmountRate, amount: discountedAmount };
        }
        if (selectedAction === 'target_total') {
            value = numberValue(form.querySelector('[name="bulk_target_total"]')?.value);
            if (value === null) return null;
            var selectedRows = rows.filter(function (candidate) {
                var candidateCheck = candidate.querySelector('.bulk-entry-check');
                return candidateCheck && candidateCheck.checked && candidate.dataset.billable === '1';
            });
            var selectedMinutes = selectedRows.reduce(function (sum, candidate) {
                return sum + Number(candidate.dataset.billableMinutes || 0);
            }, 0);
            if (selectedMinutes <= 0) return null;
            var targetRate = Math.max(0, value) / (selectedMinutes / 60);
            return { rate: targetRate, amount: billableMinutes > 0 ? (billableMinutes / 60) * targetRate : 0 };
        }
        return null;
    }

    function rowPreview(row) {
        var billable = row.dataset.billable === '1';
        var actualMinutes = Number(row.dataset.actualMinutes || 0);
        var billableMinutes = billable ? Number(row.dataset.billableMinutes || 0) : 0;
        var originalRate = Number(row.dataset.originalRate || 0);
        var originalAmount = Number(row.dataset.originalAmount || 0);
        var costAmount = Number(row.dataset.costAmount || 0);
        var rate = originalRate;
        var amount = originalAmount;
        var bulkPreview = bulkPreviewForRow(row, billableMinutes, originalRate);
        var form = row.querySelector('.entry-billing-form');

        if (bulkPreview) {
            rate = bulkPreview.rate;
            amount = bulkPreview.amount;
        } else if (form) {
            var action = form.querySelector('[name="entry_adjust_action"]');
            var input = form.querySelector('[name="entry_adjust_value"]');
            var value = numberValue(input ? input.value : '');
            if (value !== null) {
                if (action && action.value === 'set_rate') {
                    rate = value;
                    amount = billableMinutes > 0 ? (billableMinutes / 60) * rate : 0;
                } else if (action && action.value === 'discount_percent') {
                    rate = originalRate * (1 - Math.min(Math.max(value, 0), 100) / 100);
                    amount = billableMinutes > 0 ? (billableMinutes / 60) * rate : 0;
                } else if (action && action.value === 'discount_amount') {
                    amount = Math.max(0, originalAmount - Math.max(0, value));
                    rate = billableMinutes > 0 ? amount / (billableMinutes / 60) : 0;
                } else if (action && action.value === 'target_total') {
                    amount = Math.max(0, value);
                    rate = billableMinutes > 0 ? amount / (billableMinutes / 60) : originalRate;
                }
            }
        }

        if (!billable) {
            amount = 0;
            rate = 0;
        }

        return {
            actualMinutes: actualMinutes,
            billableMinutes: billableMinutes,
            amount: Math.max(0, amount),
            rate: Math.max(0, rate),
            cost: costAmount,
            profit: Math.max(0, amount) - costAmount
        };
    }

    function updatePreview() {
        var totals = rows.reduce(function (acc, row) {
            var preview = rowPreview(row);
            acc.actualMinutes += preview.actualMinutes;
            acc.billableMinutes += preview.billableMinutes;
            acc.amount += preview.amount;
            acc.cost += preview.cost;
            acc.profit += preview.profit;

            var amountEl = row.querySelector('[data-entry-amount]');
            var rateEl = row.querySelector('[data-entry-rate]');
            if (amountEl) amountEl.textContent = formatMoney(preview.amount);
            if (rateEl) rateEl.textContent = formatMoney(preview.rate) + '/h';
            return acc;
        }, { actualMinutes: 0, billableMinutes: 0, amount: 0, cost: 0, profit: 0 });

        var totalTimeEl = document.getElementById('detail-total-time');
        var billableTimeEl = document.getElementById('detail-billable-time');
        var profitEl = document.getElementById('detail-profit');
        if (totalTimeEl) totalTimeEl.textContent = formatDuration(totals.actualMinutes);
        if (billableTimeEl) billableTimeEl.textContent = formatDuration(totals.billableMinutes);
        totalAmountEl.textContent = formatMoney(totals.amount);
        if (profitEl) profitEl.textContent = formatMoney(totals.profit);
    }

    document.querySelectorAll('.entry-billing-form select, .entry-billing-form input, #bulk-billing-form select, #bulk-billing-form input')
        .forEach(function (field) {
            field.addEventListener('input', updatePreview);
            field.addEventListener('change', updatePreview);
        });

    updatePreview();
})();
