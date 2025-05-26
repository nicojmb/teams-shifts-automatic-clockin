<?php
if (PHP_SAPI !== 'cli')
{
    exit("Este script solo puede ejecutarse desde la consola.\n");
}
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/api.php';

/**
 * Helpers para estados
 */
function isClockedIn(array $state): bool
{
    return $state['lastAction'] === 'clocked_in';
}

function isBreakStarted(array $state): bool
{
    return $state['lastAction'] === 'break_started';
}

function isClockedOut(array $state): bool
{
    return $state['lastAction'] === 'clocked_out';
}

try
{
    ########### SET DATE/TIME ##########

    $timezone = new DateTimeZone(TIMEZONE);

    if (TEST_MODE && defined('FIXED_TEST_TIME') && !empty(FIXED_TEST_TIME))
    {
        if (strlen(FIXED_TEST_TIME) === 8 && strpos(FIXED_TEST_TIME, ':') !== false)
        {
            $now = new DateTime(date('Y-m-d') . ' ' . FIXED_TEST_TIME, $timezone);
        }
        else
        {
            $now = new DateTime(FIXED_TEST_TIME, $timezone);
        }
        logMsg("MODO TEST: Hora fijada a: " . $now->format('Y-m-d H:i:s P'), 'DEBUG');
    }
    else
    {
        $now = new DateTime("now", $timezone);
    }

    logMsg("Iniciando proceso de fichaje. Hora actual: " . $now->format('Y-m-d H:i:s P'));

    $currentState = getData();
    logMsg("Estado actual cargado: " . json_encode($currentState), 'DEBUG');

    ########### GET SHIFT ##########

    $todayStr = $now->format('Y-m-d');

    if (isset($currentState['cachedShiftDate']) && $currentState['cachedShiftDate'] === $todayStr)
    {
        if (!empty($currentState['cachedShift']))
        {
            $shift = $currentState['cachedShift'];
            logMsg("📦 Reutilizando turno guardado localmente para hoy ($todayStr).", 'DEBUG');
        }
        else
        {
            $shift = null;
            logMsg("📦 No se encontró turno guardado localmente para hoy ($todayStr). Hoy no se debe fichar.", 'DEBUG');
        }
    }
    else
    {
        $currentState['cachedShift'] = null;
        $currentState['cachedShiftDate'] = $todayStr;

        $shift = getShiftForUserAndDay($now);

        if ($shift !== null)
        {
            $currentState['cachedShift'] = $shift;
            $currentState['cachedShiftDate'] = $todayStr;
            saveData($currentState);
        }
    }

    if (empty($shift) || !isset($shift['sharedShift']))
    {
        logMsg("No se encontraron turnos aplicables para el usuario " . USER_ID . " para la fecha " . $now->format('Y-m-d') . ".", 'INFO');

        if (isClockedIn($currentState) || isBreakStarted($currentState))
        {
            logMsg("Usuario fichado ('{$currentState['lastAction']}') pero no se encontró turno. Se evaluará salida por defecto si es necesario.", 'WARNING');

            $lastActionTime = new DateTime($currentState['lastActionTimestamp'] ?? '1970-01-01', $timezone);
            $hoursSinceLastAction = ($now->getTimestamp() - $lastActionTime->getTimestamp()) / 3600;

            if ($hoursSinceLastAction > (defined('MAX_SHIFT_HOURS_FALLBACK') ? MAX_SHIFT_HOURS_FALLBACK : 12))
            {
                if ($currentState['activeTimeCardId'])
                {
                    logMsg("⚠️ Forzando Clock-Out por ausencia de turno y tiempo prolongado (más de " . round($hoursSinceLastAction, 2) . "h desde '{$currentState['lastAction']}').", 'WARNING');
                    doClockOut($currentState['activeTimeCardId']);
                    updateState('clocked_out', $currentState['activeTimeCardId'], $currentState, $now);
                    saveData($currentState);
                    logMsg("✅ Clock-Out (por ausencia de turno/inactividad) realizado.", 'SUCCESS');
                }
            }
        }

        logMsg("El proceso ha finalizado. No hay turnos activos y/o el usuario no está fichado o ya se ha gestionado.", 'INFO');
        logMsg("-------------------------------", 'DEBUG');
        exit;
    }

    $sharedShift = $shift['sharedShift'];
    $shiftId = $shift['id'];

    $shiftStart = (new DateTime($sharedShift['startDateTime'], new DateTimeZone('UTC')))->setTimezone($timezone);
    $shiftEnd = (new DateTime($sharedShift['endDateTime'], new DateTimeZone('UTC')))->setTimezone($timezone);

    logMsg("Turno encontrado: " . ($sharedShift['displayName'] ?? 'Sin nombre') . " (ID: $shiftId, Inicia: " . $shiftStart->format('Y-m-d H:i') . ", Fin: " . $shiftEnd->format('Y-m-d H:i') . ")", 'INFO');

    ########### GET BREAKS ##########

    $breaks = getBreaksFromShift($sharedShift);

    if (!empty($breaks))
    {
        logMsg("Descansos encontrados (" . count($breaks) . "):", 'INFO');
        foreach ($breaks as $idx => $break)
        {
            logMsg("---> Descanso " . ($idx + 1) . ": " . $break['displayName'] . " (Inicio: " . $break['startDateTime']->format('H:i') . ", Fin: " . $break['endDateTime']->format('H:i') . ")", 'INFO');
        }
    }

    $timeCardId = $currentState['activeTimeCardId'];
    $lastAction = $currentState['lastAction'];
    $actionMarginInterval = new DateInterval('PT' . ACTION_MARGIN_MINUTES . 'M');

    ########### ACTIONS ##########

    // Clock-In
    if ($now >= $shiftStart && $now < $shiftEnd)
    {
        if (empty($lastAction) || isClockedOut($currentState) || $currentState['currentShiftId'] !== $shiftId)
        {
            logMsg("🟢 Intentando Clock-In para el turno: " . ($sharedShift['displayName'] ?? $shiftId), 'INFO');
            $newTimeCardId = doClockIn();
            updateState('clocked_in', $newTimeCardId, $currentState, $now, $shiftId);
            $timeCardId = $newTimeCardId;
            logMsg("✅ Clock-In realizado. TimeCardID: $timeCardId", 'SUCCESS');
        }
    }

    // Break Start
    if ($timeCardId && (isClockedIn($currentState) || $currentState['lastAction'] === 'break_ended') && $currentState['currentShiftId'] === $shiftId && !empty($breaks))
    {
        foreach ($breaks as $break)
        {
            $breakStartBoundary = (clone $break['startDateTime'])->sub($actionMarginInterval);
            $breakEndBoundary = (clone $break['endDateTime'])->add($actionMarginInterval);

            if ($now >= $breakStartBoundary && $now < $break['endDateTime'])
            {
                logMsg("🕒 Intentando Iniciar Descanso: " . $break['displayName'], 'INFO');
                doStartBreak($timeCardId, $break['displayName']);
                updateState('break_started', $timeCardId, $currentState, $now, $shiftId, $break['displayName']);
                logMsg("✅ Inicio de Descanso '" . $break['displayName'] . "' realizado.", 'SUCCESS');
                break;
            }
        }
    }
    // Break End
    else if ($timeCardId && isBreakStarted($currentState) && $currentState['currentShiftId'] === $shiftId && !empty($breaks))
    {
        $currentActiveBreak = null;
        foreach ($breaks as $b)
        {
            if ($b['displayName'] === $currentState['currentBreakDisplayName'])
            {
                $currentActiveBreak = $b;
                break;
            }
        }

        if ($currentActiveBreak)
        {
            $breakEndBoundary = (clone $currentActiveBreak['endDateTime'])->sub($actionMarginInterval);
            $maxTimeToEndBreak = (clone $currentActiveBreak['endDateTime'])->add($actionMarginInterval);

            if ($now >= $breakEndBoundary && $now <= $maxTimeToEndBreak)
            {
                logMsg("🕒 Intentando Finalizar Descanso: " . $currentActiveBreak['displayName'], 'INFO');
                doEndBreak($timeCardId, $currentActiveBreak['displayName']);
                updateState('break_ended', $timeCardId, $currentState, $now, $shiftId);
                logMsg("✅ Fin de Descanso '" . $currentActiveBreak['displayName'] . "' realizado.", 'SUCCESS');
            }
            elseif ($now > $maxTimeToEndBreak)
            {
                logMsg("Forzando Fin de Descanso (tiempo excedido): " . $currentActiveBreak['displayName'], 'WARNING');
                doEndBreak($timeCardId, $currentActiveBreak['displayName']);
                updateState('break_ended', $timeCardId, $currentState, $now, $shiftId);
                logMsg("✅ Fin de Descanso (forzado) '" . $currentActiveBreak['displayName'] . "' realizado.", 'SUCCESS');
            }
        }
        else
        {
            logMsg("⚠️ Estado 'break_started' pero no se encontró el descanso activo '{$currentState['currentBreakDisplayName']}' en la lista de descansos del turno. Volviendo a 'clocked_in'.", 'WARNING');
            updateState('clocked_in', $timeCardId, $currentState, $now, $shiftId);
        }
    }

    // Clock-Out
    if ($timeCardId && (isClockedIn($currentState) || $currentState['lastAction'] === 'break_ended') && $currentState['currentShiftId'] === $shiftId)
    {
        $shiftEndBoundary = (clone $shiftEnd)->sub($actionMarginInterval);
        $maxTimeToClockOut = (clone $shiftEnd)->add(new DateInterval('PT60M'));

        if ($now >= $shiftEndBoundary && $now <= $maxTimeToClockOut)
        {
            logMsg("🔴 Intentando Clock-Out para el turno: " . ($sharedShift['displayName'] ?? $shiftId), 'INFO');
            doClockOut($timeCardId);
            updateState('clocked_out', $timeCardId, $currentState, $now);
            logMsg("✅ Clock-Out realizado. TimeCardID: $timeCardId", 'SUCCESS');
        }
        elseif ($now > $maxTimeToClockOut)
        {
            logMsg("🔴 Forzando Clock-Out (tiempo de turno excedido considerablemente): " . ($sharedShift['displayName'] ?? $shiftId), 'WARNING');
            doClockOut($timeCardId);
            updateState('clocked_out', $timeCardId, $currentState, $now);
            logMsg("✅ Clock-Out (forzado) realizado. TimeCardID: $timeCardId", 'SUCCESS');
        }
    }

    saveData($currentState);
    logMsg("Estado actualizado guardado correctamente.", 'DEBUG');
}
catch (Exception $e)
{
    logMsg("Error fatal durante el proceso: " . $e->getMessage(), 'ERROR');
    logMsg("-------------------------------", 'DEBUG');
    exit(1);
}
