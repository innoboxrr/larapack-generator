import * as middleware from '@router/middleware'

export default [
	{
		path: 'kebabcasemodelname',
		name: "AdminPluralPascalCaseModelName",
		component: () => import (/* webpackChunkName: "AdminPluralPascalCaseModelName"*/ "./../AdminPluralPascalCaseModelName"),
		meta: {
			title: 'Panel de administración',
			middleware: [
				middleware.auth
			]
		},
		children: [
			{
				path: 'create',
				name: "AdminCreatePascalCaseModelName",
				component: () => import (/* webpackChunkName: "CreatePascalCaseModelName"*/ "./../CreatePascalCaseModelName"),
				meta: {
					title: 'Crear',
					middleware: [
						middleware.auth
					]
				}
			},
			{
				path: ':id/show',
				name: "AdminShowPascalCaseModelName",
				component: () => import (/* webpackChunkName: "ShowPascalCaseModelName"*/ "./../ShowPascalCaseModelName"),
				meta: {
					title: undefined,
					middleware: [
						middleware.auth
					]
				}
			},
			{
				path: ':id/edit',
				name: "AdminEditPascalCaseModelName",
				component: () => import (/* webpackChunkName: "EditPascalCaseModelName"*/ "./../EditPascalCaseModelName"),
				meta: {
					title: 'Editar',
					middleware: [
						middleware.auth
					]
				}
			},
		]
	},
]