<?php

/**
 * Realiza una solicitud cURL a la API de Microsoft Graph.
 *
 * @param string $url URL del endpoint.
 * @param string $accessToken Token de acceso.
 * @param string $method Método HTTP (GET, POST, PATCH, DELETE).
 * @param array|null $payload Datos para enviar en el cuerpo (para POST/PATCH).
 * @param bool $expectNoContent Si es true, un código 204 se considera éxito.
 * @return array|string Decoded JSON response or raw response for 204.
 * @throws Exception Si ocurre un error en cURL o la API devuelve un error.
 */
function callGraphApi($url, $accessToken, $method = 'GET', $payload = null, $expectNoContent = false)
{
    $headers = [
        "Authorization: Bearer $accessToken",
        "MS-APP-ACTS-AS: " . USER_ID, // Actuar en nombre del usuario configurado
    ];

    if ($payload !== null)
    {
        $headers[] = "Content-Type: application/json";
        $postFields = json_encode($payload);
    }

    $ch = curl_init($url);
    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ];

    // Aplicar configuración SSL parametrizable
    if (defined('ENABLE_SSL_VERIFICATION'))
    {
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = ENABLE_SSL_VERIFICATION;
        $curlOptions[CURLOPT_SSL_VERIFYHOST] = ENABLE_SSL_VERIFICATION ? 2 : 0;
        if (!ENABLE_SSL_VERIFICATION)
        {
            logMsg("⚠️ ADVERTENCIA: La verificación SSL está DESHABILITADA para la llamada a Graph API ($url). Solo para pruebas locales.", 'WARNING');
        }
    }
    else
    {
        // Comportamiento por defecto si no está definida la constante (recomendado: true)
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = true;
        $curlOptions[CURLOPT_SSL_VERIFYHOST] = 2;
    }

    if ($payload !== null)
    {
        $curlOptions[CURLOPT_POSTFIELDS] = $postFields;
    }

    curl_setopt_array($ch, $curlOptions);
    $response = curl_exec($ch);

    if (curl_errno($ch))
    {
        $error = curl_error($ch);
        curl_close($ch);
        logMsg("❌ Error en cURL ($method $url): " . $error, 'ERROR');
        throw new Exception("Error en cURL ($method $url): $error");
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Éxito si es 204 y se esperaba "No Content"
    if ($expectNoContent && $httpCode === 204)
    {
        return "204 No Content";
    }

    // Éxito si es un código 2xx y no se esperaba específicamente 204
    if ($httpCode >= 200 && $httpCode < 300)
    {
        $responseData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE && !empty($response))
        {
            logMsg("⚠️ Respuesta no JSON de Graph API ($method $url) con código $httpCode: $response", "WARNING");
            return $response;
        }
        return $responseData;
    }

    // Si no es un código de éxito
    $errorMsg = "❌ Error ($httpCode) en la API ($method $url): ";
    $decodedResponse = json_decode($response, true);
    if (isset($decodedResponse['error']['message']))
    {
        $errorMsg .= $decodedResponse['error']['message'];
    }
    else
    {
        $errorMsg .= $response;
    }
    logMsg($errorMsg, 'ERROR');
    throw new Exception($errorMsg);
}


function getShiftForUserAndDay(DateTime $targetDay)
{
    $accessToken = getAccessToken();

    $localTimezone = new DateTimeZone(TIMEZONE); // Tu zona horaria local
    $utcTimezone = new DateTimeZone('UTC');    // Zona horaria UTC

    // Inicio y fin del día en la zona horaria local
    $startOfDayLocal = (clone $targetDay)->setTime(0, 0, 0)->setTimezone($localTimezone);
    $endOfDayLocal = (clone $targetDay)->setTime(23, 59, 59)->setTimezone($localTimezone);

    // Convertir estas fechas a UTC para la consulta de Graph API
    $startOfDayForQueryUTC = (clone $startOfDayLocal)->setTimezone($utcTimezone)->format('Y-m-d\TH:i:s\Z');
    $endOfDayForQueryUTC = (clone $endOfDayLocal)->setTimezone($utcTimezone)->format('Y-m-d\TH:i:s\Z');

    // Este filtro sigue buscando turnos que empiezan Y terminan estrictamente dentro del día local $targetDay
    $filter = "sharedShift/startDateTime+ge+$startOfDayForQueryUTC+and+sharedShift/endDateTime+le+$endOfDayForQueryUTC+";

    logMsg("DEBUG: Filtro de turnos (tu lógica con UTC corregido): $filter", 'DEBUG');
    $url = "https://graph.microsoft.com/v1.0/teams/" . TEAM_ID . "/schedule/shifts?\$filter=" . $filter;


    try
    {
        $data = callGraphApi($url, $accessToken);
    }
    catch (Exception $e)
    {
        logMsg("❌ Error obteniendo turnos: " . $e->getMessage(), 'ERROR');
        return null;
    }

    if (isset($data['value']) && is_array($data['value']))
    {
        $userShifts = array_filter($data['value'], function ($shift)
        {
            return isset($shift['userId']) && $shift['userId'] === USER_ID;
        });

        if (!empty($userShifts))
        {
            // Podría haber múltiples turnos que se solapen.
            // Ordenar por hora de inicio y tomar el primero que esté activo o más próximo.
            // Por ahora, tomamos el primero encontrado.
            usort($userShifts, function ($a, $b)
            {
                return new DateTime($a['sharedShift']['startDateTime']) <=> new DateTime($b['sharedShift']['startDateTime']);
            });
            return reset($userShifts);
        }
    }
    return null;
}


