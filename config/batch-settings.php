<?php

return [
    'default_languages' => [ 'en' ], // Default if not specified
    'default_output_folder' => 'translated/',

    // File discovery settings
    'supported_extensions' => [ 'xliff', 'xlf' ],
    'scan_subdirectories' => true,
    'max_subdirectory_depth' => 1,

    // Batch processing settings
    'resume_capability' => true,
    'skip_existing_files' => true, // Skip if output already exists
    'progress_save_interval' => 1, // Save progress after each job

    // Error handling
    'continue_on_error' => true, // Continue batch even if individual files fail
    'max_retries_per_file' => 0, // Don't retry failed files in same batch
    'log_failed_files' => true,

    // Output organization
    'organize_by_language' => false, // Create subfolders: translated/en/, translated/de/
    'preserve_folder_structure' => false, // Keep input folder structure in output
    'output_filename_pattern' => '{filename}_{language}.xliff',

    // Progress reporting
    'show_realtime_progress' => true,
    'progress_update_interval' => 1, // Every N files
    'estimated_time_calculation' => true,

    // Batch limits (for very large batches)
    'max_files_per_batch' => 1000,
    'max_jobs_per_batch' => 4000, // files × languages
];
