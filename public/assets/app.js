const state = {
    currentView: 'positions',
    quoteTimer: null,
    countdownTimer: null,
    quoteCountdownSeconds: null,
    positions: [],
    positionsSort: {
        key: 'profit_rate',
        direction: 'desc',
    },
    ttradeFilterName: '',
    ttrades: [],
    watchlist: [],
    calculatorInputs: {},
    auth: {
        loggedIn: false,
        passwordConfigured: false,
        mustChangePassword: false,
    },
    quoteRefreshSeconds: window.APP_CONFIG?.quoteRefreshSeconds ?? 15,
    refreshOnlyTradingHours: window.APP_CONFIG?.quoteRefreshOnlyTradingHours ?? true,
    calculatorDefaultLotSize: Math.max(1, Number(window.APP_CONFIG?.calculatorDefaultLotSize ?? 1) || 1),
    positionAlertGainPercent: Math.max(0.1, Number(window.APP_CONFIG?.positionAlertGainPercent ?? 5) || 5),
    positionAlertLossPercent: Math.max(0.1, Number(window.APP_CONFIG?.positionAlertLossPercent ?? 5) || 5),
    positionAlertStateBySymbol: {},
    positionAlertsInitialized: false,
};

document.addEventListener('DOMContentLoaded', async () => {
    bindTabs();
    bindForms();
    bindRefreshButtons();
    bindRefreshSettings();
    bindPositionsSorting();
    bindPositionControls();
    bindTTradeControls();
    bindCalculatorControls();
    bindAuthActions();
    updateRefreshInfo();
    await initializeAuth();
});

function bindTabs() {
    document.querySelectorAll('#tabs .tab').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!state.auth.loggedIn || state.auth.mustChangePassword) {
                return;
            }

            const view = button.dataset.view;
            if (view === state.currentView) {
                return;
            }

            state.currentView = view;
            document.querySelectorAll('#tabs .tab').forEach((tab) => tab.classList.toggle('active', tab === button));
            document.querySelectorAll('.view').forEach((section) => {
                section.classList.toggle('active', section.dataset.view === view);
            });

            await loadCurrentView();
            startQuotePolling();
        });
    });
}

function bindForms() {
    document.getElementById('position-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        await submitForm('position.create', event.currentTarget, async () => {
            await loadPositions(true);
        });
    });

    document.getElementById('watchlist-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        await submitForm('watchlist.create', event.currentTarget, () => loadWatchlist());
    });

    document.getElementById('ttrade-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const result = await postForm('ttrade.create', new FormData(form));
        form.reset();
        setDefaultDates();
        renderTTradeSymbolOptions();
        syncTTradeNameWithSelectedSymbol();
        if (result.status === 'closed') {
            toast(`做T已完成，收益 ${formatMoney(result.profit.profit)}`);
        } else if (result.merged) {
            toast(`已合并未完成记录，均价 ${formatQuoteNumber(result.merged.merged_price)}，数量 ${result.merged.merged_qty}`);
        } else {
            toast('已保存未完成记录，等待后续记录完成做T');
        }
        await loadTTrades();
    });

    document.getElementById('login-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        await postForm('auth.login', new FormData(form));
        form.reset();
        toast('登录成功');
        await initializeAuth();
    });

    document.getElementById('password-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        await postForm('auth.change-password', new FormData(form));
        form.reset();
        toast('密码已更新');
        await initializeAuth();
    });
}

function bindAuthActions() {
    const logoutButton = document.getElementById('logout-button');
    const forceButton = document.getElementById('force-password-change-button');

    if (logoutButton) {
        logoutButton.addEventListener('click', async () => {
            const formData = new FormData();
            await postForm('auth.logout', formData);
            toast('已退出登录');
            await initializeAuth();
        });
    }

    if (forceButton) {
        forceButton.addEventListener('click', async () => {
            const password = window.prompt('请输入当前密码以启用强制修改密码');
            if (password === null) {
                return;
            }

            const formData = new FormData();
            formData.append('password', password);
            await postForm('auth.force-password-change', formData);
            toast('已启用强制修改密码');
            await initializeAuth();
        });
    }
}

function bindPositionControls() {
    const importButton = document.getElementById('positions-import-button');
    const saveButton = document.getElementById('positions-import-save');
    const editForm = document.getElementById('position-edit-form');

    if (importButton) {
        importButton.addEventListener('click', () => {
            openPositionsImportModal();
        });
    }

    document.querySelectorAll('[data-close-positions-import]').forEach((button) => {
        button.addEventListener('click', () => {
            closePositionsImportModal();
        });
    });

    document.querySelectorAll('[data-close-position-edit]').forEach((button) => {
        button.addEventListener('click', () => {
            closePositionEditModal();
        });
    });

    if (saveButton) {
        saveButton.addEventListener('click', async () => {
            await saveImportedPositions();
        });
    }

    if (editForm) {
        editForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            await savePositionEdit(event.currentTarget);
        });
    }
}

function bindTTradeControls() {
    const symbolSelect = document.querySelector('#ttrade-form select[name="symbol"]');
    const nameFilter = document.getElementById('ttrade-name-filter');

    if (symbolSelect) {
        symbolSelect.addEventListener('change', () => {
            syncTTradeNameWithSelectedSymbol();
        });
    }

    if (nameFilter) {
        nameFilter.addEventListener('change', () => {
            state.ttradeFilterName = nameFilter.value;
            renderTTradesTable(state.ttrades || []);
        });
    }

    renderTTradeSymbolOptions();
    renderTTradeNameFilterOptions([]);
}

function bindCalculatorControls() {
    const table = document.getElementById('calculator-table');
    if (!table) {
        return;
    }

    table.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const symbol = target.dataset.symbol;
        if (!symbol) {
            return;
        }

        if (target.matches('[data-calculator-lot]')) {
            const input = target;
            const rawValue = Number.parseInt(input.value || '0', 10);
            const lots = Math.max(1, rawValue || 1);
            state.calculatorInputs[symbol] = {
                ...getCalculatorInput(symbol),
                lots,
            };
            renderCalculatorTable(state.positions);
        }
    });

    table.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const symbol = target.dataset.symbol;
        if (!symbol) {
            return;
        }

        if (target.matches('[data-calculator-lot]')) {
            const input = target;
            const rawValue = Number.parseInt(input.value || '0', 10);
            const lots = Math.max(1, rawValue || 1);
            input.value = String(lots);
            state.calculatorInputs[symbol] = {
                ...getCalculatorInput(symbol),
                lots,
            };
            renderCalculatorTable(state.positions);
            return;
        }

        if (target.matches('[data-calculator-side]')) {
            const select = target;
            state.calculatorInputs[symbol] = {
                ...getCalculatorInput(symbol),
                side: select.value === 'sell' ? 'sell' : 'buy',
            };
            renderCalculatorTable(state.positions);
        }
    });
}

