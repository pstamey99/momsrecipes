# FINAL FIX: instructions → directions

## 🔴 The Problem

The database on your server was created with the OLD schema that uses `instructions` field. Even though we updated the code to use `directions`, the database itself still has the old structure.

**Error:**
```
Missing required field: instructions
```

This happens because:
1. ✅ Local code uses 'directions' 
2. ❌ Server database expects 'instructions' (old schema)

---

## ✅ The Complete Fix

### Step 1: Update Local Files

**Download these 2 updated files:**
1. **database.php** (fixed line 297)
2. **recipe_pipeline.py** (fixed 404 handling)

Both files are in the downloads above.

---

### Step 2: Delete Old Database on Server

**Via cPanel File Manager:**

1. Log into cPanel: https://paulstamey.com:2083

2. Click **File Manager**

3. Navigate to: `/public_html/momsrecipes/api/data/`

4. Find file: **`recipes.db`**

5. **Right-click → Delete**

6. Confirm deletion

This removes the database with the old `instructions` field.

---

### Step 3: Deploy Updated Backend

```bash
cd /Users/pstamey/momrecipes

# Replace local files with updated versions
mv ~/Downloads/database.php api/
mv ~/Downloads/recipe_pipeline.py .

# Deploy to server
python3 deploy.py --backend
```

**This will:**
- Upload the corrected `database.php`
- Next API call will create fresh database with `directions` field

---

### Step 4: Verify Database Schema

```bash
# Test API - this creates the new database
curl https://paulstamey.com/momsrecipes/api/index.php

# Should return:
# {"status":"ok","timestamp":"...","database":"connected"}
```

The database is now recreated with the correct schema!

---

### Step 5: Upload Recipes

```bash
# Now upload all recipes
python3 recipe_pipeline.py --update-db
```

**Expected output:**
```
Processing recipes...
✓ Created: Recipe 1 (ID: 1)
✓ Created: Recipe 2 (ID: 2)
✓ Created: Recipe 3 (ID: 3)
...
✓ Successfully uploaded: 248 recipes
✗ Failed uploads: 0
```

---

## 🔍 Why This Keeps Happening

**Timeline:**
1. **January:** Created database with `instructions` field
2. **February 6:** Updated code to use `directions` field
3. **Problem:** Database on server still has old structure

**The fix:**
- Delete old `recipes.db` → Forces recreation with new schema
- Deploy updated `database.php` → Ensures schema uses `directions`
- Upload recipes → Works with correct field name

---

## ✅ Verification Checklist

After following all steps:

```bash
# 1. Check database exists
curl https://paulstamey.com/momsrecipes/api/index.php
# ✓ Should return: {"status":"ok","database":"connected"}

# 2. Create test recipe
curl -X POST https://paulstamey.com/momsrecipes/api/index.php/recipes \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Test Recipe",
    "ingredients": "Test ingredients",
    "directions": "Test directions",
    "category": "Test"
  }'

# ✓ Should return: {"success":true,"message":"Recipe created successfully"...}

# 3. Upload all recipes
python3 recipe_pipeline.py --update-db
# ✓ Should complete without "Missing required field: instructions" errors

# 4. Check recipe count
curl https://paulstamey.com/momsrecipes/api/index.php/recipes | \
  python3 -c "import json,sys; data=json.load(sys.stdin); print(f'Total: {len(data[\"recipes\"])}')"
# ✓ Should show: Total: 248 (or your recipe count)

# 5. Open website
open https://paulstamey.com/momsrecipes/momsrecipes/
# ✓ Should show all recipes
```

---

## 🎯 Quick Reference

| File | What Changed | Why |
|------|--------------|-----|
| **database.php** | Line 297: 'instructions' → 'directions' | Edit history tracking |
| **database.php** | Line 55: Already correct | Schema definition |
| **recipes.db** | DELETE THIS FILE | Old schema, needs recreation |
| **recipe_pipeline.py** | Lines 489-518: 404 handling | Allows first-time upload |

---

## 📋 Step-by-Step Summary

```bash
# 1. Delete old database
# Via cPanel: Delete /public_html/momsrecipes/api/data/recipes.db

# 2. Update local files
mv ~/Downloads/database.php api/
mv ~/Downloads/recipe_pipeline.py .

# 3. Deploy backend
python3 deploy.py --backend

# 4. Test API (creates new database)
curl https://paulstamey.com/momsrecipes/api/index.php

# 5. Upload recipes
python3 recipe_pipeline.py --update-db

# 6. Verify
open https://paulstamey.com/momsrecipes/api/test.html
# Click "Get All Recipes" - should show all your recipes!
```

---

## 💡 Why DELETE is Safe

**"But won't I lose my recipes?"**

No! Your recipes are in:
- ✅ `output/recipes_metadata.json` (local)
- ✅ `output/website/momsrecipes/recipes/*.html` (local & deployed)
- ✅ Word documents in `input/` (original source)

The database is just a **copy** for search functionality. You can always recreate it by running `--update-db`.

---

## 🚨 Critical Steps

**DO NOT SKIP:**
1. ✅ Delete `recipes.db` via cPanel first
2. ✅ Deploy updated `database.php`
3. ✅ Then run `--update-db`

**If you skip step 1**, the old database structure stays and you'll keep getting "Missing required field: instructions" errors.

---

## 🎉 After Fix

Once completed:
- ✅ Database uses 'directions' field
- ✅ All recipes uploaded successfully
- ✅ Website shows all recipes with search working
- ✅ No more "instructions" errors ever again

---

## 🆘 If Still Not Working

**If you still see "Missing required field: instructions" after following all steps:**

1. **Verify database was deleted:**
   - Check cPanel File Manager
   - Path: `/public_html/momsrecipes/api/data/`
   - Should NOT see `recipes.db` file

2. **Verify new database was created:**
   ```bash
   curl https://paulstamey.com/momsrecipes/api/index.php
   ```
   Should return OK

3. **Check what's actually deployed:**
   ```bash
   # Download the deployed database.php
   # Via cPanel: navigate to /public_html/momsrecipes/api/
   # Right-click database.php → View
   # Search for "directions TEXT NOT NULL"
   # Should be on line 55
   ```

4. **If database.php on server is still old:**
   ```bash
   # Force redeploy
   python3 deploy.py --backend
   
   # Verify it uploaded
   # Check file timestamp in cPanel
   ```

---

## Summary

**The problem:** Old database with `instructions` field  
**The solution:** Delete old database, deploy updated code, recreate database  
**The result:** Everything uses `directions` consistently  

**Follow the 5 steps above and you'll be done!** 🎉
