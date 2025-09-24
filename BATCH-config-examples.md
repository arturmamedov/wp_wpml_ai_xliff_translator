# Batch Configuration Examples

## Configuration Options in `config/batch-settings.php`

### Option 1: Language Folders (Recommended)

```php
'organize_by_language' => true,
'preserve_folder_structure' => false,
'output_filename_pattern' => '{filename}_{language}.xliff',
```

**Output:**

```bash
translated/
├── en/
│   ├── post-1_en.xliff
│   ├── page-about_en.xliff
│   └── category-surf_en.xliff
├── de/
│   ├── post-1_de.xliff
│   ├── page-about_de.xliff
│   └── category-surf_de.xliff
└── fr/
    ├── post-1_fr.xliff
    ├── page-about_fr.xliff
    └── category-surf_fr.xliff
```

### Option 2: All Files in One Folder

```php
'organize_by_language' => false,
'preserve_folder_structure' => false,
'output_filename_pattern' => '{filename}_{language}.xliff',
```

**Output:**

```bash
translated/
├── post-1_en.xliff
├── post-1_de.xliff
├── post-1_fr.xliff
├── page-about_en.xliff
├── page-about_de.xliff
├── page-about_fr.xliff
├── category-surf_en.xliff
├── category-surf_de.xliff
└── category-surf_fr.xliff
```

### Option 3: Preserve Original Filenames (No Language Suffix)

```php
'organize_by_language' => true,
'preserve_folder_structure' => false,
'output_filename_pattern' => '{filename}.xliff',
```

**Output:**

```bash
translated/
├── en/
│   ├── post-1.xliff          # ← Original filename preserved
│   ├── page-about.xliff
│   └── category-surf.xliff
├── de/
│   ├── post-1.xliff
│   ├── page-about.xliff
│   └── category-surf.xliff
└── fr/
    ├── post-1.xliff
    ├── page-about.xliff
    └── category-surf.xliff
```

### Option 4: Preserve Input Folder Structure

**Input:**

```bash
input/
├── posts/
│   ├── post-1.xliff
│   └── post-2.xliff
├── pages/
│   ├── about.xliff
│   └── contact.xliff
└── categories/
    └── surf.xliff
```

```php
'organize_by_language' => true,
'preserve_folder_structure' => true,
'output_filename_pattern' => '{filename}_{language}.xliff',
```

**Output:**

```bash
translated/
├── en/
│   ├── posts/
│   │   ├── post-1_en.xliff
│   │   └── post-2_en.xliff
│   ├── pages/
│   │   ├── about_en.xliff
│   │   └── contact_en.xliff
│   └── categories/
│       └── surf_en.xliff
├── de/
│   ├── posts/
│   │   ├── post-1_de.xliff
│   │   └── post-2_de.xliff
│   ├── pages/
│   │   ├── about_de.xliff
│   │   └── contact_de.xliff
│   └── categories/
│       └── surf_de.xliff
└── fr/
    ├── posts/
    │   ├── post-1_fr.xliff
    │   └── post-2_fr.xliff
    ├── pages/
    │   ├── about_fr.xliff
    │   └── contact_fr.xliff
    └── categories/
        └── surf_fr.xliff
```

### Option 5: Flat Structure with Preserved Filenames (Your Preferred?)

```php
'organize_by_language' => false,
'preserve_folder_structure' => false,
'output_filename_pattern' => '{filename}.xliff',
```

**⚠️ WARNING:** This would cause filename conflicts! Files would overwrite each other since multiple languages would create the same
filename.

**Better Alternative for Similar Result:**

```php
'organize_by_language' => false,
'preserve_folder_structure' => false,
'output_filename_pattern' => '{language}-{filename}.xliff',
```

**Output:**

```bash
translated/
├── en-post-1.xliff
├── de-post-1.xliff
├── fr-post-1.xliff
├── en-page-about.xliff
├── de-page-about.xliff
├── fr-page-about.xliff
├── en-category-surf.xliff
├── de-category-surf.xliff
└── fr-category-surf.xliff
```

## Custom Filename Patterns

Available placeholders in `output_filename_pattern`:

- `{filename}` - Original filename without extension
- `{language}` - Target language code (en, de, fr, it)

**Examples:**

- `{filename}_{language}.xliff` → `post-1_en.xliff`
- `{filename}.xliff` → `post-1.xliff`
- `{language}-{filename}.xliff` → `en-post-1.xliff`
- `translated-{filename}-{language}.xliff` → `translated-post-1-en.xliff`

## Recommended Configurations

### For WPML Import Organization (Best Practice)

```php
'organize_by_language' => true,
'preserve_folder_structure' => false,
'output_filename_pattern' => '{filename}.xliff',
```

### For Bulk Processing with Clear Language Identification

```php
'organize_by_language' => true,
'preserve_folder_structure' => false,
'output_filename_pattern' => '{filename}_{language}.xliff',
```

### For Simple Flat Structure

```php
'organize_by_language' => false,
'preserve_folder_structure' => false,
'output_filename_pattern' => '{language}-{filename}.xliff',
```

Which configuration works best for your WPML import workflow? 🤔
