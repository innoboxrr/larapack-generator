<template>

    <form :id="formId" @submit.prevent="onSubmit">

<!-- Add more inputs -->

        <ButtonComponent
            :disabled="disabled"
            :value="t('Update')" />

    </form>

</template>

<script setup>

    import { onMounted, reactive, ref } from 'vue'
    import JSValidator from 'innoboxrr-js-validator'
    import t from 'innoboxrr-i18n'
    import {
        ButtonComponent,
        TextInputComponent,
//import_more_components//
    } from 'innoboxrr-form-elements'

    import { usePascalCaseModelNameStore } from '../store'

    const props = defineProps({
        formId: {
            type: String,
            default: 'editPascalCaseModelNameForm',
        },
        camelCaseModelNameId: {
            type: [Number, String],
            required: true,
        },
//props//
    })

    const emit = defineEmits(['submit'])

    const store = usePascalCaseModelNameStore()

    const disabled = ref(false)
    const validator = ref(null)

    const form = reactive({
//form_fields//
    })

    onMounted(async () => {

        const camelCaseModelName = await store.fetchOne(props.camelCaseModelNameId)

        // Solo se rellenan las claves que el formulario declara; asi un campo
        // nuevo en la API no se cuela en el payload de actualizacion.
        Object.keys(form).forEach((field) => {
            if (camelCaseModelName[field] !== undefined) {
                form[field] = camelCaseModelName[field]
            }
        })

        validator.value = new JSValidator(props.formId).init()
        validator.value.status = true

    })

    const onSubmit = async () => {

        if (! validator.value?.status) {
            return
        }

        disabled.value = true

        try {

            emit('submit', await store.update(props.camelCaseModelNameId, {
//submit_data//
            }))

        } catch (error) {

            if (error.response?.status === 422) {
                validator.value.appendExternalErrors(error.response.data.errors)
            }

        } finally {

            disabled.value = false

        }

    }

</script>
