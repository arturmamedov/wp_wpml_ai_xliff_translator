<?php

return [
    'default_output_folder' => 'translated/',

    // File discovery settings
    'supported_extensions' => [ 'xliff', 'xlf' ],
    'scan_subdirectories' => true,
    'max_subdirectory_depth' => 2, // Increased for your nested structure

    // Batch processing settings
    'resume_capability' => true,
    'skip_existing_files' => true, // Skip if output already exists
    'progress_save_interval' => 1, // Save progress after each job

    // Error handling
    'continue_on_error' => true, // Continue batch even if individual files fail
    'max_retries_per_file' => 0, // Don't retry failed files in same batch
    'log_failed_files' => true,

    // Output organization
    'organize_by_language' => false, // true for Create language folders to avoid overwrites
    'preserve_folder_structure' => true, // Keep your WP-NestsHostels-translation-job-XX-XX/ structure
    'output_filename_pattern' => '{filename}.xliff', // Keep original names exactly

    // Progress reporting
    'show_realtime_progress' => true,
    'progress_update_interval' => 1, // Every N files
    'estimated_time_calculation' => true,

    // Batch limits (for very large batches)
    'max_files_per_batch' => 1000,

    // Note: Each file is processed once to its designated target language
    // No multiplication by language count needed
];
