<div class="admin-page">
    <div class="page-header">
        <h1><?= e__('Administration') ?></h1>
    </div>
    
    <div class="admin-dashboard">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['maps'] ?></div>
                <div class="stat-label"><?= e__('Maps') ?></div>
                <a href="/admin/maps" class="stat-link"><?= e__('Manage Maps') ?></a>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['groups'] ?></div>
                <div class="stat-label"><?= e__('Groups') ?></div>
                <a href="/admin/groups" class="stat-link"><?= e__('Manage Groups') ?></a>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['users'] ?></div>
                <div class="stat-label"><?= e__('Users') ?></div>
                <a href="/admin/users" class="stat-link"><?= e__('Manage Users') ?></a>
            </div>
        </div>
        
        <div class="admin-menu">
            <h2><?= e__('Quick Actions') ?></h2>
            <div class="menu-grid">
                <a href="/admin/maps" class="menu-item">
                    <span class="menu-icon">🗺️</span>
                    <span class="menu-title"><?= e__('Maps') ?></span>
                    <span class="menu-desc"><?= e__('Add, edit, and manage weathermaps') ?></span>
                </a>
                <a href="/admin/groups" class="menu-item">
                    <span class="menu-icon">📁</span>
                    <span class="menu-title"><?= e__('Groups') ?></span>
                    <span class="menu-desc"><?= e__('Organize maps into groups') ?></span>
                </a>
                <a href="/admin/users" class="menu-item">
                    <span class="menu-icon">👥</span>
                    <span class="menu-title"><?= e__('Users') ?></span>
                    <span class="menu-desc"><?= e__('Manage user accounts and permissions') ?></span>
                </a>
                <a href="/admin/kiosk" class="menu-item">
                    <span class="menu-icon">📺</span>
                    <span class="menu-title"><?= e__('Kiosk mode') ?></span>
                    <span class="menu-desc"><?= e__('Display time and zoom of each map in the map cycle') ?></span>
                </a>
                <a href="/admin/data-sources" class="menu-item">
                    <span class="menu-icon">🔌</span>
                    <span class="menu-title"><?= e__('Data Sources') ?></span>
                    <span class="menu-desc"><?= e__('Configure Zabbix and other data sources') ?></span>
                </a>
                <a href="/admin/migration" class="menu-item">
                    <span class="menu-icon">📦</span>
                    <span class="menu-title"><?= e__('Migration') ?></span>
                    <span class="menu-desc"><?= e__('Import maps from an existing Weathermap') ?></span>
                </a>
                <a href="/admin/settings" class="menu-item">
                    <span class="menu-icon">⚙️</span>
                    <span class="menu-title"><?= e__('Settings') ?></span>
                    <span class="menu-desc"><?= e__('Configure application settings') ?></span>
                </a>
                <a href="/admin/check-db" class="menu-item">
                    <span class="menu-icon">🛡️</span>
                    <span class="menu-title"><?= e__('Check DB') ?></span>
                    <span class="menu-desc"><?= e__('Verify database integrity') ?></span>
                </a>
                <a href="/editor" class="menu-item">
                    <span class="menu-icon">✏️</span>
                    <span class="menu-title"><?= e__('Editor') ?></span>
                    <span class="menu-desc"><?= e__('Visual map editor') ?></span>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.stat-card {
    background: var(--card-bg);
    padding: 25px;
    border-radius: 8px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.stat-value {
    font-size: 48px;
    font-weight: 700;
    color: var(--primary-color);
}
.stat-label {
    font-size: 14px;
    color: var(--text-muted);
    margin-bottom: 15px;
}
.stat-link {
    font-size: 13px;
}
.admin-menu h2 {
    margin: 0 0 20px;
    font-size: 18px;
}
.menu-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
}
.menu-item {
    display: block;
    background: var(--card-bg);
    padding: 20px;
    border-radius: 8px;
    text-decoration: none;
    color: inherit;
    transition: transform 0.2s, box-shadow 0.2s;
}
.menu-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    text-decoration: none;
}
.menu-icon {
    font-size: 32px;
    display: block;
    margin-bottom: 10px;
}
.menu-title {
    font-size: 16px;
    font-weight: 600;
    display: block;
    margin-bottom: 5px;
}
.menu-desc {
    font-size: 13px;
    color: var(--text-muted);
}
</style>
