<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Cadenas de idioma para local_sm_estratoos_plugin (Español).
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// General.
$string['pluginname'] = 'SmartMind Estratoos Plugin';
$string['plugindescription'] = 'Crear y gestionar tokens de API con alcance por empresa para instalaciones multi-tenant de SmartMind - Estratoos.';

// Capacidades.
$string['sm_estratoos_plugin:managetokens'] = 'Gestionar todos los tokens SmartMind';
$string['sm_estratoos_plugin:managecompanytokens'] = 'Gestionar tokens de una empresa';
$string['sm_estratoos_plugin:createbatch'] = 'Crear tokens en lote';
$string['sm_estratoos_plugin:viewreports'] = 'Ver informes de tokens';
$string['sm_estratoos_plugin:export'] = 'Exportar tokens';
$string['sm_estratoos_plugin:createtokensapi'] = 'Crear tokens vía API';

// Panel de control.
$string['dashboard'] = 'Panel de Gestión de Tokens y Funciones API';
$string['dashboarddesc'] = 'Crear y gestionar tokens de API para su instalación de Moodle.';
$string['createadmintoken'] = 'Crear Token de Administrador';
$string['createadmintokendesc'] = 'Crear un token a nivel de sistema para el administrador de Moodle con acceso completo.';
$string['createcompanytokens'] = 'Crear Tokens de Usuario';
$string['createcompanytokensdesc'] = 'Crear tokens de API para usuarios en lote. En modo IOMAD, los tokens tienen alcance de empresa y solo devuelven datos de la empresa seleccionada.';
$string['managetokens'] = 'Gestionar Tokens';
$string['managetokensdesc'] = 'Ver, editar y revocar tokens existentes.';

// Página de token de administrador.
$string['admintoken'] = 'Token de Administrador';
$string['admintokendesc'] = 'Crear un token a nivel de sistema para el administrador del sitio. Este token tendrá acceso completo a todos los datos.';
$string['createadmintokenbutton'] = 'Crear Token de Administrador';
$string['admintokencreated'] = 'Token de administrador creado exitosamente';
$string['admintokenwarning'] = 'Advertencia: Este token proporciona acceso completo al sistema. ¡Manténgalo seguro!';

// Página de tokens en lote.
$string['batchtokens'] = 'Creación de Tokens en Lote';
$string['batchtokensdesc'] = 'Crear tokens para múltiples usuarios a la vez con acceso limitado a la empresa.';
$string['createbatchtokens'] = 'Crear Tokens en Lote';

// Selección de usuarios.
$string['userselection'] = 'Selección de Usuarios';
$string['selectionmethod'] = 'Método de selección';
$string['bycompany'] = 'Por empresa';
$string['bycsv'] = 'Carga de CSV';
$string['company'] = 'Empresa';
$string['selectcompany'] = 'Seleccionar empresa';
$string['department'] = 'Departamento';
$string['alldepartments'] = 'Todos los departamentos';
$string['csvfile'] = 'Archivo CSV';
$string['csvfield'] = 'Campo CSV para identificación de usuario';
$string['userid'] = 'ID de Usuario';
$string['csvhelp'] = 'Suba un archivo CSV con un identificador de usuario por línea. La primera fila puede ser un encabezado.';
$string['csvhelp_help'] = 'El archivo CSV debe contener un identificador de usuario por línea. Puede usar IDs de usuario, nombres de usuario o direcciones de correo electrónico. Si la primera fila es un encabezado, se omitirá automáticamente.';

// Selección de servicio.
$string['serviceselection'] = 'Servicio Web';
$string['service'] = 'Servicio';
$string['selectservice'] = 'Seleccionar servicio web';
$string['noservicesenabled'] = 'No hay servicios web habilitados. Por favor habilite al menos un servicio web.';

// Restricciones de token.
$string['tokenrestrictions'] = 'Restricciones del Token';
$string['restricttocompany'] = 'Restringir a empresa';
$string['restricttocompany_desc'] = 'Cuando está habilitado, las llamadas API solo devolverán datos de la empresa seleccionada.';
$string['restricttoenrolment'] = 'Restringir a matriculación';
$string['restricttoenrolment_desc'] = 'Cuando está habilitado, los usuarios solo verán cursos en los que están matriculados (además del filtro de empresa).';

// Configuración de lote.
$string['batchsettings'] = 'Configuración de Lote';
$string['iprestriction'] = 'Restricción de IP';
$string['iprestriction_help'] = 'Ingrese direcciones IP o rangos permitidos (separados por comas). Deje vacío para sin restricción. Ejemplos: 192.168.1.1, 10.0.0.0/8';
$string['validuntil'] = 'Válido hasta';
$string['validuntil_help'] = 'Establezca una fecha de vencimiento para los tokens. Deje vacío para tokens que nunca expiran.';
$string['neverexpires'] = 'Nunca expira';

