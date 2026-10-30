<?php
    $favourites = isset($favourites) ? array_map('intval', (array) $favourites) : [];
    $statuses = isset($statuses) ? (array) $statuses : [];
?>
<link rel="stylesheet" href="/assets/css/viewer.css">
<div class="maps-page">
    <div class="page-header">
        <h1><?= e__('Network Weathermaps') ?></h1>
        <div class="page-actions">
            <a href="/cycle" class="btn btn-primary"><?= e__('Cycle Maps') ?></a>
        </div>
    </div>
    
    <?php if (count($groups) > 1): ?>
    <div class="map-groups-tabs">
        <ul class="tabs">
            <li><a href="?group_id=0" class="<?= $currentGroup === 0 ? 'active' : '' ?>"><?= e__('All') ?></a></li>
            <?php foreach ($groups as $group): ?>
            <li>
                <a href="?group_id=<?= $group['id'] ?>" 
                   class="<?= $currentGroup === (int)$group['id'] ? 'active' : '' ?>">
                    <?= htmlspecialchars($group['name']) ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <?php if (empty($maps)): ?>
    <div class="empty-state">
        <p><?= e__('No maps available.') ?></p>
        <?php if ($auth->isAdmin()): ?>
        <p><a href="/admin/maps"><?= e__('Create your first map') ?></a></p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="maps-toolbar">
        <input type="search" id="mapsSearch" class="maps-search" placeholder="<?= e__('Search maps') ?>" aria-label="<?= e__('Search maps') ?>" autocomplete="off">
        <span class="maps-count" id="mapsCount" aria-live="polite"></span>
    </div>
    <p class="maps-no-match" id="mapsNoMatch" hidden><?= e__('No map matches your search.') ?></p>
    <div class="maps-grid" id="mapsGrid">
        <?php foreach ($maps as $i => $map): ?>
        <?php
            $mid = (int) $map['id'];
            $timezone = $timezones[$mid];
            $isFav = in_array($mid, $favourites, true);
            $st = $statuses[$mid] ?? null;
            $title = $map['title_cache'] ?: $map['name'];
            $stTitle = __('No data yet');
            $stText = '';
            if ($st !== null && $st['max'] !== null) {
                $stTitle = __('Max load :pct %', ['pct' => $st['max']]) . ($st['link'] ? ' (' . $st['link'] . ')' : '');
                $stText = round((float) $st['max']) . ' %';
            }
            if ($st !== null && !empty($st['time'])) {
                $stTitle .= ' — ' . __('Updated :time', ['time' => \App\Services\TimezoneService::formatTimestamp((int) $st['time'], $timezone, 'Y-m-d H:i')]);
            }
        ?>
        <div class="map-card" data-map-id="<?= $mid ?>" data-timezone="<?= htmlspecialchars($timezone) ?>" data-order="<?= (int) ($map['list_order'] ?? $i) ?>"
             data-name="<?= htmlspecialchars(mb_strtolower($title . ' ' . $map['name'] . ' ' . ($map['group_name'] ?? ''))) ?>"
             data-fav="<?= $isFav ? '1' : '0' ?>"
             data-stale="<?= ($st === null || !empty($st['stale'])) ? '1' : '0' ?>">
            <span class="map-state<?= ($st !== null && !empty($st['stale'])) ? ' map-state-stale' : '' ?>" title="<?= htmlspecialchars($stTitle) ?>" aria-label="<?= htmlspecialchars($stTitle) ?>">
                <span class="map-state-dot"<?= ($st !== null && !empty($st['colour'])) ? ' style="background:' . htmlspecialchars((string) $st['colour']) . '"' : '' ?>></span>
                <span class="map-state-text"><?= htmlspecialchars($stText) ?></span>
            </span>
            <button type="button" class="map-fav" aria-pressed="<?= $isFav ? 'true' : 'false' ?>"
                    title="<?= $isFav ? e__('Remove from favourites') : e__('Add to favourites') ?>"
                    aria-label="<?= htmlspecialchars(__('Favourite: :name', ['name' => $title])) ?>"><?= $isFav ? '★' : '☆' ?></button>
            <a href="/map/<?= $map['id'] ?>" class="map-link">
                <div class="map-thumbnail">
                    <img src="/map/<?= $map['id'] ?>/thumb?fast=1" 
                         alt="<?= htmlspecialchars($map['name']) ?>"
                         loading="lazy">
                </div>
                <div class="map-info">
                    <h3><?= htmlspecialchars($map['title_cache'] ?: $map['name']) ?></h3>
                    <?php if ($map['group_name']): ?>
                    <span class="map-group"><?= htmlspecialchars($map['group_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($auth->isAdmin()): ?>
                    <button class="btn-icon move-map-btn" data-map-id="<?= $map['id'] ?>" data-current-group="<?= $map['group_id'] ?>" title="<?= e__('Move to another group') ?>" aria-label="<?= e__('Move to another group') ?>">
                        ↔️
                    </button>
                    <?php endif; ?>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Move Map Modal -->
<div id="moveMapModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?= e__('Move Map') ?></h3>
            <button type="button" class="close-btn" onclick="closeMoveModal()" aria-label="<?= e__('Close') ?>">&times;</button>
        </div>
        <form id="moveMapForm">
            <input type="hidden" id="moveMapId" name="map_id">
            <div class="form-group">
                <label for="moveMapGroup"><?= e__('Select destination group:') ?></label>
                <select id="moveMapGroup" name="group_id" class="form-control" required>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeMoveModal()"><?= e__('Cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= e__('Move Map') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
window.WM_T = Object.assign(window.WM_T || {}, <?= json_encode(\App\Core\I18n::forScript(['Error: :msg', 'Failed to move map', 'An error occurred while moving the map']) + \App\Core\I18n::section('viewer-js')) ?>);
</script>
<script src="/assets/js/maplist.js" defer></script>

<script>
function t(s) { return (window.WM_T && window.WM_T[s]) || s; }

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('moveMapModal');
    const form = document.getElementById('moveMapForm');
    const btns = document.querySelectorAll('.move-map-btn');
    
    btns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const mapId = this.dataset.mapId;
            const currentGroupId = this.dataset.currentGroup;
            
            document.getElementById('moveMapId').value = mapId;
            document.getElementById('moveMapGroup').value = currentGroupId;
            
            modal.style.display = 'flex';
        });
    });
    
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const mapId = document.getElementById('moveMapId').value;
        const groupId = document.getElementById('moveMapGroup').value;
        
        fetch(`/admin/maps/move/${mapId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ group_id: groupId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(t('Error: :msg').replace(':msg', data.error || t('Failed to move map')));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert(t('An error occurred while moving the map'));
        });
    });
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target == modal) {
            closeMoveModal();
        }
    }
});

function closeMoveModal() {
    document.getElementById('moveMapModal').style.display = 'none';
}
</script>

<style>
.map-info {
    position: relative;
}

.move-map-btn {
    position: absolute;
    right: 0;
    bottom: 0;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 1.2rem;
    padding: 5px;
    opacity: 0.6;
    transition: opacity 0.2s;
    z-index: 10;
}

.move-map-btn:hover {
    opacity: 1;
    transform: scale(1.1);
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    align-items: center;
    justify-content: center;
}

.modal-content {
    background-color: var(--card-bg);
    color: var(--text-color);
    padding: 20px;
    border-radius: 8px;
    width: 100%;
    max-width: 400px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-header h3 {
    margin: 0;
}

.close-btn {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--text-muted);
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}

.btn-secondary {
    background-color: var(--secondary-color);
    color: white;
}

.btn-secondary:hover {
    background-color: var(--secondary-color);
    filter: brightness(0.9);
}
</style>
