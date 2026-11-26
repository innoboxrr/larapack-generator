/**
 * Contrato del controlador PascalCaseModelName (`controllers[]` del laraimport).
 *
 * No tiene modelo, ni tabla, ni pantalla: una funcion por endpoint declarado,
 * que llama a su ruta por nombre. Quien tenga la pantalla —un escritorio, un
 * asistente— la importa de aqui. Es el mismo archivo en Vue y en React.
 *
 * El prefijo de rutas tiene que coincidir con el `->as('api.dotNamespace...')`
 * del RouteServiceProvider del paquete. Si cambia uno, cambia el otro.
 */

import makeHttpRequest from 'innoboxrr-http-request'
import route from 'innoboxrr-route-resolver'

export const API_ROUTE_PREFIX = 'api.dotNamespacesnake_case_model_name.'

/**
 * Se lee bajo demanda: el meta tag puede no existir todavia. Solo lo mandan
 * las escrituras; en un GET acabaria en la URL.
 *
 * @returns {string}
 */
export const csrfToken = () => {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}
//ENDPOINT_SERVICES//