// Configuración individual.
$string['individualoverrides'] = 'Anulaciones Individuales';
$string['allowindividualoverrides'] = 'Permitir configuración individual de tokens';
$string['allowindividualoverrides_desc'] = 'Cuando está habilitado, puede modificar restricciones de IP y validez para tokens individuales después de la creación.';

// Notas.
$string['notes'] = 'Notas';
$string['notes_help'] = 'Notas opcionales sobre este lote o token para propósitos administrativos.';

// Acciones.
$string['createtokens'] = 'Crear Tokens';
$string['cancel'] = 'Cancelar';
$string['back'] = 'Volver';
$string['revoke'] = 'Revocar';
$string['revokeselected'] = 'Revocar Seleccionados';
$string['export'] = 'Exportar';
$string['exportselected'] = 'Exportar Seleccionados';
$string['exportcsv'] = 'Exportar como CSV';
$string['edit'] = 'Editar';
$string['delete'] = 'Eliminar';
$string['apply'] = 'Aplicar';
$string['filter'] = 'Filtrar';

// Resultados.
$string['batchcomplete'] = 'Creación de tokens en lote completada';
$string['tokenscreated'] = '{$a} tokens creados exitosamente';
$string['tokensfailed'] = '{$a} tokens fallaron al crear';
$string['errors'] = 'Errores';
$string['createdtokens'] = 'Tokens Creados';
$string['tokensshownonce'] = 'Las cadenas de token se muestran solo una vez. Asegúrese de guardarlas antes de salir de esta página.';
$string['batchid'] = 'ID de Lote';
$string['createnewbatch'] = 'Crear Nuevo Lote';
$string['recentbatches'] = 'Lotes Recientes';
$string['createdby'] = 'Creado por';

// Lista de tokens.
$string['tokenlist'] = 'Lista de Tokens';
$string['notokens'] = 'No se encontraron tokens';
$string['token'] = 'Token';
$string['tokens'] = 'tokens';
$string['user'] = 'Usuario';
$string['restrictions'] = 'Restricciones';
$string['companyonly'] = 'Solo empresa';
$string['enrolledonly'] = 'Solo matriculados';

// Estadísticas.
$string['companytokens_stat'] = 'Tokens creados para usuarios asociados a empresas';
$string['stat_success'] = 'Éxito';
$string['stat_failed'] = 'Fallidos';
$string['lastaccess'] = 'Último acceso';
$string['actions'] = 'Acciones';
$string['bulkactions'] = 'Acciones masivas...';
$string['selectall'] = 'Seleccionar todo';

// Mensajes de confirmación.
$string['confirmrevoke'] = '¿Está seguro de que desea revocar este token? Esta acción no se puede deshacer.';
$string['confirmrevokeselected'] = '¿Está seguro de que desea revocar los tokens seleccionados? Esta acción no se puede deshacer.';
$string['tokenrevoked'] = 'Token revocado exitosamente';
$string['tokensrevoked'] = '{$a} tokens revocados exitosamente';

// Mensajes de error.
$string['accessdenied'] = 'Acceso denegado. Solo los administradores del sitio pueden acceder a esta página.';
$string['invalidcompany'] = 'Empresa seleccionada inválida';
$string['invalidservice'] = 'Servicio seleccionado inválido';
$string['usernotincompany'] = 'El usuario {$a->userid} no es miembro de la empresa {$a->companyid}';
$string['coursenotincompany'] = 'Este curso no pertenece a su empresa';
$string['usernotenrolled'] = 'No está matriculado en este curso';
$string['forumnotincompany'] = 'Este foro no pertenece a su empresa';
$string['discussionnotincompany'] = 'Esta discusión no pertenece a su empresa';
$string['invalidtoken'] = 'Token inválido';
$string['tokennotfound'] = 'Token no encontrado';
$string['invalidiprestriction'] = 'Formato de restricción de IP inválido';
$string['csverror'] = 'Error procesando archivo CSV: {$a}';
$string['nousersfound'] = 'No se encontraron usuarios que coincidan con los criterios';
$string['emptycsv'] = 'El archivo CSV está vacío o no contiene usuarios válidos';

