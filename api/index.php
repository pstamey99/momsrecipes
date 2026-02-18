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
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(400, ['error' => 'Invalid JSON in request body']);
    }
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
