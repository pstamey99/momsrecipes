#!/usr/bin/env python3
"""Force-insert recipes that are missing from the live database."""
import json
import requests

API = 'https://paulstamey.com/momsrecipes/api/index.php'

def normalize(val):
    if not val:
        return ''
    if isinstance(val, list):
        return '\n'.join(str(i).strip() for i in val if i)
    return str(val).strip()

# Get all live titles
print('Fetching live recipes...')
live = requests.get(f'{API}/recipes').json().get('recipes', [])
live_titles = {r['title'].strip().lower() for r in live}
print(f'Live count: {len(live_titles)}')

# Get local titles
with open('./output/recipes_metadata.json') as f:
    local = json.load(f)
print(f'Local count: {len(local)}')

# Find missing
missing = [r for r in local if r['title'].strip().lower() not in live_titles]
print(f'Missing: {len(missing)}\n')

created = 0
failed = 0
for r in missing:
    title = r.get('title', '')
    print(f'  Creating: {title}')
    payload = {
        'title':       title,
        'category':    r.get('category', ''),
        'contributor': r.get('contributor', ''),
        'ingredients': normalize(r.get('ingredients', '')),
        'directions':  normalize(r.get('directions', '')) or '1. See ingredients.',
        'notes':       normalize(r.get('notes', '')),
        'tags':        normalize(r.get('tags', '')),
    }
    try:
        resp = requests.post(
            f'{API}/recipes',
            json=payload,
            headers={'Content-Type': 'application/json'},
            timeout=30
        )
        if resp.ok:
            created += 1
            print(f'    ✓ Created (id: {resp.json().get("recipe", {}).get("id")})')
        else:
            failed += 1
            print(f'    ✗ Failed {resp.status_code}: {resp.text[:150]}')
    except Exception as e:
        failed += 1
        print(f'    ✗ Error: {e}')

print(f'\nDone — Created: {created}  Failed: {failed}')
print(f'New total should be: {len(live_titles) + created}')
