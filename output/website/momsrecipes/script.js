// Mom's Recipes - Search and Filter

let allRecipes = [];
let filteredRecipes = [];

// DOM elements
const searchInput = document.getElementById('search');
const mealTypeFilter = document.getElementById('meal-type');
const cuisineFilter = document.getElementById('cuisine');
const ingredientFilter = document.getElementById('ingredient');
const methodFilter = document.getElementById('method');
const clearButton = document.getElementById('clear-filters');
const recipeGrid = document.getElementById('recipe-grid');
const recipeCount = document.getElementById('recipe-count');

// Load recipes on page load
document.addEventListener('DOMContentLoaded', async () => {
    try {
        console.log('Loading recipes from recipes.json...');
        const response = await fetch('recipes.json');
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        allRecipes = await response.json();
        filteredRecipes = [...allRecipes];
        
        console.log(`Loaded ${allRecipes.length} recipes`);
        
        populateFilters();
        
        // Restore filter state from URL params (preserves state on back button)
        restoreFilterState();
        
        // Add event listeners
        searchInput.addEventListener('input', filterRecipes);
        mealTypeFilter.addEventListener('change', filterRecipes);
        cuisineFilter.addEventListener('change', filterRecipes);
        ingredientFilter.addEventListener('change', filterRecipes);
        methodFilter.addEventListener('change', filterRecipes);
        clearButton.addEventListener('click', clearAllFilters);
        
    } catch (error) {
        console.error('Error loading recipes:', error);
        
        let errorMsg = 'Error loading recipes: ' + error.message;
        
        // Check if it's a CORS/file protocol error
        if (error.message.includes('Failed to fetch') || error.message.includes('CORS')) {
            errorMsg += '<br><br><strong>Solution:</strong> You cannot open the HTML file directly (file:// protocol).<br>' +
                       'Please use a local web server:<br><br>' +
                       '1. Open Terminal/Command Prompt<br>' +
                       '2. Navigate to: recipe_cards/website/momsrecipes<br>' +
                       '3. Run: python -m http.server 8000<br>' +
                       '4. Open browser to: http://localhost:8000/';
        } else if (error.message.includes('404')) {
            errorMsg += '<br><br><strong>Problem:</strong> recipes.json file not found.<br>' +
                       'Make sure recipes.json exists in the momsrecipes folder.';
        }
        
        recipeGrid.innerHTML = '<div style="color: white; background: rgba(255,0,0,0.2); padding: 30px; border-radius: 10px; border: 2px solid #ff6b6b;">' + errorMsg + '</div>';
    }
});

// Save filter state to URL query params (so back button preserves filters)
function saveFilterState() {
    const params = new URLSearchParams();
    if (searchInput.value) params.set('q', searchInput.value);
    if (mealTypeFilter.value) params.set('meal', mealTypeFilter.value);
    if (cuisineFilter.value) params.set('cuisine', cuisineFilter.value);
    if (ingredientFilter.value) params.set('ing', ingredientFilter.value);
    if (methodFilter.value) params.set('method', methodFilter.value);
    
    const newUrl = params.toString() 
        ? window.location.pathname + '?' + params.toString()
        : window.location.pathname;
    
    // replaceState so we don't create extra history entries for each keystroke
    history.replaceState(null, '', newUrl);
}

// Restore filter state from URL query params
function restoreFilterState() {
    const params = new URLSearchParams(window.location.search);
    
    if (params.has('q')) searchInput.value = params.get('q');
    if (params.has('meal')) mealTypeFilter.value = params.get('meal');
    if (params.has('cuisine')) cuisineFilter.value = params.get('cuisine');
    if (params.has('ing')) ingredientFilter.value = params.get('ing');
    if (params.has('method')) methodFilter.value = params.get('method');
    
    // Apply filters (don't save state again — we just loaded it)
    filterRecipesNoSave();
}