function bindPositionsSorting() {
    document.querySelectorAll('#positions-sort-head [data-sort-key]').forEach((header) => {
        header.addEventListener('click', () => {
            applyPositionsSort(header.dataset.sortKey);
        });

        header.addEventListener('keydown', (event) => {
            if (!['Enter', ' '].includes(event.key)) {
                return;
            }

            event.preventDefault();
            applyPositionsSort(header.dataset.sortKey);
        });
    });

    updatePositionsSortHeaders();
}

function bindRefreshButtons() {
    document.querySelectorAll('[data-refresh]').forEach((button) => {
        button.addEventListener('click', async () => {
            const type = button.dataset.refresh;
            if (type === 'positions') {
                await loadPositions(true);
                startQuotePolling();
            }
            if (type === 'watchlist') {
                await loadWatchlist(true);
                startQuotePolling();
            }
        });
    });
}

function bindRefreshSettings() {
    const refreshSecondsInput = document.getElementById('refresh-seconds');
    const refreshTradingHoursInput = document.getElementById('refresh-trading-hours');
    const calculatorDefaultLotSizeInput = document.getElementById('calculator-default-lot-size');
    const positionAlertGainPercentInput = document.getElementById('position-alert-gain-percent');
    const positionAlertLossPercentInput = document.getElementById('position-alert-loss-percent');
    const enablePositionAlertsButton = document.getElementById('enable-position-alerts');

    if (refreshSecondsInput) {
        refreshSecondsInput.value = String(state.quoteRefreshSeconds);
        refreshSecondsInput.addEventListener('change', async () => {
            const nextValue = Math.max(3, Number.parseInt(refreshSecondsInput.value || '0', 10) || state.quoteRefreshSeconds);
            refreshSecondsInput.value = String(nextValue);
            await saveDisplaySettings({ quoteRefreshSeconds: nextValue });
        });
    }

    if (refreshTradingHoursInput) {
        refreshTradingHoursInput.checked = Boolean(state.refreshOnlyTradingHours);
        refreshTradingHoursInput.addEventListener('change', async () => {
            await saveDisplaySettings({ refreshOnlyTradingHours: refreshTradingHoursInput.checked });
        });
    }

    if (calculatorDefaultLotSizeInput) {
        calculatorDefaultLotSizeInput.value = String(state.calculatorDefaultLotSize);
        calculatorDefaultLotSizeInput.addEventListener('change', async () => {
            const nextValue = Math.max(1, Number.parseInt(calculatorDefaultLotSizeInput.value || '0', 10) || state.calculatorDefaultLotSize);
            calculatorDefaultLotSizeInput.value = String(nextValue);
            await saveDisplaySettings({ calculatorDefaultLotSize: nextValue });
        });
    }

    if (positionAlertGainPercentInput) {
        positionAlertGainPercentInput.value = String(state.positionAlertGainPercent);
        positionAlertGainPercentInput.addEventListener('change', async () => {
            const nextValue = Math.max(0.1, Number(positionAlertGainPercentInput.value || '0') || state.positionAlertGainPercent);
            positionAlertGainPercentInput.value = String(nextValue);
            await saveDisplaySettings({ positionAlertGainPercent: nextValue });
        });
    }

    if (positionAlertLossPercentInput) {
        positionAlertLossPercentInput.value = String(state.positionAlertLossPercent);
        positionAlertLossPercentInput.addEventListener('change', async () => {
            const nextValue = Math.max(0.1, Number(positionAlertLossPercentInput.value || '0') || state.positionAlertLossPercent);
            positionAlertLossPercentInput.value = String(nextValue);
            await saveDisplaySettings({ positionAlertLossPercent: nextValue });
        });
    }

    if (enablePositionAlertsButton) {
        enablePositionAlertsButton.addEventListener('click', async () => {
            const permission = await requestNotificationPermission();
            if (permission === 'granted') {
                await sendCurrentPositionAlerts();
                toast('浏览器通知已启用');
                return;
            }
            if (permission === 'denied') {
                toast('浏览器通知已被拒绝，请在浏览器设置中开启');
                return;
            }
            toast('浏览器通知未启用');
        });
    }
}

async function initializeAuth() {
    const auth = await getJson('auth.status', { skipAuthHandling: true });
    applyAuthState(auth);

    if (state.auth.loggedIn && !state.auth.mustChangePassword) {
        await loadCurrentView();
        startQuotePolling();
    } else {
        stopQuotePolling();
        clearBusinessState();
    }
}

function applyAuthState(auth) {
    state.auth.loggedIn = Boolean(auth?.logged_in);
    state.auth.passwordConfigured = Boolean(auth?.password_configured);
    state.auth.mustChangePassword = Boolean(auth?.must_change_password);

    const authShell = document.getElementById('auth-shell');
    const loginCard = document.getElementById('login-card');
    const passwordCard = document.getElementById('password-card');
    const passwordCardTitle = document.getElementById('password-card-title');
    const passwordCardDesc = document.getElementById('password-card-desc');
    const appContent = document.getElementById('app-content');
    const currentPasswordInput = document.querySelector('#password-form input[name="current_password"]');

    const showPasswordCard = state.auth.mustChangePassword;
    const showLoginCard = !showPasswordCard && !state.auth.loggedIn;
    const showAppContent = state.auth.loggedIn && !showPasswordCard;

    authShell.classList.toggle('hidden', showAppContent);
    loginCard.classList.toggle('hidden', !showLoginCard);
    passwordCard.classList.toggle('hidden', !showPasswordCard);
    appContent.classList.toggle('hidden', !showAppContent);

    if (showPasswordCard) {
        const firstSetup = !state.auth.passwordConfigured;
        passwordCardTitle.textContent = firstSetup ? '设置登录密码' : '修改密码';
        passwordCardDesc.textContent = firstSetup ? '首次使用请先设置登录密码。' : '当前已启用强制修改密码，完成后才能继续使用系统。';
        currentPasswordInput.required = !firstSetup;
        currentPasswordInput.classList.toggle('hidden', firstSetup);
        currentPasswordInput.value = '';
    }

    if (showLoginCard) {
        document.getElementById('login-form').reset();
    }

    if (showAppContent) {
        document.getElementById('password-form').reset();
    }
}

function clearBusinessState() {
    state.positions = [];
    state.watchlist = [];
    state.ttrades = [];
    state.ttradeFilterName = '';
    state.calculatorInputs = {};
    state.positionAlertStateBySymbol = {};
    state.positionAlertsInitialized = false;
    renderPositionsTable([]);
    renderPositionsSummary({});
    renderWatchlistTable([], []);
    renderTTradeNameFilterOptions([]);
    renderTTradesTable([]);
    renderTTradeSummary({ total_profit: 0, open_count: 0, by_symbol: [] });
    renderCalculatorTable([]);
    renderTTradeSymbolOptions();
    syncTTradeNameWithSelectedSymbol();
    closePositionsImportModal();
}

