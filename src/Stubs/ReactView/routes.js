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
 */

export default [
    {
        path: 'kebabcasemodelname',
        id: 'AdminPluralPascalCaseModelName',
        handle: { title: 'PluralPascalCaseModelName', auth: true },
        lazy: async () => ({ Component: (await import('../views/AdminView.jsx')).default }),
        children: [
            {
                path: 'create',
                id: 'AdminCreatePascalCaseModelName',
                handle: { title: 'Crear PluralPascalCaseModelName', auth: true },
                lazy: async () => ({ Component: (await import('../views/CreateView.jsx')).default }),
            },
            {
                path: ':id',
                id: 'AdminShowPascalCaseModelName',
                handle: { title: 'Ver PluralPascalCaseModelName', auth: true },
                lazy: async () => ({ Component: (await import('../views/ShowView.jsx')).default }),
                children: [
                    {
                        path: 'edit',
                        id: 'AdminEditPascalCaseModelName',
                        handle: { title: 'Editar PluralPascalCaseModelName', auth: true },
                        lazy: async () => ({ Component: (await import('../views/EditView.jsx')).default }),
                    },
                ],
            },
        ],
    },
]
