# 🟢 SENA - Sistema de Seguimiento de Aprendices
**Versión 1.0** | PHP 8.0+ | MySQL 5.7+ / MariaDB 10.3+

---

## 📋 Descripción

Sistema web para la Coordinación Académica del SENA que permite llevar control
detallado de aprendices con resultados de aprendizaje o competencias pendientes,
sus acciones remediales y la trazabilidad completa del proceso formativo.

**Soluciona:**
- Desarticulación entre gestores e instructores de apoyo
- Falta de registro centralizado de pendientes
- Ausencia de seguimiento a acciones remediales
- Llegada tardía de aprendices al comité sin historial

---

## 🚀 Instalación

### Requisitos
- PHP 8.0 o superior (extensiones: PDO, PDO_MySQL, mbstring)
- MySQL 5.7+ o MariaDB 10.3+
- Servidor web: Apache / Nginx / XAMPP / WAMP / Laragon

### Paso 1 – Crear la base de datos
```sql
-- Opción A: desde línea de comandos MySQL
mysql -u root -p < database.sql

-- Opción B: desde phpMyAdmin
-- Importar el archivo database.sql
```

### Paso 2 – Configurar la conexión
Editar el archivo `config/database.php`:
```php
define('DB_HOST', 'localhost');   // Host de tu servidor MySQL
define('DB_USER', 'root');        // Tu usuario MySQL
define('DB_PASS', '');            // Tu contraseña MySQL
define('DB_NAME', 'sena_aprendices');
```

### Paso 3 – Copiar al servidor web
```
# Para XAMPP:
Copiar la carpeta sena_aprendices/ a: C:/xampp/htdocs/

# Para WAMP:
Copiar la carpeta sena_aprendices/ a: C:/wamp64/www/

# Para Laragon:
Copiar la carpeta sena_aprendices/ a: C:/laragon/www/
```

### Paso 4 – Acceder al sistema
```
http://localhost/sena_aprendices/
```

---

## 🔐 Credenciales por defecto

| Usuario       | Contraseña | Rol           |
|---------------|------------|---------------|
| `admin`       | `password` | Administrador |
| `coordinador` | `password` | Coordinador   |

> ⚠️ **Cambiar las contraseñas inmediatamente después del primer acceso.**
> Las contraseñas se almacenan con hash bcrypt (password_hash).

Para cambiar una contraseña, ejecutar en MySQL:
```sql
UPDATE usuarios
SET password_hash = '$2y$12$NUEVO_HASH_AQUI'
WHERE username = 'admin';
```
O usar PHP para generar el hash:
```php
echo password_hash('NuevaContraseña123', PASSWORD_DEFAULT);
```

---

## 📁 Estructura del Proyecto

```
sena_aprendices/
│
├── index.php               ← Login
├── logout.php              ← Cierre de sesión
├── database.sql            ← Script SQL completo
│
├── config/
│   └── database.php        ← Configuración BD y constantes
│
├── includes/
│   ├── auth.php            ← Autenticación y sesiones
│   ├── header.php          ← Layout: cabecera + sidebar
│   └── footer.php          ← Layout: pie de página
│
├── pages/
│   ├── dashboard.php       ← Panel principal con estadísticas
│   ├── aprendices.php      ← CRUD aprendices
│   ├── pendientes.php      ← CRUD pendientes de competencias
│   ├── acciones.php        ← CRUD acciones remediales
│   ├── comite.php          ← Gestión de comité académico
│   ├── instructores.php    ← CRUD instructores
│   ├── fichas.php          ← CRUD fichas/grupos
│   └── reportes.php        ← Reportes y estadísticas
│
├── ajax/
│   └── resultados.php      ← Endpoint: resultados por competencia
│
└── assets/
    ├── css/
    │   └── main.css        ← Estilos SENA (verde #39A900)
    └── js/
        └── main.js         ← JavaScript general
```

---

## 🗄️ Modelo de Datos

```
programas ──┐
            ├── fichas ── aprendices ──── pendientes_aprendices
competencias┘               │                    │
    │                       │              acciones_remediales
resultados_aprendizaje      │
                            └── comite_aprendices
instructores ──────────────────────────────────────────────────
usuarios (acceso al sistema)
```

---

## 🛡️ Roles de Usuario

| Rol           | Acceso                                              |
|---------------|-----------------------------------------------------|
| Administrador | Acceso completo + gestión de usuarios               |
| Coordinador   | Acceso completo + reportes                          |
| Gestor        | Aprendices, pendientes, acciones, comité            |
| Instructor    | Solo lectura en pendientes y acciones               |

---

## ✅ Funcionalidades

- [x] Login con sesiones seguras y timeout
- [x] Dashboard con estadísticas en tiempo real
- [x] CRUD completo de aprendices con búsqueda y paginación
- [x] Registro de pendientes (competencia/resultado) por aprendiz
- [x] Historial de acciones remediales con tipo, fecha y resultado
- [x] Control de aprendices remitidos a comité
- [x] Gestión de instructores y fichas
- [x] Reportes: top aprendices en riesgo, competencias críticas
- [x] Indicador de nivel de riesgo por aprendiz
- [x] Diseño responsive (móvil y escritorio)
- [x] Paleta oficial SENA (verde #39A900 y blanco)

---

## 🔧 Personalización

**Cambiar colores SENA** (si se actualiza la marca):
```css
/* assets/css/main.css — línea 1 */
:root {
    --verde:      #39A900;   ← Color principal SENA
    --verde-dark: #2a7d00;   ← Hover / dark
    --verde-light:#5abf1a;   ← Light / accent
}
```

**Agregar nuevos programas y competencias:**
```sql
INSERT INTO programas (nombre, codigo, nivel, duracion_trimestres)
VALUES ('Nombre del Programa', '123456', 'Técnico', 4);

INSERT INTO competencias (nombre, codigo, programa_id, trimestre)
VALUES ('Nombre de la Competencia', 'COD001', 1, 1);
```

---

## 📞 Soporte

Desarrollado para el SENA — Servicio Nacional de Aprendizaje
Sistema de Seguimiento Académico v1.0
