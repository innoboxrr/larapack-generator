/**
 * Rutas de PascalCaseModelName para vue-router.
 *
 * `auth: true` declara que la ruta pide sesion, y es el router de la
 * aplicacion el que lo lee. El modulo no importa ningun middleware del
 * anfitrion: antes lo hacia desde '@router/middleware', un alias que solo
 * existe dentro de la aplicacion que lo define, y fuera de ella el modulo no
 * compilaba. Es el mismo `auth` que las rutas React llevan en `handle`.
 */

export default [
	{
		path: 'kebabcasemodelname',
		name: "AdminPluralPascalCaseModelName",
		component: () => import ("./../views/AdminView.vue"),
		meta: {
			title: 'PluralPascalCaseModelName',
			auth: true,
		},
		children: [
			// @larapack:if create
			{
				path: 'create',
				name: "AdminCreatePascalCaseModelName",
				component: () => import ("./../views/CreateView.vue"),
				meta: {
					title: 'Crear PluralPascalCaseModelName',
					auth: true,
				}
			},
			// @larapack:endif
			// @larapack:if show
			{
				path: ':id',
				name: "AdminShowPascalCaseModelName",
				component: () => import ("./../views/ShowView.vue"),
				meta: {
					title: 'Ver PluralPascalCaseModelName',
					auth: true,
				},
				children: [
					// @larapack:if update
					{
						path: 'edit',
						name: "AdminEditPascalCaseModelName",
						component: () => import ("./../views/EditView.vue"),
						meta: {
							title: 'Editar PluralPascalCaseModelName',
							auth: true,
						}
					},
					// @larapack:endif
				]
			},
			// @larapack:endif
		]
	},
]
