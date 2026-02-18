#!/usr/bin/env python3
"""
Image Flow Diagnostic
Traces where images go during processing
"""

import os
from pathlib import Path

def check_image_directories():
    """Check all image-related directories"""
    
    print("\n" + "="*70)
    print("IMAGE DIRECTORY DIAGNOSTIC")
    print("="*70 + "\n")
    
    # Check temp_images
    temp_dir = Path('temp_images')
    print(f"1. temp_images/ directory:")
    if temp_dir.exists():
        images = list(temp_dir.glob('*.*'))
        print(f"   ✓ Exists")
        print(f"   Files: {len(images)}")
        if images:
            for img in images[:5]:  # Show first 5
                print(f"     - {img.name} ({img.stat().st_size // 1024} KB)")
            if len(images) > 5:
                print(f"     ... and {len(images) - 5} more")
    else:
        print(f"   ✗ Does not exist")
    
    print()
    
    # Check output/website/momsrecipes/images
    output_dir = Path('output/website/momsrecipes/images')
    print(f"2. output/website/momsrecipes/images/ directory:")
    if output_dir.exists():
        images = list(output_dir.glob('*.*'))
        print(f"   ✓ Exists")
        print(f"   Files: {len(images)}")
        if images:
            for img in images[:5]:
                print(f"     - {img.name} ({img.stat().st_size // 1024} KB)")
            if len(images) > 5:
                print(f"     ... and {len(images) - 5} more")
    else:
        print(f"   ✗ Does not exist")
    
    print()
    
    # Check recipes_metadata.json for image references
    metadata_file = Path('output/recipes_metadata.json')
    print(f"3. Recipes with images in metadata:")
    if metadata_file.exists():
        import json
        with open(metadata_file) as f:
            recipes = json.load(f)
        
        with_images = [r for r in recipes if r.get('images')]
        print(f"   Total recipes: {len(recipes)}")
        print(f"   With images: {len(with_images)}")
        
        if with_images:
            print(f"\n   Examples:")
            for recipe in with_images[:3]:
                print(f"     - {recipe['title']}: {len(recipe['images'])} image(s)")
                for img in recipe['images']:
                    print(f"       → {img}")
    else:
        print(f"   ✗ recipes_metadata.json not found")
    
    print("\n" + "="*70)
    print("DIAGNOSIS")
    print("="*70)
    
    if temp_dir.exists() and list(temp_dir.glob('*.*')):
        if output_dir.exists() and list(output_dir.glob('*.*')):
            print("✅ Images extracted AND copied to output")
            print("   → Images should appear on website")
        else:
            print("⚠️  Images extracted to temp_images/ but NOT copied to output")
            print("   → Solution: Re-run recipe_pipeline.py --process")
    else:
        if output_dir.exists() and list(output_dir.glob('*.*')):
            print("✅ Images in output (temp_images cleaned up)")
            print("   → This is normal after successful processing")
        else:
            print("❌ No images found anywhere")
            print("   → Images not being extracted from Word documents")
            print("   → Run: python3 test_image_extraction.py input/*.docx")
    
    print()

if __name__ == '__main__':
    check_image_directories()
