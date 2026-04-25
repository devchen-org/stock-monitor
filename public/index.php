<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

routeRequest($pdo, $config);

$appSettingsRepository = new AppSettingsRepository($pdo);
$appSettings = formatAppSettings($appSettingsRepository->get(), $config);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>股票工具</title>
    <link rel="shortcut icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <div>
            <h1>股票工具</h1>
            <p>持仓、自选、做T记录、计算器</p>
        </div>
    </header>

    <section class="auth-shell" id="auth-shell">
        <section class="card auth-card hidden" id="login-card">
            <h2>登录</h2>
            <form id="login-form" class="form-grid compact">
                <input name="password" type="password" placeholder="请输入登录密码" required>
                <button type="submit" class="primary span-2">登录</button>
            </form>
        </section>

        <section class="card auth-card hidden" id="password-card">
            <h2 id="password-card-title">修改密码</h2>
            <p class="muted" id="password-card-desc">设置完成后即可进入系统。</p>
            <form id="password-form" class="form-grid compact">
                <input id="current-password-input" name="current_password" type="password" placeholder="当前密码">
                <input name="new_password" type="password" placeholder="新密码（至少 6 位）" required>
                <input name="confirm_password" type="password" placeholder="确认新密码" required>
                <button type="submit" class="primary span-2">保存新密码</button>
            </form>
        </section>
    </section>

    <div id="app-content" class="hidden">
        <nav class="tabs" id="tabs">
            <button class="tab active" data-view="positions">我的持仓</button>
            <button class="tab" data-view="watchlist">我的自选</button>
            <button class="tab" data-view="ttrades">做T记录</button>
            <button class="tab" data-view="calculator">计算器</button>
            <button class="tab" data-view="settings">设置</button>
        </nav>

    <main>
        <section class="view active" data-view="positions">
            <section class="card">
                <div class="card-header positions-header">
                    <div class="positions-header-main">
                        <h2>当前持仓</h2>
                        <div class="refresh-info" id="refresh-info"></div>
                    </div>
                    <div class="auth-actions">
                        <button type="button" id="positions-import-button">导入持仓</button>
                        <button type="button" data-refresh="positions">立即刷新</button>
                    </div>
                </div>
                <div class="table-wrap"><table><thead><tr id="positions-sort-head"><th data-sort-key="symbol" role="button" tabindex="0" aria-sort="none"><span class="sort-label">代码</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th data-sort-key="name" role="button" tabindex="0" aria-sort="none"><span class="sort-label">名称</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th data-sort-key="quantity" role="button" tabindex="0" aria-sort="none"><span class="sort-label">数量</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th data-sort-key="cost_price" role="button" tabindex="0" aria-sort="none"><span class="sort-label">成本价</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th data-sort-key="cost_amount" role="button" tabindex="0" aria-sort="none"><span class="sort-label">总成本</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th data-sort-key="latest_price" role="button" tabindex="0" aria-sort="none"><span class="sort-label">现价</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th data-sort-key="change_percent" role="button" tabindex="0" aria-sort="none"><span class="sort-label">涨跌幅</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th data-sort-key="open" role="button" tabindex="0" aria-sort="none"><span class="sort-label">今开</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th data-sort-key="prev_close" role="button" tabindex="0" aria-sort="none"><span class="sort-label">昨收</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th data-sort-key="high" role="button" tabindex="0" aria-sort="none"><span class="sort-label">最高</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th data-sort-key="low" role="button" tabindex="0" aria-sort="none"><span class="sort-label">最低</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th data-sort-key="market_value" role="button" tabindex="0" aria-sort="none"><span class="sort-label">市值</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th data-sort-key="profit" role="button" tabindex="0" aria-sort="none"><span class="sort-label">浮盈亏</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th data-sort-key="profit_rate" role="button" tabindex="0" aria-sort="none"><span class="sort-label">收益率</span><span class="sort-indicator" aria-hidden="true">↕</span></th><th>操作</th></tr></thead><tbody id="positions-table"></tbody></table></div>
            </section>
            <div class="grid two-columns">
                <section class="card">
                    <h2>新增持仓</h2>
                    <form id="position-form" class="form-grid compact">
                        <input name="symbol" placeholder="股票代码，如 600000 / SH600000" required>
                        <input name="name" placeholder="股票名称">
                        <input name="quantity" type="number" step="1" placeholder="数量" required>
                        <input name="cost_price" type="number" step="0.0001" placeholder="成本价" required>
                        <button type="submit" class="primary span-2">保存持仓</button>
                    </form>
                </section>
                <section class="card">
                    <h2>持仓汇总</h2>
                    <div id="positions-summary" class="summary-grid"></div>
                </section>
            </div>
        </section>

        <section class="view" data-view="watchlist">
            <div class="grid two-columns">
                <section class="card">
                    <h2>新增自选</h2>
                    <form id="watchlist-form" class="form-grid compact">
                        <input name="symbol" placeholder="股票代码" required>
                        <input name="name" placeholder="股票名称">
                        <button type="submit" class="primary span-2">加入自选</button>
                    </form>
                </section>
                <section class="card">
                    <h2>行情说明</h2>
                    <p class="muted">页面会定时刷新当前自选股票行情，支持直接输入 600000、000001、SH600000、SZ000001。</p>
                </section>
            </div>
            <section class="card">
                <div class="card-header">
                    <h2>自选列表</h2>
                    <button type="button" data-refresh="watchlist">立即刷新</button>
                </div>
                <div class="table-wrap"><table><thead><tr><th>代码</th><th>名称</th><th>现价</th><th>涨跌</th><th>涨跌幅</th><th>更新时间</th><th>操作</th></tr></thead><tbody id="watchlist-table"></tbody></table></div>
            </section>
        </section>

        <section class="view" data-view="ttrades">
            <div class="grid two-columns">
                <section class="card">
                    <h2>记录做T</h2>
                    <form id="ttrade-form" class="form-grid">
                        <select name="symbol" required>
                            <option value="">请选择持仓股票</option>
                        </select>
                        <input name="name" placeholder="股票名称">
                        <select name="side" required>
                            <option value="buy">买入</option>
                            <option value="sell">卖出</option>
                        </select>
                        <input name="price" type="number" step="0.001" placeholder="成交价" required>
                        <input name="quantity" type="number" step="1" placeholder="数量" required>
                        <input name="trade_date" type="date" required>
                        <input name="note" placeholder="备注">
                        <button type="submit" class="primary span-2">保存本次记录</button>
                    </form>
                    <p class="muted">同一股票第一次提交会创建待完成记录；后续同向记录会自动合并均价与数量，提交相反方向后自动闭合并计算做T收益。未完成记录会在下方高亮显示。</p>
                </section>
                <section class="card">
                    <h2>做T收益统计</h2>
                    <div id="ttrade-summary" class="summary-grid"></div>
                </section>
            </div>
            <section class="card">
                <div class="card-header">
                    <h2>做T流水</h2>
                    <label class="filter-field">
                        <span>股票筛选</span>
                        <select id="ttrade-name-filter">
                            <option value="">全部股票</option>
                        </select>
                    </label>
                </div>
                <div class="table-wrap"><table><thead><tr><th>状态</th><th>代码</th><th>名称</th><th>首次记录</th><th>第二次记录</th><th>收益</th><th>备注</th><th>操作</th></tr></thead><tbody id="ttrades-table"></tbody></table></div>
            </section>
        </section>

        <section class="view" data-view="calculator">
            <section class="card">
                <div class="card-header">
                    <div>
                        <h2>持仓试算</h2>
                        <p class="muted">基于当前持仓和现价，试算买入或卖出 N 手后的持仓成本。</p>
                    </div>
                </div>
                <div class="table-wrap"><table><thead><tr><th>代码</th><th>名称</th><th>数量</th><th>成本价</th><th>总成本</th><th>现价</th><th>收益率</th><th>N 手</th><th>方向</th><th>成交金额</th><th>新数量</th><th>新总成本</th><th>新成本价</th><th>新收益率</th></tr></thead><tbody id="calculator-table"></tbody></table></div>
            </section>
        </section>

        <section class="view" data-view="settings">
            <div class="grid two-columns settings-grid">
                <section class="card">
                    <h2>刷新设置</h2>
                    <p class="muted">可随时调整自动刷新频率，并控制是否仅在交易时间刷新。</p>
                    <div class="refresh-controls settings-refresh-controls">
                        <label class="refresh-field">
                            <span>频率（秒）</span>
                            <input id="refresh-seconds" type="number" min="3" step="1" value="<?= (int) $appSettings['quote_refresh_seconds'] ?>">
                        </label>
                        <label class="refresh-checkbox">
                            <input id="refresh-trading-hours" type="checkbox" <?= !empty($appSettings['quote_refresh_only_trading_hours']) ? 'checked' : '' ?>>
                            <span>仅交易时间刷新</span>
                        </label>
                        <label class="refresh-field">
                            <span>默认 N 手</span>
                            <input id="calculator-default-lot-size" type="number" min="1" step="1" value="<?= (int) $appSettings['calculator_default_lot_size'] ?>">
                        </label>
                        <label class="refresh-field">
                            <span>涨幅提醒（%）</span>
                            <input id="position-alert-gain-percent" type="number" min="0.1" step="0.1" value="<?= (float) $appSettings['position_alert_gain_percent'] ?>">
                        </label>
                        <label class="refresh-field">
                            <span>跌幅提醒（%）</span>
                            <input id="position-alert-loss-percent" type="number" min="0.1" step="0.1" value="<?= (float) $appSettings['position_alert_loss_percent'] ?>">
                        </label>
                        <div class="auth-actions">
                            <button type="button" id="enable-position-alerts">启用浏览器通知</button>
                        </div>
                    </div>
                </section>
                <section class="card">
                    <h2>账户操作</h2>
                    <p class="muted">可以主动触发强制修改密码，或退出当前登录状态。</p>
                    <div class="auth-actions settings-auth-actions" id="auth-actions">
                        <button type="button" id="force-password-change-button">强制修改密码</button>
                        <button type="button" id="logout-button">退出登录</button>
                    </div>
                </section>
            </div>
        </section>
    </main>
    </div>