function getBreaksFromShift($sharedShift)
{
    $breaks = [];
    $definedBreakNames = defined('BREAK_DISPLAY_NAMES') ? BREAK_DISPLAY_NAMES : ['Merienda', 'Comida'];

    if (!isset($sharedShift['activities']) || !is_array($sharedShift['activities']))
    {
        return $breaks;
    }

    foreach ($sharedShift['activities'] as $activity)
    {
        if (isset($activity['displayName']) && in_array($activity['displayName'], $definedBreakNames, true))
        {
            try
            {
                $breakStart = new DateTime($activity['startDateTime'], new DateTimeZone('UTC'));
                $breakStart->setTimezone(new DateTimeZone(TIMEZONE));

                $breakEnd = new DateTime($activity['endDateTime'], new DateTimeZone('UTC'));
                $breakEnd->setTimezone(new DateTimeZone(TIMEZONE));

                $breaks[] = [
                    'startDateTime' => $breakStart,
                    'endDateTime' => $breakEnd,
                    'displayName' => $activity['displayName'],
                ];
            }
            catch (Exception $e)
            {
                logMsg("⚠️ Error procesando fecha de descanso '{$activity['displayName']}': " . $e->getMessage(), 'WARNING');
            }
        }
    }
    usort($breaks, function ($a, $b)
    {
        return $a['startDateTime'] <=> $b['startDateTime'];
    });
    return $breaks;
}

function doClockIn()
{
    $accessToken = getAccessToken();
    $url = "https://graph.microsoft.com/beta/teams/" . TEAM_ID . "/schedule/timeCards/clockIn";
    $payload = [
        "atApprovedLocation" => true,
        "notes" => [
            "content" => "Fichaje de entrada automático",
            "contentType" => "text"
        ]
    ];

    $responseData = callGraphApi($url, $accessToken, 'POST', $payload);
    if (isset($responseData['id']))
    {
        return $responseData['id'];
    }
    throw new Exception("❌ Error en doClockIn: La respuesta no contenía un ID de timeCard. Respuesta: " . json_encode($responseData));
}

function doClockOut($timeCardId)
{
    $accessToken = getAccessToken();
    $url = "https://graph.microsoft.com/beta/teams/" . TEAM_ID . "/schedule/timeCards/$timeCardId/clockOut";
    $payload = [
        "atApprovedLocation" => true,
        "notes" => [
            "content" => "Fichaje de salida automático",
            "contentType" => "text"
        ]
    ];
    callGraphApi($url, $accessToken, 'POST', $payload, true);
    return $timeCardId;
}

function doStartBreak($timeCardId, $breakDisplayName = "Descanso")
{
    $accessToken = getAccessToken();
    $url = "https://graph.microsoft.com/beta/teams/" . TEAM_ID . "/schedule/timeCards/$timeCardId/startBreak";
    $payload = [
        "atApprovedLocation" => true,
        "notes" => [
            "content" => "Inicio de descanso automático: " . $breakDisplayName,
            "contentType" => "text"
        ]
    ];
    callGraphApi($url, $accessToken, 'POST', $payload, true);
    return $timeCardId;
}

function doEndBreak($timeCardId, $breakDisplayName = "Descanso")
{
    $accessToken = getAccessToken();
    $url = "https://graph.microsoft.com/beta/teams/" . TEAM_ID . "/schedule/timeCards/$timeCardId/endBreak";
    $payload = [
        "atApprovedLocation" => true,
        "notes" => [
            "content" => "Fin de descanso automático: " . $breakDisplayName,
            "contentType" => "text"
        ]
    ];
    callGraphApi($url, $accessToken, 'POST', $payload, true);
    return $timeCardId;
}
