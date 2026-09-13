<template>

    <MenuComponent :items="menuItems" :label="label">

        <template #trigger="{ toggle, loading, triggerProps }">
            <button
                type="button"
                :class="classFor('iconButton')"
                v-bind="triggerProps"
                :disabled="loading"
                @click="toggle">
                <IconComponent name="more" :size="16" />
            </button>
        </template>

    </MenuComponent>

</template>

<script setup>

    /**
     * Las acciones de un registro, en un menú desplegable.
     *
     * Antes las vistas Vue las delegaban en un <DropdownButtonComponent> que
     * no existe en ningun paquete del ecosistema, y despues se pintaron como
     * una lista de enlaces siempre abierta. Ahora es el MenuComponent de
     * innoboxrr-form-elements: se abre con el teclado, se cierra con Escape y
     * al pulsar fuera, y devuelve el foco al boton.
     *
     * El contrato de `items` es el mismo en Vue y en React:
     *
     *     { type: 'router', to, label, icon? }
     *     { type: 'event', action, label, icon?, danger? }
     */

    import { computed } from 'vue'
    import { useRouter } from 'vue-router'
    import { classFor } from 'innoboxrr-form-core'
    import { IconComponent, MenuComponent } from 'innoboxrr-form-elements'

    const props = defineProps({
        items: {
            type: Array,
            required: true,
        },
        label: {
            type: String,
            default: 'Acciones',
        },
    })

    const router = useRouter()

    const menuItems = computed(() => props.items.map((item, index) => ({
        id: item.id ?? `${item.label}-${index}`,
        label: item.label,
        icon: item.icon,
        danger: item.danger === true,
        disabled: item.disabled === true,
        disabledReason: item.disabledReason,
        action: item.type === 'router' ? () => router.push(item.to) : item.action,
    })))

</script>
