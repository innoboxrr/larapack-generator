/**
 * Punto de entrada del modulo packageName.
 *
 * El anfitrion monta las rutas y, si quiere, instala el plugin:
 *
 *     import module, { routes } from 'packageName'
 *
 *     app.use(module)
 *     router.addRoute({ path: '/admin', children: routes })
 */

import moduleRoutes from './src/routes'

export const routes = moduleRoutes

export default {
    install(app, options = {}) {
        // Registra aqui los componentes globales del modulo, si los hubiera.
    },
}