function applyPositionsSort(sortKey) {
    if (!sortKey) {
        return;
    }

    if (state.positionsSort.key === sortKey) {
        state.positionsSort.direction = state.positionsSort.direction === 'asc' ? 'desc' : 'asc';
    } else {
        state.positionsSort.key = sortKey;
        state.positionsSort.direction = defaultSortDirection(sortKey);
    }

    renderPositionsTable(state.positions);
    updatePositionsSortHeaders();
}

function defaultSortDirection(sortKey) {
    return ['symbol', 'name'].includes(sortKey) ? 'asc' : 'desc';
}

function sortPositions(items) {
    const sortedItems = [...items];
    const { key, direction } = state.positionsSort;
    const factor = direction === 'asc' ? 1 : -1;

    sortedItems.sort((left, right) => {
        const leftValue = left?.[key];
        const rightValue = right?.[key];
        const leftMissing = isMissingSortValue(leftValue);
        const rightMissing = isMissingSortValue(rightValue);

        if (leftMissing && rightMissing) {
            return compareStringValues(left.symbol, right.symbol);
        }
        if (leftMissing) {
            return 1;
        }
        if (rightMissing) {
            return -1;
        }

        if (['symbol', 'name'].includes(key)) {
            const result = compareStringValues(leftValue, rightValue);
            if (result !== 0) {
                return result * factor;
            }
            return compareStringValues(left.symbol, right.symbol);
        }

        const result = Number(leftValue) - Number(rightValue);
        if (result !== 0) {
            return result * factor;
        }

        return compareStringValues(left.symbol, right.symbol);
    });

    return sortedItems;
}

function isMissingSortValue(value) {
    return value === null || value === undefined || value === '';
}

function compareStringValues(left, right) {
    return String(left ?? '').localeCompare(String(right ?? ''), 'zh-CN', {
        numeric: true,
        sensitivity: 'base',
    });
}

function updatePositionsSortHeaders() {
    document.querySelectorAll('#positions-sort-head [data-sort-key]').forEach((header) => {
        const isActive = header.dataset.sortKey === state.positionsSort.key;
        header.classList.toggle('is-active', isActive);
        header.setAttribute('aria-sort', isActive
            ? (state.positionsSort.direction === 'asc' ? 'ascending' : 'descending')
            : 'none');

        const indicator = header.querySelector('.sort-indicator');
        if (!indicator) {
            return;
        }

        if (!isActive) {
            indicator.textContent = '↕';
            return;
        }

        indicator.textContent = state.positionsSort.direction === 'asc' ? '↑' : '↓';
    });
}

async function saveDisplaySettings(partialSettings) {
    const refreshSecondsInput = document.getElementById('refresh-seconds');
    const refreshTradingHoursInput = document.getElementById('refresh-trading-hours');
    const calculatorDefaultLotSizeInput = document.getElementById('calculator-default-lot-size');
    const positionAlertGainPercentInput = document.getElementById('position-alert-gain-percent');
    const positionAlertLossPercentInput = document.getElementById('position-alert-loss-percent');
    const previousSettings = {
        quoteRefreshSeconds: state.quoteRefreshSeconds,
        refreshOnlyTradingHours: state.refreshOnlyTradingHours,
        calculatorDefaultLotSize: state.calculatorDefaultLotSize,
        positionAlertGainPercent: state.positionAlertGainPercent,
        positionAlertLossPercent: state.positionAlertLossPercent,
    };
    const nextSettings = {
        quoteRefreshSeconds: partialSettings.quoteRefreshSeconds ?? previousSettings.quoteRefreshSeconds,
        refreshOnlyTradingHours: partialSettings.refreshOnlyTradingHours ?? previousSettings.refreshOnlyTradingHours,
        calculatorDefaultLotSize: partialSettings.calculatorDefaultLotSize ?? previousSettings.calculatorDefaultLotSize,
        positionAlertGainPercent: partialSettings.positionAlertGainPercent ?? previousSettings.positionAlertGainPercent,
        positionAlertLossPercent: partialSettings.positionAlertLossPercent ?? previousSettings.positionAlertLossPercent,
    };

    state.quoteRefreshSeconds = nextSettings.quoteRefreshSeconds;
    state.refreshOnlyTradingHours = nextSettings.refreshOnlyTradingHours;
    state.calculatorDefaultLotSize = nextSettings.calculatorDefaultLotSize;
    state.positionAlertGainPercent = nextSettings.positionAlertGainPercent;
    state.positionAlertLossPercent = nextSettings.positionAlertLossPercent;
    if (refreshSecondsInput) {
        refreshSecondsInput.value = String(state.quoteRefreshSeconds);
    }
    if (refreshTradingHoursInput) {
        refreshTradingHoursInput.checked = Boolean(state.refreshOnlyTradingHours);
    }
    if (calculatorDefaultLotSizeInput) {
        calculatorDefaultLotSizeInput.value = String(state.calculatorDefaultLotSize);
    }
    if (positionAlertGainPercentInput) {
        positionAlertGainPercentInput.value = String(state.positionAlertGainPercent);
    }
    if (positionAlertLossPercentInput) {
        positionAlertLossPercentInput.value = String(state.positionAlertLossPercent);
    }
    updateRefreshInfo();
    startQuotePolling();

    const formData = new FormData();
    formData.append('quote_refresh_seconds', String(nextSettings.quoteRefreshSeconds));
    formData.append('quote_refresh_only_trading_hours', nextSettings.refreshOnlyTradingHours ? '1' : '0');
    formData.append('calculator_default_lot_size', String(nextSettings.calculatorDefaultLotSize));
    formData.append('position_alert_gain_percent', String(nextSettings.positionAlertGainPercent));
    formData.append('position_alert_loss_percent', String(nextSettings.positionAlertLossPercent));

    try {
        const settings = await postForm('settings.refresh.update', formData);
        applyDisplaySettings(settings);
        toast('设置已保存');
    } catch (error) {
        state.quoteRefreshSeconds = previousSettings.quoteRefreshSeconds;
        state.refreshOnlyTradingHours = previousSettings.refreshOnlyTradingHours;
        state.calculatorDefaultLotSize = previousSettings.calculatorDefaultLotSize;
        state.positionAlertGainPercent = previousSettings.positionAlertGainPercent;
        state.positionAlertLossPercent = previousSettings.positionAlertLossPercent;
        if (refreshSecondsInput) {
            refreshSecondsInput.value = String(state.quoteRefreshSeconds);
        }
        if (refreshTradingHoursInput) {
            refreshTradingHoursInput.checked = Boolean(state.refreshOnlyTradingHours);
        }
        if (calculatorDefaultLotSizeInput) {
            calculatorDefaultLotSizeInput.value = String(state.calculatorDefaultLotSize);
        }
        if (positionAlertGainPercentInput) {
            positionAlertGainPercentInput.value = String(state.positionAlertGainPercent);
        }
        if (positionAlertLossPercentInput) {
            positionAlertLossPercentInput.value = String(state.positionAlertLossPercent);
        }
        updateRefreshInfo();
        startQuotePolling();
        throw error;
    }
}

