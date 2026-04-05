// Mom's Recipes - Search and Filter

const API_BASE = '/momsrecipes/api/index.php';

let allRecipes = [];
let filteredRecipes = [];
let currentSort = localStorage.getItem('momsrecipes_sort') || 'name_asc';
let myFavorites = new Set();      // set of recipe_uuid strings
let favoriteCounts = {};          // uuid -> count
let allReactions = {};            // uuid -> { emoji: count }
let showFavoritesOnly = false;

// DOM elements — declared here, assigned inside DOMContentLoaded
let searchInput, mealTypeFilter, cuisineFilter, ingredientFilter,
    methodFilter, clearButton, recipeGrid, recipeCount, fromSourceFilter;

// Load recipes on page load
document.addEventListener('DOMContentLoaded', async () => {
    // Apply display preferences immediately
    loadDisplayPreferences();
    // Assign DOM elements now that the DOM is ready
    searchInput      = document.getElementById('search');
    mealTypeFilter   = document.getElementById('meal-type');
    cuisineFilter    = document.getElementById('cuisine');
    ingredientFilter = document.getElementById('ingredient');
    methodFilter     = document.getElementById('method');
    clearButton      = document.getElementById('clear-filters');
    recipeGrid       = document.getElementById('recipe-grid');
    recipeCount      = document.getElementById('recipe-count');
    fromSourceFilter = document.getElementById('from-source');

    try {
        // Check session cache first — avoids a round-trip on back-navigation
        const cached = getCachedRecipes();
        const username = getCurrentUsername();

        // Fire recipes + social data in parallel; don't wait for social before rendering
        const recipesPromise = cached
            ? Promise.resolve(cached)
            : fetch(`${API_BASE}?action=get_recipes_search`)
                .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); });

        const socialPromise = username ? loadSocialData() : Promise.resolve();

        // Render as soon as recipes arrive — don't block on social data
        allRecipes = await recipesPromise;
        if (!cached) setCachedRecipes(allRecipes);
        filteredRecipes = [...allRecipes];

        populateFilters();
        displayRecipes();   // renders immediately with hearts in default state

        // Social data fills in hearts/reactions on existing cards — no re-render
        socialPromise.then(() => {
            patchSocialOnCards();
        }).catch(() => {});
        
        // Add event listeners
        searchInput.addEventListener('input', filterRecipes);
        mealTypeFilter.addEventListener('change', filterRecipes);
        cuisineFilter.addEventListener('change', filterRecipes);
        ingredientFilter.addEventListener('change', filterRecipes);
        methodFilter.addEventListener('change', filterRecipes);
        if (fromSourceFilter) fromSourceFilter.addEventListener('change', filterRecipes);
        clearButton.addEventListener('click', clearAllFilters);

        // Sort dropdown
        const sortSelect = document.getElementById('sort-select');
        if (sortSelect) {
            sortSelect.value = currentSort;
            sortSelect.addEventListener('change', () => {
                currentSort = sortSelect.value;
                localStorage.setItem('momsrecipes_sort', currentSort);
                displayRecipes();
            });
        }

        // Favorites filter button
        const favBtn = document.getElementById('favorites-filter');
        if (favBtn) {
            favBtn.addEventListener('click', () => {
                showFavoritesOnly = !showFavoritesOnly;
                favBtn.classList.toggle('active', showFavoritesOnly);
                favBtn.textContent = showFavoritesOnly ? '♥ My Favorites' : '♡ My Favorites';
                filterRecipes();
            });
        }
        
    } catch (error) {
        console.error('Error loading recipes:', error);
        
        let errorMsg = 'Error loading recipes: ' + error.message;
        
        recipeGrid.innerHTML = '<div style="color: white; background: rgba(255,0,0,0.2); padding: 30px; border-radius: 10px; border: 2px solid #ff6b6b;">' + errorMsg + '</div>';
    }
});

function getCurrentUsername() {
    try {
        const u = JSON.parse(localStorage.getItem('momsrecipes_current_user') || 'null');
        return u ? (u.username || '') : '';
    } catch(e) { return ''; }
}

function isPaul() {
    const u = getCurrentUsername().toLowerCase();
    return u === 'paul' || u === 'pstamey';
}

// ── Session cache helpers ────────────────────────────────────────────────────
const CACHE_KEY = 'momsrecipes_recipes_cache';
const CACHE_TTL = 5 * 60 * 1000; // 5 minutes

