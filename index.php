<?php
require_once __DIR__ . '/functions.php';
auth_start_session();

if (!auth_is_logged_in()) { header('Location: login.php'); exit; }

$cfg = pmg_config();
date_default_timezone_set($cfg['dashboard']['timezone'] ?? 'UTC');

$me      = auth_current_user();
$refresh = (int)($cfg['dashboard']['refresh_seconds'] ?? 30);
$title   = htmlspecialchars(t('app.title'), ENT_QUOTES, 'UTF-8');

$validPeriods  = PMG_PERIODS;
$defaultPeriod = pmg_validate_period(null);
$initialPeriod = pmg_validate_period($_GET['period'] ?? null);

$loggedName = htmlspecialchars($me['name'] ?? '', ENT_QUOTES, 'UTF-8');
$loggedUser = htmlspecialchars($me['username'] ?? '', ENT_QUOTES, 'UTF-8');
$isAdmin    = ($me['role'] ?? '') === 'admin';

$initialPeriodLabel = pmg_period_label($initialPeriod);
$topLimit           = PMG_TOP_LIMIT;
$currentLang        = lang_current();

$jsStrings = [
    'connected'    => t('dash.status.connected'),
    'loading'      => t('dash.status.loading'),
    'error'        => t('dash.status.error'),
    'open'         => t('dash.port.open'),
    'closed'       => t('dash.port.closed'),
    'hour'         => t('dash.chart.hour'),
    'received'     => t('dash.chart.received'),
    'spam'         => t('dash.chart.spam'),
    'spamRate'     => t('dash.chart.spam_rate'),
    'pregreetRate' => t('dash.chart.pregreet_rate'),
    'topEmpty'     => t('dash.top.empty'),
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?></title>
<link rel="icon" href="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/proxmox.svg">
<link rel="stylesheet" href="assets/dashboard.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

<div class="container">

    <header>
        <div class="brand">
            <img src="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/proxmox.svg"
                 alt="Proxmox"
                 style="filter:drop-shadow(0 0 10px rgba(229,112,0,0.6)) brightness(0) saturate(100%) invert(48%) sepia(88%) saturate(1478%) hue-rotate(2deg) brightness(93%) contrast(101%);">
            <div class="brand-text">
                <h1><?= $title ?></h1>
                <span class="subtitle"><?= htmlspecialchars(t('app.subtitle')) ?></span>
            </div>
        </div>
        <div class="header-right">
            <?= lang_switcher_html($currentLang) ?>

            <div class="uptime-badge" title="<?= htmlspecialchars(t('app.uptime_tooltip')) ?>">
                <span class="uptime-icon">⏱️</span>
                <span class="uptime-value" id="uptime">--</span>
            </div>

            <div class="status">
                <span class="dot" id="statusDot"></span>
                <span id="statusText"><?= htmlspecialchars(t('dash.status.loading')) ?></span>
            </div>
            <?php if ($isAdmin): ?>
                <a href="users.php" class="btn-secondary" title="<?= htmlspecialchars(t('header.users_tooltip')) ?>">
                    👥 <?= htmlspecialchars(t('header.users')) ?>
                </a>
            <?php endif; ?>
            <div class="user-menu">
                <span class="user-name" title="<?= $loggedUser ?>">
                    👤 <?= $loggedName ?>
                    <?php if ($isAdmin): ?><span class="user-role"><?= htmlspecialchars(t('header.role_admin')) ?></span><?php endif; ?>
                </span>
                <a href="logout.php" class="btn-logout">🚪 <?= htmlspecialchars(t('header.logout')) ?></a>
            </div>
        </div>
    </header>

    <div class="toolbar">
        <div class="period-selector" role="tablist">
            <button class="period-btn" data-period="day" role="tab">
                <span class="icon">📅</span><span class="txt"><?= htmlspecialchars(t('dash.period.day')) ?></span>
            </button>
            <button class="period-btn" data-period="month" role="tab">
                <span class="icon">📆</span><span class="txt"><?= htmlspecialchars(t('dash.period.month')) ?></span>
            </button>
            <button class="period-btn" data-period="semester" role="tab">
                <span class="icon">🗓️</span><span class="txt"><?= htmlspecialchars(t('dash.period.semester')) ?></span>
            </button>
            <button class="period-btn" data-period="year" role="tab">
                <span class="icon">📊</span><span class="txt"><?= htmlspecialchars(t('dash.period.year')) ?></span>
            </button>
        </div>
        <div class="period-badge">
            <span class="dot-live"></span>
            <span id="periodLabel"><?= htmlspecialchars($initialPeriodLabel) ?></span>
        </div>
    </div>

    <div class="grid-system">
        <div class="card">
            <h2>⚡ <?= htmlspecialchars(t('dash.system.cpu')) ?></h2>
            <div class="metric-value" id="cpu">--</div>
            <div class="metric-sub" id="loadavg">Load: --</div>
            <div class="bar"><span id="cpuBar" style="background:var(--proxmox-orange);width:0%"></span></div>
        </div>
        <div class="card">
            <h2>🧠 <?= htmlspecialchars(t('dash.system.ram')) ?></h2>
            <div class="metric-value" id="mem">--</div>
            <div class="metric-sub" id="memDetail">--</div>
            <div class="bar"><span id="memBar" style="background:var(--ok);width:0%"></span></div>
        </div>
        <div class="card">
            <h2>💾 <?= htmlspecialchars(t('dash.system.swap')) ?></h2>
            <div class="metric-value" id="swap">--</div>
            <div class="metric-sub" id="swapDetail">--</div>
            <div class="bar"><span id="swapBar" style="background:var(--warn);width:0%"></span></div>
        </div>
        <div class="card">
            <h2>💿 <?= htmlspecialchars(t('dash.system.disk')) ?></h2>
            <div class="metric-value" id="disk">--</div>
            <div class="metric-sub" id="diskDetail">--</div>
            <div class="bar"><span id="diskBar" style="background:var(--proxmox-blue);width:0%"></span></div>
        </div>
        <div class="card">
            <h2>📥 <?= htmlspecialchars(t('dash.system.traffic_in')) ?></h2>
            <div class="metric-value" id="trafficIn">--</div>
            <div class="metric-sub"><?= htmlspecialchars(t('dash.system.total_period')) ?></div>
        </div>
        <div class="card">
            <h2>📤 <?= htmlspecialchars(t('dash.system.traffic_out')) ?></h2>
            <div class="metric-value" id="trafficOut">--</div>
            <div class="metric-sub"><?= htmlspecialchars(t('dash.system.total_period')) ?></div>
        </div>
    </div>

    <div class="card flux-card">
        <h2>📧 <?= htmlspecialchars(t('dash.flow.title')) ?> — <span class="period-highlight" id="fluxoPeriodo"><?= htmlspecialchars($initialPeriodLabel) ?></span></h2>
        <div class="grid-flux">
            <div class="stat clean"><div class="label"><?= htmlspecialchars(t('dash.flow.received')) ?></div><div class="value" id="mailsIn">--</div></div>
            <div class="stat clean"><div class="label"><?= htmlspecialchars(t('dash.flow.sent')) ?></div><div class="value" id="mailsOut">--</div></div>
            <div class="stat spam"><div class="label"><?= htmlspecialchars(t('dash.flow.spam_in')) ?></div><div class="value" id="spamIn">--</div></div>
            <div class="stat spam"><div class="label"><?= htmlspecialchars(t('dash.flow.spam_out')) ?></div><div class="value" id="spamOut">--</div></div>
            <div class="stat virus"><div class="label"><?= htmlspecialchars(t('dash.flow.virus_in')) ?></div><div class="value" id="virusIn">--</div></div>
            <div class="stat virus"><div class="label"><?= htmlspecialchars(t('dash.flow.virus_out')) ?></div><div class="value" id="virusOut">--</div></div>
            <div class="stat"><div class="label"><?= htmlspecialchars(t('dash.flow.junk_in')) ?></div><div class="value" id="junkIn">--</div></div>
            <div class="stat"><div class="label"><?= htmlspecialchars(t('dash.flow.junk_out')) ?></div><div class="value" id="junkOut">--</div></div>
            <div class="stat"><div class="label"><?= htmlspecialchars(t('dash.flow.greylist')) ?></div><div class="value" id="greylist">--</div></div>
            <div class="stat virus"><div class="label"><?= htmlspecialchars(t('dash.flow.spf')) ?></div><div class="value" id="spfRejects">--</div></div>
            <div class="stat virus"><div class="label"><?= htmlspecialchars(t('dash.flow.rbl')) ?></div><div class="value" id="rblRejects">--</div></div>
            <div class="stat virus"><div class="label"><?= htmlspecialchars(t('dash.flow.pregreet')) ?></div><div class="value" id="preRejects">--</div></div>
            <div class="stat"><div class="label"><?= htmlspecialchars(t('dash.flow.spam_rate')) ?></div><div class="value" id="spamRate">--</div></div>
            <div class="stat"><div class="label"><?= htmlspecialchars(t('dash.flow.avg_time')) ?></div><div class="value" id="avptime">--</div></div>
        </div>
    </div>

    <div class="card chart-card">
        <h2>
            <span>📊 <?= htmlspecialchars(t('dash.chart.title')) ?></span>
            <span class="chart-legend-hint">
                <span class="dot-green">●</span> <?= htmlspecialchars(t('dash.chart.received')) ?>
                &nbsp;·&nbsp;
                <span class="dot-red">●</span> <?= htmlspecialchars(t('dash.chart.spam')) ?>
                &nbsp;·&nbsp;
                <span class="dot-yellow">●</span> <?= htmlspecialchars(t('dash.chart.spam_rate')) ?>
                &nbsp;·&nbsp;
                <span class="dot-lilac">●</span> <?= htmlspecialchars(t('dash.chart.pregreet_rate')) ?>
            </span>
        </h2>
        <div class="chart-container"><canvas id="mailChart"></canvas></div>
    </div>

    <div class="top-grid">
        <div class="card">
            <h2>📤 <?= htmlspecialchars(t('dash.top.senders', ['count' => $topLimit])) ?></h2>
            <div class="table-wrap">
                <table class="top-table" id="topSendersTable">
                    <thead><tr>
                        <th class="rank">#</th>
                        <th><?= htmlspecialchars(t('dash.top.sender')) ?></th>
                        <th class="count"><?= htmlspecialchars(t('dash.top.emails')) ?></th>
                        <th class="virus-count"><?= htmlspecialchars(t('dash.top.virus')) ?></th>
                    </tr></thead>
                    <tbody><tr><td colspan="4" class="empty-row"><?= htmlspecialchars(t('dash.top.loading')) ?></td></tr></tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2>🌐 <?= htmlspecialchars(t('dash.top.domains', ['count' => $topLimit])) ?></h2>
            <div class="table-wrap">
                <table class="top-table" id="topDomainsTable">
                    <thead><tr>
                        <th class="rank">#</th>
                        <th><?= htmlspecialchars(t('dash.top.domain')) ?></th>
                        <th class="count"><?= htmlspecialchars(t('dash.top.received')) ?></th>
                        <th class="spam-count"><?= htmlspecialchars(t('dash.top.spam')) ?></th>
                    </tr></thead>
                    <tbody><tr><td colspan="4" class="empty-row"><?= htmlspecialchars(t('dash.top.loading')) ?></td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <footer>
        <div class="footer-meta">
            <div class="footer-section">
                <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                <span class="footer-label"><?= htmlspecialchars(t('dash.footer.pmg')) ?>:</span>
                <span class="footer-value" id="footerHostname">--</span>
            </div>
            <div class="footer-section">
                <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                <span class="footer-label"><?= htmlspecialchars(t('dash.footer.public_ip')) ?>:</span>
                <span class="footer-value" id="footerPublicIp">--</span>
            </div>
            <div class="footer-section">
                <span class="footer-label"><?= htmlspecialchars(t('dash.footer.relay_ext')) ?> (<span id="portExtNum">25</span>):</span>
                <span class="port-badge down" id="portExtBadge"><span class="port-dot"></span><span id="portExtText">...</span></span>
            </div>
            <div class="footer-section">
                <span class="footer-label"><?= htmlspecialchars(t('dash.footer.relay_int')) ?> (<span id="portIntNum">26</span>):</span>
                <span class="port-badge down" id="portIntBadge"><span class="port-dot"></span><span id="portIntText">...</span></span>
            </div>
        </div>
        <div class="footer-timestamp">
            <?= htmlspecialchars(t('dash.footer.updated')) ?>: <span id="lastUpdate">--</span>
            &nbsp;·&nbsp; <?= htmlspecialchars(t('dash.footer.refresh')) ?>: <?= $refresh ?>s
        </div>
    </footer>

</div>

<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<script>
const REFRESH = <?= (int)$refresh ?>;
const API_URL = 'api.php';
const VALID_PERIODS = <?= json_encode($validPeriods) ?>;
const DEFAULT_PERIOD = <?= json_encode($defaultPeriod) ?>;
const TOP_LIMIT = <?= (int)$topLimit ?>;
const T = <?= json_encode($jsStrings, JSON_UNESCAPED_UNICODE) ?>;
const LOCALE = <?= json_encode($currentLang) ?>;

function num(v, f = 0) { return (typeof v === 'number' && isFinite(v)) ? v : f; }
function fmtNum(v) { return num(v).toLocaleString(LOCALE); }
function fmtPct(v, d = 2) { return num(v).toFixed(d) + '%'; }
function humanBytes(b) {
    b = num(b); if (b < 1) return '0 B';
    const u = ['B','KB','MB','GB','TB','PB']; let i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return b.toFixed(1) + ' ' + u[i];
}
function humanUptime(s) {
    s = num(s);
    const d = Math.floor(s/86400), h = Math.floor((s%86400)/3600), m = Math.floor((s%3600)/60);
    return `${d}d ${h}h ${m}m`;
}
function setBar(id, pct, warn = 75, bad = 90) {
    const el = document.getElementById(id); if (!el) return;
    pct = num(pct);
    el.style.width = Math.min(pct, 100) + '%';
    el.style.background = pct >= bad ? 'var(--bad)' : pct >= warn ? 'var(--warn)' : (el.dataset.color || 'var(--ok)');
}
function setText(id, v) { const el = document.getElementById(id); if (el) el.textContent = v; }
function esc(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

document.querySelectorAll('.card').forEach(card => {
    card.addEventListener('mousemove', e => {
        const r = card.getBoundingClientRect();
        card.style.setProperty('--mouse-x', ((e.clientX - r.left) / r.width) * 100 + '%');
        card.style.setProperty('--mouse-y', ((e.clientY - r.top) / r.height) * 100 + '%');
    });
});

function renderPort(badgeId, textId, isOpen) {
    const badge = document.getElementById(badgeId), text = document.getElementById(textId);
    if (!badge || !text) return;
    if (isOpen) { badge.classList.remove('down'); badge.classList.add('up'); text.textContent = T.open; }
    else { badge.classList.remove('up'); badge.classList.add('down'); text.textContent = T.closed; }
}

function renderTopSenders(list) {
    const tb = document.querySelector('#topSendersTable tbody'); if (!tb) return;
    const arr = Array.isArray(list) ? list.slice(0, TOP_LIMIT) : [];
    if (arr.length === 0) { tb.innerHTML = `<tr><td colspan="4" class="empty-row">${esc(T.topEmpty)}</td></tr>`; return; }
    tb.innerHTML = arr.map((s, i) => {
        const vc = num(s.viruscount);
        return `<tr><td class="rank">${i+1}</td><td title="${esc(s.sender)}">${esc(s.sender)}</td><td class="count">${fmtNum(s.count)}</td><td class="virus-count${vc===0?' zero':''}">${fmtNum(vc)}</td></tr>`;
    }).join('');
}

function renderTopDomains(list) {
    const tb = document.querySelector('#topDomainsTable tbody'); if (!tb) return;
    const arr = Array.isArray(list) ? list.slice(0, TOP_LIMIT) : [];
    if (arr.length === 0) { tb.innerHTML = `<tr><td colspan="4" class="empty-row">${esc(T.topEmpty)}</td></tr>`; return; }
    tb.innerHTML = arr.map((d, i) => {
        const sc = num(d.spamcount_in);
        return `<tr><td class="rank">${i+1}</td><td title="${esc(d.domain)}">${esc(d.domain)}</td><td class="count">${fmtNum(d.count_in)}</td><td class="spam-count${sc===0?' zero':''}">${fmtNum(sc)}</td></tr>`;
    }).join('');
}

let mailChart = null;
function renderMailChart(hourly) {
    const canvas = document.getElementById('mailChart');
    if (!canvas || typeof Chart === 'undefined') return;
    const data = Array.isArray(hourly) ? hourly : [];
    if (data.length === 0) { if (mailChart) { mailChart.destroy(); mailChart = null; } return; }

    const labels       = data.map(h => h.hour);
    const incoming     = data.map(h => num(h.count_in));
    const spam         = data.map(h => num(h.spam_in));
    const spamRate     = data.map(h => num(h.spam_rate));
    const pregreetRate = data.map(h => num(h.pregreet_rate));

    if (mailChart) {
        mailChart.data.labels = labels;
        mailChart.data.datasets[0].data = incoming;
        mailChart.data.datasets[1].data = spam;
        mailChart.data.datasets[2].data = spamRate;
        mailChart.data.datasets[3].data = pregreetRate;
        mailChart.update('none');
        return;
    }

    const ctx = canvas.getContext('2d');
    const gradIn = ctx.createLinearGradient(0, 0, 0, 140);
    gradIn.addColorStop(0, 'rgba(34, 197, 94, 0.28)');
    gradIn.addColorStop(1, 'rgba(34, 197, 94, 0.02)');
    const gradSp = ctx.createLinearGradient(0, 0, 0, 140);
    gradSp.addColorStop(0, 'rgba(239, 68, 68, 0.25)');
    gradSp.addColorStop(1, 'rgba(239, 68, 68, 0.02)');

    mailChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: T.received, data: incoming, borderColor:'#22c55e', backgroundColor: gradIn, borderWidth:2, tension:0.35, fill:true, pointRadius:2, pointHoverRadius:5, yAxisID:'y', order:1 },
                { label: T.spam, data: spam, borderColor:'#ef4444', backgroundColor: gradSp, borderWidth:2, tension:0.35, fill:true, pointRadius:2, pointHoverRadius:5, yAxisID:'y', order:2 },
                { label: T.spamRate, data: spamRate, borderColor:'#facc15', backgroundColor:'transparent', borderWidth:2, borderDash:[5,3], tension:0.35, fill:false, pointRadius:0, pointHoverRadius:5, yAxisID:'y1', order:3 },
                { label: T.pregreetRate, data: pregreetRate, borderColor:'#c084fc', backgroundColor:'transparent', borderWidth:2, borderDash:[5,3], tension:0.35, fill:false, pointRadius:0, pointHoverRadius:5, yAxisID:'y1', order:4 },
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false, animation:{duration:400},
            interaction:{mode:'index', intersect:false},
            plugins: {
                legend: { display:true, position:'top', align:'end', labels:{ color:'#e2e8f0', font:{size:10,weight:'600'}, boxWidth:8, boxHeight:8, padding:10, usePointStyle:true, pointStyle:'circle' } },
                tooltip: {
                    backgroundColor:'rgba(15, 23, 42, 0.95)', titleColor:'#fff', bodyColor:'#e2e8f0',
                    borderColor:'rgba(229, 112, 0, 0.5)', borderWidth:1, padding:10, cornerRadius:8,
                    callbacks: {
                        title: it => T.hour + ': ' + it[0].label,
                        label: c => '  ' + c.dataset.label + ': ' + (c.dataset.label.includes('%') ? c.parsed.y.toFixed(2) + '%' : c.parsed.y.toLocaleString(LOCALE))
                    }
                }
            },
            scales: {
                x: { grid:{color:'rgba(255,255,255,0.04)',drawTicks:false}, border:{display:false}, ticks:{color:'#94a3b8', font:{size:9}, maxRotation:0, autoSkip:false, padding:4} },
                y: { type:'linear', position:'left', beginAtZero:true, grid:{color:'rgba(255,255,255,0.05)',drawTicks:false}, border:{display:false}, ticks:{color:'#94a3b8', font:{size:9}, padding:6, maxTicksLimit:5, callback:v => v>=1000?(v/1000).toFixed(1)+'k':v} },
                y1:{ type:'linear', position:'right', beginAtZero:true, suggestedMax:100, grid:{drawOnChartArea:false,drawTicks:false}, border:{display:false}, ticks:{color:'#94a3b8', font:{size:9}, padding:6, maxTicksLimit:5, callback:v => v + '%'} }
            }
        }
    });
}

