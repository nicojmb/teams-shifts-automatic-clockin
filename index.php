<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'api.php';

try
{
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

    // --- Lógica Principal ---
    $shift = getShiftForUserAndDay($now);

    if (empty($shift) || !isset($shift['sharedShift']))
    {
        logMsg("No se encontraron turnos aplicables para el usuario " . USER_ID . " para la fecha " . $now->format('Y-m-d') . ".", 'INFO');
        if ($currentState['lastAction'] === 'clocked_in' || $currentState['lastAction'] === 'break_started')
        {
            logMsg("Usuario fichado ('{$currentState['lastAction']}') pero no se encontró turno. Se evaluará salida por defecto si es necesario.", 'WARNING');
            // Potencial lógica de auto-clock-out si es apropiado y ha pasado mucho tiempo
            $lastActionTime = new DateTime($currentState['lastActionTimestamp'] ?? '1970-01-01', $timezone);
            $hoursSinceLastAction = ($now->getTimestamp() - $lastActionTime->getTimestamp()) / 3600;
            if ($hoursSinceLastAction > (defined('MAX_SHIFT_HOURS_FALLBACK') ? MAX_SHIFT_HOURS_FALLBACK : 12))
            { // Ej: 12 horas por defecto
                if ($currentState['activeTimeCardId'])
                {
                    logMsg("⚠️ Forzando Clock-Out por ausencia de turno y tiempo prolongado (más de " . ($hoursSinceLastAction) . "h desde '{$currentState['lastAction']}').", 'WARNING');
                    doClockOut($currentState['activeTimeCardId']);
                    updateState('clocked_out', $currentState['activeTimeCardId'], $currentState, $now);
                    saveData($currentState);
                    logMsg("✅ Clock-Out (por ausencia de turno/inactividad) realizado.", 'SUCCESS');
                }
            }
        }
        logMsg("El proceso ha finalizado. No hay turnos activos y/o el usuario no está fichado o ya se ha gestionado.", 'INFO');
        exit;
    }

    $sharedShift = $shift['sharedShift'];
    $shiftId = $shift['id'];

    $shiftStart = (new DateTime($sharedShift['startDateTime'], new DateTimeZone('UTC')))->setTimezone($timezone);
    $shiftEnd = (new DateTime($sharedShift['endDateTime'], new DateTimeZone('UTC')))->setTimezone($timezone);

    logMsg("Turno encontrado: " . ($sharedShift['displayName'] ?? 'Sin nombre') .
        " (ID: $shiftId, Inicia: " . $shiftStart->format('Y-m-d H:i') .
        ", Fin: " . $shiftEnd->format('Y-m-d H:i') . ")", 'INFO');

    $breaks = getBreaksFromShift($sharedShift);
    if (!empty($breaks))
    {
        logMsg("Descansos encontrados (" . count($breaks) . "):", 'INFO');
        foreach ($breaks as $idx => $break)
        {
            logMsg("---> Descanso " . ($idx + 1) . ": " . $break['displayName'] .
                " (Inicio: " . $break['startDateTime']->format('H:i') .
                ", Fin: " . $break['endDateTime']->format('H:i') . ")", 'INFO');
        }
    }

    $timeCardId = $currentState['activeTimeCardId'];
    $lastAction = $currentState['lastAction'];
    $actionMarginInterval = new DateInterval('PT' . ACTION_MARGIN_MINUTES . 'M');

    // --- LÓGICA DE ACCIONES ---

    // 1. Clock-In
    // Solo si el turno actual es el correcto y no estamos ya fichados en él.
    if ($now >= $shiftStart && $now < $shiftEnd)
    {
        if (empty($lastAction) || $lastAction === 'clocked_out' || $currentState['currentShiftId'] !== $shiftId)
        {
            logMsg("Intentando Clock-In para el turno: " . ($sharedShift['displayName'] ?? $shiftId), 'INFO');
            $newTimeCardId = doClockIn();
            updateState('clocked_in', $newTimeCardId, $currentState, $now, $shiftId);
            $timeCardId = $newTimeCardId; // Actualizar $timeCardId localmente para las siguientes acciones
            logMsg("✅ Clock-In realizado. TimeCardID: $timeCardId", 'SUCCESS');
        }
    }

    // Lógica para Descansos (solo si está 'clocked_in' en el turno actual)
    if ($timeCardId && $currentState['lastAction'] === 'clocked_in' && $currentState['currentShiftId'] === $shiftId && !empty($breaks))
    {
        foreach ($breaks as $break)
        {
            $breakStartBoundary = (clone $break['startDateTime'])->sub($actionMarginInterval); // Podemos empezar un poco antes
            $breakEndBoundary = (clone $break['endDateTime'])->add($actionMarginInterval); // Podemos estar un poco pasados

            if ($now >= $breakStartBoundary && $now < $break['endDateTime'])
            { // Dentro del rango para iniciar este descanso
                logMsg("Intentando Iniciar Descanso: " . $break['displayName'], 'INFO');
                doStartBreak($timeCardId, $break['displayName']);
                updateState('break_started', $timeCardId, $currentState, $now, $shiftId, $break['displayName']);
                logMsg("✅ Inicio de Descanso '" . $break['displayName'] . "' realizado.", 'SUCCESS');
                break;
            }
        }
    }
    // Lógica para Terminar Descanso (solo si está 'break_started' en el turno actual)
    else if ($timeCardId && $currentState['lastAction'] === 'break_started' && $currentState['currentShiftId'] === $shiftId && !empty($breaks))
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
            $breakEndBoundary = (clone $currentActiveBreak['endDateTime'])->sub($actionMarginInterval); // Podemos terminar un poco antes
            $maxTimeToEndBreak = (clone $currentActiveBreak['endDateTime'])->add($actionMarginInterval); // Límite para terminar

            if ($now >= $breakEndBoundary && $now <= $maxTimeToEndBreak)
            {
                logMsg("Intentando Finalizar Descanso: " . $currentActiveBreak['displayName'], 'INFO');
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
            // Asumir que el descanso terminó o es inválido, volver a estado 'clocked_in'
            updateState('clocked_in', $timeCardId, $currentState, $now, $shiftId); // O 'break_ended' si se prefiere y luego que el clockout lo gestione
        }
    }

    // 2. Clock-Out
    // Solo si está 'clocked_in' o 'break_ended' en el turno actual
    if ($timeCardId && ($currentState['lastAction'] === 'clocked_in' || $currentState['lastAction'] === 'break_ended') && $currentState['currentShiftId'] === $shiftId)
    {
        $shiftEndBoundary = (clone $shiftEnd)->sub($actionMarginInterval); // Podemos fichar salida un poco antes
        $maxTimeToClockOut = (clone $shiftEnd)->add(new DateInterval('PT60M')); // Límite para fichar salida (ej. 1h después)

        if ($now >= $shiftEndBoundary && $now <= $maxTimeToClockOut)
        {
            logMsg("Intentando Clock-Out para el turno: " . ($sharedShift['displayName'] ?? $shiftId), 'INFO');
            doClockOut($timeCardId);
            updateState('clocked_out', $timeCardId, $currentState, $now); // $shiftId se limpia en updateState
            logMsg("✅ Clock-Out realizado. TimeCardID: $timeCardId", 'SUCCESS');
        }
        elseif ($now > $maxTimeToClockOut)
        {
            logMsg("Forzando Clock-Out (tiempo de turno excedido considerablemente): " . ($sharedShift['displayName'] ?? $shiftId), 'WARNING');
            doClockOut($timeCardId);
            updateState('clocked_out', $timeCardId, $currentState, $now);
            logMsg("✅ Clock-Out (forzado) realizado. TimeCardID: $timeCardId", 'SUCCESS');
        }
    }
    saveData($currentState);
}
catch (Exception $e)
{
    logMsg("❌ ERROR CRÍTICO EN EL SCRIPT: " . $e->getMessage() . "\n" . $e->getTraceAsString(), 'CRITICAL');
}
finally
{
    logMsg("El proceso ha finalizado.", 'INFO');
    logMsg(str_repeat("-", 30), 'INFO');
}
