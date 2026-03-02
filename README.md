# Plugin Solicitud — GLPI 11.x

Plugin para GLPI 11.0.5 que gestiona solicitudes enviadas mediante tickets,
con aprobación o rechazo vía email **sin necesidad de que el aprobador inicie sesión**.

---

## Estructura de carpetas

```
solicitud/
├── setup.php                     ← Metadatos, constantes, hooks
├── hook.php                      ← install/uninstall + hook item_add Ticket
├── inc/
│   ├── solicitud.class.php       ← Clase principal (lógica de aprobación)
│   ├── approvaltoken.class.php   ← CRUD de tokens en BD
│   ├── config.class.php          ← Configuración del plugin
│   └── mail.php                  ← Envío de emails + plantilla HTML
├── front/
│   └── approval.php              ← Endpoint público (approve / reject)
└── install/
    ├── install.sql               ← DDL de instalación
    └── uninstall.sql             ← DDL de desinstalación
```

---

## Instalación

### 1. Copiar el plugin

```bash
cp -r solicitud/ /var/www/html/glpi/plugins/solicitud/
chown -R www-data:www-data /var/www/html/glpi/plugins/solicitud/
```

> El nombre de la carpeta **debe ser exactamente** `solicitud`.

### 2. Activar desde GLPI

1. Entrar a GLPI → **Configuración → Plugins**.
2. Localizar **Solicitud de Aprobación**.
3. Hacer clic en **Instalar** y después en **Activar**.

GLPI ejecutará automáticamente `plugin_solicitud_install()` de `hook.php`,
que crea las tablas `glpi_plugin_solicitud_tokens` y `glpi_plugin_solicitud_configs`.

### 3. Configurar el plugin

Editar la fila de configuración en la base de datos (o crear un formulario de admin):

```sql
UPDATE glpi_plugin_solicitud_configs SET
    category_name  = 'Solicitud de Alta de Mail',   -- Nombre exacto de la categoría GLPI
    approver_email = 'directivo@empresa.com',         -- Email del aprobador
    it_email       = 'it@empresa.com',                -- Email del área IT
    glpi_base_url  = 'https://glpi.tuempresa.com'     -- URL base de tu GLPI (sin /final)
WHERE id = 1;
```

### 4. Configurar notificaciones en GLPI

GLPI → **Configuración → Notificaciones** → **Configuración de correos electrónicos**:
- Asegurarse de que el servidor SMTP esté configurado (host, puerto, usuario, contraseña).
- El plugin usa esas mismas credenciales a través de `GLPIMailer`.

### 5. Crear la categoría en GLPI

GLPI → **Asistencia → Categorías de incidentes/solicitudes**:
- Crear una categoría con el nombre exacto configurado en el paso 3
  (por defecto: **Solicitud de Alta de Mail**).

---

## Flujo completo

```
Usuario crea ticket con categoría "Solicitud de Alta de Mail"
        │
        ▼
hook item_add dispara plugin_solicitud_ticket_created()
        │
        ├─ Genera token único (64 chars hex)
        ├─ Lo guarda en glpi_plugin_solicitud_tokens (status=pending)
        ├─ Envía email HTML al directivo con botones Aprobar/Rechazar
        └─ Agrega seguimiento al ticket: "Solicitud enviada..."
        │
        ▼
Directivo recibe el email y hace clic en un botón
        │
        ▼
GET /plugins/solicitud/front/approval.php?token=XXX&action=approve
        │
        ├─ Valida token (existe, está en pending)
        ├─ Actualiza token → approved/rejected
        ├─ Cambia estado del Ticket (SOLVED si aprobado / CLOSED si rechazado)
        ├─ Agrega seguimiento: "Solicitud APROBADA/RECHAZADA por directivo..."
        ├─ Envía email al área IT notificando la decisión
        └─ Muestra página HTML de confirmación al directivo
```

---

## Probar el flujo completo

### Paso 1 — Crear un ticket de prueba

1. Ingresar a GLPI con cualquier usuario.
2. Crear un ticket con:
   - **Tipo:** Solicitud
   - **Categoría:** Solicitud de Alta de Mail
   - **Título:** Solicitud de alta email para Juan Pérez

### Paso 2 — Verificar el registro del token

```sql
SELECT * FROM glpi_plugin_solicitud_tokens ORDER BY id DESC LIMIT 1;
```

Debería aparecer un registro con `status = pending` y el token generado.

### Paso 3 — Simular el clic del directivo (sin email)

Construir la URL manualmente:

```
https://glpi.tuempresa.com/plugins/solicitud/front/approval.php?token=<TOKEN_DE_BD>&action=approve
```

Abrirla en el navegador. Debe mostrar la página de confirmación verde.

### Paso 4 — Verificar resultado

```sql
-- Token actualizado
SELECT id, tickets_id, status, date_action FROM glpi_plugin_solicitud_tokens;

-- Seguimientos del ticket
SELECT id, content, date_creation FROM glpi_plugin_solicitud_followups
WHERE items_id = <ID_TICKET>;

-- Estado del ticket
SELECT id, status FROM glpi_tickets WHERE id = <ID_TICKET>;
-- status=5 → SOLVED (aprobado)  |  status=6 → CLOSED (rechazado)
```

---

## Constantes de estado de Ticket en GLPI 11

| Constante PHP         | Valor | Significado |
|-----------------------|-------|-------------|
| `Ticket::INCOMING`    | 1     | Nuevo       |
| `Ticket::ASSIGNED`    | 2     | En curso    |
| `Ticket::PLANNED`     | 3     | Planificado |
| `Ticket::WAITING`     | 4     | En espera   |
| `Ticket::SOLVED`      | 5     | Resuelto ✔  |
| `Ticket::CLOSED`      | 6     | Cerrado ✘   |

---

## Compatibilidad

| Requisito | Versión mínima |
|-----------|---------------|
| GLPI      | 11.0.0        |
| PHP       | 8.0           |
| MySQL     | 5.7 / MariaDB 10.3 |
| Apache    | 2.4           |

---

## Consideraciones de seguridad (futuras mejoras)

- Agregar expiración al token (columna `expires_at`).
- Validar HMAC con clave secreta en el parámetro de la URL.
- Limitar intentos de acceso al endpoint por IP.
- Forzar HTTPS en el servidor Apache.

<?php
require_once __DIR__ . '/inc/approvaltoken.class.php';
require_once __DIR__ . '/inc/config.class.php';
require_once __DIR__ . '/inc/mail.php';
require_once __DIR__ . '/inc/solicitud.class.php';