// Configuración.
$string['settings'] = 'Configuración de Tokens SmartMind';
$string['defaultvaliditydays'] = 'Validez predeterminada (días)';
$string['defaultvaliditydays_desc'] = 'Número predeterminado de días antes de que los tokens expiren. Establezca en 0 para tokens que nunca expiran.';
$string['cleanupexpiredtokens'] = 'Limpiar tokens expirados';
$string['cleanupexpiredtokens_desc'] = 'Eliminar automáticamente registros de tokens de empresa expirados durante el cron.';
$string['defaultrestricttocompany'] = 'Predeterminado: Restringir a empresa';
$string['defaultrestricttocompany_desc'] = 'Valor predeterminado para restricción de empresa al crear nuevos tokens.';
$string['defaultrestricttoenrolment'] = 'Predeterminado: Restringir a matriculación';
$string['defaultrestricttoenrolment_desc'] = 'Valor predeterminado para restricción de matriculación al crear nuevos tokens.';

// Privacidad.
$string['privacy:metadata:local_sm_estratoos_plugin'] = 'Información sobre tokens con alcance de empresa';
$string['privacy:metadata:local_sm_estratoos_plugin:tokenid'] = 'El ID del token externo';
$string['privacy:metadata:local_sm_estratoos_plugin:companyid'] = 'La empresa a la que está limitado este token';
$string['privacy:metadata:local_sm_estratoos_plugin:createdby'] = 'El usuario que creó este token';
$string['privacy:metadata:local_sm_estratoos_plugin:timecreated'] = 'Cuándo se creó el token';

// Tareas.
$string['task:cleanupexpiredtokens'] = 'Limpiar tokens de empresa expirados';

// Selección de usuarios.
$string['quickselect'] = 'Selección rápida';
$string['selectallusers'] = 'Todos';
$string['selectnone'] = 'Ninguno';
$string['selectstudents'] = 'Estudiantes';
$string['selectteachers'] = 'Profesores';
$string['selectmanagers'] = 'Gestores';
$string['selectothers'] = 'Otros';
$string['selectedusers'] = 'usuarios seleccionados';
$string['searchusers'] = 'Buscar usuarios...';
$string['loadingusers'] = 'Cargando usuarios...';
$string['nousersselected'] = 'Por favor seleccione al menos un usuario';
$string['companymanager'] = 'Gestor de Empresa';

// Role name badges.
$string['role_student'] = 'Estudiante';
$string['role_teacher'] = 'Profesor';
$string['role_manager'] = 'Gestor';
$string['role_other'] = 'Otro';

// Role names for token naming (uppercase, no accents).
$string['tokenrole_student'] = 'ESTUDIANTE';
$string['tokenrole_teacher'] = 'PROFESOR';
$string['tokenrole_manager'] = 'GESTOR';
$string['tokenrole_other'] = 'OTRO';

// Detección de IOMAD.
$string['iomaddetected'] = 'Modo multi-tenant IOMAD detectado';
$string['standardmoodle'] = 'Modo Moodle estándar (sin empresas)';
$string['moodlemode'] = 'Modo Moodle';

// Modo sin IOMAD.
$string['createusertokens'] = 'Crear Tokens de Usuario';
$string['createusertokensdesc'] = 'Crear tokens de API para usuarios en lote.';
$string['selectusers'] = 'Seleccionar Usuarios';
$string['allusers'] = 'Todos los usuarios';
$string['searchandselect'] = 'Buscar y seleccionar usuarios';
$string['nousersavailable'] = 'No hay usuarios disponibles';

// Notificaciones de actualización.
$string['task:checkforupdates'] = 'Verificar actualizaciones del plugin';
$string['messageprovider:updatenotification'] = 'Notificaciones de actualización del plugin';
$string['updateavailable_subject'] = 'Actualización disponible de SmartMind Plugin: v{$a}';
$string['updateavailable_message'] = 'Una nueva versión de SmartMind - Estratoos Plugin está disponible.

Versión actual: {$a->currentversion}
Nueva versión: {$a->newversion}

Para instalar la actualización, vaya a:
{$a->updateurl}';
$string['updateavailable_message_html'] = '<p>Una nueva versión de <strong>SmartMind - Estratoos Plugin</strong> está disponible.</p>
<table>
<tr><td><strong>Versión actual:</strong></td><td>{$a->currentversion}</td></tr>
<tr><td><strong>Nueva versión:</strong></td><td>{$a->newversion}</td></tr>
</table>
<p><a href="{$a->updateurl}" class="btn btn-primary">Instalar actualización</a></p>';

