/**
 * El aspecto de este paquete: colores, formas e iconos.
 *
 * Importar esto una vez, al arrancar el modulo, es todo lo que hace falta para
 * que los formularios, la tabla y las vistas tengan aspecto. No hay que
 * cargar UIkit, ni Tailwind, ni Font Awesome: el modulo no depende de ninguno
 * de los tres.
 *
 *     import 'packageName/src/theme.js'
 *
 * ---------------------------------------------------------------------------
 * COLORES Y FORMAS
 *
 * La hoja de estilos trae un sistema de variables CSS. Para cambiar el aspecto
 * de todo el paquete no hace falta tocar `setTheme`: basta con redefinir las
 * variables que interesen en el CSS de la aplicacion.
 *
 *     :root {
 *         --fe-primary: #7c3aed;
 *         --fe-radius: 10px;
 *         --fe-density: 0.875;   // interfaz mas compacta
 *     }
 *
 * El modo oscuro ya esta resuelto: responde a la preferencia del sistema y a
 * un `data-theme="dark"` en la raiz, sin que haya que declarar nada.
 *
 * ---------------------------------------------------------------------------
 * CLASES
 *
 * `setTheme` sirve para lo otro: apuntar un token a las clases de otro sistema
 * visual, si la aplicacion ya tiene el suyo.
 *
 *   envoltorios  field, fieldInner, label, help, helpIcon, error
 *   controles    input, select, textarea, checkbox, radio, file
 *   botones      button, buttonSecondary, buttonDanger, buttonLink
 *   navegacion   breadcrumb, breadcrumbLink, breadcrumbCurrent,
 *                breadcrumbSeparator, actionMenu, actionMenuItem,
 *                actionMenuDanger
 *   superficies  surface, surfaceRaised, toolbar, badge, skeleton, overlay,
 *                dialog, drawer, menu, menuItem, toast, toastDanger,
 *                toastSuccess
 *   tabla        table, tableNumeric
 *
 * ---------------------------------------------------------------------------
 * ICONOS
 *
 * Los iconos se piden por nombre semantico y el mapa decide de que coleccion
 * salen. Son mas de 200.000 iconos de mas de 150 colecciones bajo un solo
 * esquema, asi que darle personalidad propia al paquete es cambiar el mapa:
 *
 *     setIcons({ plus: 'lucide:plus', delete: 'lucide:trash-2' })
 *
 * Un nombre de coleccion se puede pasar entero donde haga falta uno suelto:
 * `<IconComponent name="mdi:home" />` funciona sin darlo de alta.
 */

import 'innoboxrr-form-core/styles'

import { setIcons, setTheme } from 'innoboxrr-form-core'

setTheme({
    // input: 'form-control',
    // button: 'btn btn-primary',
})

setIcons({
    // plus: 'lucide:plus',
    // delete: 'lucide:trash-2',
})

export { getIcon, getTheme, iconFor, setIcons, setTheme } from 'innoboxrr-form-core'
