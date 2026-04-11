<?php
/**
 * config.php — temporary SmartCaptcha secret key storage.
 *
 * IMPORTANT: This file is a TEMPORARY workaround. The recommended approach is
 * to set the SMARTCAPTCHA_SECRET environment variable on the server and DELETE
 * this file. See README.md for details.
 *
 * Do NOT commit a real secret key to this repository. Replace the placeholder
 * below with the actual secret only on the production server.
 */

// Replace with your real Yandex SmartCaptcha secret key on the server.
// Once the SMARTCAPTCHA_SECRET environment variable is configured, delete this file.
define('SMARTCAPTCHA_SECRET_KEY', 'REPLACE_WITH_YOUR_SMARTCAPTCHA_SECRET_KEY');
