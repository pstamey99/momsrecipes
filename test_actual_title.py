#!/usr/bin/env python3
"""
Test with your actual recipe title format
"""

import sys
sys.path.insert(0, '.')

from recipe_converter_namecheap import RecipeExtractor

def test_actual_title():
    """Test with the actual title from screenshot"""
    
    print("\n" + "="*70)
    print("TESTING ACTUAL RECIPE TITLE")
    print("="*70 + "\n")
    
    extractor = RecipeExtractor("dummy.docx")
    
    # Test with the exact title from your screenshot
    title = "Lefse - 2 Bake"
    
    recipe = extractor._create_new_recipe(title)
    
    print(f"Title: '{title}'")
    print(f"Title lowercase: '{title.lower()}'")
    print()
    print(f"Meal Type: '{recipe['meal_type']}'")
    print(f"Cuisine: '{recipe['cuisine']}'")
    print(f"Main Ingredient: '{recipe['main_ingredient']}'")
    print(f"Method: '{recipe['method']}'")
    print()
    
    # Check what should have matched
    title_lower = title.lower()
    
    print("Expected matches:")
    if 'lefse' in title_lower:
        print("  ✓ 'lefse' found → should detect Cuisine: Norwegian, Meal Type: Bread")
    if 'bake' in title_lower:
        print("  ✓ 'bake' found → should detect Method: Baked")
    
    print()
    
    if recipe['meal_type'] or recipe['cuisine'] or recipe['method']:
        print("✅ SUCCESS: Metadata detected!")
    else:
        print("❌ PROBLEM: No metadata detected!")
        print("   This suggests an issue with the detection logic.")
    
    print()

if __name__ == '__main__':
    test_actual_title()
