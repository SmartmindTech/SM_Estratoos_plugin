# SmartMind - Estratoos Plugin: Documentación Técnica Completa

> **Versión:** 1.7.21
> **Última actualización:** Enero 2025
> **Plataforma:** Moodle 4.1+ / IOMAD 5.0+

---

## Índice

1. [Descripción General](#1-descripción-general)
2. [Arquitectura del Sistema](#2-arquitectura-del-sistema)
3. [Detección IOMAD vs Moodle Estándar](#3-detección-iomad-vs-moodle-estándar)
4. [Flujo de Creación de Tokens](#4-flujo-de-creación-de-tokens)
5. [Sistema de Restricciones por Empresa](#5-sistema-de-restricciones-por-empresa)
6. [Filtrado de Respuestas API](#6-filtrado-de-respuestas-api)
7. [Control de Acceso y Permisos](#7-control-de-acceso-y-permisos)
8. [Servicios Web (API)](#8-servicios-web-api)
9. [Páginas y Puntos de Entrada](#9-páginas-y-puntos-de-entrada)
10. [Base de Datos](#10-base-de-datos)
11. [Troubleshooting](#11-troubleshooting)

---

## 1. Descripción General

### ¿Qué es el Plugin?

El **SmartMind - Estratoos Plugin** es un plugin local de Moodle que proporciona:

- **Creación masiva de tokens API** con filtrado por empresa para entornos multi-tenant IOMAD
- **API comprensiva de contenido de cursos** para consumo externo (SCORM, Quiz, Assignment, Lesson, etc.)
- **Filtrado de datos por empresa** para garantizar aislamiento de datos en entornos multi-tenant
- **Compatibilidad dual** con instalaciones IOMAD y Moodle estándar

### Diagrama de Alto Nivel

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           SMARTMIND ESTRATOOS PLUGIN                         │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────────┐    ┌──────────────────┐    ┌──────────────────┐      │
│  │   Dashboard UI   │    │  Token Manager   │    │  API Services    │      │
│  │   (index.php)    │───▶│  (company_token  │───▶│  (db/services)   │      │
│  │                  │    │   _manager.php)  │    │                  │      │
│  └──────────────────┘    └──────────────────┘    └──────────────────┘      │
│           │                       │                       │                 │
│           ▼                       ▼                       ▼                 │
│  ┌──────────────────┐    ┌──────────────────┐    ┌──────────────────┐      │
│  │   Access Control │    │   IOMAD/Standard │    │ Webservice Filter│      │
│  │   (util.php)     │◀──▶│   Detection      │◀──▶│ (webservice_     │      │
│  │                  │    │                  │    │  filter.php)     │      │
│  └──────────────────┘    └──────────────────┘    └──────────────────┘      │
│           │                       │                       │                 │
│           └───────────────────────┼───────────────────────┘                 │
│                                   ▼                                         │
│                    ┌──────────────────────────┐                             │
│                    │     Base de Datos        │                             │
│                    │  • external_tokens       │                             │
│                    │  • local_sm_estratoos_*  │                             │
│                    │  • company_* (IOMAD)     │                             │
│                    └──────────────────────────┘                             │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Arquitectura del Sistema

### Componentes Principales

| Componente | Archivo | Responsabilidad |
|------------|---------|-----------------|
| **Token Manager** | `classes/company_token_manager.php` | CRUD de tokens, creación por lotes, validación |
| **Webservice Filter** | `classes/webservice_filter.php` | Filtrado de respuestas API por empresa |
| **Utilidades** | `classes/util.php` | Detección IOMAD, control de acceso, gestión de empresas |
| **Hooks** | `lib.php` | Pre/post procesadores, navegación, integración Moodle |
| **Dashboard** | `index.php` | Interfaz principal de administración |
| **Definiciones API** | `db/services.php` | Funciones web service disponibles |
| **Capacidades** | `db/access.php` | Permisos y roles |

### Estructura de Directorios

```
SM_Estratoos_plugin/
├── classes/
│   ├── company_token_manager.php   # Gestión de tokens
│   ├── webservice_filter.php       # Filtrado por empresa
│   ├── util.php                    # Utilidades generales
│   ├── external/                   # Funciones API externas
│   │   ├── get_course_content.php  # API de contenido de cursos
│   │   ├── create_batch_tokens.php # Creación masiva de tokens
│   │   └── ...
│   └── form/
│       └── batch_token_form.php    # Formulario de creación por lotes
├── db/
│   ├── install.php                 # Script de instalación
│   ├── upgrade.php                 # Scripts de actualización
│   ├── services.php                # Definiciones de servicios web
│   └── access.php                  # Capacidades
├── lang/
│   ├── en/                         # Idioma inglés
│   ├── es/                         # Idioma español
│   └── pt_br/                      # Idioma portugués
├── amd/                            # JavaScript AMD modules
├── index.php                       # Dashboard principal
├── company_access.php              # Gestión de acceso por empresa
├── batch_token.php                 # Página de creación por lotes
└── version.php                     # Versión del plugin
```

---

## 3. Detección IOMAD vs Moodle Estándar

### Diagrama de Decisión

```
                    ┌─────────────────────────┐
                    │   Solicitud entrante    │
                    └───────────┬─────────────┘
                                │
                                ▼
                    ┌─────────────────────────┐
                    │ ¿Existe directorio      │
                    │ /local/iomad?           │
                    └───────────┬─────────────┘
                                │
                    ┌───────────┴───────────┐
                    │NO                     │SÍ
                    ▼                       ▼
        ┌───────────────────┐   ┌─────────────────────────┐
        │  MODO ESTÁNDAR    │   │ ¿Existe tabla 'company' │
        │  (Non-IOMAD)      │   │ en la base de datos?    │
        └───────────────────┘   └───────────┬─────────────┘
                                            │
                                ┌───────────┴───────────┐
                                │NO                     │SÍ
                                ▼                       ▼
                    ┌───────────────────┐   ┌─────────────────────────┐
                    │  MODO ESTÁNDAR    │   │ ¿Existe al menos una    │
                    │  (Non-IOMAD)      │   │ empresa registrada?     │
                    └───────────────────┘   └───────────┬─────────────┘
                                                        │
                                            ┌───────────┴───────────┐
                                            │NO                     │SÍ
                                            ▼                       ▼
                                ┌───────────────────┐   ┌───────────────────┐
                                │  MODO ESTÁNDAR    │   │    MODO IOMAD     │
                                │  (Non-IOMAD)      │   │  (Multi-tenant)   │
                                └───────────────────┘   └───────────────────┘
```

### Código de Detección

```php
// Ubicación: classes/util.php - método is_iomad_installed()

public static function is_iomad_installed(): bool {
    global $CFG, $DB;

    static $isiomad = null;

    if ($isiomad !== null) {
        return $isiomad;  // Cache del resultado
    }

    // Verificación 1: ¿Existe el directorio de IOMAD?
    if (!file_exists($CFG->dirroot . '/local/iomad')) {
        $isiomad = false;
        return false;
    }

    // Verificación 2: ¿Existe la tabla 'company'?
    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('company')) {
        $isiomad = false;
        return false;
    }

    // Verificación 3: ¿Hay al menos una empresa?
    $isiomad = $DB->record_exists('company', []);
    return $isiomad;
}
```

### Diferencias según el Modo

| Aspecto | Modo IOMAD | Modo Estándar |
|---------|------------|---------------|
| **Contexto del token** | Categoría de la empresa | Contexto del sistema |
| **Nombre del token** | `NOMBRE_APELLIDO_EMPRESA` | `NOMBRE_APELLIDO_ROL` |
| **Validación de usuario** | Vía tabla `company_users` | Sin validación adicional |
| **Filtrado de API** | Por `company_course`, `company_users` | Sin filtrado (todos los resultados) |
| **Gestión de empresas** | Habilitar/deshabilitar por empresa | No aplica |
| **Origen de usuarios** | CSV/Excel con validación de empresa | CSV/Excel sin validación |

---

## 4. Flujo de Creación de Tokens

### 4.1 Creación de Token Individual

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    FLUJO DE CREACIÓN DE TOKEN INDIVIDUAL                     │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌────────────────┐                                                         │
│  │ Usuario Admin  │                                                         │
│  │ solicita token │                                                         │
│  └───────┬────────┘                                                         │
│          │                                                                   │
│          ▼                                                                   │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │              company_token_manager::create_token()          │            │
│  │                                                             │            │
│  │  Parámetros:                                                │            │
│  │  • userid     - ID del usuario                              │            │
│  │  • companyid  - ID de empresa (0 si no-IOMAD)              │            │
│  │  • serviceid  - ID del servicio web                         │            │
│  │  • options    - validez, restricción IP, etc.              │            │
│  └────────────────────────────────────────────────────────────┘            │
│          │                                                                   │
│          ▼                                                                   │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │                    ¿Es modo IOMAD?                          │            │
│  └──────────────────────────┬─────────────────────────────────┘            │
│                             │                                               │
│          ┌──────────────────┴──────────────────┐                           │
│          │SÍ                                   │NO                          │
│          ▼                                     ▼                            │
│  ┌────────────────────┐              ┌────────────────────┐                │
│  │ Validar usuario    │              │ Sin validación     │                │
│  │ pertenece a        │              │ de empresa         │                │
│  │ empresa            │              │                    │                │
│  └─────────┬──────────┘              └─────────┬──────────┘                │
│            │                                   │                            │
│            ▼                                   ▼                            │
│  ┌────────────────────┐              ┌────────────────────┐                │
│  │ Contexto:          │              │ Contexto:          │                │
│  │ Categoría empresa  │              │ Sistema            │                │
│  └─────────┬──────────┘              └─────────┬──────────┘                │
│            │                                   │                            │
│            └───────────────┬───────────────────┘                           │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │              Generar nombre del token                       │            │
│  │                                                             │            │
│  │  IOMAD:    "JUAN_GARCIA_ACME_CORP"                         │            │
│  │  Estándar: "JUAN_GARCIA_ESTUDIANTE"                        │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │         external_generate_token() de Moodle                 │            │
│  │                                                             │            │
│  │  → Crea registro en tabla 'external_tokens'                 │            │
│  │  → Genera hash único para el token                          │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │      Guardar metadatos en 'local_sm_estratoos_plugin'       │            │
│  │                                                             │            │
│  │  • tokenid        - Referencia a external_tokens            │            │
│  │  • companyid      - ID de empresa                           │            │
│  │  • batchid        - ID de lote (si aplica)                  │            │
│  │  • restricttocompany - Filtrar por empresa (1/0)            │            │
│  │  • restricttoenrolment - Filtrar por inscripción (1/0)      │            │
│  │  • iprestriction  - Restricción de IP                       │            │
│  │  • validuntil     - Fecha de expiración                     │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │         ensure_webservice_capability()                      │            │
│  │                                                             │            │
│  │  → Asegura que el usuario tenga 'webservice/rest:use'       │            │
│  │  → Asigna rol estudiante/profesor si es necesario           │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│                   ┌────────────────┐                                       │
│                   │  Token creado  │                                       │
│                   │  exitosamente  │                                       │
│                   └────────────────┘                                       │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 4.2 Creación Masiva de Tokens (Batch)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     FLUJO DE CREACIÓN MASIVA (BATCH)                         │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │  Administrador sube archivo CSV/Excel o selecciona usuarios │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │        company_token_manager::create_batch_tokens()         │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │              1. Generar UUID único para el lote             │            │
│  │                                                             │            │
│  │              batch_id = uniqid('batch_', true)              │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │       2. Crear registro en 'local_sm_estratoos_plugin_batch'│            │
│  │                                                             │            │
│  │       • batchid      - UUID generado                        │            │
│  │       • companyid    - Empresa destino                      │            │
│  │       • serviceid    - Servicio a otorgar                   │            │
│  │       • totalusers   - Total de usuarios                    │            │
│  │       • source       - 'company', 'csv', 'excel'            │            │
│  │       • status       - 'processing'                         │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │         3. Loop: Para cada usuario en la lista              │            │
│  │                                                             │            │
│  │    ┌──────────────────────────────────────────────────┐    │            │
│  │    │                                                  │    │            │
│  │    │  ┌───────────────────────────────────────────┐   │    │            │
│  │    │  │    create_token(userid, companyid, ...)   │   │    │            │
│  │    │  └───────────────────────────────────────────┘   │    │            │
│  │    │                     │                            │    │            │
│  │    │          ┌──────────┴──────────┐                 │    │            │
│  │    │          │                     │                 │    │            │
│  │    │    ┌─────▼─────┐         ┌─────▼─────┐          │    │            │
│  │    │    │  ÉXITO    │         │   ERROR   │          │    │            │
│  │    │    │           │         │           │          │    │            │
│  │    │    │ success++ │         │  fail++   │          │    │            │
│  │    │    │ tokens[]  │         │ errors[]  │          │    │            │
│  │    │    └───────────┘         └───────────┘          │    │            │
│  │    │                                                  │    │            │
│  │    └──────────────────────────────────────────────────┘    │            │
│  │                                                             │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │         4. Actualizar registro del lote                     │            │
│  │                                                             │            │
│  │         • successcount = tokens creados exitosamente        │            │
│  │         • failcount = errores                               │            │
│  │         • status = 'completed'                              │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │                    5. Retornar resultado                    │            │
│  │                                                             │            │
│  │         {                                                   │            │
│  │           batchid: "batch_65a1b2c3...",                     │            │
│  │           tokens: [...],                                    │            │
│  │           errors: [...],                                    │            │
│  │           successcount: 45,                                 │            │
│  │           failcount: 5                                      │            │
│  │         }                                                   │            │
│  └────────────────────────────────────────────────────────────┘            │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 5. Sistema de Restricciones por Empresa

### 5.1 Flujo de Habilitación/Deshabilitación de Empresas

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                 FLUJO DE GESTIÓN DE ACCESO POR EMPRESA                       │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │        Administrador accede a "Manage Company Access"       │            │
│  │                    (company_access.php)                     │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │         Lista de todas las empresas con checkbox            │            │
│  │                                                             │            │
│  │   ☑ Empresa A     [Enabled]                                │            │
│  │   ☐ Empresa B     [Disabled]                               │            │
│  │   ☑ Empresa C     [Enabled]                                │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│                 ┌──────────────────────┐                                   │
│                 │  Admin guarda cambios │                                   │
│                 └──────────┬───────────┘                                   │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │          util::set_enabled_companies($companyids)           │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│          ┌─────────────────┴─────────────────┐                             │
│          │                                   │                              │
│          ▼                                   ▼                              │
│  ┌────────────────────┐            ┌────────────────────┐                  │
│  │ Para empresas      │            │ Para empresas      │                  │
│  │ SELECCIONADAS      │            │ NO SELECCIONADAS   │                  │
│  └─────────┬──────────┘            └─────────┬──────────┘                  │
│            │                                 │                              │
│            ▼                                 ▼                              │
│  ┌────────────────────┐            ┌────────────────────┐                  │
│  │ enable_company_    │            │ disable_company_   │                  │
│  │ access($id)        │            │ access($id)        │                  │
│  └─────────┬──────────┘            └─────────┬──────────┘                  │
│            │                                 │                              │
│            ▼                                 ▼                              │
│  ┌────────────────────┐            ┌────────────────────┐                  │
│  │ ACTIVAR tokens     │            │ SUSPENDER tokens   │                  │
│  │ de la empresa      │            │ de la empresa      │                  │
│  └────────────────────┘            └────────────────────┘                  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 5.2 Flujo de Suspensión de Tokens

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      FLUJO DE SUSPENSIÓN DE TOKENS                           │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│              disable_company_access($companyid)                              │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │     set_company_tokens_active($companyid, false)            │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │       Obtener todos los tokens de la empresa                │            │
│  │                                                             │            │
│  │  SELECT * FROM local_sm_estratoos_plugin                    │            │
│  │  WHERE companyid = $companyid AND tokenid IS NOT NULL       │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │              Para cada token encontrado:                    │            │
│  │                                                             │            │
│  │  1. Obtener registro completo de external_tokens            │            │
│  │                                                             │            │
│  │  2. Serializar como JSON y guardar en 'token_backup'        │            │
│  │     {                                                       │            │
│  │       "id": 123,                                            │            │
│  │       "token": "abc123...",                                 │            │
│  │       "userid": 456,                                        │            │
│  │       "externalserviceid": 1,                               │            │
│  │       ...                                                   │            │
│  │     }                                                       │            │
│  │                                                             │            │
│  │  3. Establecer tokenid = NULL en local_sm_estratoos_plugin  │            │
│  │                                                             │            │
│  │  4. ELIMINAR registro de external_tokens                    │            │
│  │     → Esto BLOQUEA inmediatamente todas las llamadas API    │            │
│  │                                                             │            │
│  │  5. Establecer active = 0 en local_sm_estratoos_plugin      │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │                   RESULTADO                                 │            │
│  │                                                             │            │
│  │  • Tokens eliminados de external_tokens                     │            │
│  │  • Backup guardado en token_backup (JSON)                   │            │
│  │  • Llamadas API con estos tokens → ERROR inmediato          │            │
│  │  • Tokens restaurables cuando se re-habilite la empresa     │            │
│  └────────────────────────────────────────────────────────────┘            │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 5.3 Flujo de Restauración de Tokens

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     FLUJO DE RESTAURACIÓN DE TOKENS                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│               enable_company_access($companyid)                              │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │      set_company_tokens_active($companyid, true)            │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │       Obtener tokens suspendidos de la empresa              │            │
│  │                                                             │            │
│  │  SELECT * FROM local_sm_estratoos_plugin                    │            │
│  │  WHERE companyid = $companyid                               │            │
│  │    AND token_backup IS NOT NULL                             │            │
│  │    AND active = 0                                           │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │              Para cada token con backup:                    │            │
│  │                                                             │            │
│  │  1. Deserializar JSON de token_backup                       │            │
│  │                                                             │            │
│  │  2. RE-INSERTAR registro en external_tokens                 │            │
│  │     → El mismo hash de token se restaura                    │            │
│  │     → El token vuelve a funcionar inmediatamente            │            │
│  │                                                             │            │
│  │  3. Actualizar tokenid con el nuevo ID                      │            │
│  │                                                             │            │
│  │  4. Limpiar token_backup = NULL                             │            │
│  │                                                             │            │
│  │  5. Establecer active = 1                                   │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │                   RESULTADO                                 │            │
│  │                                                             │            │
│  │  • Tokens restaurados con el MISMO hash original            │            │
│  │  • Llamadas API funcionan inmediatamente                    │            │
│  │  • Clientes externos NO necesitan actualizar tokens         │            │
│  └────────────────────────────────────────────────────────────┘            │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 6. Filtrado de Respuestas API

### 6.1 Flujo General de Filtrado

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     FLUJO DE FILTRADO DE RESPUESTAS API                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │         Cliente externo realiza llamada API                 │            │
│  │                                                             │            │
│  │  POST /webservice/rest/server.php                           │            │
│  │    wstoken=abc123...                                        │            │
│  │    wsfunction=core_course_get_courses                       │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │            PRE-PROCESSOR (lib.php línea 50)                 │            │
│  │                                                             │            │
│  │  1. Obtener token de la solicitud                           │            │
│  │  2. Verificar: ¿El token está activo?                       │            │
│  │     - Si NO → throw 'tokensuspended' exception              │            │
│  │     - Si SÍ → continuar                                     │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │              Moodle ejecuta la función API                  │            │
│  │                                                             │            │
│  │  → core_course_get_courses()                                │            │
│  │  → Retorna TODOS los cursos sin filtrar                     │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │           POST-PROCESSOR (lib.php línea 69)                 │            │
│  │                                                             │            │
│  │  local_sm_estratoos_plugin_post_processor($function, $data) │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │         1. Obtener restricciones del token                  │            │
│  │                                                             │            │
│  │  $restrictions = get_token_restrictions($token)             │            │
│  │                                                             │            │
│  │  {                                                          │            │
│  │    companyid: 5,                                            │            │
│  │    restricttocompany: true,                                 │            │
│  │    restricttoenrolment: false                               │            │
│  │  }                                                          │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│          ┌─────────────────┴─────────────────┐                             │
│          │                                   │                              │
│    restricttocompany                   restricttocompany                    │
│       = FALSE                              = TRUE                           │
│          │                                   │                              │
│          ▼                                   ▼                              │
│  ┌────────────────────┐            ┌────────────────────┐                  │
│  │ Retornar datos     │            │ Crear filtro       │                  │
│  │ sin modificar      │            │                    │                  │
│  └────────────────────┘            │ $filter = new      │                  │
│                                    │ webservice_filter  │                  │
│                                    │ ($restrictions)    │                  │
│                                    └─────────┬──────────┘                  │
│                                              │                              │
│                                              ▼                              │
│                                    ┌────────────────────┐                  │
│                                    │ Aplicar filtro     │                  │
│                                    │ según función      │                  │
│                                    │                    │                  │
│                                    │ switch($function)  │                  │
│                                    │   'core_course_*'  │                  │
│                                    │   'core_user_*'    │                  │
│                                    │   'mod_quiz_*'     │                  │
│                                    │   ...              │                  │
│                                    └─────────┬──────────┘                  │
│                                              │                              │
│                                              ▼                              │
│                                    ┌────────────────────┐                  │
│                                    │ Datos filtrados    │                  │
│                                    │ solo de la empresa │                  │
│                                    └────────────────────┘                  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 6.2 Fuentes de Datos para Filtrado (IOMAD)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    FUENTES DE DATOS PARA FILTRADO                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                         TABLAS IOMAD                                 │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│   ┌───────────────────┐        ┌───────────────────┐                       │
│   │   company_course  │        │   company_users   │                       │
│   │                   │        │                   │                       │
│   │  companyid ──────────────────── companyid     │                       │
│   │  courseid        │        │  userid           │                       │
│   │                   │        │  managertype      │                       │
│   └─────────┬─────────┘        └─────────┬─────────┘                       │
│             │                            │                                  │
│             │     ┌──────────────────────┘                                  │
│             │     │                                                         │
│             ▼     ▼                                                         │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                      webservice_filter                               │   │
│   │                                                                      │   │
│   │   $companycourseids = [12, 15, 23, 45, ...]   ◄── company_course    │   │
│   │   $companyuserids = [101, 102, 105, ...]      ◄── company_users     │   │
│   │   $companycategoryids = [3, 7, 12, ...]       ◄── course_categories │   │
│   │   $userenrolledcourseids = [12, 23, ...]      ◄── enrollments       │   │
│   └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                      MÉTODOS DE FILTRADO                             │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│   ┌─────────────────────────────────────────────────────────────────┐      │
│   │  filter_courses($courses)                                        │      │
│   │    → Elimina cursos que NO están en $companycourseids            │      │
│   │                                                                  │      │
│   │  filter_users($users)                                            │      │
│   │    → Elimina usuarios que NO están en $companyuserids            │      │
│   │                                                                  │      │
│   │  filter_assignments($assignments)                                │      │
│   │    → Filtra por curso + usuario enviador                         │      │
│   │                                                                  │      │
│   │  filter_quiz_attempts($attempts)                                 │      │
│   │    → Filtra por curso + usuario                                  │      │
│   │                                                                  │      │
│   │  filter_discussions($discussions)                                │      │
│   │    → Filtra por foro en curso de la empresa                      │      │
│   └─────────────────────────────────────────────────────────────────┘      │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 6.3 Lógica de Restricción

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        LÓGICA DE RESTRICCIÓN                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │  CASO 1: restricttocompany = FALSE                                  │   │
│   │                                                                      │   │
│   │  → Token puede ver TODOS los datos de Moodle                        │   │
│   │  → Típico para tokens de administrador del sistema                  │   │
│   │  → Sin filtrado aplicado                                            │   │
│   └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │  CASO 2: restricttocompany = TRUE, restricttoenrolment = FALSE      │   │
│   │                                                                      │   │
│   │  → Token puede ver datos de TODA la empresa                         │   │
│   │  → Cursos: solo los asignados a la empresa                          │   │
│   │  → Usuarios: solo los pertenecientes a la empresa                   │   │
│   │  → Típico para managers de empresa                                  │   │
│   └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │  CASO 3: restricttocompany = TRUE, restricttoenrolment = TRUE       │   │
│   │                                                                      │   │
│   │  → Token puede ver solo cursos donde está INSCRITO                  │   │
│   │  → Intersección: (cursos empresa) ∩ (cursos inscrito)               │   │
│   │  → Típico para estudiantes y profesores                             │   │
│   └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│                                                                              │
│                    DIAGRAMA DE INTERSECCIÓN                                  │
│                                                                              │
│     restricttocompany=TRUE          restricttoenrolment=TRUE                │
│     ┌──────────────────────┐       ┌──────────────────────┐                │
│     │   Cursos de la       │       │   Cursos donde el    │                │
│     │     Empresa          │       │  usuario está inscrito│                │
│     │                      │       │                      │                │
│     │      ┌───────────────┼───────┼──────────┐          │                │
│     │      │               │       │          │          │                │
│     │      │    DATOS      │       │          │          │                │
│     │      │   VISIBLES    │       │          │          │                │
│     │      │               │       │          │          │                │
│     │      └───────────────┼───────┼──────────┘          │                │
│     │                      │       │                      │                │
│     └──────────────────────┘       └──────────────────────┘                │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 7. Control de Acceso y Permisos

### 7.1 Capacidades Definidas

| Capacidad | Contexto | Descripción |
|-----------|----------|-------------|
| `local/sm_estratoos_plugin:managetokens` | SYSTEM | Gestionar todos los tokens (admin del sitio) |
| `local/sm_estratoos_plugin:managecompanytokens` | COURSECAT | Gestionar tokens de una empresa |
| `local/sm_estratoos_plugin:createbatch` | SYSTEM | Crear tokens en lote |
| `local/sm_estratoos_plugin:viewreports` | SYSTEM | Ver reportes de tokens |
| `local/sm_estratoos_plugin:export` | SYSTEM | Exportar tokens a CSV/Excel |
| `local/sm_estratoos_plugin:createtokensapi` | SYSTEM | Crear tokens vía API |

### 7.2 Flujo de Verificación de Acceso

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    FLUJO DE VERIFICACIÓN DE ACCESO                           │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │         Usuario intenta acceder al plugin                   │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │               util::require_token_admin()                   │            │
│  └────────────────────────────────────────────────────────────┘            │
│                            │                                                │
│                            ▼                                                │
│  ┌────────────────────────────────────────────────────────────┐            │
│  │                    ¿Es Site Admin?                          │            │
│  └──────────────────────────┬─────────────────────────────────┘            │
│                             │                                               │
│          ┌──────────────────┴──────────────────┐                           │
│          │SÍ                                   │NO                          │
│          ▼                                     ▼                            │
│  ┌────────────────────┐            ┌────────────────────────────┐          │
│  │  ACCESO CONCEDIDO  │            │ ¿Tiene rol admin/manager   │          │
│  │  (acceso total)    │            │ a nivel de SISTEMA o       │          │
│  └────────────────────┘            │ CATEGORÍA?                 │          │
│                                    └─────────────┬──────────────┘          │
│                                                  │                          │
│                          ┌───────────────────────┴───────────────┐         │
│                          │SÍ                                     │NO        │
│                          ▼                                       ▼         │
│                ┌────────────────────────────┐      ┌────────────────────┐  │
│                │ ¿Es modo IOMAD?            │      │  ACCESO DENEGADO   │  │
│                └─────────────┬──────────────┘      └────────────────────┘  │
│                              │                                              │
│          ┌───────────────────┴───────────────────┐                         │
│          │SÍ                                     │NO                        │
│          ▼                                       ▼                          │
│  ┌────────────────────────────┐      ┌────────────────────┐                │
│  │ ¿El usuario es manager de  │      │  ACCESO CONCEDIDO  │                │
│  │ al menos una empresa       │      │  (modo estándar)   │                │
│  │ HABILITADA?                │      └────────────────────┘                │
│  └─────────────┬──────────────┘                                            │
│                │                                                            │
│    ┌───────────┴───────────┐                                               │
│    │SÍ                     │NO                                              │
│    ▼                       ▼                                                │
│  ┌──────────────────┐  ┌────────────────────┐                              │
│  │ ACCESO CONCEDIDO │  │  ACCESO DENEGADO   │                              │
│  │ (solo a sus      │  │                    │                              │
│  │  empresas)       │  │                    │                              │
│  └──────────────────┘  └────────────────────┘                              │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 7.3 Detección de Roles Admin/Manager

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                   DETECCIÓN DE ROLES ADMIN/MANAGER                           │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Método: util::has_admin_or_manager_role($userid)                           │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                     ROLES DETECTADOS                                 │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│   Shortname exacto:                                                         │
│   • companymanager (IOMAD)                                                  │
│                                                                              │
│   Shortname contiene (case-insensitive):                                    │
│   • admin                                                                   │
│   • manager                                                                 │
│   • administrador                                                           │
│   • gerente                                                                 │
│   • gestor                                                                  │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    CONTEXTOS VERIFICADOS                             │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│   ✓ CONTEXT_SYSTEM (nivel 10)     - Administradores del sitio              │
│   ✓ CONTEXT_COURSECAT (nivel 40)  - Managers de categoría/empresa          │
│   ✗ CONTEXT_COURSE (nivel 50)     - NO se incluye (límite de seguridad)    │
│                                                                              │
│  IMPORTANTE: Los managers a nivel de CURSO no obtienen acceso al plugin.   │
│  Esto es intencional para mantener la seguridad del sistema.               │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 7.4 Empresas Gestionadas por Usuario

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    EMPRESAS GESTIONADAS POR USUARIO                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Método: util::get_user_managed_companies($userid)                          │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                      SITE ADMINS                                     │   │
│  │                                                                      │   │
│  │  → Retorna TODAS las empresas                                        │   │
│  │  → Sin restricción                                                   │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    COMPANY MANAGERS                                  │   │
│  │                                                                      │   │
│  │  SELECT c.*                                                          │   │
│  │  FROM company c                                                      │   │
│  │  JOIN company_users cu ON cu.companyid = c.id                        │   │
│  │  WHERE cu.userid = $userid                                           │   │
│  │    AND cu.managertype > 0     ◄── Solo si es manager                 │   │
│  │                                                                      │   │
│  │  managertype valores:                                                │   │
│  │    0 = Usuario normal                                                │   │
│  │    1 = Company manager                                               │   │
│  │    2 = Department manager                                            │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                      NON-IOMAD MODE                                  │   │
│  │                                                                      │   │
│  │  → Retorna array vacío []                                            │   │
│  │  → No hay concepto de empresas                                       │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 8. Servicios Web (API)

### 8.1 Funciones Disponibles

#### Gestión de Tokens

| Función | Descripción |
|---------|-------------|
| `local_sm_estratoos_plugin_create_batch` | Crear tokens para múltiples usuarios |
| `local_sm_estratoos_plugin_get_tokens` | Listar tokens de la empresa |
| `local_sm_estratoos_plugin_revoke` | Revocar/eliminar tokens |
| `local_sm_estratoos_plugin_create_admin_token` | Crear token de administrador |
| `local_sm_estratoos_plugin_get_batch_history` | Historial de operaciones por lotes |

#### Usuarios y Empresas

| Función | Descripción |
|---------|-------------|
| `local_sm_estratoos_plugin_get_company_users` | Obtener usuarios de una empresa |
| `local_sm_estratoos_plugin_get_companies` | Listar empresas IOMAD |
| `local_sm_estratoos_plugin_get_services` | Listar servicios web |
| `local_sm_estratoos_plugin_get_users_by_field` | Buscar usuarios por campo |
| `local_sm_estratoos_plugin_get_categories` | Obtener categorías de cursos |

#### Contenido de Cursos

| Función | Descripción |
|---------|-------------|
| `local_sm_estratoos_plugin_get_course_content` | API comprensiva de contenido (SCORM, Quiz, etc.) |

#### Mensajería

| Función | Descripción |
|---------|-------------|
| `local_sm_estratoos_plugin_get_conversations` | Obtener conversaciones |
| `local_sm_estratoos_plugin_get_conversation_messages` | Obtener mensajes de una conversación |

#### Foros

| Función | Descripción |
|---------|-------------|
| `local_sm_estratoos_plugin_forum_create` | Crear foro |
| `local_sm_estratoos_plugin_forum_edit` | Editar foro |
| `local_sm_estratoos_plugin_forum_delete` | Eliminar foro |

### 8.2 API de Contenido de Cursos

```
┌─────────────────────────────────────────────────────────────────────────────┐
│              API DE CONTENIDO DE CURSOS (get_course_content)                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Endpoint: POST /webservice/rest/server.php                                 │
│                                                                              │
│  Parámetros:                                                                │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │  wstoken                    Token de autenticación                   │   │
│  │  wsfunction                 local_sm_estratoos_plugin_get_course_... │   │
│  │  moodlewsrestformat         json                                     │   │
│  │  courseids[0]               ID del curso                             │   │
│  │  options[includescormdetails]    Incluir detalles SCORM (true/false) │   │
│  │  options[includequizquestions]   Incluir preguntas de quiz           │   │
│  │  options[includeassignmentdetails] Incluir detalles de tareas        │   │
│  │  options[includelessonpages]     Incluir páginas de lecciones        │   │
│  │  options[includeuserdata]        Incluir progreso del usuario        │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  Estructura de Respuesta:                                                   │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │  {                                                                   │   │
│  │    "courses": [                                                      │   │
│  │      {                                                               │   │
│  │        "id": 2,                                                      │   │
│  │        "fullname": "Curso de Ejemplo",                               │   │
│  │        "sections": [                                                 │   │
│  │          {                                                           │   │
│  │            "id": 5,                                                  │   │
│  │            "name": "Sección 1",                                      │   │
│  │            "modules": [                                              │   │
│  │              {                                                       │   │
│  │                "id": 10,                                             │   │
│  │                "modname": "quiz",                                    │   │
│  │                "quiz": "{...JSON codificado...}"  ◄── IMPORTANTE    │   │
│  │              }                                                       │   │
│  │            ]                                                         │   │
│  │          }                                                           │   │
│  │        ]                                                             │   │
│  │      }                                                               │   │
│  │    ]                                                                 │   │
│  │  }                                                                   │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  IMPORTANTE: Los campos scorm, quiz, assignment, lesson, book son           │
│  strings JSON que deben ser parseados:                                      │
│                                                                              │
│  JavaScript:  const quizData = JSON.parse(module.quiz);                     │
│  Python:      quiz_data = json.loads(module['quiz'])                        │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 9. Páginas y Puntos de Entrada

### 9.1 Dashboard Principal (index.php)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           DASHBOARD PRINCIPAL                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  URL: /local/sm_estratoos_plugin/index.php                                  │
│  Acceso: require_token_admin()                                              │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                                                                      │   │
│  │  ┌──────────────────────────────────────────────────────────────┐   │   │
│  │  │  MODO: IOMAD Multi-tenant          [Check for Updates]       │   │   │
│  │  └──────────────────────────────────────────────────────────────┘   │   │
│  │                                                                      │   │
│  │  ┌────────────┐ ┌────────────┐ ┌────────────┐ ┌────────────┐       │   │
│  │  │  Create    │ │  Create    │ │  Manage    │ │  Manage    │       │   │
│  │  │  Admin     │ │  Company   │ │  Tokens    │ │  Services  │       │   │
│  │  │  Token     │ │  Tokens    │ │            │ │            │       │   │
│  │  │            │ │            │ │            │ │            │       │   │
│  │  │ [Solo      │ │ [Todos los │ │ [Todos los │ │ [Solo      │       │   │
│  │  │  admins]   │ │  admins]   │ │  admins]   │ │  admins]   │       │   │
│  │  └────────────┘ └────────────┘ └────────────┘ └────────────┘       │   │
│  │                                                                      │   │
│  │  ┌────────────┐                                                     │   │
│  │  │  Manage    │     Solo visible en modo IOMAD                      │   │
│  │  │  Company   │     y solo para site admins                         │   │
│  │  │  Access    │                                                     │   │
│  │  └────────────┘                                                     │   │
│  │                                                                      │   │
│  │  ┌──────────────────────────────────────────────────────────────┐   │   │
│  │  │  ESTADÍSTICAS                                                 │   │   │
│  │  │                                                               │   │   │
│  │  │  Total Tokens: 156          Total Batches: 12                │   │   │
│  │  └──────────────────────────────────────────────────────────────┘   │   │
│  │                                                                      │   │
│  │  ┌──────────────────────────────────────────────────────────────┐   │   │
│  │  │  LOTES RECIENTES                                              │   │   │
│  │  │                                                               │   │   │
│  │  │  Fecha       | Empresa    | Éxitos | Fallos | Estado         │   │   │
│  │  │  2025-01-15  | ACME Corp  | 45     | 2      | Completado     │   │   │
│  │  │  2025-01-14  | Tech Inc   | 30     | 0      | Completado     │   │   │
│  │  └──────────────────────────────────────────────────────────────┘   │   │
│  │                                                                      │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 9.2 Gestión de Acceso por Empresa (company_access.php)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      GESTIÓN DE ACCESO POR EMPRESA                           │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  URL: /local/sm_estratoos_plugin/company_access.php                         │
│  Acceso: Solo Site Admins + Modo IOMAD                                      │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                                                                      │   │
│  │  Quick Select: [Select All] [Deselect All]                          │   │
│  │                                                                      │   │
│  │  2 companies selected                    [🔍 Search companies...]   │   │
│  │                                                                      │   │
│  │  ┌──────────────────────────────────────────────────────────────┐   │   │
│  │  │  ☑ Marketing4Talent (MK4T)                      [Enabled]    │   │   │
│  │  │  ☑ SmartMind (SMARTMIND)                        [Enabled]    │   │   │
│  │  │  ☐ Empresa Demo (DEMO)                          [Disabled]   │   │   │
│  │  │  ☐ Test Company (TEST)                          [Disabled]   │   │   │
│  │  └──────────────────────────────────────────────────────────────┘   │   │
│  │                                                                      │   │
│  │  [Save Changes]  [Cancel]                                           │   │
│  │                                                                      │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  Funcionalidad:                                                             │
│  • Buscar empresas mientras se escribe (filtrado en tiempo real)            │
│  • Seleccionar/deseleccionar todas las empresas visibles                    │
│  • Al guardar: empresas no seleccionadas tienen sus tokens SUSPENDIDOS      │
│  • Badge verde = Enabled, Badge gris = Disabled                             │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 9.3 Creación Masiva de Tokens (batch_token.php)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      CREACIÓN MASIVA DE TOKENS                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  URL: /local/sm_estratoos_plugin/batch_token.php                            │
│  Acceso: Token Admins                                                       │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                                                                      │   │
│  │  PASO 1: Seleccionar Empresa (solo IOMAD)                           │   │
│  │  ┌──────────────────────────────────────────────────────────────┐   │   │
│  │  │  Empresa: [▼ SmartMind                              ]        │   │   │
│  │  └──────────────────────────────────────────────────────────────┘   │   │
│  │                                                                      │   │
│  │  PASO 2: Seleccionar Servicio Web                                   │   │
│  │  ┌──────────────────────────────────────────────────────────────┐   │   │
│  │  │  Servicio: [▼ SmartMind - Estratoos Plugin          ]        │   │   │
│  │  └──────────────────────────────────────────────────────────────┘   │   │
│  │                                                                      │   │
│  │  PASO 3: Seleccionar Usuarios                                       │   │
│  │                                                                      │   │
│  │  Quick Select: [Select All] [Deselect All]                          │   │
│  │                                                                      │   │
│  │  5 users selected                        [🔍 Search users...]       │   │
│  │                                                                      │   │
│  │  ┌──────────────────────────────────────────────────────────────┐   │   │
│  │  │  ☑ Juan García (juan@empresa.com)            Teacher         │   │   │
│  │  │  ☑ María López (maria@empresa.com)           Student         │   │   │
│  │  │  ☐ Pedro Martínez (pedro@empresa.com)        Student         │   │   │
│  │  └──────────────────────────────────────────────────────────────┘   │   │
│  │                                                                      │   │
│  │  --- O ---                                                          │   │
│  │                                                                      │   │
│  │  IMPORTAR DESDE ARCHIVO                                             │   │
│  │  ┌──────────────────────────────────────────────────────────────┐   │   │
│  │  │  [Seleccionar archivo CSV/Excel]                             │   │   │
│  │  └──────────────────────────────────────────────────────────────┘   │   │
│  │                                                                      │   │
│  │  OPCIONES AVANZADAS                                                 │   │
│  │  ┌──────────────────────────────────────────────────────────────┐   │   │
│  │  │  ☑ Restringir a cursos de la empresa                         │   │   │
│  │  │  ☐ Restringir a cursos donde está inscrito                   │   │   │
│  │  │  Válido hasta: [____________________]                        │   │   │
│  │  │  Restricción IP: [____________________]                      │   │   │
│  │  └──────────────────────────────────────────────────────────────┘   │   │
│  │                                                                      │   │
│  │  [Crear Tokens]                                                     │   │
│  │                                                                      │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 10. Base de Datos

### 10.1 Tablas del Plugin

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          ESQUEMA DE BASE DE DATOS                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    local_sm_estratoos_plugin                         │   │
│  │                 (Metadatos de tokens creados)                        │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │  id                 BIGINT      PK, AUTO_INCREMENT                   │   │
│  │  tokenid            BIGINT      FK → external_tokens.id (nullable)   │   │
│  │  companyid          BIGINT      ID de empresa (0 si non-IOMAD)       │   │
│  │  batchid            VARCHAR     UUID del lote (si aplica)            │   │
│  │  restricttocompany  TINYINT     1 = filtrar por empresa              │   │
│  │  restricttoenrolment TINYINT    1 = filtrar por inscripción          │   │
│  │  iprestriction      VARCHAR     Restricción de IP                    │   │
│  │  validuntil         BIGINT      Timestamp de expiración              │   │
│  │  notes              TEXT        Notas adicionales                    │   │
│  │  active             TINYINT     1 = activo, 0 = suspendido           │   │
│  │  token_backup       TEXT        JSON backup de token suspendido      │   │
│  │  createdby          BIGINT      Usuario que creó el token            │   │
│  │  timecreated        BIGINT      Timestamp de creación                │   │
│  │  timemodified       BIGINT      Timestamp de última modificación     │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                  local_sm_estratoos_plugin_batch                     │   │
│  │                (Registro de operaciones por lotes)                   │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │  id                 BIGINT      PK, AUTO_INCREMENT                   │   │
│  │  batchid            VARCHAR     UUID único del lote                  │   │
│  │  companyid          BIGINT      Empresa destino                      │   │
│  │  serviceid          BIGINT      Servicio otorgado                    │   │
│  │  totalusers         INT         Total de usuarios procesados         │   │
│  │  successcount       INT         Tokens creados exitosamente          │   │
│  │  failcount          INT         Errores durante creación             │   │
│  │  source             VARCHAR     'company', 'csv', 'excel'            │   │
│  │  status             VARCHAR     'processing', 'completed', 'failed'  │   │
│  │  createdby          BIGINT      Usuario que inició el lote           │   │
│  │  timecreated        BIGINT      Timestamp de inicio                  │   │
│  │  timecompleted      BIGINT      Timestamp de finalización            │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                 local_sm_estratoos_plugin_access                     │   │
│  │                 (Control de acceso por empresa)                      │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │  id                 BIGINT      PK, AUTO_INCREMENT                   │   │
│  │  companyid          BIGINT      ID de empresa                        │   │
│  │  enabled            TINYINT     1 = habilitada, 0 = deshabilitada    │   │
│  │  enabledby          BIGINT      Usuario que cambió el estado         │   │
│  │  timecreated        BIGINT      Timestamp de creación                │   │
│  │  timemodified       BIGINT      Timestamp de última modificación     │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 10.2 Relaciones con Tablas de Moodle/IOMAD

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       RELACIONES CON OTRAS TABLAS                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│                          TABLAS MOODLE CORE                                  │
│                                                                              │
│  ┌──────────────────┐         ┌──────────────────┐                         │
│  │  external_tokens │◄────────│ local_sm_        │                         │
│  │                  │  FK     │ estratoos_plugin │                         │
│  │  • id            │         │                  │                         │
│  │  • token         │         │  • tokenid ──────┘                         │
│  │  • userid        │         │                                            │
│  │  • ...           │         └──────────────────┘                         │
│  └──────────────────┘                                                       │
│                                                                              │
│  ┌──────────────────┐                                                       │
│  │  user            │                                                       │
│  │                  │◄──── Referenciado por tokenid → external_tokens      │
│  │  • id            │                                                       │
│  │  • username      │                                                       │
│  │  • ...           │                                                       │
│  └──────────────────┘                                                       │
│                                                                              │
│                            TABLAS IOMAD                                      │
│                                                                              │
│  ┌──────────────────┐         ┌──────────────────┐                         │
│  │  company         │◄────────│ local_sm_        │                         │
│  │                  │ companyid│ estratoos_plugin │                         │
│  │  • id            │         │                  │                         │
│  │  • name          │         │  • companyid ────┘                         │
│  │  • shortname     │         │                                            │
│  │  • category      │         └──────────────────┘                         │
│  └──────────────────┘                                                       │
│                                                                              │
│  ┌──────────────────┐         ┌──────────────────┐                         │
│  │  company_users   │         │  company_course  │                         │
│  │                  │         │                  │                         │
│  │  • companyid     │         │  • companyid     │                         │
│  │  • userid        │         │  • courseid      │                         │
│  │  • managertype   │         │                  │                         │
│  │  • departmentid  │         └──────────────────┘                         │
│  └──────────────────┘                                                       │
│                                                                              │
│  Usado por webservice_filter para determinar qué datos puede ver            │
│  cada token según su empresa asociada.                                      │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 11. Troubleshooting

### 11.1 Problemas Comunes

| Problema | Causa | Solución |
|----------|-------|----------|
| Token no funciona | Token suspendido | Verificar que la empresa esté habilitada |
| API retorna datos vacíos | Filtrado por empresa | Verificar `restricttocompany` en el token |
| Usuario no aparece en lista | No pertenece a la empresa | Verificar tabla `company_users` |
| Error de acceso denegado | Sin rol de manager | Verificar rol a nivel SYSTEM o CATEGORY |
| Búsqueda no funciona | Cache de JavaScript | Purgar caches de Moodle |

### 11.2 Consultas SQL de Diagnóstico

```sql
-- Verificar configuración del servicio
SELECT * FROM mdl_external_services
WHERE shortname = 'sm_estratoos_plugin';

-- Verificar detalles de un token
SELECT et.*, es.name as service_name, lsp.*
FROM mdl_external_tokens et
JOIN mdl_external_services es ON es.id = et.externalserviceid
LEFT JOIN mdl_local_sm_estratoos_plugin lsp ON lsp.tokenid = et.id
WHERE et.token = 'TOKEN_AQUI';

-- Verificar tokens suspendidos de una empresa
SELECT * FROM mdl_local_sm_estratoos_plugin
WHERE companyid = 5 AND active = 0;

-- Verificar estado de acceso de empresas
SELECT c.id, c.name, c.shortname, lspa.enabled
FROM mdl_company c
LEFT JOIN mdl_local_sm_estratoos_plugin_access lspa ON lspa.companyid = c.id;

-- Verificar cursos de una empresa
SELECT c.id, c.fullname, cc.companyid
FROM mdl_course c
JOIN mdl_company_course cc ON cc.courseid = c.id
WHERE cc.companyid = 5;

-- Verificar usuarios de una empresa
SELECT u.id, u.username, u.email, cu.managertype
FROM mdl_user u
JOIN mdl_company_users cu ON cu.userid = u.id
WHERE cu.companyid = 5;
```

### 11.3 Logs de Debugging

El plugin registra eventos importantes en el log de Moodle:

```php
// Buscar en logs de Moodle
SELECT * FROM mdl_logstore_standard_log
WHERE component = 'local_sm_estratoos_plugin'
ORDER BY timecreated DESC
LIMIT 100;

// O en el log de errores de PHP
// Buscar líneas con: SM_ESTRATOOS_PLUGIN
```

---

## Historial de Versiones Recientes

| Versión | Cambios |
|---------|---------|
| 1.7.21 | Limpieza de logs de consola, documentación actualizada |
| 1.7.20 | FIX: Búsqueda de empresas - usar clase d-none (Bootstrap) |
| 1.7.17-19 | Debugging de búsqueda de empresas |
| 1.7.16 | Reconstrucción completa de página de acceso de empresas |
| 1.7.15 | Limpieza agresiva de roles a nivel de sistema |
| 1.7.6 | Fix upgrade script - drop key before changing tokenid notnull |
| 1.7.5 | Fix token suspension to properly block API calls |

---

**Documento generado:** Enero 2025
**Plugin:** SmartMind - Estratoos Plugin v1.7.21
**Autor:** SmartMind Technologies
