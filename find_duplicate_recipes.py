#!/usr/bin/env python3
"""Find duplicate recipe titles in local metadata and show which source files they came from."""
import json
from collections import defaultdict

with open('./output/recipes_metadata.json') as f:
    local = json.load(f)

# Group by normalized title
title_map = defaultdict(list)
for r in local:
    title = r['title'].strip()
    key = title.lower()
    title_map[key].append({
        'title': title,
        'source_file': r.get('source_file', r.get('file', r.get('filename', '(unknown)'))),
        'contributor': r.get('contributor', ''),
        'category': r.get('category', ''),
    })

dupes = {t: entries for t, entries in title_map.items() if len(entries) > 1}

print(f'Total local recipes:  {len(local)}')
print(f'Unique titles:        {len(title_map)}')
print(f'Duplicate titles:     {len(dupes)}')
print(f'Extra copies:         {sum(len(e)-1 for e in dupes.values())}')
print()

for key, entries in sorted(dupes.items()):
    print(f'  [{len(entries)}x] {entries[0]["title"]}')
    for e in entries:
        print(f'        File:        {e["source_file"]}')
        print(f'        Contributor: {e["contributor"]}')
        print(f'        Category:    {e["category"]}')
    print()
