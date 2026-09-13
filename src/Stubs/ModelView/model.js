/**
 * Contrato del modelo PascalCaseModelName.
 *
 * Este archivo no depende de ningun framework de UI: son funciones puras y
 * llamadas HTTP. Vue y React consumen exactamente el mismo modulo; solo
 * cambian los componentes que lo usan.
 *
 * El prefijo de rutas tiene que coincidir con el `->as('api.dotNamespace...')`
 * del RouteServiceProvider del paquete. Si cambia uno, cambia el otro.
 */

import makeHttpRequest from 'innoboxrr-http-request'
// @larapack:if delete|forceDelete|export
import { RequestCancelledError } from 'innoboxrr-http-request'
import { confirmAction } from 'innoboxrr-form-core'
// @larapack:endif
import route from 'innoboxrr-route-resolver'
import t from 'innoboxrr-i18n'

export const API_ROUTE_PREFIX = 'api.dotNamespacesnake_case_model_name.'

/**
 * Se lee bajo demanda y no al importar el modulo: el meta tag puede no existir
 * todavia (o nunca, en una prueba) y antes eso reventaba la carga del modulo.
 *
 * @returns {string}
 */
export const csrfToken = () => {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}

// FILTROS
// Estado de modulo, no reactivo: es el contrato que espera el datatable, que
// llama a setFilters()/getFilters() por su cuenta.

let filters = {}

export const setFilters = (newFilters = {}) => {
    filters = { ...filters, ...newFilters }

    return filters
}

export const getFilters = () => filters

export const resetFilters = () => {
    filters = {}

    return filters
}

// TABLA

/**
 * Acciones de la barra superior del datatable.
 *
 * `route: true` hace que se renderice como router-link hacia `params.to.name`;
 * `route: false` invoca `model[callback](params)`, asi que el callback tiene
 * que ser un export real de este archivo.
 */
export const crudActions = () => [
    // @larapack:if create
    {
        id: 'create',
        name: t('Create'),
        callback: null,
        icon: 'plus',
        route: true,
        policy: false,
        params: {
            to: {
                name: 'AdminCreatePascalCaseModelName',
                params: {},
            },
        },
    },
    // @larapack:endif
    // @larapack:if export
    {
        id: 'export',
        name: t('Export'),
        callback: 'exportModel',
        icon: 'download',
        route: false,
        policy: false,
        params: {},
    },
    // @larapack:endif
]

export const dataTableHead = () => [
    {
        id: 'id',
        value: 'ID',
        sortable: true,
        html: false,
    },
//DATA_TABLE_COLUMNS//
    /*
    {
        id: 'column',
        value: 'Column',
        sortable: true,
        html: false,
        parser: (value, row) => value,
    },
    */
]

export const dataTableSort = () => ({
//DATA_TABLE_SORT//
    id: 'asc',
})

// PERMISOS
// @larapack:if policies

export const getPolicies = (modelId = null) => {
    return makeHttpRequest('get', route(API_ROUTE_PREFIX + 'policies'), {
        _token: csrfToken(),
        id: modelId,
    }, {}, 3, 1500)
}
// @larapack:endif
// @larapack:if policy

export const getPolicy = (policy, modelId = null) => {
    return makeHttpRequest('get', route(API_ROUTE_PREFIX + 'policy'), {
        _token: csrfToken(),
        policy,
        id: modelId,
    }, {}, 3, 1500)
}
// @larapack:endif

// @larapack:if delete|forceDelete|export
/**
 * Pregunta antes de lo que no se deshace, con el diálogo del tema
 * (ConfirmHostComponent). Antes lo hacía un SweetAlert con sus propios colores
 * escritos aquí, que no seguía el tema ni el modo oscuro.
 *
 * Cancelar rechaza con RequestCancelledError, lo mismo que hacía aquel diálogo:
 * la tabla y las vistas lo reconocen y no avisan de una operación que el
 * usuario decidió no hacer.
 */
const confirmOrCancel = async (options) => {
    if (! await confirmAction(options)) {
        throw new RequestCancelledError()
    }
}

// @larapack:endif
// CRUD
// @larapack:if index

export const indexModel = (params = {}) => {
    return makeHttpRequest('get', route(API_ROUTE_PREFIX + 'index'), {
        _token: csrfToken(),
        ...params,
    }, {}, 3, 1500)
}
// @larapack:endif
// @larapack:if show

export const showModel = (modelId, loadRelations = [], loadCounts = [], data = {}) => {
    return makeHttpRequest('get', route(API_ROUTE_PREFIX + 'show'), {
        _token: csrfToken(),
        snake_case_model_name_id: modelId,
        load_relations: loadRelations,
        load_counts: loadCounts,
        ...data,
    }, {}, 3, 1500)
}
// @larapack:endif
// @larapack:if create

export const createModel = (data) => {
    return makeHttpRequest('post', route(API_ROUTE_PREFIX + 'create'), {
        _token: csrfToken(),
        ...data,
    }, {}, 0, 1500)
}
// @larapack:endif
// @larapack:if update

export const updateModel = (modelId, data) => {
    return makeHttpRequest('put', route(API_ROUTE_PREFIX + 'update'), {
        _token: csrfToken(),
        ...data,
        snake_case_model_name_id: modelId,
    }, {}, 0, 1500)
}
// @larapack:endif
// @larapack:if delete

/**
 * La ruta esta registrada como Route::delete. El method spoofing de Laravel
 * (`_method`) solo se aplica a peticiones POST de formulario, no a un cuerpo
 * JSON, asi que enviar POST aqui devolvia 405.
 */
export const deleteModel = async (data) => {
    await confirmOrCancel({
        title: t('Confirm operation'),
        message: t('Are you sure you want to delete this item?'),
        confirmLabel: t('Yes, delete'),
        cancelLabel: t('Cancel'),
        variant: 'danger',
    })

    return makeHttpRequest('delete', route(API_ROUTE_PREFIX + 'delete'), {
        _token: csrfToken(),
        snake_case_model_name_id: data.id,
    }, {}, 0, 1500)
}
// @larapack:endif
// @larapack:if restore

export const restoreModel = (data) => {
    return makeHttpRequest('post', route(API_ROUTE_PREFIX + 'restore'), {
        _token: csrfToken(),
        snake_case_model_name_id: data.id,
    }, {}, 0, 1500)
}
// @larapack:endif
// @larapack:if forceDelete

export const forceDeleteModel = async (data) => {
    await confirmOrCancel({
        title: t('Confirm operation'),
        message: t('This will permanently delete the item.'),
        confirmLabel: t('Yes, delete permanently'),
        cancelLabel: t('Cancel'),
        variant: 'danger',
    })

    return makeHttpRequest('delete', route(API_ROUTE_PREFIX + 'force.delete'), {
        _token: csrfToken(),
        snake_case_model_name_id: data.id,
    }, {}, 0, 1500)
}
// @larapack:endif
// @larapack:if export

export const exportModel = async (data = {}) => {
    await confirmOrCancel({
        title: t('Confirm operation'),
        message: t('Are you sure you want to export this item?'),
        confirmLabel: t('Yes, continue'),
        cancelLabel: t('Cancel'),
    })

    return makeHttpRequest('post', route(API_ROUTE_PREFIX + 'export'), {
        _token: csrfToken(),
        ...data,
    }, {}, 0, 1500)
}
// @larapack:endif
