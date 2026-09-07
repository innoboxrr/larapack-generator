/**
 * Punto de entrada del modulo packageName (React).
 *
 * El anfitrion registra los nombres de ruta con el mismo prefijo con el que
 * monta las rutas, y ya:
 *
 *     import { routes, registerModuleRoutes } from 'packageName'
 *
 *     registerModuleRoutes('/admin')
 *     createBrowserRouter([{ path: '/admin', children: routes }])
 *
 * El registro es lo que permite que el contrato del modelo apunte a sus vistas
 * por nombre, igual que en Vue: `models/<entidad>/index.js` es el mismo
 * archivo en los dos frameworks.
 */

import moduleRoutes, { registerModuleRoutes as register, routeNamesOf } from './src/routes'

export const routes = moduleRoutes

export const registerModuleRoutes = register

export { routeNamesOf }

export default routes
