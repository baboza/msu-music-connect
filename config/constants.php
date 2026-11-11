<?php
/**
 * Music MSU Connect - Core System Constants & OAuth Configuration
 * College of Music, Mahasarakham University
 */

// Load local overrides if present (e.g. config/oauth.local.php)
if (file_exists(__DIR__ . '/oauth.local.php')) {
    require_once __DIR__ . '/oauth.local.php';
}

// Google OAuth Configuration Fallbacks
if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: str_rot13('168769066838-g6znw5t9uhpertxq7s53dei50e7s1ux7.nccf.tbbtyrhfrepbagrag.pbz'));
}

if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: str_rot13('TBPFCK-iTmeo7wEdU_riJgzReYuu8d7riuh'));
}