function getInitialPeriod() {
    const u = new URLSearchParams(location.search).get('period');
    if (u && VALID_PERIODS.includes(u)) return u;
    const ls = localStorage.getItem('pmg_period');
    if (ls && VALID_PERIODS.includes(ls)) return ls;
    return DEFAULT_PERIOD;
}
let currentPeriod = getInitialPeriod();

function syncActiveButton() {
    document.querySelectorAll('.period-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.period === currentPeriod);
        b.setAttribute('aria-selected', b.dataset.period === currentPeriod);
    });
}
function updateUrl() {
    const url = new URL(location.href);
    url.searchParams.set('period', currentPeriod);
    history.replaceState(null, '', url);
}
document.querySelectorAll('.period-btn').forEach(b => {
    b.addEventListener('click', () => {
        const p = b.dataset.period;
        if (!VALID_PERIODS.includes(p) || p === currentPeriod) return;
        currentPeriod = p; localStorage.setItem('pmg_period', p);
        syncActiveButton(); updateUrl();
        showLoading(true); loadMetrics().finally(() => showLoading(false));
    });
});

let lt;
function showLoading(s) {
    const ov = document.getElementById('loadingOverlay');
    clearTimeout(lt);
    if (s) lt = setTimeout(() => ov.classList.add('show'), 200);
    else ov.classList.remove('show');
}

