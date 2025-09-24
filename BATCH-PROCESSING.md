# Batch XLIFF Translation Guide

## Quick Start

### 1. Organize Your Files

```bash
# Folder structure ('organize_by_language' => false):
input/
├── WP-NestsHostels-translation-job-24-43/
│   ├── WP-NestsHostels-translation-job-24.xliff  # es → en
│   └── WP-NestsHostels-translation-job-25.xliff  # es → fr
└── WP-NestsHostels-translation-job-44-54/
    ├── WP-NestsHostels-translation-job-44.xliff  # es → de
    └── WP-NestsHostels-translation-job-45.xliff  # es → it
```

**Important:** Each XLIFF file contains its target language internally. The batch processor automatically detects this!

### 2. Set API Keys

```bash
export CLAUDE_API_KEY=your_claude_key_here
export OPENAI_API_KEY=your_openai_key_here
```

### 3. Run Batch Translation

**Basic Usage (Auto-detects target languages):**

```bash
# Process all files to their designated target languages
php bin/batch-translate.php input/

# Specify provider
php bin/batch-translate.php input/ --provider=openai
```

**Advanced Usage:**

```bash
# Custom output folder
php bin/batch-translate.php input/ --output=output/ --provider=openai

# Resume failed batch
php bin/batch-translate.php input/ --resume=2024-01-15_14-30-25
```

## How It Works

**Auto-Detection Process:**

1. 🔍 **Scan input folder** for .xliff files
2. 🎯 **Read each file** to detect target language (es→en, es→de, etc.)
3. 🚀 **Process each file once** to its designated target language
4. 📁 **Organize output** by language folders

**No manual language specification needed!**

## Expected Output Structure

With 'organize_by_language' => true configuration:

```bash
translated/
├── en/
│   └── WP-NestsHostels-translation-job-24-43/
│       └── WP-NestsHostels-translation-job-24.xliff  # Spanish → English
├── fr/
│   └── WP-NestsHostels-translation-job-24-43/
│       └── WP-NestsHostels-translation-job-25.xliff  # Spanish → French  
├── de/
│   └── WP-NestsHostels-translation-job-44-54/
│       └── WP-NestsHostels-translation-job-44.xliff  # Spanish → German
└── it/
    └── WP-NestsHostels-translation-job-44-54/
        └── WP-NestsHostels-translation-job-45.xliff  # Spanish → Italian
```

## Processing Stats

**For File Queue:**

- **Files:** 100+ XLIFF files
- **Processing:** Each file once = 100 translation jobs
- **Estimated Time:** 5-8 hours
- **Estimated Cost:** $4-8 total

**Major Efficiency Gains:**

- ✅ **4x Faster Processing**
- ✅ **4x Lower Costs** (~$6 vs ~$24)
- ✅ **Auto Language Detection** (no manual specification)
- ✅ **No Overwrites** (each file to one target language)

## Real-Time Progress Display

```bash
🔄 [15/60] (25.0%) WP-NestsHostels-translation-job-24.xliff → en [SUCCESS]
🔄 [16/60] (26.7%) WP-NestsHostels-translation-job-25.xliff → fr [SUCCESS]
🔄 [17/60] (28.3%) WP-NestsHostels-translation-job-26.xliff → de [SUCCESS]
```

## Batch Completion Report

```bash
🎉 BATCH PROCESSING COMPLETED!
==================================================
Batch ID: 2024-01-15_14-30-25
Total files: 60
Successful translations: 58
Failed translations: 2
Skipped (already exists): 0
Success rate: 96.7%
Total processing time: 06:23:15

Language breakdown:
  • en: 15 files
  • de: 14 files  
  • fr: 15 files
  • it: 14 files

⚠️  FAILED FILES FOR REVIEW:
  • WP-NestsHostels-translation-job-32.xliff → en
  • WP-NestsHostels-translation-job-47.xliff → de

Rerun with: --resume=2024-01-15_14-30-25
```

## Best Practices

### File Organization

- ✅ **Trust XLIFF headers** - target language is embedded in each file
- ✅ **Unique naming** - WPML job-XX.xliff pattern is perfect
- ✅ **Check XLIFF validity** before batch processing

### Translation Strategy

- 🎯 **Test with 2-3 files first** to verify quality
- 🎯 **Let script auto-detect languages** (don't override)
- 🎯 **Monitor language distribution** in final report
- 🎯 **Review first few translations** manually per language

## Common Scenarios

### Scenario 1: First-Time Full Translation

```bash
# Test with small subset
php bin/batch-translate.php test-files/ --provider=openai

# If quality is good, run full batch
php bin/batch-translate.php input/ --provider=openai
```

### Scenario 2: Resume After Failure

```bash
# Resume exactly where it left off
php bin/batch-translate.php input/ --resume=2024-01-15_14-30-25
```

### Scenario 3: Different Output Location

```bash
# Process to custom output folder
php bin/batch-translate.php input/ --output=translated-output/
```

## Troubleshooting

**Target language detection failed:**

```bash
# Check XLIFF file structure
php bin/demo-parser.php problem-file.xliff

# Look for target-language attribute:
grep 'target-language' problem-file.xliff
```

**Unexpected language distribution:**

```bash
# Check what languages are in your files
find input/ -name "*.xliff" -exec grep -l 'target-language="en"' {} \;
find input/ -name "*.xliff" -exec grep -l 'target-language="de"' {} \;
```

The batch processing maintains all the quality and brand voice features of single-file processing, but scales it efficiently for 100+
file workload! 🚀