// Filter without saving state (used on restore to avoid circular save)
function filterRecipesNoSave() {
    const searchTerm = searchInput.value.toLowerCase();
    const mealType = mealTypeFilter.value;
    const cuisine = cuisineFilter.value;
    const ingredient = ingredientFilter.value;
    const method = methodFilter.value;
    
    filteredRecipes = allRecipes.filter(recipe => {
        let currentTitle = recipe.title;
        const recipeId = recipe.uuid || recipe.id;
        if (recipeId) {
            try {
                let savedRecipe = localStorage.getItem('momsrecipes_recipe_' + recipeId);
                if (!savedRecipe) {
                    savedRecipe = localStorage.getItem('recipe_' + recipeId);
                }
                if (savedRecipe) {
                    const parsed = JSON.parse(savedRecipe);
                    if (parsed.title) {
                        currentTitle = parsed.title;
                    }
                }
            } catch (e) {}
        }
        
        const matchesSearch = !searchTerm || 
            currentTitle.toLowerCase().includes(searchTerm) ||
            (recipe.family_source && recipe.family_source.toLowerCase().includes(searchTerm));
        
        const matchesMealType = !mealType || recipe.meal_type === mealType;
        const matchesCuisine = !cuisine || recipe.cuisine === cuisine;
        const matchesIngredient = !ingredient || recipe.main_ingredient === ingredient;
        const matchesMethod = !method || recipe.method === method;
        
        return matchesSearch && matchesMealType && matchesCuisine && 
               matchesIngredient && matchesMethod;
    });
    
    displayRecipes();
}

function populateFilters() {
    const mealTypes = new Set();
    const cuisines = new Set();
    const ingredients = new Set();
    const methods = new Set();
    
    // Get custom options from localStorage
    const customOptions = JSON.parse(localStorage.getItem('momsrecipes_custom_meta_options') || '{}');
    
    // Add custom options to sets
    if (customOptions.meal_type) {
        customOptions.meal_type.forEach(opt => mealTypes.add(opt));
    }
    if (customOptions.cuisine) {
        customOptions.cuisine.forEach(opt => cuisines.add(opt));
    }
    if (customOptions.main_ingredient) {
        customOptions.main_ingredient.forEach(opt => ingredients.add(opt));
    }
    if (customOptions.method) {
        customOptions.method.forEach(opt => methods.add(opt));
    }
    
    // Add recipe values
    allRecipes.forEach(recipe => {
        if (recipe.meal_type) mealTypes.add(recipe.meal_type);
        if (recipe.cuisine) cuisines.add(recipe.cuisine);
        if (recipe.main_ingredient) ingredients.add(recipe.main_ingredient);
        if (recipe.method) methods.add(recipe.method);
    });
    
    populateSelect(mealTypeFilter, Array.from(mealTypes).sort());
    populateSelect(cuisineFilter, Array.from(cuisines).sort());
    populateSelect(ingredientFilter, Array.from(ingredients).sort());
    populateSelect(methodFilter, Array.from(methods).sort());
}

// Listen for custom meta options being added on recipe pages
window.addEventListener('customMetaOptionAdded', function(e) {
    console.log('New custom option added:', e.detail);
    // Refresh filters to include the new option
    // Clear existing options (except "All X" default)
    [mealTypeFilter, cuisineFilter, ingredientFilter, methodFilter].forEach(filter => {
        while (filter.options.length > 1) {
            filter.remove(1);
        }
    });
    // Repopulate with new custom options
    populateFilters();
});


function populateSelect(selectElement, options) {
    options.forEach(option => {
        const optionElement = document.createElement('option');
        optionElement.value = option;
        optionElement.textContent = option;
        selectElement.appendChild(optionElement);
    });
}

