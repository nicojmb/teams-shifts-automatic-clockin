<?php

define('CLIENT_ID', 'xxx');
define('CLIENT_SECRET', '');
define('TENANT_ID', '');

define('USER_ID', '');
define('TEAM_ID', '');

define('TEST_MODE', true);
define('FIXED_TEST_TIME', ''); // Example: '2024-05-15 09:05:00' o '09:05:00'
define('BREAK_DISPLAY_NAMES', ['Merienda', 'Comida']);

define('LOG_DIR', __DIR__ . '/logs');
define('DATA_DIR',  __DIR__ . '/data');

define('TIMEZONE', 'Europe/Madrid');
date_default_timezone_set(TIMEZONE);

define('DIR_PERMISSIONS', 0755);

define('ACTION_MARGIN_MINUTES', 5);

define('MAX_SHIFT_HOURS_FALLBACK', 12);

define('ENABLE_SSL_VERIFICATION', false);
