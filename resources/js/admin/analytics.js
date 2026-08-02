const clamp = (value, minimum, maximum) => Math.min(maximum, Math.max(minimum, value));

export const nearestTrendPointIndex = (clientX, bounds, viewBox, pointXs) => {
    if (!Array.isArray(pointXs) || pointXs.length === 0) return 0;

    const ratio = bounds.width > 0 ? clamp((clientX - bounds.left) / bounds.width, 0, 1) : 0;
    const viewX = viewBox.x + ratio * viewBox.width;

    return pointXs.reduce((nearestIndex, pointX, index) => (
        Math.abs(pointX - viewX) < Math.abs(pointXs[nearestIndex] - viewX) ? index : nearestIndex
    ), 0);
};

const pointerIndex = (event, surface, points) => {
    const viewBox = surface.viewBox?.baseVal;

    return nearestTrendPointIndex(
        event.clientX,
        surface.getBoundingClientRect(),
        { x: viewBox?.x ?? 0, width: viewBox?.width ?? surface.clientWidth },
        points.map((point) => Number.parseFloat(point.dataset.x || point.getAttribute('cx') || '0')),
    );
};

const replaceTokens = (template, values) => Object.entries(values).reduce(
    (result, [key, value]) => result.replaceAll(`:${key}`, String(value)),
    template,
);

const initializeLogChart = (chart) => {
    const surface = chart.querySelector('[data-log-chart-surface]');
    const dataElement = chart.querySelector('[data-log-chart-data]');
    const points = Array.from(chart.querySelectorAll('[data-log-chart-point]'));
    const guide = chart.querySelector('[data-log-chart-guide]');
    const pvMarker = chart.querySelector('[data-log-chart-pv-marker]');
    const aiMarker = chart.querySelector('[data-log-chart-ai-marker]');
    const detailDate = chart.querySelector('[data-log-detail-date]');
    const detailPv = chart.querySelector('[data-log-detail-pv]');
    const detailUniqueIp = chart.querySelector('[data-log-detail-unique-ip]');
    const detailAi = chart.querySelector('[data-log-detail-ai]');
    const detailErrors = chart.querySelector('[data-log-detail-errors]');
    const detailState = chart.querySelector('[data-log-detail-state]');

    if (!surface || !dataElement || points.length === 0 || !guide || !pvMarker || !aiMarker) {
        return;
    }

    let series;
    try {
        series = JSON.parse(dataElement.textContent || '[]');
    } catch {
        return;
    }

    if (!Array.isArray(series) || series.length !== points.length) {
        return;
    }

    const numberFormatter = new Intl.NumberFormat(document.documentElement.lang || undefined);
    const defaultIndex = clamp(Number.parseInt(chart.dataset.defaultIndex || '0', 10), 0, series.length - 1);
    let activeIndex = defaultIndex;
    let pinnedIndex = null;

    const update = (index, pinned = false) => {
        activeIndex = clamp(index, 0, series.length - 1);
        const row = series[activeIndex];
        const point = points[activeIndex];
        const x = point.dataset.x || point.getAttribute('cx');
        const pvY = point.dataset.pvY || point.getAttribute('cy');
        const aiY = point.dataset.aiY || point.getAttribute('cy');

        guide.setAttribute('x1', x);
        guide.setAttribute('x2', x);
        pvMarker.setAttribute('cx', x);
        pvMarker.setAttribute('cy', pvY);
        aiMarker.setAttribute('cx', x);
        aiMarker.setAttribute('cy', aiY);

        if (detailDate) detailDate.textContent = row.date;
        if (detailPv) detailPv.textContent = numberFormatter.format(row.pv);
        if (detailUniqueIp) detailUniqueIp.textContent = numberFormatter.format(row.unique_ip);
        if (detailAi) detailAi.textContent = numberFormatter.format(row.ai_bot_pv);
        if (detailErrors) detailErrors.textContent = numberFormatter.format(row.errors);
        if (detailState) detailState.textContent = pinned ? chart.dataset.pinnedLabel : chart.dataset.previewLabel;

        chart.dataset.pinned = pinned ? 'true' : 'false';
        chart.setAttribute('aria-label', replaceTokens(chart.dataset.ariaTemplate || '', {
            date: row.date,
            pv: row.pv,
            unique_ip: row.unique_ip,
            ai: row.ai_bot_pv,
            errors: row.errors,
        }));
    };

    surface.addEventListener('pointermove', (event) => {
        if (event.pointerType === 'touch') {
            return;
        }

        update(pointerIndex(event, surface, points), false);
    });

    surface.addEventListener('pointerleave', () => {
        const restoreIndex = pinnedIndex ?? defaultIndex;
        update(restoreIndex, pinnedIndex !== null);
    });

    surface.addEventListener('click', (event) => {
        pinnedIndex = pointerIndex(event, surface, points);
        update(pinnedIndex, true);
    });

    chart.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            const direction = event.key === 'ArrowLeft' ? -1 : 1;
            update(activeIndex + direction, false);
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            pinnedIndex = activeIndex;
            update(pinnedIndex, true);
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            pinnedIndex = null;
            update(activeIndex, false);
        }
    });

    update(defaultIndex, false);
};

