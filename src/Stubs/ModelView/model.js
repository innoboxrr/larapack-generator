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
	return new Promise((resolve, reject) => {
		const maxRetries = 3;
		const retryInterval = 1500;

		const makeRequest = (retryCount) => {
			axios
				.get(route(API_ROUTE_PREFIX + 'policies'), {
					params: {
						_token: CSRF_TOKEN,
						id: modelId,
					},
				})
				.then((res) => {
					resolve(res);
				})
				.catch((error) => {
					if (retryCount < maxRetries) {
						setTimeout(() => {
							makeRequest(retryCount + 1);
						}, retryInterval);
					} else {
						reject(error); // Mensaje de error más descriptivo
					}
				});
		};

		makeRequest(0);
	});
};

export const getPolicy = (policy, modelId = null) => {
	return new Promise((resolve, reject) => {
		const maxRetries = 3;
		const retryInterval = 1500;

		const makeRequest = (retryCount) => {
			axios
				.get(route(API_ROUTE_PREFIX + 'policy'), {
					params: {
						_token: CSRF_TOKEN,
						policy: policy,
						id: modelId,
					},
				})
				.then((res) => {
					resolve(res);
				})
				.catch((error) => {
					if (retryCount < maxRetries) {
						setTimeout(() => {
							makeRequest(retryCount + 1);
						}, retryInterval);
					} else {
						reject(error); // Mensaje de error más descriptivo
					}
				});
		};

		makeRequest(0);
	});
};

export const showModel = (modelId, loadRelations = []) => {
	return new Promise((resolve, reject) => {
		const maxRetries = 3;
		const retryInterval = 1500;

		const makeRequest = (retryCount) => {
			axios
				.get(route(API_ROUTE_PREFIX + 'show'), {
					params: {
						_token: CSRF_TOKEN,
						snake_case_model_name_id: modelId,
						load_relations: loadRelations,
					},
				})
				.then((res) => {
					resolve(res);
				})
				.catch((error) => {
					if (retryCount < maxRetries) {
						setTimeout(() => {
							makeRequest(retryCount + 1);
						}, retryInterval);
					} else {
						reject(error); // Mensaje de error más descriptivo
					}
				});
		};

		makeRequest(0);
	});
};

export const indexModel = (filters = {}) => {
	return new Promise((resolve, reject) => {
		const maxRetries = 3;
		const retryInterval = 1500;

		const makeRequest = (retryCount) => {
			axios
				.get(route(API_ROUTE_PREFIX + 'index'), {
					params: {
						_token: CSRF_TOKEN,
						...filters,
					},
				})
				.then((res) => {
					resolve(res);
				})
				.catch((error) => {
					if (retryCount < maxRetries) {
						setTimeout(() => {
							makeRequest(retryCount + 1);
						}, retryInterval);
					} else {
						reject(error); // Mensaje de error más descriptivo
					}
				});
		};

		makeRequest(0);
	});
};

export const createModel = (data) => {
	return new Promise((resolve, reject) => {
		const maxRetries = 1;
		const retryInterval = 1500;

		const makeRequest = (retryCount) => {
			axios
				.post(route(API_ROUTE_PREFIX + 'create'), {
					_token: CSRF_TOKEN,
					...data,
				})
				.then((res) => {
					resolve(res);
				})
				.catch((error) => {
					if (retryCount < maxRetries) {
						setTimeout(() => {
							makeRequest(retryCount + 1);
						}, retryInterval);
					} else {
						reject(error); // Mensaje de error más descriptivo
					}
				});
		};

		makeRequest(0);
	});
};

export const updateModel = (modelId, data) => {
	return new Promise((resolve, reject) => {
		const maxRetries = 1;
		const retryInterval = 1500;

		const makeRequest = (retryCount) => {
			axios
				.put(route(API_ROUTE_PREFIX + 'update'), {
					_token: CSRF_TOKEN,
					...data,
					snake_case_model_name_id: modelId,
				})
				.then((res) => {
					resolve(res);
				})
				.catch((error) => {
					if (retryCount < maxRetries) {
						setTimeout(() => {
							makeRequest(retryCount + 1);
						}, retryInterval);
					} else {
						reject(error); // Mensaje de error más descriptivo
					}
				});
		};

		makeRequest(0);
	});
};

export const deleteModel = (data) => {
	return new Promise((resolve, reject) => {
		const maxRetries = 3;
		const retryInterval = 1500;

		const makeRequest = (retryCount) => {
			Swal.fire({
				title: strings.deleteModel.confirmation.title,
				text: strings.deleteModel.confirmation.text,
				icon: 'warning',
				showCancelButton: true,
				confirmButtonColor: '#3085d6',
				cancelButtonColor: '#d33',
				confirmButtonText: t('Yes, delete'),
			}).then((result) => {
				if (result.isConfirmed) {
					axios
						.post(route(API_ROUTE_PREFIX + 'delete'), {
							_token: CSRF_TOKEN,
							_method: 'DELETE',
							snake_case_model_name_id: data.id,
						})
						.then((res) => {
							resolve({
								message: t('Operation successful')
							});
						})
						.catch((error) => {
							reject({
								message: error.response.data.message
							});
						});
				} else {
					Swal.fire(t('Operation canceled'), t('The operation has been canceled'), 'error');
				}
			});
		};

		makeRequest(0);
	});
};

export const exportModel = (data) => {
	return new Promise((resolve, reject) => {
		const maxRetries = 3;
		const retryInterval = 1500;

		const makeRequest = (retryCount) => {
			Swal.fire({
				title: strings.exportModel.confirmation.title,
				text: strings.exportModel.confirmation.text,
				icon: 'warning',
				showCancelButton: true,
				confirmButtonColor: '#3085d6',
				cancelButtonColor: '#d33',
				confirmButtonText: t('Yes, continue'),
			}).then((result) => {
				if (result.isConfirmed) {
					axios
						.post(route(API_ROUTE_PREFIX + 'export'), {
							_token: CSRF_TOKEN,
							...filters,
						})
						.then((res) => {
							resolve({
								message: t('You will receive an email with the download file in a few seconds')
							});
						})
						.catch((error) => {
							reject({
								message: error.response.data.message
							});
						});
				} else {
					Swal.fire(t('Operation canceled'), t('The operation has been canceled'), 'error');
				}
			});
		};

		makeRequest(0);
	});
};