/**
 * Store de PascalCaseModelName.
 *
 * Zustand es el equivalente de Pinia en React: un store con estado y acciones,
 * sin providers ni reducers. Expone exactamente la misma superficie que el
 * store de Vue del mismo modelo, para que las vistas de los dos frameworks
 * hagan lo mismo con los mismos nombres.
 */

import { create } from 'zustand'

import {
    createModel,
    deleteModel,
    getPolicies,
    indexModel,
    showModel,
    updateModel,
} from '../index'

export const usePascalCaseModelNameStore = create((set, get) => {

    const run = async (operation) => {
        set({ loading: true, error: null })

        try {
            return await operation()
        } catch (error) {
            set({ error })

            throw error
        } finally {
            set({ loading: false })
        }
    }

    return {

        items: [],
        meta: null,
        links: null,

        current: null,
        policies: {},

        loading: false,
        error: null,

        /**
         * @param {string} ability  view, update, delete, export...
         */
        can: (ability) => get().policies[ability] === true,

        fetchIndex: (params = {}) => run(async () => {
            // El index devuelve una coleccion paginada de Laravel:
            // { data, links, meta }.
            const response = await indexModel(params)

            set({
                items: response.data ?? [],
                meta: response.meta ?? null,
                links: response.links ?? null,
            })

            return get().items
        }),

        fetchOne: (id, loadRelations = [], loadCounts = []) => run(async () => {
            const current = await showModel(id, loadRelations, loadCounts)

            set({ current })

            return current
        }),

        fetchPolicies: (id = null) => run(async () => {
            const policies = await getPolicies(id)

            set({ policies })

            return policies
        }),

        create: (data) => run(async () => {
            const created = await createModel(data)

            set((state) => ({ items: [created, ...state.items], current: created }))

            return created
        }),

        update: (id, data) => run(async () => {
            const updated = await updateModel(id, data)

            set((state) => ({
                items: state.items.map((item) => item.id === updated.id ? updated : item),
                current: updated,
            }))

            return updated
        }),

        remove: (id) => run(async () => {
            await deleteModel({ id })

            set((state) => ({
                items: state.items.filter((item) => item.id !== id),
                current: state.current?.id === id ? null : state.current,
            }))
        }),

        reset: () => set({
            items: [],
            meta: null,
            links: null,
            current: null,
            policies: {},
            error: null,
        }),

    }

})

/**
 * `isEmpty` es una computed en Pinia; en Zustand se lee como selector para no
 * repintar cuando cambia otra cosa del store.
 *
 *     const isEmpty = usePascalCaseModelNameStore(selectIsEmpty)
 */
export const selectIsEmpty = (state) => ! state.loading && state.items.length === 0