function applyDisplaySettings(settings) {
    state.quoteRefreshSeconds = Number(settings?.quote_refresh_seconds ?? state.quoteRefreshSeconds);
    state.refreshOnlyTradingHours = Boolean(settings?.quote_refresh_only_trading_hours ?? state.refreshOnlyTradingHours);
    state.calculatorDefaultLotSize = Math.max(1, Number(settings?.calculator_default_lot_size ?? state.calculatorDefaultLotSize) || 1);
    state.positionAlertGainPercent = Math.max(0.1, Number(settings?.position_alert_gain_percent ?? state.positionAlertGainPercent) || state.positionAlertGainPercent);
    state.positionAlertLossPercent = Math.max(0.1, Number(settings?.position_alert_loss_percent ?? state.positionAlertLossPercent) || state.positionAlertLossPercent);

    const refreshSecondsInput = document.getElementById('refresh-seconds');
    const refreshTradingHoursInput = document.getElementById('refresh-trading-hours');
    const calculatorDefaultLotSizeInput = document.getElementById('calculator-default-lot-size');
    const positionAlertGainPercentInput = document.getElementById('position-alert-gain-percent');
    const positionAlertLossPercentInput = document.getElementById('position-alert-loss-percent');
    if (refreshSecondsInput) {
        refreshSecondsInput.value = String(state.quoteRefreshSeconds);
    }
    if (refreshTradingHoursInput) {
        refreshTradingHoursInput.checked = Boolean(state.refreshOnlyTradingHours);
    }
    if (calculatorDefaultLotSizeInput) {
        calculatorDefaultLotSizeInput.value = String(state.calculatorDefaultLotSize);
    }
    if (positionAlertGainPercentInput) {
        positionAlertGainPercentInput.value = String(state.positionAlertGainPercent);
    }
    if (positionAlertLossPercentInput) {
        positionAlertLossPercentInput.value = String(state.positionAlertLossPercent);
    }
    updateRefreshInfo();
    renderCalculatorTable(state.positions);
    startQuotePolling();
}

function setDefaultDates() {
    const today = new Date().toISOString().slice(0, 10);
    document.querySelectorAll('input[type="date"]').forEach((input) => {
        input.value = today;
    });
}

async function ensurePositionsLoaded() {
    if (state.positions.length > 0) {
        return;
    }

    const data = await getJson('positions');
    state.positions = Array.isArray(data.positions) ? data.positions : [];
    renderCalculatorTable(state.positions);
    renderTTradeSymbolOptions();
}

function renderTTradeSymbolOptions() {
    const symbolSelect = document.querySelector('#ttrade-form select[name="symbol"]');
    if (!symbolSelect) {
        return;
    }

    const currentValue = symbolSelect.value;
    const options = state.positions.map((item) => {
        const label = item.name ? `${item.name} (${item.symbol})` : item.symbol;
        return `<option value="${escapeHtml(item.symbol)}">${escapeHtml(label)}</option>`;
    }).join('');

    symbolSelect.innerHTML = `<option value="">${state.positions.length > 0 ? '请选择持仓股票' : '暂无持仓可选'}</option>${options}`;
    symbolSelect.value = state.positions.some((item) => item.symbol === currentValue) ? currentValue : '';
}

function syncTTradeNameWithSelectedSymbol() {
    const symbolSelect = document.querySelector('#ttrade-form select[name="symbol"]');
    const nameInput = document.querySelector('#ttrade-form input[name="name"]');
    if (!symbolSelect || !nameInput) {
        return;
    }

    const selected = state.positions.find((item) => item.symbol === symbolSelect.value);
    nameInput.value = selected?.name || '';
}

function renderTTradeNameFilterOptions(items) {
    const filter = document.getElementById('ttrade-name-filter');
    if (!filter) {
        return;
    }

    const currentValue = state.ttradeFilterName;
    const uniqueItems = [];
    const seen = new Set();

    items.forEach((item) => {
        const value = item.name || item.symbol || '';
        if (!value || seen.has(value)) {
            return;
        }
        seen.add(value);
        uniqueItems.push({ value, label: value });
    });

    filter.innerHTML = `<option value="">全部股票</option>${uniqueItems.map((item) => `<option value="${escapeHtml(item.value)}">${escapeHtml(item.label)}</option>`).join('')}`;
    filter.value = uniqueItems.some((item) => item.value === currentValue) ? currentValue : '';
    state.ttradeFilterName = filter.value;
}

function filterTTrades(items) {
    if (!state.ttradeFilterName) {
        return items;
    }

    return items.filter((item) => (item.name || item.symbol || '') === state.ttradeFilterName);
}

async function loadCurrentView() {
    if (!state.auth.loggedIn || state.auth.mustChangePassword) {
        return;
    }

    if (state.currentView === 'positions') {
        await loadPositions();
        return;
    }

    if (state.currentView === 'watchlist') {
        await loadWatchlist();
        return;
    }

    if (state.currentView === 'ttrades') {
        await ensurePositionsLoaded();
        syncTTradeNameWithSelectedSymbol();
        await loadTTrades();
        return;
    }

    if (state.currentView === 'calculator') {
        await loadPositions();
        return;
    }

    if (state.currentView === 'settings') {
        updateRefreshInfo();
    }
}

async function loadPositions(force = false) {
    if (!force && state.currentView !== 'positions' && state.currentView !== 'calculator' && state.positions.length > 0) {
        renderCalculatorTable(state.positions);
        renderTTradeSymbolOptions();
        syncTTradeNameWithSelectedSymbol();
        return;
    }

    const data = await getJson('positions');
    state.positions = Array.isArray(data.positions) ? data.positions : [];
    renderPositionsTable(state.positions);
    renderPositionsSummary(data.summary || {});
    renderCalculatorTable(state.positions);
    renderTTradeSymbolOptions();
    syncTTradeNameWithSelectedSymbol();
    notifyPositionAlerts(state.positions);
}

async function loadWatchlist(force = false) {
    if (!force && state.currentView !== 'watchlist' && state.watchlist.length > 0) {
        return;
    }

    const data = await getJson('watchlist.quotes');
    state.watchlist = Array.isArray(data.items) ? data.items : [];
    renderWatchlistTable(state.watchlist, Array.isArray(data.quotes) ? data.quotes : []);
}

