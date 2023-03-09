import * as middleware from '@router/middleware'

export default [
	{
		path: 'kebabcasemodelname',
		name: "AdminPluralPascalCaseModelName",
		component: () => import (/* webpackChunkName: "AdminPluralPascalCaseModelName"*/ "./../AdminView.vue"),
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
				component: () => import (/* webpackChunkName: "CreatePascalCaseModelName"*/ "./../CreateView.vue"),
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
				component: () => import (/* webpackChunkName: "ShowPascalCaseModelName"*/ "./../ShowView.vue"),
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
				component: () => import (/* webpackChunkName: "EditPascalCaseModelName"*/ "./../EditView.vue"),
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