function getCachedRecipes() {
    try {
        const raw = sessionStorage.getItem(CACHE_KEY);
        if (!raw) return null;
        const { ts, data } = JSON.parse(raw);
        if (Date.now() - ts > CACHE_TTL) { sessionStorage.removeItem(CACHE_KEY); return null; }
        return data;
    } catch(e) { return null; }
}

function setCachedRecipes(data) {
    try { sessionStorage.setItem(CACHE_KEY, JSON.stringify({ ts: Date.now(), data })); } catch(e) {}
}

function invalidateRecipeCache() {
    sessionStorage.removeItem(CACHE_KEY);
}

async function deleteRecipe(recipeId, recipeTitle, cardElement, event) {
    event.preventDefault();
    event.stopPropagation();

    // Show confirmation modal
    const modal = document.getElementById('delete-confirm-modal');
    const titleEl = document.getElementById('delete-recipe-title');
    if (!modal) return;
    titleEl.textContent = recipeTitle;
    modal.classList.add('active');

    // Return a promise that resolves when user confirms or cancels
    return new Promise((resolve) => {
        const confirmBtn = document.getElementById('delete-confirm-btn');
        const cancelBtn  = document.getElementById('delete-cancel-btn');

        function cleanup() {
            modal.classList.remove('active');
            confirmBtn.replaceWith(confirmBtn.cloneNode(true));
            cancelBtn.replaceWith(cancelBtn.cloneNode(true));
        }

        document.getElementById('delete-confirm-btn').addEventListener('click', async () => {
            cleanup();
            try {
                const resp = await fetch(`${API_BASE}?action=delete_recipe`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: recipeId, username: getCurrentUsername() })
                });
                const data = await resp.json();
                if (resp.ok && data.success) {
                    // Remove from local arrays and re-render
                    invalidateRecipeCache();
                    allRecipes = allRecipes.filter(r => r.id !== recipeId);
                    filteredRecipes = filteredRecipes.filter(r => r.id !== recipeId);
                    if (cardElement) cardElement.remove();
                    const count = filteredRecipes.length;
                    recipeCount.textContent = `${count} recipe${count !== 1 ? 's' : ''}`;
                } else {
                    alert('Could not delete recipe: ' + (data.error || 'Unknown error'));
                }
            } catch(e) {
                console.error('deleteRecipe error:', e);
                alert('Error deleting recipe.');
            }
            resolve();
        });

        document.getElementById('delete-cancel-btn').addEventListener('click', () => {
            cleanup();
            resolve();
        });
    });
}

async function loadSocialData() {
    const username = getCurrentUsername();
    if (!username) return;
    try {
        const resp = await fetch(`${API_BASE}?action=get_all_reactions&username=${encodeURIComponent(username)}`);
        if (!resp.ok) return;
        const data = await resp.json();
        myFavorites   = new Set(data.favorites || []);
        favoriteCounts = data.favorite_counts || {};
        allReactions   = data.reactions || {};
    } catch(e) {
        console.warn('Could not load social data:', e);
    }
}

// Patch heart/reaction state onto already-rendered cards without re-rendering
function patchSocialOnCards() {
    document.querySelectorAll('.recipe-card').forEach(link => {
        const card = link.querySelector('div');
        if (!card) return;
        const uuid = link.dataset.uuid || '';
        if (!uuid) return;

        // Update heart button
        const heartBtn = card.querySelector('.fav-btn');
        if (heartBtn) {
            const isFav = myFavorites.has(uuid);
            heartBtn.classList.toggle('favorited', isFav);
            heartBtn.title = isFav ? 'Remove from favorites' : 'Add to favorites';
        }

        // Update or inject reaction bar
        const reactions = allReactions[uuid] || {};
        const hasReactions = Object.values(reactions).some(c => c > 0);
        let bar = card.querySelector('.reaction-bar');
        if (hasReactions) {
            if (!bar) {
                bar = document.createElement('div');
                bar.className = 'reaction-bar';
                card.appendChild(bar);
            }
            bar.innerHTML = '';
            ['❤️','😋','⭐','👍'].forEach(emoji => {
                const count = reactions[emoji] || 0;
                if (count > 0) {
                    const chip = document.createElement('span');
                    chip.className = 'reaction-chip';
                    chip.textContent = `${emoji} ${count}`;
                    bar.appendChild(chip);
                }
            });
        } else if (bar) {
            bar.remove();
        }
    });
}