async function loadTTrades() {
    const [recordsData, statsData] = await Promise.all([
        getJson('ttrades'),
        getJson('ttrade.stats'),
    ]);

    state.ttrades = Array.isArray(recordsData.items) ? recordsData.items : [];
    renderTTradeNameFilterOptions(state.ttrades);
    renderTTradesTable(state.ttrades);
    renderTTradeSummary(statsData || { total_profit: 0, open_count: 0, by_symbol: [] });
}

function startQuotePolling() {
    if (!state.auth.loggedIn || state.auth.mustChangePassword) {
        stopQuotePolling();
        return;
    }

    if (state.quoteTimer) {
        clearInterval(state.quoteTimer);
        state.quoteTimer = null;
    }

    restartQuoteCountdown();

    state.quoteTimer = window.setInterval(async () => {
        if (!shouldRefreshQuotesNow()) {
            updateRefreshInfo();
            return;
        }

        if (state.currentView === 'positions') {
            await loadPositions(true);
        } else if (state.currentView === 'watchlist') {
            await loadWatchlist(true);
        } else if (state.currentView === 'ttrades') {
            await ensurePositionsLoaded();
            await loadTTrades();
        } else if (state.currentView === 'calculator') {
            await loadPositions(true);
        } else if (state.currentView === 'settings') {
            updateRefreshInfo();
            return;
        } else {
            return;
        }

        restartQuoteCountdown();
    }, Math.max(3, state.quoteRefreshSeconds) * 1000);
}

function stopQuotePolling() {
    if (state.quoteTimer) {
        clearInterval(state.quoteTimer);
        state.quoteTimer = null;
    }
    stopQuoteCountdown();
    state.quoteCountdownSeconds = null;
    updateRefreshInfo();
}

function shouldRefreshQuotesNow() {
    if (!state.refreshOnlyTradingHours) {
        return true;
    }

    return isTradingTime();
}

function isTradingTime(now = new Date()) {
    const day = now.getDay();
    if (day === 0 || day === 6) {
        return false;
    }

    const minutes = now.getHours() * 60 + now.getMinutes();
    const morningStart = 9 * 60 + 30;
    const morningEnd = 11 * 60 + 30;
    const afternoonStart = 13 * 60;
    const afternoonEnd = 15 * 60;

    return (minutes >= morningStart && minutes <= morningEnd)
        || (minutes >= afternoonStart && minutes <= afternoonEnd);
}

function restartQuoteCountdown() {
    stopQuoteCountdown();
    state.quoteCountdownSeconds = Math.max(3, state.quoteRefreshSeconds);
    updateRefreshInfo();

    state.countdownTimer = window.setInterval(() => {
        if (state.quoteCountdownSeconds === null) {
            return;
        }

        state.quoteCountdownSeconds = Math.max(0, state.quoteCountdownSeconds - 1);
        updateRefreshInfo();

        if (state.quoteCountdownSeconds === 0) {
            state.quoteCountdownSeconds = Math.max(3, state.quoteRefreshSeconds);
            updateRefreshInfo();
        }
    }, 1000);
}

function stopQuoteCountdown() {
    if (state.countdownTimer) {
        clearInterval(state.countdownTimer);
        state.countdownTimer = null;
    }
}

function updateRefreshInfo() {
    const container = document.getElementById('refresh-info');
    if (!container) {
        return;
    }

    if (!state.auth.loggedIn || state.auth.mustChangePassword) {
        container.textContent = '登录后可使用自动刷新。';
        return;
    }

    const inTradingTime = isTradingTime();
    const autoRefreshActive = !state.refreshOnlyTradingHours || inTradingTime;
    const parts = [`${state.refreshOnlyTradingHours ? '仅交易时间' : '全天'}自动刷新，每 ${state.quoteRefreshSeconds} 秒一次`];

    if (autoRefreshActive && state.quoteCountdownSeconds !== null) {
        parts.push(`${state.quoteCountdownSeconds} 秒后刷新`);
    }

    if (state.refreshOnlyTradingHours) {
        parts.push(inTradingTime ? '当前处于交易时间' : '当前不在交易时间');
    }

    container.textContent = parts.join('，');
}

function renderPositionsTable(items) {
    const tbody = document.getElementById('positions-table');
    const sortedItems = sortPositions(items || []);
    tbody.innerHTML = sortedItems.map((item) => `
        <tr>
            <td>${escapeHtml(item.symbol)}</td>
            <td>${escapeHtml(item.name || '')}</td>
            <td>${item.quantity ?? '--'}</td>
            <td>${formatQuoteNumber(item.cost_price)}</td>
            <td>${formatMoney(item.cost_amount)}</td>
            <td>${formatQuoteNumber(item.latest_price)}</td>
            <td class="${profitClass(item.change_percent)}">${formatSignedPercent(item.change_percent)}</td>
            <td>${formatQuoteNumber(item.open)}</td>
            <td>${formatQuoteNumber(item.prev_close)}</td>
            <td>${formatQuoteNumber(item.high)}</td>
            <td>${formatQuoteNumber(item.low)}</td>
            <td>${formatMoney(item.market_value)}</td>
            <td class="${profitClass(item.profit)}">${formatMoney(item.profit)}</td>
            <td class="${profitClass(item.profit_rate)}">${formatSignedPercent(item.profit_rate)}</td>
            <td><div class="actions"><button type="button" onclick="editPosition(${item.id})">编辑</button><button type="button" class="btn-danger" onclick="deletePosition(${item.id})">删除</button></div></td>
        </tr>
    `).join('') || '<tr><td colspan="15" class="muted">暂无持仓</td></tr>';

    updatePositionsSortHeaders();
}

function renderPositionsSummary(summary) {
    const list = [
        ['总成本', formatMoney(summary.total_cost ?? 0)],
        ['总市值', formatMoney(summary.total_market_value ?? 0)],
        ['总浮盈亏', formatMoney(summary.total_profit ?? 0), summary.total_profit],
        ['总收益率', formatSignedPercent(summary.total_profit_rate ?? 0), summary.total_profit_rate],
    ];

    document.getElementById('positions-summary').innerHTML = list
        .map(([label, value, profitValue]) => summaryCard(label, value, profitValue))
        .join('');
}

