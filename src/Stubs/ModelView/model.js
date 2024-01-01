import makeHttpRequest from 'innoboxrr-http-request'

export const API_ROUTE_PREFIX = 'api.snake_case_model_name.'; // Reemplaza con la ruta adecuada

export const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content'); // Reemplaza con el token adecuado

export let filters = {}

export let strings = {
  crudActions: {
    create: {
      name: 'Create',
      icon: 'fa-plus',
    },
    export: {
      name: 'Export',
      icon: 'fa-download',
    },
  },
  exportModel: {
    confirmation: {
      title: 'Confirm operation',
      text: 'Confirm export',
    },
  },
  deleteModel: {
    confirmation: {
      title: 'Confirm operation',
      text: 'Confirm delete',
    },
  },
};

export const setFilters = (newFilters = {}) => {

	filters = {

		...filters,

		...newFilters,

	}

	return filters;
};

export const getFilters = () => {

	return filters;
};

export const resetFilters = () => {

	filters = {};

	return filters;
};

export const crudActions = () => {

	return [
		{
			id: 'create',
			name: strings.crudActions.create.name,
			callback: 'createPascalCaseModelName',
			icon: strings.crudActions.create.icon,
			route: true,
			policy: false,
			params: {
				to: {
					name: 'AdminCreatePascalCaseModelName',
					params: {}
				}
			}
		},
		{
			id: 'export',
			name: strings.crudActions.export.name,
			callback: 'exportModel',
			icon: strings.crudActions.export.icon,
			route: false,
			policy: false,
			params: {}
		}
	];
};

export const dataTableHead = () => {
	return [
		{
			id: 'id',
			value: 'ID',
			sortable: true,
			html: false,
		},
		/*
		{
			id: 'column',
			value: 'Column',
			sortable: true,
			html: false,
			parser: (value) => {

				return value;

			}
		},
		*/
	];
};

export const dataTableSort = () => {
	return {
		id: 'asc',
		// Añadir columnas ordenables
	};
};

export const getPolicies = (modelId = null) => {
    return makeHttpRequest('get', route(API_ROUTE_PREFIX + 'policies'), {
        _token: CSRF_TOKEN,
        id: modelId,
    }, {}, 3, 1500);
};

export const getPolicy = (policy, modelId = null) => {
    return makeHttpRequest('get', route(API_ROUTE_PREFIX + 'policy'), {
        _token: CSRF_TOKEN,
        policy: policy,
        id: modelId,
    }, {}, 3, 1500);
};

export const showModel = (modelId, loadRelations = [], loadCounts = [], data = {}) => {
    return makeHttpRequest('get', route(API_ROUTE_PREFIX + 'show'), {
        _token: CSRF_TOKEN,
        snake_case_model_name_id: modelId,
        load_relations: loadRelations,
        load_counts: loadCounts,
        ...data,
    }, {}, 3, 1500);
};

export const indexModel = (filters = {}) => {
    return makeHttpRequest('get', route(API_ROUTE_PREFIX + 'index'), {
        _token: CSRF_TOKEN,
        ...filters,
    }, {}, 3, 1500);
};

export const createModel = (data) => {
    return makeHttpRequest('post', route(API_ROUTE_PREFIX + 'create'), {
        _token: CSRF_TOKEN,
        ...data,
    }, {}, 1, 1500);
};

export const updateModel = (modelId, data) => {
    return makeHttpRequest('put', route(API_ROUTE_PREFIX + 'update'), {
        _token: CSRF_TOKEN,
        ...data,
        snake_case_model_name_id: modelId,
    }, {}, 1, 1500);
};

export const deleteModel = (data) => {
    const confirmOptions = {
        title: strings.deleteModel.confirmation.title,
        text: strings.deleteModel.confirmation.text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: t('Yes, delete'),
    };
    return makeHttpRequest('post', route(API_ROUTE_PREFIX + 'delete'), {
        _token: CSRF_TOKEN,
        _method: 'DELETE',
        snake_case_model_name_id: data.id,
    }, {}, 3, 1500, confirmOptions);
};

export const exportModel = (data) => {
    const confirmOptions = {
        title: strings.exportModel.confirmation.title,
        text: strings.exportModel.confirmation.text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: t('Yes, continue'),
    };
    return makeHttpRequest('post', route(API_ROUTE_PREFIX + 'export'), {
        _token: CSRF_TOKEN,
        ...data,
    }, {}, 3, 1500, confirmOptions);
};
