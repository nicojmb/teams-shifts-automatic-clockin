<?php

function loadEnv($path = __DIR__ . '/.env')
{
    if (!file_exists($path))
    {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line)
    {
        // Ignora comentarios
        if (str_starts_with(trim($line), '#'))
        {
            continue;
        }

        // Divide clave=valor
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Quitar comillas si las hay
        $value = trim($value, '"\'');

        // Establecer variable de entorno
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}


function logMsg($msg, $level = 'INFO')
{
    if (!is_dir(LOG_DIR))
    {
        // Intentar crear el directorio con permisos definidos
        if (!mkdir(LOG_DIR, DIR_PERMISSIONS, true) && !is_dir(LOG_DIR))
        {
            // Falló la creación del directorio, usar error_log como fallback
            error_log("Error creating log directory: " . LOG_DIR . ". Log message: [$level] $msg");
            return;
        }
    }

    $date = date('Y-m-d');
    $time = date('H:i:s');
    $logMessage = "[$time][$level] $msg\n";

    $logFile = LOG_DIR . '/' . $date . '.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

function getAccessToken()
{
    $url = "https://login.microsoftonline.com/" . TENANT_ID . "/oauth2/v2.0/token";
    $postData = http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => CLIENT_ID,
        'client_secret' => CLIENT_SECRET, // ¡¡PROTEGER ESTO!! Ver config.php
        'scope' => 'https://graph.microsoft.com/.default',
    ]);

    $ch = curl_init($url);
    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded"],
    ];

    // Aplicar configuración SSL parametrizable
    if (defined('ENABLE_SSL_VERIFICATION'))
    {
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = ENABLE_SSL_VERIFICATION;
        $curlOptions[CURLOPT_SSL_VERIFYHOST] = ENABLE_SSL_VERIFICATION ? 2 : 0;
    }
    else
    {
        // Comportamiento por defecto si no está definida la constante (recomendado: true)
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = true;
        $curlOptions[CURLOPT_SSL_VERIFYHOST] = 2;
    }

    curl_setopt_array($ch, $curlOptions);

    $response = curl_exec($ch);

    if (curl_errno($ch))
    {
        $error = curl_error($ch);
        curl_close($ch);
        logMsg("❌ Error en cURL (getAccessToken): $error", 'ERROR');
        throw new Exception("Error en cURL obteniendo token de acceso: $error");
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);

    if ($httpCode !== 200 || !isset($json['access_token']))
    {
        logMsg("❌ No se pudo obtener token de acceso. Código: $httpCode. Respuesta: " . $response, 'ERROR');
        throw new Exception("No se pudo obtener token de acceso. Código: $httpCode.");
    }

    return $json['access_token'];
}

function getData()
{
    if (!is_dir(DATA_DIR))
    {
        if (!mkdir(DATA_DIR, DIR_PERMISSIONS, true) && !is_dir(DATA_DIR))
        {
            logMsg("❌ Error creando directorio de datos: " . DATA_DIR, 'ERROR');
            throw new Exception("No se pudo crear el directorio de datos.");
        }
    }

    $file = DATA_DIR . '/state.json'; // Cambiado de data.json a state.json para reflejar mejor su propósito
    $data = [
        'activeTimeCardId' => null,
        'lastAction' => null, // 'clocked_in', 'clocked_out', 'break_started', 'break_ended'
        'lastActionTimestamp' => null,
        'currentShiftId' => null,
        'currentBreakDisplayName' => null // Para saber en qué descanso específico estamos
    ];

    if (file_exists($file))
    {
        $fp = fopen($file, 'r');
        if (!$fp)
        {
            logMsg("❌ No se pudo abrir state.json para lectura.", 'ERROR');
            throw new Exception("No se pudo abrir state.json para lectura.");
        }

        if (flock($fp, LOCK_SH))
        { // Bloqueo compartido para lectura
            $content = stream_get_contents($fp); // Más seguro que filesize + fread para concurrencia
            flock($fp, LOCK_UN);
            fclose($fp);

            if ($content)
            {
                $decodedData = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedData))
                {
                    // Fusionar con valores por defecto para asegurar que todas las claves existan
                    $data = array_merge($data, $decodedData);
                }
                else
                {
                    logMsg("⚠️ state.json contiene JSON inválido o no es un array. Se usará estado por defecto. Error: " . json_last_error_msg(), 'WARNING');
                }
            }
        }
        else
        {
            fclose($fp);
            logMsg("❌ No se pudo obtener bloqueo para leer state.json", 'ERROR');
            throw new Exception("No se pudo obtener bloqueo para leer state.json");
        }
    }
    return $data;
}

