<?php

// Configuración de la API
// !! IMPORTANTE: Mover CLIENT_SECRET a variables de entorno o un gestor de secretos para producción !!
define('CLIENT_ID', ''); // Mantén tu Client ID
define('CLIENT_SECRET', ''); // ¡¡PROTEGER ESTO!!
define('TENANT_ID', ''); // Mantén tu Tenant ID

// Configuración del Fichaje
define('USER_ID', ''); // Mantén tu User ID
define('TEAM_ID', ''); // Mantén tu Team ID

// Modo de prueba: true para habilitar logs más detallados o fijar la hora (ver index.php)
define('TEST_MODE', true);
// Hora fija para TEST_MODE (formato 'YYYY-MM-DD HH:MM:SS' o solo 'HH:MM:SS' para la hora en el día actual)
// Dejar vacío para usar la hora real incluso en TEST_MODE si solo quieres logs detallados.
define('FIXED_TEST_TIME', ''); // Ejemplo: '2024-05-15 09:05:00' o '09:05:00'

// Nombres de los descansos a procesar (sensible a mayúsculas/minúsculas)
define('BREAK_DISPLAY_NAMES', ['Merienda', 'Comida']);

// Ruta para guardar los logs
define('LOG_DIR', __DIR__ . '/logs');
define('DATA_DIR',  __DIR__ . '/data'); // Ruta del archivo donde se guardarán los datos de estado

// Establecer la zona horaria
define('TIMEZONE', 'Europe/Madrid');
date_default_timezone_set(TIMEZONE); // Establecer la zona horaria por defecto para todas las funciones de fecha/hora

// Permisos para directorios creados (0755 es un valor común, ajústalo según tu política de seguridad)
define('DIR_PERMISSIONS', 0755);

// Margen de tiempo en minutos para considerar una acción (ej. si el cron se ejecuta cada 5 min, un margen de 5-10 min)
define('ACTION_MARGIN_MINUTES', 5);

// Horas máximas para un turno como fallback si no se encuentra el turno y se necesita hacer auto clock-out.
define('MAX_SHIFT_HOURS_FALLBACK', 12); // Ejemplo: 12 horas

// *** CONFIGURACIÓN SSL ***
// Habilitar verificación SSL. ¡¡MUY RECOMENDADO DEJAR EN 'true' PARA PRODUCCIÓN!!
// Cambiar a 'false' SOLO para pruebas locales si tienes problemas de certificados y entiendes los riesgos.
define('ENABLE_SSL_VERIFICATION', false); // true para producción, false para pruebas locales si es estrictamente necesario
