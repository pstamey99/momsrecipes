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
            $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
            if (!$id) sendResponse(400, ['error' => 'Missing recipe id']);
            if (!$db->getRecipe($id)) sendResponse(404, ['error' => 'Recipe not found']);
            $db->updateRecipe($id, $input);
            $recipe = $db->getRecipe($id);
            sendResponse(200, $recipe);
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
            sendResponse(isset($result['error']) ? 401 : 200, $result);
            break;

        case 'logout':
            $token = $input['token'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '');
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

        case 'sync_approved_users':
            // Read approved_users.json and bulk-insert into DB table
            $jsonPath = __DIR__ . '/../approved_users.json';
            if (!file_exists($jsonPath)) sendResponse(404, ['error' => 'approved_users.json not found']);
            $json = json_decode(file_get_contents($jsonPath), true);
            $users = $json['users'] ?? [];
            $synced = [];
            foreach ($users as $u) {
                $u = strtolower(trim($u));
                if ($u) { $db->addApprovedUser($u, 'json-sync'); $synced[] = $u; }
            }
            sendResponse(200, ['success' => true, 'synced' => count($synced), 'users' => $synced]);
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
            if (empty($input['ingredients'])) $input['ingredients'] = '1. No ingredients listed';
            if (empty($input['directions'])) $input['directions'] = '1. See ingredients';
            $id = $db->createRecipe($input);
            $recipe = $db->getRecipe($id);
            sendResponse(201, ['recipe' => $recipe, 'message' => 'Recipe created successfully']);
            break;

        case 'update_recipe':
            $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
            if (!$id) sendResponse(400, ['error' => 'Missing recipe id']);
            if (!$db->getRecipe($id)) sendResponse(404, ['error' => 'Recipe not found']);
            $db->updateRecipe($id, $input);
            sendResponse(200, $db->getRecipe($id));
            break;

        case 'get_recipes_search':
            $all = $db->getRecipes();
            $rows = array_map(function($r) {
                return [
                    'id'             => $r['id'],
                    'title'          => $r['title'],
                    'category'       => $r['category'] ?? '',
                    'contributor'    => $r['contributor'] ?? '',
                    'tags'           => $r['tags'] ?? '',
                    'updated_at'     => $r['updated_at'] ?? '',
                    'meal_type'      => '',
                    'cuisine'        => '',
                    'main_ingredient'=> '',
                    'method'         => '',
                ];
            }, $all ?? []);
            sendResponse(200, $rows);
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
    
    // Get all contributors
    elseif ($path === 'contributors' && $method === 'GET') {
        $contributors = $db->getContributors();
        sendResponse(200, ['contributors' => $contributors]);
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
