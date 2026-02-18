<?php
/**
 * Import Utility
 * Import recipes from JSON file
 * Access: yoursite.com/api/import.php
 */

require_once 'config.php';
require_once 'database.php';
require_once 'helpers.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed. Use POST.']));
}

// Authentication check
$isAuthorized = isset($_GET['key']) && $_GET['key'] === API_KEY;

if (AUTH_ENABLED && !$isAuthorized) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

try {
    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }
    
    // Read the JSON file
    $jsonContent = file_get_contents($_FILES['file']['tmp_name']);
    $data = json_decode($jsonContent, true);
    
    if (!$data) {
        throw new Exception('Invalid JSON format');
    }
    
    // Validate structure
    if (!isset($data['recipes']) || !is_array($data['recipes'])) {
        throw new Exception('Invalid data structure. Expected "recipes" array.');
    }
    
    $db = new RecipeDatabase();
    $results = [
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
        'errors' => []
    ];
    
    // Import recipes
    foreach ($data['recipes'] as $index => $recipe) {
        try {
            // Validate required fields
            if (empty($recipe['title']) || empty($recipe['ingredients']) || empty($recipe['directions'])) {
                $results['skipped']++;
                $results['errors'][] = [
                    'index' => $index,
                    'title' => $recipe['title'] ?? 'Unknown',
                    'error' => 'Missing required fields'
                ];
                continue;
            }
            
            // Check if recipe already exists (by title)
            $existing = $db->getRecipes($recipe['title']);
            if (!empty($existing)) {
                $results['skipped']++;
                $results['errors'][] = [
                    'index' => $index,
                    'title' => $recipe['title'],
                    'error' => 'Recipe already exists'
                ];
                continue;
            }
            
            // Create recipe
            $db->createRecipe($recipe);
            $results['success']++;
            
        } catch (Exception $e) {
            $results['failed']++;
            $results['errors'][] = [
                'index' => $index,
                'title' => $recipe['title'] ?? 'Unknown',
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Return results
    header('Content-Type: application/json');
    echo json_encode([
        'message' => 'Import completed',
        'results' => $results,
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'error' => 'Import failed',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
