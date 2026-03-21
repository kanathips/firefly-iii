/**
 * InsightsCard.js
 *
 * Dashboard card component that fetches and displays AI spending insights
 * from the /api/v1/ext/ai/insights endpoint.
 *
 * Handles loading, error, and empty states gracefully. Uses vanilla JS to
 * avoid adding a framework dependency.
 */

'use strict';

const INSIGHTS_ENDPOINT = '/api/v1/ext/ai/insights';

/**
 * Fetch AI insights for the current user and render them into the given
 * container element.
 *
 * @param {HTMLElement} container - The DOM element to render the card into.
 * @returns {Promise<void>}
 */
async function renderInsightsCard(container) {
    if (!container) {
        return;
    }

    container.innerHTML = '<p class="ai-insights-loading">Loading AI insights...</p>';

    let insights;

    try {
        const response = await fetch(INSIGHTS_ENDPOINT, {
            headers: {
                'Accept':       'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const json = await response.json();
        insights   = json.data?.attributes ?? null;
    } catch (error) {
        container.innerHTML = `<p class="ai-insights-error">Could not load AI insights: ${error.message}</p>`;
        return;
    }

    if (!insights) {
        container.innerHTML = '<p class="ai-insights-empty">No insights available yet.</p>';
        return;
    }

    container.innerHTML = buildCardHTML(insights);
}

/**
 * Build the HTML string for the insights card.
 *
 * @param {object} insights
 * @param {string} insights.period_start
 * @param {string} insights.period_end
 * @param {number} insights.total_spent
 * @param {number} insights.daily_average
 * @param {number} insights.transaction_count
 * @param {Array<{category: string, amount: number, percentage: number}>} insights.top_categories
 * @returns {string}
 */
function buildCardHTML(insights) {
    const categories = (insights.top_categories ?? [])
        .map(
            (cat) =>
                `<li class="ai-insights-category">
                    <span class="ai-insights-category-name">${escapeHtml(cat.category)}</span>
                    <span class="ai-insights-category-amount">${formatAmount(cat.amount)}</span>
                    <span class="ai-insights-category-pct">(${cat.percentage}%)</span>
                </li>`
        )
        .join('');

    return `
        <div class="ai-insights-card">
            <h3 class="ai-insights-title">AI Spending Insights</h3>
            <p class="ai-insights-period">${escapeHtml(insights.period_start)} — ${escapeHtml(insights.period_end)}</p>
            <dl class="ai-insights-stats">
                <dt>Total spent</dt>
                <dd>${formatAmount(insights.total_spent)}</dd>
                <dt>Daily average</dt>
                <dd>${formatAmount(insights.daily_average)}</dd>
                <dt>Transactions</dt>
                <dd>${insights.transaction_count}</dd>
            </dl>
            ${categories ? `<ul class="ai-insights-categories">${categories}</ul>` : ''}
        </div>`;
}

/**
 * Escape a string for safe HTML insertion.
 *
 * @param {string} str
 * @returns {string}
 */
function escapeHtml(str) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(str).replace(/[&<>"']/g, (char) => map[char]);
}

/**
 * Format a numeric amount with 2 decimal places.
 *
 * @param {number} amount
 * @returns {string}
 */
function formatAmount(amount) {
    return Number(amount).toFixed(2);
}

export { buildCardHTML, escapeHtml, formatAmount, renderInsightsCard };
