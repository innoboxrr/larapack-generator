<template>

    <form :id="formId" @submit.prevent="onSubmit">

        <div class="uk-flex uk-flex-left uk-child-width-1-4@m uk-child-width-1-1@s" uk-grid>

            <div>
                <TextInputComponent
                    :custom-class="inputClass"
                    type="text"
                    name="id"
                    label="ID"
                    placeholder="ID"
                    v-model="form.id" />
            </div>

<!-- Add more inputs -->

        </div>

        <div class="uk-flex uk-flex-right uk-child-width-auto@m uk-child-width-1-1@m" uk-grid>
            <div>
                <button :class="buttonClass">
                    {{ t('Search') }}
                </button>
            </div>
            <div>
                <button
                    :class="buttonClass + ' bg-gray-400'"
                    @click.prevent="resetForm">
                    {{ t('Reset') }}
                </button>
            </div>
        </div>

    </form>

</template>

<script setup>

    import { reactive } from 'vue'
    import t from 'innoboxrr-i18n'
    import {
        TextInputComponent,
//import_more_components//
    } from 'innoboxrr-form-elements'

    defineProps({
        formId: {
            type: String,
            default: 'camelCaseModelNameFilterForm',
        },
    })

    const emit = defineEmits(['submit'])

    const initialState = () => ({
        id: null,
//form_fields//
    })

    const form = reactive(initialState())

    // El datatable espera recibir el objeto de filtros completo, no solo los
    // que tienen valor: un campo vaciado tiene que limpiar su filtro.
    const onSubmit = () => emit('submit', { ...form })

    const resetForm = () => {
        Object.assign(form, initialState())

        onSubmit()
    }

</script>