async function toggleFavorite(uuid, btn, event) {
    event.preventDefault();
    event.stopPropagation();
    const username = getCurrentUsername();
    if (!username) return;
    try {
        const resp = await fetch(`${API_BASE}?action=toggle_favorite`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, recipe_uuid: uuid })
        });
        const data = await resp.json();
        if (data.favorited) {
            myFavorites.add(uuid);
            btn.classList.add('favorited');
            btn.title = 'Remove from favorites';
        } else {
            myFavorites.delete(uuid);
            btn.classList.remove('favorited');
            btn.title = 'Add to favorites';
        }
        // If favorites filter is active, re-render
        if (showFavoritesOnly) filterRecipes();
    } catch(e) {
        console.error('toggleFavorite error:', e);
    }
}


function getRecipeUrl(recipe) {
    // Use dynamic PHP template directly — no static HTML files needed
    if (recipe.uuid && recipe.uuid !== 'undefined' && recipe.uuid !== '') {
        return `/momsrecipes/api/recipe.php?id=${recipe.uuid}`;
    }
    // Fallback: title slug via recipe.php
    const slug = recipe.title
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
    return `/momsrecipes/api/recipe.php?id=${slug}`;
}

function populateFilters() {
    const mealTypes = new Set();
    const cuisines = new Set();
    const ingredients = new Set();
    const methods = new Set();
    const sources = new Set();
    
    allRecipes.forEach(recipe => {
        if (recipe.meal_type) mealTypes.add(recipe.meal_type);
        if (recipe.cuisine) cuisines.add(recipe.cuisine);
        if (recipe.main_ingredient) ingredients.add(recipe.main_ingredient);
        if (recipe.method) methods.add(recipe.method);
        if (recipe.family_source && recipe.family_source.trim()) sources.add(recipe.family_source.trim());
    });
    
    populateSelect(mealTypeFilter, Array.from(mealTypes).sort());
    populateSelect(cuisineFilter, Array.from(cuisines).sort());
    populateSelect(ingredientFilter, Array.from(ingredients).sort());
    populateSelect(methodFilter, Array.from(methods).sort());
    if (fromSourceFilter) populateSelect(fromSourceFilter, Array.from(sources).sort());
}

// Listen for custom meta options being added on recipe pages
window.addEventListener('customMetaOptionAdded', function(e) {
    console.log('New custom option added:', e.detail);
    [mealTypeFilter, cuisineFilter, ingredientFilter, methodFilter, fromSourceFilter].forEach(filter => {
        if (!filter) return;
        while (filter.options.length > 1) {
            filter.remove(1);
        }
    });
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
    const fromSource = fromSourceFilter ? fromSourceFilter.value : '';
    
    filteredRecipes = allRecipes.filter(recipe => {
        const currentTitle = recipe.title;
        
        const matchesSearch = !searchTerm || 
            currentTitle.toLowerCase().includes(searchTerm) ||
            (recipe.family_source && recipe.family_source.toLowerCase().includes(searchTerm));
        
        const matchesMealType = !mealType || recipe.meal_type === mealType;
        const matchesCuisine = !cuisine || recipe.cuisine === cuisine;
        const matchesIngredient = !ingredient || recipe.main_ingredient === ingredient;
        const matchesMethod = !method || recipe.method === method;
        const matchesSource = !fromSource || (recipe.family_source && recipe.family_source.trim() === fromSource);
        
        return matchesSearch && matchesMealType && matchesCuisine && 
               matchesIngredient && matchesMethod && matchesSource &&
               (!showFavoritesOnly || myFavorites.has(recipe.uuid));
    });
    
    displayRecipes();
}

function sortRecipes(recipes) {
    const sorted = [...recipes];
    switch (currentSort) {
        case 'name_asc':
            sorted.sort((a, b) => (a.title || '').localeCompare(b.title || ''));
            break;
        case 'name_desc':
            sorted.sort((a, b) => (b.title || '').localeCompare(a.title || ''));
            break;
        case 'updated':
            sorted.sort((a, b) => {
                const da = new Date((a.updated_at || '').replace(' ', 'T') + 'Z');
                const db = new Date((b.updated_at || '').replace(' ', 'T') + 'Z');
                return db - da;
            });
            break;
        case 'newest':
            sorted.sort((a, b) => {
                const da = new Date((a.created_at || '').replace(' ', 'T') + 'Z');
                const db = new Date((b.created_at || '').replace(' ', 'T') + 'Z');
                return db - da;
            });
            break;
        case 'reactions':
            sorted.sort((a, b) => {
                const ra = allReactions[a.uuid] ? Object.values(allReactions[a.uuid]).reduce((s, n) => s + n, 0) : 0;
                const rb = allReactions[b.uuid] ? Object.values(allReactions[b.uuid]).reduce((s, n) => s + n, 0) : 0;
                return rb - ra;
            });
            break;
    }
    return sorted;
}

function displayRecipes() {
    recipeGrid.innerHTML = '';

    if (filteredRecipes.length === 0) {
        recipeGrid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 40px; background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);"><p style="color: #666; margin: 0; font-size: 1.1em;">No recipes found. Try adjusting your filters.</p></div>';
        recipeCount.textContent = '0 recipes';
        return;
    }

    const sorted = sortRecipes(filteredRecipes);
    const count = sorted.length;
    recipeCount.textContent = `${count} recipe${count !== 1 ? 's' : ''}`;

    // Render first 40 cards synchronously so the page feels instant
    const FIRST_BATCH = 40;
    const firstBatch = sorted.slice(0, FIRST_BATCH);
    const fragment = document.createDocumentFragment();
    firstBatch.forEach(recipe => fragment.appendChild(createRecipeCard(recipe)));
    recipeGrid.appendChild(fragment);

    // Render the rest during idle time so the browser stays responsive
    if (sorted.length > FIRST_BATCH) {
        const remaining = sorted.slice(FIRST_BATCH);
        const schedule = window.requestIdleCallback || (cb => setTimeout(cb, 50));
        schedule(() => {
            const frag2 = document.createDocumentFragment();
            remaining.forEach(recipe => frag2.appendChild(createRecipeCard(recipe)));
            recipeGrid.appendChild(frag2);
        });
    }
}

