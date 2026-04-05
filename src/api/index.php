<?php
/**
 * Recipe API Backend - Main Endpoint
 * Handles all recipe operations with SQLite database
 * Designed for Namecheap shared hosting
 */

// Error reporting for development - comment out for production
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS headers for cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database and helper functions
require_once 'config.php';
require_once 'database.php';
require_once 'helpers.php';
require_once __DIR__ . '/mailer.php';

// Initialize database
$db = new RecipeDatabase();

// Parse the request
$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Remove the base path - try multiple patterns to be flexible
$originalPath = $path;
$path = preg_replace('#^/momsrecipes/api/#', '', $path);  // Full path
$path = preg_replace('#^/api/#', '', $path);              // Just /api/
$path = preg_replace('#^/momsrecipes/api/index\.php/#', '', $path);  // With index.php
$path = preg_replace('#^/api/index\.php/#', '', $path);   // With index.php
$path = str_replace('index.php/', '', $path);             // Any remaining index.php
$path = str_replace('index.php', '', $path);              // Standalone index.php
$path = trim($path, '/');

// Debug mode - uncomment to see path processing
// error_log("REQUEST_URI: $requestUri | Original Path: $originalPath | Final Path: $path");

// Get request body for POST/PUT requests
$input = null;
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $input = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            sendResponse(400, ['error' => 'Invalid JSON in request body']);
        }
    }
}