const initializeTrendChart = (root) => {
    const chart = root.querySelector('[data-analytics-trend-chart]');
    const dataElement = root.querySelector('[data-analytics-trend-data]');
    const metricsElement = root.querySelector('[data-analytics-trend-metrics]');
    const points = Array.from(root.querySelectorAll('[data-analytics-trend-point]'));
    const guide = root.querySelector('[data-analytics-trend-guide]');
    const date = root.querySelector('[data-analytics-trend-date]');
    const state = root.querySelector('[data-analytics-trend-state]');
    if (!chart || !dataElement || !metricsElement || points.length === 0 || !guide) return;

    let series;
    let metrics;
    try {
        series = JSON.parse(dataElement.textContent || '[]');
        metrics = JSON.parse(metricsElement.textContent || '[]');
    } catch {
        return;
    }
    if (!Array.isArray(series) || !Array.isArray(metrics) || series.length !== points.length) return;

    const formatter = new Intl.NumberFormat(document.documentElement.lang || undefined);
    const defaultIndex = clamp(Number.parseInt(root.dataset.defaultIndex || '0', 10), 0, series.length - 1);
    let activeIndex = defaultIndex;
    let pinnedIndex = null;

    const update = (index, pinned = false) => {
        activeIndex = clamp(index, 0, series.length - 1);
        const row = series[activeIndex];
        const point = points[activeIndex];
        const x = point.dataset.x;
        let positions = {};
        try { positions = JSON.parse(point.dataset.y || '{}'); } catch { positions = {}; }
        guide.setAttribute('x1', x);
        guide.setAttribute('x2', x);
        if (date) date.textContent = row.date || '';
        if (state) state.textContent = pinned ? root.dataset.pinnedLabel : root.dataset.previewLabel;
        metrics.forEach((metric) => {
            const marker = root.querySelector(`[data-analytics-trend-marker="${metric.key}"]`);
            const value = root.querySelector(`[data-analytics-trend-value="${metric.key}"]`);
            if (marker) {
                marker.setAttribute('cx', x);
                marker.setAttribute('cy', positions[metric.key] ?? 194);
            }
            if (value) {
                const decimals = Number.parseInt(metric.decimals || '0', 10);
                value.textContent = `${Number(row[metric.key] || 0).toLocaleString(document.documentElement.lang || undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}${metric.suffix || ''}`;
            }
        });
        root.dataset.pinned = pinned ? 'true' : 'false';
        chart.setAttribute('aria-label', `${row.date || ''}, ${metrics.map((metric) => `${metric.label} ${formatter.format(row[metric.key] || 0)}${metric.suffix || ''}`).join(', ')}`);
    };

    chart.addEventListener('pointermove', (event) => {
        if (event.pointerType !== 'touch') update(pointerIndex(event, chart, points), false);
    });
    chart.addEventListener('pointerleave', () => update(pinnedIndex ?? defaultIndex, pinnedIndex !== null));
    chart.addEventListener('click', (event) => {
        pinnedIndex = pointerIndex(event, chart, points);
        update(pinnedIndex, true);
    });
    chart.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            update(activeIndex + (event.key === 'ArrowLeft' ? -1 : 1), false);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            pinnedIndex = activeIndex;
            update(pinnedIndex, true);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            pinnedIndex = null;
            update(activeIndex, false);
        }
    });

    update(defaultIndex, false);
};

const initializeAnalyticsFilterForm = (form) => {
    const presetInput = form.querySelector('input[name="preset"]');
    const dateFromInput = form.querySelector('input[name="date_from"]');
    const dateToInput = form.querySelector('input[name="date_to"]');
    const presetButtons = form.querySelectorAll('[data-analytics-preset-button]');
    const activeClasses = ['border-blue-600', 'bg-blue-50', 'text-blue-700'];
    const inactiveClasses = ['border-gray-200', 'text-gray-600', 'hover:border-blue-200', 'hover:bg-blue-50'];

    const setPresetButtonState = (selectedPreset) => {
        presetButtons.forEach((button) => {
            const isActive = button.dataset.preset === selectedPreset;
            button.classList.remove(...activeClasses, ...inactiveClasses);
            button.classList.add(...(isActive ? activeClasses : inactiveClasses));
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    };

    presetButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (!presetInput || !dateFromInput || !dateToInput) return;

            const selectedPreset = button.dataset.preset || '7d';
            presetInput.value = selectedPreset;
            dateFromInput.value = button.dataset.dateFrom || dateFromInput.value;
            dateToInput.value = button.dataset.dateTo || dateToInput.value;
            setPresetButtonState(selectedPreset);
            if (selectedPreset === 'custom') dateFromInput.focus();
        });
    });

    form.querySelectorAll('[data-analytics-custom-date]').forEach((input) => {
        input.addEventListener('change', () => {
            if (presetInput) presetInput.value = 'custom';
            setPresetButtonState('custom');
        });
    });
};

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-analytics-log-chart]').forEach(initializeLogChart);
    document.querySelectorAll('[data-analytics-trend]').forEach(initializeTrendChart);
    document.querySelectorAll('[data-analytics-filter-form]').forEach(initializeAnalyticsFilterForm);
}
