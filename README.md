# Fichajes Automáticos con Microsoft Graph API (Teams Shifts)

Este proyecto en PHP permite realizar fichajes automáticos (entrada, salida y descansos) mediante la API de Microsoft Graph, específicamente el módulo de **Shifts de Microsoft Teams**. Está diseñado para ejecutarse mediante `cron` cada 30 minutos, gestionando correctamente el estado del turno y los descansos según el horario configurado.

## 🧩 Estructura del Proyecto

- `api.php`: Punto de entrada del script. Controla la lógica principal para decidir qué acción realizar (clockIn, startBreak, endBreak, clockOut).
- `config.php`: Contiene parámetros de configuración como el token de acceso, IDs de Teams, y márgenes de tolerancia.
- `functions.php`: Funciones auxiliares para interactuar con la API de Microsoft Graph y manejar los estados.

## 📦 Requisitos

- PHP 7.4 o superior
- Extensión `curl` habilitada
- Acceso válido a la API de Microsoft Graph con permisos para `Shifts`, `TimeCard`, `Schedule.ReadWrite.All`
- Un sistema operativo con soporte para `cron` (si se desea automatizar)

## ⚙️ Instalación

1. Clona o descarga este repositorio en tu servidor:

   ```bash
   git clone https://github.com/tuusuario/fichajes-teams.git
   cd fichajes-teams
   ```

2. Configura `config.php` con los siguientes datos:

   - `ACCESS_TOKEN`: Token OAuth 2.0 con permisos necesarios
   - `TEAM_ID`: ID del equipo de Microsoft Teams
   - `USER_ID`: ID del usuario a fichar
   - `MARGEN_ANTES` y `MARGEN_DESPUES`: Margen de tiempo en minutos para ejecutar acciones automáticas

3. Asegúrate de que `cron` puede ejecutar el script PHP correctamente (ver siguiente sección).

## ⏱️ Automatización con Cron

Agrega una tarea en `crontab -e` para que el script se ejecute cada 30 minutos:

```cron
*/30 * * * * /usr/bin/php /ruta/a/tu/proyecto/api.php >> /ruta/a/tu/logs/fichajes.log 2>&1
```

🔁 El script es inteligente:

- Si el usuario tiene un turno hoy, realiza `clockIn` si aún no ha fichado.
- Inicia o termina descansos dependiendo de los datos del turno.
- Realiza `clockOut` si ya terminó el turno.

## 💾 Persistencia de Estado

El sistema guarda el `timeCardId` y el estado de los descansos por usuario en un archivo JSON o array local (dependiendo de tu implementación), para tomar decisiones en futuras ejecuciones.

## 🔐 Seguridad

El `ACCESS_TOKEN` debe mantenerse en secreto. Se recomienda almacenarlo en un sistema de secretos o actualizarlo mediante una tarea cron si es temporal.

## 📘 Referencias

- [Documentación de Microsoft Graph - Shifts](https://learn.microsoft.com/en-us/graph/api/resources/schedule?view=graph-rest-1.0)
- [Autenticación con Microsoft Graph](https://learn.microsoft.com/en-us/graph/auth-v2-user)

## 🧑‍💻 Autor

Desarrollado por Nicolás Javier Martinez.
Contacto: [nicojmb@gmail.com]