function createRecipeCard(recipe) {
    const link = document.createElement('a');
    link.href = getRecipeUrl(recipe);
    link.className = 'recipe-card';
    link.dataset.uuid = recipe.uuid || '';
    
    if (recipe.meal_type) {
        const mealSlug = recipe.meal_type.toLowerCase().replace(/\s+/g, '-');
        link.setAttribute('data-meal-' + mealSlug, '');
    }
    
    const card = document.createElement('div');
    card.style.position = 'relative';

    // ── Heart / favorite button ─────────────────────────────────────────
    const uuid = recipe.uuid || '';
    const isFav = myFavorites.has(uuid);
    const heartBtn = document.createElement('button');
    heartBtn.className = 'fav-btn' + (isFav ? ' favorited' : '');
    heartBtn.innerHTML = '♥';
    heartBtn.title = isFav ? 'Remove from favorites' : 'Add to favorites';
    heartBtn.addEventListener('click', (e) => toggleFavorite(uuid, heartBtn, e));
    card.appendChild(heartBtn);

    // ── Delete button (paul only) ───────────────────────────────────────
    if (isPaul()) {
        const delBtn = document.createElement('button');
        delBtn.className = 'delete-recipe-btn';
        delBtn.innerHTML = '🗑';
        delBtn.title = 'Delete this recipe';
        delBtn.addEventListener('click', (e) => deleteRecipe(recipe.id, recipe.title, link, e));
        card.appendChild(delBtn);
    }

    // ── Title ───────────────────────────────────────────────────────────
    const title = document.createElement('h3');
    title.className = 'recipe-card-title';
    title.textContent = recipe.title;
    
    const meta = document.createElement('div');
    meta.className = 'recipe-card-meta';
    
    if (recipe.meal_type) meta.appendChild(createTag(recipe.meal_type, 'meal-type'));
    if (recipe.cuisine) meta.appendChild(createTag(recipe.cuisine, 'cuisine'));
    if (recipe.main_ingredient) meta.appendChild(createTag(recipe.main_ingredient, 'ingredient'));
    if (recipe.method) meta.appendChild(createTag(recipe.method, 'method'));
    
    if (!recipe.meal_type && !recipe.cuisine && !recipe.main_ingredient && !recipe.method && recipe.category) {
        meta.appendChild(createTag(recipe.category, 'category'));
    }
    
    card.appendChild(title);

    // ── From (family source) ────────────────────────────────────────────
    if (recipe.family_source && recipe.family_source.trim()) {
        const from = document.createElement('div');
        from.className = 'recipe-card-source';
        from.textContent = 'From: ' + recipe.family_source.trim();
        card.appendChild(from);
    }

    card.appendChild(meta);

    // ── Reaction bar ────────────────────────────────────────────────────
    const reactions = allReactions[uuid] || {};
    const hasReactions = Object.values(reactions).some(c => c > 0);
    if (hasReactions) {
        const reactionBar = document.createElement('div');
        reactionBar.className = 'reaction-bar';
        ['❤️','😋','⭐','👍'].forEach(emoji => {
            const count = reactions[emoji] || 0;
            if (count > 0) {
                const chip = document.createElement('span');
                chip.className = 'reaction-chip';
                chip.textContent = `${emoji} ${count}`;
                reactionBar.appendChild(chip);
            }
        });
        card.appendChild(reactionBar);
    }

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
    if (fromSourceFilter) fromSourceFilter.value = '';
    filterRecipes();
}

