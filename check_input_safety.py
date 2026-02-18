#!/usr/bin/env python3
"""
Verify that input files are NEVER modified during processing
"""

import os
import hashlib
from pathlib import Path
from datetime import datetime

def get_file_hash(filepath):
    """Calculate MD5 hash of a file"""
    hash_md5 = hashlib.md5()
    with open(filepath, "rb") as f:
        for chunk in iter(lambda: f.read(4096), b""):
            hash_md5.update(chunk)
    return hash_md5.hexdigest()

def get_file_info(filepath):
    """Get file modification time and size"""
    stat = os.stat(filepath)
    return {
        'size': stat.st_size,
        'modified': datetime.fromtimestamp(stat.st_mtime).isoformat(),
        'hash': get_file_hash(filepath)
    }

def check_input_files():
    """Check all input files and save their state"""
    
    input_dir = Path('input')
    if not input_dir.exists():
        print("❌ Input directory not found!")
        return
    
    # Find all Word documents
    docx_files = list(input_dir.rglob('*.docx'))
    doc_files = list(input_dir.rglob('*.doc'))
    
    all_files = [f for f in docx_files + doc_files if not f.name.startswith('~')]
    
    if not all_files:
        print("❌ No Word documents found in input/")
        return
    
    print("\n" + "="*70)
    print("INPUT FILE SAFETY CHECK")
    print("="*70 + "\n")
    
    print(f"Found {len(all_files)} Word documents in input/\n")
    
    # Save current state
    state_file = Path('input_files_state.txt')
    
    if state_file.exists():
        # Compare with previous state
        print("📋 Comparing with previous state...\n")
        
        with open(state_file, 'r') as f:
            old_state = {}
            for line in f:
                if '|' in line:
                    parts = line.strip().split('|')
                    if len(parts) == 4:
                        old_state[parts[0]] = {
                            'size': int(parts[1]),
                            'modified': parts[2],
                            'hash': parts[3]
                        }
        
        changed = []
        unchanged = 0
        new_files = []
        
        for filepath in all_files:
            rel_path = str(filepath.relative_to(input_dir))
            current_info = get_file_info(filepath)
            
            if rel_path in old_state:
                old_info = old_state[rel_path]
                if current_info['hash'] != old_info['hash']:
                    changed.append({
                        'file': filepath.name,
                        'old_modified': old_info['modified'],
                        'new_modified': current_info['modified']
                    })
                else:
                    unchanged += 1
            else:
                new_files.append(filepath.name)
        
        # Report
        if changed:
            print("⚠️  CHANGED FILES (Modified since last check):")
            for item in changed:
                print(f"   - {item['file']}")
                print(f"     Old: {item['old_modified']}")
                print(f"     New: {item['new_modified']}")
            print()
        
        if new_files:
            print(f"✨ NEW FILES: {len(new_files)}")
            for f in new_files:
                print(f"   - {f}")
            print()
        
        print(f"✅ UNCHANGED: {unchanged} files")
        print()
        
        if changed:
            print("="*70)
            print("⚠️  WARNING: Input files have been modified!")
            print("   This could indicate:")
            print("   1. You manually edited the files (OK)")
            print("   2. A script is modifying input files (BAD)")
            print("="*70)
        else:
            print("="*70)
            print("✅ SUCCESS: No input files were modified by processing")
            print("="*70)
    
    else:
        print("📋 First run - saving baseline state...\n")
    
    # Save current state
    with open(state_file, 'w') as f:
        for filepath in all_files:
            rel_path = str(filepath.relative_to(input_dir))
            info = get_file_info(filepath)
            f.write(f"{rel_path}|{info['size']}|{info['modified']}|{info['hash']}\n")
    
    print(f"\n💾 State saved to: {state_file}")
    print("\nTo check if files change during processing:")
    print("  1. Run this script now")
    print("  2. Run: python3 recipe_pipeline.py --process")
    print("  3. Run this script again")
    print("  4. Compare results")
    print()

if __name__ == '__main__':
    check_input_files()
