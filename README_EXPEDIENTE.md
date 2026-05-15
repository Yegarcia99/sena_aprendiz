# Modulo de expediente del aprendiz

Esta actualizacion agrega el flujo acordado en reunion para que el caso llegue a comite con trazabilidad completa.

## Que incluye

- Panel principal profesional en `pages/dashboard.php` con ruta institucional, acciones rapidas y expedientes incompletos.
- Asistente guiado en `pages/asistente_caso.php` para registrar un caso completo desde cero.
- Nueva pantalla: `pages/expediente.php`.
- Registro de planes de mejoramiento de primera y segunda instancia.
- Carga de soportes: actas, control de inasistencia, evidencias, notificaciones y soportes disciplinarios.
- Registro de notificaciones como evidencia dentro del expediente.
- Acciones remediales con soporte adjunto.
- Opcion para justificar que no hubo accion remedial.
- Validacion en comite: si faltan acciones, plan o soportes, no permite remitir salvo que se marque como caso excepcional.

## Base de datos

El sistema crea automaticamente las tablas necesarias al abrir Pendientes, Acciones, Expediente o Comite.
Si se desea migrar manualmente, ejecutar `actualizacion_expediente.sql` una sola vez.

## Flujo recomendado

1. Entrar a `Asistente de Caso`.
2. Registrar el tipo de caso y el momento del proceso.
3. Registrar accion remedial o justificar por que no aplica.
4. Adjuntar soporte y capturar firmas.
5. Crear primera o segunda instancia si el resultado ya finalizo.
6. Registrar notificacion al aprendiz.
7. Revisar el expediente.
8. Cuando el caso este completo, remitir a comite desde el modulo Comite.
