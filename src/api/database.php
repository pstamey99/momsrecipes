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
            family_source TEXT,
            servings TEXT,
            prep_time TEXT,
            cook_time TEXT,
            total_time TEXT,
            ingredients TEXT NOT NULL,
            directions TEXT NOT NULL,
            notes TEXT,
            tags TEXT,
            meal_type TEXT,
            cuisine TEXT,
            main_ingredient TEXT,
            method TEXT,
            occasion TEXT,
            uuid TEXT,
            image_data TEXT,
            image_filename TEXT,
            images TEXT,
            source_url TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS blog_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT DEFAULT '',
            content TEXT NOT NULL,
            author TEXT,
            date TEXT,
            category TEXT,
            tags TEXT,
            likes TEXT DEFAULT '[]',
            replies TEXT DEFAULT '[]',
            image_data TEXT,
            image_filename TEXT,
            published BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS title_changes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER,
            old_title TEXT NOT NULL,
            new_title TEXT NOT NULL,
            changed_by TEXT,
            changed_at DATETIME DEFAULT CURRENT_TIMESTAMP
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
        
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            fullname TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME,
            is_active INTEGER DEFAULT 1
        );

        CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            session_token TEXT UNIQUE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS approved_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            added_by TEXT
        );

        CREATE TABLE IF NOT EXISTS custom_meta_options (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            field_type TEXT NOT NULL,
            value TEXT NOT NULL,
            added_by TEXT,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(field_type, value)
        );

        CREATE TABLE IF NOT EXISTS recipe_favorites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            recipe_uuid TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(username, recipe_uuid)
        );

        CREATE TABLE IF NOT EXISTS recipe_reactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            recipe_uuid TEXT NOT NULL,
            reaction TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(username, recipe_uuid, reaction)
        );

        CREATE INDEX IF NOT EXISTS idx_favorites_uuid ON recipe_favorites(recipe_uuid);
        CREATE INDEX IF NOT EXISTS idx_favorites_user ON recipe_favorites(username);
        CREATE INDEX IF NOT EXISTS idx_reactions_uuid ON recipe_reactions(recipe_uuid);

        CREATE INDEX IF NOT EXISTS idx_recipes_category ON recipes(category);
        CREATE INDEX IF NOT EXISTS idx_recipes_contributor ON recipes(contributor);
        CREATE INDEX IF NOT EXISTS idx_recipes_updated ON recipes(updated_at);
        CREATE INDEX IF NOT EXISTS idx_blog_posts_category ON blog_posts(category);
        CREATE INDEX IF NOT EXISTS idx_blog_posts_published ON blog_posts(published);
        CREATE INDEX IF NOT EXISTS idx_blog_posts_created ON blog_posts(created_at);
        CREATE INDEX IF NOT EXISTS idx_history_recipe ON edit_history(recipe_id);
        CREATE INDEX IF NOT EXISTS idx_history_date ON edit_history(changed_at);
        CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(session_token);
        CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);
        ";
        
        try {
            $this->db->exec($schema);
            $this->migrateSchema();
        } catch (PDOException $e) {
            error_log('Schema initialization failed: ' . $e->getMessage());
            throw new Exception('Failed to initialize database schema');
        }
    }
    
    /**
     * Migrate existing databases to add missing columns/tables
     */
    private function migrateSchema() {
        // Add likes column to blog_posts if missing
        try {
            $this->db->exec("ALTER TABLE blog_posts ADD COLUMN likes TEXT DEFAULT '[]'");
        } catch (PDOException $e) { /* already exists */ }
        
        // Add replies column to blog_posts if missing
        try {
            $this->db->exec("ALTER TABLE blog_posts ADD COLUMN replies TEXT DEFAULT '[]'");
        } catch (PDOException $e) { /* already exists */ }
        
        // Add date column to blog_posts if missing
        try {
            $this->db->exec("ALTER TABLE blog_posts ADD COLUMN date TEXT");
        } catch (PDOException $e) { /* already exists */ }
        
        // Add metadata columns to recipes if missing (for existing databases)
        $metaCols = ['meal_type', 'cuisine', 'main_ingredient', 'method', 'occasion', 'uuid', 'family_source', 'images'];
        foreach ($metaCols as $col) {
            try {
                $this->db->exec("ALTER TABLE recipes ADD COLUMN {$col} TEXT");
            } catch (PDOException $e) { /* already exists */ }
        }

        // Migrate: copy contributor → family_source for any recipes where family_source is still empty
        try {
            $this->db->exec("UPDATE recipes SET family_source = contributor WHERE family_source IS NULL AND contributor IS NOT NULL AND contributor != ''");
        } catch (PDOException $e) { /* ignore */ }
        
        // Create title_changes table if missing
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS title_changes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                recipe_id INTEGER,
                old_title TEXT NOT NULL,
                new_title TEXT NOT NULL,
                changed_by TEXT,
                changed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (PDOException $e) { /* already exists */ }

        // Add email column to users table if missing
        try {
            $this->db->exec("ALTER TABLE users ADD COLUMN email TEXT");
        } catch (PDOException $e) { /* already exists */ }

        // Create user_preferences table
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS user_preferences (
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                username              TEXT NOT NULL UNIQUE,
                email                 TEXT,
                notify_new_recipe     INTEGER NOT NULL DEFAULT 1,
                notify_reactions      INTEGER NOT NULL DEFAULT 0,
                notify_edits          INTEGER NOT NULL DEFAULT 0,
                notify_weekly         INTEGER NOT NULL DEFAULT 0,
                notifications_enabled INTEGER NOT NULL DEFAULT 1,
                created_at            TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at            TEXT NOT NULL DEFAULT (datetime('now'))
            )");
        } catch (PDOException $e) { /* already exists */ }

        // Create activity_log table
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS activity_log (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                username   TEXT NOT NULL,
                action     TEXT NOT NULL,
                ip_address TEXT,
                detail     TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )");
        } catch (PDOException $e) { /* already exists */ }

        // Add recipe_uuid column to title_changes if missing
        try {
            $this->db->exec("ALTER TABLE title_changes ADD COLUMN recipe_uuid TEXT");
        } catch (PDOException $e) { /* already exists */ }

        // Add is_admin column to users table if missing
        try {
            $this->db->exec("ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0");
        } catch (PDOException $e) { /* already exists */ }
    }
    
    /**
     * Check if database is healthy
     */
    public function raw() {
        return $this->db;
    }

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
        // Exclude image_data — base64 images exhaust memory with 300+ recipes
        $sql = "SELECT id, title, category, contributor, family_source, servings, prep_time, cook_time,
                       total_time, ingredients, directions, notes, tags, meal_type, cuisine,
                       main_ingredient, method, occasion, uuid, image_filename,
                       source_url, created_at, updated_at
                FROM recipes WHERE 1=1";
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
     * Get recipe without image_data (lightweight — safe for update/notify flows)
     */
    public function getRecipeMeta($id) {
        $sql = "SELECT id, title, category, contributor, family_source, servings, prep_time,
                       cook_time, total_time, ingredients, directions, notes, tags,
                       meal_type, cuisine, main_ingredient, method, occasion, uuid,
                       image_filename, images, source_url, created_at, updated_at
                FROM recipes WHERE id = :id";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log('Get recipe meta failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get recipe by UUID string (used by recipe pages)
     */
    public function getRecipeByUuid($uuid) {
        $sql = "SELECT * FROM recipes WHERE uuid = :uuid LIMIT 1";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':uuid' => $uuid]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log('Get recipe by UUID failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve an id-or-uuid string to an integer recipe id.
     * Returns the integer id, or 0 if not found.
     */
    public function resolveRecipeId($idOrUuid) {
        if (is_numeric($idOrUuid)) {
            return (int)$idOrUuid;
        }
        // Looks like a UUID — look it up
        $recipe = $this->getRecipeByUuid($idOrUuid);
        return $recipe ? (int)$recipe['id'] : 0;
    }
    
    /**
     * Create a new recipe
     */
    public function createRecipe($data) {
        $sql = "INSERT INTO recipes (
            title, category, contributor, family_source, servings, prep_time, cook_time, total_time,
            ingredients, directions, notes, tags,
            meal_type, cuisine, main_ingredient, method, occasion, uuid,
            image_data, image_filename, images, source_url
        ) VALUES (
            :title, :category, :contributor, :family_source, :servings, :prep_time, :cook_time, :total_time,
            :ingredients, :directions, :notes, :tags,
            :meal_type, :cuisine, :main_ingredient, :method, :occasion, :uuid,
            :image_data, :image_filename, :images, :source_url
        )";
        
        $params = [
            ':title'          => $data['title'],
            ':category'       => $data['category'] ?? null,
            ':contributor'    => $data['contributor'] ?? null,
            ':family_source'  => $data['family_source'] ?? $data['contributor'] ?? null,
            ':servings'       => $data['servings'] ?? null,
            ':prep_time'      => $data['prep_time'] ?? null,
            ':cook_time'      => $data['cook_time'] ?? null,
            ':total_time'     => $data['total_time'] ?? null,
            ':ingredients'    => $data['ingredients'],
            ':directions'     => $data['directions'],
            ':notes'          => $data['notes'] ?? null,
            ':tags'           => is_array($data['tags'] ?? null) ? implode(',', $data['tags']) : ($data['tags'] ?? null),
            ':meal_type'      => $data['meal_type'] ?? null,
            ':cuisine'        => $data['cuisine'] ?? null,
            ':main_ingredient'=> $data['main_ingredient'] ?? null,
            ':method'         => $data['method'] ?? null,
            ':occasion'       => $data['occasion'] ?? null,
            ':uuid'           => $data['uuid'] ?? null,
            ':image_data'     => $data['image_data'] ?? null,
            ':image_filename' => $data['image_filename'] ?? null,
            ':images'         => $data['images'] ?? null,
            ':source_url'     => $data['source_url'] ?? null
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
        // Get old values for history tracking — explicitly exclude image_data (can be huge)
        $oldRecipe = $this->db->prepare(
            "SELECT id, title, category, contributor, family_source, servings, prep_time, cook_time,
                    total_time, ingredients, directions, notes, tags, meal_type, cuisine,
                    main_ingredient, method, occasion, uuid, image_filename, images, source_url
             FROM recipes WHERE id = :id"
        );
        $oldRecipe->execute([':id' => $id]);
        $oldRecipe = $oldRecipe->fetch();
        if (!$oldRecipe) {
            return false;
        }

        // Only update image_data if caller explicitly provided it
        $hasImageData = array_key_exists('image_data', $data);

        $sql = "UPDATE recipes SET
            title = :title,
            category = :category,
            contributor = :contributor,
            family_source = :family_source,
            servings = :servings,
            prep_time = :prep_time,
            cook_time = :cook_time,
            total_time = :total_time,
            ingredients = :ingredients,
            directions = :directions,
            notes = :notes,
            tags = :tags,
            meal_type = :meal_type,
            cuisine = :cuisine,
            main_ingredient = :main_ingredient,
            method = :method,
            occasion = :occasion,
            uuid = :uuid,
            image_filename = :image_filename,
            images = :images,
            source_url = :source_url,
            updated_at = CURRENT_TIMESTAMP"
            . ($hasImageData ? ", image_data = :image_data" : "")
            . " WHERE id = :id";

        $params = [
            ':id'             => $id,
            ':title'          => $data['title'] ?? $oldRecipe['title'],
            ':category'       => $data['category'] ?? $oldRecipe['category'],
            ':contributor'    => $data['contributor'] ?? $oldRecipe['contributor'],
            ':family_source'  => $data['family_source'] ?? $oldRecipe['family_source'] ?? null,
            ':servings'       => $data['servings'] ?? $oldRecipe['servings'],
            ':prep_time'      => $data['prep_time'] ?? $oldRecipe['prep_time'],
            ':cook_time'      => $data['cook_time'] ?? $oldRecipe['cook_time'],
            ':total_time'     => $data['total_time'] ?? $oldRecipe['total_time'],
            ':ingredients'    => $data['ingredients'] ?? $oldRecipe['ingredients'],
            ':directions'     => $data['directions'] ?? $oldRecipe['directions'],
            ':notes'          => $data['notes'] ?? $oldRecipe['notes'],
            ':tags'           => isset($data['tags']) ? (is_array($data['tags']) ? implode(',', $data['tags']) : $data['tags']) : $oldRecipe['tags'],
            ':meal_type'      => $data['meal_type'] ?? $oldRecipe['meal_type'] ?? null,
            ':cuisine'        => $data['cuisine'] ?? $oldRecipe['cuisine'] ?? null,
            ':main_ingredient'=> $data['main_ingredient'] ?? $oldRecipe['main_ingredient'] ?? null,
            ':method'         => $data['method'] ?? $oldRecipe['method'] ?? null,
            ':occasion'       => $data['occasion'] ?? $oldRecipe['occasion'] ?? null,
            ':uuid'           => $data['uuid'] ?? $oldRecipe['uuid'] ?? null,
            ':image_filename' => $data['image_filename'] ?? $oldRecipe['image_filename'],
            ':images'         => $data['images'] ?? $oldRecipe['images'] ?? null,
            ':source_url'     => $data['source_url'] ?? $oldRecipe['source_url']
        ];

        if ($hasImageData) {
            $params[':image_data'] = $data['image_data'];
        }
        
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
     * Public method to add edit history entry from API
     */
    public function addEditHistoryEntry($recipeId, $data) {
        $this->addEditHistory(
            $recipeId,
            $data['field_name'] ?? 'edit',
            $data['old_value'] ?? '',
            $data['new_value'] ?? '',
            $data['changed_by'] ?? 'Anonymous'
        );
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
     * Get all unique contributors (who digitized/added the recipe)
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
     * Get all unique family sources (recipe authors / where the recipe came from)
     */
    public function getFamilySources() {
        $sql = "SELECT DISTINCT family_source FROM recipes WHERE family_source IS NOT NULL AND family_source != '' ORDER BY family_source";
        try {
            $stmt = $this->db->query($sql);
            return array_column($stmt->fetchAll(), 'family_source');
        } catch (PDOException $e) {
            error_log('Get family sources failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Add an image filename to a recipe's images JSON array.
     * Returns the updated array.
     */
    public function addRecipeImage($id, $filename) {
        $recipe = $this->getRecipe($id);
        if (!$recipe) return false;
        $images = json_decode($recipe['images'] ?? '[]', true) ?: [];
        if (!in_array($filename, $images)) {
            $images[] = $filename;
        }
        $stmt = $this->db->prepare("UPDATE recipes SET images = :images, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':images' => json_encode($images), ':id' => $id]);
        return $images;
    }

    /**
     * Delete an image filename from a recipe's images JSON array.
     * Also deletes the physical file from the images directory.
     * Returns the updated array.
     */
    public function deleteRecipeImage($id, $filename) {
        $recipe = $this->getRecipe($id);
        if (!$recipe) return false;

        // Remove from images JSON array
        $images = json_decode($recipe['images'] ?? '[]', true) ?: [];
        $images = array_values(array_filter($images, fn($f) => $f !== $filename));

        // Check if this is the legacy primary image_filename
        $updatePrimary = ($recipe['image_filename'] === $filename);

        $stmt = $this->db->prepare(
            "UPDATE recipes SET images = :images" .
            ($updatePrimary ? ", image_filename = NULL, image_data = NULL" : "") .
            ", updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $stmt->execute([':images' => json_encode($images), ':id' => $id]);

        // Delete physical file
        $imgPath = __DIR__ . '/../images/' . basename($filename);
        if (file_exists($imgPath)) @unlink($imgPath);

        return $images;
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
            $rows = $stmt->fetchAll();
            // Decode JSON fields
            foreach ($rows as &$row) {
                $row['likes']   = json_decode($row['likes']   ?? '[]', true) ?: [];
                $row['replies'] = json_decode($row['replies'] ?? '[]', true) ?: [];
                if (empty($row['date'])) $row['date'] = $row['created_at'];
            }
            return $rows;
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
     * Update a single field on a blog post
     */
    public function updateBlogPostField($id, $field, $value) {
        $allowed = ['likes', 'replies', 'title', 'content', 'author', 'published'];
        if (!in_array($field, $allowed)) return false;
        $sql = "UPDATE blog_posts SET $field = :value, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([':value' => $value, ':id' => $id]);
        } catch (PDOException $e) {
            error_log('updateBlogPostField failed: ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // TITLE CHANGE METHODS
    // =========================================================================
    
    /**
     * Get recent title changes
     */
    public function getTitleChanges($limit = 30) {
        $sql = "SELECT tc.id, tc.recipe_id, tc.old_title, tc.new_title, tc.changed_by, tc.changed_at,
                       COALESCE(tc.recipe_uuid, r.uuid) AS recipe_uuid
                FROM title_changes tc
                LEFT JOIN recipes r ON r.id = tc.recipe_id
                ORDER BY tc.changed_at DESC LIMIT :limit";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Get title changes failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Save a title change record
     */
    public function saveTitleChange($recipeId, $oldTitle, $newTitle, $changedBy = null) {
        // $recipeId may be a uuid string or integer id — store both
        $uuidVal  = null;
        $intIdVal = null;
        if (is_numeric($recipeId)) {
            $intIdVal = (int)$recipeId;
            // Look up uuid from recipes table
            try {
                $s = $this->db->prepare("SELECT uuid FROM recipes WHERE id = ?");
                $s->execute([$intIdVal]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if ($row) $uuidVal = $row['uuid'];
            } catch (PDOException $e) {}
        } else {
            $uuidVal = $recipeId;
            // Look up integer id from recipes table
            try {
                $s = $this->db->prepare("SELECT id FROM recipes WHERE uuid = ?");
                $s->execute([$uuidVal]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if ($row) $intIdVal = (int)$row['id'];
            } catch (PDOException $e) {}
        }

        $sql = "INSERT INTO title_changes (recipe_id, recipe_uuid, old_title, new_title, changed_by)
                VALUES (:recipe_id, :recipe_uuid, :old_title, :new_title, :changed_by)";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':recipe_id'   => $intIdVal,
                ':recipe_uuid' => $uuidVal,
                ':old_title'   => $oldTitle,
                ':new_title'   => $newTitle,
                ':changed_by'  => $changedBy
            ]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('Save title change failed: ' . $e->getMessage());
            return false;
        }
    }

    // ── Auth ────────────────────────────────────────────────────────────────

    public function isApprovedUser($username) {
        try {
            $stmt = $this->db->prepare('SELECT id FROM approved_users WHERE LOWER(username) = LOWER(:u)');
            $stmt->execute([':u' => $username]);
            return (bool)$stmt->fetch();
        } catch (PDOException $e) {
            error_log('isApprovedUser failed: ' . $e->getMessage());
            return false;
        }
    }

    public function addApprovedUser($username, $addedBy = null) {
        try {
            $stmt = $this->db->prepare('INSERT OR IGNORE INTO approved_users (username, added_by) VALUES (LOWER(:u), :by)');
            $stmt->execute([':u' => $username, ':by' => $addedBy]);
            return true;
        } catch (PDOException $e) {
            error_log('addApprovedUser failed: ' . $e->getMessage());
            return false;
        }
    }

    public function removeApprovedUser($username) {
        try {
            $stmt = $this->db->prepare('DELETE FROM approved_users WHERE LOWER(username) = LOWER(:u)');
            $stmt->execute([':u' => $username]);
            // Also deactivate the user account
            $stmt2 = $this->db->prepare('UPDATE users SET is_active = 0 WHERE LOWER(username) = LOWER(:u)');
            $stmt2->execute([':u' => $username]);
            return true;
        } catch (PDOException $e) {
            error_log('removeApprovedUser failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getApprovedUsers() {
        try {
            $stmt = $this->db->query('SELECT username, added_at, added_by FROM approved_users ORDER BY username');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('getApprovedUsers failed: ' . $e->getMessage());
            return [];
        }
    }

    public function registerUser($username, $password, $fullname = '') {
        $username = strtolower(trim($username));
        if (!$this->isApprovedUser($username)) {
            return ['error' => 'Username not on approved list'];
        }
        try {
            $check = $this->db->prepare('SELECT id FROM users WHERE username = :u');
            $check->execute([':u' => $username]);
            if ($check->fetch()) {
                return ['error' => 'Username already registered'];
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $this->db->prepare('INSERT INTO users (username, password_hash, fullname) VALUES (:u, :h, :f)');
            $stmt->execute([':u' => $username, ':h' => $hash, ':f' => $fullname]);
            return ['success' => true];
        } catch (PDOException $e) {
            error_log('registerUser failed: ' . $e->getMessage());
            return ['error' => 'Registration failed'];
        }
    }

    public function loginUser($username, $password) {
        $username = strtolower(trim($username));
        if (!$this->isApprovedUser($username)) {
            return ['error' => 'Access denied'];
        }
        try {
            $stmt = $this->db->prepare('SELECT id, password_hash, fullname, is_active, is_admin FROM users WHERE username = :u');
            $stmt->execute([':u' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
                return ['error' => 'Invalid username or password'];
            }
            // Create session token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
            $s = $this->db->prepare('INSERT INTO sessions (user_id, session_token, expires_at) VALUES (:uid, :tok, :exp)');
            $s->execute([':uid' => $user['id'], ':tok' => $token, ':exp' => $expires]);
            // Update last login
            $this->db->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = :id')->execute([':id' => $user['id']]);
            return ['success' => true, 'token' => $token, 'username' => $username, 'fullname' => $user['fullname'], 'is_admin' => (int)$user['is_admin']];
        } catch (PDOException $e) {
            error_log('loginUser failed: ' . $e->getMessage());
            return ['error' => 'Login failed'];
        }
    }

    public function verifySession($token) {
        if (!$token) return null;
        try {
            $stmt = $this->db->prepare('
                SELECT u.id, u.username, u.fullname, u.is_active
                FROM sessions s
                JOIN users u ON s.user_id = u.id
                WHERE s.session_token = :tok AND s.expires_at > CURRENT_TIMESTAMP
            ');
            $stmt->execute([':tok' => $token]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || !$user['is_active']) return null;
            if (!$this->isApprovedUser($user['username'])) return null;
            return $user;
        } catch (PDOException $e) {
            error_log('verifySession failed: ' . $e->getMessage());
            return null;
        }
    }

    public function logoutUser($token) {
        try {
            $stmt = $this->db->prepare('DELETE FROM sessions WHERE session_token = :tok');
            $stmt->execute([':tok' => $token]);
            return true;
        } catch (PDOException $e) {
            error_log('logoutUser failed: ' . $e->getMessage());
            return false;
        }
    }

    public function resetPassword($username, $fullname, $newPassword) {
        $username = strtolower(trim($username));
        if (!$this->isApprovedUser($username)) {
            return ['error' => 'Username not approved'];
        }
        try {
            $stmt = $this->db->prepare('SELECT id, fullname FROM users WHERE username = :u AND is_active = 1');
            $stmt->execute([':u' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) return ['error' => 'User not found'];
            // Verify fullname matches (security check)
            if (strtolower(trim($user['fullname'])) !== strtolower(trim($fullname))) {
                return ['error' => 'Full name does not match our records'];
            }
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $this->db->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute([':h' => $hash, ':id' => $user['id']]);
            // Invalidate all existing sessions
            $this->db->prepare('DELETE FROM sessions WHERE user_id = :id')->execute([':id' => $user['id']]);
            return ['success' => true];
        } catch (PDOException $e) {
            error_log('resetPassword failed: ' . $e->getMessage());
            return ['error' => 'Reset failed'];
        }
    }

    // ── Custom Meta Options ─────────────────────────────────────────────────

    public function getCustomMetaOptions($fieldType = null) {
        try {
            if ($fieldType) {
                $stmt = $this->db->prepare('SELECT value FROM custom_meta_options WHERE field_type = :t ORDER BY value');
                $stmt->execute([':t' => $fieldType]);
                return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'value');
            } else {
                $stmt = $this->db->query('SELECT field_type, value FROM custom_meta_options ORDER BY field_type, value');
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $result = [];
                foreach ($rows as $row) {
                    $result[$row['field_type']][] = $row['value'];
                }
                return $result;
            }
        } catch (PDOException $e) {
            error_log('getCustomMetaOptions failed: ' . $e->getMessage());
            return $fieldType ? [] : [];
        }
    }

    public function addCustomMetaOption($fieldType, $value, $addedBy = null) {
        try {
            $stmt = $this->db->prepare('INSERT OR IGNORE INTO custom_meta_options (field_type, value, added_by) VALUES (:t, :v, :by)');
            $stmt->execute([':t' => $fieldType, ':v' => $value, ':by' => $addedBy]);
            return true;
        } catch (PDOException $e) {
            error_log('addCustomMetaOption failed: ' . $e->getMessage());
            return false;
        }
    }

    // ── Recipes as JSON (for search index) ─────────────────────────────────

    // ── Favorites ───────────────────────────────────────────────────────────

    public function toggleFavorite($username, $recipeUuid) {
        try {
            $stmt = $this->db->prepare(
                'SELECT id FROM recipe_favorites WHERE username=? AND recipe_uuid=?'
            );
            $stmt->execute([$username, $recipeUuid]);
            if ($stmt->fetch()) {
                $this->db->prepare(
                    'DELETE FROM recipe_favorites WHERE username=? AND recipe_uuid=?'
                )->execute([$username, $recipeUuid]);
                return ['favorited' => false];
            } else {
                $this->db->prepare(
                    'INSERT INTO recipe_favorites (username, recipe_uuid) VALUES (?,?)'
                )->execute([$username, $recipeUuid]);
                return ['favorited' => true];
            }
        } catch (PDOException $e) {
            error_log('toggleFavorite failed: ' . $e->getMessage());
            throw new Exception('Failed to toggle favorite');
        }
    }

    public function getFavorites($username) {
        try {
            $stmt = $this->db->prepare(
                'SELECT recipe_uuid FROM recipe_favorites WHERE username=? ORDER BY created_at DESC'
            );
            $stmt->execute([$username]);
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'recipe_uuid');
        } catch (PDOException $e) {
            error_log('getFavorites failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getFavoriteCounts() {
        try {
            $stmt = $this->db->query(
                'SELECT recipe_uuid, COUNT(*) as count FROM recipe_favorites GROUP BY recipe_uuid'
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = [];
            foreach ($rows as $row) $result[$row['recipe_uuid']] = (int)$row['count'];
            return $result;
        } catch (PDOException $e) {
            error_log('getFavoriteCounts failed: ' . $e->getMessage());
            return [];
        }
    }

    // ── Reactions ───────────────────────────────────────────────────────────

    public function toggleReaction($username, $recipeUuid, $reaction) {
        $allowed = ['❤️', '😋', '⭐', '👍'];
        if (!in_array($reaction, $allowed)) throw new Exception('Invalid reaction');
        try {
            $stmt = $this->db->prepare(
                'SELECT id FROM recipe_reactions WHERE username=? AND recipe_uuid=? AND reaction=?'
            );
            $stmt->execute([$username, $recipeUuid, $reaction]);
            if ($stmt->fetch()) {
                $this->db->prepare(
                    'DELETE FROM recipe_reactions WHERE username=? AND recipe_uuid=? AND reaction=?'
                )->execute([$username, $recipeUuid, $reaction]);
                return ['reacted' => false, 'reaction' => $reaction];
            } else {
                $this->db->prepare(
                    'INSERT INTO recipe_reactions (username, recipe_uuid, reaction) VALUES (?,?,?)'
                )->execute([$username, $recipeUuid, $reaction]);
                return ['reacted' => true, 'reaction' => $reaction];
            }
        } catch (PDOException $e) {
            error_log('toggleReaction failed: ' . $e->getMessage());
            throw new Exception('Failed to toggle reaction');
        }
    }

    public function getReactions($recipeUuid) {
        try {
            $stmt = $this->db->prepare(
                'SELECT reaction, COUNT(*) as count FROM recipe_reactions WHERE recipe_uuid=? GROUP BY reaction'
            );
            $stmt->execute([$recipeUuid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = [];
            foreach ($rows as $row) $result[$row['reaction']] = (int)$row['count'];
            return $result;
        } catch (PDOException $e) {
            error_log('getReactions failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getReactionsByUser($username, $recipeUuid) {
        try {
            $stmt = $this->db->prepare(
                'SELECT reaction FROM recipe_reactions WHERE username=? AND recipe_uuid=?'
            );
            $stmt->execute([$username, $recipeUuid]);
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'reaction');
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getAllReactions() {
        try {
            $stmt = $this->db->query(
                'SELECT recipe_uuid, reaction, COUNT(*) as count FROM recipe_reactions GROUP BY recipe_uuid, reaction'
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = [];
            foreach ($rows as $row) {
                if (!isset($result[$row['recipe_uuid']])) $result[$row['recipe_uuid']] = [];
                $result[$row['recipe_uuid']][$row['reaction']] = (int)$row['count'];
            }
            return $result;
        } catch (PDOException $e) {
            error_log('getAllReactions failed: ' . $e->getMessage());
            return [];
        }
    }

    // ── Search index ────────────────────────────────────────────────────────

    public function getRecipesForSearch() {
        try {
            $stmt = $this->db->query('
                SELECT id, title, category, contributor, family_source, meal_type, cuisine,
                       main_ingredient, method, occasion, tags, uuid, updated_at
                FROM recipes ORDER BY title
            ');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('getRecipesForSearch failed: ' . $e->getMessage());
            return [];
        }
    }

    // ── User Preferences & Notifications ────────────────────────────────────

    public function getUserPreferences(string $username): array {
        try {
            $stmt = $this->db->prepare("SELECT * FROM user_preferences WHERE username = ?");
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                // Return defaults — row doesn't exist yet
                return [
                    'username'              => $username,
                    'email'                 => '',
                    'notify_new_recipe'     => 1,
                    'notify_reactions'      => 0,
                    'notify_edits'          => 0,
                    'notify_weekly'         => 0,
                    'notifications_enabled' => 1,
                ];
            }
            return $row;
        } catch (PDOException $e) {
            error_log('getUserPreferences failed: ' . $e->getMessage());
            return [];
        }
    }

    public function saveUserPreferences(string $username, array $data): bool {
        try {
            $email     = trim($data['email'] ?? '');
            $newRecipe = isset($data['notify_new_recipe'])     ? (int)(bool)$data['notify_new_recipe']     : 1;
            $reactions = isset($data['notify_reactions'])      ? (int)(bool)$data['notify_reactions']      : 0;
            $edits     = isset($data['notify_edits'])          ? (int)(bool)$data['notify_edits']          : 0;
            $weekly    = isset($data['notify_weekly'])         ? (int)(bool)$data['notify_weekly']         : 0;
            $enabled   = isset($data['notifications_enabled']) ? (int)(bool)$data['notifications_enabled'] : 1;

            $stmt = $this->db->prepare("
                INSERT INTO user_preferences
                    (username, email, notify_new_recipe, notify_reactions,
                     notify_edits, notify_weekly, notifications_enabled, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
                ON CONFLICT(username) DO UPDATE SET
                    email                 = excluded.email,
                    notify_new_recipe     = excluded.notify_new_recipe,
                    notify_reactions      = excluded.notify_reactions,
                    notify_edits          = excluded.notify_edits,
                    notify_weekly         = excluded.notify_weekly,
                    notifications_enabled = excluded.notifications_enabled,
                    updated_at            = excluded.updated_at
            ");
            return $stmt->execute([$username, $email, $newRecipe, $reactions, $edits, $weekly, $enabled]);
        } catch (PDOException $e) {
            error_log('saveUserPreferences failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Returns users who opted into a specific notification type.
     * $type: 'notify_new_recipe' | 'notify_reactions' | 'notify_edits' | 'notify_weekly'
     */
    public function getNotificationSubscribers(string $type): array {
        $allowed = ['notify_new_recipe', 'notify_reactions', 'notify_edits', 'notify_weekly'];
        if (!in_array($type, $allowed, true)) return [];
        try {
            $stmt = $this->db->query("
                SELECT username, email
                FROM user_preferences
                WHERE notifications_enabled = 1
                  AND {$type} = 1
                  AND email IS NOT NULL
                  AND email != ''
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('getNotificationSubscribers failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Returns data for the weekly digest: recipes added in last 7 days.
     */
    public function getWeeklyDigestData(): array {
        try {
            $stmtNew = $this->db->prepare("
                SELECT title, uuid, contributor, family_source
                FROM recipes
                WHERE created_at >= datetime('now', '-7 days')
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmtNew->execute();
            $newRecipes = $stmtNew->fetchAll(PDO::FETCH_ASSOC);

            $stmtTop = $this->db->prepare("
                SELECT r.title, r.uuid
                FROM recipes r
                ORDER BY r.updated_at DESC
                LIMIT 5
            ");
            $stmtTop->execute();
            $topReacted = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

            return [
                'new_recipes'   => $newRecipes,
                'top_reactions' => $topReacted,
                'week_label'    => date('F j') . '–' . date('j, Y', strtotime('+6 days')),
            ];
        } catch (PDOException $e) {
            error_log('getWeeklyDigestData failed: ' . $e->getMessage());
            return ['new_recipes' => [], 'top_reactions' => [], 'week_label' => date('F j')];
        }
    }

    // ── Activity Log ─────────────────────────────────────────────────────────

    public function logActivity(string $username, string $action, string $detail = ''): void {
        try {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
                ?? $_SERVER['HTTP_CF_CONNECTING_IP']
                ?? $_SERVER['REMOTE_ADDR']
                ?? 'unknown';
            $ip = trim(explode(',', $ip)[0]);
            $stmt = $this->db->prepare("
                INSERT INTO activity_log (username, action, ip_address, detail, created_at)
                VALUES (?, ?, ?, ?, datetime('now'))
            ");
            $stmt->execute([$username, $action, $ip, $detail]);
        } catch (PDOException $e) {
            error_log('logActivity failed: ' . $e->getMessage());
        }
    }

    public function getActivityLog(string $filterUser = '', int $limit = 50, int $offset = 0): array {
        try {
            if ($filterUser) {
                $stmt = $this->db->prepare("
                    SELECT id, username, action, ip_address, detail, created_at
                    FROM activity_log
                    WHERE LOWER(username) = LOWER(?)
                    ORDER BY created_at DESC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$filterUser, $limit, $offset]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT id, username, action, ip_address, detail, created_at
                    FROM activity_log
                    ORDER BY created_at DESC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$limit, $offset]);
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('getActivityLog failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getActivityLogUsers(): array {
        try {
            $stmt = $this->db->query("
                SELECT DISTINCT username FROM activity_log ORDER BY username
            ");
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'username');
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getActivityLogCount(string $filterUser = ''): int {
        try {
            if ($filterUser) {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM activity_log WHERE LOWER(username) = LOWER(?)
                ");
                $stmt->execute([$filterUser]);
            } else {
                $stmt = $this->db->query("SELECT COUNT(*) FROM activity_log");
            }
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    // ── User Management ──────────────────────────────────────────────────────

    /**
     * Returns all registered users with their admin status.
     */
    public function getAllUsers(): array {
        try {
            $stmt = $this->db->query("
                SELECT username, fullname, is_admin, is_active, created_at, last_login
                FROM users
                ORDER BY username
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('getAllUsers failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if a user has admin privileges (is_admin=1).
     * Note: paul/pstamey is always super admin regardless of this flag.
     */
    public function isAdmin(string $username): bool {
        $username = strtolower(trim($username));
        if ($username === 'paul' || $username === 'pstamey') return true;
        try {
            $stmt = $this->db->prepare("
                SELECT is_admin FROM users WHERE LOWER(username) = LOWER(?) AND is_active = 1
            ");
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row && (int)$row['is_admin'] === 1;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Grant or revoke admin for a user. Only super admin (paul) can call this.
     */
    public function setUserAdmin(string $targetUsername, bool $grant): bool {
        try {
            $stmt = $this->db->prepare("
                UPDATE users SET is_admin = ? WHERE LOWER(username) = LOWER(?)
            ");
            return $stmt->execute([$grant ? 1 : 0, $targetUsername]);
        } catch (PDOException $e) {
            error_log('setUserAdmin failed: ' . $e->getMessage());
            return false;
        }
    }
}
