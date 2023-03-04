<template>
	
	<form :id="formId" @submit.prevent="onSubmit">

        <!-- INPUTS -->
        <text-input-component
            custom-class="jsValidator"
            type="text"
            name="name"
            label="Nombre"
            placeholder="Nombre"
            validators="required length"
            min_length="3"
            max_length="100"
            v-model="name" />

        <button-component
            custom-class="uk-width-1-1"
            :disabled="disabled"
            value="Actualizar" />
        
    </form>

</template>

<script>

    import * as models from '@models/kebabcasemodelname/model.js'
    import JSValidator from 'innoboxrr-js-validator'
    import {
        TextInputComponent,
        ButtonComponent
    } from 'innoboxrr-form-elements'
    
	
	export default {

        components: {
            
            TextInputComponent,
            
            ButtonComponent,

        },

        props: {

            formId: {
                type: String,
                default: 'editPascalCaseModelNameForm'
            },

            camelCaseModelNameId: {
                type: [Number, String],
                required: true
            }

        },

        emits: ['submit'],

        mounted() {

            this.fetchData(); 

            this.JSValidator = new JSValidator(this.formId).init();

            this.JSValidator.status = true;

        },

        data() {

            return {

                camelCaseModelName: {},

                // ...

                disabled: false,

                JSValidator: undefined,

                fetchPascalCaseModelNameAttempts: 0,

            }

        },

        methods: {

            fetchData() {

                this.fetchPascalCaseModelName();

            },

            fetchPascalCaseModelName() {

                model.getModel(this.camelCaseModelNameId)
                    .then( res => {

                        this.fetchPascalCaseModelNameAttempts;

                        this.camelCaseModelName = res.data;

                    })
                    .catch( error => {

                        if(this.fetchPascalCaseModelNameAttempts <= 3) {

                            setTimeout( () => {

                                ++this.fetchPascalCaseModelNameAttempts;

                                this.fetchPascalCaseModelName();

                            }, 1500);

                        }

                    });

            },

            onSubmit() {

                if(this.JSValidator.status) {

                    this.disabled = true;

                    model.updateModel({}, this.camelCaseModelName.id)
                        .then( res => {

                            this.$emit('submit', res);

                            setTimeout(() => { this.disabled = false; }, 2500);

                        })
                        .catch(error => {

                            this.disabled = false;

                            if(error.response.status == 422)
                                this.JSValidator
                                    .appendExternalErrors(error.response.data.errors);

                        });

                } else {

                    this.disabled = false;

                }

            }

        }

	}

</script>