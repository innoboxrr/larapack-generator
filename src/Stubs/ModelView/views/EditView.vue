<template>

    <EditForm
        :key="route.params.id"
        :kebabcasemodelname-id="route.params.id"
        @submit="onUpdated" />

</template>

<script setup>

    /**
     * La edición de PascalCaseModelName.
     *
     * Vive dentro del drawer que el detalle abre sobre la ficha. Como el alta,
     * no decide a dónde ir después: avisa con `updateData`, y el detalle cierra
     * el drawer, recarga el registro y se lo dice al usuario.
     */

    import { onMounted } from 'vue'
    import { useRoute } from 'vue-router'

    import EditForm from '../forms/EditForm.vue'
    import { getPolicy } from '../index'

    const route = useRoute()

    const emit = defineEmits(['updateData'])

    onMounted(async () => {

        const policy = await getPolicy('update', route.params.id)

        if (! policy.update) {
            // router.push({ name: 'NotAuthorized' })
        }

    })

    const onUpdated = (camelCaseModelName) => emit('updateData', camelCaseModelName)

</script>