function renderCalculatorTable(items) {
    const tbody = document.getElementById('calculator-table');
    if (!tbody) {
        return;
    }

    tbody.innerHTML = (items || []).map((item) => {
        const input = getCalculatorInput(item.symbol);
        const result = calculatePositionScenario(item, input);
        const currentProfitRate = Number(item?.profit_rate);
        const currentProfitRateText = Number.isFinite(currentProfitRate) ? formatSignedPercent(currentProfitRate) : '--';
        const currentProfitRateClass = Number.isFinite(currentProfitRate) ? profitClass(currentProfitRate) : '';
        const newProfitRateText = result.available ? formatSignedPercent(result.newProfitRate) : '--';
        const newProfitRateClass = result.available ? profitClass(result.newProfitRate) : '';
        const tradeAmountText = result.available ? formatMoney(result.tradeAmount) : '--';
        const newQtyText = result.available ? `${result.newQty}` : '--';
        const newCostAmountText = result.available ? formatMoney(result.newCostAmount) : '--';
        const newCostPriceText = result.available ? formatQuoteNumber(result.newCostPrice) : '--';
        const note = result.note ? `<span class="calculator-note">${escapeHtml(result.note)}</span>` : '';

        return `
        <tr>
            <td>${escapeHtml(item.symbol)}</td>
            <td>${escapeHtml(item.name || '')}</td>
            <td>${item.quantity ?? '--'}</td>
            <td>${formatQuoteNumber(item.cost_price)}</td>
            <td>${formatMoney(item.cost_amount)}</td>
            <td>${formatQuoteNumber(item.latest_price)}</td>
            <td class="${currentProfitRateClass}">${currentProfitRateText}</td>
            <td><input class="calculator-lot-input" data-calculator-lot data-symbol="${escapeHtml(item.symbol)}" type="number" min="1" step="1" value="${input.lots}"></td>
            <td><select class="calculator-side-select" data-calculator-side data-symbol="${escapeHtml(item.symbol)}"><option value="buy" ${input.side === 'buy' ? 'selected' : ''}>买入</option><option value="sell" ${input.side === 'sell' ? 'selected' : ''}>卖出</option></select></td>
            <td>${tradeAmountText}${note}</td>
            <td>${newQtyText}</td>
            <td>${newCostAmountText}</td>
            <td>${newCostPriceText}</td>
            <td class="${newProfitRateClass}">${newProfitRateText}</td>
        </tr>`;
    }).join('') || '<tr><td colspan="14" class="muted">暂无持仓可试算</td></tr>';
}

function getCalculatorInput(symbol) {
    const existing = state.calculatorInputs[symbol];
    if (existing) {
        return existing;
    }

    const nextInput = {
        lots: state.calculatorDefaultLotSize,
        side: 'buy',
    };
    state.calculatorInputs[symbol] = nextInput;
    return nextInput;
}

function calculatePositionScenario(item, input) {
    const latestPrice = Number(item?.latest_price);
    const currentQty = Number(item?.quantity ?? 0);
    const currentCostAmount = Number(item?.cost_amount ?? 0);
    const lots = Math.max(1, Number(input?.lots ?? state.calculatorDefaultLotSize) || state.calculatorDefaultLotSize);
    const tradeQty = lots * 100;

    if (!Number.isFinite(latestPrice) || latestPrice <= 0) {
        return {
            available: false,
            tradeAmount: null,
            tradeQty,
            newQty: null,
            newCostAmount: null,
            newCostPrice: null,
            newProfitRate: null,
            note: '缺少现价，暂不可试算',
        };
    }

    if (input?.side === 'sell') {
        const soldQty = Math.min(tradeQty, currentQty);
        const tradeAmount = latestPrice * soldQty;
        const newQty = currentQty - soldQty;
        const newCostAmount = newQty > 0
            ? Math.max(0, currentCostAmount - tradeAmount)
            : 0;
        const marketValue = newQty * latestPrice;
        const newProfit = marketValue - newCostAmount;

        return {
            available: true,
            tradeAmount: roundMoney(tradeAmount),
            tradeQty: soldQty,
            newQty,
            newCostAmount: roundMoney(newCostAmount),
            newCostPrice: newQty > 0 ? roundQuote(newCostAmount / newQty, 4) : 0,
            newProfitRate: newCostAmount > 0 ? roundQuote((newProfit / newCostAmount) * 100, 3) : 0,
            note: soldQty < tradeQty ? '已按当前持仓数量上限计算' : '',
        };
    }

    const tradeAmount = latestPrice * tradeQty;
    const newQty = currentQty + tradeQty;
    const newCostAmount = currentCostAmount + tradeAmount;
    const marketValue = newQty * latestPrice;
    const newProfit = marketValue - newCostAmount;

    return {
        available: true,
        tradeAmount: roundMoney(tradeAmount),
        tradeQty,
        newQty,
        newCostAmount: roundMoney(newCostAmount),
        newCostPrice: newQty > 0 ? roundQuote(newCostAmount / newQty, 4) : 0,
        newProfitRate: newCostAmount > 0 ? roundQuote((newProfit / newCostAmount) * 100, 3) : 0,
        note: '',
    };
}

function openPositionsImportModal() {
    const modal = document.getElementById('positions-import-modal');
    const textarea = document.getElementById('positions-import-textarea');
    if (!modal || !textarea) {
        return;
    }

    textarea.value = state.positions.map((item) => {
        const fields = [
            item.symbol,
            item.name || '',
            Number(item.quantity ?? 0),
            Number(item.cost_price ?? 0),
        ];
        return fields.join(',');
    }).join('\n');
    modal.classList.remove('hidden');
}

function closePositionsImportModal() {
    const modal = document.getElementById('positions-import-modal');
    if (!modal) {
        return;
    }

    modal.classList.add('hidden');
}

async function saveImportedPositions() {
    const textarea = document.getElementById('positions-import-textarea');
    if (!textarea) {
        return;
    }

    const formData = new FormData();
    formData.append('payload', textarea.value);
    const result = await postForm('positions.import', formData);
    closePositionsImportModal();
    toast(`已导入 ${result.count} 条持仓`);
    await loadPositions(true);
}

async function editPosition(id) {
    const current = state.positions.find((item) => Number(item.id) === Number(id));
    if (!current) {
        toast('持仓记录不存在');
        return;
    }

    const modal = document.getElementById('position-edit-modal');
    const form = document.getElementById('position-edit-form');
    if (!modal || !(form instanceof HTMLFormElement)) {
        return;
    }

    const idInput = form.querySelector('input[name="id"]');
    const symbolInput = form.querySelector('input[name="symbol"]');
    const nameInput = form.querySelector('input[name="name"]');
    const quantityInput = form.querySelector('input[name="quantity"]');
    const costPriceInput = form.querySelector('input[name="cost_price"]');
    if (!idInput || !symbolInput || !nameInput || !quantityInput || !costPriceInput) {
        return;
    }

    idInput.value = String(current.id);
    symbolInput.value = current.symbol || '';
    nameInput.value = current.name || '';
    quantityInput.value = String(current.quantity ?? '');
    costPriceInput.value = String(current.cost_price ?? '');
    modal.classList.remove('hidden');
}

function closePositionEditModal() {
    const modal = document.getElementById('position-edit-modal');
    const form = document.getElementById('position-edit-form');
    if (!modal || !(form instanceof HTMLFormElement)) {
        return;
    }

    form.reset();
    modal.classList.add('hidden');
}

