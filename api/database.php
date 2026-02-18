<?php
/**
 * Recipe Database Class
 * Handles all database operations with SQLite
 * 
 * SCHEMA VERSION: 2.0 - Uses 'directions' field (updated 2026-02-06)
 * Cache bust: v2.0.20260206
 */

// Disable opcode caching for this file during updates
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

class RecipeDatabase {
    private $db;
    
    public function __construct() {
        $this->connect();
        $this->initializeSchema();
    }
    
    /**
     * Establish database connection
     */
    private function connect() {
        try {
            $this->db = new PDO('sqlite:' . DB_PATH);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Enable foreign keys
            $this->db->exec('PRAGMA foreign_keys = ON');
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed');
        }
    }
    
    /**
     * Initialize database schema
     */
    private function initializeSchema() {
        $schema = "
        CREATE TABLE IF NOT EXISTS recipes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            category TEXT,
            contributor TEXT,
            servings TEXT,
            prep_time TEXT,
            cook_time TEXT,
            total_time TEXT,
            ingredients TEXT NOT NULL,
            directions TEXT NOT NULL,
            notes TEXT,
            tags TEXT,
            image_data TEXT,
            image_filename TEXT,
            source_url TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS blog_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            author TEXT,
            category TEXT,
            tags TEXT,
            image_data TEXT,
            image_filename TEXT,
            published BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS edit_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER NOT NULL,
            field_name TEXT NOT NULL,
            old_value TEXT,
            new_value TEXT,
            changed_by TEXT,
            changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        );
        
        CREATE INDEX IF NOT EXISTS idx_recipes_category ON recipes(category);
        CREATE INDEX IF NOT EXISTS idx_recipes_contributor ON recipes(contributor);
        CREATE INDEX IF NOT EXISTS idx_recipes_updated ON recipes(updated_at);
        CREATE INDEX IF NOT EXISTS idx_blog_posts_category ON blog_posts(category);
        CREATE INDEX IF NOT EXISTS idx_blog_posts_published ON blog_posts(published);
        CREATE INDEX IF NOT EXISTS idx_blog_posts_created ON blog_posts(created_at);
        CREATE INDEX IF NOT EXISTS idx_history_recipe ON edit_history(recipe_id);
        CREATE INDEX IF NOT EXISTS idx_history_date ON edit_history(changed_at);
        ";
        