function filterRecipes() {
    const searchTerm = searchInput.value.toLowerCase();
    const mealType = mealTypeFilter.value;
    const cuisine = cuisineFilter.value;
    const ingredient = ingredientFilter.value;
    const method = methodFilter.value;
    
    filteredRecipes = allRecipes.filter(recipe => {
        // Get current title from localStorage if it exists
        let currentTitle = recipe.title;
        const recipeId = recipe.uuid || recipe.id;
        if (recipeId) {
            try {
                // Try new UUID-based key first
                let savedRecipe = localStorage.getItem('momsrecipes_recipe_' + recipeId);
                // Fall back to old key
                if (!savedRecipe) {
                    savedRecipe = localStorage.getItem('recipe_' + recipeId);
                }
                if (savedRecipe) {
                    const parsed = JSON.parse(savedRecipe);
                    if (parsed.title) {
                        currentTitle = parsed.title;
                    }
                }
            } catch (e) {
                // If error, use original title
            }
        }
        
        const matchesSearch = !searchTerm || 
            currentTitle.toLowerCase().includes(searchTerm) ||
            (recipe.family_source && recipe.family_source.toLowerCase().includes(searchTerm));
        
        const matchesMealType = !mealType || recipe.meal_type === mealType;
        const matchesCuisine = !cuisine || recipe.cuisine === cuisine;
        const matchesIngredient = !ingredient || recipe.main_ingredient === ingredient;
        const matchesMethod = !method || recipe.method === method;
        
        return matchesSearch && matchesMealType && matchesCuisine && 
               matchesIngredient && matchesMethod;
    });
    
    // Save filter state to URL so back button preserves it
    saveFilterState();
    displayRecipes();
}

function displayRecipes() {
    recipeGrid.innerHTML = '';
    
    if (filteredRecipes.length === 0) {
        recipeGrid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 40px; background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);"><p style="color: #666; margin: 0; font-size: 1.1em;">No recipes found. Try adjusting your filters.</p></div>';
        recipeCount.textContent = '0 recipes';
        return;
    }
    
    filteredRecipes.forEach(recipe => {
        const card = createRecipeCard(recipe);
        recipeGrid.appendChild(card);
    });
    
    const count = filteredRecipes.length;
    recipeCount.textContent = `${count} recipe${count !== 1 ? 's' : ''}`;
}

function createRecipeCard(recipe) {
    const link = document.createElement('a');
    link.href = recipe.url;
    link.className = 'recipe-card';
    
    // Add data attribute for color-coded background
    if (recipe.meal_type) {
        const mealSlug = recipe.meal_type.toLowerCase().replace(/\s+/g, '-');
        link.setAttribute('data-meal-' + mealSlug, '');
    }
    
    const card = document.createElement('div');
    
    // Check localStorage for updated title (uses UUID if available)
    let displayTitle = recipe.title;
    const recipeId = recipe.uuid || recipe.id;
    if (recipeId) {
        try {
            // Try new UUID-based key first
            let savedRecipe = localStorage.getItem('momsrecipes_recipe_' + recipeId);
            // Fall back to old key
            if (!savedRecipe) {
                savedRecipe = localStorage.getItem('recipe_' + recipeId);
            }
            if (savedRecipe) {
                const parsed = JSON.parse(savedRecipe);
                if (parsed.title) {
                    displayTitle = parsed.title;
                }
            }
        } catch (e) {
            // If error, use original title
            console.log('Could not load saved recipe:', e);
        }
    }
    
    const title = document.createElement('h3');
    title.className = 'recipe-card-title';
    title.textContent = displayTitle;
    
    const meta = document.createElement('div');
    meta.className = 'recipe-card-meta';
    
    if (recipe.meal_type) {
        meta.appendChild(createTag(recipe.meal_type, 'meal-type'));
    }
    if (recipe.cuisine) {
        meta.appendChild(createTag(recipe.cuisine, 'cuisine'));
    }
    if (recipe.main_ingredient) {
        meta.appendChild(createTag(recipe.main_ingredient, 'ingredient'));
    }
    if (recipe.method) {
        meta.appendChild(createTag(recipe.method, 'method'));
    }
    
    card.appendChild(title);
    card.appendChild(meta);
    link.appendChild(card);
    
    return link;
}

function createTag(text, extraClass = '') {
    const tag = document.createElement('span');
    tag.className = `meta-tag ${extraClass}`;
    tag.textContent = text;
    return tag;
}

function clearAllFilters() {
    searchInput.value = '';
    mealTypeFilter.value = '';
    cuisineFilter.value = '';
    ingredientFilter.value = '';
    methodFilter.value = '';
    // Clear URL params
    history.replaceState(null, '', window.location.pathname);
    filterRecipesNoSave();
}