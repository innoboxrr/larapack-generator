<template>

    <CreateForm @submit="onCreated" />

</template>

<script setup>

    /**
     * El alta de PascalCaseModelName.
     *
     * Vive dentro del drawer que la vista del índice abre sobre la tabla, así
     * que no pinta migas ni título —los pone el drawer— ni decide a dónde ir
     * después: avisa con `updateData`, y el índice cierra el drawer, recarga la
     * tabla y se lo dice al usuario. La ruta conserva su nombre, así que un
     * enlace a AdminCreatePascalCaseModelName sigue abriendo el alta.
     */

    import { onMounted } from 'vue'

    import CreateForm from '../forms/CreateForm.vue'
    import { getPolicy } from '../index'

    const emit = defineEmits(['updateData'])

    onMounted(async () => {

        const policy = await getPolicy('create')

        if (! policy.create) {
            // router.push({ name: 'NotAuthorized' })
        }

    })

    const onCreated = (camelCaseModelName) => emit('updateData', camelCaseModelName)

</script>
