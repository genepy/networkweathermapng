<div class="admin-container">
    <div class="page-header">
        <h1><?= htmlspecialchars($title) ?></h1>
    </div>
    
    <?php
    $settings = json_decode((string) ($source['settings'] ?? ''), true) ?: [];
    $type = $source['type'] ?? 'zabbix';
    $setting = static fn(string $key, string $default = ''): string => htmlspecialchars((string) ($settings[$key] ?? $default));
    ?>
    <form method="post" class="form-container" data-type="<?= htmlspecialchars($type) ?>">
        <div class="form-group">
            <label for="name">Name *</label>
            <input type="text" id="name" name="name" required
                   value="<?= htmlspecialchars($source['name'] ?? '') ?>"
                   placeholder="e.g., Production Observium">
            <small>A friendly name to identify this data source</small>
        </div>
        
        <div class="form-group">
            <label for="type">Type *</label>
            <select id="type" name="type" required>
                <?php foreach ($types as $value => $label): ?>
                <option value="<?= $value ?>" <?= $type === $value ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="url">URL <span class="only-zabbix">*</span></label>
            <input type="url" id="url" name="url"
                   value="<?= htmlspecialchars($source['url'] ?? '') ?>"
                   placeholder="https://monitoring.example.com">
            <small class="only-zabbix">Base URL of the Zabbix server (without /api_jsonrpc.php)</small>
            <small class="only-observium">Base URL of Observium. With an API token or a user below, Weathermap reads Observium through its API (Professional and Enterprise editions). Leave the credentials empty to read the database instead (all editions, Community included). The URL is also used for links to port pages.</small>
        </div>
        
        <fieldset class="form-fieldset">
            <legend>Authentication</legend>
            
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       value="<?= htmlspecialchars($source['username'] ?? '') ?>"
                       placeholder="Admin">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="new-password"
                       placeholder="<?= $source ? '(unchanged)' : '' ?>">
                <?php if ($source): ?>
                <small>Leave empty to keep current password</small>
                <?php endif; ?>
            </div>
            
            <div class="form-divider">
                <span>OR</span>
            </div>
            
            <div class="form-group">
                <label for="api_token">API Token</label>
                <input type="text" id="api_token" name="api_token"
                       value="<?= htmlspecialchars($source['api_token'] ?? '') ?>"
                       placeholder="API token">
                <small class="only-zabbix">For Zabbix 5.4+, you can use an API token instead of username/password</small>
                <small class="only-observium">Observium API token, sent as a Bearer token</small>
            </div>
        </fieldset>

        <fieldset class="form-fieldset only-observium">
            <legend>Observium access</legend>

            <div class="form-group">
                <label for="obs_mode">Read Observium through</label>
                <select id="obs_mode" name="settings[mode]">
                    <?php foreach (['auto' => 'Automatic: API when credentials are set, otherwise the database', 'api' => 'API only', 'mysql' => 'Database only'] as $value => $label): ?>
                    <option value="<?= $value ?>" <?= ($settings['mode'] ?? 'auto') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="obs_install_path">Observium folder</label>
                <input type="text" id="obs_install_path" name="settings[install_path]"
                       value="<?= $setting('install_path') ?>" placeholder="/opt/observium">
                <small>When Weathermap runs on the Observium server, the database settings and the RRD folder are read from config.php in this folder. Fill in the fields below only to override them or for a remote Observium.</small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="obs_db_host">Database host</label>
                    <input type="text" id="obs_db_host" name="settings[db_host]" value="<?= $setting('db_host') ?>" placeholder="from config.php">
                </div>
                <div class="form-group form-group-small">
                    <label for="obs_db_port">Port</label>
                    <input type="number" id="obs_db_port" name="settings[db_port]" value="<?= $setting('db_port') ?>" placeholder="3306">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="obs_db_name">Database name</label>
                    <input type="text" id="obs_db_name" name="settings[db_name]" value="<?= $setting('db_name') ?>" placeholder="observium">
                </div>
                <div class="form-group">
                    <label for="obs_db_user">Read-only user</label>
                    <input type="text" id="obs_db_user" name="settings[db_user]" value="<?= $setting('db_user') ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="obs_db_password">Database password</label>
                <input type="password" id="obs_db_password" name="settings[db_password]" autocomplete="new-password"
                       placeholder="<?= !empty($settings['db_password']) ? '(unchanged)' : '' ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="obs_rrd_dir">RRD folder</label>
                    <input type="text" id="obs_rrd_dir" name="settings[rrd_dir]" value="<?= $setting('rrd_dir') ?>" placeholder="from config.php, or /opt/observium/rrd">
                </div>
                <div class="form-group">
                    <label for="obs_rrdcached">rrdcached address</label>
                    <input type="text" id="obs_rrdcached" name="settings[rrdcached]" value="<?= $setting('rrdcached') ?>" placeholder="optional, e.g. observium:42217">
                </div>
            </div>
            <small>RRD files give the hover graphs their history. For a remote Observium, point rrdcached at it.</small>

            <div class="form-group" style="margin-top:15px">
                <label class="checkbox-label">
                    <input type="hidden" name="settings[verify_tls]" value="0">
                    <input type="checkbox" name="settings[verify_tls]" value="1" <?= ($settings['verify_tls'] ?? true) ? 'checked' : '' ?>>
                    Verify the HTTPS certificate of the API
                </label>
            </div>
        </fieldset>
        
        <?php if ($source): ?>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="active" <?= $source['active'] ? 'checked' : '' ?>>
                Active
            </label>
        </div>
        <?php endif; ?>
        
        <div class="form-actions">
            <a href="/admin/data-sources" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <?= $action === 'create' ? 'Create Data Source' : 'Save Changes' ?>
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    var form = document.querySelector('.form-container');
    var type = document.getElementById('type');
    var url = document.getElementById('url');
    function update() {
        form.setAttribute('data-type', type.value);
        url.required = type.value === 'zabbix';
    }
    type.addEventListener('change', update);
    update();
})();
</script>

<style>
.form-container {
    max-width: 600px;
    background: #fff;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}
.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}
.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #007bff;
}
.form-group small {
    display: block;
    margin-top: 5px;
    color: #666;
    font-size: 12px;
}
.form-fieldset {
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}
.form-fieldset legend {
    padding: 0 10px;
    font-weight: 500;
}
.form-divider {
    text-align: center;
    margin: 20px 0;
    position: relative;
}
.form-divider::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    width: 100%;
    height: 1px;
    background: #ddd;
}
.form-divider span {
    background: #fff;
    padding: 0 15px;
    position: relative;
    color: #666;
}
.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #eee;
}
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}
.checkbox-label input {
    width: auto;
}
.form-row {
    display: flex;
    gap: 15px;
}
.form-row .form-group {
    flex: 1;
}
.form-row .form-group-small {
    flex: 0 0 110px;
}
.form-container[data-type="zabbix"] .only-observium,
.form-container:not([data-type="zabbix"]) .only-zabbix {
    display: none;
}
</style>
