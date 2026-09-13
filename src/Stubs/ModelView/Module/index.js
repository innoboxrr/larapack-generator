/**
 * Punto de entrada del modulo packageName.
 *
 * El anfitrion carga sus textos, monta las rutas y, si quiere, instala el
 * plugin:
 *
 *     import module, { routes, translations } from 'packageName'
 *
 *     addTranslations(translations)
 *     app.use(module)
 *     router.addRoute({ path: '/admin', children: routes })
 */

import moduleRoutes from './src/routes'

export const routes = moduleRoutes

export { translations } from './src/i18n.js'

export default {
    install(app, options = {}) {
        // Registra aqui los componentes globales del modulo, si los hubiera.
    },
}