// Handle legacy ?action= style requests from frontend
$action = $_GET['action'] ?? null;
if ($action) {
    switch ($action) {
        case 'get_blog':
            $posts = $db->getBlogPosts();
            sendResponse(200, $posts ?? []);
            break;

        case 'get_title_changes':
            try {
                $titleChanges = $db->getTitleChanges();
                sendResponse(200, $titleChanges ?? []);
            } catch (Exception $e) {
                sendResponse(200, []);
            }
            break;

        case 'save_blog':
            $required = ['title', 'content'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    sendResponse(400, ['error' => "Missing required field: $field"]);
                }
            }
            $postId = $db->createBlogPost($input);
            $post = $db->getBlogPost($postId);
            sendResponse(201, ['post' => $post, 'message' => 'Blog post created successfully']);
            break;

        case 'delete_blog':
            $postId = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$postId) sendResponse(400, ['error' => 'Missing post id']);
            $success = $db->deleteBlogPost($postId);
            sendResponse($success ? 200 : 500, ['message' => $success ? 'Deleted' : 'Failed to delete']);
            break;

        case 'create_blog':
            if (empty($input['content'])) sendResponse(400, ['error' => 'Missing required field: content']);
            $input['title'] = $input['title'] ?? '';
            $postId = $db->createBlogPost($input);
            $post = $db->getBlogPost($postId);
            sendResponse(201, $post);
            break;

        case 'like_blog':
            $postId = (int)($_GET['id'] ?? 0);
            if (!$postId) sendResponse(400, ['error' => 'Missing post id']);
            $post = $db->getBlogPost($postId);
            if (!$post) sendResponse(404, ['error' => 'Post not found']);
            $likes = json_decode($post['likes'] ?? '[]', true) ?: [];
            $user = $input['user'] ?? $_GET['user'] ?? 'anonymous';
            if (!in_array($user, $likes)) $likes[] = $user;
            $db->updateBlogPostField($postId, 'likes', json_encode($likes));
            sendResponse(200, ['likes' => $likes]);
            break;

        case 'reply_blog':
            $postId = (int)($_GET['id'] ?? $input['id'] ?? 0);
            if (!$postId) sendResponse(400, ['error' => 'Missing post id']);
            $post = $db->getBlogPost($postId);
            if (!$post) sendResponse(404, ['error' => 'Post not found']);
            $replies = json_decode($post['replies'] ?? '[]', true) ?: [];
            $replies[] = [
                'id'      => uniqid(),
                'author'  => $input['author'] ?? $input['user'] ?? 'Anonymous',
                'content' => $input['content'] ?? '',
                'date'    => gmdate('c')
            ];
            $db->updateBlogPostField($postId, 'replies', json_encode($replies));
            sendResponse(200, ['replies' => $replies]);
            break;

        case 'delete_reply':
            $postId  = (int)($_GET['id'] ?? $input['id'] ?? 0);
            $replyId = $input['reply_id'] ?? $_GET['reply_id'] ?? null;
            if (!$postId) sendResponse(400, ['error' => 'Missing post id']);
            $post = $db->getBlogPost($postId);
            if (!$post) sendResponse(404, ['error' => 'Post not found']);
            $replies = json_decode($post['replies'] ?? '[]', true) ?: [];
            $replies = array_values(array_filter($replies, fn($r) => $r['id'] !== $replyId));
            $db->updateBlogPostField($postId, 'replies', json_encode($replies));
            sendResponse(200, ['replies' => $replies]);
            break;

        case 'get_recipe':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) sendResponse(400, ['error' => 'Missing recipe id']);
            $recipe = $db->getRecipe($id);
            if (!$recipe) sendResponse(404, ['error' => 'Recipe not found']);
            sendResponse(200, $recipe);
            break;

        case 'save_recipe':
            $uuid = $input['uuid'] ?? '';
            $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
            if (!$id && $uuid) $id = $db->resolveRecipeId($uuid);
            if (!$id) sendResponse(400, ['error' => 'Missing recipe id or uuid']);
            if (!$db->getRecipe($id)) sendResponse(404, ['error' => 'Recipe not found']);
            // If saving image_data (base64), write to images/ folder and store filename
            if (($input['field'] ?? '') === 'image_data' && !empty($input['value'])) {
                $base64 = $input['value'];
                $base64clean = preg_replace('#^data:image/\w+;base64,#', '', $base64);
                $ext = 'jpg';
                if (preg_match('#^data:image/(\w+);#', $input['value'], $m)) $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                $filename = ($uuid ?: $id) . '_' . time() . '.' . $ext;
                $imgDir = __DIR__ . '/../images/';
                if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
                file_put_contents($imgDir . $filename, base64_decode($base64clean));
                $input['field'] = 'image_filename';
                $input['value'] = $filename;
                $db->raw()->prepare("UPDATE recipes SET image_data=NULL WHERE id=:id")->execute([':id' => $id]);
            }
            // Single-field save: copy value into the named key so updateRecipe picks it up
            if (!empty($input['field']) && array_key_exists('value', $input)) {
                $input[$input['field']] = $input['value'];
            }
            $db->updateRecipe($id, $input);
            $recipe = $db->getRecipe($id);
            sendResponse(200, $recipe);
            break;

        case 'add_image':
            // Upload a new image and append to the recipe's images array
            $uuid = $input['uuid'] ?? '';
            $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
            if (!$id && $uuid) $id = $db->resolveRecipeId($uuid);
            if (!$id) sendResponse(400, ['error' => 'Missing recipe id or uuid']);
            if (empty($input['image_data'])) sendResponse(400, ['error' => 'Missing image_data']);

            $base64 = $input['image_data'];
            $base64clean = preg_replace('#^data:image/\w+;base64,#', '', $base64);
            $ext = 'jpg';
            if (preg_match('#^data:image/(\w+);#', $base64, $m)) $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
            $filename = ($uuid ?: $id) . '_' . time() . '_' . uniqid() . '.' . $ext;
            $imgDir = __DIR__ . '/../images/';
            if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
            file_put_contents($imgDir . $filename, base64_decode($base64clean));

            // If this recipe has no primary image yet, set it as the primary
            $recipe = $db->getRecipe($id);
            if (empty($recipe['image_filename'])) {
                $db->raw()->prepare("UPDATE recipes SET image_filename = :f, image_data = NULL WHERE id = :id")
                    ->execute([':f' => $filename, ':id' => $id]);
                $images = json_decode($recipe['images'] ?? '[]', true) ?: [];
            } else {
                // Otherwise append to the images array
                $images = $db->addRecipeImage($id, $filename);
            }
            sendResponse(200, ['success' => true, 'filename' => $filename, 'images' => $images]);
            break;

        case 'save_edited_image':
            // Save a cropped/rotated version of an existing image, replacing the old file
            $uuid = $input['uuid'] ?? '';
            $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
            if (!$id && $uuid) $id = $db->resolveRecipeId($uuid);
            if (!$id) sendResponse(400, ['error' => 'Missing recipe id or uuid']);
            $oldFilename = $input['old_filename'] ?? '';
            if (empty($input['image_data'])) sendResponse(400, ['error' => 'Missing image_data']);

            $base64 = $input['image_data'];
            $base64clean = preg_replace('#^data:image/\w+;base64,#', '', $base64);
            // Always save edited images as jpg
            $newFilename = ($uuid ?: $id) . '_edited_' . time() . '_' . uniqid() . '.jpg';
            $imgDir = __DIR__ . '/../images/';
            if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
            file_put_contents($imgDir . $newFilename, base64_decode($base64clean));

            // Update DB: swap old filename for new filename wherever it appears
            $recipe = $db->getRecipe($id);
            if (!$recipe) sendResponse(404, ['error' => 'Recipe not found']);

            $primary = $recipe['image_filename'] ?? '';
            $extras  = json_decode($recipe['images'] ?? '[]', true) ?: [];

            if ($primary === $oldFilename) {
                $primary = $newFilename;
            } else {
                $extras = array_map(fn($f) => $f === $oldFilename ? $newFilename : $f, $extras);
            }
            $db->raw()->prepare(
                "UPDATE recipes SET image_filename = :p, images = :imgs, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute([':p' => $primary, ':imgs' => json_encode($extras), ':id' => $id]);

            // Delete old file (best-effort)
            if ($oldFilename && file_exists($imgDir . $oldFilename)) {
                @unlink($imgDir . $oldFilename);
            }

            sendResponse(200, ['success' => true, 'filename' => $newFilename]);
            break;

        case 'delete_image':
            // Remove an image from a recipe
            $uuid = $input['uuid'] ?? '';
            $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
            if (!$id && $uuid) $id = $db->resolveRecipeId($uuid);
            if (!$id) sendResponse(400, ['error' => 'Missing recipe id or uuid']);
            $filename = $input['filename'] ?? '';
            if (!$filename) sendResponse(400, ['error' => 'Missing filename']);
            $images = $db->deleteRecipeImage($id, $filename);
            sendResponse(200, ['success' => true, 'images' => $images]);
            break;

        case 'reorder_images':
            // Save a new image display order for a recipe
            $uuid = $input['uuid'] ?? '';
            $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
            if (!$id && $uuid) $id = $db->resolveRecipeId($uuid);
            if (!$id) sendResponse(400, ['error' => 'Missing recipe id or uuid']);
            $order = $input['order'] ?? [];
            if (!is_array($order)) sendResponse(400, ['error' => 'order must be an array']);
            $recipe = $db->getRecipe($id);
            if (!$recipe) sendResponse(404, ['error' => 'Recipe not found']);
            // First filename becomes the primary image_filename, rest go into images JSON
            $primary  = !empty($order) ? $order[0] : ($recipe['image_filename'] ?? '');
            $extras   = array_slice($order, 1);
            $db->raw()->prepare(
                "UPDATE recipes SET image_filename = :f, images = :imgs, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute([':f' => $primary, ':imgs' => json_encode($extras), ':id' => $id]);
            sendResponse(200, ['success' => true, 'primary' => $primary, 'images' => $extras]);
            break;

        case 'add_history':
            $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
            if (!$id) sendResponse(400, ['error' => 'Missing recipe id']);
            $db->addEditHistoryEntry($id, [
                'field_name'  => $input['changes'] ?? 'edit',
                'old_value'   => json_encode($input['changeDetails'] ?? []),
                'new_value'   => $input['formatted_time'] ?? $input['timestamp'] ?? '',
                'changed_by'  => $input['user'] ?? 'Anonymous'
            ]);
            sendResponse(200, ['success' => true]);
            break;

        case 'get_history':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) sendResponse(400, ['error' => 'Missing recipe id']);
            $history = $db->getEditHistory($id);
            sendResponse(200, $history ?? []);
            break;

        case 'add_title_change':
            $recipeId  = $input['recipe_uuid'] ?? $input['id'] ?? null;
            $oldTitle  = $input['old_title'] ?? '';
            $newTitle  = $input['new_title'] ?? '';
            $changedBy = $input['changed_by'] ?? 'Anonymous';
            if (!$oldTitle || !$newTitle) sendResponse(400, ['error' => 'Missing title data']);
            $db->saveTitleChange($recipeId, $oldTitle, $newTitle, $changedBy);
            sendResponse(200, ['success' => true]);
            break;

        // ── Auth ──────────────────────────────────────────────────────────
        case 'register':
            if (empty($input['username']) || empty($input['password'])) {
                sendResponse(400, ['error' => 'Username and password required']);
            }
            $result = $db->registerUser($input['username'], $input['password'], $input['fullname'] ?? '');
            sendResponse(isset($result['error']) ? 400 : 201, $result);
            break;

        case 'login':
            if (empty($input['username']) || empty($input['password'])) {
                sendResponse(400, ['error' => 'Username and password required']);
            }
            $result = $db->loginUser($input['username'], $input['password']);
            if (isset($result['error'])) {
                $db->logActivity(strtolower(trim($input['username'])), 'login_failed', $result['error']);
                sendResponse(401, $result);
            } else {
                $db->logActivity($result['username'], 'login_success');
                sendResponse(200, $result);
            }
            break;

        case 'logout':
            $token = $input['token'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '');
            $logoutUser = $db->verifySession($token);
            if ($logoutUser) $db->logActivity($logoutUser['username'], 'logout');
            $db->logoutUser($token);
            sendResponse(200, ['success' => true]);
            break;

        case 'verify':
            $token = $input['token'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '');
            $user = $db->verifySession($token);
            if ($user) {
                sendResponse(200, ['success' => true, 'username' => $user['username'], 'fullname' => $user['fullname']]);
            } else {
                sendResponse(401, ['error' => 'Invalid or expired session']);
            }
            break;

        case 'reset_password':
            if (empty($input['username']) || empty($input['fullname']) || empty($input['new_password'])) {
                sendResponse(400, ['error' => 'Username, full name, and new password required']);
            }
            $result = $db->resetPassword($input['username'], $input['fullname'], $input['new_password']);
            sendResponse(isset($result['error']) ? 400 : 200, $result);
            break;

        case 'get_approved_users':
            sendResponse(200, $db->getApprovedUsers());
            break;

        case 'add_approved_user':
            if (empty($input['username'])) sendResponse(400, ['error' => 'Username required']);
            $db->addApprovedUser($input['username'], $input['added_by'] ?? 'admin');
            sendResponse(200, ['success' => true]);
            break;

        case 'remove_approved_user':
            if (empty($input['username'])) sendResponse(400, ['error' => 'Username required']);
            $db->removeApprovedUser($input['username']);
            sendResponse(200, ['success' => true]);
            break;

        // ── Custom Meta Options ───────────────────────────────────────────
        case 'get_custom_meta':
            $fieldType = $_GET['field_type'] ?? null;
            sendResponse(200, $db->getCustomMetaOptions($fieldType));
            break;

        case 'add_custom_meta':
            if (empty($input['field_type']) || empty($input['value'])) {
                sendResponse(400, ['error' => 'field_type and value required']);
            }
            $db->addCustomMetaOption($input['field_type'], $input['value'], $input['added_by'] ?? null);
            sendResponse(200, ['success' => true]);
            break;

        // ── Search index (replaces static recipes.json) ───────────────────
        case 'create_recipe':
            if (empty($input['title'])) sendResponse(400, ['error' => 'Title required']);
            // Normalize ingredients/directions: accept arrays or newline strings, store as JSON
            foreach (['ingredients', 'directions'] as $field) {
                if (isset($input[$field]) && is_array($input[$field])) {
                    $input[$field] = json_encode($input[$field]);
                }
            }
            if (empty($input['ingredients'])) $input['ingredients'] = json_encode(['No ingredients listed']);
            if (empty($input['directions'])) $input['directions'] = json_encode(['See ingredients']);
            // Save image_data as a file instead of storing base64 in DB
            if (!empty($input['image_data'])) {
                $base64 = $input['image_data'];
                $base64clean = preg_replace('#^data:image/\w+;base64,#', '', $base64);
                $ext = 'jpg';
                if (preg_match('#^data:image/(\w+);#', $base64, $m)) $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                $uuid = $input['uuid'] ?? uniqid();
                $filename = $uuid . '_' . time() . '.' . $ext;
                $imgDir = __DIR__ . '/../images/';
                if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
                file_put_contents($imgDir . $filename, base64_decode($base64clean));
                $input['image_filename'] = $filename;
                $input['image_data'] = null;  // Don't store base64 in DB
            }
            $id = $db->createRecipe($input);
            $recipe = $db->getRecipe($id);
            // Notify subscribers of new recipe
            $newRecipeSubs = $db->getNotificationSubscribers('notify_new_recipe');
            if (!empty($newRecipeSubs)) {
                notifyNewRecipe([
                    'title'         => $recipe['title'],
                    'contributor'   => $recipe['contributor'] ?? '',
                    'family_source' => $recipe['family_source'] ?? '',
                    'uuid'          => $recipe['uuid'] ?? '',
                ], $newRecipeSubs);
            }
            $db->logActivity($input['contributor'] ?? 'unknown', 'recipe_created', $recipe['title']);
            sendResponse(201, ['recipe' => $recipe, 'message' => 'Recipe created successfully']);
            break;

        case 'update_recipe':
            $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
            if (!$id) sendResponse(400, ['error' => 'Missing recipe id']);
            if (!$db->getRecipe($id)) sendResponse(404, ['error' => 'Recipe not found']);
            $db->updateRecipe($id, $input);
            $updatedRecipe = $db->getRecipe($id);
            // Notify subscribers of recipe edit
            $editSubs = $db->getNotificationSubscribers('notify_edits');
            if (!empty($editSubs)) {
                notifyRecipeEdited([
                    'recipe_title' => $updatedRecipe['title'],
                    'recipe_uuid'  => $updatedRecipe['uuid'] ?? '',
                    'editor'       => trim($input['username'] ?? $input['contributor'] ?? 'Someone'),
                ], $editSubs);
            }
            sendResponse(200, $updatedRecipe);
            break;

        case 'delete_recipe':
            $requestingUser = strtolower(trim($input['username'] ?? ''));
            if ($requestingUser !== 'paul') {
                sendResponse(403, ['error' => 'Only paul can delete recipes']);
            }
            $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
            if (!$id) sendResponse(400, ['error' => 'Missing recipe id']);
            $toDelete = $db->getRecipe($id);
            if (!$toDelete) sendResponse(404, ['error' => 'Recipe not found']);
            $success = $db->deleteRecipe($id);
            if ($success) $db->logActivity($requestingUser, 'recipe_deleted', $toDelete['title'] ?? "id:$id");
            sendResponse($success ? 200 : 500, ['success' => $success, 'message' => $success ? 'Recipe deleted' : 'Failed to delete']);
            break;

        case 'rename_contributor':
            // Renames ALL recipes with old_name → new_name (merges duplicates automatically)
            $requestingUser = strtolower(trim($input['username'] ?? ''));
            if ($requestingUser !== 'paul') {
                sendResponse(403, ['error' => 'Only paul can rename contributors']);
            }
            $oldName = trim($input['old_name'] ?? '');
            $newName = trim($input['new_name'] ?? '');
            if (!$oldName || !$newName) sendResponse(400, ['error' => 'old_name and new_name required']);
            try {
                $stmt = $db->raw()->prepare(
                    "UPDATE recipes SET contributor = :new WHERE contributor = :old"
                );
                $stmt->execute([':new' => $newName, ':old' => $oldName]);
                $affected = $stmt->rowCount();
                sendResponse(200, ['success' => true, 'updated' => $affected, 'old_name' => $oldName, 'new_name' => $newName]);
            } catch (Exception $e) {
                sendResponse(500, ['error' => 'Rename failed: ' . $e->getMessage()]);
            }
            break;

        case 'get_recipes_search':
            $all = $db->getRecipes();
            $rows = array_map(function($r) {
                return [
                    'id'             => $r['id'],
                    'title'          => $r['title'],
                    'category'       => $r['category'] ?? '',
                    'contributor'    => $r['contributor'] ?? '',
                    'family_source'  => $r['family_source'] ?? '',
                    'tags'           => $r['tags'] ?? '',
                    'updated_at'     => $r['updated_at'] ?? '',
                    'created_at'     => $r['created_at'] ?? '',
                    'meal_type'      => $r['meal_type'] ?? '',
                    'cuisine'        => $r['cuisine'] ?? '',
                    'main_ingredient'=> $r['main_ingredient'] ?? '',
                    'method'         => $r['method'] ?? '',
                    'uuid'           => $r['filename'] ?? $r['uuid'] ?? '',
                    'has_image'      => !empty($r['image_filename']) ? 1 : 0,
                    'image_filename' => $r['image_filename'] ?? '',
                ];
            }, $all ?? []);
            sendResponse(200, $rows);
            break;

        case 'toggle_favorite':
            $user = $input['username'] ?? '';
            $uuid = $input['recipe_uuid'] ?? '';
            if (!$user || !$uuid) { sendResponse(400, ['error' => 'username and recipe_uuid required']); break; }
            $result = $db->toggleFavorite($user, $uuid);
            sendResponse(200, $result);
            break;

        case 'get_favorites':
            $user = $_GET['username'] ?? ($input['username'] ?? '');
            if (!$user) { sendResponse(400, ['error' => 'username required']); break; }
            $favorites = $db->getFavorites($user);
            $counts    = $db->getFavoriteCounts();
            sendResponse(200, ['favorites' => $favorites, 'counts' => $counts]);
            break;

        case 'toggle_reaction':
            $user     = $input['username'] ?? '';
            $uuid     = $input['recipe_uuid'] ?? '';
            $reaction = $input['reaction'] ?? '';
            if (!$user || !$uuid || !$reaction) { sendResponse(400, ['error' => 'username, recipe_uuid and reaction required']); break; }
            $result = $db->toggleReaction($user, $uuid, $reaction);
            $counts = $db->getReactions($uuid);
            $result['counts'] = $counts;
            // Notify subscribers when a reaction is added (not removed)
            if (!empty($result['added'])) {
                $reactionSubs = $db->getNotificationSubscribers('notify_reactions');
                if (!empty($reactionSubs)) {
                    $reactionRecipe = $db->getRecipeByUuid($uuid);
                    if ($reactionRecipe) {
                        notifyReaction([
                            'emoji'        => $reaction,
                            'reactor'      => $user,
                            'recipe_title' => $reactionRecipe['title'],
                            'recipe_uuid'  => $uuid,
                        ], $reactionSubs);
                    }
                }
            }
            sendResponse(200, $result);
            break;

        case 'get_reactions':
            $uuid = $_GET['recipe_uuid'] ?? ($input['recipe_uuid'] ?? '');
            $user = $_GET['username'] ?? ($input['username'] ?? '');
            if (!$uuid) { sendResponse(400, ['error' => 'recipe_uuid required']); break; }
            $counts   = $db->getReactions($uuid);
            $mine     = $user ? $db->getReactionsByUser($user, $uuid) : [];
            sendResponse(200, ['counts' => $counts, 'mine' => $mine]);
            break;

        case 'get_all_reactions':
            $user      = $_GET['username'] ?? '';
            $allR      = $db->getAllReactions();
            $favorites = $user ? $db->getFavorites($user) : [];
            $favCounts = $db->getFavoriteCounts();
            sendResponse(200, ['reactions' => $allR, 'favorites' => $favorites, 'favorite_counts' => $favCounts]);
            break;


        case 'import_recipe':
            $body = json_decode(file_get_contents('php://input'), true);
            $imageBase64    = $body['image_base64']    ?? '';
            $imageMediaType = $body['image_media_type'] ?? 'image/jpeg';
            $extraPrompt    = $body['extra_prompt']    ?? '';
            if (!$imageBase64) { sendResponse(400, ['error' => 'No image data provided']); break; }
            if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) { sendResponse(500, ['error' => 'Anthropic API key not configured']); break; }
            $systemPrompt = 'You are a recipe extraction specialist. Return ONLY a valid JSON object: {"title":"","family_source":"","meal_type":"","cuisine":"","main_ingredient":"","method":"","ingredients":[],"directions":[],"notes":""}';
            $userText = 'Extract this recipe into JSON.' . ($extraPrompt ? ' Context: ' . $extraPrompt : '');
            $payload = ['model' => 'claude-sonnet-4-20250514', 'max_tokens' => 1000, 'system' => $systemPrompt,
                'messages' => [['role' => 'user', 'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $imageMediaType, 'data' => $imageBase64]],
                    ['type' => 'text', 'text' => $userText]
                ]]]];
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'],
                CURLOPT_TIMEOUT => 60]);
            $result = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlErr = curl_error($ch); curl_close($ch);
            if ($curlErr) { sendResponse(500, ['error' => 'Network error: ' . $curlErr]); break; }
            $anthropicData = json_decode($result, true);
            if ($httpCode !== 200) { sendResponse(502, ['error' => $anthropicData['error']['message'] ?? 'Anthropic error ' . $httpCode]); break; }
            $raw = '';
            foreach (($anthropicData['content'] ?? []) as $block) { if ($block['type'] === 'text') $raw .= $block['text']; }
            $recipe = json_decode(trim(preg_replace('/```json|```/', '', $raw)), true);
            if (!$recipe) { sendResponse(500, ['error' => 'Could not parse recipe from response']); break; }
            sendResponse(200, ['recipe' => $recipe]);
            break;


        case 'get_image':
            // Serve a recipe's base64 image_data as a proper image response
            $uuid = $_GET['uuid'] ?? '';
            if (!$uuid) { http_response_code(400); exit('Missing uuid'); }
            $recipe = $db->getRecipeByUuid($uuid);
            if (!$recipe || empty($recipe['image_data'])) { http_response_code(404); exit('Image not found'); }
            $base64 = $recipe['image_data'];
            // Detect media type from data URI prefix
            $mediaType = 'image/jpeg';
            if (preg_match('#^data:(image/\w+);base64,#', $base64, $m)) {
                $mediaType = $m[1];
                $base64 = preg_replace('#^data:image/\w+;base64,#', '', $base64);
            }
            header('Content-Type: ' . $mediaType);
            header('Cache-Control: public, max-age=86400');
            echo base64_decode($base64);
            exit;

        case 'get_preferences':
            $username = trim($_GET['username'] ?? '');
            if (!$username) sendResponse(400, ['error' => 'username required']);
            $prefs = $db->getUserPreferences($username);
            sendResponse(200, ['success' => true, 'preferences' => $prefs]);
            break;

        case 'save_preferences':
            $username = trim($input['username'] ?? '');
            if (!$username) sendResponse(400, ['error' => 'username required']);
            $emailVal = trim($input['email'] ?? '');
            if ($emailVal && !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
                sendResponse(400, ['error' => 'Invalid email address']);
            }
            $ok = $db->saveUserPreferences($username, $input);
            sendResponse($ok ? 200 : 500, ['success' => $ok]);
            break;

        case 'send_test_email':
            ini_set('display_errors', 1);
            error_reporting(E_ALL);
            $username = trim($input['username'] ?? '');
            if (!in_array($username, ['paul', 'pstamey'], true)) sendResponse(403, ['error' => 'Admin only']);
            $prefs = $db->getUserPreferences($username);
            if (empty($prefs['email'])) sendResponse(400, ['error' => 'No email address saved yet — save your preferences first']);
            $emailError = null;
            $ok = sendEmail(
                $prefs['email'],
                $username,
                "Test — Mom's Recipes notifications are working!",
                '<h2>It works!</h2><p>Your notification email is set up correctly. You\'ll receive updates based on your preferences.</p><p><a class="btn" href="' . SITE_URL . '">Visit Mom\'s Recipes</a></p>',
                $emailError
            );
            sendResponse($ok ? 200 : 500, ['success' => $ok, 'sent_to' => $prefs['email'], 'error' => $ok ? null : ($emailError ?? 'Send failed')]);
            break;

        case 'send_weekly_digest':
            $secret = $_GET['secret'] ?? '';
            if ($secret !== DIGEST_CRON_SECRET) sendResponse(403, ['error' => 'Unauthorized']);
            $digestSubs = $db->getNotificationSubscribers('notify_weekly');
            if (empty($digestSubs)) sendResponse(200, ['success' => true, 'message' => 'No subscribers', 'sent' => 0]);
            $digest = $db->getWeeklyDigestData();
            sendWeeklyDigest($digest, $digestSubs);
            sendResponse(200, ['success' => true, 'sent' => count($digestSubs)]);
            break;

        case 'get_activity_log':
            $requestingUser = trim($_GET['username'] ?? '');
            if (!$db->isAdmin($requestingUser)) {
                sendResponse(403, ['error' => 'Admin only']);
            }
            $filterUser = trim($_GET['filter_user'] ?? '');
            $limit      = min((int)($_GET['limit'] ?? 50), 500);
            $offset     = (int)($_GET['offset'] ?? 0);
            $entries    = $db->getActivityLog($filterUser, $limit, $offset);
            $total      = $db->getActivityLogCount($filterUser);
            $users      = $db->getActivityLogUsers();
            sendResponse(200, [
                'entries' => $entries,
                'total'   => $total,
                'users'   => $users,
                'limit'   => $limit,
                'offset'  => $offset,
            ]);
            break;

        case 'get_users':
            $requestingUser = trim($_GET['username'] ?? '');
            if (!$db->isAdmin($requestingUser)) {
                sendResponse(403, ['error' => 'Admin only']);
            }
            $users = $db->getAllUsers();
            sendResponse(200, ['users' => $users]);
            break;

        case 'set_admin':
            // Super admin only — paul can grant/revoke admin for others
            $requestingUser = trim($input['username'] ?? '');
            if (!in_array($requestingUser, ['paul', 'pstamey'], true)) {
                sendResponse(403, ['error' => 'Super admin only']);
            }
            $targetUser = trim($input['target_username'] ?? '');
            $grant      = (bool)($input['grant'] ?? false);
            if (!$targetUser) sendResponse(400, ['error' => 'target_username required']);
            // Cannot demote yourself
            if (strtolower($targetUser) === strtolower($requestingUser)) {
                sendResponse(400, ['error' => 'Cannot change your own admin status']);
            }
            $ok = $db->setUserAdmin($targetUser, $grant);
            if ($ok) {
                $action = $grant ? 'admin_granted' : 'admin_revoked';
                $db->logActivity($requestingUser, $action, $targetUser);
            }
            sendResponse($ok ? 200 : 500, ['success' => $ok]);
            break;

        case 'check_admin':
            $requestingUser = trim($_GET['username'] ?? '');
            $isAdmin = $db->isAdmin($requestingUser);
            $isSuperAdmin = in_array($requestingUser, ['paul', 'pstamey'], true);
            sendResponse(200, ['is_admin' => $isAdmin, 'is_super_admin' => $isSuperAdmin]);
            break;

        default:
            sendResponse(404, ['error' => "Unknown action: $action"]);
    }
    exit();
}

