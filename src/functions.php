<?php

function loadEnv($path = __DIR__ . '/../.env')
{
    if (!file_exists($path))
    {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line)
    {
        if (str_starts_with(trim($line), '#'))
        {
            continue;
        }

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, '"\'');
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}


function logMsg($msg, $level = 'INFO')
{
    if (!is_dir(LOG_DIR))
    {
        if (!mkdir(LOG_DIR, DIR_PERMISSIONS, true) && !is_dir(LOG_DIR))
        {
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
        'client_secret' => CLIENT_SECRET,
        'scope' => 'https://graph.microsoft.com/.default',
    ]);

    $ch = curl_init($url);
    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded"],
    ];

    if (defined('ENABLE_SSL_VERIFICATION'))
    {
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = ENABLE_SSL_VERIFICATION;
        $curlOptions[CURLOPT_SSL_VERIFYHOST] = ENABLE_SSL_VERIFICATION ? 2 : 0;
    }
    else
    {
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

    $file = DATA_DIR . '/state.json';
    $data = [
        'activeTimeCardId' => null,
        'lastAction' => null,
        'lastActionTimestamp' => null,
        'currentShiftId' => null,
        'currentBreakDisplayName' => null
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
        {
            $content = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            if ($content)
            {
                $decodedData = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedData))
                {
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
    $fp = fopen($file, 'c+');
    if (!$fp)
    {
        logMsg("❌ No se pudo abrir state.json para escritura", 'ERROR');
        throw new Exception("No se pudo abrir state.json para escritura");
    }

    if (!flock($fp, LOCK_EX))
    {
        fclose($fp);
        logMsg("❌ No se pudo obtener bloqueo exclusivo para escribir state.json", 'ERROR');
        throw new Exception("No se pudo obtener bloqueo exclusivo para escribir state.json");
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function getLastShiftCheckStatus()
{
    $file = DATA_DIR . '/last_shift_check.json';
    if (!file_exists($file)) return ['date' => '', 'shouldCheckAgain' => true];
    return json_decode(file_get_contents($file), true);
}

function setLastShiftCheckStatus($date, $shouldCheckAgain)
{
    $file = DATA_DIR . '/last_shift_check.json';
    file_put_contents($file, json_encode(['date' => $date, 'shouldCheckAgain' => $shouldCheckAgain]));
}

function updateState($actionType, $timeCardId, &$currentStateData, DateTime $timestamp, $shiftId = null, $breakDisplayName = null)
{
    $currentStateData['lastAction'] = $actionType;
    $currentStateData['lastActionTimestamp'] = $timestamp->format('Y-m-d H:i:s');

    if ($actionType === 'clocked_in')
    {
        $currentStateData['activeTimeCardId'] = $timeCardId;
        $currentStateData['currentShiftId'] = $shiftId;
        $currentStateData['currentBreakDisplayName'] = null;
    }
    elseif ($actionType === 'clocked_out')
    {
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

########### LOAD ENV ##########
loadEnv();
