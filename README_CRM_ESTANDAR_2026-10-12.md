# Aidora CRM · Ajustes estándar 2026-10-12

## Agregado
- Customer Journey de 4 etapas en Dashboard.
- Segmentos de Customer Journey en Mensajes.
- Buscador por nombre o WhatsApp para envío individual.
- Mi perfil: nombre, apellidos, correo, fotografía y cambio de contraseña.
- Usuarios: creación/edición, estado activo, rol y permisos Ver/Editar por módulo.
- Control de permisos en navegación y acciones sensibles.
- Customer Journey se sincroniza también desde `bot-api.php`.

## Regla de Customer Journey adaptada a Aidora
- Prospecto: 1 o 2 consultas y sin registros aprobados.
- Prospecto recurrente: 3 o más consultas y sin registros aprobados.
- Cliente: 1 a 3 registros aprobados.
- Cliente recurrente: 4 o más registros aprobados.

## Instalación sobre una web existente
1. Ejecutar primero `database/aidora_update_crm_estandar_2026-10-12.sql` en `crmcamar_aidora`.
2. Subir el PATCH y reemplazar los archivos indicados.
3. Conservar el `back/config/config.local.php` que ya funciona en el servidor.
4. Verificar que `uploads/profiles/` tenga permisos de escritura para PHP (normalmente 755/775 según el hosting).

No se requiere deploy de Firebase para estos cambios de CRM.
