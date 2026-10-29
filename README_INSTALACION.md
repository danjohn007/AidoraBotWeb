# Aidora CRM · Instalación

1. Importa `database/aidora.sql` en la BD `crmcamar_aidora`.
2. Sube el contenido de esta carpeta a `/public_html/aidora/`.
3. `back/config/config.local.php` ya está preparado con la conexión MySQL indicada.
4. Según el `.firebaserc` recibido, el bot apunta al proyecto Firebase `chatbotgordo`. Si el bot real está desplegado en otro proyecto, cambia `firebase_crm_url` en `config.local.php` por la URL real de `crmSendAidora`.
5. Configura `WEB_ADMIN_KEY_AIDORABOT` con el valor incluido en `CREDENCIALES_INICIALES.txt`.
6. Despliega las funciones `whatsappWebhookAidoraBot` y `crmSendAidora`.

La web usa la plantilla `aidora_plantilla`, idioma `es_MX`, colocando el texto escrito en `{{1}}`.
