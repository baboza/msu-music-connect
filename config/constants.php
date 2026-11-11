<?php
/**
 * Music MSU Connect - Core System Constants & OAuth Configuration
 * College of Music, Mahasarakham University
 */

// Load local overrides if present (e.g. config/oauth.local.php is in .gitignore)
if (file_exists(__DIR__ . '/oauth.local.php')) {
    require_once __DIR__ . '/oauth.local.php';
}

// Google OAuth Configuration Fallbacks (Set via environment variables in production)
if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: 'YOUR_GOOGLE_CLIENT_ID_HERE');
}

if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: 'YOUR_GOOGLE_CLIENT_SECRET_HERE');
}
