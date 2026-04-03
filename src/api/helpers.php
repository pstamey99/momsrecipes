<?php
/**
 * Helper Functions
 * Utility functions for the API
 */

/**
 * Send JSON response
 */
function sendResponse($statusCode, $data) {
    // Discard any stray PHP output (warnings, notices) that would corrupt JSON
    if (ob_get_level()) { ob_end_clean(); }
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);
    
    // Log the response if logging is enabled
    if (LOG_ENABLED) {
        logRequest($statusCode, $data);
    }
    
    exit();
}

/**
 * Log API requests
 */
function logRequest($statusCode, $data) {
    $logEntry = [
        'timestamp' => date('c'),
        'method' => $_SERVER['REQUEST_METHOD'],
        'path' => $_SERVER['REQUEST_URI'],
        'status' => $statusCode,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    if ($statusCode >= 400) {
        $logEntry['error'] = $data['error'] ?? 'Unknown error';
    }
    
    $logLine = json_encode($logEntry) . PHP_EOL;
    @file_put_contents(LOG_FILE, $logLine, FILE_APPEND);
}

/**
 * Validate API key (if authentication is enabled)
 */
function validateApiKey() {
    if (!AUTH_ENABLED) {
        return true;
    }
    
    $headers = getallheaders();
    $apiKey = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    // Remove 'Bearer ' prefix if present
    $apiKey = preg_replace('/^Bearer\s+/i', '', $apiKey);
    
    if ($apiKey !== API_KEY) {
        sendResponse(401, ['error' => 'Invalid or missing API key']);
    }
    
    return true;
}

/**
 * Rate limiting check
 */
function checkRateLimit() {
    if (!RATE_LIMIT_ENABLED) {
        return true;
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheFile = __DIR__ . '/cache/rate_limit_' . md5($ip) . '.json';
    
    // Ensure cache directory exists
    if (!is_dir(__DIR__ . '/cache')) {
        mkdir(__DIR__ . '/cache', 0755, true);
    }
    
    $now = time();
    $windowStart = $now - RATE_LIMIT_WINDOW;
    
    // Load existing rate limit data
    $requests = [];
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if ($data) {
            // Filter out old requests
            $requests = array_filter($data['requests'], function($timestamp) use ($windowStart) {
                return $timestamp > $windowStart;
            });
        }
    }
    
    // Check if rate limit exceeded
    if (count($requests) >= RATE_LIMIT_REQUESTS) {
        sendResponse(429, [
            'error' => 'Rate limit exceeded',
            'limit' => RATE_LIMIT_REQUESTS,
            'window' => RATE_LIMIT_WINDOW,
            'retry_after' => min($requests) + RATE_LIMIT_WINDOW - $now
        ]);
    }
    
    // Add current request
    $requests[] = $now;
    file_put_contents($cacheFile, json_encode(['requests' => $requests]));
    
    return true;
}

/**
 * Sanitize input string
 */
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    
    if (!is_string($input)) {
        return $input;
    }
    
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email format
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate URL format
 */
function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Generate a random string
 */
function generateRandomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Convert tags string to array
 */
function tagsToArray($tags) {
    if (is_array($tags)) {
        return $tags;
    }
    
    if (empty($tags)) {
        return [];
    }
    
    return array_map('trim', explode(',', $tags));
}

/**
 * Convert tags array to string
 */
function tagsToString($tags) {
    if (is_string($tags)) {
        return $tags;
    }
    
    if (empty($tags) || !is_array($tags)) {
        return '';
    }
    
    return implode(',', array_map('trim', $tags));
}

/**
 * Format file size in human-readable format
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Clean database backup directory
 */
function cleanBackupDirectory() {
    if (!is_dir(DB_BACKUP_DIR)) {
        return;
    }
    
    $files = glob(DB_BACKUP_DIR . '/recipes_*.db');
    $cutoffTime = time() - (BACKUP_RETENTION_DAYS * 86400);
    
    foreach ($files as $file) {
        if (filemtime($file) < $cutoffTime) {
            @unlink($file);
        }
    }
}

/**
 * Get database file size
 */
function getDatabaseSize() {
    if (file_exists(DB_PATH)) {
        return filesize(DB_PATH);
    }
    return 0;
}

/**
 * Validate recipe data structure
 */
function validateRecipeData($data) {
    $errors = [];
    
    // Required fields
    if (empty($data['title'])) {
        $errors[] = 'Title is required';
    } elseif (strlen($data['title']) > MAX_RECIPE_TITLE_LENGTH) {
        $errors[] = 'Title is too long (max ' . MAX_RECIPE_TITLE_LENGTH . ' characters)';
    }
    
    if (empty($data['ingredients'])) {
        $errors[] = 'Ingredients are required';
    }
    
    if (empty($data['directions'])) {
        $errors[] = 'Directions are required';
    }
    
    // Validate image size if present
    if (!empty($data['image_data'])) {
        $imageSize = strlen($data['image_data']);
        if ($imageSize > MAX_IMAGE_SIZE) {
            $errors[] = 'Image is too large (max ' . formatFileSize(MAX_IMAGE_SIZE) . ')';
        }
    }
    
    return $errors;
}

/**
 * Parse ingredients text into structured array
 */
function parseIngredients($text) {
    $lines = explode("\n", $text);
    $ingredients = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line)) {
            $ingredients[] = [
                'text' => $line,
                'checked' => false
            ];
        }
    }
    
    return $ingredients;
}

/**
 * Parse instructions text into structured array
 */
function parseInstructions($text) {
    $lines = explode("\n", $text);
    $instructions = [];
    $step = 1;
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line)) {
            // Remove existing step numbers if present
            $line = preg_replace('/^\d+\.\s*/', '', $line);
            $instructions[] = [
                'step' => $step++,
                'text' => $line
            ];
        }
    }
    
    return $instructions;
}

/**
 * Export database to JSON
 */
function exportToJson() {
    $db = new RecipeDatabase();
    $recipes = $db->getRecipes();
    
    $export = [
        'exported_at' => date('c'),
        'recipe_count' => count($recipes),
        'recipes' => $recipes
    ];
    
    return json_encode($export, JSON_PRETTY_PRINT);
}

/**
 * Import recipes from JSON
 */
function importFromJson($json) {
    $data = json_decode($json, true);
    
    if (!$data || !isset($data['recipes'])) {
        throw new Exception('Invalid JSON format');
    }
    
    $db = new RecipeDatabase();
    $imported = 0;
    $errors = [];
    
    foreach ($data['recipes'] as $recipe) {
        try {
            $db->createRecipe($recipe);
            $imported++;
        } catch (Exception $e) {
            $errors[] = [
                'recipe' => $recipe['title'] ?? 'Unknown',
                'error' => $e->getMessage()
            ];
        }
    }
    
    return [
        'imported' => $imported,
        'errors' => $errors
    ];
}

/**
 * Get server information
 */
function getServerInfo() {
    return [
        'php_version' => PHP_VERSION,
        'sqlite_version' => SQLite3::version()['versionString'],
        'max_upload_size' => ini_get('upload_max_filesize'),
        'max_post_size' => ini_get('post_max_size'),
        'memory_limit' => ini_get('memory_limit'),
        'timezone' => date_default_timezone_get(),
        'database_size' => formatFileSize(getDatabaseSize())
    ];
}

// Apply authentication and rate limiting checks
if (AUTH_ENABLED) {
    validateApiKey();
}

if (RATE_LIMIT_ENABLED) {
    checkRateLimit();
}

// Auto-backup is triggered from index.php after $db is safely initialized
