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

// Image carousel — merge legacy image_filename with new images JSON array
$imageFilename = $recipe['image_filename'] ?? '';
$imageData     = $recipe['image_data'] ?? '';
$extraImages   = json_decode($recipe['images'] ?? '[]', true) ?: [];

// Build ordered list of all images: primary first, then extras
$allImageSrcs = [];
if ($imageFilename) {
    $allImageSrcs[] = ['src' => $basePath . '/images/' . $imageFilename, 'file' => $imageFilename];
} elseif ($imageData) {
    $allImageSrcs[] = ['src' => $imageData, 'file' => ''];
}
foreach ($extraImages as $f) {
    if (!in_array($f, array_column($allImageSrcs, 'file'))) {
        $allImageSrcs[] = ['src' => $basePath . '/images/' . $f, 'file' => $f];
    }
}

$carouselHtml = '';
if (count($allImageSrcs) > 0) {
    $slides = '';
    foreach ($allImageSrcs as $idx => $img) {
        $slides .= '
                    <div class="carousel-slide" data-filename="' . e($img['file']) . '">
                        <img src="' . e($img['src']) . '"
                             alt="' . e($recipe['title']) . '"
                             class="clickable-image carousel-image"
                             data-image-index="' . $idx . '">
                        <button class="replace-image-btn" onclick="replaceImage(this)">Replace</button>
                        <button class="edit-image-btn"    onclick="editImage(this)">✏ Edit</button>
                        <button class="delete-image-btn" onclick="deleteImage(this)">Delete</button>
                    </div>';
    }
    $total = count($allImageSrcs);
    $prevNext = $total > 1
        ? '<button class="carousel-btn carousel-prev" onclick="moveCarousel(-1)">&#8249;</button>'
        . '<button class="carousel-btn carousel-next" onclick="moveCarousel(1)">&#8250;</button>'
        : '';
    $counter = $total > 1
        ? '<div class="carousel-indicators"><span id="carousel-counter">1 / ' . $total . '</span></div>'
        : '';
    $carouselHtml = '
    <div class="image-carousel" id="image-carousel-wrap">
        <div class="carousel-container" id="carousel-container">
            ' . $prevNext . '
            <div class="carousel-viewport">
                <div class="carousel-track" id="carousel-track">' . $slides . '</div>
            </div>
        </div>
        ' . $counter . '
        <div class="carousel-footer">
            <button class="add-image-btn" onclick="addNewImage()">➕ Add Image</button>
        </div>
    </div>';
} else {
    $carouselHtml = '
    <div class="image-carousel no-images" id="image-carousel-wrap">
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

// Family Source (recipe author — editable)
if ($recipe['family_source']) {
    $sourceHtml = '<div class="source-field"><label class="source-label">From:</label>'
                . '<span id="family-source" class="source editable-meta editable-source" data-type="family_source" title="Click to edit">'
                . e($recipe['family_source']) . '</span></div>';
} else {
    $sourceHtml = '<div class="source-field"><label class="source-label">From:</label>'
                . '<span id="family-source" class="source editable-meta editable-source empty" data-type="family_source" title="Click to add source">+ Add Source</span></div>';
}

// Contributor (who digitized it — editable by Paul only)
if ($recipe['contributor']) {
    $contributorHtml = '<div class="source-field"><label class="source-label">Added by:</label>'
                     . '<span id="contributor-field" class="source editable-meta editable-contributor" data-type="contributor" title="Click to edit">'
                     . e($recipe['contributor']) . '</span></div>';
} else {
    $contributorHtml = '<div class="source-field"><label class="source-label">Added by:</label>'
                     . '<span id="contributor-field" class="source editable-meta editable-contributor empty" data-type="contributor" title="Click to add">+ Add Contributor</span></div>';
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

// All sources for dropdown — use the real family_source column
$allSources = $db->getFamilySources();

// All contributors for dropdown — use the contributor column
$allContributors = $db->getContributors();

// Recipe data JSON for JS editing
$recipeDataJson = json_encode([
    'uuid'            => $recipe['uuid'],
    'id'              => $recipe['uuid'],
    'title'           => $recipe['title'],
    'family_source'   => $recipe['family_source'] ?? '',
    'contributor'     => $recipe['contributor'] ?? '',
    'ingredients'     => $ingredients,
    'directions'      => $directions,
    'notes'           => $notes,
    'meal_type'       => $recipe['meal_type'] ?? '',
    'cuisine'         => $recipe['cuisine'] ?? '',
    'main_ingredient' => $recipe['main_ingredient'] ?? '',
    'method'          => $recipe['method'] ?? '',
    'images'          => array_column($allImageSrcs, 'file'),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);

$recipeId      = e($recipe['uuid']);
$recipeTitle   = e($recipe['title']);
$allSourcesJson      = json_encode($allSources, JSON_HEX_TAG);
$allContributorsJson = json_encode($allContributors, JSON_HEX_TAG);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $recipeTitle ?> - Mom's Recipes</title>
    <link rel="stylesheet" href="<?= $basePath ?>/styles.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
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
        .drag-handle { cursor: grab; color: #aaa; user-select: none; font-size: 1em; padding: 0 4px; }
        .drag-handle:active { cursor: grabbing; }
        li.dragging { opacity: 0.4; }
        li.drag-over { border-top: 2px solid #4A90E2; }
        .item-controls { display: inline-flex; align-items: center; gap: 2px; float: right; margin-left: 8px; opacity: 0; transition: opacity 0.2s; }
        li:hover .item-controls { opacity: 1; }
        .delete-item-btn { background: none; border: none; color: #e74c3c; cursor: pointer; font-size: 1.1em; padding: 0 2px; line-height: 1; }
        /* Ingredient / direction inline editing */
        .item-text {
            border-radius: 4px;
            padding: 2px 6px;
            transition: background 0.15s, box-shadow 0.15s;
            cursor: text;
        }
        .item-text:focus {
            background: #fff9e6;
            box-shadow: 0 0 0 2px #ffd700, inset 0 1px 3px rgba(0,0,0,0.08);
            outline: none;
        }
        li:hover .item-text:not(:focus) {
            background: #f5f5f5;
        }
        /* Editable title */
        .editable-title[contenteditable="true"] {
            background: #fff9e6;
            box-shadow: 0 0 0 2px #ffd700;
            border-bottom: 2px dashed #8B4513;
            outline: none;
            border-radius: 4px;
        }
        /* Source / meta dropdowns */
        .editable-meta { cursor: pointer; }
        .editable-meta:hover { opacity: 0.8; }
        .editable-contributor { color: #888; font-size: 0.9em; font-style: italic; cursor: not-allowed; }
        .editable-contributor.paul-editable { cursor: pointer; }
        .editable-contributor.paul-editable:hover { opacity: 0.8; color: #555; }
        .meta-dropdown { position: absolute; background: white; border: 1px solid #ddd; border-radius: 5px; padding: 5px 0; z-index: 100; min-width: 150px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-height: 200px; overflow-y: auto; }
        .meta-dropdown-item { padding: 8px 15px; cursor: pointer; font-size: 0.9em; }
        .meta-dropdown-item:hover { background: #f0f0f0; }
        .meta-dropdown-input { width: calc(100% - 20px); margin: 5px 10px; padding: 5px; border: 1px solid #ddd; border-radius: 3px; }
        .meta-dropdown-item:last-child { border-bottom-left-radius: 5px; border-bottom-right-radius: 5px; }
        /* Image carousel */
        .image-carousel { margin: 20px auto; width: 100%; max-width: 680px; }
        .carousel-container { position: relative; width: 100%; }
        .carousel-viewport { overflow: hidden; border-radius: 10px; width: 100%; }
        .carousel-track { display: flex; transition: transform 0.35s ease; will-change: transform; }
        .carousel-slide { min-width: 100%; position: relative; display: flex; justify-content: center; align-items: center; background: #f9f5f0; border-radius: 10px; cursor: grab; user-select: none; }
        .carousel-image { max-width: 100%; max-height: 480px; width: auto; height: auto; object-fit: contain; border-radius: 10px; cursor: zoom-in; display: block; margin: 0 auto; }
        .carousel-btn { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.5); color: white; border: none; border-radius: 50%; width: 44px; height: 44px; font-size: 1.8em; line-height: 1; cursor: pointer; z-index: 10; transition: background 0.2s; }
        .carousel-btn:hover { background: rgba(0,0,0,0.8); }
        .carousel-prev { left: 8px; }
        .carousel-next { right: 8px; }
        .carousel-indicators { text-align: center; margin: 8px 0; color: #666; font-size: 0.9em; }
        .carousel-footer { margin-top: 8px; text-align: center; }
        .add-image-btn { padding: 8px 16px; background: #27AE60; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 0.9em; }
        .add-image-btn:hover { background: #229954; }
        .replace-image-btn, .delete-image-btn, .edit-image-btn { position: absolute; background: rgba(0,0,0,0.6); color: white; border: none; border-radius: 3px; padding: 4px 10px; cursor: pointer; font-size: 0.8em; }
        .replace-image-btn { bottom: 10px; left: 10px; }
        .edit-image-btn    { bottom: 10px; left: 50%; transform: translateX(-50%); background: rgba(41,128,185,0.85); }
        .delete-image-btn  { bottom: 10px; right: 10px; background: rgba(220,53,69,0.8); }
        .replace-image-btn:hover { background: rgba(0,0,0,0.85); }
        .edit-image-btn:hover    { background: rgba(21,108,165,0.95); }
        .delete-image-btn:hover  { background: rgba(185,28,28,0.95); }

        /* ── Image editor modal ── */
        #img-editor-modal { display:none; position:fixed; inset:0; z-index:9000; background:rgba(0,0,0,0.88); flex-direction:column; align-items:center; justify-content:center; }
        #img-editor-modal.active { display:flex; }
        #img-editor-wrap { position:relative; max-width:90vw; max-height:70vh; width:700px; }
        #img-editor-wrap img { display:block; max-width:100%; max-height:70vh; }
        .editor-toolbar { display:flex; gap:10px; margin-top:14px; flex-wrap:wrap; justify-content:center; }
        .editor-toolbar button { padding:8px 16px; border:none; border-radius:5px; cursor:pointer; font-size:0.9em; color:white; }
        .editor-btn-rotate { background:#7f8c8d; }
        .editor-btn-flip-h { background:#7f8c8d; }
        .editor-btn-flip-v { background:#7f8c8d; }
        .editor-btn-reset  { background:#e67e22; }
        .editor-btn-save   { background:#27ae60; font-weight:bold; padding:8px 28px; }
        .editor-btn-cancel { background:#c0392b; }
        .editor-toolbar button:hover { filter:brightness(1.15); }
        .no-image-placeholder { height: 200px; background: #f0e6d6; display: flex; flex-direction: column; align-items: center; justify-content: center; border-radius: 10px; color: #8B4513; }
        .carousel-slide.dragging  { opacity: 0.4; cursor: grabbing; }
        .carousel-slide.drag-over { outline: 3px dashed #8B4513; outline-offset: -3px; }
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
            .image-carousel { max-width: 100%; }
            .carousel-image { max-height: 320px; }
            .carousel-btn { width: 36px; height: 36px; font-size: 1.4em; }
        }
    </style>
</head>
<body>
    <div class="recipe-page">
        <div class="recipe-header">
            <a href="<?= $basePath ?>/" class="back-link">← Back to Search</a>
            <h1 id="recipe-title" class="editable-title" contenteditable="false" title="Click to edit title"><?= $recipeTitle ?></h1>
            <?= $sourceHtml ?>
            <?= $contributorHtml ?>

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

    <!-- Upload progress overlay -->
    <div id="upload-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:16px;">
        <div style="background:white;border-radius:12px;padding:32px 40px;text-align:center;min-width:260px;box-shadow:0 8px 32px rgba(0,0,0,0.3);">
            <div style="font-size:1.1em;font-weight:600;color:#333;margin-bottom:16px;" id="upload-label">Uploading image…</div>
            <div style="background:#eee;border-radius:8px;height:12px;overflow:hidden;width:100%;">
                <div id="upload-progress-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#27AE60,#2ecc71);border-radius:8px;transition:width 0.2s;"></div>
            </div>
            <div style="margin-top:10px;color:#666;font-size:0.85em;" id="upload-pct">0%</div>
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

        const API_URL      = '<?= $basePath ?>/api/index.php';
        const API_BASE_PATH = '<?= $basePath ?>';
        let ALL_KNOWN_SOURCES      = <?= $allSourcesJson ?>;
        let ALL_KNOWN_CONTRIBUTORS = <?= $allContributorsJson ?>;

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
                // Record the title change in the log
                fetch(API_URL + '?action=add_title_change', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        recipe_uuid: RECIPE_ID,
                        old_title:   oldTitle,
                        new_title:   newTitle,
                        changed_by:  user?.username || 'anonymous'
                    })
                }).catch(() => {}); // fire-and-forget, don't block UI
            } catch(e) { titleEl.textContent = oldTitle; showToast('Failed to save title', 'error'); }
        });
        titleEl.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); } });

        // ── Meta options: built-in + custom (loaded from API) ─────────────
        const META_OPTIONS = {
            meal_type:       ['Breakfast','Lunch','Dinner','Dessert','Snack','Appetizer','Side Dish','Beverage'],
            cuisine:         ['American','Italian','Mexican','Chinese','Japanese','French','Indian','Thai','Mediterranean','Greek','Spanish','Middle Eastern','Scandinavian','German','Other'],
            main_ingredient: ['Chicken','Beef','Pork','Fish','Seafood','Vegetables','Pasta','Rice','Beans','Eggs','Cheese','Bread','Fruit','Nuts','Other'],
            method:          ['Baked','Fried','Grilled','Roasted','Steamed','Boiled','Sautéed','Slow Cooker','Pressure Cooker','No-Bake','Raw','Other']
        };
        let _customMeta = null;

        async function loadCustomMetaOptions() {
            if (_customMeta) return _customMeta;
            try {
                const r = await fetch(API_URL + '?action=get_custom_meta');
                if (r.ok) _customMeta = await r.json();
                else _customMeta = {};
            } catch(e) { _customMeta = {}; }
            return _customMeta;
        }

        async function saveCustomMetaOption(type, value) {
            const user = getCurrentUser();
            try {
                await fetch(API_URL + '?action=add_custom_meta', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ field_type: type, value, added_by: user?.username || 'anonymous' })
                });
                if (!_customMeta) _customMeta = {};
                if (!_customMeta[type]) _customMeta[type] = [];
                if (!_customMeta[type].includes(value)) {
                    _customMeta[type].push(value);
                    _customMeta[type].sort();
                }
            } catch(e) { console.warn('Could not save custom meta option', e); }
        }

        function getAllMetaOptions(type) {
            const builtIn = META_OPTIONS[type] || [];
            const custom  = (_customMeta && _customMeta[type]) ? _customMeta[type] : [];
            return [...new Set([...builtIn, ...custom])].sort();
        }

        // ── Editable meta tags ─────────────────────────────────────────────
        let _canEditContributor = false; // set after check_admin resolves

        async function _checkContributorAccess() {
            const user = getCurrentUser();
            if (!user || !user.username) return false;
            try {
                const resp = await fetch(API_URL + '?action=check_admin&username=' + encodeURIComponent(user.username));
                const data = await resp.json();
                // Super admin or admin can edit contributor
                return data.is_admin === true || data.is_super_admin === true;
            } catch(e) {
                // Fallback: allow paul/pstamey by name if API call fails
                return isPaulUser();
            }
        }

        function initMetaTagEditing() {
            // Async: check admin status then unlock contributor field if allowed
            _checkContributorAccess().then(allowed => {
                _canEditContributor = allowed;
                const contribEl = document.getElementById('contributor-field');
                if (contribEl) {
                    if (allowed) {
                        contribEl.classList.add('paul-editable');
                        contribEl.title = 'Click to edit';
                    } else {
                        contribEl.title = 'Only admins can edit this';
                    }
                }
            });

            document.querySelectorAll('.editable-meta').forEach(el => {
                // Clone to remove any stale listeners
                const fresh = el.cloneNode(true);
                el.replaceWith(fresh);
                fresh.addEventListener('click', (e) => {
                    e.stopPropagation();

                    // Contributor field is admin-only
                    if (fresh.dataset.type === 'contributor' && !_canEditContributor) return;

                    document.querySelectorAll('.meta-dropdown').forEach(d => d.remove());
                    const type = fresh.dataset.type;
                    const currentValue = fresh.classList.contains('empty') ? '' : fresh.textContent.trim();

                    const dropdown = document.createElement('div');
                    dropdown.className = 'meta-dropdown';
                    dropdown.style.cssText = 'position:absolute;z-index:200;';

                    // Type-ahead input
                    const input = document.createElement('input');
                    input.className = 'meta-dropdown-input';
                    input.placeholder = (type === 'family_source' || type === 'contributor')
                        ? 'Type a name and press Enter...' : 'Type or select...';
                    input.value = currentValue;
                    dropdown.appendChild(input);

                    // contributor and family_source use their own known-name lists; others use meta options
                    const allOptions = type === 'contributor'   ? ALL_KNOWN_CONTRIBUTORS :
                                       type === 'family_source' ? ALL_KNOWN_SOURCES : getAllMetaOptions(type);

                    function renderItems(filter) {
                        dropdown.querySelectorAll('.meta-dropdown-item').forEach(i => i.remove());
                        const filtered = filter
                            ? allOptions.filter(o => o.toLowerCase().includes(filter.toLowerCase()))
                            : allOptions;
                        filtered.forEach(opt => {
                            const item = document.createElement('div');
                            item.className = 'meta-dropdown-item';
                            item.textContent = opt;
                            item.addEventListener('mousedown', (ev) => {
                                ev.preventDefault();
                                selectMetaValue(fresh, type, opt);
                                dropdown.remove();
                            });
                            dropdown.appendChild(item);
                        });
                        // ➕ Add Custom... only shown for structured meta fields (not family_source — free-text entry works there)
                        const canAddCustom = type !== 'family_source';
                        if (canAddCustom) {
                            const customItem = document.createElement('div');
                            customItem.className = 'meta-dropdown-item';
                            customItem.style.cssText = 'font-weight:bold;color:#ff69b4;border-top:1px solid #eee;margin-top:4px;padding-top:8px;';
                            customItem.textContent = '➕ Add Custom...';
                            customItem.addEventListener('mousedown', (ev) => {
                                ev.preventDefault();
                                dropdown.remove();
                                showCustomMetaInput(fresh, type, currentValue);
                            });
                            dropdown.appendChild(customItem);
                        }
                    }

                    renderItems('');
                    input.addEventListener('input', () => renderItems(input.value));
                    input.addEventListener('keydown', e => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            const val = input.value.trim();
                            if (val) {
                                // Always save exactly what was typed — never auto-select a list item on Enter
                                if (type !== 'family_source') {
                                    // For structured meta fields, persist unknown values as custom options
                                    const known = getAllMetaOptions(type);
                                    if (!known.map(o => o.toLowerCase()).includes(val.toLowerCase())) {
                                        saveCustomMetaOption(type, val);
                                    }
                                }
                                selectMetaValue(fresh, type, val);
                                dropdown.remove();
                            }
                        } else if (e.key === 'Escape') { dropdown.remove(); }
                    });

                    // Prevent clicks inside the dropdown from bubbling to the
                    // document listener below (which would immediately close it)
                    dropdown.addEventListener('click', e => e.stopPropagation());
                    dropdown.addEventListener('mousedown', e => e.stopPropagation());

                    fresh.parentNode.style.position = 'relative';
                    fresh.parentNode.appendChild(dropdown);
                    input.focus();
                    input.select();
                });
            });
            document.addEventListener('click', () => document.querySelectorAll('.meta-dropdown').forEach(d => d.remove()));
        }

        function showCustomMetaInput(el, type, currentValue) {
            document.querySelectorAll('.meta-dropdown').forEach(d => d.remove());
            const dropdown = document.createElement('div');
            dropdown.className = 'meta-dropdown';
            dropdown.style.cssText = 'position:absolute;z-index:200;';
            const input = document.createElement('input');
            input.className = 'meta-dropdown-input';
            input.placeholder = 'Enter custom value, then Enter';
            input.style.minWidth = '180px';
            dropdown.appendChild(input);
            dropdown.addEventListener('click', e => e.stopPropagation());
            dropdown.addEventListener('mousedown', e => e.stopPropagation());
            el.parentNode.style.position = 'relative';
            el.parentNode.appendChild(dropdown);
            input.focus();
            input.addEventListener('keydown', async (e) => {
                if (e.key === 'Enter') {
                    const val = input.value.trim();
                    if (val) {
                        await saveCustomMetaOption(type, val);
                        selectMetaValue(el, type, val);
                    }
                    dropdown.remove();
                } else if (e.key === 'Escape') {
                    dropdown.remove();
                }
            });
            input.addEventListener('blur', () => setTimeout(() => dropdown.remove(), 300));
        }

        function isPaulUser() {
            const user = getCurrentUser();
            if (!user || !user.username) return false;
            const u = user.username.toLowerCase();
            return u === 'paul' || u === 'pstamey';
        }

        async function selectMetaValue(el, type, value) {
            const user = getCurrentUser();
            const oldValue = el.classList.contains('empty') ? '' : el.textContent.trim();

            // Optimistically update the UI immediately
            el.textContent = value;
            el.classList.remove('empty');

            try {
                if (type === 'family_source') {
                    // Check if the typed value matches an existing source (case-insensitive)
                    // If so, use the canonical existing version to avoid duplicates
                    const existing = ALL_KNOWN_SOURCES.find(
                        s => s.toLowerCase() === value.toLowerCase()
                    );
                    const canonical = existing || value;

                    // Update UI to canonical version in case casing differed
                    el.textContent = canonical;

                    await fetch(API_URL + '?action=save_recipe', {
                        method: 'POST', headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({uuid: RECIPE_ID, field: 'family_source', value: canonical, changed_by: user?.username || 'anonymous', old_value: oldValue})
                    });

                    // Add to in-memory list if it's genuinely new
                    if (!existing) {
                        ALL_KNOWN_SOURCES.push(canonical);
                        ALL_KNOWN_SOURCES.sort();
                    }
                    currentRecipe.family_source = canonical;
                    showToast('Source saved!', 'success');
                } else if (type === 'contributor') {
                    // Paul-only — deduplicate against known contributors (case-insensitive)
                    if (!isPaulUser()) return;
                    const existing = ALL_KNOWN_CONTRIBUTORS.find(
                        s => s.toLowerCase() === value.toLowerCase()
                    );
                    const canonical = existing || value;
                    el.textContent = canonical;
                    await fetch(API_URL + '?action=save_recipe', {
                        method: 'POST', headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({uuid: RECIPE_ID, field: 'contributor', value: canonical, changed_by: user?.username || 'anonymous', old_value: oldValue})
                    });
                    if (!existing) {
                        ALL_KNOWN_CONTRIBUTORS.push(canonical);
                        ALL_KNOWN_CONTRIBUTORS.sort();
                    }
                    currentRecipe.contributor = canonical;
                    showToast('Contributor saved!', 'success');
                } else {
                    // All other meta fields — save just this recipe
                    await fetch(API_URL + '?action=save_recipe', {
                        method: 'POST', headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({uuid: RECIPE_ID, field: type, value: value, changed_by: user?.username || 'anonymous', old_value: oldValue})
                    });
                    currentRecipe[type] = value;
                    window.dispatchEvent(new CustomEvent('customMetaOptionAdded', {detail: {type, value}}));
                    showToast('Saved!', 'success');
                }
            } catch(e) {
                el.textContent = oldValue || ('+ ' + type);
                showToast('Save failed: ' + e.message, 'error');
            }
        }

        function showToastWithAction(msg, btnLabel, onAction) {
            let t = document.querySelector('.toast-message');
            if (!t) { t = document.createElement('div'); t.className = 'toast-message'; document.body.appendChild(t); }
            t.innerHTML = '';
            const msgSpan = document.createElement('span');
            msgSpan.textContent = msg;
            t.appendChild(msgSpan);
            const btn = document.createElement('button');
            btn.textContent = btnLabel;
            btn.style.cssText = 'margin-left:12px;background:white;color:#8B4513;border:none;border-radius:5px;padding:4px 10px;cursor:pointer;font-weight:bold;font-size:0.9em;';
            btn.addEventListener('click', () => { t.classList.remove('show'); onAction(); });
            t.appendChild(btn);
            t.className = 'toast-message toast-success show';
            clearTimeout(t._hideTimer);
            t._hideTimer = setTimeout(() => t.classList.remove('show'), 8000);
        }

        // ── Inline ingredient / direction editing ──────────────────────────

        // Shared helper — builds a fully wired <li> for both existing and new items
        function buildListItem(listId, fieldName, initialText = '') {
            const list = document.getElementById(listId);
            const li = document.createElement('li');
            li.draggable = true;
            li.style.display = 'flex';
            li.style.alignItems = 'baseline';
            li.style.gap = '6px';

            // Editable text span
            const textSpan = document.createElement('span');
            textSpan.className = 'item-text';
            textSpan.contentEditable = 'true';
            textSpan.textContent = initialText;
            textSpan.style.flex = '1';
            textSpan.style.minWidth = '0';
            textSpan.addEventListener('blur', () => saveList(listId, fieldName));
            textSpan.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); textSpan.blur(); } });
            textSpan.addEventListener('paste', (e) => {
                e.preventDefault();
                const plain = (e.clipboardData || window.clipboardData).getData('text/plain');
                document.execCommand('insertText', false, plain);
            });
            li.appendChild(textSpan);

            // Right-aligned controls: drag handle + delete
            const controls = document.createElement('span');
            controls.className = 'item-controls';
            controls.style.flexShrink = '0';
            const handle = document.createElement('span');
            handle.className = 'drag-handle';
            handle.textContent = '☰';
            const delBtn = document.createElement('button');
            delBtn.className = 'delete-item-btn';
            delBtn.textContent = '✕';
            delBtn.onclick = (e) => { e.stopPropagation(); deleteListItem(li, listId, fieldName); };
            controls.appendChild(handle);
            controls.appendChild(delBtn);
            li.appendChild(controls);

            // Drag events — reordering handled by list-level dragover in makeListEditable
            li.addEventListener('dragstart', () => { li.classList.add('dragging'); });
            li.addEventListener('dragend',   () => { li.classList.remove('dragging'); });

            return { li, textSpan };
        }

        function makeListEditable(listId, fieldName) {
            const list = document.getElementById(listId);
            if (!list) return;
            Array.from(list.children).forEach((oldLi) => {
                const text = oldLi.textContent.trim();
                const { li, textSpan } = buildListItem(listId, fieldName, text);
                list.replaceChild(li, oldLi);
            });
            // Allow dropping after the last item by listening on the list itself
            list.addEventListener('dragover', (e) => {
                e.preventDefault();
                const dragging = list.querySelector('.dragging');
                if (!dragging) return;
                const afterEl = getDragAfterElement(list, e.clientY);
                if (afterEl === null) {
                    list.appendChild(dragging);
                } else {
                    list.insertBefore(dragging, afterEl);
                }
            });
            list.addEventListener('dragend', () => saveList(listId, fieldName));
        }

        // Returns the element the dragged item should be inserted before,
        // or null if it should go at the end.
        function getDragAfterElement(list, y) {
            const draggableEls = [...list.querySelectorAll('li:not(.dragging)')];
            return draggableEls.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset, element: child };
                }
                return closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element ?? null;
        }

        async function saveList(listId, fieldName) {
            const list = document.getElementById(listId);
            if (!list) return;
            const items = Array.from(list.children).map(li => {
                // Read from the editable text span if present, else fall back to full text
                const textSpan = li.querySelector('.item-text');
                if (textSpan) return textSpan.textContent.trim();
                const clone = li.cloneNode(true);
                const ctrl = clone.querySelector('.item-controls');
                if (ctrl) ctrl.remove();
                return clone.textContent.trim();
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
            const { li, textSpan } = buildListItem('ingredients-list', 'ingredients', '');
            list.appendChild(li);
            textSpan.focus();
        }

        function addDirection() {
            const list = document.getElementById('directions-list');
            const { li, textSpan } = buildListItem('directions-list', 'directions', '');
            list.appendChild(li);
            textSpan.focus();
        }

                // ── Image carousel ─────────────────────────────────────────────────
        let _carouselIdx = 0;

        // ── Navigation ─────────────────────────────────────────────────────
        function moveCarousel(dir) {
            const track = document.getElementById('carousel-track');
            if (!track) return;
            const slides = Array.from(track.children);
            if (!slides.length) return;
            _carouselIdx = (_carouselIdx + dir + slides.length) % slides.length;
            track.style.transform = `translateX(-${_carouselIdx * 100}%)`;
            _updateCounter(slides.length);
        }

        function _updateCounter(total) {
            const el = document.getElementById('carousel-counter');
            if (el) el.textContent = `${_carouselIdx + 1} / ${total}`;
        }

        function _rebuildNav() {
            const track  = document.getElementById('carousel-track');
            const wrap   = document.getElementById('image-carousel-wrap');
            const container = document.getElementById('carousel-container');
            if (!track || !wrap || !container) return;
            const total = track.children.length;

            // Remove existing nav buttons
            container.querySelectorAll('.carousel-btn').forEach(b => b.remove());

            if (total > 1) {
                const prev = document.createElement('button');
                prev.className = 'carousel-btn carousel-prev';
                prev.innerHTML = '&#8249;';
                prev.onclick = () => moveCarousel(-1);

                const next = document.createElement('button');
                next.className = 'carousel-btn carousel-next';
                next.innerHTML = '&#8250;';
                next.onclick = () => moveCarousel(1);

                container.prepend(prev);
                container.appendChild(next);
            }

            // Counter
            let ind = document.getElementById('carousel-counter');
            if (total > 1) {
                if (!ind) {
                    const div = document.createElement('div');
                    div.className = 'carousel-indicators';
                    div.innerHTML = '<span id="carousel-counter"></span>';
                    const footer = wrap.querySelector('.carousel-footer');
                    footer ? wrap.insertBefore(div, footer) : wrap.appendChild(div);
                    ind = document.getElementById('carousel-counter');
                }
                _carouselIdx = Math.min(_carouselIdx, total - 1);
                track.style.transform = `translateX(-${_carouselIdx * 100}%)`;
                _updateCounter(total);
            } else {
                ind?.closest('.carousel-indicators')?.remove();
                _carouselIdx = 0;
                track.style.transform = 'translateX(0)';
            }
        }

        // ── Slide helpers ───────────────────────────────────────────────────
        function _makeSlide(src, filename) {
            const slide = document.createElement('div');
            slide.className = 'carousel-slide';
            slide.dataset.filename = filename;
            slide.draggable = true;
            slide.innerHTML = `
                <img src="${src}" alt="" class="clickable-image carousel-image">
                <button class="replace-image-btn" onclick="replaceImage(this)">Replace</button>
                <button class="edit-image-btn"    onclick="editImage(this)">✏ Edit</button>
                <button class="delete-image-btn" onclick="deleteImage(this)">Delete</button>`;
            slide.querySelector('.clickable-image').addEventListener('click', _openFullscreen);
            _attachDrag(slide);
            return slide;
        }

        function _addSlide(src, filename) {
            let track = document.getElementById('carousel-track');
            const wrap = document.getElementById('image-carousel-wrap');

            // Convert "no images" placeholder to real carousel
            if (wrap && wrap.classList.contains('no-images')) {
                wrap.classList.remove('no-images');
                wrap.innerHTML = `
                    <div class="carousel-container" id="carousel-container">
                        <div class="carousel-viewport">
                            <div class="carousel-track" id="carousel-track"></div>
                        </div>
                    </div>
                    <div class="carousel-footer">
                        <button class="add-image-btn" onclick="addNewImage()">➕ Add Image</button>
                    </div>`;
                track = document.getElementById('carousel-track');
            }
            if (!track) return;

            track.appendChild(_makeSlide(src, filename));
            _carouselIdx = track.children.length - 1;
            _rebuildNav();
        }

        function _removeSlide(filename) {
            const track = document.getElementById('carousel-track');
            if (!track) return;
            const slide = track.querySelector(`[data-filename="${CSS.escape(filename)}"]`);
            if (slide) {
                const idx = Array.from(track.children).indexOf(slide);
                slide.remove();
                if (_carouselIdx >= idx && _carouselIdx > 0) _carouselIdx--;
            }
            const total = track.children.length;
            if (total === 0) {
                const wrap = document.getElementById('image-carousel-wrap');
                if (wrap) {
                    wrap.classList.add('no-images');
                    wrap.innerHTML = `
                        <div class="no-image-placeholder">
                            <p>📷 No images yet</p>
                            <button class="add-image-btn" onclick="addNewImage()">➕ Add Image</button>
                        </div>`;
                }
            } else {
                _rebuildNav();
            }
        }

        // ── File picker helper ──────────────────────────────────────────────
        // ── Upload progress overlay ─────────────────────────────────────────
        function _showUploadOverlay(label) {
            const overlay = document.getElementById('upload-overlay');
            document.getElementById('upload-label').textContent = label || 'Uploading image…';
            document.getElementById('upload-progress-bar').style.width = '0%';
            document.getElementById('upload-pct').textContent = '0%';
            overlay.style.display = 'flex';
        }
        function _updateUploadProgress(pct) {
            document.getElementById('upload-progress-bar').style.width = pct + '%';
            document.getElementById('upload-pct').textContent = Math.round(pct) + '%';
        }
        function _hideUploadOverlay() {
            document.getElementById('upload-overlay').style.display = 'none';
        }

        // XHR-based upload with real progress tracking
        function _uploadImage(base64, label) {
            return new Promise((resolve, reject) => {
                const user = getCurrentUser();
                const payload = JSON.stringify({
                    uuid: RECIPE_ID, image_data: base64,
                    changed_by: user?.username || 'anonymous'
                });
                _showUploadOverlay(label || 'Uploading image…');
                const xhr = new XMLHttpRequest();
                xhr.open('POST', API_URL + '?action=add_image');
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.upload.onprogress = e => {
                    if (e.lengthComputable) _updateUploadProgress((e.loaded / e.total) * 100);
                };
                xhr.onload = () => {
                    _updateUploadProgress(100);
                    setTimeout(() => {
                        _hideUploadOverlay();
                        try { resolve({ ok: xhr.status < 300, data: JSON.parse(xhr.responseText) }); }
                        catch(e) { reject(new Error('Bad response')); }
                    }, 300);
                };
                xhr.onerror = () => { _hideUploadOverlay(); reject(new Error('Network error')); };
                xhr.send(payload);
            });
        }

        function _pickFile(cb) {
            const input = document.createElement('input');
            input.type = 'file'; input.accept = 'image/*';
            input.onchange = e => {
                const file = e.target.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = ev => cb(ev.target.result, file.name);
                reader.readAsDataURL(file);
            };
            input.click();
        }

        // ── Add image ───────────────────────────────────────────────────────
        async function addNewImage() {
            _pickFile(async (base64) => {
                try {
                    const { ok, data } = await _uploadImage(base64, 'Adding image…');
                    if (ok && data.success) {
                        _addSlide(API_BASE_PATH + '/images/' + data.filename, data.filename);
                        showToast('Image added!', 'success');
                    } else {
                        showToast('Upload failed: ' + (data.error || 'unknown'), 'error');
                    }
                } catch(e) { _hideUploadOverlay(); showToast('Upload failed: ' + e.message, 'error'); }
            });
        }

        // ── Replace image ───────────────────────────────────────────────────
        async function replaceImage(btn) {
            const slide = btn.closest('.carousel-slide');
            if (!slide) return;
            const oldFilename = slide.dataset.filename;
            _pickFile(async (base64) => {
                try {
                    if (oldFilename) {
                        fetch(API_URL + '?action=delete_image', {
                            method: 'POST', headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ uuid: RECIPE_ID, filename: oldFilename })
                        });
                    }
                    const { ok, data } = await _uploadImage(base64, 'Replacing image…');
                    if (ok && data.success) {
                        const img = slide.querySelector('img');
                        if (img) img.src = API_BASE_PATH + '/images/' + data.filename;
                        slide.dataset.filename = data.filename;
                        showToast('Image replaced!', 'success');
                    } else {
                        showToast('Replace failed: ' + (data.error || 'unknown'), 'error');
                    }
                } catch(e) { _hideUploadOverlay(); showToast('Replace failed: ' + e.message, 'error'); }
            });
        }

        // ── Delete image ────────────────────────────────────────────────────
        async function deleteImage(btn) {
            const slide = btn.closest('.carousel-slide');
            if (!slide) return;
            const filename = slide.dataset.filename;
            if (!filename) { _removeSlide(''); return; }
            if (!confirm('Delete this image?')) return;
            const user = getCurrentUser();
            try {
                const resp = await fetch(API_URL + '?action=delete_image', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ uuid: RECIPE_ID, filename,
                                          changed_by: user?.username || 'anonymous' })
                });
                const data = await resp.json();
                if (resp.ok && data.success) {
                    _removeSlide(filename);
                    showToast('Image deleted!', 'success');
                } else {
                    showToast('Delete failed: ' + (data.error || 'unknown'), 'error');
                }
            } catch(e) { showToast('Delete failed: ' + e.message, 'error'); }
        }

        // ── Fullscreen click ────────────────────────────────────────────────
        function _openFullscreen() {
            const src = this.src;
            if (!src) return;
            if (src.startsWith('data:')) {
                const win = window.open('', '_blank');
                if (win) {
                    win.document.write('<!DOCTYPE html><html><head><title>Image</title></head>'
                        + '<body style="margin:0;background:#111;display:flex;align-items:center;'
                        + 'justify-content:center;min-height:100vh;">'
                        + `<img src="${src}" style="max-width:100%;max-height:100vh;object-fit:contain;">`
                        + '</body></html>');
                    win.document.close();
                }
            } else {
                window.open(src, '_blank');
            }
        }
        // Wire up existing page-load images
        document.querySelectorAll('.clickable-image').forEach(img => {
            img.addEventListener('click', _openFullscreen);
        });

        // ── Drag-to-reorder ─────────────────────────────────────────────────
        let _dragSrc = null;

        function _attachDrag(slide) {
            slide.addEventListener('dragstart', e => {
                _dragSrc = slide;
                slide.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            slide.addEventListener('dragend', () => {
                slide.classList.remove('dragging');
                document.querySelectorAll('.carousel-slide').forEach(s => s.classList.remove('drag-over'));
                _dragSrc = null;
                _saveImageOrder();
            });
            slide.addEventListener('dragover', e => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                if (_dragSrc && _dragSrc !== slide) slide.classList.add('drag-over');
            });
            slide.addEventListener('dragleave', () => slide.classList.remove('drag-over'));
            slide.addEventListener('drop', e => {
                e.preventDefault();
                slide.classList.remove('drag-over');
                if (!_dragSrc || _dragSrc === slide) return;
                const track = document.getElementById('carousel-track');
                const kids  = Array.from(track.children);
                const srcIdx = kids.indexOf(_dragSrc);
                const tgtIdx = kids.indexOf(slide);
                if (srcIdx < tgtIdx) track.insertBefore(_dragSrc, slide.nextSibling);
                else                  track.insertBefore(_dragSrc, slide);
                _carouselIdx = Array.from(track.children).indexOf(_dragSrc);
                _rebuildNav();
            });
        }

        // Wire drag onto existing page-load slides
        document.querySelectorAll('.carousel-slide').forEach(s => {
            s.draggable = true;
            _attachDrag(s);
        });

        async function _saveImageOrder() {
            const track = document.getElementById('carousel-track');
            if (!track) return;
            const order = Array.from(track.children).map(s => s.dataset.filename).filter(Boolean);
            const user = getCurrentUser();
            try {
                await fetch(API_URL + '?action=reorder_images', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ uuid: RECIPE_ID, order,
                                          changed_by: user?.username || 'anonymous' })
                });
            } catch(e) { console.warn('Could not save image order:', e); }
        }

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

        // ── Init ───────────────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            makeListEditable('ingredients-list', 'ingredients');
            makeListEditable('directions-list',  'directions');
            loadCustomMetaOptions().then(() => {
                initMetaTagEditing();
            });
            loadSocialState();
        });
    </script>

    <!-- ── Cropper.js ── -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>

    <!-- ── Image editor modal ── -->
    <div id="img-editor-modal">
        <div id="img-editor-wrap">
            <img id="img-editor-src" src="" alt="Edit image">
        </div>
        <div class="editor-toolbar">
            <button class="editor-btn-rotate" onclick="_editorRotate(-90)">↺ Rotate L</button>
            <button class="editor-btn-rotate" onclick="_editorRotate(90)">↻ Rotate R</button>
            <button class="editor-btn-flip-h" onclick="_editorFlip('h')">⇄ Flip H</button>
            <button class="editor-btn-flip-v" onclick="_editorFlip('v')">↕ Flip V</button>
            <button class="editor-btn-reset"  onclick="_editorReset()">⟳ Reset</button>
            <button class="editor-btn-save"   onclick="_editorSave()">✓ Save</button>
            <button class="editor-btn-cancel" onclick="_editorClose()">✕ Cancel</button>
        </div>
    </div>

    <script>
    // ── Image editor (Cropper.js) ───────────────────────────────────────────
    let _cropper      = null;
    let _editSlide    = null;   // the .carousel-slide being edited
    let _editFilename = null;

    async function editImage(btn) {
        const slide = btn.closest('.carousel-slide');
        if (!slide) return;
        const img = slide.querySelector('img');
        if (!img || !img.src) return;
        _editSlide    = slide;
        _editFilename = slide.dataset.filename;

        document.getElementById('img-editor-modal').classList.add('active');

        // Destroy previous instance if any
        if (_cropper) { _cropper.destroy(); _cropper = null; }

        const editorImg = document.getElementById('img-editor-src');

        // Fetch image as blob URL so getCroppedCanvas() never hits canvas taint errors
        try {
            const fetchResp = await fetch(img.src);
            const blob = await fetchResp.blob();
            const blobUrl = URL.createObjectURL(blob);
            if (editorImg._blobUrl) URL.revokeObjectURL(editorImg._blobUrl);
            editorImg._blobUrl = blobUrl;
            editorImg.src = blobUrl;
        } catch(e) {
            editorImg.src = img.src; // fallback
        }

        editorImg.onload = () => {
            if (_cropper) { _cropper.destroy(); _cropper = null; }
            _cropper = new Cropper(editorImg, {
                viewMode: 1,
                autoCropArea: 0.9,
                responsive: true,
                checkOrientation: false
            });
        };
    }

    function _editorRotate(deg) { _cropper && _cropper.rotate(deg); }
    function _editorFlip(axis) {
        if (!_cropper) return;
        const d = _cropper.getData();
        if (axis === 'h') _cropper.scaleX(d.scaleX === -1 ? 1 : -1);
        else              _cropper.scaleY(d.scaleY === -1 ? 1 : -1);
    }
    function _editorReset() { _cropper && _cropper.reset(); }

    function _editorClose() {
        document.getElementById('img-editor-modal').classList.remove('active');
        if (_cropper) { _cropper.destroy(); _cropper = null; }
        _editSlide = _editFilename = null;
    }

    async function _editorSave() {
        if (!_cropper || !_editSlide || !_editFilename) {
            console.error('Editor save: missing cropper, slide, or filename', { _cropper: !!_cropper, _editSlide: !!_editSlide, _editFilename });
            return;
        }

        let canvas;
        try {
            canvas = _cropper.getCroppedCanvas({ maxWidth: 1600, maxHeight: 1600, imageSmoothingQuality: 'high' });
        } catch(e) {
            console.error('getCroppedCanvas failed:', e);
            showToast('Could not read image for editing: ' + e.message, 'error');
            return;
        }

        if (!canvas) {
            console.error('getCroppedCanvas returned null');
            showToast('Could not process image — try again', 'error');
            return;
        }

        const base64 = canvas.toDataURL('image/jpeg', 0.88);
        // Keep a local ref to slide/filename before closing (close resets them)
        const savedSlide    = _editSlide;
        const savedFilename = _editFilename;

        _editorClose();
        _showUploadOverlay('Saving edited image…');

        try {
            const user = getCurrentUser();
            const resp = await fetch(API_URL + '?action=save_edited_image', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    uuid: RECIPE_ID,
                    old_filename: savedFilename,
                    image_data: base64,
                    changed_by: user?.username || 'anonymous'
                })
            });
            _hideUploadOverlay();
            const data = await resp.json();
            console.log('save_edited_image response:', data);
            if (resp.ok && data.success) {
                const img = savedSlide.querySelector('img');
                if (img) img.src = API_BASE_PATH + '/images/' + data.filename + '?v=' + Date.now();
                savedSlide.dataset.filename = data.filename;
                showToast('Image saved!', 'success');
            } else {
                showToast('Save failed: ' + (data.error || 'unknown'), 'error');
            }
        } catch(e) {
            _hideUploadOverlay();
            console.error('save_edited_image fetch error:', e);
            showToast('Save failed: ' + e.message, 'error');
        }
    }
    </script>
</body>
</html>