// ── Notification Preferences ─────────────────────────────────────────────────

function notifToast(msg, type) {
    if (typeof showToast === 'function') {
        showToast(msg, type);
    } else {
        console.log('[notif]', msg);
    }
}

async function loadNotificationPreferences(username) {
    if (!username) return;
    try {
        const res  = await fetch(`${API_BASE}?action=get_preferences&username=${encodeURIComponent(username)}`);
        const data = await res.json();
        if (!data.success) return;
        const p = data.preferences;
        const emailEl = document.getElementById('pref-email');
        if (emailEl) emailEl.value = p.email || '';
        ['notify_new_recipe', 'notify_reactions', 'notify_edits', 'notify_weekly', 'notifications_enabled'].forEach(f => {
            const el = document.getElementById('pref-' + f.replace(/_/g, '-'));
            if (el) el.checked = !!parseInt(p[f]);
        });
        updateNotifTogglesVisibility();
    } catch (err) {
        console.error('Failed to load notification preferences:', err);
    }
}

function applyDisplayPreferences() {
    const hide = document.getElementById('pref-hide-title-changes')?.checked
        ?? (localStorage.getItem('momsrecipes_hide_title_changes') === '1');
    document.body.classList.toggle('hide-title-changes', !!hide);
}

function loadDisplayPreferences() {
    const hide = localStorage.getItem('momsrecipes_hide_title_changes') === '1';
    const el = document.getElementById('pref-hide-title-changes');
    if (el) el.checked = hide;
    applyDisplayPreferences();
}

async function saveNotificationPreferences(username) {
    if (!username) return;
    const email   = document.getElementById('pref-email')?.value?.trim() || '';
    const enabled = document.getElementById('pref-notifications-enabled')?.checked ?? true;
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        notifToast('Please enter a valid email address', 'error');
        return;
    }
    const payload = {
        username,
        email,
        notifications_enabled: enabled ? 1 : 0,
        notify_new_recipe:  document.getElementById('pref-notify-new-recipe')?.checked  ? 1 : 0,
        notify_reactions:   document.getElementById('pref-notify-reactions')?.checked   ? 1 : 0,
        notify_edits:       document.getElementById('pref-notify-edits')?.checked       ? 1 : 0,
        notify_weekly:      document.getElementById('pref-notify-weekly')?.checked      ? 1 : 0,
    };
    try {
        const res  = await fetch(`${API_BASE}?action=save_preferences`, {
            method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            // Save display preferences to localStorage
            const hideChanges = document.getElementById('pref-hide-title-changes')?.checked ? '1' : '0';
            localStorage.setItem('momsrecipes_hide_title_changes', hideChanges);
            applyDisplayPreferences();
            notifToast('Preferences saved ✓', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            notifToast(data.error || 'Failed to save', 'error');
        }
    } catch (err) {
        notifToast('Network error saving preferences', 'error');
    }
}

async function sendTestEmail(username) {
    const btn = document.getElementById('btn-send-test-email');
    if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }
    try {
        const res  = await fetch(`${API_BASE}?action=send_test_email`, {
            method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ username })
        });
        const data = await res.json();
        notifToast(data.success ? `Test email sent to ${data.sent_to} ✓` : (data.error || 'Failed'), data.success ? 'success' : 'error');
    } catch (err) {
        notifToast('Network error', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Send test email'; }
    }
}

function updateNotifTogglesVisibility() {
    const enabled = document.getElementById('pref-notifications-enabled')?.checked;
    const group   = document.getElementById('notif-individual-toggles');
    if (!group) return;
    group.style.opacity    = enabled ? '1' : '0.4';
    group.style.pointerEvents = enabled ? '' : 'none';
}
