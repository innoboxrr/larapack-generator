/**
 * Rutas de PascalCaseModelName para vue-router.
 *
 * `auth: true` declara que la ruta pide sesion, y es el router de la
 * aplicacion el que lo lee. El modulo no importa ningun middleware del
 * anfitrion: antes lo hacia desde '@router/middleware', un alias que solo
 * existe dentro de la aplicacion que lo define, y fuera de ella el modulo no
 * compilaba. Es el mismo `auth` que las rutas React llevan en `handle`.
 *
 * `title` es un getter: se traduce cada vez que el router lo lee, con el idioma
 * que la aplicacion haya elegido, y no al importar este archivo, que puede
 * cargarse antes de setLocale(). Antes era un texto fijo en espanol con el
 * nombre de la clase ("Editar Products").
 */

import t from 'innoboxrr-i18n'

export default [
	{
		path: 'kebabcasemodelname',
		name: "AdminPluralPascalCaseModelName",
		component: () => import ("./../views/AdminView.vue"),
		meta: {
			get title() { return t('PluralModelLabel') },
			auth: true,
		},
		children: [
			// @larapack:if create
			{
				path: 'create',
				name: "AdminCreatePascalCaseModelName",
				component: () => import ("./../views/CreateView.vue"),
				meta: {
					get title() { return t('Create :name', { name: t('SingularModelLabel') }) },
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
					get title() { return t('SingularModelLabel') },
					auth: true,
				},
				children: [
					// @larapack:if update
					{
						path: 'edit',
						name: "AdminEditPascalCaseModelName",
						component: () => import ("./../views/EditView.vue"),
						meta: {
							get title() { return t('Edit :name', { name: t('SingularModelLabel') }) },
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