async function savePositionEdit(form) {
    const idInput = form.querySelector('input[name="id"]');
    const symbolInput = form.querySelector('input[name="symbol"]');
    const nameInput = form.querySelector('input[name="name"]');
    const quantityInput = form.querySelector('input[name="quantity"]');
    const costPriceInput = form.querySelector('input[name="cost_price"]');
    if (!idInput || !symbolInput || !nameInput || !quantityInput || !costPriceInput) {
        return;
    }

    const formData = new FormData();
    formData.append('id', String(idInput.value || ''));
    formData.append('symbol', String(symbolInput.value || '').trim());
    formData.append('name', String(nameInput.value || '').trim());
    formData.append('quantity', String(quantityInput.value || '').trim());
    formData.append('cost_price', String(costPriceInput.value || '').trim());
    await postForm('position.update', formData);
    closePositionEditModal();
    toast('持仓已更新');
    await loadPositions(true);
}

async function deletePosition(id) {
    await deleteItem('position.delete', id, () => loadPositions(true));
}

function renderWatchlistTable(items, quotes) {
    const quoteMap = Object.fromEntries((quotes || []).map((item) => [item.symbol, item]));
    const tbody = document.getElementById('watchlist-table');
    tbody.innerHTML = items.map((item) => {
        const quote = quoteMap[item.symbol] || {};
        return `
        <tr>
            <td>${escapeHtml(item.symbol)}</td>
            <td>${escapeHtml(quote.name || item.name || '')}</td>
            <td>${quote.price ?? '--'}</td>
            <td class="${profitClass(quote.change)}">${quote.change ?? '--'}</td>
            <td class="${profitClass(quote.change_percent)}">${quote.change_percent ?? '--'}${quote.change_percent === undefined ? '' : '%'}</td>
            <td>${escapeHtml(quote.time || '--')}</td>
            <td><div class="actions"><button class="btn-danger" onclick="deleteItem('watchlist.delete', ${item.id}, loadWatchlist)">删除</button></div></td>
        </tr>`;
    }).join('') || '<tr><td colspan="7" class="muted">暂无自选股票</td></tr>';
}

function renderTTradeSummary(stats) {
    const list = [
        ['累计收益', formatMoney(stats.total_profit ?? 0), stats.total_profit ?? 0],
        ['未完成记录', `${stats.open_count ?? 0}`],
        ['已统计股票', `${(stats.by_symbol || []).length}`],
    ];
    (stats.by_symbol || []).forEach((item) => {
        list.push([item.symbol, formatMoney(item.profit), item.profit]);
    });
    document.getElementById('ttrade-summary').innerHTML = list.map(([label, value, profitValue]) => summaryCard(label, value, profitValue)).join('');
}

function renderTTradesTable(items) {
    const tbody = document.getElementById('ttrades-table');
    const filteredItems = filterTTrades(items || []);
    tbody.innerHTML = filteredItems.map((item) => {
        const isOpen = item.status === 'open';
        const estimate = isOpen ? item.estimate || null : null;
        const firstSide = item.first_side === 'buy' ? '买入' : '卖出';
        const secondSide = item.second_side === 'buy' ? '买入' : (item.second_side === 'sell' ? '卖出' : '--');
        const estimateSideLabel = estimate?.second_side === 'buy' ? '买入' : '卖出';
        const firstRecord = `${escapeHtml(item.first_date || '--')} ${firstSide} ${formatQuoteNumber(item.first_price)} × ${item.first_qty ?? '--'}`;
        const secondRecord = isOpen
            ? renderOpenTTradeSecondRecord(estimate, estimateSideLabel)
            : `${escapeHtml(item.second_date || '--')} ${secondSide} ${formatQuoteNumber(item.second_price)} × ${item.second_qty ?? '--'}`;
        const profitValue = isOpen ? estimate?.profit : item.profit;
        const profitText = isOpen
            ? renderOpenTTradeProfit(estimate)
            : formatMoney(item.profit);
        const statusText = isOpen ? '未完成' : '已完成';
        const statusClass = isOpen ? 'ttrade-status ttrade-status-open' : 'ttrade-status ttrade-status-closed';
        const rowClass = isOpen ? 'ttrade-row-open' : '';
        return `
        <tr class="${rowClass}">
            <td><span class="${statusClass}">${statusText}</span></td>
            <td>${escapeHtml(item.symbol)}</td>
            <td>${escapeHtml(item.name || '')}</td>
            <td>${firstRecord}</td>
            <td>${secondRecord}</td>
            <td class="${profitClass(profitValue)}">${profitText}</td>
            <td>${escapeHtml(item.note || '')}</td>
            <td><div class="actions">${isOpen ? `<button type="button" onclick="estimateOpenTTrade(${item.id})">试算</button>` : ''}<button class="btn-danger" onclick="deleteItem('ttrade.delete', ${item.id}, loadTTrades)">删除</button></div></td>
        </tr>
    `;
    }).join('') || '<tr><td colspan="8" class="muted">暂无做T记录</td></tr>';
}

function renderOpenTTradeSecondRecord(estimate, estimateSideLabel) {
    if (!estimate) {
        return '<span class="ttrade-waiting">等待后续记录完成做T</span>';
    }

    const quoteTime = estimate.quote_time ? `（${escapeHtml(estimate.quote_time)}）` : '';
    return `<span class="ttrade-waiting">等待后续记录完成做T</span><br><span class="muted">按相反方向当前价试算：${estimateSideLabel} ${formatQuoteNumber(estimate.second_price)} × ${estimate.second_qty}${quoteTime}</span>`;
}

function renderOpenTTradeProfit(estimate) {
    if (!estimate) {
        return '--';
    }

    return `<div>${formatMoney(estimate.profit)}</div><div class="muted">按相反方向当前价${estimate.second_side === 'buy' ? '买入' : '卖出'}完成</div>`;
}

async function estimateOpenTTrade(id) {
    const priceText = window.prompt('请输入试算价格');
    if (priceText === null) {
        return;
    }

    const price = Number(priceText);
    if (!Number.isFinite(price) || price <= 0) {
        toast('试算价格必须大于 0');
        return;
    }

    const formData = new FormData();
    formData.append('id', String(id));
    formData.append('price', String(price));
    const result = await postForm('ttrade.estimate', formData);
    const actionLabel = result.second_side === 'buy' ? '买入' : '卖出';
    toast(`按 ${formatQuoteNumber(result.second_price)} ${actionLabel} 完成，预计收益 ${formatMoney(result.profit)}`);
}

async function submitForm(action, form, reload) {
    await postForm(action, new FormData(form));
    form.reset();
    setDefaultDates();
    if (typeof reload === 'function') {
        await reload();
    }
    toast('保存成功');
}

async function deleteItem(action, id, reload) {
    if (!window.confirm('确认删除这条记录吗？')) {
        return;
    }
    const formData = new FormData();
    formData.append('id', id);
    await postForm(action, formData);
    toast('删除成功');
    await reload();
}

