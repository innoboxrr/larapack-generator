<template>
	
	<form :id="formId" @submit.prevent="onSubmit">      

        <!-- NAME INPUT -->
        <text-input-component
            :custom-class="inputClass"
            type="text"
            name="name"
            label="Nombre"
            placeholder="Nombre"
            validators="required length"
            min_length="3"
            max_length="100"
            v-model="name" />

        
        <button-component
            :custom-class="buttonClass"
            :disabled="disabled"
            value="Crear" />
        
    </form>

</template>

<script>

    import { createModel } from '@models/kebabcasemodelname'
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
        		default: 'createPascalCaseModelNameForm',
        	}

        },

        emits: ['submit'],

        data() {

            return {
                
                name: '',

                // Set more $data
                
                disabled: false,
                
                JSValidator: undefined,

            }

        },

        mounted() {

            this.fetchData();

            this.JSValidator = new JSValidator(this.formId).init();

        },

        methods: {

            fetchData() {},

            onSubmit() {

                if(this.JSValidator.status) {

                    this.disabled = true;

                    createModel({

                        // data...

                    }).then( res => {

                        this.$emit('submit', res);

                        setTimeout(() => { this.disabled = false; }, 2500);

                    }).catch(error => {

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