<?php
/**
 * Dynamic Recipe Template
 * Serves any recipe by UUID from the database.
 * URL: /momsrecipes/recipes/{uuid}  (rewritten by .htaccess)
 *      /momsrecipes/api/recipe.php?id={uuid}  (direct)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// ── Detect environment — local dev vs production ───────────────────────────
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = preg_match('/localhost|127\.0\.0\.1/', $host);
$basePath = $isLocal ? '' : '/momsrecipes';

// ── Get UUID from query string ──────────────────────────────────────────────
$uuid = trim($_GET['id'] ?? '');
if (!$uuid) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><h1>Recipe not found</h1><a href="' . $basePath . '/">← Back</a></body></html>';
    exit;
}

// ── Load recipe from database ───────────────────────────────────────────────
$db     = new RecipeDatabase();
$recipe = $db->getRecipeByUuid($uuid);

if (!$recipe) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><h1>Recipe not found</h1><a href="' . $basePath . '/">← Back</a></body></html>';
    exit;
}

// ── Parse stored fields ─────────────────────────────────────────────────────
// ingredients and directions are stored as JSON arrays or newline strings
function parseListField($value) {
    if (!$value) return [];
    $decoded = json_decode($value, true);
    if (is_array($decoded)) return $decoded;
    // Fallback: newline-separated string
    return array_filter(array_map('trim', explode("\n", $value)));
}

$ingredients = parseListField($recipe['ingredients']);
$directions  = parseListField($recipe['directions']);
$notes       = parseListField($recipe['notes'] ?? '');

// ── Build HTML fragments ────────────────────────────────────────────────────
function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// Image carousel
$imageData     = $recipe['image_data'] ?? '';
$imageFilename = $recipe['image_filename'] ?? '';
$carouselHtml  = '';
if ($imageData) {
    // Imported recipe — inline base64 image
    $carouselHtml = '
    <div class="image-carousel">
        <div class="carousel-container">
            <div class="carousel-viewport">
                <div class="carousel-track" id="carousel-track">
                    <div class="carousel-slide">
                        <img src="' . e($imageData) . '"
                             alt="' . e($recipe['title']) . '"
                             class="clickable-image carousel-image"
                             data-image-index="0">
                        <button class="replace-image-btn" data-image-index="0">Replace</button>
                        <button class="delete-image-btn" data-image-index="0">Delete</button>
                    </div>
                </div>
            </div>
        </div>
        <button class="add-image-btn" onclick="addNewImage()">➕ Add Image</button>
    </div>';
} else {
    $carouselHtml = '
    <div class="image-carousel no-images">
        <div class="no-image-placeholder">
            <p>📷 No images yet</p>
            <button class="add-image-btn" onclick="addNewImage()">➕ Add Image</button>
        </div>
    </div>';
}

// Meta fields
function metaTag($value, $type, $label, $cssClass) {
    if ($value) {
        return '<div class="meta-field"><label class="meta-label">' . $label . ':</label>'
             . '<span class="meta-tag ' . $cssClass . ' editable-meta" data-type="' . $type . '" title="Click to edit">'
             . e($value) . '</span></div>';
    }
    return '<div class="meta-field"><label class="meta-label">' . $label . ':</label>'
         . '<span class="meta-tag ' . $cssClass . ' editable-meta empty" data-type="' . $type . '" title="Click to add">+ ' . $label . '</span></div>';
}
$metaHtml  = metaTag($recipe['meal_type'],       'meal_type',       'Meal Type',       'meal-type');
$metaHtml .= metaTag($recipe['cuisine'],         'cuisine',         'Cuisine',         'cuisine');
$metaHtml .= metaTag($recipe['main_ingredient'], 'main_ingredient', 'Main Ingredient', 'ingredient');
$metaHtml .= metaTag($recipe['method'],          'method',          'Method',          'method');

// Source
if ($recipe['contributor']) {
    $sourceHtml = '<div class="source-field"><label class="source-label">From:</label>'
                . '<span id="family-source" class="source editable-meta editable-source" data-type="family_source" title="Click to edit source">'
                . e($recipe['contributor']) . '</span></div>';
} else {
    $sourceHtml = '<div class="source-field"><label class="source-label">From:</label>'
                . '<span id="family-source" class="source editable-meta editable-source empty" data-type="family_source" title="Click to add source">+ Add Source</span></div>';
}

// Notes
$notesHtml = '';
if ($notes) {
    $notesHtml = '<div class="notes">';
    foreach ($notes as $note) {
        $notesHtml .= '<p>' . e($note) . '</p>';
    }
    $notesHtml .= '</div>';
}

// Ingredients
$ingredientsHtml = '<ul class="ingredients" id="ingredients-list">';
foreach ($ingredients as $ing) {
    $ingredientsHtml .= '<li>' . e($ing) . '</li>';
}
$ingredientsHtml .= '</ul>';

// Directions
$directionsHtml = '<ol class="directions" id="directions-list">';
foreach ($directions as $dir) {
    $directionsHtml .= '<li>' . e($dir) . '</li>';
}
$directionsHtml .= '</ol>';

// All sources for dropdown (load from DB)
$allRecipes  = $db->getRecipes();
$allSources  = [];
foreach ($allRecipes as $r) {
    if (!empty($r['contributor'])) $allSources[] = $r['contributor'];
}
$allSources = array_values(array_unique($allSources));
sort($allSources);

// Recipe data JSON for JS editing
$recipeDataJson = json_encode([
    'uuid'            => $recipe['uuid'],
    'id'              => $recipe['uuid'],
    'title'           => $recipe['title'],
    'family_source'   => $recipe['contributor'] ?? '',
    'ingredients'     => $ingredients,
    'directions'      => $directions,
    'notes'           => $notes,
    'meal_type'       => $recipe['meal_type'] ?? '',
    'cuisine'         => $recipe['cuisine'] ?? '',
    'main_ingredient' => $recipe['main_ingredient'] ?? '',
    'method'          => $recipe['method'] ?? '',
    'images'          => $imageData ? [$imageData] : [],
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);

$recipeId      = e($recipe['uuid']);
$recipeTitle   = e($recipe['title']);
$allSourcesJson = json_encode($allSources, JSON_HEX_TAG);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $recipeTitle ?> - Mom's Recipes</title>
    <link rel="stylesheet" href="<?= $basePath ?>/styles.css">
    <style>
        .edit-controls { margin: 20px 0; padding: 15px; background: #f9f9f9; border-radius: 5px; display: flex; gap: 10px; flex-wrap: wrap; }
        .edit-btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: bold; transition: all 0.3s; }
        .edit-btn-primary   { background: #8B4513; color: white; }
        .edit-btn-primary:hover { background: #A0522D; }
        .edit-btn-secondary { background: #4A90E2; color: white; }
        .edit-btn-secondary:hover { background: #357ABD; }
        .edit-btn-success   { background: #27AE60; color: white; }
        .edit-btn-success:hover { background: #229954; }
        .edit-btn-cancel    { background: #95a5a6; color: white; }
        .edit-btn-cancel:hover { background: #7f8c8d; }
        .add-item-btn { margin-top: 10px; padding: 8px 15px; background: #4A90E2; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 13px; }
        .add-item-btn:hover { background: #357ABD; }
        .no-image { width: 100%; height: 300px; background: #f0e6d6; display: flex; align-items: center; justify-content: center; font-size: 2em; color: #8B4513; border-radius: 10px; }
        .message { padding: 15px; margin: 15px 0; border-radius: 5px; font-weight: bold; }
        .message-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 1000; overflow-y: auto; }
        .modal.active { display: flex; align-items: center; justify-content: center; padding: 20px; }
        .modal-content { background: white; border-radius: 10px; max-width: 800px; width: 100%; max-height: 80vh; overflow-y: auto; position: relative; }
        .modal-header { background: #8B4513; color: white; padding: 20px; border-radius: 10px 10px 0 0; }
        .modal-header h2 { margin: 0; }
        .modal-close { position: absolute; top: 15px; right: 20px; font-size: 30px; color: white; cursor: pointer; background: none; border: none; }
        .modal-body { padding: 30px; }
        .edit-log { background: #f9f9f9; border-radius: 5px; padding: 20px; }
        .edit-entry { background: white; padding: 15px; border-left: 4px solid #8B4513; margin-bottom: 15px; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .edit-entry-header { display: flex; justify-content: space-between; margin-bottom: 10px; color: #666; font-size: 0.9em; }
        #confirm-delete-btn:hover { background: #c82333 !important; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220,53,69,0.4) !important; }
        #cancel-delete-btn:hover  { background: #5a6268 !important; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(108,117,125,0.3) !important; }
        .toast-message { position: fixed; bottom: -100px; left: 50%; transform: translateX(-50%); padding: 15px 25px; border-radius: 8px; color: white; font-weight: bold; z-index: 10001; transition: bottom 0.3s; }
        .toast-message.show { bottom: 30px; }
        .toast-success { background: #27AE60; }
        /* Drag-to-reorder */
        .drag-handle { cursor: grab; color: #aaa; margin-right: 8px; user-select: none; }
        .drag-handle:active { cursor: grabbing; }
        li.dragging { opacity: 0.4; }
        li.drag-over { border-top: 2px solid #4A90E2; }
        .delete-item-btn { float: right; background: none; border: none; color: #e74c3c; cursor: pointer; font-size: 1.1em; opacity: 0; transition: opacity 0.2s; }
        li:hover .delete-item-btn { opacity: 1; }
        /* Editable title */
        .editable-title[contenteditable="true"] { border-bottom: 2px dashed #8B4513; outline: none; }
        /* Source / meta dropdowns */
        .editable-meta { cursor: pointer; }
        .editable-meta:hover { opacity: 0.8; }
        .meta-dropdown { position: absolute; background: white; border: 1px solid #ddd; border-radius: 5px; padding: 5px 0; z-index: 100; min-width: 150px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-height: 200px; overflow-y: auto; }
        .meta-dropdown-item { padding: 8px 15px; cursor: pointer; font-size: 0.9em; }
        .meta-dropdown-item:hover { background: #f0f0f0; }
        .meta-dropdown-input { width: calc(100% - 20px); margin: 5px 10px; padding: 5px; border: 1px solid #ddd; border-radius: 3px; }
        /* Image carousel */
        .image-carousel { margin: 20px 0; }
        .carousel-container { position: relative; }
        .carousel-viewport { overflow: hidden; border-radius: 10px; }
        .carousel-track { display: flex; transition: transform 0.3s ease; }
        .carousel-slide { min-width: 100%; position: relative; }
        .carousel-image { width: 100%; max-height: 500px; height: auto; object-fit: contain; border-radius: 10px; cursor: pointer; background: #f5f5f5; }
        .carousel-btn { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.5); color: white; border: none; border-radius: 50%; width: 50px; height: 50px; font-size: 2em; cursor: pointer; z-index: 10; }
        .carousel-prev { left: 10px; }
        .carousel-next { right: 10px; }
        .carousel-indicators { text-align: center; margin: 10px 0; color: #666; }
        .add-image-btn { margin-top: 10px; padding: 8px 15px; background: #27AE60; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .replace-image-btn, .delete-image-btn { position: absolute; background: rgba(0,0,0,0.6); color: white; border: none; border-radius: 3px; padding: 4px 8px; cursor: pointer; font-size: 0.8em; }
        .replace-image-btn { bottom: 10px; left: 10px; }
        .delete-image-btn  { bottom: 10px; right: 10px; background: rgba(220,53,69,0.8); }
        .no-image-placeholder { height: 200px; background: #f0e6d6; display: flex; flex-direction: column; align-items: center; justify-content: center; border-radius: 10px; color: #8B4513; }
        /* Lightbox */
        .lightbox { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 9999; align-items: center; justify-content: center; }
        .lightbox.active { display: flex; }
        .lightbox img { max-width: 90%; max-height: 90vh; border-radius: 5px; }
        .lightbox-close { position: absolute; top: 20px; right: 30px; color: white; font-size: 40px; cursor: pointer; }
        /* Reactions */
        .social-bar { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; margin: 15px 0; }
        .fav-btn-page { padding: 8px 16px; border: 2px solid #e74c3c; border-radius: 20px; background: white; color: #e74c3c; cursor: pointer; font-size: 0.9em; transition: all 0.2s; }
        .fav-btn-page.favorited { background: #e74c3c; color: white; }
        .reactions-panel { display: flex; align-items: center; gap: 5px; }
        .reactions-label { color: #666; font-size: 0.9em; }
        .react-btn { padding: 5px 10px; border: 1px solid #ddd; border-radius: 15px; background: white; cursor: pointer; font-size: 1.1em; transition: all 0.2s; }
        .react-btn.reacted { background: #fff3cd; border-color: #ffc107; }
        .reaction-counts { display: flex; gap: 8px; flex-wrap: wrap; }
        .rc-chip { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 12px; padding: 3px 10px; font-size: 0.9em; }
        @media (max-width: 600px) {
            .carousel-image { max-height: 350px; }
            .carousel-btn { width: 40px; height: 40px; font-size: 1.5em; }
        }
    </style>
</head>
<body>
    <div class="recipe-page">
        <div class="recipe-header">
            <a href="<?= $basePath ?>/" class="back-link">← Back to Search</a>
            <h1 id="recipe-title" class="editable-title" contenteditable="false" title="Click to edit title"><?= $recipeTitle ?></h1>
            <?= $sourceHtml ?>

            <!-- Favorites & Reactions -->
            <div class="social-bar" id="social-bar">
                <button class="fav-btn-page" id="fav-btn-page" onclick="toggleFavoritePage()" title="Add to favorites">♡ Favorite</button>
                <div class="reactions-panel">
                    <span class="reactions-label">React:</span>
                    <button class="react-btn" data-emoji="❤️" onclick="toggleReactionPage('❤️')" title="Love it">❤️</button>
                    <button class="react-btn" data-emoji="😋" onclick="toggleReactionPage('😋')" title="Delicious">😋</button>
                    <button class="react-btn" data-emoji="⭐" onclick="toggleReactionPage('⭐')" title="Family favorite">⭐</button>
                    <button class="react-btn" data-emoji="👍" onclick="toggleReactionPage('👍')" title="Great recipe">👍</button>
                </div>
                <div class="reaction-counts" id="reaction-counts"></div>
            </div>

            <div class="meta-section">
                <h3 class="meta-section-title">Recipe Details</h3>
                <div class="meta-tags"><?= $metaHtml ?></div>
            </div>

            <div class="edit-controls">
                <button class="edit-btn edit-btn-secondary" onclick="showEditHistory()">📝 View Edit History</button>
            </div>

            <div id="message-area"></div>
        </div>

        <!-- Image Carousel -->
        <?= $carouselHtml ?>

        <div class="recipe-content">
            <div class="recipe-main">
                <?= $notesHtml ?>

                <section class="recipe-section">
                    <h2>Ingredients</h2>
                    <?= $ingredientsHtml ?>
                    <button class="add-item-btn" id="add-ingredient-btn" onclick="addIngredient()">➕ Add Ingredient</button>
                </section>

                <section class="recipe-section">
                    <h2>Directions</h2>
                    <?= $directionsHtml ?>
                    <button class="add-item-btn" id="add-direction-btn" onclick="addDirection()">➕ Add Direction</button>
                </section>
            </div>

            <div class="recipe-sidebar">
                <h3>Quick Actions</h3>
                <div class="sidebar-actions">
                    <p style="font-size:0.9em;color:#666;margin-bottom:10px;">Click text to edit • Drag with ☰ to reorder</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="delete-modal" class="modal">
        <div class="modal-content" style="max-width:450px;padding:30px;text-align:center;border-radius:15px;box-shadow:0 10px 40px rgba(0,0,0,0.3);">
            <h2 style="color:#dc3545;margin:0 0 20px 0;font-size:1.8em;">⚠️ Confirm Delete</h2>
            <p id="delete-message" style="font-size:1.15em;margin:0 0 30px 0;color:#555;line-height:1.6;"></p>
            <div class="modal-actions" style="display:flex;gap:12px;justify-content:center;">
                <button id="confirm-delete-btn" style="padding:12px 28px;background:#dc3545;color:white;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:1em;transition:all 0.2s;box-shadow:0 2px 8px rgba(220,53,69,0.3);">Delete</button>
                <button id="cancel-delete-btn"  style="padding:12px 28px;background:#6c757d;color:white;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:1em;transition:all 0.2s;box-shadow:0 2px 8px rgba(108,117,125,0.3);">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Edit History Modal -->
    <div id="history-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit History</h2>
                <button class="modal-close" onclick="closeHistoryModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="edit-log" id="edit-log"></div>
            </div>
        </div>
    </div>

    <!-- Lightbox -->
    <div id="lightbox" class="lightbox" onclick="closeLightbox()">
        <span class="lightbox-close">&times;</span>
        <img id="lightbox-img" src="" alt="">
    </div>

    <script>
        const RECIPE_ID   = '<?= $recipeId ?>';
        const originalRecipe = <?= $recipeDataJson ?>;
        let currentRecipe = {...originalRecipe};
        if (!currentRecipe.uuid) currentRecipe.uuid = RECIPE_ID;

        const API_URL = '<?= $basePath ?>/api/index.php';
        const ALL_KNOWN_SOURCES = <?= $allSourcesJson ?>;

        function getCurrentUser() {
            const s = localStorage.getItem('momsrecipes_current_user');
            if (!s) return null;
            try { return JSON.parse(s); } catch(e) { return null; }
        }

        // ── Favorites & Reactions ──────────────────────────────────────────
        let _myReactions = new Set();

        async function loadSocialState() {
            const user = getCurrentUser();
            if (!user) return;
            try {
                const resp = await fetch(API_URL + '?action=get_reactions&recipe_uuid=' + encodeURIComponent(RECIPE_ID) + '&username=' + encodeURIComponent(user.username));
                if (!resp.ok) return;
                const data = await resp.json();
                renderReactionCounts(data.counts || {});
                _myReactions = new Set(data.mine || []);
                document.querySelectorAll('.react-btn').forEach(btn => {
                    if (_myReactions.has(btn.dataset.emoji)) btn.classList.add('reacted');
                });
                const favResp = await fetch(API_URL + '?action=get_favorites&username=' + encodeURIComponent(user.username));
                if (favResp.ok) {
                    const favData = await favResp.json();
                    const isFav = (favData.favorites || []).includes(RECIPE_ID);
                    const btn = document.getElementById('fav-btn-page');
                    if (btn) { btn.textContent = isFav ? '♥ Favorited' : '♡ Favorite'; btn.classList.toggle('favorited', isFav); }
                }
            } catch(e) { console.warn('loadSocialState error:', e); }
        }

        function renderReactionCounts(counts) {
            const el = document.getElementById('reaction-counts');
            if (!el) return;
            el.innerHTML = '';
            ['❤️','😋','⭐','👍'].forEach(emoji => {
                const c = counts[emoji] || 0;
                if (c > 0) {
                    const chip = document.createElement('span');
                    chip.className = 'rc-chip';
                    chip.textContent = emoji + ' ' + c;
                    el.appendChild(chip);
                }
            });
        }

        async function toggleFavoritePage() {
            const user = getCurrentUser();
            if (!user) { showToast('Please log in to favorite recipes', 'error'); return; }
            try {
                const resp = await fetch(API_URL + '?action=toggle_favorite', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({username: user.username, recipe_uuid: RECIPE_ID})
                });
                const data = await resp.json();
                const btn = document.getElementById('fav-btn-page');
                if (btn) { btn.textContent = data.favorited ? '♥ Favorited' : '♡ Favorite'; btn.classList.toggle('favorited', data.favorited); }
            } catch(e) { console.error('toggleFavoritePage error:', e); }
        }

        async function toggleReactionPage(emoji) {
            const user = getCurrentUser();
            if (!user) { showToast('Please log in to react', 'error'); return; }
            try {
                const resp = await fetch(API_URL + '?action=toggle_reaction', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({username: user.username, recipe_uuid: RECIPE_ID, reaction: emoji})
                });
                const data = await resp.json();
                if (data.reacted) { _myReactions.add(emoji); } else { _myReactions.delete(emoji); }
                document.querySelectorAll('.react-btn').forEach(btn => { btn.classList.toggle('reacted', _myReactions.has(btn.dataset.emoji)); });
                // Refresh counts
                const countResp = await fetch(API_URL + '?action=get_reactions&recipe_uuid=' + encodeURIComponent(RECIPE_ID));
                if (countResp.ok) { const d = await countResp.json(); renderReactionCounts(d.counts || {}); }
            } catch(e) { console.error('toggleReactionPage error:', e); }
        }

        // ── Editable title ─────────────────────────────────────────────────
        const titleEl = document.getElementById('recipe-title');
        titleEl.addEventListener('click', () => { titleEl.contentEditable = 'true'; titleEl.focus(); });
        titleEl.addEventListener('blur', async () => {
            titleEl.contentEditable = 'false';
            const newTitle = titleEl.textContent.trim();
            if (!newTitle || newTitle === currentRecipe.title) return;
            const oldTitle = currentRecipe.title;
            const user = getCurrentUser();
            try {
                await fetch(API_URL + '?action=save_recipe', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({uuid: RECIPE_ID, field: 'title', value: newTitle, changed_by: user?.username || 'anonymous', old_value: oldTitle})
                });
                currentRecipe.title = newTitle;
                document.title = newTitle + " - Mom's Recipes";
                showToast('Title updated!', 'success');
            } catch(e) { titleEl.textContent = oldTitle; showToast('Failed to save title', 'error'); }
        });
        titleEl.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); } });

        // ── Editable meta tags ─────────────────────────────────────────────
        function initMetaTagEditing() {
            const metaTypeMap = { meal_type: 'Meal Type', cuisine: 'Cuisine', main_ingredient: 'Main Ingredient', method: 'Method' };
            document.querySelectorAll('.editable-meta').forEach(el => {
                el.addEventListener('click', (e) => {
                    e.stopPropagation();
                    document.querySelectorAll('.meta-dropdown').forEach(d => d.remove());
                    const type = el.dataset.type;
                    const dropdown = document.createElement('div');
                    dropdown.className = 'meta-dropdown';
                    dropdown.style.cssText = 'position:absolute;';
                    const input = document.createElement('input');
                    input.className = 'meta-dropdown-input';
                    input.placeholder = 'Type or select...';
                    input.value = el.classList.contains('empty') ? '' : el.textContent;
                    dropdown.appendChild(input);
                    const existingOptions = type === 'family_source' ? ALL_KNOWN_SOURCES : getMetaOptions(type);
                    existingOptions.forEach(opt => {
                        const item = document.createElement('div');
                        item.className = 'meta-dropdown-item';
                        item.textContent = opt;
                        item.addEventListener('click', () => { selectMetaValue(el, type, opt); dropdown.remove(); });
                        dropdown.appendChild(item);
                    });
                    input.addEventListener('keydown', e => {
                        if (e.key === 'Enter') {
                            const val = input.value.trim();
                            if (val) { selectMetaValue(el, type, val); dropdown.remove(); }
                        } else if (e.key === 'Escape') { dropdown.remove(); }
                    });
                    el.parentNode.style.position = 'relative';
                    el.parentNode.appendChild(dropdown);
                    input.focus();
                });
            });
            document.addEventListener('click', () => document.querySelectorAll('.meta-dropdown').forEach(d => d.remove()));
        }

        function getMetaOptions(type) {
            return [];  // Could fetch from API; keeping simple for now
        }

        async function selectMetaValue(el, type, value) {
            const user = getCurrentUser();
            const oldValue = el.classList.contains('empty') ? '' : el.textContent;
            el.textContent = value;
            el.classList.remove('empty');
            try {
                await fetch(API_URL + '?action=save_recipe', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({uuid: RECIPE_ID, field: type, value: value, changed_by: user?.username || 'anonymous', old_value: oldValue})
                });
                currentRecipe[type === 'family_source' ? 'family_source' : type] = value;
                // Notify index page that a new option was added
                window.dispatchEvent(new CustomEvent('customMetaOptionAdded', {detail: {type, value}}));
                showToast('Saved!', 'success');
            } catch(e) { el.textContent = oldValue || ('+ ' + type); showToast('Save failed', 'error'); }
        }

        // ── Inline ingredient / direction editing ──────────────────────────
        function makeListEditable(listId, fieldName) {
            const list = document.getElementById(listId);
            if (!list) return;
            Array.from(list.children).forEach((li, idx) => {
                li.draggable = true;
                const handle = document.createElement('span');
                handle.className = 'drag-handle';
                handle.textContent = '☰';
                li.prepend(handle);
                const delBtn = document.createElement('button');
                delBtn.className = 'delete-item-btn';
                delBtn.textContent = '✕';
                delBtn.onclick = (e) => { e.stopPropagation(); deleteListItem(li, listId, fieldName); };
                li.appendChild(delBtn);
                li.contentEditable = 'true';
                li.addEventListener('blur', () => saveList(listId, fieldName));
                // Drag events
                li.addEventListener('dragstart', () => { li.classList.add('dragging'); });
                li.addEventListener('dragend',   () => { li.classList.remove('dragging'); saveList(listId, fieldName); });
                li.addEventListener('dragover',  (e) => { e.preventDefault(); li.classList.add('drag-over'); });
                li.addEventListener('dragleave', () => li.classList.remove('drag-over'));
                li.addEventListener('drop', (e) => {
                    e.preventDefault();
                    li.classList.remove('drag-over');
                    const dragging = list.querySelector('.dragging');
                    if (dragging && dragging !== li) list.insertBefore(dragging, li);
                });
            });
        }

        async function saveList(listId, fieldName) {
            const list = document.getElementById(listId);
            if (!list) return;
            const items = Array.from(list.children).map(li => {
                // Remove handle and delete-btn text, keep only the text content
                let text = li.textContent;
                text = text.replace(/^☰/, '').replace(/✕$/, '').trim();
                return text;
            }).filter(Boolean);
            currentRecipe[fieldName] = items;
            const user = getCurrentUser();
            try {
                await fetch(API_URL + '?action=save_recipe', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({uuid: RECIPE_ID, field: fieldName, value: JSON.stringify(items), changed_by: user?.username || 'anonymous'})
                });
            } catch(e) { console.error('saveList error:', e); }
        }

        async function deleteListItem(li, listId, fieldName) {
            li.remove();
            await saveList(listId, fieldName);
            showToast('Item removed', 'success');
        }

        function addIngredient() {
            const list = document.getElementById('ingredients-list');
            const li = document.createElement('li');
            li.contentEditable = 'true';
            li.textContent = '';
            const handle = document.createElement('span');
            handle.className = 'drag-handle';
            handle.textContent = '☰';
            li.prepend(handle);
            const delBtn = document.createElement('button');
            delBtn.className = 'delete-item-btn';
            delBtn.textContent = '✕';
            delBtn.onclick = (e) => { e.stopPropagation(); deleteListItem(li, 'ingredients-list', 'ingredients'); };
            li.appendChild(delBtn);
            li.addEventListener('blur', () => saveList('ingredients-list', 'ingredients'));
            list.appendChild(li);
            li.focus();
        }

        function addDirection() {
            const list = document.getElementById('directions-list');
            const li = document.createElement('li');
            li.contentEditable = 'true';
            li.textContent = '';
            const handle = document.createElement('span');
            handle.className = 'drag-handle';
            handle.textContent = '☰';
            li.prepend(handle);
            const delBtn = document.createElement('button');
            delBtn.className = 'delete-item-btn';
            delBtn.textContent = '✕';
            delBtn.onclick = (e) => { e.stopPropagation(); deleteListItem(li, 'directions-list', 'directions'); };
            li.appendChild(delBtn);
            li.addEventListener('blur', () => saveList('directions-list', 'directions'));
            list.appendChild(li);
            li.focus();
        }

        // ── Image handling ─────────────────────────────────────────────────
        function addNewImage() {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.onchange = async (e) => {
                const file = e.target.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = async (ev) => {
                    const base64 = ev.target.result;
                    const user = getCurrentUser();
                    try {
                        await fetch(API_URL + '?action=save_recipe', {
                            method: 'POST', headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({uuid: RECIPE_ID, field: 'image_data', value: base64, changed_by: user?.username || 'anonymous'})
                        });
                        showToast('Image saved! Refresh to see it.', 'success');
                    } catch(e) { showToast('Failed to save image', 'error'); }
                };
                reader.readAsDataURL(file);
            };
            input.click();
        }

        document.querySelectorAll('.clickable-image').forEach(img => {
            img.addEventListener('click', () => {
                document.getElementById('lightbox-img').src = img.src;
                document.getElementById('lightbox').classList.add('active');
            });
        });
        function closeLightbox() { document.getElementById('lightbox').classList.remove('active'); }

        // ── Edit History ───────────────────────────────────────────────────
        async function showEditHistory() {
            document.getElementById('history-modal').classList.add('active');
            const log = document.getElementById('edit-log');
            log.innerHTML = '<p>Loading...</p>';
            try {
                const resp = await fetch(API_URL + '?action=get_history&recipe_uuid=' + encodeURIComponent(RECIPE_ID));
                const data = await resp.json();
                const history = data.history || [];
                if (!history.length) { log.innerHTML = '<p>No edit history yet.</p>'; return; }
                log.innerHTML = history.map(entry => `
                    <div class="edit-entry">
                        <div class="edit-entry-header">
                            <span><strong>${escHtml(entry.field_name)}</strong> changed by ${escHtml(entry.changed_by || 'unknown')}</span>
                            <span>${escHtml(entry.changed_at || '')}</span>
                        </div>
                        <div><strong>From:</strong> ${escHtml(entry.old_value || '(empty)')}</div>
                        <div><strong>To:</strong>   ${escHtml(entry.new_value || '(empty)')}</div>
                    </div>`).join('');
            } catch(e) { log.innerHTML = '<p>Could not load history.</p>'; }
        }
        function closeHistoryModal() { document.getElementById('history-modal').classList.remove('active'); }

        // ── Utilities ──────────────────────────────────────────────────────
        function escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        function showToast(msg, type = 'success') {
            let t = document.querySelector('.toast-message');
            if (!t) { t = document.createElement('div'); t.className = 'toast-message'; document.body.appendChild(t); }
            t.textContent = msg;
            t.className = 'toast-message toast-' + type + ' show';
            setTimeout(() => t.classList.remove('show'), 3000);
        }

        // ── Carousel ───────────────────────────────────────────────────────
        let _carouselIdx = 0;
        function moveCarousel(dir) {
            const track = document.getElementById('carousel-track');
            if (!track) return;
            const slides = track.children.length;
            _carouselIdx = (_carouselIdx + dir + slides) % slides;
            track.style.transform = 'translateX(-' + (_carouselIdx * 100) + '%)';
        }

        // ── Init ───────────────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            makeListEditable('ingredients-list', 'ingredients');
            makeListEditable('directions-list',  'directions');
            initMetaTagEditing();
            loadSocialState();
        });
    </script>
</body>
</html>
