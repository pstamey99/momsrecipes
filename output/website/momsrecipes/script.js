// Mom's Recipes - Search and Filter

let allRecipes = [];
let filteredRecipes = [];

// DOM elements
const searchInput = document.getElementById('search');
const mealTypeFilter = document.getElementById('meal-type');
const cuisineFilter = document.getElementById('cuisine');
const ingredientFilter = document.getElementById('ingredient');
const methodFilter = document.getElementById('method');
const fromSourceFilter = document.getElementById('from-source');
const clearButton = document.getElementById('clear-filters');
const recipeGrid = document.getElementById('recipe-grid');
const recipeCount = document.getElementById('recipe-count');

// Load recipes on page load
document.addEventListener('DOMContentLoaded', async () => {
    try {
        console.log('Loading recipes from API...');
        const response = await fetch('api/index.php?action=get_recipes_search');
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        allRecipes = await response.json();
        filteredRecipes = [...allRecipes];
        
        console.log(`Loaded ${allRecipes.length} recipes`);
        
        populateFilters();
        displayRecipes();
        
        // Add event listeners
        searchInput.addEventListener('input', filterRecipes);
        mealTypeFilter.addEventListener('change', filterRecipes);
        cuisineFilter.addEventListener('change', filterRecipes);
        ingredientFilter.addEventListener('change', filterRecipes);
        methodFilter.addEventListener('change', filterRecipes);
        fromSourceFilter.addEventListener('change', filterRecipes);
        clearButton.addEventListener('click', clearAllFilters);
        
    } catch (error) {
        console.warn('API unavailable, trying static recipes.json fallback...', error.message);
        try {
            const fallback = await fetch('recipes.json');
            if (!fallback.ok) throw new Error('recipes.json not found');
            allRecipes = await fallback.json();
            filteredRecipes = [...allRecipes];
            console.log(`Loaded ${allRecipes.length} recipes from static fallback`);
            populateFilters();
            displayRecipes();
            searchInput.addEventListener('input', filterRecipes);
            mealTypeFilter.addEventListener('change', filterRecipes);
            cuisineFilter.addEventListener('change', filterRecipes);
            ingredientFilter.addEventListener('change', filterRecipes);
            methodFilter.addEventListener('change', filterRecipes);
            fromSourceFilter.addEventListener('change', filterRecipes);
            clearButton.addEventListener('click', clearAllFilters);
        } catch (fallbackError) {
            console.error('Fallback also failed:', fallbackError);
            recipeGrid.innerHTML = '<div style="color: white; background: rgba(255,0,0,0.2); padding: 30px; border-radius: 10px; border: 2px solid #ff6b6b;">Error loading recipes. Run the pipeline to generate recipes.json.</div>';
        }
    }
});

function populateFilters() {
    const mealTypes = new Set();
    const cuisines = new Set();
    const ingredients = new Set();
    const methods = new Set();
    const sources = new Set();
    
    // Custom options already merged into allRecipes from API — no localStorage needed
    
    // Add recipe values
    allRecipes.forEach(recipe => {
        if (recipe.meal_type) mealTypes.add(recipe.meal_type);
        if (recipe.cuisine) cuisines.add(recipe.cuisine);
        if (recipe.main_ingredient) ingredients.add(recipe.main_ingredient);
        if (recipe.method) methods.add(recipe.method);
        if (recipe.family_source) sources.add(recipe.family_source);
    });
    
    populateSelect(mealTypeFilter, Array.from(mealTypes).sort());
    populateSelect(cuisineFilter, Array.from(cuisines).sort());
    populateSelect(ingredientFilter, Array.from(ingredients).sort());
    populateSelect(methodFilter, Array.from(methods).sort());
    populateSelect(fromSourceFilter, Array.from(sources).sort());
}

// Listen for custom meta options being added on recipe pages
window.addEventListener('customMetaOptionAdded', function(e) {
    console.log('New custom option added:', e.detail);
    // Refresh filters to include the new option
    // Clear existing options (except "All X" default)
    [mealTypeFilter, cuisineFilter, ingredientFilter, methodFilter, fromSourceFilter].forEach(filter => {
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
    const fromSource = fromSourceFilter.value;
    
    filteredRecipes = allRecipes.filter(recipe => {
        const currentTitle = recipe.title;
        
        const matchesSearch = !searchTerm || 
            currentTitle.toLowerCase().includes(searchTerm) ||
            (recipe.family_source && recipe.family_source.toLowerCase().includes(searchTerm));
        
        const matchesMealType = !mealType || recipe.meal_type === mealType;
        const matchesCuisine = !cuisine || recipe.cuisine === cuisine;
        const matchesIngredient = !ingredient || recipe.main_ingredient === ingredient;
        const matchesMethod = !method || recipe.method === method;
        const matchesSource = !fromSource || recipe.family_source === fromSource;
        
        return matchesSearch && matchesMealType && matchesCuisine && 
               matchesIngredient && matchesMethod && matchesSource;
    });
    
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
    
    if (recipe.family_source) {
        const fromEl = document.createElement('div');
        fromEl.className = 'recipe-card-from';
        fromEl.textContent = 'From: ' + recipe.family_source;
        card.appendChild(title);
        card.appendChild(fromEl);
    } else {
        card.appendChild(title);
    }
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
    fromSourceFilter.value = '';
    filterRecipes();
}