function saveData($data)
{
    if (!is_dir(DATA_DIR))
    {
        if (!mkdir(DATA_DIR, DIR_PERMISSIONS, true) && !is_dir(DATA_DIR))
        {
            logMsg("❌ Error creando directorio de datos: " . DATA_DIR, 'ERROR');
            throw new Exception("No se pudo crear el directorio de datos.");
        }
    }

    $file = DATA_DIR . '/state.json';
    $fp = fopen($file, 'c+'); // Crear si no existe, truncar si existe.
    if (!$fp)
    {
        logMsg("❌ No se pudo abrir state.json para escritura", 'ERROR');
        throw new Exception("No se pudo abrir state.json para escritura");
    }

    if (!flock($fp, LOCK_EX))
    { // Bloqueo exclusivo para escritura
        fclose($fp);
        logMsg("❌ No se pudo obtener bloqueo exclusivo para escribir state.json", 'ERROR');
        throw new Exception("No se pudo obtener bloqueo exclusivo para escribir state.json");
    }

    ftruncate($fp, 0);      // Truncar el archivo
    rewind($fp);            // Mover el puntero al inicio
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);            // Asegurar que se escribe en disco
    flock($fp, LOCK_UN);    // Liberar el bloqueo
    fclose($fp);
}

/**
 * Actualiza y registra el estado de la acción.
 *
 * @param string $actionType El tipo de acción (e.g., 'clocked_in', 'break_started').
 * @param string|null $timeCardId El ID de la tarjeta de tiempo, si aplica.
 * @param array &$currentStateData Array del estado actual, se modificará por referencia.
 * @param DateTime $timestamp La hora de la acción.
 * @param string|null $shiftId El ID del turno actual, si aplica.
 * @param string|null $breakDisplayName El nombre del descanso, si aplica.
 */
function updateState($actionType, $timeCardId, &$currentStateData, DateTime $timestamp, $shiftId = null, $breakDisplayName = null)
{
    $currentStateData['lastAction'] = $actionType;
    $currentStateData['lastActionTimestamp'] = $timestamp->format('Y-m-d H:i:s');

    if ($actionType === 'clocked_in')
    {
        $currentStateData['activeTimeCardId'] = $timeCardId;
        $currentStateData['currentShiftId'] = $shiftId;
        $currentStateData['currentBreakDisplayName'] = null; // Reset break on clock-in
    }
    elseif ($actionType === 'clocked_out')
    {
        // activeTimeCardId se podría limpiar, pero mantenerlo puede ser útil para el último log de la sesión.
        // $currentStateData['activeTimeCardId'] = null; // Opcional
        $currentStateData['currentShiftId'] = null;
        $currentStateData['currentBreakDisplayName'] = null;
    }
    elseif ($actionType === 'break_started')
    {
        $currentStateData['currentBreakDisplayName'] = $breakDisplayName;
    }
    elseif ($actionType === 'break_ended')
    {
        $currentStateData['currentBreakDisplayName'] = null;
    }

    logMsg("---> Acción realizada: $actionType. TimeCardID: $timeCardId. Hora: " . $timestamp->format('H:i'));
}

// 
loadEnv();