// Route the request
try {
    // Health check endpoint
    if ($path === 'health' || $path === '') {
        sendResponse(200, [
            'status' => 'ok',
            'timestamp' => date('c'),
            'database' => $db->isHealthy() ? 'connected' : 'error'
        ]);
    }
    
    // Get all recipes
    elseif ($path === 'recipes' && $method === 'GET') {
        $search = $_GET['search'] ?? null;
        $category = $_GET['category'] ?? null;
        $tags = isset($_GET['tags']) ? explode(',', $_GET['tags']) : null;
        $contributor = $_GET['contributor'] ?? null;
        
        $recipes = $db->getRecipes($search, $category, $tags, $contributor);
        sendResponse(200, ['recipes' => $recipes, 'count' => count($recipes)]);
    }
    
    // Get single recipe by ID
    elseif (preg_match('/^recipes\/(\d+)$/', $path, $matches) && $method === 'GET') {
        $recipeId = (int)$matches[1];
        $recipe = $db->getRecipe($recipeId);
        
        if ($recipe) {
            sendResponse(200, ['recipe' => $recipe]);
        } else {
            sendResponse(404, ['error' => 'Recipe not found']);
        }
    }
    
    // Create new recipe
    elseif ($path === 'recipes' && $method === 'POST') {
        $required = ['title', 'ingredients', 'directions'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                sendResponse(400, ['error' => "Missing required field: $field"]);
            }
        }
        
        $recipeId = $db->createRecipe($input);
        $recipe = $db->getRecipe($recipeId);
        
        sendResponse(201, ['recipe' => $recipe, 'message' => 'Recipe created successfully']);
    }
    
    // Update existing recipe
    elseif (preg_match('/^recipes\/(\d+)$/', $path, $matches) && $method === 'PUT') {
        $recipeId = (int)$matches[1];
        
        if (!$db->getRecipe($recipeId)) {
            sendResponse(404, ['error' => 'Recipe not found']);
        }
        
        $success = $db->updateRecipe($recipeId, $input);
        
        if ($success) {
            $recipe = $db->getRecipe($recipeId);
            sendResponse(200, ['recipe' => $recipe, 'message' => 'Recipe updated successfully']);
        } else {
            sendResponse(500, ['error' => 'Failed to update recipe']);
        }
    }
    
    // Delete recipe
    elseif (preg_match('/^recipes\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
        $recipeId = (int)$matches[1];
        
        if (!$db->getRecipe($recipeId)) {
            sendResponse(404, ['error' => 'Recipe not found']);
        }
        
        $success = $db->deleteRecipe($recipeId);
        
        if ($success) {
            sendResponse(200, ['message' => 'Recipe deleted successfully']);
        } else {
            sendResponse(500, ['error' => 'Failed to delete recipe']);
        }
    }
    
    // Get edit history for a recipe
    elseif (preg_match('/^recipes\/(\d+)\/history$/', $path, $matches) && $method === 'GET') {
        $recipeId = (int)$matches[1];
        $history = $db->getEditHistory($recipeId);
        sendResponse(200, ['history' => $history, 'count' => count($history)]);
    }
    
    // Get all categories
    elseif ($path === 'categories' && $method === 'GET') {
        $categories = $db->getCategories();
        sendResponse(200, ['categories' => $categories]);
    }
    
    // Get all tags
    elseif ($path === 'tags' && $method === 'GET') {
        $tags = $db->getTags();
        sendResponse(200, ['tags' => $tags]);
    }
    
    // Get all contributors (who digitized/added the recipe)
    elseif ($path === 'contributors' && $method === 'GET') {
        $contributors = $db->getContributors();
        sendResponse(200, ['contributors' => $contributors]);
    }

    // Get all family sources (recipe authors)
    elseif ($path === 'family-sources' && $method === 'GET') {
        $sources = $db->getFamilySources();
        sendResponse(200, ['family_sources' => $sources]);
    }
    
    // Bulk sync - accepts multiple recipe updates
    elseif ($path === 'recipes/sync' && $method === 'POST') {
        if (!isset($input['recipes']) || !is_array($input['recipes'])) {
            sendResponse(400, ['error' => 'Invalid sync data - expected recipes array']);
        }
        
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($input['recipes'] as $recipeData) {
            try {
                if (isset($recipeData['id']) && $db->getRecipe($recipeData['id'])) {
                    $db->updateRecipe($recipeData['id'], $recipeData);
                } else {
                    $db->createRecipe($recipeData);
                }
                $results['success']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'recipe' => $recipeData['title'] ?? 'Unknown',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        sendResponse(200, $results);
    }
    
    // Stats endpoint
    elseif ($path === 'stats' && $method === 'GET') {
        $stats = [
            'total_recipes' => $db->getRecipeCount(),
            'total_blog_posts' => $db->getBlogPostCount(),
            'categories' => count($db->getCategories()),
            'contributors' => count($db->getContributors()),
            'tags' => count($db->getTags()),
            'last_updated' => $db->getLastUpdate()
        ];
        sendResponse(200, $stats);
    }
    
    // =========================================================================
    // BLOG POST ENDPOINTS
    // =========================================================================
    
    // Get all blog posts
    elseif ($path === 'blog-posts' && $method === 'GET') {
        $search = $_GET['search'] ?? null;
        $category = $_GET['category'] ?? null;
        $published = isset($_GET['published']) ? (bool)$_GET['published'] : null;
        
        $posts = $db->getBlogPosts($search, $category, $published);
        sendResponse(200, ['posts' => $posts, 'count' => count($posts)]);
    }
    
    // Get single blog post by ID
    elseif (preg_match('/^blog-posts\/(\d+)$/', $path, $matches) && $method === 'GET') {
        $postId = (int)$matches[1];
        $post = $db->getBlogPost($postId);
        
        if ($post) {
            sendResponse(200, ['post' => $post]);
        } else {
            sendResponse(404, ['error' => 'Blog post not found']);
        }
    }
    
    // Create new blog post
    elseif ($path === 'blog-posts' && $method === 'POST') {
        $required = ['title', 'content'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                sendResponse(400, ['error' => "Missing required field: $field"]);
            }
        }
        
        $postId = $db->createBlogPost($input);
        $post = $db->getBlogPost($postId);
        
        sendResponse(201, ['post' => $post, 'message' => 'Blog post created successfully']);
    }
    
    // Update existing blog post
    elseif (preg_match('/^blog-posts\/(\d+)$/', $path, $matches) && $method === 'PUT') {
        $postId = (int)$matches[1];
        
        if (!$db->getBlogPost($postId)) {
            sendResponse(404, ['error' => 'Blog post not found']);
        }
        
        $success = $db->updateBlogPost($postId, $input);
        
        if ($success) {
            $post = $db->getBlogPost($postId);
            sendResponse(200, ['post' => $post, 'message' => 'Blog post updated successfully']);
        } else {
            sendResponse(500, ['error' => 'Failed to update blog post']);
        }
    }
    
    // Delete blog post
    elseif (preg_match('/^blog-posts\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
        $postId = (int)$matches[1];
        
        if (!$db->getBlogPost($postId)) {
            sendResponse(404, ['error' => 'Blog post not found']);
        }
        
        $success = $db->deleteBlogPost($postId);
        
        if ($success) {
            sendResponse(200, ['message' => 'Blog post deleted successfully']);
        } else {
            sendResponse(500, ['error' => 'Failed to delete blog post']);
        }
    }
    
    // Unknown endpoint
    else {
        sendResponse(404, [
            'error' => 'Endpoint not found',
            'path' => $path,
            'method' => $method
        ]);
    }
    
} catch (Exception $e) {
    error_log('API Error: ' . $e->getMessage());
    sendResponse(500, [
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}
