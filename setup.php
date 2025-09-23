<?php

/**
 * Setup script for XLIFF Translation Workflow
 * Creates necessary directories and validates environment
 */

echo "🔧 Setting up XLIFF Translation Workflow...\n\n";

// Required directories
$directories = [
    'input',
    'translated',
    'logs',
    'src/Core',
    'src/Utils',
    'config',
    'bin'
];

echo "📁 Creating directory structure...\n";
foreach ($directories as $dir) {
    if ( ! is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "  ✅ Created: {$dir}/\n";
    } else {
        echo "  ✅ Exists: {$dir}/\n";
    }
}

// Check PHP requirements
echo "\n🔍 Checking PHP requirements...\n";

$requiredVersion = '8.0';
if (version_compare(PHP_VERSION, $requiredVersion, '>=')) {
    echo "  ✅ PHP Version: " . PHP_VERSION . " (>= {$requiredVersion})\n";
} else {
    echo "  ❌ PHP Version: " . PHP_VERSION . " (requires >= {$requiredVersion})\n";
    exit(1);
}

$requiredExtensions = [ 'dom', 'libxml', 'json' ];
foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        echo "  ✅ Extension: {$ext}\n";
    } else {
        echo "  ❌ Missing extension: {$ext}\n";
        exit(1);
    }
}

// Check if composer autoload exists
echo "\n📦 Checking Composer setup...\n";
if (file_exists('vendor/autoload.php')) {
    echo "  ✅ Composer autoload found\n";
} else {
    echo "  ⚠️  Composer autoload not found. Run: composer install\n";
}

// Check for sample XLIFF files
echo "\n📄 Checking for input files...\n";
$inputFiles = glob('input/*.xliff');
if ( ! empty($inputFiles)) {
    echo "  ✅ Found " . count($inputFiles) . " XLIFF files in input/\n";
    foreach ($inputFiles as $file) {
        echo "    - " . basename($file) . "\n";
    }
} else {
    echo "  ⚠️  No XLIFF files found in input/ directory\n";
    echo "     Place your sample XLIFF files in the input/ directory\n";
}

echo "\n✅ Setup complete!\n\n";

echo "🚀 QUICK START:\n";
echo "1. Run: composer install (if not done already)\n";
echo "2. Place your XLIFF files in the input/ directory\n";
echo "3. Test the parser: php bin/demo-parser.php input/your-file.xliff\n";
echo "4. Check logs in the logs/ directory\n";
echo "5. Find translated files in the translated/ directory\n\n";

echo "📚 NEXT STEPS:\n";
echo "- The XLIFFParser class is ready to use\n";
echo "- Add OpenAI integration for actual translation\n";
echo "- Create brand voice prompts\n";
echo "- Build the full CLI workflow\n\n";
