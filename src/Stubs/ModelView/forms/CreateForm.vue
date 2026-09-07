<template>

    <form :id="formId" @submit.prevent="onSubmit">

<!-- Add more inputs -->

        <ButtonComponent
            :disabled="disabled"
            :value="t('Create')" />

    </form>

</template>

<script setup>

    import { onMounted, onUnmounted, reactive, ref } from 'vue'
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
            default: 'createPascalCaseModelNameForm',
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

    onMounted(() => {
        validator.value = new JSValidator(props.formId).init()
    })

    // Sin esto, un formulario que se monta y desmonta varias veces —un modal,
    // una vista de edicion— acumula manejadores de submit.
    onUnmounted(() => validator.value?.destroy())

    const onSubmit = async () => {

        // validate() devuelve el resultado, asi que no depende de que el
        // manejador del validador haya corrido antes que este.
        if (! validator.value?.validate()) {
            return
        }

        disabled.value = true

        try {

            emit('submit', await store.create({
//submit_data//
            }))

        } catch (error) {

            // 422 son los errores de validacion del FormRequest de Laravel:
            // se pintan sobre el mismo formulario en lugar de descartarse.
            if (error.response?.status === 422) {
                validator.value.appendExternalErrors(error.response.data.errors)
            }

        } finally {

            disabled.value = false

        }

    }

</script>
