/**
 * Store de PascalCaseModelName.
 *
 * Sustituye al modulo Vuex que se generaba antes, que ademas nacia vacio y no
 * lo registraba nadie. El id lleva el namespace del paquete para no chocar con
 * el de otro modulo que tenga una entidad con el mismo nombre.
 */

import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import {
    // @larapack:if create
    createModel,
    // @larapack:endif
    // @larapack:if delete
    deleteModel,
    // @larapack:endif
    // @larapack:if policies
    getPolicies,
    // @larapack:endif
    // @larapack:if index
    indexModel,
    // @larapack:endif
    // @larapack:if show
    showModel,
    // @larapack:endif
    // @larapack:if update
    updateModel,
    // @larapack:endif
} from '../index'

export const usePascalCaseModelNameStore = defineStore('dotNamespacesnake_case_model_name', () => {

    const items = ref([])
    const meta = ref(null)
    const links = ref(null)

    const current = ref(null)
    const policies = ref({})

    const loading = ref(false)
    const error = ref(null)

    const isEmpty = computed(() => ! loading.value && items.value.length === 0)

    /**
     * @param {string} ability  view, update, delete, export...
     */
    const can = (ability) => policies.value[ability] === true

    const run = async (operation) => {
        loading.value = true
        error.value = null

        try {
            return await operation()
        } catch (e) {
            error.value = e

            throw e
        } finally {
            loading.value = false
        }
    }

    // @larapack:if index
    const fetchIndex = (params = {}) => run(async () => {
        // El index devuelve una coleccion paginada de Laravel:
        // { data, links, meta }.
        const response = await indexModel(params)

        items.value = response.data ?? []
        meta.value = response.meta ?? null
        links.value = response.links ?? null

        return items.value
    })

    // @larapack:endif
    // @larapack:if show
    const fetchOne = (id, loadRelations = [], loadCounts = []) => run(async () => {
        current.value = await showModel(id, loadRelations, loadCounts)

        return current.value
    })

    // @larapack:endif
    // @larapack:if policies
    const fetchPolicies = (id = null) => run(async () => {
        policies.value = await getPolicies(id)

        return policies.value
    })

    // @larapack:endif
    // @larapack:if create
    const create = (data) => run(async () => {
        const created = await createModel(data)

        items.value = [created, ...items.value]
        current.value = created

        return created
    })

    // @larapack:endif
    // @larapack:if update
    const update = (id, data) => run(async () => {
        const updated = await updateModel(id, data)

        items.value = items.value.map((item) => item.id === updated.id ? updated : item)
        current.value = updated

        return updated
    })

    // @larapack:endif
    // @larapack:if delete
    const remove = (id) => run(async () => {
        await deleteModel({ id })

        items.value = items.value.filter((item) => item.id !== id)

        if (current.value?.id === id) {
            current.value = null
        }
    })

    // @larapack:endif
    const reset = () => {
        items.value = []
        meta.value = null
        links.value = null
        current.value = null
        policies.value = {}
        error.value = null
    }

    return {
        items,
        meta,
        links,
        current,
        policies,
        loading,
        error,
        isEmpty,
        can,
        // @larapack:if index
        fetchIndex,
        // @larapack:endif
        // @larapack:if show
        fetchOne,
        // @larapack:endif
        // @larapack:if policies
        fetchPolicies,
        // @larapack:endif
        // @larapack:if create
        create,
        // @larapack:endif
        // @larapack:if update
        update,
        // @larapack:endif
        // @larapack:if delete
        remove,
        // @larapack:endif
        reset,
    }

})
