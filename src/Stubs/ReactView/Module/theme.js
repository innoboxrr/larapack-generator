/**
 * El aspecto de los formularios de este paquete.
 *
 * Antes las clases del input y del boton se esperaban de un mixin global que la
 * aplicacion anfitriona tenia que registrar sin que nada lo dijera: el modulo
 * no se podia montar fuera de esa aplicacion, ni probar.
 *
 * Ahora es un tema. Se ajusta aqui, una vez, y lo leen todos los componentes de
 * innoboxrr-react-form-elements en todos los modelos del paquete.
 *
 * Los tokens disponibles:
 *
 *   envoltorios  field, fieldInner, label, help, helpIcon, error
 *   controles    input, select, textarea, checkbox, radio, file
 *   botones      button, buttonSecondary, buttonDanger, buttonLink
 *
 * Los valores de fabrica son los de UIkit + Tailwind; solo hace falta declarar
 * lo que se quiera cambiar.
 */

import { setTheme } from 'innoboxrr-form-core'

export default setTheme({
    // input: 'form-control',
    // button: 'btn btn-primary',
})

export { getTheme, setTheme } from 'innoboxrr-form-core'