        try {
            $this->db->exec($schema);
        } catch (PDOException $e) {
            error_log('Schema initialization failed: ' . $e->getMessage());
            throw new Exception('Failed to initialize database schema');
        }
    }
    
    /**
     * Check if database is healthy
     */
    public function isHealthy() {
        try {
            $this->db->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Get all recipes with optional filtering
     */
    public function getRecipes($search = null, $category = null, $tags = null, $contributor = null) {
        $sql = "SELECT * FROM recipes WHERE 1=1";
        $params = [];
        
        if ($search) {
            $sql .= " AND (title LIKE :search OR ingredients LIKE :search OR directions LIKE :search)";
            $params[':search'] = "%$search%";
        }
        
        if ($category) {
            $sql .= " AND category = :category";
            $params[':category'] = $category;
        }
        
        if ($contributor) {
            $sql .= " AND contributor = :contributor";
            $params[':contributor'] = $contributor;
        }
        
        if ($tags && is_array($tags)) {
            foreach ($tags as $i => $tag) {
                $sql .= " AND tags LIKE :tag$i";
                $params[":tag$i"] = "%$tag%";
            }
        }
        
        $sql .= " ORDER BY title ASC";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Get recipes failed: ' . $e->getMessage());
            throw new Exception('Failed to fetch recipes');
        }
    }
    
    /**
     * Get a single recipe by ID
     */
    public function getRecipe($id) {
        $sql = "SELECT * FROM recipes WHERE id = :id";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log('Get recipe failed: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create a new recipe
     */
    public function createRecipe($data) {
        $sql = "INSERT INTO recipes (
            title, category, contributor, servings, prep_time, cook_time, total_time,
            ingredients, directions, notes, tags, image_data, image_filename, source_url
        ) VALUES (
            :title, :category, :contributor, :servings, :prep_time, :cook_time, :total_time,
            :ingredients, :directions, :notes, :tags, :image_data, :image_filename, :source_url
        )";
        
        $params = [
            ':title' => $data['title'],
            ':category' => $data['category'] ?? null,
            ':contributor' => $data['contributor'] ?? null,
            ':servings' => $data['servings'] ?? null,
            ':prep_time' => $data['prep_time'] ?? null,
            ':cook_time' => $data['cook_time'] ?? null,
            ':total_time' => $data['total_time'] ?? null,
            ':ingredients' => $data['ingredients'],
            ':directions' => $data['directions'],
            ':notes' => $data['notes'] ?? null,
            ':tags' => is_array($data['tags'] ?? null) ? implode(',', $data['tags']) : ($data['tags'] ?? null),
            ':image_data' => $data['image_data'] ?? null,
            ':image_filename' => $data['image_filename'] ?? null,
            ':source_url' => $data['source_url'] ?? null
        ];
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Create recipe failed: ' . $e->getMessage());
            throw new Exception('Failed to create recipe: ' . $e->getMessage());
        }
    }
    
    /**
     * Update an existing recipe
     */
    public function updateRecipe($id, $data) {
        // Get old values for history tracking
        $oldRecipe = $this->getRecipe($id);
        if (!$oldRecipe) {
            return false;
        }
        
        $sql = "UPDATE recipes SET
            title = :title,
            category = :category,
            contributor = :contributor,
            servings = :servings,
            prep_time = :prep_time,
            cook_time = :cook_time,
            total_time = :total_time,
            ingredients = :ingredients,
            directions = :directions,
            notes = :notes,
            tags = :tags,
            image_data = :image_data,
            image_filename = :image_filename,
            source_url = :source_url,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id";
        
        $params = [
            ':id' => $id,
            ':title' => $data['title'] ?? $oldRecipe['title'],
            ':category' => $data['category'] ?? $oldRecipe['category'],
            ':contributor' => $data['contributor'] ?? $oldRecipe['contributor'],
            ':servings' => $data['servings'] ?? $oldRecipe['servings'],
            ':prep_time' => $data['prep_time'] ?? $oldRecipe['prep_time'],
            ':cook_time' => $data['cook_time'] ?? $oldRecipe['cook_time'],
            ':total_time' => $data['total_time'] ?? $oldRecipe['total_time'],
            ':ingredients' => $data['ingredients'] ?? $oldRecipe['ingredients'],
            ':directions' => $data['directions'] ?? $oldRecipe['directions'],
            ':notes' => $data['notes'] ?? $oldRecipe['notes'],
            ':tags' => isset($data['tags']) ? (is_array($data['tags']) ? implode(',', $data['tags']) : $data['tags']) : $oldRecipe['tags'],
            ':image_data' => $data['image_data'] ?? $oldRecipe['image_data'],
            ':image_filename' => $data['image_filename'] ?? $oldRecipe['image_filename'],
            ':source_url' => $data['source_url'] ?? $oldRecipe['source_url']
        ];
        
        try {
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);
            
            // Track changes in history
            if ($success) {
                $this->trackChanges($id, $oldRecipe, $params, $data['changed_by'] ?? 'anonymous');
            }
            
            return $success;
        } catch (PDOException $e) {
            error_log('Update recipe failed: ' . $e->getMessage());
            throw new Exception('Failed to update recipe: ' . $e->getMessage());
        }
    }
    
    /**
     * Delete a recipe
     */
    public function deleteRecipe($id) {
        $sql = "DELETE FROM recipes WHERE id = :id";
        
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log('Delete recipe failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Track changes to edit history
     */
    private function trackChanges($recipeId, $oldData, $newData, $changedBy) {
        $fieldsToTrack = ['title', 'category', 'contributor', 'ingredients', 'directions', 'tags'];
        
        foreach ($fieldsToTrack as $field) {
            $oldValue = $oldData[$field] ?? '';
            $newValue = $newData[":$field"] ?? '';
            
            if ($oldValue != $newValue) {
                $this->addEditHistory($recipeId, $field, $oldValue, $newValue, $changedBy);
            }
        }
    }
    
    /**
     * Add an edit history entry
     */
    private function addEditHistory($recipeId, $fieldName, $oldValue, $newValue, $changedBy) {
        $sql = "INSERT INTO edit_history (recipe_id, field_name, old_value, new_value, changed_by)
                VALUES (:recipe_id, :field_name, :old_value, :new_value, :changed_by)";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':recipe_id' => $recipeId,
                ':field_name' => $fieldName,
                ':old_value' => $oldValue,
                ':new_value' => $newValue,
                ':changed_by' => $changedBy
            ]);
        } catch (PDOException $e) {
            error_log('Add edit history failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get edit history for a recipe
     */
    public function getEditHistory($recipeId, $limit = 50) {
        $sql = "SELECT * FROM edit_history 
                WHERE recipe_id = :recipe_id 
                ORDER BY changed_at DESC 
                LIMIT :limit";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':recipe_id', $recipeId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Get edit history failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all unique categories
     */
    public function getCategories() {
        $sql = "SELECT DISTINCT category FROM recipes WHERE category IS NOT NULL AND category != '' ORDER BY category";
        
        try {
            $stmt = $this->db->query($sql);
            return array_column($stmt->fetchAll(), 'category');
        } catch (PDOException $e) {
            error_log('Get categories failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all unique tags
     */
    public function getTags() {
        $sql = "SELECT DISTINCT tags FROM recipes WHERE tags IS NOT NULL AND tags != ''";
        
        try {
            $stmt = $this->db->query($sql);
            $allTags = [];
            foreach ($stmt->fetchAll() as $row) {
                $tags = explode(',', $row['tags']);
                foreach ($tags as $tag) {
                    $tag = trim($tag);
                    if ($tag && !in_array($tag, $allTags)) {
                        $allTags[] = $tag;
                    }
                }
            }
            sort($allTags);
            return $allTags;
        } catch (PDOException $e) {
            error_log('Get tags failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all unique contributors
     */
    public function getContributors() {
        $sql = "SELECT DISTINCT contributor FROM recipes WHERE contributor IS NOT NULL AND contributor != '' ORDER BY contributor";
        
        try {
            $stmt = $this->db->query($sql);
            return array_column($stmt->fetchAll(), 'contributor');
        } catch (PDOException $e) {
            error_log('Get contributors failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get total recipe count
     */
    public function getRecipeCount() {
        $sql = "SELECT COUNT(*) as count FROM recipes";
        
        try {
            $stmt = $this->db->query($sql);
            $result = $stmt->fetch();
            return $result['count'];
        } catch (PDOException $e) {
            error_log('Get recipe count failed: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get last update timestamp
     */
    public function getLastUpdate() {
        $sql = "SELECT MAX(updated_at) as last_update FROM recipes";
        
        try {
            $stmt = $this->db->query($sql);
            $result = $stmt->fetch();
            return $result['last_update'];
        } catch (PDOException $e) {
            error_log('Get last update failed: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create database backup
     */
    public function createBackup() {
        $backupFile = DB_BACKUP_DIR . '/recipes_' . date('Y-m-d_H-i-s') . '.db';
        
        try {
            if (copy(DB_PATH, $backupFile)) {
                $this->cleanOldBackups();
                return $backupFile;
            }
            return false;
        } catch (Exception $e) {
            error_log('Backup failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clean old backups
     */
    private function cleanOldBackups() {
        $files = glob(DB_BACKUP_DIR . '/recipes_*.db');
        $cutoffTime = time() - (BACKUP_RETENTION_DAYS * 86400);
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                @unlink($file);
            }
        }
    }
    
    // =========================================================================
    // BLOG POST METHODS
    // =========================================================================
    
    /**
     * Get all blog posts with optional filtering
     */
    public function getBlogPosts($search = null, $category = null, $published = null) {
        $sql = "SELECT * FROM blog_posts WHERE 1=1";
        $params = [];
        
        if ($search) {
            $sql .= " AND (title LIKE :search OR content LIKE :search)";
            $params[':search'] = "%$search%";
        }
        
        if ($category) {
            $sql .= " AND category = :category";
            $params[':category'] = $category;
        }
        
        if ($published !== null) {
            $sql .= " AND published = :published";
            $params[':published'] = $published ? 1 : 0;
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Get blog posts failed: ' . $e->getMessage());
            throw new Exception('Failed to fetch blog posts');
        }
    }
    
    /**
     * Get a single blog post by ID
     */
    public function getBlogPost($id) {
        $sql = "SELECT * FROM blog_posts WHERE id = :id";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log('Get blog post failed: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create a new blog post
     */
    public function createBlogPost($data) {
        $sql = "INSERT INTO blog_posts (
            title, content, author, category, tags, image_data, image_filename, published
        ) VALUES (
            :title, :content, :author, :category, :tags, :image_data, :image_filename, :published
        )";
        
        $params = [
            ':title' => $data['title'],
            ':content' => $data['content'],
            ':author' => $data['author'] ?? null,
            ':category' => $data['category'] ?? null,
            ':tags' => is_array($data['tags'] ?? null) ? implode(',', $data['tags']) : ($data['tags'] ?? null),
            ':image_data' => $data['image_data'] ?? null,
            ':image_filename' => $data['image_filename'] ?? null,
            ':published' => isset($data['published']) ? ($data['published'] ? 1 : 0) : 1
        ];
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Create blog post failed: ' . $e->getMessage());
            throw new Exception('Failed to create blog post: ' . $e->getMessage());
        }
    }
    
    /**
     * Update an existing blog post
     */
    public function updateBlogPost($id, $data) {
        $oldPost = $this->getBlogPost($id);
        if (!$oldPost) {
            return false;
        }
        
        $sql = "UPDATE blog_posts SET
            title = :title,
            content = :content,
            author = :author,
            category = :category,
            tags = :tags,
            image_data = :image_data,
            image_filename = :image_filename,
            published = :published,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id";
        
        $params = [
            ':id' => $id,
            ':title' => $data['title'] ?? $oldPost['title'],
            ':content' => $data['content'] ?? $oldPost['content'],
            ':author' => $data['author'] ?? $oldPost['author'],
            ':category' => $data['category'] ?? $oldPost['category'],
            ':tags' => isset($data['tags']) ? (is_array($data['tags']) ? implode(',', $data['tags']) : $data['tags']) : $oldPost['tags'],
            ':image_data' => $data['image_data'] ?? $oldPost['image_data'],
            ':image_filename' => $data['image_filename'] ?? $oldPost['image_filename'],
            ':published' => isset($data['published']) ? ($data['published'] ? 1 : 0) : $oldPost['published']
        ];
        
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log('Update blog post failed: ' . $e->getMessage());
            throw new Exception('Failed to update blog post: ' . $e->getMessage());
        }
    }
    
    /**
     * Delete a blog post
     */
    public function deleteBlogPost($id) {
        $sql = "DELETE FROM blog_posts WHERE id = :id";
        
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log('Delete blog post failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get blog post count
     */
    public function getBlogPostCount() {
        $sql = "SELECT COUNT(*) as count FROM blog_posts";
        
        try {
            $stmt = $this->db->query($sql);
            $result = $stmt->fetch();
            return $result['count'];
        } catch (PDOException $e) {
            error_log('Get blog post count failed: ' . $e->getMessage());
            return 0;
        }
    }
}
