# Fichajes automáticos en Teams Shifts (Microsoft Graph API)

Este proyecto en PHP permite realizar fichajes automáticos (entrada, salida y descansos) mediante la API de Microsoft Graph, específicamente el módulo de **Shifts de Microsoft Teams**.
Está diseñado para ejecutarse mediante `cron` cada 10 minutos, gestionando correctamente el estado del turno y los descansos según el horario configurado.

## 🧩 Estructura del Proyecto

- `public/index.php`: Punto de entrada del script, controla la lógica principal para decidir qué acción realizar (clockIn, startBreak, endBreak, clockOut).
- `src/config.php`: Contiene parámetros de configuración como el token de acceso, IDs de Teams y márgenes de tolerancia.
- `src/functions.php`: Funciones auxiliares para interactuar con la API de Microsoft Graph y manejar los estados.
- `src/api.php`: Contiene las funciones para llamar al API de Microsoft para cada tipo de acción.
- `.env`: Archivo a crear con las variables de entorno necesarias para la ejecución del script.
- `logs/`: Carpeta donde se guardan los logs de ejecución del script.
- `data/`: Carpeta donde se guardan el estado del TimeCard.

## ✅ Requisitos

- PHP 8 o superior
- Extensión `curl` habilitada
- Acceso válido a la API de Microsoft Graph con permisos para `Shifts`, `TimeCard`, `Schedule.ReadWrite.All` mediante una aplicación en EntraID.
- Un sistema operativo con soporte para `cron` (si se desea automatizar)

## ⚙️ Instalación

1. Clona o descarga este repositorio en tu servidor:

   ```bash
   git clone https://github.com/nicojmb/teams-shifts-automatic-clockin
   cd teams-shifts-automatic-clockin
   ```

2. Crea un archivo `.env` con las siguientes variables:

   ```dotenv
   CLIENT_ID=
   CLIENT_SECRET=
   TENANT_ID=
   USER_ID=
   TEAM_ID=
   ```

3. Prueba el resultado ejecutando:

   ```bash
   php public/index.php
   ```

   - Si todo está correcto, deberías ver un mensaje indicando que el script se ejecutó correctamente.
   - Si hay errores, revisa los logs generados en la carpeta `logs` para más detalles.

   > **Nota:** Este archivo solo se puede ejecutar desde la línea de comandos, no desde un navegador web. Si el script se ejecuta desde un navegador, no se ejecutará correctamente y mostrará un error.

4. Si deseas automatizarlo, crea una tarea en `cron` ejecutando el script PHP (ver siguiente sección).

## ⏱️ Automatización con Cron

Agrega una tarea en `crontab -e` para que el script se ejecute cada 10 minutos:

```cron
*/10 * * * * /usr/bin/php /ruta/a/teams-shifts-automatic-clockin/public/index.php >> /ruta/a/logs/teams-shifts.log 2>&1
```

El script es inteligente:

- Si el usuario tiene un turno hoy y no ha fichado, realiza `clockIn`.
- Inicia o termina descansos dependiendo de los datos del turno.
- Realiza `clockOut` si ya terminó el turno.

## 🧠 Optimización de Consultas

Para evitar consultas innecesarias a la API de Microsoft Graph, el script implementa un sistema de caché local que guarda el estado de los turnos:

- **Consulta diaria:** El script verifica si ya se ha consultado el turno para el día actual. Si ya se ha consultado y no es necesario fichar, no realiza una nueva consulta.
- **Estado de fichaje:** Si ya se ha fichado y no es necesario realizar ninguna acción, el script no realiza consultas adicionales.

Estas optimizaciones mejoran el rendimiento y reducen el número de llamadas a la API.

## 💾 Persistencia de Estado

El sistema guarda el `timeCardId` y el estado de los descansos por usuario en un archivo JSON o array local (dependiendo de tu implementación), para tomar decisiones en futuras ejecuciones.

## 🔐 Seguridad

El `ACCESS_TOKEN` debe mantenerse en secreto. Se recomienda almacenarlo en un sistema de secretos o actualizarlo mediante una tarea cron si es temporal.

## 📚 Referencias

- [Documentación de Microsoft Graph - Shifts](https://learn.microsoft.com/en-us/graph/api/resources/schedule?view=graph-rest-1.0)
- [Autenticación con Microsoft Graph](https://learn.microsoft.com/en-us/graph/auth-v2-user)

## 👤 Autor

Desarrollado por Nicolás Javier Martinez | @nicojmb - nicojmb@gmail.com
