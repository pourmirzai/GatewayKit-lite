(function($) {
	'use strict';

	var config = window.GatewayKitAnalytics || {};

	function escapeHtml(str) {
		if (str === null || str === undefined) return '';
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function formatMoney(amount, currency) {
		currency = currency || config.currency || 'USD';
		var num = parseFloat(amount) || 0;
		try {
			return new Intl.NumberFormat(config.locale || 'en-US', {
				style: 'currency',
				currency: currency,
				minimumFractionDigits: 2,
				maximumFractionDigits: 2
			}).format(num);
		} catch (e) {
			return num.toFixed(2) + ' ' + currency;
		}
	}

	function showLoading($el) {
		$el.html('<p class="gk-loading">' + escapeHtml(config.i18n && config.i18n.loading) + '</p>');
	}

	function showEmpty($el) {
		$el.html('<p class="gk-empty">' + escapeHtml(config.i18n && config.i18n.no_data) + '</p>');
	}

	function showError($el) {
		$el.html('<p class="gk-empty">' + escapeHtml(config.i18n && config.i18n.error) + '</p>');
	}

	function renderKPIs(kpi, currency) {
		var $revenue = $('#gk-kpi-revenue');
		var $count = $('#gk-kpi-count');
		var $avg = $('#gk-kpi-avg');
		var $rate = $('#gk-kpi-rate');

		if (!kpi) {
			showEmpty($revenue.add($count).add($avg).add($rate));
			return;
		}

		$revenue.text(formatMoney(kpi.revenue, currency));
		$count.text(parseInt(kpi.count, 10) || 0);
		$avg.text(formatMoney(kpi.avg, currency));
		$rate.text((parseFloat(kpi.success_rate) || 0).toFixed(1) + '%');
	}

	function renderRevenueChart(chart, currency) {
		var $container = $('#gk-revenue-chart');
		if (!chart || !chart.length) {
			showEmpty($container);
			return;
		}

		var items = chart.slice(0, 60);

		var maxAmount = 0;
		for (var i = 0; i < items.length; i++) {
			if (parseFloat(items[i].amount) > maxAmount) {
				maxAmount = parseFloat(items[i].amount);
			}
		}

		if (maxAmount === 0) {
			showEmpty($container);
			return;
		}

		var html = '<div class="gk-bar-chart"><div class="gk-bar-chart__bars">';

		for (var j = 0; j < items.length; j++) {
			var item = items[j];
			var amount = parseFloat(item.amount) || 0;
			var pct = maxAmount > 0 ? (amount / maxAmount) * 100 : 0;
			var label = escapeHtml(item.date || '');
			var amountFormatted = formatMoney(amount, currency);
			var count = parseInt(item.count, 10) || 0;

			var tooltip = escapeHtml(label) + ': ' + escapeHtml(amountFormatted) + ' (' + count + ')';

			html += '<div class="gk-bar-chart__bar-wrap" title="' + tooltip + '">';
			html += '<div class="gk-bar-chart__bar" style="height: ' + pct + '%;"></div>';
			html += '<div class="gk-bar-chart__label">' + label + '</div>';
			html += '</div>';
		}

		html += '</div></div>';
		$container.html(html);
	}

	function renderGatewayChart(chart, currency) {
		var $container = $('#gk-gateway-chart');
		if (!chart || !chart.length) {
			showEmpty($container);
			return;
		}

		var totalRevenue = 0;
		for (var i = 0; i < chart.length; i++) {
			totalRevenue += parseFloat(chart[i].revenue) || 0;
		}

		var colors = ['#00a32a', '#2271b1', '#dba617', '#d63638', '#826eb4', '#f3722b', '#787c82'];

		var html = '<div class="gk-donut-list">';

		for (var j = 0; j < chart.length; j++) {
			var item = chart[j];
			var revenue = parseFloat(item.revenue) || 0;
			var pct = totalRevenue > 0 ? (revenue / totalRevenue) * 100 : 0;
			var color = colors[j % colors.length];

			html += '<div class="gk-donut-item">';
			html += '<span class="gk-donut-dot" style="background: ' + color + ';"></span>';
			html += '<span class="gk-donut-label">' + escapeHtml(item.gateway || '') + '</span>';
			html += '<span class="gk-donut-value">' + formatMoney(revenue, currency) + '</span>';
			html += '<span class="gk-donut-pct">' + pct.toFixed(1) + '%</span>';
			html += '</div>';
		}

		html += '</div>';
		$container.html(html);
	}

	function renderStatusChart(chart) {
		var $container = $('#gk-status-chart');
		if (!chart || !chart.counts) {
			showEmpty($container);
			return;
		}

		var statuses = [
			{ key: 'completed', label: 'Completed', color: '#00a32a' },
			{ key: 'failed', label: 'Failed', color: '#d63638' },
			{ key: 'pending', label: 'Pending', color: '#dba617' },
			{ key: 'refunded', label: 'Refunded', color: '#826eb4' },
			{ key: 'cancelled', label: 'Cancelled', color: '#787c82' }
		];

		var totalCount = 0;
		for (var i = 0; i < statuses.length; i++) {
			totalCount += parseInt(chart.counts[statuses[i].key], 10) || 0;
		}

		if (totalCount === 0) {
			showEmpty($container);
			return;
		}

		var html = '<div class="gk-status-bars">';

		for (var j = 0; j < statuses.length; j++) {
			var status = statuses[j];
			var count = parseInt(chart.counts[status.key], 10) || 0;
			var pct = totalCount > 0 ? (count / totalCount) * 100 : 0;

			html += '<div class="gk-status-row">';
			html += '<span class="gk-status-label">' + escapeHtml(status.label) + '</span>';
			html += '<div class="gk-status-bar-wrap">';
			html += '<div class="gk-status-bar" style="width: ' + pct + '%; background: ' + status.color + ';"></div>';
			html += '</div>';
			html += '<span class="gk-status-count">' + count + '</span>';
			html += '<span class="gk-status-pct">' + pct.toFixed(1) + '%</span>';
			html += '</div>';
		}

		html += '</div>';
		$container.html(html);
	}

	function renderTopForms(forms, currency) {
		var $container = $('#gk-top-forms');
		if (!forms || !forms.length) {
			showEmpty($container);
			return;
		}

		var maxRevenue = 0;
		for (var i = 0; i < forms.length; i++) {
			if (parseFloat(forms[i].revenue) > maxRevenue) {
				maxRevenue = parseFloat(forms[i].revenue);
			}
		}

		var html = '';

		for (var j = 0; j < forms.length; j++) {
			var form = forms[j];
			var revenue = parseFloat(form.revenue) || 0;
			var count = parseInt(form.count, 10) || 0;
			var pct = maxRevenue > 0 ? (revenue / maxRevenue) * 100 : 0;

			html += '<div class="gk-top-forms-item">';
			html += '<div class="gk-top-forms-name">' + escapeHtml(form.name || '') + '</div>';
			html += '<div class="gk-top-forms-bar-wrap">';
			html += '<div class="gk-top-forms-bar" style="width: ' + pct + '%;"></div>';
			html += '</div>';
			html += '<div class="gk-top-forms-meta">' + formatMoney(revenue, currency) + ' &middot; ' + count + '</div>';
			html += '</div>';
		}

		$container.html(html);
	}

	function loadData() {
		var days = $('#gk-period').val() || '30';
		var gateway = $('#gk-gateway').val() || '';
		var currency = config.currency || 'USD';

		var $targets = $('#gk-kpi-revenue, #gk-kpi-count, #gk-kpi-avg, #gk-kpi-rate, #gk-revenue-chart, #gk-gateway-chart, #gk-status-chart, #gk-top-forms');
		showLoading($targets);

		$.post(config.ajax_url, {
			action: 'gatewaykit_analytics_data',
			nonce: config.nonce,
			days: days,
			gateway: gateway
		})
		.done(function(response) {
			if (response && response.success) {
				var data = response.data;
				renderKPIs(data.kpi, currency);
				renderRevenueChart(data.revenue_chart, currency);
				renderGatewayChart(data.gateway_chart, currency);
				renderStatusChart(data.status_chart);
				renderTopForms(data.top_forms, currency);
			} else {
				showError($targets);
			}
		})
		.fail(function() {
			showError($targets);
		});
	}

	$(document).ready(function() {
		loadData();

		$('#gk-period').on('change', function() {
			loadData();
		});

		$('#gk-gateway').on('change', function() {
			loadData();
		});
	});

})(jQuery);
