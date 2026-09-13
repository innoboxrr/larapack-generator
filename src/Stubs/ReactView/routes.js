/**
 * Rutas de PascalCaseModelName para React Router 7.
 *
 * Cada ruta lleva `id`, y ese id es el nombre al que apunta el contrato del
 * modelo (`params.to.name`), igual que en Vue, porque `index.js` es el mismo
 * archivo en los dos frameworks.
 *
 * React Router no resuelve rutas por nombre; el agregador del modulo recorre
 * este arbol y registra los nombres en innoboxrr-react-datatable, que es quien
 * los traduce a rutas. Asi el nombre y la ruta salen del mismo sitio y no
 * pueden separarse.
 *
 * Los componentes se cargan con `lazy` para que este archivo siga siendo JS
 * plano, sin JSX, como su gemelo de Vue.
 *
 * `title` es un getter: se traduce cada vez que se lee, con el idioma que la
 * aplicacion haya elegido, y no al importar este archivo, que puede cargarse
 * antes de setLocale().
 */

import t from 'innoboxrr-i18n'

export default [
    {
        path: 'kebabcasemodelname',
        id: 'AdminPluralPascalCaseModelName',
        handle: { get title() { return t('PluralModelLabel') }, auth: true },
        lazy: async () => ({ Component: (await import('../views/AdminView.jsx')).default }),
        children: [
            // @larapack:if create
            {
                path: 'create',
                id: 'AdminCreatePascalCaseModelName',
                handle: { get title() { return t('Create :name', { name: t('SingularModelLabel') }) }, auth: true },
                lazy: async () => ({ Component: (await import('../views/CreateView.jsx')).default }),
            },
            // @larapack:endif
            // @larapack:if show
            {
                path: ':id',
                id: 'AdminShowPascalCaseModelName',
                handle: { get title() { return t('SingularModelLabel') }, auth: true },
                lazy: async () => ({ Component: (await import('../views/ShowView.jsx')).default }),
                children: [
                    // @larapack:if update
                    {
                        path: 'edit',
                        id: 'AdminEditPascalCaseModelName',
                        handle: { get title() { return t('Edit :name', { name: t('SingularModelLabel') }) }, auth: true },
                        lazy: async () => ({ Component: (await import('../views/EditView.jsx')).default }),
                    },
                    // @larapack:endif
                ],
            },
            // @larapack:endif
        ],
    },
]
