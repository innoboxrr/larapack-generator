<template>

    <div :class="classFor('actionMenu')">

        <template v-for="action in items" :key="action.label">

            <RouterLink
                v-if="action.type === 'router'"
                :to="action.to"
                :class="classFor('actionMenuItem')">{{ action.label }}</RouterLink>

            <button
                v-else
                type="button"
                :class="classFor(action.danger ? 'actionMenuDanger' : 'actionMenuItem')"
                @click="action.action">{{ action.label }}</button>

        </template>

    </div>

</template>

<script setup>

    /**
     * Las acciones de un registro.
     *
     * Antes las vistas Vue las delegaban en un <DropdownButtonComponent> que
     * no existe en ningun paquete del ecosistema: el codigo generado solo
     * compilaba si la aplicacion anfitriona lo habia registrado globalmente,
     * sin que ningun import lo declarara. La rama React, por su parte, pintaba
     * las mismas acciones en linea con su propio marcado, asi que las dos
     * ramas divergian en algo que deberia ser identico.
     *
     * El contrato de `items` es el mismo que ya usaban ambas:
     *
     *     { type: 'router', to, label }
     *     { type: 'event', action, label, danger? }
     */

    import { RouterLink } from 'vue-router'
    import { classFor } from 'innoboxrr-form-core'

    defineProps({
        items: {
            type: Array,
            required: true,
        },
    })

</script>