function summaryCard(label, value, profitValue = null) {
    const extraClass = profitValue === null ? '' : profitClass(profitValue);
    return `
        <div class="summary-item">
            <label>${escapeHtml(label)}</label>
            <strong class="${extraClass}">${escapeHtml(String(value))}</strong>
        </div>
    `;
}

function profitClass(value) {
    const numeric = Number(value ?? 0);
    if (numeric > 0) {
        return 'profit-positive';
    }
    if (numeric < 0) {
        return 'profit-negative';
    }
    return '';
}

function notifyPositionAlerts(positions) {
    const gainThreshold = Math.max(0.1, Number(state.positionAlertGainPercent) || 5);
    const lossThreshold = Math.max(0.1, Number(state.positionAlertLossPercent) || 5);
    const nextStates = buildPositionAlertStateMap(positions, gainThreshold, lossThreshold);

    if (!state.positionAlertsInitialized) {
        state.positionAlertStateBySymbol = nextStates;
        state.positionAlertsInitialized = true;
        return;
    }

    if (!('Notification' in window)) {
        state.positionAlertStateBySymbol = nextStates;
        return;
    }

    const permission = Notification.permission;
    if (permission !== 'granted') {
        state.positionAlertStateBySymbol = nextStates;
        return;
    }

    positions.forEach((item) => {
        const symbol = String(item?.symbol || '');
        if (!symbol) {
            return;
        }

        const nextState = nextStates[symbol] || null;
        const previousState = state.positionAlertStateBySymbol[symbol] || null;
        if (nextState === null || nextState === previousState) {
            return;
        }

        sendPositionAlert(item, nextState, gainThreshold, lossThreshold);
    });

    state.positionAlertStateBySymbol = nextStates;
}

function buildPositionAlertStateMap(positions, gainThreshold, lossThreshold) {
    const states = {};

    (positions || []).forEach((item) => {
        const symbol = String(item?.symbol || '');
        const changePercent = Number(item?.change_percent);
        if (!symbol) {
            return;
        }

        if (!Number.isFinite(changePercent)) {
            states[symbol] = null;
            return;
        }

        if (changePercent >= gainThreshold) {
            states[symbol] = 'gain';
            return;
        }

        if (changePercent <= -lossThreshold) {
            states[symbol] = 'loss';
            return;
        }

        states[symbol] = null;
    });

    return states;
}

async function requestNotificationPermission() {
    if (!('Notification' in window)) {
        return 'unsupported';
    }

    if (Notification.permission !== 'default') {
        return Notification.permission;
    }

    try {
        return await Notification.requestPermission();
    } catch {
        return 'denied';
    }
}

async function sendCurrentPositionAlerts() {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return;
    }

    if (state.positions.length === 0) {
        await loadPositions(true);
        return;
    }

    const gainThreshold = Math.max(0.1, Number(state.positionAlertGainPercent) || 5);
    const lossThreshold = Math.max(0.1, Number(state.positionAlertLossPercent) || 5);
    const nextStates = buildPositionAlertStateMap(state.positions, gainThreshold, lossThreshold);

    state.positions.forEach((item) => {
        const symbol = String(item?.symbol || '');
        const nextState = symbol ? (nextStates[symbol] || null) : null;
        if (nextState === null) {
            return;
        }

        sendPositionAlert(item, nextState, gainThreshold, lossThreshold);
    });

    state.positionAlertStateBySymbol = nextStates;
    state.positionAlertsInitialized = true;
}

function sendPositionAlert(item, type, gainThreshold, lossThreshold) {
    const symbol = String(item?.symbol || '');
    const name = String(item?.name || '');
    const changePercent = Number(item?.change_percent);
    const titlePrefix = type === 'gain' ? '涨幅提醒' : '跌幅提醒';
    const thresholdText = type === 'gain'
        ? `达到涨幅提醒 ${gainThreshold.toFixed(1)}%`
        : `达到跌幅提醒 -${lossThreshold.toFixed(1)}%`;
    const body = [
        name ? `${name} (${symbol})` : symbol,
        `当前涨跌幅 ${formatSignedPercent(changePercent)}`,
        thresholdText,
    ].join('，');

    new Notification(titlePrefix, {
        body,
        icon: 'assets/favicon.svg',
        tag: `${symbol}-${type}`,
    });
}

function formatMoney(value) {
    const number = Number(value ?? 0);
    return `¥${number.toFixed(2)}`;
}

function formatQuoteNumber(value, digits = 3) {
    if (value === null || value === undefined || value === '') {
        return '--';
    }

    const number = Number(value);
    if (!Number.isFinite(number)) {
        return '--';
    }

    return number.toFixed(digits);
}

function formatSignedPercent(value) {
    if (value === null || value === undefined || value === '') {
        return '--';
    }

    const number = Number(value);
    if (!Number.isFinite(number)) {
        return '--';
    }

    return `${number > 0 ? '+' : ''}${number.toFixed(3)}%`;
}

function roundMoney(value) {
    return Math.round(Number(value ?? 0) * 100) / 100;
}

function roundQuote(value, digits = 3) {
    const factor = 10 ** digits;
    return Math.round(Number(value ?? 0) * factor) / factor;
}

async function getJson(action, options = {}) {
    const response = await fetch(`index.php?action=${encodeURIComponent(action)}`);
    const payload = await parseJsonResponse(response);
    if (!payload.success) {
        await handleApiFailure(response, payload, options);
    }
    return payload.data;
}

async function postForm(action, formData, options = {}) {
    const response = await fetch(`index.php?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        body: formData,
    });
    const payload = await parseJsonResponse(response);
    if (!payload.success) {
        await handleApiFailure(response, payload, options);
    }
    return payload.data;
}

async function parseJsonResponse(response) {
    const raw = await response.text();
    if (raw.trim() === '') {
        throw new Error('接口返回为空');
    }

    try {
        return JSON.parse(raw);
    } catch (error) {
        throw new Error('接口返回的不是有效 JSON');
    }
}

async function handleApiFailure(response, payload, options = {}) {
    const message = payload.message || '请求失败';
    const mustChangePassword = Boolean(payload?.data?.must_change_password);
    const unauthorized = response.status === 401 || response.status === 403;

    if (!options.skipAuthHandling && (unauthorized || mustChangePassword)) {
        if (mustChangePassword) {
            state.auth.mustChangePassword = true;
            state.auth.loggedIn = true;
        } else if (response.status === 401) {
            state.auth.loggedIn = false;
            state.auth.mustChangePassword = false;
        }
        applyAuthState({
            logged_in: state.auth.loggedIn,
            password_configured: state.auth.passwordConfigured,
            must_change_password: state.auth.mustChangePassword,
        });
        stopQuotePolling();
        clearBusinessState();
    }

    toast(message);
    throw new Error(message);
}

function toast(message) {
    const element = document.getElementById('toast');
    element.textContent = message;
    element.classList.remove('hidden');
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => element.classList.add('hidden'), 2400);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