// Cadenas de la página de actualización.
$string['checkforupdates'] = 'Buscar actualizaciones';
$string['updateplugin'] = 'Actualizar SmartMind Plugin';
$string['updateavailable'] = 'Actualización disponible';
$string['currentversion'] = 'Versión actual';
$string['newversion'] = 'Nueva versión';
$string['updateconfirm'] = '¿Está seguro de que desea actualizar el SmartMind - Estratoos Plugin? Los archivos del plugin serán reemplazados por la última versión.';
$string['updatingplugin'] = 'Actualizando plugin...';
$string['downloadingupdate'] = 'Descargando actualización...';
$string['extractingupdate'] = 'Extrayendo archivos...';
$string['installingupdate'] = 'Instalando actualización...';
$string['updatesuccessful'] = '¡Actualización exitosa!';
$string['updatesuccessful_desc'] = 'El plugin ha sido actualizado. Haga clic en continuar para completar la actualización de la base de datos.';
$string['updatefailed'] = 'Actualización fallida';
$string['updatefetcherror'] = 'No se pudo obtener información de actualización del servidor.';
$string['alreadyuptodate'] = 'El plugin ya está actualizado.';
$string['downloadfailed'] = 'Error al descargar el paquete de actualización.';
$string['extractfailed'] = 'Error al extraer el paquete de actualización.';
$string['installfailed'] = 'Error al instalar la actualización.';

// Historial de eliminaciones.
$string['deletionhistory'] = 'Historial de Eliminaciones';
$string['tokensdeleted'] = 'tokens eliminados';
$string['deletedby'] = 'Eliminado por';
$string['clicktoexpand'] = 'clic para expandir';

// Instrucciones de actualización manual.
$string['manualupdate_title'] = 'Actualización Manual Requerida';
$string['manualupdate_intro'] = 'La actualización automática no se pudo completar debido a los permisos de archivos. Siga estos pasos para actualizar manualmente:';
$string['manualupdate_step1'] = 'Descargue la última versión del plugin:';
$string['manualupdate_download'] = 'Descargar ZIP';
$string['manualupdate_step2'] = 'Vaya al instalador de plugins de Moodle:';
$string['manualupdate_installer'] = 'Abrir Instalador de Plugins';
$string['manualupdate_step3'] = 'Suba el archivo ZIP y siga las instrucciones de instalación.';
$string['manualupdate_cli_title'] = 'Alternativa: Línea de Comandos (si tiene acceso al servidor):';

// Descripción de lote para modo estándar.
$string['batchtokensdesc_standard'] = 'Crear tokens de API para múltiples usuarios a la vez.';

// Plantilla CSV.
$string['downloadcsvtemplate'] = 'Descargar Plantilla CSV';
$string['downloadexceltemplate'] = 'Descargar Plantilla Excel';
$string['csvtemplate_instructions'] = 'Complete una de las columnas por fila. Solo necesita un identificador por usuario.';
$string['csvtemplate_id_only'] = 'Para identificar por ID: complete solo la columna id';
$string['csvtemplate_username_only'] = 'Para identificar por nombre de usuario: complete solo la columna username';
$string['csvtemplate_email_only'] = 'Para identificar por email: complete solo la columna email';

// Carga de archivos.
$string['uploadfile'] = 'Subir archivo (CSV o Excel)';
$string['exportexcel'] = 'Exportar como Excel';
$string['fileprocessingerrors'] = 'Errores de procesamiento de archivo';
$string['line'] = 'Línea';

// Gestión de funciones de servicios web.
$string['manageservices'] = 'Gestionar Servicios Web';
$string['manageservicesdesc'] = 'Agregar o eliminar funciones de cualquier servicio web, incluyendo servicios integrados como Moodle mobile.';
$string['servicefunctions'] = 'Funciones del Servicio';
$string['managefunctions'] = 'Gestionar Funciones';
$string['component'] = 'Componente';
$string['builtin'] = 'Integrado';
$string['noservices'] = 'No se encontraron servicios web.';
$string['functionsadded'] = 'Funciones agregadas correctamente.';
$string['functionremoved'] = 'Función eliminada correctamente.';
$string['removefunctionconfirm'] = '¿Está seguro de que desea eliminar la función "{$a->function}" del servicio "{$a->service}"?';
$string['allfunctionsadded'] = 'Todas las funciones disponibles ya han sido agregadas a este servicio.';
$string['selectfunctionstoadd'] = 'Seleccione las funciones que desea agregar a este servicio. Mantenga presionado Ctrl (o Cmd en Mac) para seleccionar múltiples funciones.';
$string['searchfunctions'] = 'Buscar funciones...';
$string['functionselecthelp'] = 'Use Ctrl+Clic para seleccionar múltiples funciones. Use Shift+Clic para seleccionar un rango.';

// Etiqueta de función API (mostrada en UI).
$string['apifunctiontag'] = 'Función API SM Estratoos';
$string['apifunctiontag_desc'] = 'Esta función es proporcionada por el plugin SmartMind Estratoos';

// Errores de funciones con contexto de categoría.
$string['usernotincompany'] = 'El usuario especificado no pertenece a la empresa del token.';
$string['categorynotincompany'] = 'La categoría especificada no está en el árbol de categorías de la empresa.';