</div>

<div id="positions-import-modal" class="modal hidden">
    <div class="modal-backdrop" data-close-positions-import></div>
    <div class="modal-card card">
        <div class="card-header">
            <div>
                <h2>导入持仓</h2>
                <p class="muted">编辑 TXT 后保存，将整体更新当前持仓；一行一个股票，格式：代码,名称,数量,成本价。</p>
            </div>
            <button type="button" data-close-positions-import>关闭</button>
        </div>
        <textarea id="positions-import-textarea" class="json-textarea" spellcheck="false"></textarea>
        <div class="auth-actions">
            <button type="button" data-close-positions-import>取消</button>
            <button type="button" id="positions-import-save" class="primary">保存导入</button>
        </div>
    </div>
</div>
<div id="position-edit-modal" class="modal hidden">
    <div class="modal-backdrop" data-close-position-edit></div>
    <div class="modal-card card">
        <div class="card-header">
            <div>
                <h2>编辑持仓</h2>
                <p class="muted">仅可修改持仓数量和成本价。</p>
            </div>
            <button type="button" data-close-position-edit>关闭</button>
        </div>
        <form id="position-edit-form" class="form-grid compact">
            <input type="hidden" name="id">
            <input type="text" name="symbol" placeholder="股票代码" readonly>
            <input type="text" name="name" placeholder="股票名称" readonly>
            <input name="quantity" type="number" step="1" placeholder="数量" required>
            <input name="cost_price" type="number" step="0.0001" placeholder="成本价" required>
            <div class="auth-actions span-2">
                <button type="button" data-close-position-edit>取消</button>
                <button type="submit" class="primary">保存修改</button>
            </div>
        </form>
    </div>
</div>
<div id="toast" class="toast hidden"></div>
<script>window.APP_CONFIG = <?= json_encode(['quoteRefreshSeconds' => (int) $appSettings['quote_refresh_seconds'], 'quoteRefreshOnlyTradingHours' => (bool) $appSettings['quote_refresh_only_trading_hours'], 'calculatorDefaultLotSize' => (int) $appSettings['calculator_default_lot_size'], 'positionAlertGainPercent' => (float) $appSettings['position_alert_gain_percent'], 'positionAlertLossPercent' => (float) $appSettings['position_alert_loss_percent']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="assets/app.js"></script>
</body>
</html>
