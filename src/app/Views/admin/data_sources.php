<div class="admin-container">
    <div class="page-header">
        <h1><?= e__('Data Sources') ?></h1>
        <a href="/admin/data-sources/create" class="btn btn-primary">+ <?= e__('Add Source') ?></a>
    </div>
    
    <p class="page-description">
        <?= e__('Manage data sources for fetching network metrics. Data sources provide hosts, interfaces, and traffic data for your maps.') ?>
    </p>
    
    <div id="test-log" class="test-log hidden">
        <div class="test-log-header">
            <div>
                <div class="test-log-title"><?= e__('Last Test Result') ?></div>
                <div id="test-log-source" class="test-log-source text-muted"></div>
            </div>
            <span id="test-log-status" class="badge badge-secondary"><?= e__('Pending') ?></span>
        </div>
        <div class="test-log-body">
            <p id="test-log-message" class="test-log-message text-muted"><?= e__('Run a test to see details.') ?></p>
            <div class="test-log-grid">
                <div>
                    <h4><?= e__('cURL Command') ?></h4>
                    <pre id="test-log-curl"><?= e__('Run a test to generate command.') ?></pre>
                </div>
                <div>
                    <h4><?= e__('Request Payload') ?></h4>
                    <pre id="test-log-request">{}</pre>
                </div>
                <div>
                    <h4><?= e__('Response') ?></h4>
                    <pre id="test-log-response">{}</pre>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (empty($sources)): ?>
    <div class="empty-state">
        <p><?= e__('No data sources configured yet.') ?></p>
        <p><?= e__('Add a data source to start fetching network data for your maps.') ?></p>
        <a href="/admin/data-sources/create" class="btn btn-primary"><?= e__('Add Your First Data Source') ?></a>
    </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th><?= e__('Name') ?></th>
                <th><?= e__('Type') ?></th>
                <th>URL</th>
                <th><?= e__('Status') ?></th>
                <th><?= e__('Last Check') ?></th>
                <th><?= e__('Actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sources as $source): ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($source['name']) ?></strong>
                    <?php if (!$source['active']): ?>
                    <span class="badge badge-warning"><?= e__('Inactive') ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-info"><?= htmlspecialchars(ucfirst($source['type'])) ?></span>
                </td>
                <td class="text-muted"><?= htmlspecialchars($source['url']) ?></td>
                <td>
                    <?php 
                    $statusClass = match($source['status']) {
                        'connected' => 'badge-success',
                        'error' => 'badge-danger',
                        default => 'badge-secondary'
                    };
                    ?>
                    <span class="badge <?= $statusClass ?>"><?= e__(ucfirst((string) $source['status'])) ?></span>
                </td>
                <td class="text-muted">
                    <?= $source['last_check'] ? date('Y-m-d H:i', strtotime($source['last_check'])) : e__('Never') ?>
                </td>
                <td class="actions">
                    <button 
                        type="button"
                        class="btn btn-sm btn-secondary"
                        onclick="testConnection(<?= $source['id'] ?>, this)"
                        data-source-name="<?= htmlspecialchars($source['name']) ?>"
                    ><?= e__('Test') ?></button>
                    <a href="/admin/data-sources/edit/<?= $source['id'] ?>" class="btn btn-sm btn-primary"><?= e__('Edit') ?></a>
                    <a href="/admin/data-sources/delete/<?= $source['id'] ?>" 
                       class="btn btn-sm btn-danger"
                       data-method="post" data-confirm="<?= e__('Are you sure you want to delete this data source?') ?>"><?= e__('Delete') ?></a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
const logBox = document.getElementById('test-log');
const logStatus = document.getElementById('test-log-status');
const logSource = document.getElementById('test-log-source');
const logMessage = document.getElementById('test-log-message');
const logCurl = document.getElementById('test-log-curl');
const logRequest = document.getElementById('test-log-request');
const logResponse = document.getElementById('test-log-response');

function testConnection(sourceId, btn) {
    const originalText = btn.textContent;
    btn.textContent = <?= json_encode(__('Testing...')) ?>;
    btn.disabled = true;
    const sourceName = btn.dataset.sourceName || <?= json_encode(__('Data Source #')) ?> + sourceId;
    logSource.textContent = sourceName;
    logBox.classList.remove('hidden');
    setLogState('badge-secondary', <?= json_encode(__('Running…')) ?>, <?= json_encode(__('Testing connection...')) ?>);
    
    fetch('/admin/data-sources/test/' + sourceId)
        .then(r => r.json())
        .then(data => {
            updateLog(data);
        })
        .catch(err => {
            setLogState('badge-danger', <?= json_encode(__('Error')) ?>, err.message);
            logCurl.textContent = '—';
            logRequest.textContent = '{}';
            logResponse.textContent = '{}';
        })
        .finally(() => {
            btn.textContent = originalText;
            btn.disabled = false;
        });
}

function updateLog(data) {
    if (!data) {
        setLogState('badge-danger', <?= json_encode(__('Error')) ?>, <?= json_encode(__('No response returned')) ?>);
        logCurl.textContent = '—';
        logRequest.textContent = '{}';
        logResponse.textContent = '{}';
        return;
    }
    
    const badgeClass = data.success ? 'badge-success' : 'badge-danger';
    const statusText = data.success ? <?= json_encode(__('Success')) ?> : <?= json_encode(__('Failed')) ?>;
    const message = data.success
        ? (data.message || <?= json_encode(__('Connection OK')) ?>) + (data.version ? ` (Zabbix ${data.version})` : '')
        : (data.error || <?= json_encode(__('Connection failed')) ?>);
    
    setLogState(badgeClass, statusText, message);
    
    const debug = data.debug || {};
    logCurl.textContent = debug.curl || '—';
    logRequest.textContent = formatJson(debug.request);
    logResponse.textContent = formatJson(debug.response);
}

function setLogState(badgeClass, statusText, message) {
    logStatus.className = 'badge ' + badgeClass;
    logStatus.textContent = statusText;
    logMessage.textContent = message;
}

function formatJson(value) {
    if (!value) {
        return '{}';
    }
    try {
        return JSON.stringify(value, null, 2);
    } catch (e) {
        return String(value);
    }
}
</script>

<style>
.page-description {
    color: var(--text-muted);
    margin-bottom: 20px;
}
.empty-state {
    text-align: center;
    padding: 40px;
    background: var(--light-color);
    border-radius: 8px;
    margin: 20px 0;
}
.empty-state p {
    margin: 10px 0;
    color: var(--text-muted);
}

.test-log {
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 25px;
    background: var(--card-bg);
    box-shadow: 0 5px 20px rgba(20,44,83,0.08);
}
.test-log.hidden {
    display: none;
}
.test-log-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.test-log-title {
    font-weight: 600;
    font-size: 16px;
}
.test-log-source {
    font-size: 13px;
}
.test-log-message {
    font-size: 14px;
    margin-bottom: 15px;
}
.test-log-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 15px;
}
.test-log-grid pre {
    background: var(--code-bg);
    color: var(--text-color);
    padding: 15px;
    border-radius: 8px;
    font-size: 12px;
    max-height: 260px;
    overflow: auto;
}
.test-log-grid h4 {
    margin-bottom: 6px;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
}
</style>
