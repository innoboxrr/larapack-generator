/**
 * Rutas del modulo, agregadas desde los modelos.
 *
 * El descubrimiento es por glob de Vite: al generar un modelo nuevo aparece
 * aqui solo, sin tener que enumerarlo. Se hace en modo `eager` porque los
 * archivos de rutas son pequenos y el router los necesita al arrancar.
 */

import { registerRoutes } from 'innoboxrr-react-datatable'

const modules = import.meta.glob('../models/*/routes/index.js', { eager: true })

export const routes = Object.values(modules).flatMap((module) => module.default ?? [])

/**
 * El nombre de cada ruta y su patron completo, recorriendo el arbol.
 *
 * Se deriva del propio arbol y no de un mapa escrito a mano: el nombre y la
 * ruta salen del mismo sitio, asi que no pueden separarse. Y el prefijo es un
 * parametro porque depende de donde monte el anfitrion el modulo — un mapa
 * fijo mentiria en cuanto alguien lo montara en otro sitio.
 *
 * @param {Array} tree
 * @param {string} base
 * @returns {Record<string, string>}
 */
export const routeNamesOf = (tree, base = '') => tree.reduce((names, route) => {
    const path = [base, route.path].filter(Boolean).join('/').replace(/\/{2,}/g, '/')
    const full = path.startsWith('/') ? path : `/${path}`

    return {
        ...names,
        ...(route.id ? { [route.id]: full } : {}),
        ...(route.children ? routeNamesOf(route.children, full) : {}),
    }
}, {})

/**
 * Registra los nombres de ruta del modulo. El anfitrion la llama con el mismo
 * prefijo con el que monte `routes`:
 *
 *     import { routes, registerModuleRoutes } from 'packageName'
 *
 *     registerModuleRoutes('/admin')
 *     createBrowserRouter([{ path: '/admin', children: routes }])
 *
 * @param {string} base
 */
export const registerModuleRoutes = (base = '/admin') => {
    const names = routeNamesOf(routes, base)

    registerRoutes(names)

    return names
}

export default routes