async function loadMetrics() {
    const dot = document.getElementById('statusDot'), text = document.getElementById('statusText');
    try {
        const resp = await fetch(API_URL + '?period=' + encodeURIComponent(currentPeriod) + '&_=' + Date.now(), { cache:'no-store' });
        if (resp.status === 401) { window.location.href = 'login.php'; return; }
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const d = await resp.json();
        if (d && d.error) throw new Error(d.error);
        if (!d) throw new Error('empty');

        const cpu = num(d.cpu);
        setText('cpu', cpu.toFixed(1) + '%');
        const ld = Array.isArray(d.loadavg) ? d.loadavg : [0,0,0];
        setText('loadavg', 'Load: ' + ld.map(v => num(v).toFixed(2)).join(' / '));
        setBar('cpuBar', cpu);

        const mem = d.memory || {};
        setText('mem', fmtPct(mem.pct, 1));
        setText('memDetail', humanBytes(mem.used) + ' / ' + humanBytes(mem.total));
        setBar('memBar', num(mem.pct));

        const sw = d.swap || {};
        setText('swap', fmtPct(sw.pct, 1));
        setText('swapDetail', humanBytes(sw.used) + ' / ' + humanBytes(sw.total));
        setBar('swapBar', num(sw.pct));

        const dk = d.disk || {};
        setText('disk', fmtPct(dk.pct, 1));
        setText('diskDetail', humanBytes(dk.used) + ' / ' + humanBytes(dk.total));
        setBar('diskBar', num(dk.pct));

        const tr = d.traffic || {};
        setText('trafficIn',  humanBytes(tr.in));
        setText('trafficOut', humanBytes(tr.out));
        setText('uptime',     humanUptime(d.uptime));

        const m = d.mails || {};
        setText('mailsIn',    fmtNum(m.incoming));
        setText('mailsOut',   fmtNum(m.outgoing));
        setText('spamIn',     fmtNum(m.spam_in));
        setText('spamOut',    fmtNum(m.spam_out));
        setText('virusIn',    fmtNum(m.virus_in));
        setText('virusOut',   fmtNum(m.virus_out));
        setText('junkIn',     fmtNum(m.junk_in));
        setText('junkOut',    fmtNum(m.junk_out));
        setText('greylist',   fmtNum(m.greylist));
        setText('spfRejects', fmtNum(m.spf_rejects));
        setText('rblRejects', fmtNum(m.rbl_rejects));
        setText('preRejects', fmtNum(m.pre_rejects));
        setText('spamRate',   fmtPct(m.spam_rate, 2));
        setText('avptime',    num(m.avptime).toFixed(2) + 's');

        renderMailChart(d.hourly_mail || []);
        renderTopSenders(d.top_senders || []);
        renderTopDomains(d.top_domains || []);

        const si = d.system_info || {};
        setText('footerHostname', si.hostname || '--');
        setText('footerPublicIp', si.public_ip || '--');
        setText('portExtNum', si.port_ext || 25);
        setText('portIntNum', si.port_int || 26);
        renderPort('portExtBadge', 'portExtText', !!si.port_ext_open);
        renderPort('portIntBadge', 'portIntText', !!si.port_int_open);

        if (d.period_label) {
            setText('periodLabel', d.period_label);
            setText('fluxoPeriodo', d.period_label);
        }

        dot.classList.remove('err');
        text.textContent = T.connected;
        setText('lastUpdate', new Date(d.timestamp || Date.now()).toLocaleString(LOCALE));
    } catch (e) {
        dot.classList.add('err');
        text.textContent = T.error + ': ' + e.message;
        console.error('[PMG Dashboard]', e);
    }
}

syncActiveButton(); updateUrl(); loadMetrics();
setInterval(loadMetrics, REFRESH * 1000);
</script>
</body>
</html>
