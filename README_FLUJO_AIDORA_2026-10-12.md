# Aidora CRM + Bot · Flujo Aidor(a)

## Qué se agregó

La web ahora administra el flujo completo de registro de Aidores:

- Dashboard con registros, pendientes de revisión y solicitudes de asesor.
- Registros Aidores con búsqueda, filtros, estado y vista de detalle.
- En el detalle se muestran todos los datos capturados por el bot: perfil, nombre, edad, teléfono, WhatsApp, correo, ciudad, zona, estudios, formación, experiencia, actividades, disponibilidad, días, movilidad, distancia y respuestas de motivación.
- Solicitudes para hablar con un asesor.
- Contactos, conversaciones y mensajes por plantilla.
- Segmentos de mensajes por estado del registro.

El número de WhatsApp NO se pregunta al usuario: se toma automáticamente del `messages[].from` recibido por Meta y se guarda en Contactos y en el registro del Aidor(a).

## Comunicación Firebase -> MySQL

Como MySQL usa `localhost` en cPanel, Firebase no intenta conectarse directamente a MySQL. El webhook usa:

`https://crmcamaradecomercio.com.mx/aidora/bot-api.php`

La API está protegida con el mismo valor de `WEB_ADMIN_KEY_AIDORABOT` que utiliza `crmSendAidora`.

## Base de datos

Si ya instalaste la primera versión del CRM, ejecuta solamente:

`aidora_update_flujo_2026-10-12.sql`

Si la base está vacía, importa:

`database/aidora.sql`

## Bot

Reemplaza `functions/index.js` y despliega:

```powershell
firebase deploy --only functions:whatsappWebhookAidoraBot,functions:crmSendAidora --project chatbotgordo
```

Secrets utilizados:

- `VERIFY_TOKEN_AIDORABOT`
- `WHATSAPP_TOKEN_AIDORABOT`
- `WHATSAPP_PHONE_NUMBER_ID_AIDORABOT`
- `WEB_ADMIN_KEY_AIDORABOT`

No cambies el valor de `WEB_ADMIN_KEY_AIDORABOT` sin cambiar también `firebase_admin_key` en `back/config/config.local.php`.
