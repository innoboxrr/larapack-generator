import * as middleware from '@router/middleware'

export default [
	{
		path: 'kebabcasemodelname',
		name: "AdminPluralPascalCaseModelName",
		component: () => import ("./../views/AdminView.vue"),
		meta: {
			title: 'PluralPascalCaseModelName',
			middleware: [
				middleware.auth
			]
		},
		children: [
			{
				path: 'create',
				name: "AdminCreatePascalCaseModelName",
				component: () => import ("./../views/CreateView.vue"),
				meta: {
					title: 'Crear PluralPascalCaseModelName',
					middleware: [
						middleware.auth
					]
				}
			},
			{
				path: ':id',
				name: "AdminShowPascalCaseModelName",
				component: () => import ("./../views/ShowView.vue"),
				meta: {
					title: 'Ver PluralPascalCaseModelName',
					middleware: [
						middleware.auth
					]
				},
				children: [
					{
						path: 'edit',
						name: "AdminEditPascalCaseModelName",
						component: () => import ("./../views/EditView.vue"),
						meta: {
							title: 'Editar PluralPascalCaseModelName',
							middleware: [
								middleware.auth
							]
						}
					},
				]
			},
		]
	},
]